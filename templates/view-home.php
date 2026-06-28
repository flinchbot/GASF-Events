<?php
/**
 * "Home" agenda view — a compact list of the next few upcoming events, laid out
 * like the old MEC modern-list home block (#12976): a coloured date block on the
 * left, title + time in the middle, a details button on the right.
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
<?php
// Alternate the original home-page teal / salmon accent per event.
$accents = [ '#0f6e56', '#e07a5f' ];
?>
<div class="gasf-home">
	<?php foreach ( $events as $i => $e ) :
		$s = $e->start();
		?>
		<article class="gasf-home__item" style="--e-color:<?php echo esc_attr( $accents[ $i % 2 ] ); ?>">
			<a class="gasf-home__date" href="<?php echo esc_url( $e->permalink() ); ?>" aria-hidden="true" tabindex="-1">
				<span class="gasf-home__dow"><?php echo esc_html( $s ? wp_date( 'D', $e->start_ts() ) : '' ); ?></span>
				<span class="gasf-home__day"><?php echo esc_html( $s ? wp_date( 'j', $e->start_ts() ) : '–' ); ?></span>
				<span class="gasf-home__mon"><?php echo esc_html( $s ? wp_date( 'M', $e->start_ts() ) : '' ); ?></span>
			</a>
			<div class="gasf-home__body">
				<h4 class="gasf-home__title">
					<a href="<?php echo esc_url( $e->permalink() ); ?>"><span aria-hidden="true"><?php echo esc_html( $e->icon() ); ?></span> <?php echo esc_html( $e->title() ); ?></a>
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
