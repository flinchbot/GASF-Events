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
