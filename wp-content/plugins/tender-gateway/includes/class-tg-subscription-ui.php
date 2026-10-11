<?php
/**
 * The supplier's own view of their subscription: what they are on, what it
 * costs, when it runs out, every invoice they have ever been issued, and the
 * plans they could move to.
 *
 * Two things here are deliberate.
 *
 * First, nothing on this screen grants access. Requesting a plan records that
 * the supplier asked; access follows a payment an administrator has seen. The
 * button says "request" because that is what it does.
 *
 * Second, the expiry warning lives on this page as well as in email. The
 * server this runs on has outgoing mail disabled, so an emailed-only reminder
 * is a reminder nobody receives. The banner works regardless.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Subscription_UI {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_invoice' ), 6 );
		// High on the page, not down with the history: whether a supplier can
		// bid at all is the first thing they need to know.
		add_action( 'tg_dashboard_primary', array( __CLASS__, 'render_panel' ), 5 );
	}

	/* -------------------------------------------------------------- Notices */

	private static function notice( $type = null, $message = null ) {
		static $store = array();

		if ( null !== $type ) {
			$store[] = array( 'type' => $type, 'message' => $message );

			return array();
		}

		return $store;
	}

	/* --------------------------------------------------------- Plan request */

	public static function handle_request() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		if ( ! isset( $_POST['tg_sub_action'] ) || 'request' !== sanitize_key( wp_unslash( $_POST['tg_sub_action'] ) ) ) {
			return;
		}

		$plan_id = isset( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : 0;

		if ( ! isset( $_POST['tg_sub_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tg_sub_nonce'] ) ), 'tg_sub_request_' . $plan_id ) ) {
			self::notice( 'error', 'Your session expired. Please try again.' );

			return;
		}

		$result = TG_Subscription::request( get_current_user_id(), $plan_id );

		if ( is_wp_error( $result ) ) {
			self::notice( 'error', $result->get_error_message() );

			return;
		}

		$plan = TG_Subscription::plan( $plan_id );

		self::notice( 'success', sprintf(
			'Thank you - your request for the %s plan has been recorded. Our team will contact you to arrange payment, and your access opens as soon as that payment is recorded.',
			$plan ? $plan['name'] : 'selected'
		) );
	}

	/* ------------------------------------------------------ Printable invoice */

	public static function maybe_render_invoice() {
		$id = isset( $_GET['tg_invoice'] ) ? (int) $_GET['tg_invoice'] : 0;

		if ( ! $id ) {
			return;
		}

		$sub = TG_Subscription::get( $id );

		if ( ! $sub ) {
			wp_die( 'That invoice does not exist.', 'Invoice', array( 'response' => 404 ) );
		}

		// An invoice is somebody's billing record. Only the supplier it was
		// issued to, and an administrator, may open it.
		$user_id = get_current_user_id();

		if ( (int) $sub['supplier_id'] !== (int) $user_id && ! current_user_can( 'manage_options' ) ) {
			wp_die( 'That invoice is not yours.', 'Invoice', array( 'response' => 403 ) );
		}

		nocache_headers();
		self::render_invoice( $sub );
		exit;
	}

	private static function render_invoice( $sub ) {
		$supplier = get_userdata( (int) $sub['supplier_id'] );
		$company  = $supplier ? get_user_meta( $supplier->ID, 'tg_company', true ) : '';
		$company  = $company ? $company : ( $supplier ? $supplier->display_name : 'Supplier' );
		$cr       = $supplier ? get_user_meta( $supplier->ID, 'tg_cr', true ) : '';
		$from     = (string) get_option( 'tg_invoice_from', get_bloginfo( 'name' ) );
		$vat_no   = TG_Subscription::vat_number();
		$cur      = $sub['currency'];

		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( 'Invoice ' . $sub['invoice_no'] ); ?></title>
	<style>
		body { font: 14px/1.6 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1d2327; margin: 0; padding: 32px; background: #f6f7f7; }
		.inv { max-width: 760px; margin: 0 auto; background: #fff; padding: 36px 40px; border: 1px solid #dcdcde; }
		.inv__top { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #1d2327; padding-bottom: 18px; margin-bottom: 24px; }
		h1 { font-size: 24px; margin: 0 0 4px; letter-spacing: 0.02em; }
		.muted { color: #50575e; }
		.meta { text-align: right; font-size: 13px; }
		.meta strong { display: inline-block; min-width: 92px; text-align: left; }
		.parties { display: flex; gap: 32px; margin-bottom: 28px; }
		.parties > div { flex: 1; }
		h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #50575e; margin: 0 0 6px; }
		table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
		th, td { text-align: left; padding: 9px 8px; border-bottom: 1px solid #e3e8ee; }
		th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #50575e; }
		td.num, th.num { text-align: right; }
		tfoot td { border-bottom: none; }
		tfoot tr.total td { border-top: 2px solid #1d2327; font-weight: 700; font-size: 16px; }
		.note { font-size: 12px; color: #50575e; border-top: 1px solid #e3e8ee; padding-top: 14px; }
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
		<div class="inv__top">
			<div>
				<h1>Tax invoice</h1>
				<p class="muted" style="margin:0;"><?php echo esc_html( $sub['invoice_no'] ); ?></p>
			</div>
			<div class="meta">
				<p style="margin:0;"><strong>Issued</strong> <?php echo esc_html( self::date( $sub['created_at'] ) ); ?></p>
				<?php if ( $vat_no ) : ?>
					<p style="margin:0;"><strong>VAT number</strong> <?php echo esc_html( $vat_no ); ?></p>
				<?php endif; ?>
				<p style="margin:0;"><strong>Payment</strong> <?php echo esc_html( $sub['payment_method'] ? $sub['payment_method'] : '-' ); ?></p>
				<?php if ( $sub['payment_ref'] ) : ?>
					<p style="margin:0;"><strong>Reference</strong> <?php echo esc_html( $sub['payment_ref'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="parties">
			<div>
				<h2>From</h2>
				<?php echo wpautop( esc_html( $from ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>
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
			<thead>
				<tr>
					<th>Description</th>
					<th>Period</th>
					<th class="num">Amount (<?php echo esc_html( $cur ); ?>)</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>
						<strong><?php echo esc_html( $sub['plan_name'] ); ?></strong><br>
						<span class="muted">Supplier subscription - tender specifications, documents and bidding</span>
					</td>
					<td><?php echo esc_html( self::date( $sub['starts_at'] ) . ' - ' . self::date( $sub['ends_at'] ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format( (float) $sub['price'], 3 ) ); ?></td>
				</tr>
			</tbody>
			<tfoot>
				<tr>
					<td colspan="2" class="num">Subtotal</td>
					<td class="num"><?php echo esc_html( number_format( (float) $sub['price'], 3 ) ); ?></td>
				</tr>
				<tr>
					<td colspan="2" class="num">VAT at <?php echo esc_html( rtrim( rtrim( number_format( (float) $sub['vat_rate'], 3 ), '0' ), '.' ) ); ?>%</td>
					<td class="num"><?php echo esc_html( number_format( (float) $sub['vat_amount'], 3 ) ); ?></td>
				</tr>
				<tr class="total">
					<td colspan="2" class="num">Total</td>
					<td class="num"><?php echo esc_html( number_format( (float) $sub['total'], 3 ) . ' ' . $cur ); ?></td>
				</tr>
			</tfoot>
		</table>

		<p class="note">
			<?php if ( ! $vat_no ) : ?>
				This document is a record of the subscription period purchased. A VAT number has not been
				set on the platform yet, so it is not a VAT invoice.
			<?php else : ?>
				Amounts are in <?php echo esc_html( $cur ); ?>. Oman Rial is quoted to three decimal places.
			<?php endif; ?>
			<?php if ( $sub['note'] ) : ?>
				<br><?php echo esc_html( $sub['note'] ); ?>
			<?php endif; ?>
		</p>
	</div>
</body>
</html>
		<?php
	}

	public static function invoice_url( $sub_id ) {
		return add_query_arg( 'tg_invoice', (int) $sub_id, tg_page_url( 'dashboard' ) );
	}

	/* ------------------------------------------------------ Dashboard panel */

	public static function render_panel( $user ) {
		$current = TG_Subscription::current( $user->ID );
		$active  = TG_Subscription::is_active( $user->ID );
		$history = TG_Subscription::history( $user->ID );
		$request = TG_Subscription::pending_request( $user->ID );
		$plans   = TG_Subscription::plans();

		echo '<section class="tg-panel tg-panel--sub"><h2>My subscription</h2>';

		$just_requested = false;

		foreach ( self::notice() as $n ) {
			if ( 'error' !== $n['type'] ) {
				$just_requested = true;
			}

			printf(
				'<div class="tg-notice tg-notice--%s">%s</div>',
				'error' === $n['type'] ? 'error' : 'success',
				esc_html( $n['message'] )
			);
		}

		self::render_status( $user, $current, $active );

		if ( ! TG_Access::subscription_required() ) {
			echo '<p class="tg-note">Subscriptions are currently switched off on this site, so every registered '
				. 'supplier has full access. Plans below are for information.</p>';
		}

		self::render_request_state( $request, $just_requested );
		self::render_plans( $plans, $current, $active, $request );
		self::render_invoices( $history );

		echo '</section>';
	}

	private static function render_status( $user, $current, $active ) {
		if ( ! $current ) {
			printf(
				'<div class="tg-notice" style="background:#fdf8e7;border-left:3px solid #8a6d1f;">'
				. '<strong>You do not have a subscription yet.</strong> You can see every tender headline and closing '
				. 'date. Full specifications, the attached documents and the right to bid open up on a subscription.</div>'
			);

			return;
		}

		$days  = TG_Subscription::days_left( $current );
		$ends  = self::date( $current['ends_at'] );
		$plan  = $current['plan_name'];
		$verif = TG_Access::is_verified( $user->ID );

		if ( $active ) {
			$warn = ( null !== $days && $days <= 14 );

			printf(
				'<div class="tg-notice" style="background:%s;border-left:3px solid %s;">'
				. '<strong>%s - active until %s.</strong> %s</div>',
				$warn ? '#fdf8e7' : '#eef7ee',
				$warn ? '#8a6d1f' : '#1d7a3f',
				esc_html( $plan ),
				esc_html( $ends ),
				$warn
					? esc_html( sprintf(
						'%d day(s) left. Renew before it ends and the new period is added on top of the days you have left, so nothing is lost.',
						max( 0, (int) $days )
					) )
					: esc_html( sprintf( '%d day(s) left.', max( 0, (int) $days ) ) )
			);

			if ( TG_Subscription::CANCELLED === $current['status'] ) {
				echo '<p class="tg-note">This subscription has been cancelled, so it will not renew. Your access runs '
					. 'to the end of the period you have already paid for.</p>';
			}

			if ( ! $verif ) {
				echo '<p class="tg-note" style="color:#8a2424;"><strong>Your company is not verified yet.</strong> '
					. 'A subscription opens the tender detail, but bidding also needs an administrator to verify your '
					. 'company. We will let you know as soon as that is done.</p>';
			}

			return;
		}

		printf(
			'<div class="tg-notice" style="background:#fbeaea;border-left:3px solid #8a2424;">'
			. '<strong>Your subscription expired on %s.</strong> Tender specifications, documents and new bids are '
			. 'closed until it is renewed. Bids you have already placed are unaffected and stay in your history.</div>',
			esc_html( $ends )
		);
	}

	private static function render_request_state( $request, $just_requested = false ) {
		if ( ! $request ) {
			return;
		}

		// The confirmation above already said this. Saying it twice in two
		// colours reads like two different things happened.
		if ( ! $just_requested ) {
			printf(
				'<div class="tg-notice" style="background:#eef4fb;border-left:3px solid #2d6cb5;">'
				. '<strong>Request received.</strong> You asked for the %s plan on %s. Our team will contact you to arrange '
				. 'payment, and your access opens as soon as that payment is recorded.</div>',
				esc_html( $request['plan_name'] ),
				esc_html( self::date( $request['created_at'] ) )
			);
		}

		$instructions = trim( (string) get_option( 'tg_payment_instructions', '' ) );

		if ( $instructions ) {
			echo '<div class="tg-note" style="padding:12px;border-radius:4px;background:#f6f7f7;margin:0 0 14px;">'
				. wp_kses_post( wpautop( esc_html( $instructions ) ) ) . '</div>';
		}
	}

	private static function render_plans( $plans, $current, $active, $request ) {
		echo '<h3 style="margin:18px 0 8px;">' . ( $active ? 'Renew or change plan' : 'Available plans' ) . '</h3>';

		if ( empty( $plans ) ) {
			echo '<p class="tg-note">No plans are published yet. Please contact us and we will arrange your '
				. 'subscription directly.</p>';

			return;
		}

		$vat_rate = TG_Subscription::vat_rate();
		$periods  = TG_Subscription::periods();

		echo '<div class="tg-plans" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;">';

		foreach ( $plans as $plan ) {
			$price = (float) $plan['price'];
			$vat   = round( $price * $vat_rate / 100, 3 );
			$mine  = $current && (int) $current['plan_id'] === (int) $plan['id'] && $active;

			echo '<div style="border:1px solid ' . ( $mine ? '#1d7a3f' : '#dcdcde' ) . ';border-radius:5px;padding:16px;background:#fff;">';

			if ( $mine ) {
				echo '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:#1d7a3f;color:#fff;'
					. 'font-size:11px;font-weight:600;margin-bottom:8px;">Your current plan</span>';
			}

			echo '<h4 style="margin:0 0 4px;font-size:16px;">' . esc_html( $plan['name'] ) . '</h4>';
			echo '<p style="margin:0 0 2px;font-size:20px;font-weight:700;">'
				. esc_html( number_format( $price, 3 ) . ' ' . $plan['currency'] ) . '</p>';
			echo '<p class="tg-note" style="margin:0 0 10px;">'
				. esc_html( isset( $periods[ $plan['period'] ] ) ? $periods[ $plan['period'] ] : $plan['period'] )
				. esc_html( sprintf( ' - plus VAT %s, total %s %s', number_format( $vat, 3 ), number_format( $price + $vat, 3 ), $plan['currency'] ) )
				. '</p>';

			if ( (int) $plan['free_months'] ) {
				echo '<p class="tg-note" style="margin:0 0 10px;color:#1d7a3f;"><strong>'
					. esc_html( sprintf( '+ %d free month(s)', (int) $plan['free_months'] ) ) . '</strong></p>';
			}

			if ( $plan['description'] ) {
				echo '<p style="margin:0 0 12px;font-size:0.94em;">' . esc_html( $plan['description'] ) . '</p>';
			}

			if ( $request ) {
				echo '<p class="tg-note" style="margin:0;">A request is already waiting.</p>';
			} else {
				echo '<form method="post" style="margin:0;">';
				wp_nonce_field( 'tg_sub_request_' . (int) $plan['id'], 'tg_sub_nonce' );
				echo '<input type="hidden" name="tg_sub_action" value="request">';
				echo '<input type="hidden" name="plan_id" value="' . esc_attr( (string) (int) $plan['id'] ) . '">';
				echo '<button type="submit" class="tg-btn ' . ( $mine ? 'tg-btn--ghost' : 'tg-btn--accent' ) . '">'
					. ( $active ? 'Request renewal' : 'Request this plan' ) . '</button>';
				echo '</form>';
			}

			echo '</div>';
		}

		echo '</div>';
		echo '<p class="tg-note" style="margin-top:10px;">Requesting a plan does not take payment. We will contact you '
			. 'with the invoice, and your access opens as soon as the payment is recorded.</p>';
	}

	private static function render_invoices( $history ) {
		echo '<h3 style="margin:22px 0 8px;">Invoices</h3>';

		if ( empty( $history ) ) {
			echo '<p class="tg-note">Nothing has been invoiced to you yet.</p>';

			return;
		}

		echo '<table class="tg-table" style="width:100%;border-collapse:collapse;">';
		echo '<thead><tr>'
			. '<th style="text-align:left;padding:8px 6px;">Invoice</th>'
			. '<th style="text-align:left;padding:8px 6px;">Plan</th>'
			. '<th style="text-align:left;padding:8px 6px;">Period</th>'
			. '<th style="text-align:left;padding:8px 6px;">Total</th>'
			. '<th style="text-align:left;padding:8px 6px;"></th>'
			. '</tr></thead><tbody>';

		foreach ( $history as $row ) {
			echo '<tr style="border-top:1px solid #e3e8ee;">';
			echo '<td style="padding:8px 6px;"><code>' . esc_html( $row['invoice_no'] ) . '</code><br>'
				. '<span class="tg-note">' . esc_html( self::date( $row['created_at'] ) ) . '</span></td>';
			echo '<td style="padding:8px 6px;">' . esc_html( $row['plan_name'] ) . '</td>';
			echo '<td style="padding:8px 6px;">' . esc_html( self::date( $row['starts_at'] ) . ' - ' . self::date( $row['ends_at'] ) ) . '</td>';
			echo '<td style="padding:8px 6px;">' . esc_html( number_format( (float) $row['total'], 3 ) . ' ' . $row['currency'] ) . '<br>'
				. '<span class="tg-note">' . esc_html( 'inc. VAT ' . number_format( (float) $row['vat_amount'], 3 ) ) . '</span></td>';
			echo '<td style="padding:8px 6px;"><a class="tg-link" href="' . esc_url( self::invoice_url( $row['id'] ) ) . '">View / print</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="tg-note" style="margin-top:10px;">Every period you have paid for keeps its own invoice number. '
			. 'They stay here permanently, including after a subscription ends.</p>';
	}

	private static function date( $value ) {
		if ( empty( $value ) ) {
			return '-';
		}

		$time = strtotime( $value );

		return $time ? date_i18n( 'j M Y', $time ) : (string) $value;
	}
}
