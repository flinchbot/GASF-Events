<?php
/**
 * Per-event view stats. A tiny JS beacon (or the kiosk) POSTs to a REST
 * endpoint that increments a cache-safe counter — server-side counting would
 * miss Cloudflare/SpeedyCache-cached HTML. Bots and logged-in editors are
 * excluded; web vs. kiosk are split. See docs/ARCHITECTURE.md §4.5.1.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Stats {

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'route' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'localize' ], 30 );
		add_filter( 'post_row_actions', [ $this, 'row_action' ], 20, 2 );
		add_action( 'admin_menu', [ $this, 'menu' ] );
	}

	/* ---- counting ----------------------------------------------------- */

	public function route(): void {
		register_rest_route( 'gasf-events/v1', '/view', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'args'                => [
				'id'  => [ 'required' => true, 'sanitize_callback' => 'absint' ],
				'ctx' => [ 'default' => 'web', 'sanitize_callback' => 'sanitize_key' ],
			],
			'callback'            => [ $this, 'record' ],
		] );
	}

	public function record( \WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$ctx = 'kiosk' === $req['ctx'] ? 'kiosk' : 'web';
		if ( ! Event::get( $id ) ) {
			return new \WP_REST_Response( [ 'ok' => false ], 404 );
		}
		// Skip obvious bots and de-dupe per viewer/event within 6h.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( $ua && preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit/i', $ua ) ) {
			return new \WP_REST_Response( [ 'ok' => true, 'counted' => false ] );
		}
		// Dedup on IP + event only — NOT the client-controlled UA (which could be
		// rotated to bypass the throttle and bloat the transient table).
		$ip          = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$fingerprint = md5( $ip . '|' . $id );
		$tkey        = 'gasf_v_' . $fingerprint;
		if ( get_transient( $tkey ) ) {
			return new \WP_REST_Response( [ 'ok' => true, 'counted' => false ] );
		}
		set_transient( $tkey, 1, 6 * HOUR_IN_SECONDS );

		update_post_meta( $id, Meta::VIEWS, (int) get_post_meta( $id, Meta::VIEWS, true ) + 1 );
		if ( 'kiosk' === $ctx ) {
			update_post_meta( $id, Meta::VIEWS_KIOSK, (int) get_post_meta( $id, Meta::VIEWS_KIOSK, true ) + 1 );
		}
		$daily = (array) get_post_meta( $id, Meta::VIEWS_DAILY, true );
		$today = wp_date( 'Ymd' );
		$daily[ $today ] = ( $daily[ $today ] ?? 0 ) + 1;
		if ( count( $daily ) > 120 ) {
			ksort( $daily );
			$daily = array_slice( $daily, -120, null, true );
		}
		update_post_meta( $id, Meta::VIEWS_DAILY, $daily );

		return new \WP_REST_Response( [ 'ok' => true, 'counted' => true ] );
	}

	/** Expose the beacon target on single event pages (not to logged-in editors). */
	public function localize(): void {
		if ( ! is_singular( GASF_EVENTS_CPT ) || ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) ) {
			return;
		}
		wp_add_inline_script(
			'gasf-events',
			'window.GASF_VIEW=' . wp_json_encode( [ 'id' => get_queried_object_id(), 'url' => rest_url( 'gasf-events/v1/view' ), 'ctx' => 'web' ] ) . ';',
			'before'
		);
	}

	/* ---- admin stats panel -------------------------------------------- */

	public function row_action( array $actions, \WP_Post $post ): array {
		if ( GASF_EVENTS_CPT === $post->post_type ) {
			$actions['gasf_stats'] = '<a href="' . esc_url( self::page_url( $post->ID ) ) . '">' . esc_html__( 'Stats', 'gasf-events' ) . '</a>';
		}
		return $actions;
	}

	public static function page_url( int $id ): string {
		return admin_url( 'edit.php?post_type=' . GASF_EVENTS_CPT . '&page=gasf-events-stats&event=' . $id );
	}

	public function menu(): void {
		add_submenu_page( null, __( 'Event Stats', 'gasf-events' ), __( 'Event Stats', 'gasf-events' ), 'edit_gasf_events', 'gasf-events-stats', [ $this, 'render' ] );
	}

	public function render(): void {
		$id = isset( $_GET['event'] ) ? (int) $_GET['event'] : 0;
		$e  = Event::get( $id );
		if ( ! $e ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Event not found.', 'gasf-events' ) . '</p></div>';
			return;
		}
		$total = $e->views();
		$kiosk = $e->views_kiosk();
		$web   = max( 0, $total - $kiosk );
		$daily = (array) get_post_meta( $id, Meta::VIEWS_DAILY, true );
		ksort( $daily );
		$last30 = array_slice( $daily, -30, null, true );
		$recent = array_sum( $last30 );
		$max    = $last30 ? max( $last30 ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Event Stats', 'gasf-events' ); ?>: <?php echo esc_html( $e->title() ); ?></h1>
			<p><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">&#8592; <?php esc_html_e( 'Edit event', 'gasf-events' ); ?></a></p>
			<table class="widefat striped" style="max-width:420px;"><tbody>
				<tr><th><?php esc_html_e( 'Total views', 'gasf-events' ); ?></th><td><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong></td></tr>
				<tr><th><?php esc_html_e( 'Web', 'gasf-events' ); ?></th><td><?php echo esc_html( number_format_i18n( $web ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Kiosk', 'gasf-events' ); ?></th><td><?php echo esc_html( number_format_i18n( $kiosk ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Last 30 days', 'gasf-events' ); ?></th><td><?php echo esc_html( number_format_i18n( $recent ) ); ?></td></tr>
			</tbody></table>
			<?php if ( $last30 ) : ?>
				<h2><?php esc_html_e( 'Last 30 days', 'gasf-events' ); ?></h2>
				<div style="display:flex;align-items:flex-end;gap:3px;height:120px;border-bottom:1px solid #ccc;max-width:640px;">
					<?php foreach ( $last30 as $day => $n ) : $h = $max ? max( 2, (int) round( $n / $max * 110 ) ) : 2; ?>
						<div title="<?php echo esc_attr( $day . ': ' . $n ); ?>" style="flex:1;height:<?php echo (int) $h; ?>px;background:#b8860b;"></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
