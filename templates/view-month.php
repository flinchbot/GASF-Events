<?php
/**
 * Month grid view. Vars: $year,$month,$label,$weeks,$prev,$next.
 * Server-rendered; prev/next are real links (work without JS) and are
 * progressively enhanced into fetch-swapped, animated navigation. Hovering an
 * event chip shows an Alpine-driven preview popup (image + time + excerpt).
 *
 * @package GASF_Events
 * @var int    $year
 * @var int    $month
 * @var string $label
 * @var array  $weeks
 * @var string $prev
 * @var string $next
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

$base      = remove_query_arg( [ 'gasf_m' ] );
$prev_url  = add_query_arg( 'gasf_m', $prev, $base );
$next_url  = add_query_arg( 'gasf_m', $next, $base );
$print_url = home_url( '/' . GASF_EVENTS_REWRITE_SLUG . '/print/' . sprintf( '%04d-%02d', $year, $month ) . '/' );
$weekdays  = [ __( 'Sun', 'gasf-events' ), __( 'Mon', 'gasf-events' ), __( 'Tue', 'gasf-events' ), __( 'Wed', 'gasf-events' ), __( 'Thu', 'gasf-events' ), __( 'Fri', 'gasf-events' ), __( 'Sat', 'gasf-events' ) ];

// Render one event chip, with the data the hover popup reads.
$render_chip = static function ( Event $e ) {
	$tf   = get_option( 'time_format' );
	$show_time = ! $e->is_all_day() && ! $e->hide_time() && $e->start();
	if ( $e->is_all_day() ) {
		$time = __( 'All day', 'gasf-events' );
	} elseif ( $e->start() ) {
		$time = wp_date( $tf, $e->start_ts() );
		if ( $e->end() && ! $e->hide_end() ) {
			$time .= ' – ' . wp_date( $tf, $e->end_ts() );
		}
	} else {
		$time = '';
	}
	$excerpt = wp_trim_words( wp_strip_all_tags( $e->description() ), 32 );
	?>
	<a class="gasf-chip gasf-chip--<?php echo esc_attr( $e->status() ?: 'scheduled' ); ?>"
		style="--e-color:<?php echo esc_attr( $e->color() ); ?>"
		href="<?php echo esc_url( $e->permalink() ); ?>"
		data-title="<?php echo esc_attr( $e->title() ); ?>"
		data-time="<?php echo esc_attr( $time ); ?>"
		data-img="<?php echo esc_url( $e->cover_url( 'medium' ) ); ?>"
		data-excerpt="<?php echo esc_attr( $excerpt ); ?>"
		@mouseenter="show($event)" @mouseleave="hide()" @focus="show($event)" @blur="hide()">
		<span class="gasf-chip__icon" aria-hidden="true"><?php echo esc_html( $e->icon() ); ?></span>
		<span class="gasf-chip__text"><?php if ( $show_time ) : ?><b><?php echo esc_html( wp_date( $tf, $e->start_ts() ) ); ?></b> <?php endif; ?><?php echo esc_html( $e->title() ); ?></span>
	</a>
	<?php
};
?>
<div class="gasf-cal" data-gasf-cal x-data="gasfCal()">
	<div class="gasf-cal__bar">
		<a class="gasf-cal__nav" data-gasf-nav href="<?php echo esc_url( $prev_url ); ?>" rel="prev" aria-label="<?php esc_attr_e( 'Previous month', 'gasf-events' ); ?>">&#8249;</a>
		<h2 class="gasf-cal__title" data-gasf-title><?php echo esc_html( $label ); ?></h2>
		<a class="gasf-cal__nav" data-gasf-nav href="<?php echo esc_url( $next_url ); ?>" rel="next" aria-label="<?php esc_attr_e( 'Next month', 'gasf-events' ); ?>">&#8250;</a>
		<a class="gasf-cal__print" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print this month', 'gasf-events' ); ?></a>
		<a class="gasf-cal__print" href="<?php echo esc_url( Add_To_Calendar::subscribe_url( true ) ); ?>" title="<?php esc_attr_e( 'Subscribe in your calendar app', 'gasf-events' ); ?>"><?php esc_html_e( 'Subscribe', 'gasf-events' ); ?></a>
	</div>

	<div class="gasf-grid" role="table" aria-label="<?php echo esc_attr( $label ); ?>">
		<div class="gasf-grid__head" role="row">
			<?php foreach ( $weekdays as $wd ) : ?>
				<span role="columnheader"><?php echo esc_html( $wd ); ?></span>
			<?php endforeach; ?>
		</div>
		<?php $cidx = 0; foreach ( $weeks as $week ) : ?>
			<div class="gasf-grid__week" role="row">
				<?php foreach ( $week as $cell ) :
					/** @var Event[] $cell_events */
					$cell_events = $cell['events'];
					$classes = 'gasf-day' . ( $cell['in_month'] ? '' : ' is-out' ) . ( $cell['is_today'] ? ' is-today' : '' );
					// Each in-month day gets its own colour from the palette (today stays gold).
					if ( $cell['in_month'] ) {
						$classes .= ' gasf-c' . ( ( $cidx % 8 ) + 1 );
						$cidx++;
					}
					?>
					<div class="<?php echo esc_attr( $classes ); ?>" role="cell">
						<span class="gasf-day__num"><?php echo esc_html( $cell['date']->format( 'j' ) ); ?></span>
						<?php if ( $cell_events ) : ?>
							<ul class="gasf-day__events">
								<?php foreach ( $cell_events as $e ) : ?>
									<li><?php $render_chip( $e ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- Shared hover popup (position:fixed so the day-cell overflow can't clip it). -->
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
