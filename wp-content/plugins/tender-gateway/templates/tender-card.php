<?php
/**
 * @var array $tender
 * @var bool  $compact
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$days    = TG_API::days_left( $tender );
$urgency = 'normal';
if ( null !== $days ) {
	if ( $days <= 7 ) {
		$urgency = 'urgent';
	} elseif ( $days <= 21 ) {
		$urgency = 'soon';
	}
}
?>
<article class="tg-card tg-card--<?php echo esc_attr( $urgency ); ?>">
	<div class="tg-card__top">
		<span class="tg-flag" aria-hidden="true"><?php echo esc_html( tg_country_code( $tender['country'] ) ); ?></span>
		<div class="tg-card__meta">
			<span class="tg-card__country"><?php echo esc_html( $tender['country'] ); ?></span>
			<span class="tg-card__sector"><?php echo esc_html( $tender['sector'] ); ?></span>
		</div>
	</div>

	<h3 class="tg-card__title">
		<a href="<?php echo esc_url( tg_tender_url( $tender['id'] ) ); ?>"><?php echo esc_html( $tender['title'] ); ?></a>
	</h3>

	<?php if ( empty( $compact ) ) : ?>
		<p class="tg-card__summary"><?php echo esc_html( wp_trim_words( $tender['summary'], 26, '...' ) ); ?></p>
	<?php endif; ?>

	<dl class="tg-card__facts">
		<div>
			<dt>Buyer</dt>
			<dd><?php echo esc_html( $tender['buyer'] ); ?></dd>
		</div>
		<div>
			<dt>Estimated value</dt>
			<dd><?php echo esc_html( TG_API::format_value( $tender ) ); ?></dd>
		</div>
	</dl>

	<div class="tg-card__foot">
		<span class="tg-deadline tg-deadline--<?php echo esc_attr( $urgency ); ?>">
			<?php if ( null === $days ) : ?>
				Deadline on request
			<?php elseif ( $days < 0 ) : ?>
				Closed
			<?php elseif ( 0 === $days ) : ?>
				Closes today
			<?php else : ?>
				<?php echo esc_html( sprintf( _n( '%d day left', '%d days left', $days, 'tender-gateway' ), $days ) ); ?>
			<?php endif; ?>
		</span>
		<a class="tg-card__link" href="<?php echo esc_url( tg_tender_url( $tender['id'] ) ); ?>">
			View tender
			<svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M2 7h9M7.5 3.5 11 7l-3.5 3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</a>
	</div>

	<?php if ( ! is_user_logged_in() ) : ?>
		<span class="tg-card__lock" title="Full tender details are available to registered suppliers">
			<svg width="12" height="12" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M4 6V4.5a3 3 0 0 1 6 0V6" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="2.5" y="6" width="9" height="6.5" rx="1.2" fill="currentColor"/></svg>
			Members only
		</span>
	<?php endif; ?>
</article>
