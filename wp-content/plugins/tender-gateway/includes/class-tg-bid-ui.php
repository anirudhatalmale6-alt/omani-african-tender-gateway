<?php
/**
 * The supplier-facing side of bidding: the panel on a tender page, the form,
 * and the supplier's own bid history on their dashboard.
 *
 * Every form submission is re-checked server-side through TG_Access::can_bid()
 * and TG_Bid. Nothing here trusts the page it came from - the screens hide what
 * cannot be done, but hiding is not enforcing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Bid_UI {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_forms' ), 5 );
		add_action( 'tg_after_tender_detail', array( __CLASS__, 'render_panel' ) );
		add_action( 'tg_dashboard_panels', array( __CLASS__, 'render_my_bids' ) );
	}

	/* ------------------------------------------------------------- Notices */

	private static function notice( $type = null, $message = null ) {
		static $store = array();

		if ( null !== $type ) {
			$store[] = array( 'type' => $type, 'message' => $message );

			return array();
		}

		return $store;
	}

	public static function add_error( $message ) {
		self::notice( 'error', $message );
	}

	public static function add_success( $message ) {
		self::notice( 'success', $message );
	}

	/* -------------------------------------------------------------- Forms */

	public static function handle_forms() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		$action = isset( $_POST['tg_bid_action'] ) ? sanitize_key( wp_unslash( $_POST['tg_bid_action'] ) ) : '';

		if ( 'submit' === $action ) {
			self::do_submit();
		} elseif ( 'withdraw' === $action ) {
			self::do_withdraw();
		}
	}

	private static function do_submit() {
		$tender_id = isset( $_POST['tender_id'] ) ? (int) $_POST['tender_id'] : 0;

		if ( ! isset( $_POST['tg_bid_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tg_bid_nonce'] ) ), 'tg_bid_' . $tender_id ) ) {
			self::add_error( 'Your session expired. Please try again.' );

			return;
		}

		$post = wp_unslash( $_POST );

		$bid = TG_Bid::submit( $tender_id, get_current_user_id(), array(
			'price'         => isset( $post['price'] ) ? str_replace( ',', '', $post['price'] ) : '',
			'currency'      => isset( $post['currency'] ) ? $post['currency'] : '',
			'delivery_days' => isset( $post['delivery_days'] ) ? $post['delivery_days'] : '',
			'validity_days' => isset( $post['validity_days'] ) ? $post['validity_days'] : '',
			'notes'         => isset( $post['notes'] ) ? $post['notes'] : '',
		) );

		if ( is_wp_error( $bid ) ) {
			self::add_error( $bid->get_error_message() );

			return;
		}

		$attached = self::handle_uploads( $bid );

		self::add_success(
			$attached
				? sprintf( 'Your bid has been submitted, with %d document(s) attached.', $attached )
				: 'Your bid has been submitted.'
		);
	}

	private static function handle_uploads( $bid_id ) {
		if ( empty( $_FILES['tg_bid_docs'] ) || ! is_array( $_FILES['tg_bid_docs']['name'] ) ) {
			return 0;
		}

		$count = 0;

		foreach ( array_keys( $_FILES['tg_bid_docs']['name'] ) as $i ) {
			if ( '' === $_FILES['tg_bid_docs']['name'][ $i ] ) {
				continue;
			}

			$file = array(
				'name'     => $_FILES['tg_bid_docs']['name'][ $i ],
				'type'     => $_FILES['tg_bid_docs']['type'][ $i ],
				'tmp_name' => $_FILES['tg_bid_docs']['tmp_name'][ $i ],
				'error'    => $_FILES['tg_bid_docs']['error'][ $i ],
				'size'     => $_FILES['tg_bid_docs']['size'][ $i ],
			);

			$stored = TG_Docs::store( $file, 'bid', $bid_id, '', get_current_user_id() );

			if ( is_wp_error( $stored ) ) {
				self::add_error( $file['name'] . ': ' . $stored->get_error_message() );
			} else {
				$count++;
			}
		}

		return $count;
	}

	private static function do_withdraw() {
		$bid_id = isset( $_POST['bid_id'] ) ? (int) $_POST['bid_id'] : 0;

		if ( ! isset( $_POST['tg_bid_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tg_bid_nonce'] ) ), 'tg_withdraw_' . $bid_id ) ) {
			self::add_error( 'Your session expired. Please try again.' );

			return;
		}

		$result = TG_Bid::withdraw( $bid_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			self::add_error( $result->get_error_message() );

			return;
		}

		self::add_success( 'Your bid has been withdrawn. You can submit a new one while the tender is still open.' );
	}

	/* ------------------------------------------------------- Tender panel */

	public static function render_panel( $tender ) {
		// Only the platform's own tenders can be bid on. Ministry feed records
		// carry no status and are read-only.
		if ( ! is_array( $tender ) || empty( $tender['status'] ) ) {
			return;
		}

		// The front end identifies a tender by its reference (JT-2026-0001);
		// the bids table keys on the numeric row id. Mixing the two silently
		// breaks the nonce and the lookup, so resolve it once here.
		$tender_id = isset( $tender['db_id'] ) ? (int) $tender['db_id'] : 0;

		if ( ! $tender_id ) {
			return;
		}

		$user_id = get_current_user_id();
		$bid     = $user_id ? TG_Bid::find( $tender_id, $user_id ) : null;
		$allowed = TG_Access::can_bid( $tender, $user_id );

		echo '<section class="tg-panel tg-bidbox" id="bid">';
		echo '<h2>Submit a bid</h2>';

		foreach ( self::notice() as $n ) {
			printf(
				'<div class="tg-note tg-note--%s" style="padding:10px 12px;border-radius:4px;margin:0 0 12px;background:%s;border-left:3px solid %s;">%s</div>',
				esc_attr( $n['type'] ),
				'error' === $n['type'] ? '#fdf2ee' : '#eef7f0',
				'error' === $n['type'] ? '#c2502a' : '#2f7d4a',
				esc_html( $n['message'] )
			);
		}

		if ( $bid && TG_Bid::SUBMITTED === $bid['status'] ) {
			self::render_existing_bid( $tender, $bid );
		}

		if ( is_wp_error( $allowed ) ) {
			self::render_blocked( $allowed, $tender );
			echo '</section>';

			return;
		}

		self::render_form( $tender, $bid, $tender_id );

		echo '</section>';
	}

	private static function render_existing_bid( $tender, $bid ) {
		$open = TG_Tender::accepts_bids( $tender );
		?>
		<div class="tg-note" style="padding:12px;border-radius:4px;background:#eef4fb;border-left:3px solid #2d6cb5;margin:0 0 14px;">
			<p style="margin:0 0 6px;"><strong>You have already bid on this tender.</strong></p>
			<p style="margin:0 0 6px;">
				<?php echo esc_html( $bid['currency'] . ' ' . number_format( (float) $bid['price'], 3 ) ); ?>
				<?php if ( $bid['delivery_days'] ) : ?>
					&middot; delivery in <?php echo (int) $bid['delivery_days']; ?> days
				<?php endif; ?>
				<?php if ( $bid['validity_days'] ) : ?>
					&middot; valid <?php echo (int) $bid['validity_days']; ?> days
				<?php endif; ?>
			</p>
			<p style="margin:0;font-size:0.92em;color:#50575e;">
				Submitted <?php echo esc_html( human_time_diff( strtotime( $bid['submitted_at'] ) ) ); ?> ago.
				<?php if ( $open ) : ?>
					You can change it or withdraw it until the tender closes.
				<?php else : ?>
					The tender has closed, so it can no longer be changed.
				<?php endif; ?>
			</p>
			<?php if ( $open ) : ?>
				<form method="post" style="margin:10px 0 0;">
					<?php wp_nonce_field( 'tg_withdraw_' . $bid['id'], 'tg_bid_nonce' ); ?>
					<input type="hidden" name="tg_bid_action" value="withdraw">
					<input type="hidden" name="bid_id" value="<?php echo esc_attr( $bid['id'] ); ?>">
					<button type="submit" class="tg-btn tg-btn--ghost" onclick="return confirm('Withdraw your bid? You can submit a new one while the tender is still open.');">Withdraw my bid</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Every refusal gets its own message and its own next step. "You cannot
	 * bid" with no reason is the fastest way to lose a supplier.
	 */
	private static function render_blocked( $error, $tender ) {
		$code = $error->get_error_code();

		$action = '';

		if ( 'tg_bid_signed_out' === $code ) {
			$action = '<a class="tg-btn tg-btn--accent" href="' . esc_url( tg_page_url( 'login' ) ) . '">Sign in</a> '
				. '<a class="tg-link" href="' . esc_url( tg_page_url( 'register' ) ) . '">or register as a supplier</a>';
		} elseif ( 'tg_bid_unsubscribed' === $code && TG_Access::subscriptions_available() ) {
			$action = '<a class="tg-btn tg-btn--accent" href="' . esc_url( tg_page_url( 'dashboard' ) ) . '">View subscription plans</a>';
		} elseif ( 'tg_bid_unverified' === $code ) {
			$action = '<span class="tg-link">We will email you as soon as your account is approved.</span>';
		}

		printf(
			'<div class="tg-note" style="padding:12px;border-radius:4px;background:#fdf8e7;border-left:3px solid #8a6d1f;"><p style="margin:0 0 %s;">%s</p>%s</div>',
			$action ? '10px' : '0',
			esc_html( $error->get_error_message() ),
			$action // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
		);
	}

	private static function render_form( $tender, $bid, $tender_id ) {
		$currency = $tender['currency'] ? $tender['currency'] : 'OMR';
		?>
		<form method="post" enctype="multipart/form-data" class="tg-bidform">
			<?php wp_nonce_field( 'tg_bid_' . $tender_id, 'tg_bid_nonce' ); ?>
			<input type="hidden" name="tg_bid_action" value="submit">
			<input type="hidden" name="tender_id" value="<?php echo esc_attr( (string) $tender_id ); ?>">

			<p>
				<label for="tg-price"><strong>Your price</strong> (<?php echo esc_html( $currency ); ?>) <span aria-hidden="true">*</span></label><br>
				<input type="text" inputmode="decimal" id="tg-price" name="price" required
					value="<?php echo $bid ? esc_attr( rtrim( rtrim( $bid['price'], '0' ), '.' ) ) : ''; ?>"
					placeholder="0.000">
				<input type="hidden" name="currency" value="<?php echo esc_attr( $currency ); ?>">
				<span class="tg-note">Total price for the full quantity requested.</span>
			</p>

			<p>
				<label for="tg-delivery"><strong>Delivery time</strong> (days)</label><br>
				<input type="number" min="0" id="tg-delivery" name="delivery_days"
					value="<?php echo $bid && $bid['delivery_days'] ? (int) $bid['delivery_days'] : ''; ?>">
				<span class="tg-note">How many days after award you can deliver.</span>
			</p>

			<p>
				<label for="tg-validity"><strong>Bid validity</strong> (days)</label><br>
				<input type="number" min="0" id="tg-validity" name="validity_days"
					value="<?php echo $bid && $bid['validity_days'] ? (int) $bid['validity_days'] : ''; ?>">
				<span class="tg-note">How long your price stays firm.</span>
			</p>

			<p>
				<label for="tg-notes"><strong>Notes</strong></label><br>
				<textarea id="tg-notes" name="notes" rows="4"><?php echo $bid ? esc_textarea( $bid['notes'] ) : ''; ?></textarea>
				<span class="tg-note">Anything the buyer should know - specification variations, payment terms, partial delivery.</span>
			</p>

			<p>
				<label for="tg-docs"><strong>Supporting documents</strong></label><br>
				<input type="file" id="tg-docs" name="tg_bid_docs[]" multiple>
				<span class="tg-note">Quotation, certificates or specifications. PDF, Word, Excel, JPG or PNG, up to 10 MB each.</span>
			</p>

			<p>
				<button type="submit" class="tg-btn tg-btn--accent">
					<?php echo $bid ? 'Update my bid' : 'Submit bid'; ?>
				</button>
			</p>

			<p class="tg-note">
				Your bid is sealed. No other supplier can see it at any stage, and the buyer sees all bids only
				after the tender closes.
			</p>
		</form>
		<?php
	}

	/* ------------------------------------------------------- My bids panel */

	public static function render_my_bids( $user ) {
		$bids = TG_Bid::for_supplier( $user->ID );

		echo '<section class="tg-panel"><h2>My bids</h2>';

		if ( empty( $bids ) ) {
			echo '<p>You have not bid on any tenders yet. Open a tender and use the bid form at the bottom of the page.</p></section>';

			return;
		}

		echo '<table class="tg-table" style="width:100%;border-collapse:collapse;">';
		echo '<thead><tr>'
			. '<th style="text-align:left;padding:8px 6px;">Tender</th>'
			. '<th style="text-align:left;padding:8px 6px;">Your price</th>'
			. '<th style="text-align:left;padding:8px 6px;">Submitted</th>'
			. '<th style="text-align:left;padding:8px 6px;">Status</th>'
			. '</tr></thead><tbody>';

		foreach ( $bids as $bid ) {
			$tender = TG_Tender::get( $bid['tender_id'] );

			echo '<tr style="border-top:1px solid #e3e8ee;">';
			echo '<td style="padding:8px 6px;">';

			if ( $tender ) {
				printf(
					'<a href="%s">%s</a><br><span class="tg-note">%s</span>',
					esc_url( tg_tender_url( $tender['external_id'] ) ),
					esc_html( $tender['title'] ),
					esc_html( $tender['external_id'] )
				);
			} else {
				echo '<em>Tender no longer available</em>';
			}

			echo '</td>';
			echo '<td style="padding:8px 6px;">' . esc_html( $bid['currency'] . ' ' . number_format( (float) $bid['price'], 3 ) ) . '</td>';
			echo '<td style="padding:8px 6px;">' . esc_html( $bid['submitted_at'] ? date_i18n( 'j M Y', strtotime( $bid['submitted_at'] ) ) : '-' ) . '</td>';
			echo '<td style="padding:8px 6px;">' . self::status_badge( $bid ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="tg-note" style="margin-top:10px;">Your bids stay visible here even if your subscription lapses.</p>';
		echo '</section>';
	}

	private static function status_badge( $bid ) {
		$colours = array(
			TG_Bid::SUBMITTED => '#1d7a3f',
			TG_Bid::WITHDRAWN => '#646970',
			TG_Bid::VOID      => '#8a2424',
			TG_Bid::WON       => '#6c3bb5',
			TG_Bid::LOST      => '#50575e',
		);

		$colour = isset( $colours[ $bid['status'] ] ) ? $colours[ $bid['status'] ] : '#646970';

		$html = '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . esc_attr( $colour )
			. ';color:#fff;font-size:11px;font-weight:600;">' . esc_html( TG_Bid::label( $bid['status'] ) ) . '</span>';

		if ( TG_Bid::VOID === $bid['status'] && ! empty( $bid['status_reason'] ) ) {
			$html .= '<br><span class="tg-note">' . esc_html( $bid['status_reason'] ) . '</span>';
		}

		return $html;
	}
}
