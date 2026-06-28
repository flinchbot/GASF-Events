<?php
/**
 * Branded single-event template. Loaded via Single::template() for
 * is_singular('gasf_event'). Wraps the theme header/footer so it inherits
 * the site chrome. See docs/ARCHITECTURE.md §5.2.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

get_header();

$event = Event::get( get_queried_object_id() );
if ( ! $event ) {
	get_footer();
	return;
}

$start   = $event->start();
$end     = $event->end();
$venue   = $event->venue();
$addr    = Add_To_Calendar::location_string( $event );
$map_url = $addr ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $addr ) : '';
$atc     = Add_To_Calendar::links( $event );
?>
<main class="gasf-single" id="gasf-single">
	<article>
		<header class="gasf-single__hero" style="background-image:url('<?php echo esc_url( $event->cover_url( 'full' ) ); ?>')">
			<div class="gasf-single__scrim"></div>
			<div class="gasf-single__heroinner">
				<?php if ( $event->status() ) : ?>
					<p class="gasf-single__statusbanner gasf-status--<?php echo esc_attr( $event->status() ); ?>">
						<?php echo esc_html( $event->status_label() ); ?>
						<?php $reason = $event->status_reason(); ?>
						<?php if ( $reason ) : ?>— <?php echo esc_html( $reason ); ?><?php endif; ?>
					</p>
				<?php endif; ?>
				<h1 class="gasf-single__title"><?php echo esc_html( $event->title() ); ?></h1>
				<?php if ( $start ) : ?>
					<p class="gasf-single__when">
						<?php
						if ( $event->is_all_day() || $event->hide_time() ) {
							echo esc_html( wp_date( 'l, F j, Y', $event->start_ts() ) );
							if ( $event->is_multiday() && $end ) {
								echo ' – ' . esc_html( wp_date( 'l, F j, Y', $event->end_ts() ) );
							}
						} else {
							echo esc_html( wp_date( 'l, F j, Y · ' . get_option( 'time_format' ), $event->start_ts() ) );
							if ( $end && ! $event->hide_end() ) {
								echo ' – ' . esc_html(
									$event->is_multiday()
										? wp_date( 'F j · ' . get_option( 'time_format' ), $event->end_ts() )
										: wp_date( get_option( 'time_format' ), $event->end_ts() )
								);
							}
						}
						?>
					</p>
				<?php endif; ?>
			</div>
		</header>

		<div class="gasf-single__grid">
			<div class="gasf-single__content">
				<?php
				$content = apply_filters( 'the_content', $event->description() );
				echo wp_kses_post( $content );
				?>

				<?php if ( $event->more_info_url() ) : ?>
					<p><a class="gasf-btn gasf-btn--primary" href="<?php echo esc_url( $event->more_info_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $event->more_info_title() ); ?></a></p>
				<?php endif; ?>
			</div>

			<aside class="gasf-single__aside">
				<?php if ( $atc ) : ?>
					<div class="gasf-atc" x-data="{ open:false }">
						<button type="button" class="gasf-btn gasf-btn--primary" @click="open=!open" aria-expanded="false" :aria-expanded="open"><?php esc_html_e( 'Add to calendar', 'gasf-events' ); ?></button>
						<ul class="gasf-atc__menu" x-show="open" x-cloak @click.outside="open=false">
							<?php foreach ( $atc as $link ) : ?>
								<li><a href="<?php echo esc_url( $link['url'] ); ?>"<?php echo 'apple' === $link['key'] ? '' : ' target="_blank" rel="noopener"'; ?>><?php echo esc_html( $link['label'] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
						<div class="gasf-atc__qr" data-gasf-qr="<?php echo esc_url( Add_To_Calendar::ics_url( $event ) ); ?>" title="<?php esc_attr_e( 'Scan to add to your phone', 'gasf-events' ); ?>"></div>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $venue['name'] ) ) : ?>
					<div class="gasf-single__venue">
						<h3><?php esc_html_e( 'Location', 'gasf-events' ); ?></h3>
						<p><strong><?php echo esc_html( $venue['name'] ); ?></strong><br><?php echo esc_html( trim( ( $venue['street'] ?? '' ) . ', ' . ( $venue['city'] ?? '' ) . ', ' . ( $venue['state'] ?? '' ) . ' ' . ( $venue['zip'] ?? '' ), ', ' ) ); ?></p>
						<?php if ( $map_url && empty( $venue['hide_map'] ) ) : ?>
							<a class="gasf-btn" href="<?php echo esc_url( $map_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View map', 'gasf-events' ); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<p class="gasf-single__back"><a href="<?php echo esc_url( get_post_type_archive_link( GASF_EVENTS_CPT ) ?: home_url( '/' ) ); ?>">&#8592; <?php esc_html_e( 'All events', 'gasf-events' ); ?></a></p>
			</aside>
		</div>
	</article>
</main>
<?php
get_footer();
