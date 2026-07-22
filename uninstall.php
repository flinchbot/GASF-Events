<?php
/**
 * Uninstall cleanup.
 *
 * Conservative on purpose: removes only the plugin's own options and the
 * capabilities it added. It does NOT delete `gasf_event` posts or `_gasf_*`
 * meta — event data is the club's, not the plugin's, and must survive an
 * accidental uninstall. (Permanent data removal, if ever wanted, is a
 * deliberate WP-CLI step, not an uninstall side effect.)
 *
 * @package GASF_Events
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'gasf_events_venue' );
delete_option( 'gasf_events_organizer' );
delete_option( 'gasf_events_default_image' );
delete_option( 'gasf_events_alert_email' );
delete_option( 'gasf_events_heroes_enabled' );
// NOTE: the scheduled-hero data (gasf_hero_entries / gasf_hero_recurring /
// gasf_hero_seeded / gasf_hero_lookahead_days) is club content, like the event
// posts — deliberately NOT deleted here, so an accidental uninstall doesn't wipe
// a maintainer's queued home-page banners.

// Credentials & integration config — remove on uninstall (these are not club
// content, unlike the event posts/meta, which are deliberately preserved).
delete_option( 'gasf_events_eventbrite' ); // Eventbrite private token
delete_option( 'gasf_events_feeds' );      // Facebook page access tokens + ICS feeds
delete_option( 'gasf_events_gcal' );
delete_option( 'gasf_events_enable_sync' );
delete_option( 'gasf_events_caps_ver' );
delete_transient( 'gasf_events_gcal_token' );

$caps = [
	'edit_gasf_event', 'read_gasf_event', 'delete_gasf_event',
	'edit_gasf_events', 'edit_others_gasf_events', 'publish_gasf_events',
	'read_private_gasf_events', 'delete_gasf_events', 'delete_private_gasf_events',
	'delete_published_gasf_events', 'delete_others_gasf_events',
	'edit_private_gasf_events', 'edit_published_gasf_events',
];
foreach ( [ 'administrator', 'editor' ] as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}
