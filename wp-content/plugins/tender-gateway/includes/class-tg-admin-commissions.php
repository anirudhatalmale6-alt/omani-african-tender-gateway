<?php
/**
 * Commission admin: what is due, what is overdue, what has been paid, and
 * the terms suppliers agree to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Admin_Commissions {

	const SLUG = 'tg-commissions';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_tg_com_pay', array( __CLASS__, 'handle_pay' ) );
		add_action( 'admin_post_tg_com_void', array( __CLASS__, 'handle_void' ) );
		add_action( 'admin_post_tg_com_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_tg_com_settings', array( __CLASS__, 'handle_settings' ) );
	}

	public static function menu() {
		add_submenu_page( 'tender-gateway', 'Commissions', 'Commissions', 'manage_options', self::SLUG, array( __CLASS__, 'screen' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'invoices';

		echo '<div class="wrap"><h1>Commissions</h1>';
		self::notice();

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( array( 'invoices' => 'Invoices', 'settings' => 'Rate, deadline &amp; terms' ) as $k => $l ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( array( 'tab' => $k ) ) ), $tab === $k ? ' nav-tab-active' : '', $l ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</h2>';

		if ( 'settings' === $tab ) {
			self::settings_tab();
		} else {
			self::invoices_tab();
		}

		echo '</div>';
	}

	/* ------------------------------------------------------------- Invoices */

	private static function invoices_tab() {
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		$filter = in_array( $filter, array( 'all', 'due', 'overdue', 'paid', 'void' ), true ) ? $filter : 'all';
		$cancel = isset( $_GET['cancel'] ) ? TG_Commission::get( (int) $_GET['cancel'] ) : null;
		$t      = TG_Commission::totals();
		$cur    = get_option( 'tg_currency', 'OMR' );

		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">';
		foreach ( array(
			array( 'Awaiting payment', $t['due'], $t['count_due'], '#b26200' ),
			array( 'Overdue', $t['overdue'], $t['count_overdue'], '#8a2424' ),
			array( 'Paid', $t['paid'], $t['count_paid'], '#1d7a3f' ),
		) as $card ) {
			printf(
				'<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid %s;padding:12px 16px;min-width:200px;">'
				. '<div style="font-size:12px;color:#50575e;">%s</div><div style="font-size:20px;font-weight:600;">%s %s</div>'
				. '<div class="description">%d invoice(s), including VAT</div></div>',
				esc_attr( $card[3] ),
				esc_html( $card[0] ),
				esc_html( number_format( $card[1], 3 ) ),
				esc_html( $cur ),
				(int) $card[2]
			);
		}
		echo '</div>';

		if ( $cancel && TG_Commission::DUE === $cancel['status'] ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;padding:14px 18px;max-width:720px;margin:0 0 16px;">';
			echo '<h2 style="margin-top:0;">Cancel ' . esc_html( $cancel['invoice_no'] ) . '</h2>';
			echo '<p class="description">Use this when the deal behind it did not go ahead. The invoice number is kept and never reused, and your reason is stored with it.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'tg_com_void_' . (int) $cancel['id'] );
			echo '<input type="hidden" name="action" value="tg_com_void"><input type="hidden" name="id" value="' . (int) $cancel['id'] . '">';
			echo '<textarea name="reason" rows="2" class="large-text" required placeholder="Why is this invoice being cancelled?"></textarea>';
			submit_button( 'Cancel this invoice', 'delete', 'submit', false );
			echo ' <a class="button" href="' . esc_url( self::url() ) . '">Keep it</a></form></div>';
		}

		$links = array();
		foreach ( array( 'all' => 'All', 'due' => 'Awaiting payment', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Cancelled' ) as $k => $l ) {
			$links[] = '<a href="' . esc_url( self::url( array( 'filter' => $k ) ) ) . '"' . ( $filter === $k ? ' class="current"' : '' ) . '>' . esc_html( $l )
				. ' <span class="count">(' . count( TG_Commission::query( $k ) ) . ')</span></a>';
		}
		echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="float:right;">';
		wp_nonce_field( 'tg_com_export' );
		echo '<input type="hidden" name="action" value="tg_com_export"><input type="hidden" name="filter" value="' . esc_attr( $filter ) . '">';
		submit_button( 'Export CSV', 'secondary', 'submit', false );
		echo '</form>';

		$rows = TG_Commission::query( $filter );

		echo '<table class="wp-list-table widefat striped" style="clear:both;"><thead><tr>';
		echo '<th style="width:12%;">Invoice</th><th style="width:20%;">Tender</th><th style="width:16%;">Supplier</th>';
		echo '<th style="width:12%;">Total</th><th style="width:10%;">Due</th><th style="width:8%;">Status</th><th style="width:22%;">Action</th>';
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="7">No commission invoices here yet. One is issued automatically when an awarded tender is marked Completed.</td></tr>';
		}

		$colours = array( 'Due' => '#b26200', 'Overdue' => '#8a2424', 'Paid' => '#1d7a3f', 'Cancelled' => '#646970' );

		foreach ( $rows as $r ) {
			$tender = TG_Tender::get( $r['tender_id'] );
			$label  = TG_Commission::label( $r );

			echo '<tr>';
			echo '<td><code>' . esc_html( $r['invoice_no'] ) . '</code><br><a href="' . esc_url( TG_Commission_UI::invoice_url( $r['id'] ) ) . '" target="_blank">View invoice</a></td>';
			echo '<td>' . ( $tender ? '<a href="' . esc_url( TG_Admin_Tenders::edit_url( $tender['id'] ) ) . '">' . esc_html( $tender['external_id'] ) . '</a><br><span class="description">' . esc_html( $tender['title'] ) . '</span>' : '-' ) . '</td>';
			echo '<td>' . esc_html( TG_Award::company_of( $r['supplier_id'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format( (float) $r['total'], 3 ) . ' ' . $r['currency'] ) . '<br><span class="description">'
				. esc_html( rtrim( rtrim( number_format( (float) $r['rate'], 3 ), '0' ), '.' ) . '% of ' . number_format( (float) $r['awarded_value'], 3 ) . ' + VAT' ) . '</span></td>';
			echo '<td>' . esc_html( date_i18n( 'j M Y', strtotime( $r['due_at'] ) ) ) . '</td>';
			echo '<td><span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:11px;font-weight:600;background:'
				. esc_attr( $colours[ $label ] ?? '#646970' ) . ';">' . esc_html( $label ) . '</span></td>';
			echo '<td>';

			if ( TG_Commission::DUE === $r['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:4px;margin:0 0 4px;">';
				wp_nonce_field( 'tg_com_pay_' . (int) $r['id'] );
				echo '<input type="hidden" name="action" value="tg_com_pay"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
				echo '<input type="text" name="payment_ref" placeholder="Transfer reference" style="width:140px;">';
				echo '<button class="button button-primary button-small">Record payment</button></form>';
				echo '<a href="' . esc_url( self::url( array( 'cancel' => (int) $r['id'] ) ) ) . '" class="description">Cancel invoice</a>';
			} elseif ( TG_Commission::PAID === $r['status'] ) {
				echo '<span class="description">Paid ' . esc_html( date_i18n( 'j M Y', strtotime( $r['paid_at'] ) ) ) . ( $r['payment_ref'] ? ' - ' . esc_html( $r['payment_ref'] ) : '' ) . '</span>';
			} else {
				echo '<span class="description">' . esc_html( $r['void_reason'] ) . '</span>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description" style="margin-top:12px;">A supplier with an overdue invoice cannot submit new bids until it is paid. Their existing bids are not affected.</p>';
	}

	/* ------------------------------------------------------------- Settings */

	private static function settings_tab() {
		$suppliers = get_users( array( 'role' => TG_Auth::ROLE, 'fields' => 'ID' ) );
		$accepted  = count( array_filter( $suppliers, array( 'TG_Terms', 'has_accepted' ) ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px;">';
		wp_nonce_field( 'tg_com_settings' );
		echo '<input type="hidden" name="action" value="tg_com_settings">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">Commission rate</th><td><input name="rate" type="text" inputmode="decimal" class="small-text" value="' . esc_attr( TG_Commission::rate() ) . '"> % of the awarded value, plus VAT</td></tr>';
		echo '<tr><th scope="row">Payment deadline</th><td><input name="days" type="number" min="1" class="small-text" value="' . esc_attr( TG_Commission::due_days() ) . '"> days from the invoice date</td></tr>';
		echo '<tr><th scope="row">Supplier terms</th><td>';
		echo '<textarea name="terms" rows="16" class="large-text" style="max-width:820px;">' . esc_textarea( TG_Terms::raw_text() ) . '</textarea>';
		echo '<p class="description">{rate} and {days} are replaced with the two settings above, so the terms always match what is charged. '
			. '<strong>Have your lawyer approve this wording before going live.</strong></p>';
		echo '<p class="description">Current version: <strong>' . (int) TG_Terms::version() . '</strong> - accepted by ' . (int) $accepted . ' of ' . count( $suppliers ) . ' supplier(s). '
			. 'Changing the wording, the rate or the deadline creates a new version, and every supplier must accept it before their next bid.</p>';
		echo '</td></tr></tbody></table>';
		submit_button( 'Save' );
		echo '</form>';
	}

	/* ------------------------------------------------------------- Handlers */

	public static function handle_pay() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		self::guard( 'tg_com_pay_' . $id );

		$r = TG_Commission::record_payment( $id, array( 'payment_ref' => isset( $_POST['payment_ref'] ) ? wp_unslash( $_POST['payment_ref'] ) : '' ) );
		$c = TG_Commission::get( $id );
		self::finish( $r, 'Payment recorded for ' . ( $c ? $c['invoice_no'] : 'the invoice' ) . '. If it was blocking the supplier from bidding, they can bid again now.' );
	}

	public static function handle_void() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		self::guard( 'tg_com_void_' . $id );

		self::finish( TG_Commission::void( $id, isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '' ), 'Invoice cancelled. The number stays on record and will not be reused.' );
	}

	public static function handle_export() {
		self::guard( 'tg_com_export' );

		$filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : 'all';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="commissions-' . $filter . '-' . current_time( 'Y-m-d' ) . '.csv"' );
		echo "\xEF\xBB\xBF" . TG_Commission::csv( TG_Commission::query( $filter ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV download.
		exit;
	}

	public static function handle_settings() {
		self::guard( 'tg_com_settings' );

		$post    = wp_unslash( $_POST );
		$changed = TG_Terms::save(
			sanitize_textarea_field( isset( $post['terms'] ) ? $post['terms'] : '' ),
			isset( $post['rate'] ) ? (float) $post['rate'] : TG_Commission::rate(),
			isset( $post['days'] ) ? (int) $post['days'] : TG_Commission::due_days()
		);

		self::finish( true, $changed
			? 'Saved. This is a new version of the terms (version ' . TG_Terms::version() . '): every supplier must accept it before their next bid.'
			: 'Saved. The terms did not change.', array( 'tab' => 'settings' ) );
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		check_admin_referer( $nonce );
	}

	private static function finish( $result, $success, $args = array() ) {
		set_transient( 'tg_com_notice_' . get_current_user_id(), is_wp_error( $result )
			? array( 'type' => 'error', 'message' => $result->get_error_message() )
			: array( 'type' => 'success', 'message' => $success ), 60 );
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private static function notice() {
		$key = 'tg_com_notice_' . get_current_user_id();
		$n   = get_transient( $key );

		if ( ! $n ) {
			return;
		}

		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $n['type'] ? 'error' : 'success', esc_html( $n['message'] ) );
	}
}
