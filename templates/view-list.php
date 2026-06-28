<?php
/**
 * Upcoming list view — date-grouped, with an Alpine live filter.
 *
 * @package GASF_Events
 * @var Event[] $events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

$groups = Calendar::group_by_day( $events );
?>
<div class="gasf-list" x-data="{ q: '' }">
	<div class="gasf-list__tools">
		<input type="search" class="gasf-list__search" x-model="q" placeholder="<?php esc_attr_e( 'Filter events…', 'gasf-events' ); ?>" aria-label="<?php esc_attr_e( 'Filter events', 'gasf-events' ); ?>">
	</div>

	<?php if ( ! $events ) : ?>
		<p class="gasf-empty"><?php esc_html_e( 'No upcoming events.', 'gasf-events' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $groups as $day => $day_events ) :
		$ts = $day_events[0]->start_ts();
		$titles = mb_strtolower( implode( '|', array_map( static fn( $e ) => $e->title(), $day_events ) ) );
		?>
		<section class="gasf-list__day" data-titles="<?php echo esc_attr( $titles ); ?>" x-show="!q || $el.dataset.titles.includes(q.toLowerCase())">
			<h3 class="gasf-list__date"><?php echo esc_html( $ts ? wp_date( 'l, F j, Y', $ts ) : '' ); ?></h3>
			<?php foreach ( $day_events as $e ) : ?>
				<article class="gasf-row" data-title="<?php echo esc_attr( mb_strtolower( $e->title() ) ); ?>" x-show="!q || $el.dataset.title.includes(q.toLowerCase())">
					<a class="gasf-row__media" href="<?php echo esc_url( $e->permalink() ); ?>">
						<img loading="lazy" src="<?php echo esc_url( $e->cover_url( 'medium' ) ); ?>" alt="">
					</a>
					<div class="gasf-row__body">
						<a class="gasf-row__title" href="<?php echo esc_url( $e->permalink() ); ?>"><?php echo esc_html( $e->title() ); ?></a>
						<p class="gasf-row__meta">
							<?php if ( $e->is_all_day() || $e->hide_time() ) : ?>
								<?php esc_html_e( 'All day', 'gasf-events' ); ?>
							<?php elseif ( $e->start() ) : ?>
								<?php echo esc_html( wp_date( get_option( 'time_format' ), $e->start_ts() ) ); ?>
							<?php endif; ?>
							<?php if ( $e->status() ) : ?><span class="gasf-status gasf-status--<?php echo esc_attr( $e->status() ); ?>"><?php echo esc_html( $e->status_label() ); ?></span><?php endif; ?>
						</p>
					</div>
				</article>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>
</div>
