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
 * @var string $bayern_filter
 * @var array  $type_rules
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

/**
 * One rule row. Used for both the saved rows and the <template> the "Add rule"
 * button clones, so the two can never drift. The index in the field name is
 * rewritten by reindex() in JS after every add/remove/reorder — PHP needs real
 * indices here (bare `[]` would scatter match/icon/color into separate rows).
 */
$render_rule_row = static function ( array $rule, int $i ) {
	$name  = Settings::OPT_TYPE_RULES;
	$icon  = (string) ( $rule['icon'] ?? '' );
	$color = (string) ( $rule['color'] ?? '' );
	$swatch = '' !== $color ? $color : Settings::DEFAULT_TYPE_COLOR;
	?>
	<tr>
		<td><input type="text" class="regular-text gasf-rule-match" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][match]" value="<?php echo esc_attr( (string) ( $rule['match'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Euchre', 'gasf-events' ); ?>"></td>
		<td><input type="text" size="4" class="gasf-rule-icon" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][icon]" value="<?php echo esc_attr( $icon ); ?>" placeholder="🃏"></td>
		<td class="gasf-rule-color">
			<input type="color" class="gasf-rule-swatch" value="<?php echo esc_attr( $swatch ); ?>" aria-label="<?php esc_attr_e( 'Pick colour', 'gasf-events' ); ?>">
			<input type="text" size="9" class="gasf-rule-hex" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][color]" value="<?php echo esc_attr( $color ); ?>" placeholder="<?php echo esc_attr( Settings::DEFAULT_TYPE_COLOR ); ?>">
		</td>
		<td>
			<span class="gasf-rule-preview" style="--e-color:<?php echo esc_attr( $swatch ); ?>">
				<span class="gasf-rule-preview__icon"><?php echo esc_html( '' !== $icon ? $icon : '📅' ); ?></span>
				<span class="gasf-rule-preview__text"><?php echo esc_html( '' !== trim( (string) ( $rule['match'] ?? '' ) ) ? $rule['match'] : __( 'Event title', 'gasf-events' ) ); ?></span>
			</span>
		</td>
		<td class="gasf-rule-actions">
			<button type="button" class="button button-small gasf-rule-up" aria-label="<?php esc_attr_e( 'Move rule up', 'gasf-events' ); ?>">&uarr;</button>
			<button type="button" class="button button-small gasf-rule-down" aria-label="<?php esc_attr_e( 'Move rule down', 'gasf-events' ); ?>">&darr;</button>
			<button type="button" class="button button-small gasf-rule-remove" aria-label="<?php esc_attr_e( 'Remove rule', 'gasf-events' ); ?>">&times;</button>
		</td>
	</tr>
	<?php
};
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

		<h2><?php esc_html_e( 'Event list filters', 'gasf-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Which events the preset shortcode lists show. Each is a case-insensitive "title contains" match — an event appears when this text occurs anywhere in its title. A blank field falls back to its default; it never matches everything.', 'gasf-events' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="gasf_dinner_filter"><?php esc_html_e( 'Dinner Nights', 'gasf-events' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="gasf_dinner_filter" name="<?php echo esc_attr( Settings::OPT_DINNER_FILTER ); ?>" value="<?php echo esc_attr( $dinner_filter ); ?>">
					<p class="description"><?php
						printf(
							/* translators: 1: [gasf_dinner_events] shortcode, 2: /dinner-night/ path */
							esc_html__( 'Feeds %1$s on %2$s. Default "Dinner" — matches both "Dinner Night at the German American Society" and "Are You Ready for Oktoberfest Dinner and Dance".', 'gasf-events' ),
							'<code>[gasf_dinner_events]</code>',
							'<code>/dinner-night/</code>'
						);
					?></p>
				</td></tr>
			<tr><th><label for="gasf_bayern_filter"><?php esc_html_e( 'FC Bayern matches', 'gasf-events' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="gasf_bayern_filter" name="<?php echo esc_attr( Settings::OPT_BAYERN_FILTER ); ?>" value="<?php echo esc_attr( $bayern_filter ); ?>">
					<p class="description"><?php
						printf(
							/* translators: %s: [gasf_bayern_events] shortcode */
							esc_html__( 'Feeds %s. Default "FC Bayern v" — the trailing "v" is deliberate: as a contains-match it catches "FC Bayern v X", "FC Bayern vs X" and "DFB Pokalfinale FC Bayern v Stuttgart", without matching an event that merely mentions the club. If you change the naming standard for match events, change it here to match.', 'gasf-events' ),
							'<code>[gasf_bayern_events]</code>'
						);
					?></p>
				</td></tr>
		</table>

		<h2><?php esc_html_e( 'Calendar icons &amp; colours', 'gasf-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'The emoji and the soft background colour on each event in the calendar. Each rule is a case-insensitive "title contains" match — the first rule whose text appears anywhere in the event title wins, so put the specific ones above the general ones ("FC Bayern v" above "Bayern").', 'gasf-events' ); ?></p>
		<p class="description"><?php esc_html_e( 'Events matching no rule keep the icon and colour the plugin guesses from their title and description. Leave the emoji or the colour blank to keep that guess and override only the other one. Tip: press Windows + . to open the emoji picker.', 'gasf-events' ); ?></p>
		<table class="widefat striped gasf-rules" id="gasf-rules">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Title contains', 'gasf-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Emoji', 'gasf-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Colour', 'gasf-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Preview', 'gasf-events' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'gasf-events' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_values( $type_rules ) as $i => $rule ) { $render_rule_row( (array) $rule, (int) $i ); } ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="gasf-rule-add"><?php esc_html_e( '+ Add rule', 'gasf-events' ); ?></button></p>
		<?php // Inert until cloned, so this row is never submitted as a phantom rule. ?>
		<template id="gasf-rule-tpl"><?php $render_rule_row( [ 'match' => '', 'icon' => '', 'color' => '' ], 0 ); ?></template>

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
<style>
/* Preview mirrors .gasf-chip on the front end (assets/css/gasf-events.css) so
   what the admin picks here is what the calendar shows. */
.gasf-rules { margin-top:8px; max-width:920px; }
.gasf-rules td, .gasf-rules th { vertical-align:middle; }
.gasf-rule-color { white-space:nowrap; }
.gasf-rule-swatch { vertical-align:middle; width:34px; height:28px; padding:1px; cursor:pointer; }
.gasf-rule-icon { text-align:center; font-size:16px; }
.gasf-rule-actions { white-space:nowrap; text-align:right; }
.gasf-rule-preview {
	display:inline-flex; gap:6px; align-items:center; max-width:100%;
	padding:3px 8px; border-radius:5px; font-size:12px; line-height:1.3; color:#1d2327;
	border-left:3px solid var(--e-color,#5a6b85);
	background:linear-gradient(180deg, color-mix(in srgb, var(--e-color,#5a6b85) 7%, #fff), color-mix(in srgb, var(--e-color,#5a6b85) 20%, #fff));
}
.gasf-rule-preview__text { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
</style>
<script>
( function () {
	var table = document.getElementById( 'gasf-rules' );
	var tpl   = document.getElementById( 'gasf-rule-tpl' );
	var add   = document.getElementById( 'gasf-rule-add' );
	if ( ! table || ! tpl || ! add ) { return; }
	var tbody = table.tBodies[0];
	var HEX   = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;

	// PHP groups fields by the index in the name, so it has to match the row's
	// actual position after any add / remove / reorder.
	function reindex() {
		Array.prototype.forEach.call( tbody.rows, function ( tr, i ) {
			Array.prototype.forEach.call( tr.querySelectorAll( '[name]' ), function ( el ) {
				el.name = el.name.replace( /\[\d+\]/, '[' + i + ']' );
			} );
		} );
	}

	function refresh( tr ) {
		if ( ! tr ) { return; }
		var icon = tr.querySelector( '.gasf-rule-icon' ).value.trim();
		var hex  = tr.querySelector( '.gasf-rule-hex' ).value.trim();
		var text = tr.querySelector( '.gasf-rule-match' ).value.trim();
		var prev = tr.querySelector( '.gasf-rule-preview' );
		prev.style.setProperty( '--e-color', HEX.test( hex ) ? hex : '#5a6b85' );
		prev.querySelector( '.gasf-rule-preview__icon' ).textContent = icon || '📅';
		prev.querySelector( '.gasf-rule-preview__text' ).textContent = text || 'Event title';
	}

	table.addEventListener( 'input', function ( e ) {
		var tr = e.target.closest( 'tr' );
		if ( ! tr || tr.parentNode !== tbody ) { return; }
		// The picker and the hex box are two views of one value: typing a valid
		// hex moves the swatch, and moving the swatch rewrites the hex box (which
		// is the field that actually submits, so it still works without JS).
		if ( e.target.classList.contains( 'gasf-rule-swatch' ) ) {
			tr.querySelector( '.gasf-rule-hex' ).value = e.target.value;
		} else if ( e.target.classList.contains( 'gasf-rule-hex' ) ) {
			var v = e.target.value.trim();
			if ( HEX.test( v ) ) { tr.querySelector( '.gasf-rule-swatch' ).value = v; }
		}
		refresh( tr );
	} );

	table.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( 'button' );
		if ( ! btn ) { return; }
		var tr = btn.closest( 'tr' );
		if ( ! tr || tr.parentNode !== tbody ) { return; }
		if ( btn.classList.contains( 'gasf-rule-up' ) && tr.previousElementSibling ) {
			tbody.insertBefore( tr, tr.previousElementSibling );
		} else if ( btn.classList.contains( 'gasf-rule-down' ) && tr.nextElementSibling ) {
			tbody.insertBefore( tr.nextElementSibling, tr );
		} else if ( btn.classList.contains( 'gasf-rule-remove' ) ) {
			tr.remove();
		} else {
			return;
		}
		reindex();
	} );

	add.addEventListener( 'click', function () {
		tbody.appendChild( tpl.content.cloneNode( true ) );
		reindex();
		var tr = tbody.rows[ tbody.rows.length - 1 ];
		refresh( tr );
		tr.querySelector( '.gasf-rule-match' ).focus();
	} );
} )();
</script>
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
