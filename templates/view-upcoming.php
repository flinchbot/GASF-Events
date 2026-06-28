<?php
/**
 * Compact "upcoming dates" widget (for [gasf_upcoming_dates] / presets).
 *
 * @package GASF_Events
 * @var Event[] $events
 * @var array   $atts  contains|heading|icon|color|limit
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

// Accept a hex color or the CSS var default; reject anything else so the
// shortcode `color` attribute can't inject arbitrary CSS declarations.
$accent = sanitize_hex_color( (string) $atts['color'] ) ?: 'var(--gasf-accent)';
?>
<div class="gasf-upcoming" style="--gasf-accent:<?php echo esc_attr( $accent ); ?>;">
	<?php if ( ! empty( $atts['heading'] ) ) : ?>
		<h3 class="gasf-upcoming__heading">
			<?php if ( ! empty( $atts['icon'] ) ) : ?><span class="gasf-upcoming__icon" aria-hidden="true"><?php echo esc_html( $atts['icon'] ); ?></span> <?php endif; ?>
			<?php echo esc_html( $atts['heading'] ); ?>
		</h3>
	<?php endif; ?>

	<?php if ( ! $events ) : ?>
		<p class="gasf-empty"><?php esc_html_e( 'No upcoming dates scheduled.', 'gasf-events' ); ?></p>
	<?php else : ?>
		<ul class="gasf-upcoming__list">
			<?php foreach ( $events as $e ) : ?>
				<li class="gasf-upcoming__item">
					<a href="<?php echo esc_url( $e->permalink() ); ?>">
						<span class="gasf-upcoming__date"><?php echo esc_html( wp_date( 'D, M j', $e->start_ts() ) ); ?></span>
						<?php if ( ! $e->is_all_day() && ! $e->hide_time() ) : ?>
							<span class="gasf-upcoming__time"><?php echo esc_html( wp_date( get_option( 'time_format' ), $e->start_ts() ) ); ?></span>
						<?php endif; ?>
						<span class="gasf-upcoming__name"><?php echo esc_html( $e->title() ); ?></span>
						<?php if ( $e->status() ) : ?><span class="gasf-status gasf-status--<?php echo esc_attr( $e->status() ); ?>"><?php echo esc_html( $e->status_label() ); ?></span><?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
