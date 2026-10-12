<?php
/**
 * Subscription admin: the plans, the VAT settings that appear on invoices,
 * and the record of every period a supplier has bought.
 *
 * There is no payment gateway yet, so an administrator records a subscription
 * against a payment they have seen - a bank transfer, usually. That is not a
 * stopgap: the platform never handles the money in any design we have
 * discussed, so recording and invoicing is the whole job.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Admin_Subscriptions {

	const SLUG = 'tg-subscriptions';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_tg_plan_save', array( __CLASS__, 'handle_plan_save' ) );
		add_action( 'admin_post_tg_plan_delete', array( __CLASS__, 'handle_plan_delete' ) );
		add_action( 'admin_post_tg_sub_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_tg_sub_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_tg_billing_save', array( __CLASS__, 'handle_billing_save' ) );
		add_action( 'admin_post_tg_req_decline', array( __CLASS__, 'handle_req_decline' ) );
	}

	public static function menu() {
		add_submenu_page(
			'tender-gateway',
			'Subscriptions',
			'Subscriptions',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'screen' )
		);
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'subs';

		echo '<div class="wrap"><h1>Subscriptions</h1>';
		self::notice();

		echo '<h2 class="nav-tab-wrapper">';

		$pending = TG_Subscription::request_count( TG_Subscription::PENDING );

		$tabs = array(
			'subs'      => 'Subscriptions',
			'renewals'  => 'Requests &amp; renewals' . ( $pending
				? ' <span style="display:inline-block;min-width:18px;padding:0 6px;border-radius:9px;background:#d63638;'
					. 'color:#fff;font-size:11px;line-height:18px;text-align:center;">' . (int) $pending . '</span>'
				: '' ),
			'plans'     => 'Plans',
			'billing'   => 'Billing &amp; VAT',
		);

		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( array( 'tab' => $key ) ) ),
				$tab === $key ? ' nav-tab-active' : '',
				$label // phpcs:ignore WordPress.Security.EscapeOutput -- literal labels.
			);
		}

		echo '</h2>';

		if ( 'plans' === $tab ) {
			self::plans_tab();
		} elseif ( 'billing' === $tab ) {
			self::billing_tab();
		} elseif ( 'renewals' === $tab ) {
			self::renewals_tab();
		} else {
			self::subs_tab();
		}

		echo '</div>';
	}

	/* -------------------------------------------------------- Subscriptions */

	private static function subs_tab() {
		global $wpdb;

		$counts = TG_Subscription::counts();
		$rows   = (array) $wpdb->get_results(
			"SELECT * FROM " . TG_Store::subs_table() . " ORDER BY id DESC LIMIT 200",
			ARRAY_A
		);

		printf(
			'<p class="description">%d active, %d expired. Revenue invoiced to date: %s (net of VAT).</p>',
			(int) $counts['active'],
			(int) $counts['expired'],
			esc_html( number_format( TG_Subscription::revenue(), 3 ) . ' ' . get_option( 'tg_currency', 'OMR' ) )
		);

		self::start_form();

		echo '<h2 style="margin-top:22px;">Recorded subscriptions</h2>';
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th style="width:19%;">Supplier</th><th style="width:13%;">Plan</th><th style="width:16%;">Period</th>';
		echo '<th style="width:13%;">Invoice</th><th style="width:13%;">Total</th><th style="width:12%;">Payment</th>';
		echo '<th style="width:8%;">Status</th><th style="width:6%;"></th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="8">No subscriptions recorded yet.</td></tr>';
		}

		foreach ( $rows as $row ) {
			$company = TG_Award::company_of( $row['supplier_id'] );
			// Same frame as TG_Subscription::is_active(), or this badge and the
			// real access rule disagree for the length of the timezone offset.
			$live    = strtotime( $row['ends_at'] ) > strtotime( current_time( 'mysql' ) )
				&& in_array( $row['status'], array( TG_Subscription::ACTIVE, TG_Subscription::CANCELLED ), true );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $company ) . '</strong></td>';
			echo '<td>' . esc_html( $row['plan_name'] ) . '</td>';
			echo '<td>' . esc_html( self::date( $row['starts_at'] ) . ' &ndash; ' . self::date( $row['ends_at'] ) ) . '</td>';
			echo '<td><code>' . esc_html( $row['invoice_no'] ) . '</code></td>';
			echo '<td>' . esc_html( number_format( (float) $row['total'], 3 ) . ' ' . $row['currency'] );
			echo '<br><span class="description">' . esc_html( 'inc. VAT ' . number_format( (float) $row['vat_amount'], 3 ) ) . '</span></td>';
			echo '<td>' . esc_html( $row['payment_method'] );

			if ( $row['payment_ref'] ) {
				echo '<br><span class="description">' . esc_html( $row['payment_ref'] ) . '</span>';
			}

			echo '</td>';
			echo '<td>' . self::badge( $row['status'], $live ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>';

			if ( TG_Subscription::ACTIVE === $row['status'] && $live ) {
				echo '<a class="button button-small" href="' . esc_url( wp_nonce_url(
					add_query_arg( array( 'action' => 'tg_sub_cancel', 'sub' => (int) $row['id'] ), admin_url( 'admin-post.php' ) ),
					'tg_sub_cancel_' . (int) $row['id']
				) ) . '" onclick="return confirm(\'Cancel this subscription? Access continues until the end of the paid period, and there is no refund.\');">Cancel</a>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description" style="margin-top:12px;">Cancelling stops the subscription renewing. Access runs to '
			. 'the end of the period already paid for, and no refund is given - the rule you set.</p>';
	}

	private static function start_form() {
		$plans = TG_Subscription::plans();

		echo '<h2 style="margin-top:18px;">Record a subscription</h2>';

		if ( empty( $plans ) ) {
			echo '<div class="notice notice-warning inline"><p>No active plans yet. Create one on the Plans tab first.</p></div>';

			return;
		}

		$suppliers = get_users( array( 'role' => TG_Auth::ROLE, 'orderby' => 'display_name' ) );

		// Prefilled when arriving from a plan request, so recording the payment
		// is one click rather than finding the supplier again in a long list.
		$pick_supplier = isset( $_GET['supplier'] ) ? (int) $_GET['supplier'] : 0;
		$pick_plan     = isset( $_GET['plan'] ) ? (int) $_GET['plan'] : 0;

		echo '<form id="tg-record" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tg-startsub">';
		wp_nonce_field( 'tg_sub_start' );
		echo '<input type="hidden" name="action" value="tg_sub_start">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">Supplier</th><td><select name="supplier_id" required style="min-width:320px;">';
		echo '<option value="">&mdash; choose &mdash;</option>';

		foreach ( $suppliers as $s ) {
			$company = get_user_meta( $s->ID, 'tg_company', true );
			$status  = TG_Admin_Suppliers::status_of( $s->ID );

			printf(
				'<option value="%d"%s>%s (%s)</option>',
				$s->ID,
				selected( $s->ID, $pick_supplier, false ),
				esc_html( $company ? $company : $s->display_name ),
				esc_html( TG_Admin_Suppliers::label( $status ) )
			);
		}

		echo '</select><p class="description">A subscription can be recorded for any supplier, but only a '
			. '<strong>verified</strong> one can bid.</p></td></tr>';

		echo '<tr><th scope="row">Plan</th><td><select name="plan_id" required>';

		foreach ( $plans as $p ) {
			printf(
				'<option value="%d"%s>%s &mdash; %s %s, %s%s</option>',
				(int) $p['id'],
				selected( (int) $p['id'], $pick_plan, false ),
				esc_html( $p['name'] ),
				esc_html( number_format( (float) $p['price'], 3 ) ),
				esc_html( $p['currency'] ),
				esc_html( TG_Subscription::periods()[ $p['period'] ] ),
				$p['free_months'] ? esc_html( ' + ' . (int) $p['free_months'] . ' free month(s)' ) : ''
			);
		}

		echo '</select></td></tr>';

		echo '<tr><th scope="row">Payment method</th><td><input name="payment_method" class="regular-text" value="bank transfer"></td></tr>';
		echo '<tr><th scope="row">Payment reference</th><td><input name="payment_ref" class="regular-text" placeholder="Transfer reference or receipt number">';
		echo '<p class="description">Whatever lets you find this payment again on the bank statement.</p></td></tr>';
		echo '<tr><th scope="row">Note</th><td><textarea name="note" rows="2" class="large-text" style="max-width:560px;"></textarea></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Record subscription and issue invoice number' );
		echo '</form>';
	}

	/* ------------------------------------------------- Requests & renewals */

	/**
	 * The screen somebody actually works from: who has asked for a plan, and
	 * whose subscription is about to run out.
	 *
	 * It exists because email cannot be relied on here. The host has outgoing
	 * mail disabled, so the reminder the platform sends may never arrive - and
	 * a reminder nobody receives is not a reminder. This list, and the banner
	 * on the supplier's own dashboard, are the parts that work regardless.
	 */
	private static function renewals_tab() {
		self::requests_box();
		self::renewals_box();
		self::reminder_log_box();
	}

	private static function requests_box() {
		$requests = TG_Subscription::requests( TG_Subscription::PENDING );

		echo '<h2 style="margin-top:18px;">Plan requests waiting</h2>';
		echo '<p class="description">A supplier chose a plan on their dashboard. Nothing has been paid and no access '
			. 'has been given. Raise the invoice, and when the money arrives record the subscription - that closes the '
			. 'request automatically.</p>';

		echo '<table class="wp-list-table widefat striped" style="max-width:1100px;"><thead><tr>';
		echo '<th style="width:26%;">Supplier</th><th style="width:18%;">Plan asked for</th><th style="width:14%;">Price then</th>';
		echo '<th style="width:14%;">Asked</th><th style="width:28%;"></th>';
		echo '</tr></thead><tbody>';

		if ( empty( $requests ) ) {
			echo '<tr><td colspan="5">No requests waiting.</td></tr>';
		}

		foreach ( $requests as $req ) {
			$supplier = get_userdata( (int) $req['supplier_id'] );
			$company  = TG_Award::company_of( $req['supplier_id'] );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $company ) . '</strong>';

			if ( $supplier ) {
				echo '<br><span class="description">' . esc_html( $supplier->user_email ) . '</span>';
				$phone = get_user_meta( $supplier->ID, 'tg_phone', true );

				if ( $phone ) {
					echo '<br><span class="description">' . esc_html( $phone ) . '</span>';
				}
			}

			echo '<br><span class="description">' . esc_html( TG_Admin_Suppliers::label( TG_Admin_Suppliers::status_of( $req['supplier_id'] ) ) ) . '</span>';
			echo '</td>';
			echo '<td>' . esc_html( $req['plan_name'] ) . '</td>';
			echo '<td>' . esc_html( number_format( (float) $req['price'], 3 ) . ' ' . $req['currency'] ) . '</td>';
			echo '<td>' . esc_html( self::date( $req['created_at'] ) ) . '</td>';
			echo '<td>';
			echo '<a class="button button-small button-primary" href="' . esc_url( self::url( array(
				'tab'      => 'subs',
				'supplier' => (int) $req['supplier_id'],
				'plan'     => (int) $req['plan_id'],
			) ) ) . '#tg-record">Record payment</a> ';
			echo '<a class="button button-small" href="' . esc_url( wp_nonce_url(
				add_query_arg( array( 'action' => 'tg_req_decline', 'req' => (int) $req['id'] ), admin_url( 'admin-post.php' ) ),
				'tg_req_decline_' . (int) $req['id']
			) ) . '" onclick="return confirm(\'Decline this request? The supplier keeps whatever access they already have.\');">Decline</a>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private static function renewals_box() {
		$rows = TG_Subscription::renewals_due( 14, 30 );

		echo '<h2 style="margin-top:26px;">Ending soon or lapsed</h2>';
		echo '<p class="description">Subscriptions ending within 14 days, and those that lapsed in the last 30. A '
			. 'renewal bought before the end date is added on top of the days left, so nobody loses time by renewing early.</p>';

		echo '<table class="wp-list-table widefat striped" style="max-width:1100px;"><thead><tr>';
		echo '<th style="width:28%;">Supplier</th><th style="width:16%;">Plan</th><th style="width:14%;">Ends</th>';
		echo '<th style="width:12%;">Days left</th><th style="width:30%;">Reminders</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="5">Nothing ending in the next 14 days.</td></tr>';
		}

		$labels = array(
			'd14'    => '14 days',
			'd7'     => '7 days',
			'd1'     => '1 day',
			'lapsed' => 'lapsed',
		);

		foreach ( $rows as $row ) {
			$days = TG_Subscription::days_left( $row );
			$sent = TG_Subscription::reminders_sent( $row );
			$due  = TG_Subscription::reminder_due( $row );

			echo '<tr>';
			echo '<td><strong>' . esc_html( TG_Award::company_of( $row['supplier_id'] ) ) . '</strong></td>';
			echo '<td>' . esc_html( $row['plan_name'] ) . '</td>';
			echo '<td>' . esc_html( self::date( $row['ends_at'] ) ) . '</td>';
			echo '<td>' . ( null === $days
				? '-'
				: ( $days < 0
					? '<span style="color:#8a2424;">' . esc_html( sprintf( '%d days ago', abs( (int) $days ) ) ) . '</span>'
					: esc_html( (string) (int) $days ) ) ) . '</td>';
			echo '<td>';

			if ( $sent ) {
				$names = array();

				foreach ( $sent as $key ) {
					$names[] = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
				}

				echo esc_html( 'sent: ' . implode( ', ', $names ) );
			} else {
				echo '<span class="description">none sent</span>';
			}

			if ( $due ) {
				echo '<br><span style="color:#8a2424;">' . esc_html( sprintf(
					'%s notice could not be emailed%s - please contact them directly.',
					isset( $labels[ $due ] ) ? $labels[ $due ] : $due,
					! empty( $row['reminder_tried_at'] ) ? ' (last tried ' . self::date( $row['reminder_tried_at'] ) . ')' : ''
				) ) . '</span>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private static function reminder_log_box() {
		$log = TG_Subscription::reminder_log();

		echo '<h2 style="margin-top:26px;">Reminder delivery log</h2>';

		if ( empty( $log ) ) {
			echo '<p class="description">No reminder has been attempted yet. The scheduler checks every five minutes '
				. 'and warns a supplier 14, 7 and 1 day before their subscription ends, and once more when it lapses.</p>';

			return;
		}

		$failed = 0;

		foreach ( $log as $entry ) {
			if ( empty( $entry['ok'] ) ) {
				$failed++;
			}
		}

		if ( $failed ) {
			printf(
				'<div class="notice notice-warning inline"><p><strong>%d of the last %d reminder emails could not be '
				. 'sent.</strong> Outgoing mail is disabled on this hosting account, so this is expected until an SMTP '
				. 'service is configured. The warning still appears on each supplier\'s own dashboard, and the list '
				. 'above tells you who to contact.</p></div>',
				(int) $failed,
				count( $log )
			);
		}

		echo '<table class="wp-list-table widefat striped" style="max-width:760px;"><thead><tr>';
		echo '<th style="width:26%;">When</th><th style="width:34%;">Supplier</th><th style="width:20%;">Notice</th><th style="width:20%;">Emailed</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $log, 0, 20 ) as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::date( isset( $entry['at'] ) ? $entry['at'] : '' ) ) . '</td>';
			echo '<td>' . esc_html( TG_Award::company_of( isset( $entry['supplier'] ) ? $entry['supplier'] : 0 ) ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['milestone'] ) ? $entry['milestone'] : '-' ) . '</td>';
			echo '<td>' . ( empty( $entry['ok'] )
				? '<span style="color:#8a2424;">no</span>'
				: '<span style="color:#1d7a3f;">yes</span>' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------- Plans */

	private static function plans_tab() {
		$plans   = TG_Subscription::plans( false );
		$editing = isset( $_GET['plan'] ) ? TG_Subscription::plan( (int) $_GET['plan'] ) : null;

		echo '<table class="wp-list-table widefat striped" style="max-width:980px;margin-top:16px;"><thead><tr>';
		echo '<th style="width:26%;">Plan</th><th style="width:14%;">Price</th><th style="width:14%;">Period</th>';
		echo '<th style="width:14%;">Free months</th><th style="width:12%;">Active</th><th style="width:20%;"></th>';
		echo '</tr></thead><tbody>';

		if ( empty( $plans ) ) {
			echo '<tr><td colspan="6">No plans yet. Create the first one below.</td></tr>';
		}

		foreach ( $plans as $p ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $p['name'] ) . '</strong>';

			if ( $p['description'] ) {
				echo '<br><span class="description">' . esc_html( $p['description'] ) . '</span>';
			}

			echo '</td>';
			echo '<td>' . esc_html( number_format( (float) $p['price'], 3 ) . ' ' . $p['currency'] ) . '</td>';
			echo '<td>' . esc_html( TG_Subscription::periods()[ $p['period'] ] ) . '</td>';
			echo '<td>' . (int) $p['free_months'] . '</td>';
			echo '<td>' . ( $p['is_active'] ? 'Yes' : '<span class="description">No</span>' ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( self::url( array( 'tab' => 'plans', 'plan' => (int) $p['id'] ) ) ) . '">Edit</a> ';
			echo '<a class="button button-small" href="' . esc_url( wp_nonce_url(
				add_query_arg( array( 'action' => 'tg_plan_delete', 'plan' => (int) $p['id'] ), admin_url( 'admin-post.php' ) ),
				'tg_plan_delete_' . (int) $p['id']
			) ) . '">Remove</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2 style="margin-top:22px;">' . ( $editing ? 'Edit plan' : 'Add a plan' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tg_plan_save' );
		echo '<input type="hidden" name="action" value="tg_plan_save">';
		echo '<input type="hidden" name="plan" value="' . esc_attr( $editing ? $editing['id'] : '' ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';

		$v = function ( $k, $d = '' ) use ( $editing ) {
			return $editing && isset( $editing[ $k ] ) ? $editing[ $k ] : $d;
		};

		echo '<tr><th scope="row">Name</th><td><input name="name" class="regular-text" required value="' . esc_attr( $v( 'name' ) ) . '"></td></tr>';
		echo '<tr><th scope="row">Description</th><td><textarea name="description" rows="2" class="large-text" style="max-width:560px;">' . esc_textarea( $v( 'description' ) ) . '</textarea></td></tr>';
		echo '<tr><th scope="row">Price</th><td><input name="price" type="text" inputmode="decimal" class="small-text" value="' . esc_attr( $v( 'price', '0.000' ) ) . '"> ';
		echo '<input name="currency" class="small-text" value="' . esc_attr( $v( 'currency', 'OMR' ) ) . '">';
		echo '<p class="description">Price before VAT. VAT is added automatically at the rate on the Billing tab.</p></td></tr>';

		echo '<tr><th scope="row">Billing period</th><td><select name="period">';

		foreach ( TG_Subscription::periods() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $key, $v( 'period', 'monthly' ), false ), esc_html( $label ) );
		}

		echo '</select></td></tr>';
		echo '<tr><th scope="row">Free months</th><td><input name="free_months" type="number" min="0" class="small-text" value="' . esc_attr( $v( 'free_months', '0' ) ) . '">';
		echo '<p class="description">Added on top of the paid period. This is where the launch offer of one free month goes.</p></td></tr>';
		echo '<tr><th scope="row">Active</th><td><label><input type="checkbox" name="is_active" value="1" ' . checked( 1, (int) $v( 'is_active', 1 ), false ) . '> Offer this plan</label></td></tr>';
		echo '</tbody></table>';

		submit_button( $editing ? 'Save plan' : 'Add plan' );
		echo '</form>';
	}

	/* -------------------------------------------------------------- Billing */

	private static function billing_tab() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px;">';
		wp_nonce_field( 'tg_billing_save' );
		echo '<input type="hidden" name="action" value="tg_billing_save">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">VAT number</th><td><input name="vat_number" class="regular-text" value="'
			. esc_attr( TG_Subscription::vat_number() ) . '" placeholder="OM1100000000">';
		echo '<p class="description">Printed on every subscription invoice. Required once you are VAT registered.</p></td></tr>';

		echo '<tr><th scope="row">VAT rate</th><td><input name="vat_rate" type="text" inputmode="decimal" class="small-text" value="'
			. esc_attr( TG_Subscription::vat_rate() ) . '"> %';
		echo '<p class="description">Oman is 5%. The rate is stored on each invoice as it was at the time, so changing it '
			. 'later does not rewrite old invoices.</p></td></tr>';

		echo '<tr><th scope="row">Invoice prefix</th><td><input name="invoice_prefix" class="regular-text" value="'
			. esc_attr( get_option( 'tg_invoice_prefix', 'JT-INV-' ) ) . '">';
		echo '<p class="description">Invoice numbers are sequential and never reused, even if a subscription is removed - '
			. 'that is a legal requirement, not a preference. Changing the prefix does not renumber anything already issued.</p></td></tr>';

		echo '<tr><th scope="row">Require a subscription</th><td><label><input type="checkbox" name="require_subscription" value="1" '
			. checked( true, TG_Access::subscription_required(), false ) . '> Suppliers must subscribe to see full tender detail and bid</label>';
		echo '<p class="description" style="color:#8a2424;"><strong>Leave this on.</strong> Turning it off gives every '
			. 'registered supplier full access to every tender for nothing. It exists so you can demonstrate the bidding '
			. 'flow before plans are live - switch it back on afterwards.</p></td></tr>';

		echo '<tr><th scope="row">Invoice issued by</th><td><textarea name="invoice_from" rows="4" class="large-text" style="max-width:560px;">'
			. esc_textarea( get_option( 'tg_invoice_from', get_bloginfo( 'name' ) ) ) . '</textarea>';
		echo '<p class="description">Your company name and address exactly as they should appear at the top of every '
			. 'invoice a supplier prints.</p></td></tr>';

		echo '<tr><th scope="row">How to pay</th><td><textarea name="payment_instructions" rows="4" class="large-text" style="max-width:560px;" '
			. 'placeholder="Bank name, account name, account number, IBAN, and who to contact.">'
			. esc_textarea( get_option( 'tg_payment_instructions', '' ) ) . '</textarea>';
		echo '<p class="description">Shown to a supplier on their dashboard once they have requested a plan. Leave it '
			. 'empty and they are simply told you will be in touch.</p></td></tr>';

		echo '</tbody></table>';
		submit_button( 'Save billing settings' );
		echo '</form>';
	}

	/* ------------------------------------------------------------- Handlers */

	public static function handle_plan_save() {
		self::guard( 'tg_plan_save' );

		$post   = wp_unslash( $_POST );
		$id     = isset( $post['plan'] ) ? (int) $post['plan'] : 0;
		$result = TG_Subscription::save_plan( $post, $id );

		self::finish( $result, $id ? 'Plan saved.' : 'Plan added.', array( 'tab' => 'plans' ) );
	}

	public static function handle_plan_delete() {
		$id = isset( $_GET['plan'] ) ? (int) $_GET['plan'] : 0;

		self::guard( 'tg_plan_delete_' . $id, false );

		self::finish( TG_Subscription::delete_plan( $id ), 'Plan removed.', array( 'tab' => 'plans' ) );
	}

	public static function handle_start() {
		self::guard( 'tg_sub_start' );

		$post = wp_unslash( $_POST );

		$result = TG_Subscription::start(
			isset( $post['supplier_id'] ) ? (int) $post['supplier_id'] : 0,
			isset( $post['plan_id'] ) ? (int) $post['plan_id'] : 0,
			$post
		);

		if ( is_wp_error( $result ) ) {
			self::finish( $result, '' );
		}

		$sub = TG_Subscription::get( $result );

		self::finish( true, sprintf(
			'Subscription recorded. Invoice %s issued for %s %s including VAT, running to %s.',
			$sub['invoice_no'],
			number_format( (float) $sub['total'], 3 ),
			$sub['currency'],
			self::date( $sub['ends_at'] )
		) );
	}

	public static function handle_cancel() {
		$id = isset( $_GET['sub'] ) ? (int) $_GET['sub'] : 0;

		self::guard( 'tg_sub_cancel_' . $id, false );

		self::finish( TG_Subscription::cancel( $id, 'Cancelled by admin.' ), 'Subscription cancelled. Access runs to the end of the paid period.' );
	}

	public static function handle_req_decline() {
		$id = isset( $_GET['req'] ) ? (int) $_GET['req'] : 0;

		self::guard( 'tg_req_decline_' . $id, false );

		self::finish(
			TG_Subscription::close_request( $id, TG_Subscription::DECLINED, 0, 'Declined by admin.' ),
			'Request declined. The supplier keeps whatever access they already had.',
			array( 'tab' => 'renewals' )
		);
	}

	public static function handle_billing_save() {
		self::guard( 'tg_billing_save' );

		$post = wp_unslash( $_POST );

		update_option( 'tg_vat_number', sanitize_text_field( isset( $post['vat_number'] ) ? $post['vat_number'] : '' ) );
		update_option( 'tg_vat_rate', max( 0, (float) ( isset( $post['vat_rate'] ) ? $post['vat_rate'] : 5 ) ) );

		$prefix = sanitize_text_field( isset( $post['invoice_prefix'] ) ? $post['invoice_prefix'] : 'JT-INV-' );
		update_option( 'tg_invoice_prefix', $prefix ? $prefix : 'JT-INV-' );

		update_option( 'tg_require_subscription', empty( $post['require_subscription'] ) ? 'no' : 'yes' );

		update_option( 'tg_invoice_from', sanitize_textarea_field( isset( $post['invoice_from'] ) ? $post['invoice_from'] : '' ) );
		update_option( 'tg_payment_instructions', sanitize_textarea_field( isset( $post['payment_instructions'] ) ? $post['payment_instructions'] : '' ) );

		self::finish( true, 'Billing settings saved.', array( 'tab' => 'billing' ) );
	}

	/* --------------------------------------------------------------- Helpers */

	private static function guard( $nonce, $post = true ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		check_admin_referer( $nonce );
	}

	private static function finish( $result, $success, $args = array() ) {
		set_transient(
			'tg_admin_notice_' . get_current_user_id(),
			is_wp_error( $result )
				? array( 'type' => 'error', 'message' => $result->get_error_message() )
				: array( 'type' => 'success', 'message' => $success ),
			60
		);

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private static function notice() {
		$key    = 'tg_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( $notice['message'] )
		);
	}

	private static function badge( $status, $live ) {
		if ( $live && TG_Subscription::CANCELLED === $status ) {
			$label  = 'Cancelled';
			$colour = '#b26200';
		} elseif ( $live ) {
			$label  = 'Active';
			$colour = '#1d7a3f';
		} else {
			$label  = 'Expired';
			$colour = '#50575e';
		}

		return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . esc_attr( $colour )
			. ';color:#fff;font-size:11px;font-weight:600;">' . esc_html( $label ) . '</span>';
	}

	private static function date( $value ) {
		if ( empty( $value ) ) {
			return '-';
		}

		$time = strtotime( $value );

		return $time ? date_i18n( 'j M Y', $time ) : (string) $value;
	}
}
