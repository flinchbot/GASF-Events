<?php
/**
 * Uninstall cleanup.
 *
 * Conservative on purpose: removes only the plugin's own options and the
 * capabilities it added. It does NOT delete `gas_event` posts or `_gas_*`
 * meta — event data is the club's, not the plugin's, and must survive an
 * accidental uninstall. (Permanent data removal, if ever wanted, is a
 * deliberate WP-CLI step, not an uninstall side effect.)
 *
 * @package GASF_Events
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'gas_events_venue' );
delete_option( 'gas_events_organizer' );
delete_option( 'gas_events_default_image' );

$caps = [
	'edit_gas_event', 'read_gas_event', 'delete_gas_event',
	'edit_gas_events', 'edit_others_gas_events', 'publish_gas_events',
	'read_private_gas_events', 'delete_gas_events', 'delete_private_gas_events',
	'delete_published_gas_events', 'delete_others_gas_events',
	'edit_private_gas_events', 'edit_published_gas_events',
];
foreach ( [ 'administrator', 'editor' ] as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}
