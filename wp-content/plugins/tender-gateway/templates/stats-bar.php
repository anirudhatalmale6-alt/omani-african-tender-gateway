<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$all       = TG_API::all();
$countries = TG_API::facet( 'country' );
$sectors   = TG_API::facet( 'sector' );

// Deliberately a count, not a summed value: the feed carries ZAR, KES, NGN,
// EUR and USD side by side, and adding those together would be meaningless.
$closing_soon = 0;
foreach ( $all as $tender ) {
	$days = TG_API::days_left( $tender );
	if ( null !== $days && $days >= 0 && $days <= 30 ) {
		$closing_soon++;
	}
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
		<strong><?php echo esc_html( number_format_i18n( $closing_soon ) ); ?></strong>
		<span>Closing within 30 days</span>
	</div>
</div>
