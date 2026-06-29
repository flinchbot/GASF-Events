<?php
/**
 * Standalone printable month grid (US-Letter landscape, one sheet).
 * No theme chrome. Vars: $data (from Shortcodes::month_data), $auto.
 *
 * @package GASF_Events
 * @var array $data
 * @var bool  $auto
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

$weekdays = [ __( 'Sun', 'gasf-events' ), __( 'Mon', 'gasf-events' ), __( 'Tue', 'gasf-events' ), __( 'Wed', 'gasf-events' ), __( 'Thu', 'gasf-events' ), __( 'Fri', 'gasf-events' ), __( 'Sat', 'gasf-events' ) ];
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html( $data['label'] ); ?> — <?php bloginfo( 'name' ); ?></title>
	<style>
		@page { size: Letter landscape; margin: 0.4in; }
		* { box-sizing: border-box; }
		html,body { margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; color:#000; }
		.p-head { display:flex; justify-content:space-between; align-items:baseline; margin:0 0 8px; }
		.p-head h1 { font-size:20pt; margin:0; }
		.p-head .org { font-size:10pt; color:#333; }
		table.p-cal { width:100%; border-collapse:collapse; table-layout:fixed; }
		table.p-cal th { font-size:9pt; text-align:left; padding:3px 4px; border:1px solid #000; background:#eee; }
		table.p-cal td { border:1px solid #000; vertical-align:top; height:1.35in; padding:2px 3px; overflow:hidden; }
		td .d { font-size:10pt; font-weight:bold; }
		td.out .d { color:#aaa; }
		td .ev { font-size:7.5pt; line-height:1.1; margin-top:1px; overflow:hidden; overflow-wrap:break-word; display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; line-clamp:2; }
		td .ev b { font-weight:bold; }
		td .more { font-size:7pt; color:#444; }
		.cancelled { text-decoration:line-through; color:#666; }
		.p-foot { margin-top:6px; font-size:8pt; color:#555; text-align:right; }
		@media screen { body { background:#f3f3f3; } .sheet { background:#fff; max-width:11in; margin:16px auto; padding:0.4in; box-shadow:0 1px 6px rgba(0,0,0,.25); } .toolbar{ text-align:center; margin:12px; } }
		@media print { .toolbar { display:none; } .sheet { box-shadow:none; margin:0; padding:0; max-width:none; } }
	</style>
</head>
<body>
	<div class="toolbar"><button onclick="window.print()"><?php esc_html_e( 'Print', 'gasf-events' ); ?></button></div>
	<div class="sheet">
		<div class="p-head">
			<h1><?php echo esc_html( $data['label'] ); ?></h1>
			<span class="org"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
		</div>
		<table class="p-cal">
			<thead><tr><?php foreach ( $weekdays as $wd ) : ?><th><?php echo esc_html( $wd ); ?></th><?php endforeach; ?></tr></thead>
			<tbody>
				<?php foreach ( $data['weeks'] as $week ) : ?>
					<tr>
						<?php foreach ( $week as $cell ) :
							/** @var Event[] $evs */
							$evs = $cell['events'];
							?>
							<td class="<?php echo $cell['in_month'] ? '' : 'out'; ?>">
								<div class="d"><?php echo esc_html( $cell['date']->format( 'j' ) ); ?></div>
								<?php foreach ( array_slice( $evs, 0, 5 ) as $e ) : ?>
									<div class="ev <?php echo 'cancelled' === $e->status() ? 'cancelled' : ''; ?>">
										<?php if ( ! $e->is_all_day() && ! $e->hide_time() ) : ?><b><?php echo esc_html( wp_date( 'g:ia', $e->start_ts() ) ); ?></b> <?php endif; ?>
										<?php echo esc_html( $e->title() ); ?>
									</div>
								<?php endforeach; ?>
								<?php if ( count( $evs ) > 5 ) : ?><div class="more">+<?php echo (int) ( count( $evs ) - 5 ); ?> <?php esc_html_e( 'more', 'gasf-events' ); ?></div><?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<div class="p-foot"><?php echo esc_html( sprintf( /* translators: %s site name */ __( 'Printed from %s', 'gasf-events' ), get_bloginfo( 'name' ) ) ); ?></div>
	</div>
	<?php if ( $auto ) : ?><script>window.addEventListener('load',function(){window.print();});</script><?php endif; ?>
</body>
</html>
