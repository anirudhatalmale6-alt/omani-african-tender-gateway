<?php
/**
 * Admin screens for tenders the platform owns: the list, the editor, the
 * status actions and the overview counters.
 *
 * Everything that changes a status goes through TG_Tender::move(), so the
 * buttons offered here are generated from TG_Status::can_move() rather than
 * hard-coded. An action that is not legal at this point in the lifecycle is
 * never rendered, and would be refused anyway if it were posted directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Admin_Tenders {

	const LIST_SLUG = 'tg-tenders';
	const EDIT_SLUG = 'tg-tender-edit';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_post_tg_tender_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_tg_tender_move', array( __CLASS__, 'handle_move' ) );
		add_action( 'admin_post_tg_tender_doc_delete', array( __CLASS__, 'handle_doc_delete' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
	}

	public static function menu() {
		add_submenu_page(
			'tender-gateway',
			'Tenders',
			'Tenders',
			'manage_options',
			self::LIST_SLUG,
			array( __CLASS__, 'list_screen' )
		);

		add_submenu_page(
			'tender-gateway',
			'Add Tender',
			'Add Tender',
			'manage_options',
			self::EDIT_SLUG,
			array( __CLASS__, 'edit_screen' )
		);
	}

	/* ---------------------------------------------------------------- URLs */

	public static function list_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::LIST_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function edit_url( $id = 0 ) {
		$args = array( 'page' => self::EDIT_SLUG );

		if ( $id ) {
			$args['tender'] = (int) $id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------ Overview */

	/**
	 * The basic counters. Three grouped queries rather than one per number.
	 */
	public static function overview() {
		global $wpdb;

		$by_status = TG_Tender::counts_by_status();

		$suppliers = (array) count_users();
		$total_sup = isset( $suppliers['avail_roles'][ TG_Auth::ROLE ] ) ? (int) $suppliers['avail_roles'][ TG_Auth::ROLE ] : 0;

		$verified = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
			'tg_status',
			'verified'
		) );

		return array(
			'suppliers'           => $total_sup,
			'suppliers_verified'  => min( $verified, $total_sup ),
			'suppliers_unverified'=> max( 0, $total_sup - $verified ),
			'tenders'             => array_sum( $by_status ),
			'by_status'           => $by_status,
			'closing_7_days'      => TG_Tender::closing_within( 7 ),
		);
	}

	public static function dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget( 'tg_overview', 'Jumla Tender - overview', array( __CLASS__, 'render_overview' ) );
	}

	/**
	 * Every number links to the matching filtered list, so the panel is a
	 * starting point rather than decoration.
	 */
	public static function render_overview() {
		$o = self::overview();

		$tiles = array(
			array( 'Suppliers', $o['suppliers'], '' ),
			array( 'Unverified suppliers', $o['suppliers_unverified'], '' ),
			array( 'Tenders', $o['tenders'], self::list_url() ),
			array( 'Awaiting approval', $o['by_status'][ TG_Status::PENDING ], self::list_url( array( 'status' => TG_Status::PENDING ) ) ),
			array( 'Open for bidding', $o['by_status'][ TG_Status::OPEN ], self::list_url( array( 'status' => TG_Status::OPEN ) ) ),
			array( 'Closed', $o['by_status'][ TG_Status::CLOSED ], self::list_url( array( 'status' => TG_Status::CLOSED ) ) ),
			array( 'Awarded', $o['by_status'][ TG_Status::AWARDED ], self::list_url( array( 'status' => TG_Status::AWARDED ) ) ),
			array( 'Closing within 7 days', $o['closing_7_days'], self::list_url( array( 'status' => TG_Status::OPEN ) ) ),
		);

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;">';

		foreach ( $tiles as $tile ) {
			list( $label, $value, $url ) = $tile;

			$inner = '<div style="font-size:22px;font-weight:600;line-height:1.1;">' . esc_html( number_format_i18n( $value ) ) . '</div>'
				. '<div style="font-size:12px;color:#50575e;">' . esc_html( $label ) . '</div>';

			echo '<div style="border:1px solid #dcdcde;border-radius:4px;padding:10px;background:#fff;">';

			if ( $url ) {
				echo '<a href="' . esc_url( $url ) . '" style="text-decoration:none;color:inherit;display:block;">' . $inner . '</a>';
			} else {
				echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------- List screen */

	public static function list_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		if ( $status && ! TG_Status::exists( $status ) ) {
			$status = '';
		}

		$result = TG_Tender::query( array(
			'status'   => $status,
			'search'   => $search,
			'page'     => $paged,
			'per_page' => 20,
		) );

		$counts = TG_Tender::counts_by_status();
		$total  = array_sum( $counts );

		echo '<div class="wrap"><h1 class="wp-heading-inline">Tenders</h1> ';
		echo '<a href="' . esc_url( self::edit_url() ) . '" class="page-title-action">Add Tender</a>';

		self::notice();

		echo '<hr class="wp-header-end">';

		echo '<div style="margin:12px 0 16px;">';
		self::render_overview();
		echo '</div>';

		// Status tabs.
		echo '<ul class="subsubsub">';
		$links   = array();
		$links[] = '<li><a href="' . esc_url( self::list_url() ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>All <span class="count">(' . (int) $total . ')</span></a></li>';

		foreach ( TG_Status::active() as $key => $label ) {
			$links[] = '<li><a href="' . esc_url( self::list_url( array( 'status' => $key ) ) ) . '"'
				. ( $status === $key ? ' class="current"' : '' ) . '>'
				. esc_html( $label ) . ' <span class="count">(' . (int) $counts[ $key ] . ')</span></a></li>';
		}

		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
		echo '</ul>';

		echo '<form method="get" style="float:right;margin:0 0 8px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::LIST_SLUG ) . '">';

		if ( $status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		}

		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search tenders">';
		echo '<button class="button">Search</button></form>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th style="width:130px;">Reference</th><th>Title</th><th style="width:150px;">Buyer</th>';
		echo '<th style="width:140px;">Status</th><th style="width:150px;">Closes</th><th style="width:90px;">Bids</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $result['rows'] ) ) {
			echo '<tr><td colspan="6">No tenders yet. <a href="' . esc_url( self::edit_url() ) . '">Create the first one</a>.</td></tr>';
		}

		foreach ( $result['rows'] as $row ) {
			echo '<tr>';
			echo '<td><a href="' . esc_url( self::edit_url( $row['id'] ) ) . '"><strong>' . esc_html( $row['external_id'] ) . '</strong></a></td>';
			echo '<td><a href="' . esc_url( self::edit_url( $row['id'] ) ) . '">' . esc_html( $row['title'] ) . '</a></td>';
			echo '<td>' . esc_html( $row['buyer'] ) . '</td>';
			echo '<td>' . self::status_badge( $row['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( self::datetime( $row['closing_at'] ) ) . '</td>';
			echo '<td>&mdash;</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $result['total'] / 20 );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput
				'base'    => esc_url_raw( add_query_arg( 'paged', '%#%', self::list_url( array( 'status' => $status, 's' => $search ) ) ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) );
			echo '</div></div>';
		}

		echo '<p class="description" style="margin-top:14px;">The Bids column fills in with M2, when supplier bidding is built.</p>';
		echo '</div>';
	}

	/* ---------------------------------------------------------- Edit screen */

	public static function edit_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$id     = isset( $_GET['tender'] ) ? (int) $_GET['tender'] : 0;
		$tender = $id ? TG_Tender::get( $id ) : null;

		if ( $id && ! $tender ) {
			echo '<div class="wrap"><h1>Tender</h1><div class="notice notice-error"><p>That tender does not exist.</p></div></div>';

			return;
		}

		$v = function ( $key, $default = '' ) use ( $tender ) {
			return $tender && isset( $tender[ $key ] ) && null !== $tender[ $key ] ? $tender[ $key ] : $default;
		};

		echo '<div class="wrap">';
		echo '<h1>' . ( $tender ? 'Tender ' . esc_html( $tender['external_id'] ) : 'Add Tender' ) . '</h1>';

		self::notice();

		if ( $tender ) {
			echo '<p>Status: ' . self::status_badge( $tender['status'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

			if ( ! empty( $tender['status_reason'] ) ) {
				echo '<p class="description">Last reason given: ' . esc_html( $tender['status_reason'] ) . '</p>';
			}
		}

		$locked = $tender && TG_Status::is_terminal( $tender['status'] );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		wp_nonce_field( 'tg_tender_save' );
		echo '<input type="hidden" name="action" value="tg_tender_save">';
		echo '<input type="hidden" name="tender" value="' . esc_attr( (string) $id ) . '">';

		echo '<table class="form-table" role="presentation"><tbody>';

		self::field( 'Title', '<input name="title" class="regular-text" required value="' . esc_attr( $v( 'title' ) ) . '">', 'What is being bought, in a few words.' );
		self::field( 'Buyer / tender owner', '<input name="buyer" class="regular-text" value="' . esc_attr( $v( 'buyer' ) ) . '">', 'The company the requirement came from.' );

		self::field(
			'Quantity',
			'<input name="quantity" class="small-text" value="' . esc_attr( $v( 'quantity' ) ) . '"> '
			. '<input name="unit" class="small-text" placeholder="kg, tonnes, units" value="' . esc_attr( $v( 'unit' ) ) . '">'
		);

		self::field( 'Category / sector', '<input name="sector" class="regular-text" value="' . esc_attr( $v( 'sector' ) ) . '">' );
		self::field( 'Country', '<input name="country" class="regular-text" value="' . esc_attr( $v( 'country', 'Oman' ) ) . '">' );
		self::field( 'Delivery location', '<input name="delivery_location" class="regular-text" value="' . esc_attr( $v( 'delivery_location' ) ) . '">' );

		self::field(
			'Estimated value',
			'<input name="value" type="number" step="0.001" class="small-text" value="' . esc_attr( $v( 'value', '0' ) ) . '"> '
			. '<input name="currency" class="small-text" placeholder="OMR" value="' . esc_attr( $v( 'currency', 'OMR' ) ) . '">',
			'Optional. Leave at zero if the value is not disclosed.'
		);

		self::field( 'Summary', '<textarea name="summary" rows="3" class="large-text">' . esc_textarea( $v( 'summary' ) ) . '</textarea>', 'Shown on the tender card and to suppliers who are not subscribed.' );
		self::field( 'Specifications', '<textarea name="specifications" rows="6" class="large-text">' . esc_textarea( $v( 'specifications' ) ) . '</textarea>', 'Full requirement. Visible to subscribed suppliers only.' );
		self::field( 'Additional detail', '<textarea name="description" rows="4" class="large-text">' . esc_textarea( $v( 'description' ) ) . '</textarea>' );

		self::field(
			'Opening date and time',
			'<input name="opening_at" type="datetime-local" value="' . esc_attr( self::local_input( $v( 'opening_at' ) ) ) . '">',
			'When the tender opens for bidding. Leave empty to open as soon as it is approved.'
		);

		self::field(
			'Closing date and time',
			'<input name="closing_at" type="datetime-local" value="' . esc_attr( self::local_input( $v( 'closing_at' ) ) ) . '">',
			'No bid can be submitted or changed after this moment. Enforced on the server, not just hidden in the page.'
		);

		self::field( 'Attach documents', '<input type="file" name="tg_docs[]" multiple>', 'PDF, Word, Excel, JPG or PNG. Up to 10 MB each. Stored privately and served only to people entitled to see them.' );

		echo '</tbody></table>';

		if ( ! $locked ) {
			submit_button( $tender ? 'Save changes' : 'Create tender' );
		} else {
			echo '<p><em>This tender is ' . esc_html( strtolower( TG_Status::label( $tender['status'] ) ) ) . ' and can no longer be edited.</em></p>';
		}

		echo '</form>';

		if ( $tender ) {
			self::render_documents( $tender );
			self::render_actions( $tender );
			self::render_history( $tender );
		}

		echo '</div>';
	}

	private static function render_documents( $tender ) {
		$docs = TG_Docs::for_owner( 'tender', $tender['id'] );

		echo '<h2>Documents</h2>';

		if ( empty( $docs ) ) {
			echo '<p class="description">No documents attached.</p>';

			return;
		}

		echo '<table class="wp-list-table widefat striped" style="max-width:760px;"><tbody>';

		foreach ( $docs as $doc ) {
			echo '<tr><td><a href="' . esc_url( TG_Docs::url( $doc ) ) . '">' . esc_html( $doc['filename'] ) . '</a></td>';
			echo '<td style="width:110px;">' . esc_html( size_format( (int) $doc['filesize'] ) ) . '</td>';
			echo '<td style="width:90px;"><a class="submitdelete" href="' . esc_url( wp_nonce_url( add_query_arg( array(
				'action' => 'tg_tender_doc_delete',
				'doc'    => (int) $doc['id'],
			), admin_url( 'admin-post.php' ) ), 'tg_doc_delete_' . (int) $doc['id'] ) ) . '">Delete</a></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Buttons are derived from the transition rules, so this screen can never
	 * offer a move the lifecycle forbids.
	 */
	private static function render_actions( $tender ) {
		$targets = array();

		foreach ( array_keys( TG_Status::active() ) as $candidate ) {
			if ( true === TG_Status::can_move( $tender['status'], $candidate ) ) {
				$targets[] = $candidate;
			}
		}

		echo '<h2>Move this tender on</h2>';

		if ( empty( $targets ) ) {
			echo '<p class="description">No further moves are possible from ' . esc_html( TG_Status::label( $tender['status'] ) ) . '.</p>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tg_tender_move' );
		echo '<input type="hidden" name="action" value="tg_tender_move">';
		echo '<input type="hidden" name="tender" value="' . esc_attr( (string) $tender['id'] ) . '">';

		echo '<p><label for="tg_to">New status</label><br><select name="to" id="tg_to">';

		foreach ( $targets as $target ) {
			echo '<option value="' . esc_attr( $target ) . '">' . esc_html( TG_Status::label( $target ) ) . '</option>';
		}

		echo '</select></p>';
		echo '<p><label for="tg_reason">Reason</label><br>';
		echo '<textarea name="reason" id="tg_reason" rows="2" class="large-text" style="max-width:640px;"></textarea>';
		echo '<span class="description">Required when rejecting or cancelling. Recorded against the tender permanently.</span></p>';

		submit_button( 'Apply', 'secondary' );
		echo '</form>';
	}

	private static function render_history( $tender ) {
		$events = TG_Tender::events( $tender['id'] );

		echo '<h2>History</h2><table class="wp-list-table widefat striped" style="max-width:900px;"><thead><tr>';
		echo '<th style="width:160px;">When</th><th style="width:150px;">Who</th><th style="width:220px;">Change</th><th>Reason</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			$who = $event['actor_id'] ? get_userdata( (int) $event['actor_id'] ) : null;

			echo '<tr><td>' . esc_html( self::datetime( $event['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $who ? $who->display_name : 'System' ) . '</td>';
			echo '<td>' . esc_html(
				( $event['from_status'] ? TG_Status::label( $event['from_status'] ) : 'Created' )
				. " \u{2192} " . TG_Status::label( $event['to_status'] )
			) . '</td>';
			echo '<td>' . esc_html( (string) $event['reason'] ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/* -------------------------------------------------------------- Handlers */

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		check_admin_referer( 'tg_tender_save' );

		$id   = isset( $_POST['tender'] ) ? (int) $_POST['tender'] : 0;
		$post = wp_unslash( $_POST );

		$data = array();

		foreach ( TG_Tender::editable_fields() as $field ) {
			$data[ $field ] = isset( $post[ $field ] ) ? $post[ $field ] : '';
		}

		if ( $id ) {
			$result = TG_Tender::update( $id, $data );
		} else {
			$result = TG_Tender::create( $data, get_current_user_id() );

			if ( ! is_wp_error( $result ) ) {
				$id = (int) $result;
			}
		}

		if ( is_wp_error( $result ) ) {
			self::redirect( self::edit_url( $id ), 'error', $result->get_error_message() );
		}

		$uploaded = self::handle_uploads( $id );

		self::redirect(
			self::edit_url( $id ),
			'success',
			$uploaded ? sprintf( 'Tender saved, %d document(s) attached.', $uploaded ) : 'Tender saved.'
		);
	}

	private static function handle_uploads( $tender_id ) {
		if ( empty( $_FILES['tg_docs'] ) || ! is_array( $_FILES['tg_docs']['name'] ) ) {
			return 0;
		}

		$count = 0;

		foreach ( array_keys( $_FILES['tg_docs']['name'] ) as $i ) {
			if ( '' === $_FILES['tg_docs']['name'][ $i ] ) {
				continue;
			}

			$file = array(
				'name'     => $_FILES['tg_docs']['name'][ $i ],
				'type'     => $_FILES['tg_docs']['type'][ $i ],
				'tmp_name' => $_FILES['tg_docs']['tmp_name'][ $i ],
				'error'    => $_FILES['tg_docs']['error'][ $i ],
				'size'     => $_FILES['tg_docs']['size'][ $i ],
			);

			$stored = TG_Docs::store( $file, 'tender', $tender_id, '', get_current_user_id() );

			if ( ! is_wp_error( $stored ) ) {
				$count++;
			}
		}

		return $count;
	}

	public static function handle_move() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		check_admin_referer( 'tg_tender_move' );

		$id     = isset( $_POST['tender'] ) ? (int) $_POST['tender'] : 0;
		$to     = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		$result = TG_Tender::move( $id, $to, get_current_user_id(), $reason );

		if ( is_wp_error( $result ) ) {
			self::redirect( self::edit_url( $id ), 'error', $result->get_error_message() );
		}

		// Approving a tender with no scheduled opening time opens it at once -
		// otherwise it would sit invisible waiting for a scheduler that has
		// nothing to wait for.
		$tender = TG_Tender::get( $id );

		if ( TG_Status::APPROVED === $to && $tender && empty( $tender['opening_at'] ) ) {
			TG_Tender::move( $id, TG_Status::OPEN, get_current_user_id(), 'Opened on approval - no scheduled opening time set.' );

			self::redirect( self::edit_url( $id ), 'success', 'Tender approved and opened for bidding.' );
		}

		self::redirect( self::edit_url( $id ), 'success', 'Tender moved to ' . TG_Status::label( $to ) . '.' );
	}

	public static function handle_doc_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$doc_id = isset( $_GET['doc'] ) ? (int) $_GET['doc'] : 0;

		check_admin_referer( 'tg_doc_delete_' . $doc_id );

		$doc    = TG_Docs::get( $doc_id );
		$tender = $doc ? (int) $doc['owner_id'] : 0;

		TG_Docs::delete( $doc_id );

		self::redirect( self::edit_url( $tender ), 'success', 'Document deleted.' );
	}

	/* --------------------------------------------------------------- Helpers */

	private static function redirect( $url, $type, $message ) {
		set_transient( 'tg_admin_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );

		wp_safe_redirect( $url );
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

	private static function field( $label, $control, $description = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput -- callers escape their values.

		if ( $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		echo '</td></tr>';
	}

	private static function status_badge( $status ) {
		$colours = array(
			TG_Status::DRAFT     => '#646970',
			TG_Status::PENDING   => '#b26200',
			TG_Status::APPROVED  => '#2271b1',
			TG_Status::OPEN      => '#1d7a3f',
			TG_Status::CLOSED    => '#50575e',
			TG_Status::AWARDED   => '#6c3bb5',
			TG_Status::CANCELLED => '#8a2424',
			TG_Status::REJECTED  => '#8a2424',
		);

		$colour = isset( $colours[ $status ] ) ? $colours[ $status ] : '#646970';

		return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . esc_attr( $colour )
			. ';color:#fff;font-size:11px;font-weight:600;">' . esc_html( TG_Status::label( $status ) ) . '</span>';
	}

	private static function datetime( $value ) {
		if ( empty( $value ) ) {
			return 'Not set';
		}

		$time = strtotime( $value );

		return $time ? date_i18n( 'j M Y, H:i', $time ) : (string) $value;
	}

	private static function local_input( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		$time = strtotime( $value );

		return $time ? gmdate( 'Y-m-d\TH:i', $time ) : '';
	}
}
