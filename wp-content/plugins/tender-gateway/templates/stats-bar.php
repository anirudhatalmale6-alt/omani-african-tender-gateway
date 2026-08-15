<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$all       = TG_API::all();
$countries = TG_API::facet( 'country' );
$sectors   = TG_API::facet( 'sector' );

$total_value = 0;
foreach ( $all as $tender ) {
	$total_value += (float) $tender['value'];
}
?>
<div class="tg-stats tg-stats--band">
	<div class="tg-stat">
		<strong><?php echo esc_html( number_format_i18n( count( $all ) ) ); ?></strong>
		<span>Open tenders tracked</span>
	</div>
	<div class="tg-stat">
		<strong><?php echo esc_html( number_format_i18n( count( $countries ) ) ); ?></strong>
		<span>African markets</span>
	</div>
	<div class="tg-stat">
		<strong><?php echo esc_html( number_format_i18n( count( $sectors ) ) ); ?></strong>
		<span>Procurement sectors</span>
	</div>
	<div class="tg-stat">
		<strong>USD <?php echo esc_html( number_format( $total_value / 1000000000, 1 ) ); ?>B</strong>
		<span>Combined contract value</span>
	</div>
</div>
