<?php
/**
 * Signage / tile view — full-bleed auto-advancing carousel for the hallway
 * display. No interaction required; each slide shows the cover, title, date,
 * and a "scan to view" QR. Alpine drives the timer + crossfade.
 *
 * @package GASF_Events
 * @var Event[] $events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

if ( ! $events ) {
	echo '<div class="gasf-tiles gasf-tiles--empty"><p>' . esc_html__( 'No upcoming events.', 'gasf-events' ) . '</p></div>';
	return;
}
?>
<div class="gasf-tiles" x-data="gasfTiles(<?php echo (int) count( $events ); ?>)" x-init="start()">
	<?php foreach ( $events as $i => $e ) :
		$datefmt = $e->is_all_day() ? wp_date( 'l, F j', $e->start_ts() ) : wp_date( 'l, F j · ' . get_option( 'time_format' ), $e->start_ts() );
		?>
		<div class="gasf-tile" x-show="i === <?php echo (int) $i; ?>" x-transition.opacity.duration.700ms <?php echo 0 === $i ? '' : 'style="display:none"'; ?>>
			<div class="gasf-tile__bg" style="background-image:url('<?php echo esc_url( $e->cover_url( 'full' ) ); ?>')"></div>
			<div class="gasf-tile__scrim"></div>
			<div class="gasf-tile__body">
				<?php if ( $e->status() ) : ?><span class="gasf-tile__status"><?php echo esc_html( $e->status_label() ); ?></span><?php endif; ?>
				<h2 class="gasf-tile__title"><?php echo esc_html( $e->title() ); ?></h2>
				<p class="gasf-tile__date"><?php echo esc_html( $datefmt ); ?></p>
			</div>
			<div class="gasf-tile__qr" data-gasf-qr="<?php echo esc_url( $e->permalink() ); ?>" title="<?php esc_attr_e( 'Scan for details', 'gasf-events' ); ?>"></div>
		</div>
	<?php endforeach; ?>
	<div class="gasf-tiles__dots">
		<?php foreach ( $events as $i => $e ) : ?>
			<button type="button" class="gasf-dot" :class="{ 'is-on': i === <?php echo (int) $i; ?> }" @click="go(<?php echo (int) $i; ?>)" aria-label="<?php echo esc_attr( sprintf( /* translators: %d slide number */ __( 'Slide %d', 'gasf-events' ), $i + 1 ) ); ?>"></button>
		<?php endforeach; ?>
	</div>
</div>
