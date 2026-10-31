<?php

namespace AdvancedUsers;

use WP_Error;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Sync di tutti gli utenti a piccoli passi, guidata dalla pagina admin. Il cursore per ID
 * viene salvato dopo ogni utente: chiudere la pagina mette in pausa, riaprirla riprende.
 */
final class SyncJob {

	private const OPTION = 'advanced_users_sync_job';

	private const STEP_SECONDS = 10;

	public function __construct( private ProfileSync $sync ) {}

	public function snapshot(): array {
		wp_cache_delete( self::OPTION, 'options' );
		$job = get_option( self::OPTION, array() );
		return is_array( $job ) ? $job : array();
	}

	/**
	 * Un lock MySQL serializza le richieste, anche da schede o server diversi.
	 */
	public function run( string $operation, string $job_id = '' ): array|WP_Error {
		global $wpdb;

		$lock = 'advanced_users_' . md5( DB_NAME . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return new WP_Error( 'sync_busy', __( 'Un\'altra richiesta di sync è in corso. Riprova tra poco.', 'wp-advanced-users' ) );
		}

		try {
			$job = $this->snapshot();
			if ( 'start' === $operation ) {
				return $this->start( $job );
			}
			if ( ! $job || ! hash_equals( $job['id'], $job_id ) ) {
				return new WP_Error( 'sync_changed', __( 'La sync è cambiata. Ricarica la pagina.', 'wp-advanced-users' ) );
			}
			if ( self::is_closed( $job ) ) {
				return $job;
			}

			$job = match ( $operation ) {
				'cancel' => array( 'status' => 'cancelled' ) + $job,
				'tick'   => $job['retry_at'] > time() ? $job : $this->advance( $job ),
				default  => new WP_Error( 'sync_operation', __( 'Azione di sync sconosciuta.', 'wp-advanced-users' ) ),
			};
			if ( ! is_wp_error( $job ) ) {
				update_option( self::OPTION, $job, false );
			}
			return $job;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function is_closed( array $job ): bool {
		return in_array( $job['status'] ?? '', array( 'completed', 'cancelled' ), true );
	}

	private function start( array $current ): array|WP_Error {
		if ( $current && ! self::is_closed( $current ) ) {
			return new WP_Error( 'sync_active', __( 'Riprendi o annulla prima la sync in corso.', 'wp-advanced-users' ) );
		}

		// Il job copre gli utenti esistenti all'avvio; i nuovi passano dall'hook di registrazione.
		$query = new WP_User_Query(
			array(
				'fields'  => 'ID',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'DESC',
			)
		);
		$job   = array(
			'id'           => wp_generate_uuid4(),
			'status'       => $query->get_total() ? 'ready' : 'completed',
			'max_user_id'  => (int) ( $query->get_results()[0] ?? 0 ),
			'last_user_id' => 0,
			'processed'    => 0,
			'total'        => $query->get_total(),
			'retry_at'     => 0,
			'message'      => '',
			'entries'      => array(),
			'started_at'   => time(),
		);
		update_option( self::OPTION, $job, false );
		return $job;
	}

	private function advance( array $job ): array {
		global $wpdb;

		// get_users() non filtra per intervallo di ID: il cursore si aggiunge alla query.
		$cursor = static function ( WP_User_Query $query ) use ( $job, $wpdb ): void {
			$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d AND {$wpdb->users}.ID <= %d", $job['last_user_id'], $job['max_user_id'] );
		};
		add_action( 'pre_user_query', $cursor );
		$ids = get_users(
			array(
				'fields'      => 'ID',
				'number'      => 2,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => false,
			)
		);
		remove_action( 'pre_user_query', $cursor );

		$job     = array(
			'status'   => 'running',
			'retry_at' => 0,
			'message'  => '',
		) + $job;
		$started = microtime( true );
		foreach ( $ids as $id ) {
			$result = $this->sync->sync_user( (int) $id );
			$status = is_wp_error( $result ) ? (int) ( $result->get_error_data()['status'] ?? 0 ) : 200;

			// Rate limit ed errori temporanei fermano tutto il job senza saltare l'utente.
			if ( is_wp_error( $result ) && ( 429 === $status || $status >= 500 || str_starts_with( $result->get_error_code(), 'http_' ) ) ) {
				return array(
					'status'   => 'paused',
					'retry_at' => time() + max( 30, (int) ( $result->get_error_data()['retry_after'] ?? 60 ) ),
					'message'  => $result->get_error_message(),
				) + $job;
			}

			$job['entries'][]    = array(
				'user_id'          => (int) $id,
				'status'           => self::entry_status( $result ),
				'message'          => is_wp_error( $result ) ? $result->get_error_message() : $result['warning'],
				'updated_fields'   => is_wp_error( $result ) ? array() : $result['updated_fields'],
				'untouched_fields' => is_wp_error( $result ) ? array() : $result['untouched_fields'],
			);
			$job['entries']      = array_slice( $job['entries'], -100 );
			$job['last_user_id'] = (int) $id;
			++$job['processed'];
			update_option( self::OPTION, $job, false );

			if ( microtime( true ) - $started >= self::STEP_SECONDS ) {
				break;
			}
		}

		if ( ! $ids || $job['last_user_id'] >= $job['max_user_id'] ) {
			$job['status'] = 'completed';
		}
		return $job;
	}

	private static function entry_status( array|WP_Error $result ): string {
		if ( is_wp_error( $result ) ) {
			return 404 === ( $result->get_error_data()['status'] ?? 0 ) ? 'skipped' : 'error';
		}
		return $result['updated_fields'] ? 'updated' : 'unchanged';
	}
}
