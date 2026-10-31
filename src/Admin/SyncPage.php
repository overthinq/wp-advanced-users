<?php

namespace AdvancedUsers\Admin;

use AdvancedUsers\LocalAvatar;
use AdvancedUsers\SyncJob;

defined( 'ABSPATH' ) || exit;

/**
 * Utenti → Sync Gravatar: sync di tutti gli utenti, log dell'ultimo job, avatar locali.
 */
final class SyncPage {

	private const SLUG = 'advanced-users-gravatar-sync';

	public function __construct( private SyncJob $job, private string $url ) {}

	public function hook(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'wp_ajax_advanced_users_sync_batch', array( $this, 'handle_batch' ) );
		add_action( 'admin_post_advanced_users_avatar_settings', array( $this, 'save_avatar_settings' ) );
	}

	public function add_page(): void {
		add_users_page( __( 'Sync Gravatar', 'wp-advanced-users' ), __( 'Sync Gravatar', 'wp-advanced-users' ), 'edit_users', self::SLUG, array( $this, 'render' ) );
	}

	public function handle_batch(): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_send_json_error( __( 'Operazione non consentita.', 'wp-advanced-users' ), 403 );
		}
		check_ajax_referer( 'advanced_users_sync_batch' );

		$result = $this->job->run(
			sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) )
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 409 );
		}
		wp_send_json_success( $result );
	}

	public function save_avatar_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Operazione non consentita.', 'wp-advanced-users' ), 403 );
		}
		check_admin_referer( 'advanced_users_avatar_settings' );

		update_option( LocalAvatar::OPTION, ! empty( $_POST['local_avatars'] ), false );
		wp_safe_redirect( admin_url( 'users.php?page=' . self::SLUG ) );
		exit;
	}

	public function render(): void {
		$job = $this->job->snapshot();
		wp_enqueue_script( 'advanced-users-sync', $this->url . 'assets/sync.js', array(), '0.2.0', true );
		wp_add_inline_script(
			'advanced-users-sync',
			'window.advancedUsersSync = ' . wp_json_encode(
				array(
					'nonce' => wp_create_nonce( 'advanced_users_sync_batch' ),
					'job'   => $job,
					'i18n'  => array(
						'idle'     => __( 'Nessuna sync avviata.', 'wp-advanced-users' ),
						/* translators: 1: stato, 2: utenti elaborati, 3: utenti totali. */
						'progress' => __( '%1$s: %2$d utenti su %3$d.', 'wp-advanced-users' ),
						/* translators: %s: ora del prossimo tentativo. */
						'retry'    => __( 'Riprova dopo le %s.', 'wp-advanced-users' ),
						'failed'   => __( 'Sync interrotta. Ricarica la pagina e riprendi.', 'wp-advanced-users' ),
						'statuses' => self::statuses(),
					),
				)
			) . ';',
			'before'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sync Gravatar', 'wp-advanced-users' ); ?></h1>
			<p><?php esc_html_e( 'La sync riempie i campi vuoti del profilo. Chiudi la pagina per metterla in pausa: riaprendola riprende dalla posizione salvata.', 'wp-advanced-users' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="advanced-users-start"><?php esc_html_e( 'Nuova sync', 'wp-advanced-users' ); ?></button>
				<button type="button" class="button" id="advanced-users-resume"><?php esc_html_e( 'Riprendi', 'wp-advanced-users' ); ?></button>
				<button type="button" class="button" id="advanced-users-cancel"><?php esc_html_e( 'Annulla', 'wp-advanced-users' ); ?></button>
			</p>
			<p id="advanced-users-progress" role="status" aria-live="polite"></p>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<h2><?php esc_html_e( 'Avatar locali', 'wp-advanced-users' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'advanced_users_avatar_settings' ); ?>
					<input type="hidden" name="action" value="advanced_users_avatar_settings">
					<label>
						<input type="checkbox" name="local_avatars" value="1" <?php checked( LocalAvatar::enabled() ); ?>>
						<?php esc_html_e( 'Scarica le immagini Gravatar e usa le copie locali come avatar di WordPress', 'wp-advanced-users' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Le immagini si scaricano durante la sync. Disattivando tornano gli avatar standard; i file restano nella libreria media.', 'wp-advanced-users' ); ?></p>
					<?php submit_button( __( 'Salva', 'wp-advanced-users' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Log dell\'ultima sync', 'wp-advanced-users' ); ?></h2>
			<?php if ( empty( $job['entries'] ) ) : ?>
				<p><?php esc_html_e( 'Nessun log: avvia una sync per generarlo.', 'wp-advanced-users' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					/* translators: %s: data e ora di avvio. */
					printf( esc_html__( 'Avviata il %s.', 'wp-advanced-users' ), esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $job['started_at'] ) ) );
					?>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Utente', 'wp-advanced-users' ); ?></th>
							<th><?php esc_html_e( 'Esito', 'wp-advanced-users' ); ?></th>
							<th><?php esc_html_e( 'Campi aggiornati', 'wp-advanced-users' ); ?></th>
							<th><?php esc_html_e( 'Campi invariati', 'wp-advanced-users' ); ?></th>
							<th><?php esc_html_e( 'Messaggio', 'wp-advanced-users' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $job['entries'] as $entry ) : ?>
							<?php $user = get_userdata( $entry['user_id'] ); ?>
							<tr>
								<td><?php echo esc_html( $user ? "{$user->user_login} (#{$user->ID})" : "#{$entry['user_id']}" ); ?></td>
								<td><?php echo esc_html( self::statuses()[ $entry['status'] ] ?? $entry['status'] ); ?></td>
								<td><?php echo esc_html( implode( ', ', $entry['updated_fields'] ) ); ?></td>
								<td><?php echo esc_html( implode( ', ', $entry['untouched_fields'] ) ); ?></td>
								<td><?php echo esc_html( $entry['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function statuses(): array {
		return array(
			'ready'     => __( 'Pronta', 'wp-advanced-users' ),
			'running'   => __( 'In corso', 'wp-advanced-users' ),
			'paused'    => __( 'In pausa', 'wp-advanced-users' ),
			'completed' => __( 'Completata', 'wp-advanced-users' ),
			'cancelled' => __( 'Annullata', 'wp-advanced-users' ),
			'updated'   => __( 'Aggiornato', 'wp-advanced-users' ),
			'unchanged' => __( 'Invariato', 'wp-advanced-users' ),
			'skipped'   => __( 'Nessun profilo', 'wp-advanced-users' ),
			'error'     => __( 'Errore', 'wp-advanced-users' ),
		);
	}
}
