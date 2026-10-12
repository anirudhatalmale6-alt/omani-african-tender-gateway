<?php
/**
 * Who is told what, and when.
 *
 * Everything here listens to events the rest of the plugin already fires, so
 * no business rule depends on an email going out - a failed send is logged by
 * TG_Mailer and nothing else changes. Renewal reminders live in
 * TG_Subscription, because they need their own retry rules.
 *
 * One rule above all: an email to a supplier carries only that supplier's own
 * information. No message ever shows anyone another supplier's price.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Notify {

	public static function init() {
		add_action( 'tg_supplier_registered', array( __CLASS__, 'supplier_registered' ) );
		add_action( 'tg_supplier_status_changed', array( __CLASS__, 'supplier_status_changed' ), 10, 2 );
		add_action( 'tg_supplier_document_uploaded', array( __CLASS__, 'document_uploaded' ), 10, 2 );
		add_action( 'tg_subscription_requested', array( __CLASS__, 'plan_requested' ), 10, 3 );
		add_action( 'tg_subscription_started', array( __CLASS__, 'subscription_started' ), 10, 3 );
		add_action( 'tg_bid_submitted', array( __CLASS__, 'bid_received' ), 10, 3 );
		add_action( 'tg_bid_updated', array( __CLASS__, 'bid_received' ), 10, 3 );
		add_action( 'tg_bid_withdrawn', array( __CLASS__, 'bid_withdrawn' ), 10, 3 );
		add_action( 'tg_bids_voided', array( __CLASS__, 'bids_voided' ), 10, 2 );
		add_action( 'tg_tender_awarded', array( __CLASS__, 'tender_awarded' ), 10, 3 );
		add_action( 'tg_tender_status_changed', array( __CLASS__, 'tender_status_changed' ), 20, 5 );
	}

	/* -------------------------------------------------------------- Helpers */

	private static function send( $to, $subject, $body ) {
		$to = array_filter( (array) $to, 'is_email' );

		if ( ! $to ) {
			return false;
		}

		$site = get_bloginfo( 'name' );

		return (bool) wp_mail(
			$to,
			sprintf( '[%s] %s', $site, $subject ),
			rtrim( $body ) . "\n\n--\n" . $site . "\n" . home_url( '/' ) . "\n"
		);
	}

	private static function to_admin( $subject, $body ) {
		return self::send( TG_Mailer::admin_recipients(), $subject, $body );
	}

	private static function supplier( $user_id ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return null;
		}

		$company = get_user_meta( $user->ID, 'tg_company', true );
		$contact = get_user_meta( $user->ID, 'tg_contact', true );

		return array(
			'email'   => $user->user_email,
			'company' => $company ? $company : $user->display_name,
			'greet'   => $contact ? $contact : ( $company ? $company : $user->display_name ),
		);
	}

	private static function tender_line( $tender ) {
		return $tender ? $tender['external_id'] . ' - ' . $tender['title'] : 'the tender';
	}

	private static function money( $amount, $currency ) {
		return ( $currency ? $currency : 'OMR' ) . ' ' . number_format( (float) $amount, 3 );
	}

	/* ------------------------------------------------------------ Suppliers */

	public static function supplier_registered( $user_id ) {
		$s = self::supplier( $user_id );

		if ( ! $s ) {
			return;
		}

		self::send( $s['email'], 'Registration received', sprintf(
			"Dear %s,\n\nThank you for registering %s on %s.\n\nYour company is now awaiting verification by our team. To speed this up, please upload your Commercial Registration certificate under Company documents on your dashboard:\n%s\n\nYou can already browse tender headlines and closing dates. Bidding opens once your company is verified, and the full tender files open with a subscription.",
			$s['greet'],
			$s['company'],
			get_bloginfo( 'name' ),
			tg_page_url( 'dashboard' )
		) );

		self::to_admin( 'New supplier awaiting verification: ' . $s['company'], sprintf(
			"A new supplier has registered and is waiting for verification.\n\nCompany: %s\nContact: %s\nEmail: %s\nTelephone: %s\nCR number: %s\n\nReview and verify:\n%s",
			$s['company'],
			get_user_meta( $user_id, 'tg_contact', true ),
			$s['email'],
			get_user_meta( $user_id, 'tg_phone', true ),
			get_user_meta( $user_id, 'tg_cr', true ),
			admin_url( 'admin.php?page=' . TG_Admin_Suppliers::SLUG )
		) );
	}

	public static function supplier_status_changed( $user_id, $to ) {
		$s = self::supplier( $user_id );

		if ( ! $s ) {
			return;
		}

		if ( TG_Admin_Suppliers::VERIFIED === $to ) {
			$subject = 'Your company has been verified';
			$text    = "Good news - %s has been verified.\n\nYou can now bid on tenders in the sectors you registered for. The full tender specifications, documents and bidding need an active subscription - if you do not have one yet, you can choose a plan on your dashboard:\n%s";
		} elseif ( TG_Admin_Suppliers::REJECTED === $to ) {
			$subject = 'Your verification was not approved';
			$text    = "We were not able to verify %s from the information provided.\n\nPlease reply to this email or contact us. You can upload corrected documents on your dashboard:\n%s";
		} elseif ( TG_Admin_Suppliers::SUSPENDED === $to ) {
			$subject = 'Your account has been suspended';
			$text    = "The supplier account for %s has been suspended, so new bids cannot be placed. Bids you have already submitted are not affected.\n\nPlease contact us to resolve this. Your dashboard:\n%s";
		} else {
			return;
		}

		self::send( $s['email'], $subject, "Dear " . $s['greet'] . ",\n\n" . sprintf( $text, $s['company'], tg_page_url( 'dashboard' ) ) );
	}

	public static function document_uploaded( $doc_id, $user_id ) {
		$s   = self::supplier( $user_id );
		$doc = TG_Docs::get( $doc_id );

		if ( ! $s || ! $doc ) {
			return;
		}

		self::to_admin( 'Document uploaded by ' . $s['company'], sprintf(
			"%s has uploaded a document for verification.\n\nDocument: %s (%s)\n\nReview it on the Suppliers screen:\n%s",
			$s['company'],
			$doc['label'] ? $doc['label'] : 'Document',
			$doc['filename'],
			admin_url( 'admin.php?page=' . TG_Admin_Suppliers::SLUG )
		) );
	}

	/* -------------------------------------------------------- Subscriptions */

	public static function plan_requested( $request_id, $supplier_id, $plan ) {
		$s = self::supplier( $supplier_id );

		if ( ! $s || ! is_array( $plan ) ) {
			return;
		}

		$price = (float) $plan['price'];
		$vat   = round( $price * TG_Subscription::vat_rate() / 100, 3 );
		$how   = trim( (string) get_option( 'tg_payment_instructions', '' ) );

		self::send( $s['email'], 'Your plan request: ' . $plan['name'], sprintf(
			"Dear %s,\n\nThank you - we have received your request for the %s plan.\n\nPrice: %s\nVAT: %s\nTotal: %s\n\n%s\n\nYour access opens as soon as we have recorded your payment, and your invoice will then be on your dashboard:\n%s",
			$s['greet'],
			$plan['name'],
			self::money( $price, $plan['currency'] ),
			self::money( $vat, $plan['currency'] ),
			self::money( $price + $vat, $plan['currency'] ),
			$how ? "How to pay:\n" . $how : 'Our team will contact you shortly to arrange payment.',
			tg_page_url( 'dashboard' )
		) );

		self::to_admin( 'Plan requested by ' . $s['company'], sprintf(
			"%s has requested the %s plan (%s plus VAT).\n\nWhen their payment arrives, record it here:\n%s",
			$s['company'],
			$plan['name'],
			self::money( $price, $plan['currency'] ),
			admin_url( 'admin.php?page=' . TG_Admin_Subscriptions::SLUG . '&tab=renewals' )
		) );
	}

	public static function subscription_started( $sub_id, $supplier_id, $plan ) {
		$s   = self::supplier( $supplier_id );
		$sub = TG_Subscription::get( $sub_id );

		if ( ! $s || ! $sub ) {
			return;
		}

		self::send( $s['email'], 'Your subscription is active - invoice ' . $sub['invoice_no'], sprintf(
			"Dear %s,\n\nThank you for your payment. Your %s subscription is now active until %s.\n\nYou now have full access to tender specifications and documents, and can bid on open tenders in your sectors.\n\nInvoice: %s\nTotal: %s (including VAT %s)\n\nView or print your invoice:\n%s",
			$s['greet'],
			$sub['plan_name'],
			date_i18n( 'j F Y', strtotime( $sub['ends_at'] ) ),
			$sub['invoice_no'],
			self::money( $sub['total'], $sub['currency'] ),
			self::money( $sub['vat_amount'], $sub['currency'] ),
			TG_Subscription_UI::invoice_url( $sub_id )
		) );
	}

	/* ----------------------------------------------------------------- Bids */

	public static function bid_received( $bid_id, $tender_id, $supplier_id ) {
		$s      = self::supplier( $supplier_id );
		$bid    = TG_Bid::get( $bid_id );
		$tender = TG_Tender::get( $tender_id );

		if ( ! $s || ! $bid || ! $tender ) {
			return;
		}

		self::send( $s['email'], 'Bid received: ' . $tender['external_id'], sprintf(
			"Dear %s,\n\nWe have received your bid.\n\nTender: %s\nYour price: %s%s%s\nTender closes: %s\n\nYou can change or withdraw your bid until the tender closes:\n%s\n\nYour bid is sealed - no other supplier can see it.",
			$s['greet'],
			self::tender_line( $tender ),
			self::money( $bid['price'], $bid['currency'] ),
			$bid['delivery_days'] ? "\nDelivery: " . (int) $bid['delivery_days'] . ' days' : '',
			$bid['validity_days'] ? "\nValid for: " . (int) $bid['validity_days'] . ' days' : '',
			$tender['closing_at'] ? date_i18n( 'j F Y, H:i', strtotime( $tender['closing_at'] ) ) : 'see the tender page',
			tg_tender_url( $tender['external_id'] )
		) );
	}

	public static function bid_withdrawn( $bid_id, $tender_id, $supplier_id ) {
		$s      = self::supplier( $supplier_id );
		$tender = TG_Tender::get( $tender_id );

		if ( ! $s || ! $tender ) {
			return;
		}

		self::send( $s['email'], 'Bid withdrawn: ' . $tender['external_id'], sprintf(
			"Dear %s,\n\nYour bid on %s has been withdrawn.\n\nIf this was a mistake, you can submit a new bid while the tender is still open:\n%s",
			$s['greet'],
			self::tender_line( $tender ),
			tg_tender_url( $tender['external_id'] )
		) );
	}

	public static function bids_voided( $tender_id, $reason ) {
		$tender = TG_Tender::get( $tender_id );

		if ( ! $tender ) {
			return;
		}

		foreach ( TG_Bid::for_tender( $tender_id, true ) as $bid ) {
			if ( TG_Bid::VOID !== $bid['status'] ) {
				continue;
			}

			$s = self::supplier( $bid['supplier_id'] );

			if ( ! $s ) {
				continue;
			}

			self::send( $s['email'], 'Tender cancelled: ' . $tender['external_id'], sprintf(
				"Dear %s,\n\nThe tender %s has been cancelled, so your bid on it no longer stands.%s\n\nThank you for taking part. You can find other open tenders here:\n%s",
				$s['greet'],
				self::tender_line( $tender ),
				'' !== trim( (string) $reason ) ? "\n\nReason given: " . trim( (string) $reason ) : '',
				tg_page_url( 'tenders' )
			) );
		}
	}

	public static function tender_awarded( $tender_id, $bid_id, $winner_id ) {
		$tender = TG_Tender::get( $tender_id );

		if ( ! $tender ) {
			return;
		}

		foreach ( TG_Bid::for_tender( $tender_id, true ) as $bid ) {
			$s = self::supplier( $bid['supplier_id'] );

			if ( ! $s ) {
				continue;
			}

			if ( (int) $bid['id'] === (int) $bid_id ) {
				self::send( $s['email'], 'Your bid was successful: ' . $tender['external_id'], sprintf(
					"Dear %s,\n\nCongratulations - your bid on %s has been selected.\n\nAwarded value: %s\n\nOur team will be in touch with the next steps. You can see the result on your dashboard:\n%s",
					$s['greet'],
					self::tender_line( $tender ),
					self::money( $bid['price'], $bid['currency'] ),
					tg_page_url( 'dashboard' )
				) );
			} elseif ( TG_Bid::LOST === $bid['status'] ) {
				// The winner's name and price are deliberately not included.
				self::send( $s['email'], 'Tender result: ' . $tender['external_id'], sprintf(
					"Dear %s,\n\nThank you for bidding on %s. On this occasion your bid was not selected.\n\nNew tenders in your sectors are listed here:\n%s",
					$s['greet'],
					self::tender_line( $tender ),
					tg_page_url( 'tenders' )
				) );
			}
		}
	}

	/* --------------------------------------------------------------- Admin */

	public static function tender_status_changed( $tender_id, $from, $to, $actor_id, $reason ) {
		if ( TG_Status::CLOSED !== $to ) {
			return;
		}

		$tender = TG_Tender::get( $tender_id );

		if ( ! $tender ) {
			return;
		}

		$count = TG_Bid::count_for_tender( $tender_id );

		self::to_admin( sprintf( 'Tender closed: %s (%d bid%s)', $tender['external_id'], $count, 1 === $count ? '' : 's' ), sprintf(
			"%s has closed with %d bid%s.\n\n%s\n%s",
			self::tender_line( $tender ),
			$count,
			1 === $count ? '' : 's',
			$count ? 'The bids are now open for comparison and award:' : 'No bids were received. You can review the tender here:',
			admin_url( 'admin.php?page=' . TG_Admin_Tenders::EDIT_SLUG . '&tender=' . (int) $tender_id )
		) );
	}
}
