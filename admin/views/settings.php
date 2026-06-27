<?php
/**
 * Event Settings page (venue / organizer / default image).
 *
 * @package GASF_Events
 * @var array  $venue
 * @var array  $organizer
 * @var int    $img_id
 * @var string $img_url
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Event Settings', 'gasf-events' ); ?></h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'gasf_events_settings' ); ?>

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

		<?php submit_button(); ?>
	</form>
</div>
<script>
( function () {
	var pick = document.getElementById( 'gasf_default_img_pick' );
	if ( ! pick || ! window.wp || ! wp.media ) { return; }
	var frame;
	pick.addEventListener( 'click', function () {
		frame = frame || wp.media( { title: 'Select default image', multiple: false } );
		frame.off( 'select' ).on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON();
			document.getElementById( 'gasf_default_img' ).value = a.id;
			document.getElementById( 'gasf_default_img_preview' ).innerHTML =
				'<img src="' + ( a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url ) + '" style="max-width:240px;height:auto;border:1px solid #ccd0d4;">';
		} );
		frame.open();
	} );
	document.getElementById( 'gasf_default_img_clear' ).addEventListener( 'click', function () {
		document.getElementById( 'gasf_default_img' ).value = '';
		document.getElementById( 'gasf_default_img_preview' ).innerHTML = '';
	} );
} )();
</script>
