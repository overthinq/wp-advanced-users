<?php

namespace AdvancedUsers\Admin;

use AdvancedUsers\ProfileSync;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Sezione Gravatar nella schermata del profilo: dati sincronizzati e sync del singolo utente.
 */
final class ProfileSection {

	private const ACTION = 'advanced_users_gravatar_sync_user';

	private const RESULT = 'advanced_users_gravatar_sync_result';

	public function __construct( private ProfileSync $sync ) {}

	public function hook(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_sync' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	public function render( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$sync_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION,
					'user_id' => $user->ID,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $user->ID
		);
		$fields   = array_filter(
			array(
				__( 'Qualifica', 'wp-advanced-users' ) => $user->advanced_users_gravatar_job_title,
				__( 'Azienda', 'wp-advanced-users' )   => $user->advanced_users_gravatar_company,
				__( 'Località', 'wp-advanced-users' )  => $user->advanced_users_gravatar_location,
				__( 'Biografia', 'wp-advanced-users' ) => $user->advanced_users_gravatar_description,
			)
		);
		$profile  = $user->advanced_users_gravatar_profile_url;
		$accounts = ProfileSync::visible_accounts( $user->ID );
		?>
		<h2><?php esc_html_e( 'Gravatar', 'wp-advanced-users' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Sincronizza', 'wp-advanced-users' ); ?></th>
				<td>
					<a href="<?php echo esc_url( $sync_url ); ?>" class="button"><?php esc_html_e( 'Sync', 'wp-advanced-users' ); ?></a>
					<a href="<?php echo esc_url( add_query_arg( 'force_update', 1, $sync_url ) ); ?>" class="button"><?php esc_html_e( 'Sync forzata', 'wp-advanced-users' ); ?></a>
					<p class="description"><?php esc_html_e( 'La sync riempie solo i campi vuoti; quella forzata sovrascrive anche quelli compilati.', 'wp-advanced-users' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Account collegati', 'wp-advanced-users' ); ?></th>
				<td>
					<?php if ( ! $accounts ) : ?>
						<p class="description"><?php esc_html_e( 'Nessun account visibile su Gravatar.', 'wp-advanced-users' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $accounts as $account ) : ?>
								<li>
									<?php if ( ! empty( $account['service_icon'] ) ) : ?>
										<img src="<?php echo esc_url( $account['service_icon'] ); ?>" alt="" width="16" height="16">
									<?php endif; ?>
									<a href="<?php echo esc_url( $account['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $account['service_label'] ); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="description"><?php esc_html_e( 'Si modificano su Gravatar, poi si sincronizzano.', 'wp-advanced-users' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Dati sincronizzati', 'wp-advanced-users' ); ?></th>
				<td>
					<?php if ( ! $fields && ! $profile ) : ?>
						<p class="description"><?php esc_html_e( 'Nessun dato Gravatar sincronizzato.', 'wp-advanced-users' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $fields as $label => $value ) : ?>
								<li><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $value ); ?></li>
							<?php endforeach; ?>
							<?php if ( $profile ) : ?>
								<li><strong><?php esc_html_e( 'Profilo', 'wp-advanced-users' ); ?>:</strong> <a href="<?php echo esc_url( $profile ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $profile ); ?></a></li>
							<?php endif; ?>
						</ul>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public function handle_sync(): void {
		$user_id = absint( $_GET['user_id'] ?? 0 );
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'Operazione non consentita.', 'wp-advanced-users' ), 403 );
		}
		check_admin_referer( self::ACTION . '_' . $user_id );

		$result   = $this->sync->sync_user( $user_id, ! empty( $_GET['force_update'] ) );
		$redirect = get_current_user_id() === $user_id ? admin_url( 'profile.php' ) : add_query_arg( 'user_id', $user_id, admin_url( 'user-edit.php' ) );
		$args     = is_wp_error( $result )
			? array(
				self::RESULT                           => 'error',
				'advanced_users_gravatar_sync_message' => rawurlencode( $result->get_error_message() ),
			)
			: array(
				self::RESULT                             => 'success',
				'advanced_users_gravatar_sync_updated'   => count( $result['updated_fields'] ),
				'advanced_users_gravatar_sync_untouched' => count( $result['untouched_fields'] ),
			);

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	public function render_notice(): void {
		// Esito passato dal redirect di handle_sync(): solo testo da mostrare, nessuna azione.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result = sanitize_key( $_GET[ self::RESULT ] ?? '' );
		if ( 'success' === $result ) {
			$message = sprintf(
				/* translators: 1: campi aggiornati, 2: campi lasciati invariati. */
				__( 'Sync Gravatar completata. Campi aggiornati: %1$d. Invariati: %2$d.', 'wp-advanced-users' ),
				absint( $_GET['advanced_users_gravatar_sync_updated'] ?? 0 ),
				absint( $_GET['advanced_users_gravatar_sync_untouched'] ?? 0 )
			);
		} elseif ( 'error' === $result ) {
			$message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['advanced_users_gravatar_sync_message'] ?? '' ) ) );
		} else {
			return;
		}
		// phpcs:enable

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $result ), esc_html( $message ) );
	}
}
