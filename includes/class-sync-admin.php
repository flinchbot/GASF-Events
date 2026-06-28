<?php
/**
 * "Event Sync" admin page — operability for the Facebook importer: the master
 * gate, account/token management (incl. one-time import from MEC), manual
 * run / dry-run, last-run status, token-expiry warning, and a log tail.
 *
 * Everything here is admin-only and gated; viewing/saving changes nothing on
 * the public site until the gate is enabled AND a sync runs.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Sync_Admin {

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_gasf_sync_save', [ $this, 'save_gate' ] );
		add_action( 'admin_post_gasf_sync_add_account', [ $this, 'add_account' ] );
		add_action( 'admin_post_gasf_sync_del_account', [ $this, 'del_account' ] );
		add_action( 'admin_post_gasf_sync_import_mec', [ $this, 'import_mec' ] );
		add_action( 'admin_post_gasf_sync_run', [ $this, 'run' ] );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . GASF_EVENTS_CPT,
			__( 'Event Sync', 'gasf-events' ),
			__( 'Event Sync', 'gasf-events' ),
			'manage_options',
			'gasf-events-sync',
			[ $this, 'render' ]
		);
	}

	private function url(): string {
		return admin_url( 'edit.php?post_type=' . GASF_EVENTS_CPT . '&page=gasf-events-sync' );
	}

	/* ---- Handlers ----------------------------------------------------- */

	public function save_gate(): void {
		$this->guard( 'gasf_sync_save' );
		update_option( FB_Importer::OPT_ENABLE, ! empty( $_POST['enable'] ) );
		( new FB_Importer() )->sync_cron_state();
		$this->back( 'saved' );
	}

	public function add_account(): void {
		$this->guard( 'gasf_sync_account' );
		$accounts = FB_Importer::accounts();
		$accounts[] = [
			'id'           => 'a' . substr( md5( uniqid( '', true ) ), 0, 8 ),
			'label'        => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'page_id'      => sanitize_text_field( wp_unslash( $_POST['page_id'] ?? '' ) ),
			'access_token' => trim( (string) wp_unslash( $_POST['access_token'] ?? '' ) ),
			'expire_at'    => $_POST['expire_at'] ? strtotime( sanitize_text_field( wp_unslash( $_POST['expire_at'] ) ) ) : 0,
		];
		update_option( FB_Importer::OPT_ACCOUNTS, $accounts, false );
		$this->back( 'account_added' );
	}

	public function del_account(): void {
		$this->guard( 'gasf_sync_account' );
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$accounts = array_values( array_filter( FB_Importer::accounts(), static fn( $a ) => ( $a['id'] ?? '' ) !== $id ) );
		update_option( FB_Importer::OPT_ACCOUNTS, $accounts, false );
		$this->back( 'account_removed' );
	}

	/** One-time import of FB token(s) from the legacy MEC importer option (read-only). */
	public function import_mec(): void {
		$this->guard( 'gasf_sync_account' );
		$mec = get_option( 'mec_advimp_auth_facebook' );
		$accounts = FB_Importer::accounts();
		$added = 0;
		if ( is_array( $mec ) ) {
			foreach ( $mec as $entry ) {
				$token = (string) ( $entry['access_token'] ?? '' );
				if ( '' === $token ) {
					continue;
				}
				$accounts[] = [
					'id'           => 'a' . substr( md5( uniqid( '', true ) ), 0, 8 ),
					'label'        => sanitize_text_field( (string) ( $entry['title'] ?? 'Imported from MEC' ) ),
					'page_id'      => 'GermanTampa', // the MU importer's forced page; edit if different
					'access_token' => $token,
					'expire_at'    => (int) ( $entry['expire_at'] ?? 0 ),
				];
				$added++;
			}
		}
		update_option( FB_Importer::OPT_ACCOUNTS, $accounts, false );
		$this->back( $added ? 'mec_imported' : 'mec_none' );
	}

	public function run(): void {
		$this->guard( 'gasf_sync_run' );
		$dry = ! empty( $_POST['dry'] );
		$stats = FB_Importer::run( $dry );
		set_transient( 'gasf_sync_result_' . get_current_user_id(), $stats, 120 );
		$this->back( $dry ? 'dry_done' : 'run_done' );
	}

	/* ---- Render ------------------------------------------------------- */

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled  = FB_Importer::enabled();
		$accounts = FB_Importer::accounts();
		$last     = (array) get_option( FB_Importer::OPT_LAST_RUN, [] );
		$log      = (array) get_option( 'gasf_events_sync_log', [] );
		$expiry   = FB_Importer::soonest_expiry_days();
		$result   = get_transient( 'gasf_sync_result_' . get_current_user_id() );
		delete_transient( 'gasf_sync_result_' . get_current_user_id() );
		$u = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Event Sync (Facebook)', 'gasf-events' ); ?></h1>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Sync is OFF.', 'gasf-events' ); ?></strong> <?php esc_html_e( 'Nothing is imported automatically. Use “Dry run” to preview safely. The existing MEC importer keeps running until cutover.', 'gasf-events' ); ?></p></div>
			<?php endif; ?>
			<?php if ( null !== $expiry && $expiry < 14 ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: %d days */ __( '⚠ A Facebook token expires in %d days. Refresh it to avoid sync failures.', 'gasf-events' ), $expiry ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $result ) : ?>
				<div class="notice notice-info"><p><strong><?php echo $result['dry'] ? esc_html__( 'Dry run', 'gasf-events' ) : esc_html__( 'Sync', 'gasf-events' ); ?>:</strong>
					<?php echo esc_html( sprintf( 'created %d · updated %d · drafted %d · skipped %d', $result['created'], $result['updated'], $result['drafted'], $result['skipped'] ) ); ?>
					<?php if ( ! empty( $result['errors'] ) ) : ?><br><span style="color:#b32d2e;"><?php echo esc_html( implode( ' | ', $result['errors'] ) ); ?></span><?php endif; ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Status', 'gasf-events' ); ?></h2>
			<form method="post" action="<?php echo $u; ?>" style="margin-bottom:8px;">
				<?php wp_nonce_field( 'gasf_sync_save' ); ?>
				<input type="hidden" name="action" value="gasf_sync_save">
				<label><input type="checkbox" name="enable" value="1" <?php checked( $enabled ); ?>> <strong><?php esc_html_e( 'Enable automatic Facebook sync (every 15 min)', 'gasf-events' ); ?></strong></label>
				<?php submit_button( __( 'Save', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>
			<table class="widefat striped" style="max-width:680px;">
				<tbody>
					<tr><th><?php esc_html_e( 'Last run', 'gasf-events' ); ?></th><td><?php echo $last ? esc_html( wp_date( 'M j, Y g:i a', (int) $last['ts'] ) ) : '—'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Last result', 'gasf-events' ); ?></th><td><?php echo $last ? esc_html( sprintf( 'created %d · updated %d · drafted %d · skipped %d · errors %d', $last['created'] ?? 0, $last['updated'] ?? 0, $last['drafted'] ?? 0, $last['skipped'] ?? 0, count( $last['errors'] ?? [] ) ) ) : '—'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Next scheduled', 'gasf-events' ); ?></th><td><?php $n = wp_next_scheduled( FB_Importer::CRON_HOOK ); echo $n ? esc_html( wp_date( 'M j, g:i a', $n ) ) : esc_html__( 'not scheduled (sync off)', 'gasf-events' ); ?></td></tr>
				</tbody>
			</table>

			<p style="margin-top:12px;">
				<form method="post" action="<?php echo $u; ?>" style="display:inline;">
					<?php wp_nonce_field( 'gasf_sync_run' ); ?>
					<input type="hidden" name="action" value="gasf_sync_run">
					<input type="hidden" name="dry" value="1">
					<?php submit_button( __( 'Dry run (no writes)', 'gasf-events' ), 'secondary', '', false ); ?>
				</form>
				<form method="post" action="<?php echo $u; ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Run a real sync now? This writes gasf_event posts.', 'gasf-events' ) ); ?>');">
					<?php wp_nonce_field( 'gasf_sync_run' ); ?>
					<input type="hidden" name="action" value="gasf_sync_run">
					<?php submit_button( __( 'Sync now', 'gasf-events' ), 'primary', '', false ); ?>
				</form>
			</p>

			<h2><?php esc_html_e( 'Facebook accounts', 'gasf-events' ); ?></h2>
			<table class="widefat striped" style="max-width:820px;">
				<thead><tr><th><?php esc_html_e( 'Label', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Page', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Token', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Expires', 'gasf-events' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php if ( ! $accounts ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No accounts yet.', 'gasf-events' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $accounts as $a ) : ?>
						<tr>
							<td><?php echo esc_html( $a['label'] ?? '' ); ?></td>
							<td><?php echo esc_html( $a['page_id'] ?? '' ); ?></td>
							<td><code><?php echo esc_html( substr( (string) ( $a['access_token'] ?? '' ), 0, 6 ) . '…' ); ?></code></td>
							<td><?php echo ! empty( $a['expire_at'] ) ? esc_html( wp_date( 'M j, Y', (int) $a['expire_at'] ) ) : '—'; ?></td>
							<td>
								<form method="post" action="<?php echo $u; ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this account?', 'gasf-events' ) ); ?>');">
									<?php wp_nonce_field( 'gasf_sync_account' ); ?>
									<input type="hidden" name="action" value="gasf_sync_del_account">
									<input type="hidden" name="id" value="<?php echo esc_attr( $a['id'] ?? '' ); ?>">
									<button class="button-link delete"><?php esc_html_e( 'Remove', 'gasf-events' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Add account', 'gasf-events' ); ?></h3>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_sync_account' ); ?>
				<input type="hidden" name="action" value="gasf_sync_add_account">
				<input type="text" name="label" placeholder="<?php esc_attr_e( 'Label', 'gasf-events' ); ?>" required>
				<input type="text" name="page_id" placeholder="<?php esc_attr_e( 'Page id or username', 'gasf-events' ); ?>" value="GermanTampa" required>
				<input type="text" name="access_token" placeholder="<?php esc_attr_e( 'Page access token', 'gasf-events' ); ?>" size="40" required>
				<input type="date" name="expire_at" title="<?php esc_attr_e( 'Token expiry (optional)', 'gasf-events' ); ?>">
				<?php submit_button( __( 'Add', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>
			<form method="post" action="<?php echo $u; ?>" style="margin-top:8px;">
				<?php wp_nonce_field( 'gasf_sync_account' ); ?>
				<input type="hidden" name="action" value="gasf_sync_import_mec">
				<?php submit_button( __( 'Import token(s) from MEC importer', 'gasf-events' ), 'link', '', false ); ?>
			</form>

			<?php if ( $log ) : ?>
				<h2><?php esc_html_e( 'Recent runs', 'gasf-events' ); ?></h2>
				<textarea readonly rows="8" style="width:100%;max-width:820px;font-family:monospace;font-size:12px;"><?php echo esc_textarea( implode( "\n", $log ) ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---- helpers ------------------------------------------------------ */

	private function guard( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gasf-events' ) );
		}
		check_admin_referer( $nonce );
	}

	private function back( string $flag ): void {
		wp_safe_redirect( add_query_arg( 'gasf_msg', $flag, $this->url() ) );
		exit;
	}
}
