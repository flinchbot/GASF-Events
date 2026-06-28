<?php
/**
 * Month grid view. Vars: $year,$month,$label,$weeks,$prev,$next.
 * Server-rendered; prev/next are real links (work without JS) and are
 * progressively enhanced into fetch-swapped, animated navigation.
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
?>
<div class="gasf-cal" data-gasf-cal>
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
		<?php foreach ( $weeks as $week ) : ?>
			<div class="gasf-grid__week" role="row">
				<?php foreach ( $week as $cell ) :
					/** @var Event[] $cell_events */
					$cell_events = $cell['events'];
					$classes = 'gasf-day' . ( $cell['in_month'] ? '' : ' is-out' ) . ( $cell['is_today'] ? ' is-today' : '' );
					?>
					<div class="<?php echo esc_attr( $classes ); ?>" role="cell" x-data="{ open:false }">
						<span class="gasf-day__num"><?php echo esc_html( $cell['date']->format( 'j' ) ); ?></span>
						<?php if ( $cell_events ) : ?>
							<ul class="gasf-day__events">
								<?php foreach ( array_slice( $cell_events, 0, 3 ) as $e ) : ?>
									<li><a class="gasf-chip gasf-chip--<?php echo esc_attr( $e->status() ?: 'scheduled' ); ?>" href="<?php echo esc_url( $e->permalink() ); ?>">
										<?php if ( ! $e->is_all_day() && ! $e->hide_time() && $e->start() ) : ?><b><?php echo esc_html( wp_date( get_option( 'time_format' ), $e->start_ts() ) ); ?></b> <?php endif; ?>
										<?php echo esc_html( $e->title() ); ?>
									</a></li>
								<?php endforeach; ?>
								<?php if ( count( $cell_events ) > 3 ) : ?>
									<li x-show="!open"><button type="button" class="gasf-more" @click="open=true">+<?php echo (int) ( count( $cell_events ) - 3 ); ?> <?php esc_html_e( 'more', 'gasf-events' ); ?></button></li>
									<div x-show="open" x-cloak>
										<?php foreach ( array_slice( $cell_events, 3 ) as $e ) : ?>
											<a class="gasf-chip gasf-chip--<?php echo esc_attr( $e->status() ?: 'scheduled' ); ?>" href="<?php echo esc_url( $e->permalink() ); ?>"><?php echo esc_html( $e->title() ); ?></a>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>
</div>
