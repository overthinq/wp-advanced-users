<?php

namespace AdvancedUsers;

use WP_Comment;
use WP_Error;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Copie locali delle immagini Gravatar, opzionali. Si scaricano durante la sync, mai mentre
 * si genera una pagina, e passano dal filtro standard degli avatar di WordPress.
 */
final class LocalAvatar {

	public const OPTION = 'advanced_users_local_avatars';

	private const META = 'advanced_users_avatar';

	private const MAX_BYTES = 4 * MB_IN_BYTES;

	private const EXTENSIONS = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	public static function enabled(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	public function hook(): void {
		add_filter( 'get_avatar_data', array( $this, 'override' ), 10, 2 );
	}

	/**
	 * @return bool|WP_Error true se l'immagine locale è cambiata.
	 */
	public function sync( int $user_id, array $profile, bool $force = false ): bool|WP_Error {
		if ( ! self::enabled() || empty( $profile['avatar_url'] ) ) {
			return false;
		}

		$source = esc_url_raw( $profile['avatar_url'] );
		$host   = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		if ( 'https' !== wp_parse_url( $source, PHP_URL_SCHEME ) || ( 'gravatar.com' !== $host && ! str_ends_with( $host, '.gravatar.com' ) ) ) {
			return new WP_Error( 'avatar_url', __( 'L\'avatar deve essere un URL Gravatar in HTTPS.', 'wp-advanced-users' ) );
		}

		global $wpdb;
		$lock = 'au_avatar_' . md5( DB_NAME . $wpdb->prefix . ':' . $user_id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return new WP_Error( 'avatar_busy', __( 'Un\'altra sync dell\'avatar è in corso per questo utente.', 'wp-advanced-users' ) );
		}

		try {
			return $this->download( $user_id, $source, $profile['avatar_alt_text'] ?? '', $force );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Ricontrolla dopo 6 ore con una richiesta condizionale. Byte identici riusano l'allegato;
	 * un'immagine cambiata allo stesso URL ne crea uno nuovo e lascia il vecchio nella libreria.
	 */
	private function download( int $user_id, string $source, string $alt, bool $force ): bool|WP_Error {
		$state      = get_user_meta( $user_id, self::META, true );
		$state      = is_array( $state ) ? $state : array();
		$current    = ! empty( $state['id'] ) && self::is_usable( (int) $state['id'] );
		$revalidate = ! $force && $current && ( $state['source'] ?? '' ) === $source;
		if ( $revalidate && time() - ( $state['checked_at'] ?? 0 ) < 6 * HOUR_IN_SECONDS ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( 'advanced-users-avatar' );
		if ( ! $tmp ) {
			return new WP_Error( 'avatar_temp', __( 'Impossibile creare il file temporaneo dell\'avatar.', 'wp-advanced-users' ) );
		}

		try {
			$headers = array();
			if ( $revalidate ) {
				$headers = array_filter(
					array(
						'If-None-Match'     => $state['etag'] ?? '',
						'If-Modified-Since' => $state['modified'] ?? '',
					)
				);
			}

			$response = wp_safe_remote_get(
				add_query_arg(
					array(
						's' => 512,
						'd' => '404',
					),
					$source
				),
				array(
					'timeout'             => 10,
					'redirection'         => 2,
					'stream'              => true,
					'filename'            => $tmp,
					'limit_response_size' => self::MAX_BYTES,
					'headers'             => $headers,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 304 === $status && $revalidate ) {
				$state['checked_at'] = time();
				update_user_meta( $user_id, self::META, $state );
				return false;
			}
			if ( 200 !== $status ) {
				return new WP_Error( 'avatar_http', __( 'Download dell\'avatar non riuscito: resta quello attuale.', 'wp-advanced-users' ) );
			}

			$extension = self::EXTENSIONS[ wp_get_image_mime( $tmp ) ] ?? '';
			if ( ! $extension || filesize( $tmp ) >= self::MAX_BYTES ) {
				return new WP_Error( 'avatar_image', __( 'Immagine dell\'avatar non valida o troppo grande.', 'wp-advanced-users' ) );
			}

			$digest    = hash_file( 'sha256', $tmp );
			$unchanged = $current && ( $state['digest'] ?? '' ) === $digest;
			$id        = $unchanged ? (int) $state['id'] : media_handle_sideload(
				array(
					'name'     => "gravatar-$user_id.$extension",
					'tmp_name' => $tmp,
				),
				0,
				'Gravatar: ' . get_the_author_meta( 'display_name', $user_id ),
				array( 'post_author' => $user_id )
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}

			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			update_user_meta(
				$user_id,
				self::META,
				array(
					'id'         => $id,
					'source'     => $source,
					'digest'     => $digest,
					'checked_at' => time(),
					'etag'       => (string) wp_remote_retrieve_header( $response, 'etag' ),
					'modified'   => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
				)
			);
			return ! $unchanged;
		} finally {
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	public function override( array $args, mixed $id_or_email ): array {
		if ( ! self::enabled() || ! empty( $args['force_default'] ) ) {
			return $args;
		}

		$user = self::user( $id_or_email );
		$id   = $user ? (int) ( get_user_meta( $user->ID, self::META, true )['id'] ?? 0 ) : 0;
		if ( ! $id || ! self::is_usable( $id ) ) {
			return $args;
		}

		$image = wp_get_attachment_image_src( $id, array( (int) $args['size'], (int) $args['size'] ) );
		if ( $image ) {
			$args['url']          = $image[0];
			$args['found_avatar'] = true;
		}
		return $args;
	}

	/**
	 * Risolve tutte le forme accettate da get_avatar(): ID, email, utente, post o commento.
	 */
	private static function user( mixed $id_or_email ): WP_User|false {
		if ( $id_or_email instanceof WP_Comment ) {
			if ( ! is_avatar_comment_type( get_comment_type( $id_or_email ) ) ) {
				return false;
			}
			$id_or_email = $id_or_email->user_id ? $id_or_email->user_id : $id_or_email->comment_author_email;
		}
		if ( $id_or_email instanceof WP_Post ) {
			$id_or_email = $id_or_email->post_author;
		}

		return match ( true ) {
			$id_or_email instanceof WP_User => $id_or_email,
			is_numeric( $id_or_email )      => get_user_by( 'id', (int) $id_or_email ),
			is_string( $id_or_email )       => get_user_by( 'email', $id_or_email ),
			default                         => false,
		};
	}

	private static function is_usable( int $attachment_id ): bool {
		return wp_attachment_is_image( $attachment_id ) && is_file( (string) get_attached_file( $attachment_id ) );
	}
}
