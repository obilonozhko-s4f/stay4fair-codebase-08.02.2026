<?php
/**
 * Plugin Name: BSBT – Financial Snapshot (Enterprise Ready)
 * Version: 2.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('mphb_booking_status_changed', 'bsbt_create_financial_snapshot', 10, 3);

function bsbt_create_financial_snapshot($booking, $new_status, $old_status) {
    
    if ( ! is_object($booking) || ! method_exists($booking, 'getId') ) return;
    
    $status = str_replace('mphb-', '', (string)$new_status);
    if ( $status !== 'confirmed' ) return;

    $booking_id = $booking->getId();

    if ( get_post_meta($booking_id, '_bsbt_snapshot_locked_at', true) ) return;

    $rooms = $booking->getReservedRooms();
    if ( empty($rooms) ) return;
    $room_type_id = $rooms[0]->getRoomTypeId();

    $check_in  = get_post_meta($booking_id, 'mphb_check_in_date', true);
    $check_out = get_post_meta($booking_id, 'mphb_check_out_date', true);
    
    $nights = 0;
    if ( $check_in && $check_out ) {
        $nights = max(1, (strtotime($check_out) - strtotime($check_in)) / DAY_IN_SECONDS);
    }
    
    if ( $nights <= 0 ) return;

    // PPN Fallback: Meta -> ACF
    $ppn = (float) get_post_meta($room_type_id, 'owner_price_per_night', true);
    if ( ! $ppn && function_exists('get_field') ) {
        $ppn = (float) get_field('owner_price_per_night', $room_type_id);
    }
    
    if ( $ppn <= 0 ) return;

    $fee_rate = defined('BSBT_FEE') ? (float)BSBT_FEE : 0.0; 
    $vat_rate = defined('BSBT_VAT_ON_FEE') ? (float)BSBT_VAT_ON_FEE : 0.0;
    $model    = get_post_meta($room_type_id, '_bsbt_business_model', true) ?: 'model_a';

    $owner_payout = round($ppn * $nights, 2);
    $fee_net      = round($owner_payout * $fee_rate, 2);
    $fee_vat      = round($fee_net * $vat_rate, 2);
    $fee_gross    = round($fee_net + $fee_vat, 2);

    $snapshot_data = [
        '_bsbt_snapshot_room_type_id'   => $room_type_id, // Страховка для аудита
        '_bsbt_snapshot_ppn'            => $ppn,
        '_bsbt_snapshot_nights'         => $nights,
        '_bsbt_snapshot_model'          => $model,
        '_bsbt_snapshot_owner_payout'   => $owner_payout,
        '_bsbt_snapshot_fee_rate'       => $fee_rate,
        '_bsbt_snapshot_fee_vat_rate'   => $vat_rate,
        '_bsbt_snapshot_fee_net_total'  => $fee_net,
        '_bsbt_snapshot_fee_vat_total'  => $fee_vat,
        '_bsbt_snapshot_fee_gross_total'=> $fee_gross,
        '_bsbt_snapshot_locked_at'      => current_time('mysql'),
    ];

    foreach ( $snapshot_data as $key => $value ) {
        update_post_meta($booking_id, $key, $value);
    }
}
