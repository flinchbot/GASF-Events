<?php
/**
 * "Home" agenda view — a compact list of the next few upcoming events, styled to
 * match the old MEC modern-list home block (#12976): a teal date on the left
 * (big day number), a salmon event-name link, time/location below. No emoji,
 * no button — the event name is the link. Inherits the theme font.
 *
 * @package GASF_Events
 * @var Event[] $events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

if ( ! $events ) {
	echo '<p class="gasf-empty">' . esc_html__( 'No upcoming events.', 'gasf-events' ) . '</p>';
	return;
}
$tf = get_option( 'time_format' );
?>
<div class="gasf-home">
	<?php foreach ( $events as $e ) :
		$s = $e->start();
		?>
		<article class="gasf-home__item">
			<a class="gasf-home__date" href="<?php echo esc_url( $e->permalink() ); ?>" aria-hidden="true" tabindex="-1">
				<span class="gasf-home__day"><?php echo esc_html( $s ? wp_date( 'j', $e->start_ts() ) : '–' ); ?></span>
				<span class="gasf-home__mon"><?php echo esc_html( $s ? wp_date( 'M', $e->start_ts() ) : '' ); ?></span>
				<span class="gasf-home__dow"><?php echo esc_html( $s ? wp_date( 'l', $e->start_ts() ) : '' ); ?></span>
			</a>
			<div class="gasf-home__body">
				<h4 class="gasf-home__title">
					<a href="<?php echo esc_url( $e->permalink() ); ?>"><?php echo esc_html( $e->title() ); ?></a>
				</h4>
				<p class="gasf-home__meta">
					<?php
					if ( $e->is_all_day() || $e->hide_time() ) {
						esc_html_e( 'All day', 'gasf-events' );
					} elseif ( $s ) {
						echo esc_html( wp_date( $tf, $e->start_ts() ) );
						if ( $e->end() && ! $e->hide_end() ) {
							echo ' – ' . esc_html( wp_date( $tf, $e->end_ts() ) );
						}
					}
					?>
					<?php if ( $e->status() ) : ?><span class="gasf-status gasf-status--<?php echo esc_attr( $e->status() ); ?>"><?php echo esc_html( $e->status_label() ); ?></span><?php endif; ?>
				</p>
			</div>
		</article>
	<?php endforeach; ?>
</div>
