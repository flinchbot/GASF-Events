<?php
/**
 * "Home" agenda view — a compact list of the next few upcoming events, styled to
 * match the old MEC modern-list home block (#12976): a teal date on the left
 * (big day number), an uppercase event-name link, time below. Hovering a row
 * lights it with a gold border and shows the shared event-preview popup (same
 * gasfCal() component the month grid uses). Degrades to plain links without JS.
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
<div class="gasf-home" x-data="gasfCal()">
	<?php foreach ( $events as $e ) :
		$s = $e->start();
		// Time string (reused by the row meta and the preview popup).
		if ( $e->is_all_day() || $e->hide_time() ) {
			$time = __( 'All day', 'gasf-events' );
		} elseif ( $s ) {
			$time = wp_date( $tf, $e->start_ts() );
			if ( $e->end() && ! $e->hide_end() ) {
				$time .= ' – ' . wp_date( $tf, $e->end_ts() );
			}
		} else {
			$time = '';
		}
		$excerpt = wp_trim_words( wp_strip_all_tags( $e->description() ), 32 );
		?>
		<article class="gasf-home__item"
			data-title="<?php echo esc_attr( $e->title() ); ?>"
			data-time="<?php echo esc_attr( $time ); ?>"
			data-img="<?php echo esc_url( $e->cover_url( 'medium' ) ); ?>"
			data-excerpt="<?php echo esc_attr( $excerpt ); ?>"
			@mouseenter="show($event)" @mouseleave="hide()">
			<a class="gasf-home__date" href="<?php echo esc_url( $e->permalink() ); ?>" aria-hidden="true" tabindex="-1">
				<span class="gasf-home__day"><?php echo esc_html( $s ? wp_date( 'j', $e->start_ts() ) : '–' ); ?></span>
				<span class="gasf-home__mon"><?php echo esc_html( $s ? wp_date( 'M', $e->start_ts() ) : '' ); ?></span>
				<span class="gasf-home__dow"><?php echo esc_html( $s ? wp_date( 'l', $e->start_ts() ) : '' ); ?></span>
			</a>
			<div class="gasf-home__body">
				<h4 class="gasf-home__title">
					<a href="<?php echo esc_url( $e->permalink() ); ?>" @focus="show($event)" @blur="hide()"><?php echo esc_html( $e->title() ); ?></a>
				</h4>
				<p class="gasf-home__meta">
					<?php echo esc_html( $time ); ?>
					<?php if ( $e->status() ) : ?><span class="gasf-status gasf-status--<?php echo esc_attr( $e->status() ); ?>"><?php echo esc_html( $e->status_label() ); ?></span><?php endif; ?>
				</p>
			</div>
		</article>
	<?php endforeach; ?>

	<!-- Shared hover popup (position:fixed so it can't be clipped). -->
	<div class="gasf-pop" x-show="pop.show" x-cloak x-transition.opacity.duration.140ms :class="{ 'is-above': pop.above }" :style="`left:${pop.x}px;top:${pop.y}px`">
		<div class="gasf-pop__inner">
			<strong class="gasf-pop__title" x-text="pop.title"></strong>
			<div class="gasf-pop__time" x-show="pop.time">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
				<span x-text="pop.time"></span>
			</div>
			<div class="gasf-pop__body">
				<img class="gasf-pop__img" :src="pop.img" x-show="pop.img" alt="">
				<p class="gasf-pop__excerpt" x-text="pop.excerpt"></p>
			</div>
		</div>
		<span class="gasf-pop__caret"></span>
	</div>
</div>
