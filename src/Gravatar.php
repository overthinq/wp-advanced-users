<?php

namespace AdvancedUsers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Client delle API Gravatar v3. I profili trovati e quelli assenti restano in cache;
 * un 429 o un errore del server sospende tutte le richieste fino a Retry-After.
 */
final class Gravatar {

	private const PAUSE = 'advanced_users_gravatar_retry';

	public static function profile( string $email_hash, bool $refresh = false ): array|WP_Error {
		$cache_key   = 'advanced_users_profile_' . $email_hash;
		$missing_key = 'advanced_users_missing_' . $email_hash;

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			if ( get_transient( $missing_key ) ) {
				return self::not_found();
			}
		}

		$pause = get_transient( self::PAUSE );
		if ( is_array( $pause ) && $pause['until'] > time() ) {
			return self::http_error( $pause['status'], $pause['until'] - time() );
		}

		$headers = array( 'Accept' => 'application/json' );
		if ( defined( 'ADVANCED_USERS_GRAVATAR_KEY' ) ) {
			$headers['Authorization'] = 'Bearer ' . ADVANCED_USERS_GRAVATAR_KEY;
		}

		$response = wp_remote_get(
			'https://api.gravatar.com/v3/profiles/' . rawurlencode( $email_hash ),
			array(
				'timeout' => 5,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			delete_transient( $cache_key );
			set_transient( $missing_key, true, max( MINUTE_IN_SECONDS, (int) apply_filters( 'advanced_users_missing_cache_ttl', DAY_IN_SECONDS ) ) );
			return self::not_found();
		}

		if ( $status < 200 || $status >= 300 ) {
			$header = (string) wp_remote_retrieve_header( $response, 'retry-after' );
			$retry  = ctype_digit( $header ) ? (int) $header : (int) strtotime( $header ) - time();
			$retry  = min( DAY_IN_SECONDS, max( 30, $retry > 0 ? $retry : 60 ) );
			if ( 429 === $status || $status >= 500 ) {
				set_transient(
					self::PAUSE,
					array(
						'status' => $status,
						'until'  => time() + $retry,
					),
					$retry
				);
			}
			return self::http_error( $status, $retry );
		}

		$profile = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $profile ) ) {
			return new WP_Error( 'advanced_users_gravatar_json_error', __( 'Risposta JSON di Gravatar non valida.', 'wp-advanced-users' ) );
		}

		delete_transient( $missing_key );
		set_transient( $cache_key, $profile, max( MINUTE_IN_SECONDS, (int) apply_filters( 'advanced_users_profile_cache_ttl', 6 * HOUR_IN_SECONDS ) ) );
		return $profile;
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'advanced_users_gravatar_not_found', __( 'Profilo Gravatar non trovato.', 'wp-advanced-users' ), array( 'status' => 404 ) );
	}

	private static function http_error( int $status, int $retry_after ): WP_Error {
		return new WP_Error(
			'advanced_users_gravatar_http_error',
			429 === $status || $status >= 500
				? __( 'Gravatar non disponibile: richieste sospese fino al prossimo tentativo.', 'wp-advanced-users' )
				/* translators: %d: codice di stato HTTP. */
				: sprintf( __( 'Risposta inattesa da Gravatar (HTTP %d).', 'wp-advanced-users' ), $status ),
			array(
				'status'      => $status,
				'retry_after' => $retry_after,
			)
		);
	}
}
