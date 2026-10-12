<?php
/**
 * The supplier's view of the commission they owe: an overdue warning at the
 * top of the dashboard, the list of commission invoices, and a printable tax
 * invoice for each - visible only to that supplier and the team.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Commission_UI {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_invoice' ), 6 );
		add_action( 'tg_dashboard_primary', array( __CLASS__, 'render_overdue' ), 3 );
		add_action( 'tg_dashboard_panels', array( __CLASS__, 'render_panel' ), 8 );
	}

	public static function invoice_url( $id ) {
		return add_query_arg( 'tg_commission', (int) $id, tg_page_url( 'dashboard' ) );
	}

	public static function render_overdue( $user ) {
		$overdue = TG_Commission::overdue_for( $user->ID );

		if ( ! $overdue ) {
			return;
		}

		$total = 0;
		foreach ( $overdue as $row ) {
			$total += (float) $row['total'];
		}

		printf(
			'<div class="tg-notice" style="background:#fbeaea;border-left:3px solid #8a2424;margin-bottom:18px;">'
			. '<strong>Commission overdue: %s.</strong> You cannot submit new bids until it is paid. Bids you have already placed are not affected. '
			. 'Pay by bank transfer quoting the invoice number. <a href="#tg-commissions">See the invoice and payment details</a>.</div>',
			esc_html( $overdue[0]['currency'] . ' ' . number_format( $total, 3 ) )
		);
	}

	public static function render_panel( $user ) {
		$rows = TG_Commission::for_supplier( $user->ID );

		if ( ! $rows ) {
			return;
		}

		echo '<section class="tg-panel" id="tg-commissions"><h2>Commission invoices</h2>';
		echo '<p class="tg-note" style="margin-top:0;">' . esc_html( sprintf(
			'Commission of %s%% of the awarded value is charged when a tender you won is completed, payable within %d days.',
			rtrim( rtrim( number_format( TG_Commission::rate(), 3 ), '0' ), '.' ),
			TG_Commission::due_days()
		) ) . '</p>';

		echo '<table class="tg-table" style="width:100%;border-collapse:collapse;"><thead><tr>'
			. '<th style="text-align:left;padding:8px 6px;">Invoice</th>'
			. '<th style="text-align:left;padding:8px 6px;">Tender</th>'
			. '<th style="text-align:left;padding:8px 6px;">Total</th>'
			. '<th style="text-align:left;padding:8px 6px;">Due</th>'
			. '<th style="text-align:left;padding:8px 6px;">Status</th>'
			. '<th style="text-align:left;padding:8px 6px;"></th>'
			. '</tr></thead><tbody>';

		$colours = array( 'Due' => '#b26200', 'Overdue' => '#8a2424', 'Paid' => '#1d7a3f', 'Cancelled' => '#646970' );
		$unpaid  = false;

		foreach ( $rows as $row ) {
			$tender = TG_Tender::get( $row['tender_id'] );
			$label  = TG_Commission::label( $row );
			$unpaid = $unpaid || TG_Commission::DUE === $row['status'];

			echo '<tr style="border-top:1px solid #e3e8ee;">';
			echo '<td style="padding:8px 6px;"><code>' . esc_html( $row['invoice_no'] ) . '</code></td>';
			echo '<td style="padding:8px 6px;">' . esc_html( $tender ? $tender['external_id'] . ' - ' . $tender['title'] : '-' ) . '</td>';
			echo '<td style="padding:8px 6px;">' . esc_html( $row['currency'] . ' ' . number_format( (float) $row['total'], 3 ) ) . '<br><span class="tg-note">inc. VAT '
				. esc_html( number_format( (float) $row['vat_amount'], 3 ) ) . '</span></td>';
			echo '<td style="padding:8px 6px;">' . esc_html( date_i18n( 'j M Y', strtotime( $row['due_at'] ) ) ) . '</td>';
			echo '<td style="padding:8px 6px;"><span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:11px;font-weight:600;background:'
				. esc_attr( isset( $colours[ $label ] ) ? $colours[ $label ] : '#646970' ) . ';">' . esc_html( $label ) . '</span></td>';
			echo '<td style="padding:8px 6px;"><a class="tg-link" href="' . esc_url( self::invoice_url( $row['id'] ) ) . '">View / print</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$how = trim( (string) get_option( 'tg_payment_instructions', '' ) );

		if ( $unpaid && $how ) {
			echo '<div class="tg-note" style="padding:12px;border-radius:4px;background:#f6f7f7;margin:12px 0 0;">'
				. '<strong>How to pay</strong> - please quote the invoice number.' . wp_kses_post( wpautop( esc_html( $how ) ) ) . '</div>';
		}

		echo '</section>';
	}

	public static function maybe_render_invoice() {
		$id = isset( $_GET['tg_commission'] ) ? (int) $_GET['tg_commission'] : 0;

		if ( ! $id ) {
			return;
		}

		$row = TG_Commission::get( $id );

		if ( ! $row ) {
			wp_die( 'That invoice does not exist.', 'Invoice', array( 'response' => 404 ) );
		}

		if ( (int) $row['supplier_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_die( 'That invoice is not yours.', 'Invoice', array( 'response' => 403 ) );
		}

		nocache_headers();
		self::render_invoice( $row );
		exit;
	}

	private static function render_invoice( $row ) {
		$tender   = TG_Tender::get( $row['tender_id'] );
		$supplier = get_userdata( (int) $row['supplier_id'] );
		$company  = $supplier ? get_user_meta( $supplier->ID, 'tg_company', true ) : '';
		$company  = $company ? $company : ( $supplier ? $supplier->display_name : 'Supplier' );
		$cr       = $supplier ? get_user_meta( $supplier->ID, 'tg_cr', true ) : '';
		$from     = (string) get_option( 'tg_invoice_from', get_bloginfo( 'name' ) );
		$vat_no   = TG_Subscription::vat_number();
		$cur      = $row['currency'];
		$label    = TG_Commission::label( $row );
		$fmt      = function ( $n ) {
			return number_format( (float) $n, 3 );
		};
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( 'Invoice ' . $row['invoice_no'] ); ?></title>
	<style>
		body { font: 14px/1.6 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1d2327; margin: 0; padding: 32px; background: #f6f7f7; }
		.inv { max-width: 760px; margin: 0 auto; background: #fff; padding: 36px 40px; border: 1px solid #dcdcde; position: relative; }
		.top { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #1d2327; padding-bottom: 18px; margin-bottom: 24px; }
		h1 { font-size: 24px; margin: 0 0 4px; }
		.muted { color: #50575e; }
		.meta { text-align: right; font-size: 13px; }
		.meta strong { display: inline-block; min-width: 92px; text-align: left; }
		.parties { display: flex; gap: 32px; margin-bottom: 28px; }
		.parties > div { flex: 1; }
		h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #50575e; margin: 0 0 6px; }
		table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
		th, td { text-align: left; padding: 9px 8px; border-bottom: 1px solid #e3e8ee; }
		th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #50575e; }
		.num { text-align: right; }
		tfoot td { border-bottom: none; }
		tr.total td { border-top: 2px solid #1d2327; font-weight: 700; font-size: 16px; }
		.note { font-size: 12px; color: #50575e; border-top: 1px solid #e3e8ee; padding-top: 14px; }
		.stamp { position: absolute; top: 34px; right: 260px; transform: rotate(-8deg); border: 3px solid; border-radius: 6px; padding: 4px 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
		.actions { max-width: 760px; margin: 0 auto 14px; }
		.actions a, .actions button { font: inherit; padding: 7px 14px; border: 1px solid #c3c4c7; background: #fff; border-radius: 3px; cursor: pointer; text-decoration: none; color: #1d2327; }
		@media print { body { background: #fff; padding: 0; } .inv { border: none; padding: 0; } .actions { display: none; } }
	</style>
</head>
<body>
	<div class="actions">
		<button type="button" onclick="window.print();">Print or save as PDF</button>
		<a href="<?php echo esc_url( tg_page_url( 'dashboard' ) ); ?>">Back to dashboard</a>
	</div>
	<div class="inv">
		<?php if ( 'Due' !== $label ) : ?>
			<div class="stamp" style="color:<?php echo esc_attr( 'Paid' === $label ? '#1d7a3f' : ( 'Overdue' === $label ? '#8a2424' : '#646970' ) ); ?>"><?php echo esc_html( $label ); ?></div>
		<?php endif; ?>
		<div class="top">
			<div>
				<h1>Tax invoice - commission</h1>
				<p class="muted" style="margin:0;"><?php echo esc_html( $row['invoice_no'] ); ?></p>
			</div>
			<div class="meta">
				<p style="margin:0;"><strong>Issued</strong> <?php echo esc_html( date_i18n( 'j M Y', strtotime( $row['issued_at'] ) ) ); ?></p>
				<p style="margin:0;"><strong>Due</strong> <?php echo esc_html( date_i18n( 'j M Y', strtotime( $row['due_at'] ) ) ); ?></p>
				<?php if ( $vat_no ) : ?>
					<p style="margin:0;"><strong>VAT number</strong> <?php echo esc_html( $vat_no ); ?></p>
				<?php endif; ?>
				<?php if ( $row['paid_at'] ) : ?>
					<p style="margin:0;"><strong>Paid</strong> <?php echo esc_html( date_i18n( 'j M Y', strtotime( $row['paid_at'] ) ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="parties">
			<div>
				<h2>From</h2>
				<?php echo wpautop( esc_html( $from ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<div>
				<h2>Billed to</h2>
				<p style="margin:0;"><strong><?php echo esc_html( $company ); ?></strong></p>
				<?php if ( $supplier ) : ?>
					<p style="margin:0;" class="muted"><?php echo esc_html( $supplier->user_email ); ?></p>
				<?php endif; ?>
				<?php if ( $cr ) : ?>
					<p style="margin:0;" class="muted">CR <?php echo esc_html( $cr ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<table>
			<thead><tr><th>Description</th><th class="num">Amount (<?php echo esc_html( $cur ); ?>)</th></tr></thead>
			<tbody>
				<tr>
					<td>
						<strong>Platform commission - <?php echo esc_html( rtrim( rtrim( number_format( (float) $row['rate'], 3 ), '0' ), '.' ) ); ?>% of awarded value</strong><br>
						<span class="muted"><?php echo esc_html( $tender ? $tender['external_id'] . ' - ' . $tender['title'] : 'Tender' ); ?></span><br>
						<span class="muted">Awarded value <?php echo esc_html( $cur . ' ' . $fmt( $row['awarded_value'] ) ); ?></span>
					</td>
					<td class="num"><?php echo esc_html( $fmt( $row['amount'] ) ); ?></td>
				</tr>
			</tbody>
			<tfoot>
				<tr><td class="num">Subtotal</td><td class="num"><?php echo esc_html( $fmt( $row['amount'] ) ); ?></td></tr>
				<tr><td class="num">VAT at <?php echo esc_html( rtrim( rtrim( number_format( (float) $row['vat_rate'], 3 ), '0' ), '.' ) ); ?>%</td><td class="num"><?php echo esc_html( $fmt( $row['vat_amount'] ) ); ?></td></tr>
				<tr class="total"><td class="num">Total</td><td class="num"><?php echo esc_html( $fmt( $row['total'] ) . ' ' . $cur ); ?></td></tr>
			</tfoot>
		</table>

		<p class="note">
			Payable by bank transfer within the due date, quoting <?php echo esc_html( $row['invoice_no'] ); ?>.
			<?php if ( TG_Commission::VOID === $row['status'] && $row['void_reason'] ) : ?>
				<br>This invoice was cancelled: <?php echo esc_html( $row['void_reason'] ); ?>
			<?php endif; ?>
			<?php if ( ! $vat_no ) : ?>
				<br>A VAT number has not been set on the platform yet, so this is not a valid VAT invoice.
			<?php endif; ?>
		</p>
	</div>
</body>
</html>
		<?php
	}
}
