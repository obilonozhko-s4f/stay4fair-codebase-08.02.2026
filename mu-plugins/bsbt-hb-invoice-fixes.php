<?php
/**
 * Plugin Name: BSBT – MPHB Invoices Fixes (Smart Model A/B)
 * Description: Интеллектуальный расчет НДС для инвойсов. Model A = 7% на всё. Model B = 19% только на комиссию сервиса.
 * Author: BS Business Travelling
 * Version: 5.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'BS_EXT_REF_META' ) ) {
    define( 'BS_EXT_REF_META', '_bs_external_reservation_ref' );
}

/* ============================================================
 * 1) Force English
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
 * 2) DOM helpers
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

function bsbt_insert_custom_row_before_total( string $html, string $label, string $valueHtml ): string {
    if ( $html === '' || $valueHtml === '' ) return $html;
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
        $th = $dom->createElement( 'th', $label );
        $td = $dom->createElement( 'td' );
        $plain = html_entity_decode( wp_strip_all_tags( $valueHtml ), ENT_QUOTES, 'UTF-8' );
        $td->appendChild( $dom->createTextNode( $plain ) );
        $tr->appendChild( $th );
        $tr->appendChild( $td );
        $targets->item( 0 )->parentNode->insertBefore( $tr, $targets->item( 0 ) );
    }

    return bsbt_dom_body_html( $dom, $html );
}

/* ============================================================
 * 3) Meta Helpers
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

function bsbt_find_company_for_booking( int $booking_id ): string {
    $c = bsbt_meta_first_nonempty( $booking_id, array('mphb_company','_mphb_company','company','_company') );
    if ( $c !== '' ) return $c;
    $customer_id = (int) get_post_meta( $booking_id, '_mphb_customer_id', true );
    if ( $customer_id > 0 ) {
        $c = bsbt_meta_first_nonempty( $customer_id, array('mphb_company','_mphb_company','company','_company') );
        if ( $c !== '' ) return $c;
    }
    return '';
}

function bsbt_inject_company_into_customer_information( string $customerInfo, string $company ): string {
    $company = trim( $company );
    if ( $customerInfo === '' || $company === '' ) return $customerInfo;
    $info = preg_replace( '~<br\s*/?>~i', '<br/>', $customerInfo );
    $parts = array_values( array_filter( array_map( 'trim', explode( '<br/>', $info ) ) ) );
    if ( empty( $parts ) ) return '<strong>' . esc_html( $company ) . '</strong><br/>' . $customerInfo;
    array_splice( $parts, 1, 0, '<strong>' . esc_html( $company ) . '</strong>' );
    return implode( '<br/>', $parts );
}

/* ============================================================
 * 4) Main Filter
 * ============================================================ */
add_filter( 'mphb_invoices_print_pdf_variables', function( array $vars, $booking_id ) {

    $booking_id = (int) $booking_id;
    if ( $booking_id <= 0 ) return $vars;

    // --- Booking Reference
    $ext = trim( (string) get_post_meta( $booking_id, BS_EXT_REF_META, true ) );
    if ( $ext !== '' ) {
        $vars['BOOKING_ID']  = $ext;
        $vars['CPT_BOOKING'] = 'Booking Ref';
    }

    // --- Customer Company
    if ( ! empty( $vars['CUSTOMER_INFORMATION'] ) ) {
        $company = bsbt_find_company_for_booking( $booking_id );
        if ( $company !== '' ) {
            $vars['CUSTOMER_INFORMATION'] = bsbt_inject_company_into_customer_information( $vars['CUSTOMER_INFORMATION'], $company );
        }
    }

    if ( ! empty( $vars['BOOKING_DETAILS'] ) ) {

        // --- Business Model Detection ---
        $model = 'model_a'; // default
        $room_type_id = 0;

        $room_details = get_post_meta( $booking_id, 'mphb_room_details', true );
        if ( is_array( $room_details ) && ! empty( $room_details ) ) {
            $first_room = reset( $room_details );
            $room_type_id = isset( $first_room['room_type_id'] ) ? (int) $first_room['room_type_id'] : 0;
        }

        if ( ! $room_type_id && function_exists( 'MPHB' ) ) {
            try {
                $booking_obj = MPHB()->getBookingRepository()->findById( $booking_id );
                if ( $booking_obj ) {
                    $reserved_rooms = $booking_obj->getReservedRooms();
                    if ( ! empty( $reserved_rooms ) ) {
                        $first_reserved = reset( $reserved_rooms );
                        $room_type_id = $first_reserved->getRoomTypeId();
                    }
                }
            } catch ( \Throwable $e ) { }
        }

        if ( $room_type_id ) {
            $model = get_post_meta( $room_type_id, '_bsbt_business_model', true ) ?: 'model_a';
        }

        // --- Cancellation Policy Row ---
        $policyText = '';
        if ( function_exists( 'bsbt_get_cancellation_policy_type_for_booking' ) && function_exists( 'bsbt_get_cancellation_short_label' ) ) {
            $ptype = bsbt_get_cancellation_policy_type_for_booking( $booking_id, 'nonref' );
            $policyText = (string) bsbt_get_cancellation_short_label( $ptype );
        }
        if ( $policyText !== '' ) {
            $vars['BOOKING_DETAILS'] = bsbt_insert_custom_row_before_total( $vars['BOOKING_DETAILS'], 'CANCELLATION POLICY', $policyText );
        }

        // --- Smart VAT Row ---
        if ( function_exists( 'MPHB' ) && function_exists( 'mphb_format_price' ) ) {
            try {
                $booking = MPHB()->getBookingRepository()->findById( $booking_id );
                if ( $booking ) {
                    $gross = (float) $booking->getTotalPrice();
                    
                    if ( $model === 'model_b' ) {
                        $vat = round( $gross * 0.02418, 2 );
                        $label = 'incl. Service Fee VAT (19%)';
                    } else {
                        $vat = round( $gross - ( $gross / 1.07 ), 2 );
                        $label = 'VAT (7%) included';
                    }

                    if ( $vat > 0 ) {
                        $vars['BOOKING_DETAILS'] = bsbt_insert_custom_row_before_total(
                            $vars['BOOKING_DETAILS'],
                            $label,
                            (string) mphb_format_price( $vat )
                        );
                    }
                }
            } catch ( \Throwable $e ) { }
        }
    }

    return $vars;

}, 20, 2 );
