<?php
/**
 * "Feeds" admin page — the single home for event ingestion + syndication.
 * Manage source feeds (Facebook pages, ICS calendars), choose destinations per
 * feed (GASF Calendar and/or Google Calendar), set the Google target, run /
 * dry-run, and read status + log. Admin-only and master-gated.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Feeds_Admin {

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_gasf_feeds_gate', [ $this, 'save_gate' ] );
		add_action( 'admin_post_gasf_feeds_add', [ $this, 'add_feed' ] );
		add_action( 'admin_post_gasf_feeds_del', [ $this, 'del_feed' ] );
		add_action( 'admin_post_gasf_feeds_toggle', [ $this, 'toggle_feed' ] );
		add_action( 'admin_post_gasf_feeds_import_mec', [ $this, 'import_mec' ] );
		add_action( 'admin_post_gasf_feeds_run', [ $this, 'run' ] );
		add_action( 'admin_post_gasf_feeds_eventbrite', [ $this, 'save_eventbrite' ] );
	}

	public function save_eventbrite(): void {
		$this->guard( 'gasf_feeds_eb' );
		$cur   = Eventbrite::config();
		$token = trim( (string) wp_unslash( $_POST['token'] ?? '' ) );
		// Keep the stored token if the field was left as the masked preview / blank.
		if ( '' === $token || false !== strpos( $token, '…' ) ) {
			$token = (string) $cur['token'];
		}
		update_option( Eventbrite::OPTION, [
			'token'           => $token,
			'organization_id' => sanitize_text_field( wp_unslash( $_POST['organization_id'] ?? '' ) ),
			'enabled'         => ! empty( $_POST['enabled'] ),
		], false );
		$this->back();
	}

	public function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . GASF_EVENTS_CPT, __( 'Feeds', 'gasf-events' ), __( 'Feeds', 'gasf-events' ), 'manage_options', 'gasf-events-feeds', [ $this, 'render' ] );
	}

	private function url(): string {
		return admin_url( 'edit.php?post_type=' . GASF_EVENTS_CPT . '&page=gasf-events-feeds' );
	}

	private function guard( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gasf-events' ) );
		}
		check_admin_referer( $nonce );
	}

	private function back(): void {
		wp_safe_redirect( $this->url() );
		exit;
	}

	/* ---- handlers ----------------------------------------------------- */

	public function save_gate(): void {
		$this->guard( 'gasf_feeds_gate' );
		update_option( Feeds::OPT_ENABLE, ! empty( $_POST['enable'] ), false );
		update_option( Feeds::OPT_GCAL, [ 'calendar_id' => sanitize_text_field( wp_unslash( $_POST['calendar_id'] ?? '' ) ) ], false );
		( new Feeds() )->sync_cron_state();
		$this->back();
	}

	public function add_feed(): void {
		$this->guard( 'gasf_feeds_edit' );
		$type  = ( ( $_POST['type'] ?? '' ) === 'ics' ) ? 'ics' : 'facebook';
		$feeds = Feeds::feeds();
		$feed  = [
			'id'          => Feeds::new_id(),
			'type'        => $type,
			'label'       => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'enabled'     => ! empty( $_POST['enabled'] ),
			'dest_gasf'   => ! empty( $_POST['dest_gasf'] ),
			'dest_google' => ! empty( $_POST['dest_google'] ),
		];
		if ( 'ics' === $type ) {
			$feed['url'] = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		} else {
			$feed['page_id']      = sanitize_text_field( wp_unslash( $_POST['page_id'] ?? '' ) );
			$feed['access_token'] = trim( (string) wp_unslash( $_POST['access_token'] ?? '' ) );
			$feed['expire_at']    = ! empty( $_POST['expire_at'] ) ? (int) strtotime( sanitize_text_field( wp_unslash( $_POST['expire_at'] ) ) ) : 0;
		}
		$feeds[] = $feed;
		Feeds::save_feeds( $feeds );
		$this->back();
	}

	public function del_feed(): void {
		$this->guard( 'gasf_feeds_edit' );
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		Feeds::save_feeds( array_filter( Feeds::feeds(), static fn( $f ) => ( $f['id'] ?? '' ) !== $id ) );
		$this->back();
	}

	public function toggle_feed(): void {
		$this->guard( 'gasf_feeds_edit' );
		$id    = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$feeds = array_map( static function ( $f ) use ( $id ) {
			if ( ( $f['id'] ?? '' ) === $id ) {
				$f['enabled'] = empty( $f['enabled'] );
			}
			return $f;
		}, Feeds::feeds() );
		Feeds::save_feeds( $feeds );
		$this->back();
	}

	/** Import FB token(s) from MEC + ICS sources from the legacy Calendar Sync. */
	public function import_mec(): void {
		$this->guard( 'gasf_feeds_edit' );
		$feeds = Feeds::feeds();

		$mec = get_option( 'mec_advimp_auth_facebook' );
		if ( is_array( $mec ) ) {
			foreach ( $mec as $entry ) {
				if ( empty( $entry['access_token'] ) ) {
					continue;
				}
				$feeds[] = [
					'id' => Feeds::new_id(), 'type' => 'facebook', 'label' => sanitize_text_field( (string) ( $entry['title'] ?? 'Facebook (MEC)' ) ),
					'enabled' => false, 'dest_gasf' => true, 'dest_google' => false,
					'page_id' => 'GermanTampa', 'access_token' => (string) $entry['access_token'], 'expire_at' => (int) ( $entry['expire_at'] ?? 0 ),
				];
			}
		}
		foreach ( (array) get_option( 'gasf_calsync_sources', [] ) as $src ) {
			if ( empty( $src['url'] ) ) {
				continue;
			}
			$feeds[] = [
				'id' => Feeds::new_id(), 'type' => 'ics', 'label' => sanitize_text_field( (string) ( $src['label'] ?? 'ICS' ) ),
				'enabled' => false, 'dest_gasf' => false, 'dest_google' => true, 'url' => esc_url_raw( (string) $src['url'] ),
			];
		}
		Feeds::save_feeds( $feeds );
		$this->back();
	}

	public function run(): void {
		$this->guard( 'gasf_feeds_run' );
		$dry  = ! empty( $_POST['dry'] );
		$only = sanitize_text_field( wp_unslash( $_POST['only'] ?? '' ) );
		$stats = Feeds::run( $dry, $only );
		set_transient( 'gasf_feeds_result_' . get_current_user_id(), $stats, 120 );
		$this->back();
	}

	/* ---- render ------------------------------------------------------- */

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled = Feeds::enabled();
		$feeds   = Feeds::feeds();
		$gcal    = Feeds::gcal();
		$last    = (array) get_option( Feeds::OPT_LAST_RUN, [] );
		$log     = (array) get_option( Feeds::OPT_LOG, [] );
		$expiry  = Feeds::soonest_expiry_days();
		$result  = get_transient( 'gasf_feeds_result_' . get_current_user_id() );
		delete_transient( 'gasf_feeds_result_' . get_current_user_id() );
		$u = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Event Feeds', 'gasf-events' ); ?></h1>
			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Sync is OFF.', 'gasf-events' ); ?></strong> <?php esc_html_e( 'Nothing runs automatically. Use “Dry run” to preview. The existing MEC importer + Calendar Sync keep running until cutover.', 'gasf-events' ); ?></p></div>
			<?php endif; ?>
			<?php if ( null !== $expiry && $expiry < 14 ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: %d days */ __( '⚠ A Facebook token expires in %d days.', 'gasf-events' ), $expiry ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $result ) : ?>
				<div class="notice notice-info"><p><strong><?php echo $result['dry'] ? esc_html__( 'Dry run', 'gasf-events' ) : esc_html__( 'Run', 'gasf-events' ); ?>:</strong>
					<?php echo esc_html( sprintf( 'GASF created %d · updated %d · drafted %d · skipped %d  |  Google +%d ~%d -%d', $result['created'], $result['updated'], $result['drafted'], $result['skipped'], $result['google']['inserted'], $result['google']['updated'], $result['google']['deleted'] ) ); ?>
					<?php if ( ! empty( $result['errors'] ) ) : ?><br><span style="color:#b32d2e;"><?php echo esc_html( implode( ' | ', $result['errors'] ) ); ?></span><?php endif; ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Global', 'gasf-events' ); ?></h2>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_feeds_gate' ); ?>
				<input type="hidden" name="action" value="gasf_feeds_gate">
				<p><label><input type="checkbox" name="enable" value="1" <?php checked( $enabled ); ?>> <strong><?php esc_html_e( 'Enable automatic sync (every 15 min)', 'gasf-events' ); ?></strong></label></p>
				<p><label><?php esc_html_e( 'Google Calendar ID (destination):', 'gasf-events' ); ?>
					<input type="text" name="calendar_id" size="48" value="<?php echo esc_attr( $gcal['calendar_id'] ); ?>" placeholder="…@group.calendar.google.com"></label>
					<span style="color:<?php echo Google_Calendar::available() ? '#46810b' : '#b32d2e'; ?>;"><?php echo Google_Calendar::available() ? esc_html__( '✓ key found', 'gasf-events' ) : esc_html__( '✗ service-account key not found', 'gasf-events' ); ?></span>
				</p>
				<?php submit_button( __( 'Save', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Status', 'gasf-events' ); ?></h2>
			<table class="widefat striped" style="max-width:680px;"><tbody>
				<tr><th><?php esc_html_e( 'Last run', 'gasf-events' ); ?></th><td><?php echo $last ? esc_html( wp_date( 'M j, Y g:i a', (int) $last['ts'] ) ) : '—'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Next scheduled', 'gasf-events' ); ?></th><td><?php $n = wp_next_scheduled( Feeds::CRON_HOOK ); echo $n ? esc_html( wp_date( 'M j, g:i a', $n ) ) : esc_html__( 'not scheduled (off)', 'gasf-events' ); ?></td></tr>
			</tbody></table>
			<p>
				<form method="post" action="<?php echo $u; ?>" style="display:inline;"><?php wp_nonce_field( 'gasf_feeds_run' ); ?><input type="hidden" name="action" value="gasf_feeds_run"><input type="hidden" name="dry" value="1"><?php submit_button( __( 'Dry run (no writes)', 'gasf-events' ), 'secondary', '', false ); ?></form>
				<form method="post" action="<?php echo $u; ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Run a real sync now?', 'gasf-events' ) ); ?>');"><?php wp_nonce_field( 'gasf_feeds_run' ); ?><input type="hidden" name="action" value="gasf_feeds_run"><?php submit_button( __( 'Sync now', 'gasf-events' ), 'primary', '', false ); ?></form>
			</p>

			<h2><?php esc_html_e( 'Feeds', 'gasf-events' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Label', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Type', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Source', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Destinations', 'gasf-events' ); ?></th><th><?php esc_html_e( 'On?', 'gasf-events' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php if ( ! $feeds ) : ?><tr><td colspan="6"><?php esc_html_e( 'No feeds yet.', 'gasf-events' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $feeds as $f ) :
						$dests = array_filter( [ ! empty( $f['dest_gasf'] ) ? 'GASF' : '', ! empty( $f['dest_google'] ) ? 'Google' : '' ] );
						$src   = 'ics' === ( $f['type'] ?? '' ) ? ( $f['url'] ?? '' ) : ( $f['page_id'] ?? '' );
						?>
						<tr>
							<td><?php echo esc_html( $f['label'] ?? '' ); ?></td>
							<td><?php echo esc_html( strtoupper( $f['type'] ?? '' ) ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( mb_strimwidth( (string) $src, 0, 48, '…' ) ); ?></code></td>
							<td><?php echo esc_html( $dests ? implode( ' + ', $dests ) : '—' ); ?></td>
							<td><?php echo ! empty( $f['enabled'] ) ? '✓' : '—'; ?></td>
							<td>
								<form method="post" action="<?php echo $u; ?>" style="display:inline;"><?php wp_nonce_field( 'gasf_feeds_edit' ); ?><input type="hidden" name="action" value="gasf_feeds_toggle"><input type="hidden" name="id" value="<?php echo esc_attr( $f['id'] ); ?>"><button class="button-link"><?php echo ! empty( $f['enabled'] ) ? esc_html__( 'Disable', 'gasf-events' ) : esc_html__( 'Enable', 'gasf-events' ); ?></button></form> |
								<form method="post" action="<?php echo $u; ?>" style="display:inline;"><?php wp_nonce_field( 'gasf_feeds_run' ); ?><input type="hidden" name="action" value="gasf_feeds_run"><input type="hidden" name="dry" value="1"><input type="hidden" name="only" value="<?php echo esc_attr( $f['id'] ); ?>"><button class="button-link"><?php esc_html_e( 'Test', 'gasf-events' ); ?></button></form> |
								<form method="post" action="<?php echo $u; ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Remove feed?', 'gasf-events' ) ); ?>');"><?php wp_nonce_field( 'gasf_feeds_edit' ); ?><input type="hidden" name="action" value="gasf_feeds_del"><input type="hidden" name="id" value="<?php echo esc_attr( $f['id'] ); ?>"><button class="button-link delete"><?php esc_html_e( 'Remove', 'gasf-events' ); ?></button></form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Add a Facebook page', 'gasf-events' ); ?></h3>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_feeds_edit' ); ?>
				<input type="hidden" name="action" value="gasf_feeds_add"><input type="hidden" name="type" value="facebook">
				<input type="text" name="label" placeholder="<?php esc_attr_e( 'Label', 'gasf-events' ); ?>" required>
				<input type="text" name="page_id" placeholder="<?php esc_attr_e( 'Page id / username', 'gasf-events' ); ?>" value="GermanTampa" required>
				<input type="text" name="access_token" placeholder="<?php esc_attr_e( 'Page access token', 'gasf-events' ); ?>" size="32" required>
				<input type="date" name="expire_at" title="<?php esc_attr_e( 'Token expiry', 'gasf-events' ); ?>">
				<label><input type="checkbox" name="dest_gasf" value="1" checked> <?php esc_html_e( 'GASF', 'gasf-events' ); ?></label>
				<label><input type="checkbox" name="dest_google" value="1"> <?php esc_html_e( 'Google', 'gasf-events' ); ?></label>
				<?php submit_button( __( 'Add', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>

			<h3><?php esc_html_e( 'Add an iCal (.ics) feed', 'gasf-events' ); ?></h3>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_feeds_edit' ); ?>
				<input type="hidden" name="action" value="gasf_feeds_add"><input type="hidden" name="type" value="ics">
				<input type="text" name="label" placeholder="<?php esc_attr_e( 'Label', 'gasf-events' ); ?>" required>
				<input type="url" name="url" placeholder="https://…/calendar.ics" size="40" required>
				<label><input type="checkbox" name="dest_gasf" value="1" checked> <?php esc_html_e( 'GASF', 'gasf-events' ); ?></label>
				<label><input type="checkbox" name="dest_google" value="1"> <?php esc_html_e( 'Google', 'gasf-events' ); ?></label>
				<?php submit_button( __( 'Add', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>

			<form method="post" action="<?php echo $u; ?>" style="margin-top:8px;"><?php wp_nonce_field( 'gasf_feeds_edit' ); ?><input type="hidden" name="action" value="gasf_feeds_import_mec"><?php submit_button( __( 'Import feeds from MEC + Calendar Sync', 'gasf-events' ), 'link', '', false ); ?></form>

			<h2><?php esc_html_e( 'Publishing destinations', 'gasf-events' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Outbound targets that the “Publish to …” bulk action on All Events can send to. Any event (manual, Facebook, or ICS) can be published; once published it stays in sync on every edit.', 'gasf-events' ); ?></p>
			<?php $eb = Eventbrite::config(); ?>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_feeds_eb' ); ?>
				<input type="hidden" name="action" value="gasf_feeds_eventbrite">
				<h3 style="margin-bottom:4px;">Eventbrite</h3>
				<p>
					<label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $eb['enabled'] ) ); ?>> <?php esc_html_e( 'Enabled', 'gasf-events' ); ?></label><br>
					<input type="text" name="organization_id" value="<?php echo esc_attr( $eb['organization_id'] ); ?>" placeholder="<?php esc_attr_e( 'Organization ID', 'gasf-events' ); ?>"><br>
					<input type="text" name="token" value="<?php echo esc_attr( $eb['token'] ? substr( $eb['token'], 0, 4 ) . '…' : '' ); ?>" placeholder="<?php esc_attr_e( 'Private token (leave masked value to keep)', 'gasf-events' ); ?>" size="40">
				</p>
				<?php submit_button( __( 'Save Eventbrite', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>

			<?php if ( $log ) : ?>
				<h2><?php esc_html_e( 'Recent runs', 'gasf-events' ); ?></h2>
				<textarea readonly rows="8" style="width:100%;max-width:900px;font-family:monospace;font-size:12px;"><?php echo esc_textarea( implode( "\n", $log ) ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}
}
