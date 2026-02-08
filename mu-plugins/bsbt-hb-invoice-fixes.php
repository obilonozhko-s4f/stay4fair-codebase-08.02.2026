<?php
/**
 * Plugin Name: BSBT – MPHB Invoices Fixes (Company + VAT + Policy + ExtRef) SAFE
 * Description: For plugin "mphb-invoices": inject customer company under name in CUSTOMER_INFORMATION, keep ext ref, VAT row, cancellation policy row, force EN locale. Safe guards against fatals.
 * Author: BS Business Travelling
 * Version: 4.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'BS_EXT_REF_META' ) ) {
	define( 'BS_EXT_REF_META', '_bs_external_reservation_ref' );
}

/* ============================================================
 * 1) Force English while rendering PDF
 * ============================================================ */
add_action( 'mphb_invoices_print_pdf_before', function( $booking_id ) {
	if ( function_exists( 'switch_to_locale' ) ) {
		switch_to_locale( 'en_US' );
	}
}, 1 );

add_action( 'mphb_invoices_print_pdf_after', function( $booking_id ) {
	if ( function_exists( 'restore_previous_locale' ) ) {
		restore_previous_locale();
	}
}, 99 );

/* ============================================================
 * 2) DOM helpers (safe)
 * ============================================================ */
function bsbt_dom_supported(): bool {
	return class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' );
}

function bsbt_dom_load_html( DOMDocument $dom, string $html ): void {
	libxml_use_internal_errors( true );
	@$dom->loadHTML(
		'<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
}

function bsbt_dom_body_html( DOMDocument $dom, string $fallback ): string {
	$out = $dom->saveHTML();
	$out = preg_replace( '~^.*?<body>(.*)</body>.*$~is', '$1', (string) $out );
	libxml_clear_errors();
	return $out ?: $fallback;
}

function bsbt_insert_vat_before_total_dom( string $html, string $vatHtml ): string {
	if ( $html === '' || $vatHtml === '' ) return $html;
	if ( ! bsbt_dom_supported() ) return $html;

	$dom = new DOMDocument( '1.0', 'UTF-8' );
	bsbt_dom_load_html( $dom, $html );
	$xpath = new DOMXPath( $dom );

	$targets = $xpath->query(
		"//tr[th and (translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='TOTAL'
		or translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='GESAMT')]"
	);

	if ( $targets && $targets->length > 0 ) {
		$tr = $dom->createElement( 'tr' );
		$tr->setAttribute( 'class', 'bsbt-vat-row' );

		$th = $dom->createElement( 'th', 'VAT (7%) included' );
		$td = $dom->createElement( 'td' );

		$plain = html_entity_decode( wp_strip_all_tags( $vatHtml ), ENT_QUOTES, 'UTF-8' );
		$td->appendChild( $dom->createTextNode( $plain ) );

		$tr->appendChild( $th );
		$tr->appendChild( $td );

		$targets->item( 0 )->parentNode->insertBefore( $tr, $targets->item( 0 ) );
	}

	return bsbt_dom_body_html( $dom, $html );
}

function bsbt_insert_cancel_policy_row_dom( string $html, string $policyText ): string {
	if ( $html === '' || $policyText === '' ) return $html;
	if ( ! bsbt_dom_supported() ) return $html;

	$dom = new DOMDocument( '1.0', 'UTF-8' );
	bsbt_dom_load_html( $dom, $html );
	$xpath = new DOMXPath( $dom );

	$targets = $xpath->query(
		"//tr[th and (translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='TOTAL'
		or translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='GESAMT')]"
	);

	if ( $targets && $targets->length > 0 ) {
		$tr = $dom->createElement( 'tr' );
		$tr->setAttribute( 'class', 'bsbt-cancel-policy-row' );

		$th = $dom->createElement( 'th', 'CANCELLATION POLICY' );
		$td = $dom->createElement( 'td' );
		$td->appendChild( $dom->createTextNode( $policyText ) );

		$tr->appendChild( $th );
		$tr->appendChild( $td );

		$targets->item( 0 )->parentNode->insertBefore( $tr, $targets->item( 0 ) );
	}

	return bsbt_dom_body_html( $dom, $html );
}

/* ============================================================
 * 3) Company finder (no MPHB Customer methods!)
 * ============================================================ */
function bsbt_meta_first_nonempty( int $post_id, array $keys ): string {
	foreach ( $keys as $k ) {
		$v = get_post_meta( $post_id, $k, true );
		if ( is_scalar( $v ) ) {
			$s = trim( (string) $v );
			if ( $s !== '' ) return $s;
		}
	}
	return '';
}

function bsbt_meta_pick_like( int $post_id, array $needles ): string {
	$all = get_post_meta( $post_id );
	if ( empty( $all ) || ! is_array( $all ) ) return '';

	foreach ( $all as $k => $vals ) {
		$lk = strtolower( (string) $k );
		$ok = false;
		foreach ( $needles as $n ) {
			if ( strpos( $lk, $n ) !== false ) { $ok = true; break; }
		}
		if ( ! $ok ) continue;

		$v = is_array( $vals ) ? reset( $vals ) : $vals;
		if ( is_scalar( $v ) ) {
			$s = trim( (string) $v );
			if ( $s !== '' ) return $s;
		}
	}
	return '';
}

