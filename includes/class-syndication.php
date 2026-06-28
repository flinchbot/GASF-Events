<?php
/**
 * Outbound syndication — publishes ANY GASF event (manual, Facebook, ICS, …)
 * to one or more external destinations, and keeps them in sync on every
 * change. Destinations are pluggable: Eventbrite is the first, more can
 * register via the `gasf_events_destinations` filter. See ARCHITECTURE.md §7.x.
 *
 * Flow: an event is *marked* for a destination (e.g. the "Publish to
 * Eventbrite" bulk action) → reconcile() pushes it and records state in
 * `_gasf_published[<dest>]`. Thereafter ANY save of that event (manual edit or
 * a feed import via Event_Ingest, which both fire save_post) re-pushes to every
 * destination it's published to; trashing/cancelling removes it.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

/** A pluggable outbound publish target. */
interface Publish_Destination {
	public function key(): string;
	public function label(): string;
	public function is_enabled(): bool;
	/**
	 * Create or update the remote event. $state is the prior state for this
	 * destination (empty if never published). Return the new state:
	 * { id, url, status:'published'|'error', synced_at, error }.
	 */
	public function push( Event $event, array $state ): array;
	/** Remove the remote event (best effort). */
	public function remove( array $state ): void;
}

/** Registry of available destinations. */
final class Destinations {

	/** @return array<string,Publish_Destination> */
	public static function all(): array {
		// Memoized — the admin list calls get() per published destination per row.
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$eb   = new Eventbrite_Destination();
		$list = [ $eb->key() => $eb ];
		/**
		 * Register additional outbound destinations.
		 * @param array<string,Publish_Destination> $list
		 */
		$list  = apply_filters( 'gasf_events_destinations', $list );
		$cache = array_filter( $list, static fn( $d ) => $d instanceof Publish_Destination );
		return $cache;
	}

	public static function get( string $key ): ?Publish_Destination {
		return self::all()[ $key ] ?? null;
	}
}

final class Syndication {

	const QUEUE = 'gasf_events_pub_queue';

	private static $queued_this_request = false;

	const DRAIN_HOOK = 'gasf_events_drain';

	public function register_hooks(): void {
		add_action( 'save_post_' . GASF_EVENTS_CPT, [ $this, 'on_save' ], 99, 1 );
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
		add_action( 'shutdown', [ $this, 'maybe_process' ] );
		add_action( self::DRAIN_HOOK, [ self::class, 'process_queue' ] );
	}

	/* ---- intent + queue ----------------------------------------------- */

	/** Mark an event to be published to a destination (e.g. bulk action). */
	public static function mark_for_publish( int $post_id, string $dest_key ): void {
		$intent = (array) get_post_meta( $post_id, Meta::PUBLISH_INTENT, true );
		if ( ! in_array( $dest_key, $intent, true ) ) {
			$intent[] = $dest_key;
			update_post_meta( $post_id, Meta::PUBLISH_INTENT, $intent );
		}
		self::enqueue( $post_id );
	}

	public static function enqueue( int $post_id ): void {
		$q = (array) get_option( self::QUEUE, [] );
		$q[ $post_id ] = true;
		update_option( self::QUEUE, $q, false );
		self::$queued_this_request = true;
	}

	public function on_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || Series::is_generating() ) {
			return;
		}
		// Only sync events that are already published somewhere or pending.
		if ( get_post_meta( $post_id, Meta::PUBLISHED, true ) || get_post_meta( $post_id, Meta::PUBLISH_INTENT, true ) ) {
			self::enqueue( $post_id );
		}
	}

	public function on_transition( string $new, string $old, $post ): void {
		if ( ! $post instanceof \WP_Post || GASF_EVENTS_CPT !== $post->post_type ) {
			return;
		}
		if ( $new !== $old && get_post_meta( $post->ID, Meta::PUBLISHED, true ) ) {
			self::enqueue( (int) $post->ID ); // reconcile() will remove if no longer public.
		}
	}

	public function maybe_process(): void {
		if ( self::$queued_this_request ) {
			self::process_queue();
		}
	}

	/* ---- processing --------------------------------------------------- */

	/** Each destination push is up to 3 blocking HTTP calls; cap per request so
	 *  a large batch can't exceed max_execution_time. Leftovers drain next run. */
	const BATCH = 25;

	public static function process_queue(): void {
		$q = (array) get_option( self::QUEUE, [] );
		if ( ! $q ) {
			return;
		}
		$ids       = array_keys( $q );
		$batch     = array_slice( $ids, 0, self::BATCH );
		$remaining = array_slice( $ids, self::BATCH );
		// Persist the remainder so a fatal mid-batch doesn't lose the whole queue.
		update_option( self::QUEUE, array_fill_keys( $remaining, true ), false );
		foreach ( $batch as $post_id ) {
			try {
				self::reconcile( (int) $post_id );
			} catch ( \Throwable $e ) {
				// Isolate one event's failure from the rest of the batch.
				continue;
			}
		}
		// Schedule a follow-up tick to drain anything beyond this request's cap.
		if ( $remaining && ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::DRAIN_HOOK );
		}
	}

	/**
	 * Push the event to every destination it's published to or marked for;
	 * remove it from all destinations if it's no longer a public event.
	 */
	public static function reconcile( int $post_id ): void {
		$event     = Event::get( $post_id );
		$published = (array) get_post_meta( $post_id, Meta::PUBLISHED, true );
		$intent    = (array) get_post_meta( $post_id, Meta::PUBLISH_INTENT, true );
		$targets   = array_unique( array_merge( array_keys( $published ), $intent ) );
		if ( ! $targets ) {
			return;
		}

		$post      = get_post( $post_id );
		$is_public = $event && $post && 'publish' === $post->post_status && 'cancelled' !== $event->status();

		foreach ( $targets as $key ) {
			$dest = Destinations::get( $key );
			if ( ! $dest || ! $dest->is_enabled() ) {
				continue;
			}
			$state = $published[ $key ] ?? [];
			if ( ! $is_public ) {
				if ( $state ) {
					$dest->remove( $state );
					unset( $published[ $key ] );
				}
				continue;
			}
			$published[ $key ] = $dest->push( $event, $state );
		}

		update_post_meta( $post_id, Meta::PUBLISHED, $published );
		delete_post_meta( $post_id, Meta::PUBLISH_INTENT );
	}
}
