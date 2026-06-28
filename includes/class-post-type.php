<?php
/**
 * Registers the `gasf_event` custom post type, its meta, and capabilities.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Post_Type {

	/** Capabilities granted to event managers. */
	const CAPS = [
		'edit_gasf_event', 'read_gasf_event', 'delete_gasf_event',
		'edit_gasf_events', 'edit_others_gasf_events', 'publish_gasf_events',
		'read_private_gasf_events', 'delete_gasf_events', 'delete_private_gasf_events',
		'delete_published_gasf_events', 'delete_others_gasf_events',
		'edit_private_gasf_events', 'edit_published_gasf_events',
	];

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ Meta::class, 'register' ] );
		add_action( 'admin_init', [ $this, 'maybe_grant_caps' ] );
	}

	/**
	 * Self-heal capabilities on version change — covers git-pull updates that
	 * never re-run activation, and roles added after install.
	 */
	public function maybe_grant_caps(): void {
		if ( get_option( 'gasf_events_caps_ver' ) !== GASF_EVENTS_VERSION ) {
			self::grant_caps();
			update_option( 'gasf_events_caps_ver', GASF_EVENTS_VERSION, false );
		}
	}

	public function register_post_type(): void {
		$labels = [
			'name'               => _x( 'Events', 'post type general name', 'gasf-events' ),
			'singular_name'      => _x( 'Event', 'post type singular name', 'gasf-events' ),
			'menu_name'          => _x( 'Events', 'admin menu', 'gasf-events' ),
			'add_new'            => __( 'Add New', 'gasf-events' ),
			'add_new_item'       => __( 'Add New Event', 'gasf-events' ),
			'edit_item'          => __( 'Edit Event', 'gasf-events' ),
			'new_item'           => __( 'New Event', 'gasf-events' ),
			'view_item'          => __( 'View Event', 'gasf-events' ),
			'search_items'       => __( 'Search Events', 'gasf-events' ),
			'not_found'          => __( 'No events found', 'gasf-events' ),
			'not_found_in_trash' => __( 'No events found in Trash', 'gasf-events' ),
			'all_items'          => __( 'All Events', 'gasf-events' ),
		];

		register_post_type( GASF_EVENTS_CPT, [
			'labels'              => $labels,
			'public'              => true,
			'has_archive'         => true,
			'show_in_rest'        => true,
			'rest_base'           => 'gasf-events',
			'menu_icon'           => 'dashicons-calendar-alt',
			'menu_position'       => 25,
			'supports'            => [ 'title', 'editor', 'thumbnail', 'revisions', 'author', 'custom-fields' ],
			'rewrite'             => [ 'slug' => GASF_EVENTS_REWRITE_SLUG, 'with_front' => false ],
			'capability_type'     => [ 'gasf_event', 'gasf_events' ],
			'map_meta_cap'        => true,
			'hierarchical'        => false,
		] );
	}

	/**
	 * Grant event capabilities to the given roles. Called on activation.
	 * Administrator gets everything; Editor acts as the "Maintainer".
	 */
	public static function grant_caps(): void {
		foreach ( [ 'administrator', 'editor' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::CAPS as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}
}