function bsbt_find_company_for_booking( int $booking_id ): string {

	// A) direct booking meta (sometimes custom fields land here)
	$c = bsbt_meta_first_nonempty( $booking_id, array(
		'mphb_company',
		'_mphb_company',
		'company',
		'_company',
	) );
	if ( $c !== '' ) return $c;

	$c = bsbt_meta_pick_like( $booking_id, array( 'company', 'firma', 'unternehmen' ) );
	if ( $c !== '' ) return $c;

	// B) customer id stored in booking meta by MPHB
	$customer_id = (int) get_post_meta( $booking_id, '_mphb_customer_id', true );
	if ( $customer_id > 0 ) {

		// common direct keys
		$c = bsbt_meta_first_nonempty( $customer_id, array(
			'mphb_company',
			'_mphb_company',
			'company',
			'_company',
		) );
		if ( $c !== '' ) return $c;

		// serialized customer meta (your case sometimes)
		$customer_meta = get_post_meta( $customer_id, '_mphb_customer_meta', true );
		if ( is_array( $customer_meta ) ) {
			// try common keys inside array
			foreach ( array( 'mphb_company', 'company', '_billing_company', 'billing_company' ) as $k ) {
				if ( isset( $customer_meta[ $k ] ) && is_scalar( $customer_meta[ $k ] ) ) {
					$s = trim( (string) $customer_meta[ $k ] );
					if ( $s !== '' ) return $s;
				}
			}
		}

		// any customer meta key "like company"
		$c = bsbt_meta_pick_like( $customer_id, array( 'company', 'firma', 'unternehmen' ) );
		if ( $c !== '' ) return $c;
	}

	return '';
}

/* ============================================================
 * 4) Inject company into CUSTOMER_INFORMATION (right under name)
 * ============================================================ */
function bsbt_inject_company_into_customer_information( string $customerInfo, string $company ): string {
	$customerInfo = (string) $customerInfo;
	$company      = trim( (string) $company );

	if ( $customerInfo === '' || $company === '' ) return $customerInfo;
	if ( stripos( $customerInfo, $company ) !== false ) return $customerInfo;

	$info  = preg_replace( '~<br\s*/?>~i', '<br/>', $customerInfo );
	$parts = array_values( array_filter( array_map( 'trim', explode( '<br/>', $info ) ), function( $x ) {
		return $x !== '';
	} ) );

	// If structure is unexpected, just prepend nicely
	if ( empty( $parts ) ) {
		return '<strong>' . esc_html( $company ) . '</strong><br/>' . $customerInfo;
	}

	// Insert after first line (usually "<strong>Name</strong>")
	array_splice( $parts, 1, 0, '<strong>' . esc_html( $company ) . '</strong>' );

	return implode( '<br/>', $parts );
}

/* ============================================================
 * 5) Main filter
 * ============================================================ */
add_filter( 'mphb_invoices_print_pdf_variables', function( array $vars, $booking_id ) {

	$booking_id = (int) $booking_id;
	if ( $booking_id <= 0 ) return $vars;

	// --- Booking Ref instead of internal Booking ID
	$ext = trim( (string) get_post_meta( $booking_id, BS_EXT_REF_META, true ) );
	if ( $ext !== '' ) {
		$vars['BOOKING_ID']  = $ext;
		$vars['CPT_BOOKING'] = 'Booking Ref';
	}

	// --- Company under customer name (template uses CUSTOMER_INFORMATION)
	if ( ! empty( $vars['CUSTOMER_INFORMATION'] ) && is_string( $vars['CUSTOMER_INFORMATION'] ) ) {
		$company = bsbt_find_company_for_booking( $booking_id );
		if ( $company !== '' ) {
			$vars['CUSTOMER_INFORMATION'] = bsbt_inject_company_into_customer_information(
				(string) $vars['CUSTOMER_INFORMATION'],
				(string) $company
			);
		}
	}

	// --- Policy + VAT (only if BOOKING_DETAILS exists)
	if ( ! empty( $vars['BOOKING_DETAILS'] ) && is_string( $vars['BOOKING_DETAILS'] ) ) {

		// Cancellation policy row
		$policyText = '';
		if ( function_exists( 'bsbt_get_cancellation_policy_type_for_booking' ) && function_exists( 'bsbt_get_cancellation_short_label' ) ) {
			$ptype = bsbt_get_cancellation_policy_type_for_booking( $booking_id, 'nonref' );
			$policyText = (string) bsbt_get_cancellation_short_label( $ptype );
		}
		if ( $policyText !== '' ) {
			$vars['BOOKING_DETAILS'] = bsbt_insert_cancel_policy_row_dom(
				(string) $vars['BOOKING_DETAILS'],
				$policyText
			);
		}

		// VAT row (requires MPHB + formatter)
		if ( function_exists( 'MPHB' ) && function_exists( 'mphb_format_price' ) ) {
			try {
				$booking = MPHB()->getBookingRepository()->findById( $booking_id );
				if ( $booking ) {
					$gross = (float) $booking->getTotalPrice();
					if ( $gross > 0 ) {
						$vat = round( $gross - ( $gross / 1.07 ), 2 );
						if ( $vat > 0 ) {
							$vars['BOOKING_DETAILS'] = bsbt_insert_vat_before_total_dom(
								(string) $vars['BOOKING_DETAILS'],
								(string) mphb_format_price( $vat )
							);
						}
					}
				}
			} catch ( \Throwable $e ) {
				// swallow any runtime issues (never fatal in admin)
			}
		}
	}

	return $vars;

}, 20, 2 );
