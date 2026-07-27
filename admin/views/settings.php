<?php
/**
 * Event Settings page (venue / organizer / default image).
 *
 * @package GASF_Events
 * @var array  $venue
 * @var array  $organizer
 * @var int    $img_id
 * @var string $img_url
 * @var string $alert_email
 * @var string $dinner_filter
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Event Settings', 'gasf-events' ); ?></h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'gasf_events_settings' ); ?>

		<h2><?php esc_html_e( 'Home page', 'gasf-events' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Heroes', 'gasf-events' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPT_HEROES ); ?>" value="1" <?php checked( Settings::heroes_enabled() ); ?>> <?php esc_html_e( 'Enable the home-page hero banner', 'gasf-events' ); ?></label>
					<p class="description"><?php
						printf(
							/* translators: %s: [gas_hero] shortcode */
							esc_html__( 'Powers the %s banner and the two admin screens (Events → Heroes, Events → Recurring Heroes). Turn off to remove the banner and hide those screens.', 'gasf-events' ),
							'<code>[gas_hero]</code>'
						);
					?></p>
				</td></tr>
		</table>

		<h2><?php esc_html_e( 'Dinner Night list', 'gasf-events' ); ?></h2>
		<p class="description"><?php
			printf(
				/* translators: 1: [gasf_dinner_events] shortcode, 2: /dinner-night/ path */
				esc_html__( 'Which events the %1$s list (the calendar on %2$s) shows.', 'gasf-events' ),
				'<code>[gasf_dinner_events]</code>',
				'<code>/dinner-night/</code>'
			);
		?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="gasf_dinner_filter"><?php esc_html_e( 'Title contains', 'gasf-events' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="gasf_dinner_filter" name="<?php echo esc_attr( Settings::OPT_DINNER_FILTER ); ?>" value="<?php echo esc_attr( $dinner_filter ); ?>">
					<p class="description"><?php esc_html_e( 'An event appears on the dinner page when its title contains this text (capitalisation ignored, anywhere in the title). "Dinner" matches both "Dinner Night at the German American Society" and "Are You Ready for Oktoberfest Dinner and Dance". Left blank, it falls back to "Dinner" — it never matches everything.', 'gasf-events' ); ?></p>
				</td></tr>
		</table>

		<h2><?php esc_html_e( 'Venue', 'gasf-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'The default location for events. Used on event pages and in structured data.', 'gasf-events' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label><?php esc_html_e( 'Name', 'gasf-events' ); ?></label></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( Settings::OPT_VENUE ); ?>[name]" value="<?php echo esc_attr( $venue['name'] ); ?>"></td></tr>
			<tr><th><label><?php esc_html_e( 'Street', 'gasf-events' ); ?></label></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( Settings::OPT_VENUE ); ?>[street]" value="<?php echo esc_attr( $venue['street'] ); ?>"></td></tr>
			<tr><th><label><?php esc_html_e( 'City / State / ZIP', 'gasf-events' ); ?></label></th>
				<td>
					<input type="text" name="<?php echo esc_attr( Settings::OPT_VENUE ); ?>[city]" value="<?php echo esc_attr( $venue['city'] ); ?>" placeholder="<?php esc_attr_e( 'City', 'gasf-events' ); ?>">
					<input type="text" size="4" name="<?php echo esc_attr( Settings::OPT_VENUE ); ?>[state]" value="<?php echo esc_attr( $venue['state'] ); ?>" placeholder="<?php esc_attr_e( 'State', 'gasf-events' ); ?>">
					<input type="text" size="10" name="<?php echo esc_attr( Settings::OPT_VENUE ); ?>[zip]" value="<?php echo esc_attr( $venue['zip'] ); ?>" placeholder="<?php esc_attr_e( 'ZIP', 'gasf-events' ); ?>">
				</td></tr>
			<tr><th><label><?php esc_html_e( 'Map', 'gasf-events' ); ?></label></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPT_VENUE ); ?>[hide_map]" value="1" <?php checked( ! empty( $venue['hide_map'] ) ); ?>> <?php esc_html_e( 'Hide the map on event pages', 'gasf-events' ); ?></label></td></tr>
		</table>

		<h2><?php esc_html_e( 'Organizer', 'gasf-events' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><label><?php esc_html_e( 'Name', 'gasf-events' ); ?></label></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( Settings::OPT_ORGANIZER ); ?>[name]" value="<?php echo esc_attr( $organizer['name'] ); ?>"></td></tr>
			<tr><th><label><?php esc_html_e( 'URL', 'gasf-events' ); ?></label></th>
				<td><input type="url" class="regular-text" name="<?php echo esc_attr( Settings::OPT_ORGANIZER ); ?>[url]" value="<?php echo esc_attr( $organizer['url'] ); ?>"></td></tr>
		</table>

		<h2><?php esc_html_e( 'Default event image', 'gasf-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Used whenever an event has no cover image — in listings, on the page, and in Google structured data (which requires an image).', 'gasf-events' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Image', 'gasf-events' ); ?></th>
				<td>
					<input type="hidden" id="gasf_default_img" name="<?php echo esc_attr( Settings::OPT_DEFAULT_IMG ); ?>" value="<?php echo esc_attr( $img_id ); ?>">
					<div id="gasf_default_img_preview" style="margin-bottom:8px;">
						<?php if ( $img_url ) : ?><img src="<?php echo esc_url( $img_url ); ?>" style="max-width:240px;height:auto;border:1px solid #ccd0d4;"><?php endif; ?>
					</div>
					<button type="button" class="button" id="gasf_default_img_pick"><?php esc_html_e( 'Choose image', 'gasf-events' ); ?></button>
					<button type="button" class="button" id="gasf_default_img_clear"><?php esc_html_e( 'Clear', 'gasf-events' ); ?></button>
				</td></tr>
		</table>

		<h2><?php esc_html_e( 'Sync alerts', 'gasf-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'When the feed sync auto-unpublishes an event (missing from its source feed for 2 consecutive runs), send a summary email to this address. Leave blank to disable.', 'gasf-events' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="gasf_alert_email"><?php esc_html_e( 'Alert email', 'gasf-events' ); ?></label></th>
				<td><input type="email" class="regular-text" id="gasf_alert_email" name="<?php echo esc_attr( Alerts::OPT_EMAIL ); ?>" value="<?php echo esc_attr( $alert_email ); ?>" placeholder="alerts@example.com"></td></tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
<script>
( function () {
	// Attach on DOMContentLoaded and test wp.media at CLICK time — not now.
	// The media library scripts are enqueued for this screen but may load in the
	// footer (after this inline block parses); checking wp.media up front would
	// wrongly bail and leave the button with no handler ("nothing happens").
	function boot() {
		var pick  = document.getElementById( 'gasf_default_img_pick' );
		var clear = document.getElementById( 'gasf_default_img_clear' );
		if ( ! pick ) { return; }
		var frame;
		pick.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( ! window.wp || ! wp.media ) {
				window.alert( 'The WordPress media library did not load — please reload the page and try again.' );
				return;
			}
			frame = frame || wp.media( { title: 'Select default image', button: { text: 'Use this image' }, multiple: false } );
			frame.off( 'select' ).on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				document.getElementById( 'gasf_default_img' ).value = a.id;
				document.getElementById( 'gasf_default_img_preview' ).innerHTML =
					'<img src="' + ( a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url ) + '" style="max-width:240px;height:auto;border:1px solid #ccd0d4;">';
			} );
			frame.open();
		} );
		if ( clear ) {
			clear.addEventListener( 'click', function () {
				document.getElementById( 'gasf_default_img' ).value = '';
				document.getElementById( 'gasf_default_img_preview' ).innerHTML = '';
			} );
		}
	}
	if ( document.readyState !== 'loading' ) { boot(); }
	else { document.addEventListener( 'DOMContentLoaded', boot ); }
} )();
</script>
