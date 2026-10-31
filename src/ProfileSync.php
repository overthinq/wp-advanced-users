<?php

namespace AdvancedUsers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Copia i dati del profilo Gravatar nei campi dell'utente. La sync normale riempie solo
 * i campi vuoti, quella forzata sovrascrive; un valore remoto vuoto non cancella mai quello locale.
 */
final class ProfileSync {

	public const ACCOUNTS = 'advanced_users_gravatar_verified_accounts';

	/**
	 * Meta utente => campo del profilo, nell'ordine in cui compaiono nel log.
	 */
	private const FIELDS = array(
		'advanced_users_gravatar_display_name'     => 'display_name',
		'advanced_users_gravatar_profile_url'      => 'profile_url',
		'advanced_users_gravatar_avatar_url'       => 'avatar_url',
		'advanced_users_gravatar_avatar_alt_text'  => 'avatar_alt_text',
		'advanced_users_gravatar_location'         => 'location',
		'advanced_users_gravatar_description'      => 'description',
		'advanced_users_gravatar_job_title'        => 'job_title',
		'advanced_users_gravatar_company'          => 'company',
		'advanced_users_gravatar_background_color' => 'background_color',
		'description'                              => 'description',
		'first_name'                               => 'first_name',
		'last_name'                                => 'last_name',
		self::ACCOUNTS                             => 'verified_accounts',
	);

	public function __construct( private LocalAvatar $avatars ) {}

	public function hook(): void {
		add_action( 'user_register', array( $this, 'sync_user' ) );
		add_action( 'profile_update', array( $this, 'sync_user' ) );
	}

	/**
	 * @return array{updated_fields: string[], untouched_fields: string[], warning: string}|WP_Error
	 */
	public function sync_user( int $user_id, bool $force = false ): array|WP_Error {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'Utente non trovato.', 'wp-advanced-users' ) );
		}
		if ( ! is_email( $user->user_email ) ) {
			return new WP_Error( 'missing_hash', __( 'Senza un indirizzo email valido non si può interrogare Gravatar.', 'wp-advanced-users' ) );
		}

		$remote = Gravatar::profile( hash( 'sha256', strtolower( trim( $user->user_email ) ) ), $force );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		$profile = self::normalize( $remote );
		$result  = array(
			'updated_fields'   => array(),
			'untouched_fields' => array(),
			'warning'          => '',
		);
		foreach ( self::FIELDS as $meta_key => $field ) {
			$outcome              = $this->store( $user_id, $meta_key, $profile[ $field ], $force ) ? 'updated_fields' : 'untouched_fields';
			$result[ $outcome ][] = $meta_key;
		}

		$avatar = $this->avatars->sync( $user_id, $profile, $force );
		if ( true === $avatar ) {
			$result['updated_fields'][] = 'local_avatar';
		} elseif ( is_wp_error( $avatar ) ) {
			$result['warning'] = $avatar->get_error_message();
		}

		return $result;
	}

	private function store( int $user_id, string $meta_key, string|array $value, bool $force ): bool {
		if ( ! self::is_filled( $value ) ) {
			return false;
		}

		$current = get_user_meta( $user_id, $meta_key, true );
		if ( $force && $current === $value ) {
			return false;
		}
		if ( ! $force && self::is_filled( $current ) ) {
			return false;
		}

		update_user_meta( $user_id, $meta_key, $value );
		return true;
	}

	private static function is_filled( mixed $value ): bool {
		return is_array( $value ) ? array() !== $value : '' !== trim( (string) $value );
	}

	private static function normalize( array $profile ): array {
		$text = static fn( string $key ): string => is_scalar( $profile[ $key ] ?? null ) ? sanitize_text_field( (string) $profile[ $key ] ) : '';
		$url  = static fn( string $key ): string => is_string( $profile[ $key ] ?? null ) ? esc_url_raw( $profile[ $key ] ) : '';

		return array(
			'display_name'      => $text( 'display_name' ),
			'profile_url'       => $url( 'profile_url' ),
			'avatar_url'        => $url( 'avatar_url' ),
			'avatar_alt_text'   => $text( 'avatar_alt_text' ),
			'location'          => $text( 'location' ),
			'description'       => is_string( $profile['description'] ?? null ) ? sanitize_textarea_field( $profile['description'] ) : '',
			'job_title'         => $text( 'job_title' ),
			'company'           => $text( 'company' ),
			'background_color'  => (string) sanitize_hex_color( $text( 'background_color' ) ),
			'first_name'        => $text( 'first_name' ),
			'last_name'         => $text( 'last_name' ),
			'verified_accounts' => self::accounts( $profile['verified_accounts'] ?? null ),
		);
	}

	private static function accounts( mixed $accounts ): array {
		$normalized = array();
		foreach ( is_array( $accounts ) ? $accounts : array() as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $account['service_label'] ?? '' ) );
			$type  = sanitize_key( (string) ( $account['service_type'] ?? '' ) );
			$type  = '' === $type ? sanitize_key( $label ) : $type;
			$url   = esc_url_raw( (string) ( $account['url'] ?? '' ) );
			if ( '' === $type || '' === $url ) {
				continue;
			}

			$normalized[] = array(
				'service_type'  => $type,
				'service_label' => '' === $label ? ucfirst( $type ) : $label,
				'service_icon'  => esc_url_raw( (string) ( $account['service_icon'] ?? '' ) ),
				'url'           => $url,
				'is_hidden'     => ! empty( $account['is_hidden'] ),
			);
		}

		return $normalized;
	}

	/**
	 * Account verificati da mostrare: Gravatar segna con is_hidden quelli che l'utente non vuole pubblici.
	 */
	public static function visible_accounts( int $user_id ): array {
		$accounts = get_user_meta( $user_id, self::ACCOUNTS, true );
		if ( ! is_array( $accounts ) ) {
			return array();
		}

		return array_filter( $accounts, static fn( $account ) => is_array( $account ) && ! empty( $account['url'] ) && empty( $account['is_hidden'] ) );
	}
}
