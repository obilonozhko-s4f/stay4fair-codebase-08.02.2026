<?php
/**
 * Core логика принятия решений владельцем.
 * Version V10.22 - Production Safe Hybrid
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BSBT_Owner_Decision_Core {

    const META_DECISION = '_bsbt_owner_decision';
    const META_BSBT_REF = '_bsbt_booking_id';

    /**
     * APPROVE FLOW (Capture + Strict Accounting)
     */
    public static function approve_and_send_payment(int $booking_id): array {

        if ($booking_id <= 0) {
            return ['ok' => false, 'message' => 'Invalid ID'];
        }

        // 🔒 Atomic lock
        $locked = add_post_meta($booking_id, self::META_DECISION, 'approved', true);
        if (!$locked) {
            return ['ok' => false, 'message' => 'Already processed or locked'];
        }

        update_post_meta($booking_id, '_bsbt_owner_decision_time', current_time('mysql'));

        // 📝 Draft payout
        $payout = self::calculate_payout($booking_id);
        update_post_meta($booking_id, '_bsbt_snapshot_owner_payout_draft', $payout);

        // 💳 Find order
        $order = self::find_order_for_booking($booking_id);

        if (!$order instanceof WC_Order) {
            update_post_meta($booking_id, '_bsbt_payment_issue', 1);
            error_log('[BSBT] Order not found for booking #' . $booking_id);
            return ['ok'=>true,'paid'=>false,'message'=>'Order not found'];
        }

        $order_id = $order->get_id();
        $paid_ok  = false;

        try {

            if (!$order->is_paid()) {

                $gateway = function_exists('wc_get_payment_gateway_by_order')
                    ? wc_get_payment_gateway_by_order($order)
                    : null;

                if ($gateway && method_exists($gateway, 'capture_payment')) {
                    $order->add_order_note('BSBT: Attempting Stripe capture...');
                    $gateway->capture_payment($order_id);
                } else {
                    $order->add_order_note('BSBT: Fallback payment_complete() used.');
                    $order->payment_complete();
                }
            }

            // Reload order
            $order = wc_get_order($order_id);
            $paid_ok = ($order && $order->is_paid());

            error_log('[BSBT APPROVE] Booking #' . $booking_id . 
                      ' | Order #' . $order_id . 
                      ' | Paid: ' . ($paid_ok ? 'YES' : 'NO'));

        } catch (\Throwable $e) {
            error_log('[BSBT APPROVE ERROR] #' . $booking_id . ' | ' . $e->getMessage());
            update_post_meta($booking_id, '_bsbt_payment_issue', 1);
            return ['ok'=>true,'paid'=>false,'message'=>'Capture error'];
        }

        // ❌ Payment failed
        if (!$paid_ok) {
            update_post_meta($booking_id, '_bsbt_payment_issue', 1);
            return [
                'ok'=>true,
                'paid'=>false,
                'order_id'=>$order_id,
                'message'=>'Payment not captured (still on-hold)'
            ];
        }

        // ✅ Success
        delete_post_meta($booking_id, '_bsbt_payment_issue');
        update_post_meta($booking_id, '_bsbt_snapshot_owner_payout', $payout);
        delete_post_meta($booking_id, '_bsbt_snapshot_owner_payout_draft');

        error_log('[BSBT SNAPSHOT] Booking #' . $booking_id .
                  ' | Order #' . $order_id .
                  ' | Snapshot=' . $payout);

        // ❗ MPHB status NOT modified intentionally

        return [
            'ok'=>true,
            'paid'=>true,
            'order_id'=>$order_id,
            'message'=>'Approved & Captured'
        ];
    }

    /**
     * DECLINE FLOW
     */
    public static function decline_booking(int $booking_id): array {

        if ($booking_id <= 0) {
            return ['ok'=>false];
        }

        $locked = add_post_meta($booking_id, self::META_DECISION, 'declined', true);
        if (!$locked) {
            return ['ok'=>false];
        }

        $order = self::find_order_for_booking($booking_id);

        if ($order instanceof WC_Order) {

            try {

                if ($order->is_paid()) {

                    if (function_exists('wc_create_refund')) {

                        wc_create_refund([
                            'amount' => $order->get_total(),
                            'reason' => 'Owner declined',
                            'order_id' => $order->get_id(),
                            'refund_payment' => true,
                            'restock_items' => false,
                        ]);

                        $order->add_order_note('BSBT: Refund executed (Owner declined).');
                    }

                } else {
                    $order->update_status('cancelled', 'BSBT: Owner declined – hold released.');
                }

            } catch (\Throwable $e) {
                error_log('[BSBT DECLINE ERROR] #' . $booking_id . ' | ' . $e->getMessage());
            }
        }

        return ['ok'=>true,'message'=>'Declined'];
    }

    /**
     * Universal Order Finder
     */
    private static function find_order_for_booking(int $booking_id): ?WC_Order {

        if (!function_exists('wc_get_orders')) return null;

        $statuses = array_keys(wc_get_order_statuses());
        $meta_keys = ['_mphb_booking_id', self::META_BSBT_REF];

        foreach ($meta_keys as $key) {
            $orders = wc_get_orders([
                'limit'=>1,
                'meta_key'=>$key,
                'meta_value'=>$booking_id,
                'status'=>$statuses
            ]);
            if (!empty($orders) && $orders[0] instanceof WC_Order) {
                return $orders[0];
            }
        }

        // Deep fallback
        $needle = 'Reservation #' . $booking_id;

        $orders = wc_get_orders([
            'limit'=>20,
            'status'=>$statuses,
            'orderby'=>'date',
            'order'=>'DESC'
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if (strpos($item->get_name(), $needle) !== false) {
                    return $order;
                }
            }
        }

        return null;
    }

    /**
     * Safe payout calculation
     */
    private static function calculate_payout(int $booking_id): float {

        if (!function_exists('MPHB')) return 0.0;

        $booking = MPHB()->getBookingRepository()->findById($booking_id);
        if (!$booking) return 0.0;

        $room = $booking->getReservedRooms()[0] ?? null;
        if (!$room) return 0.0;

        $room_type_id = $room->getRoomTypeId();

        $ppn = (float) get_post_meta($room_type_id, 'owner_price_per_night', true);
        if (!$ppn && function_exists('get_field')) {
            $ppn = (float) get_field('owner_price_per_night', $room_type_id);
        }

        $in_ts  = self::normalize_to_timestamp($booking->getCheckInDate());
        $out_ts = self::normalize_to_timestamp($booking->getCheckOutDate());

        return (float) ($ppn * max(0, ($out_ts - $in_ts) / 86400));
    }

    private static function normalize_to_timestamp($value): int {
        if ($value instanceof \DateTimeInterface) return $value->getTimestamp();
        if (is_string($value) && !empty($value)) {
            $ts = strtotime($value);
            return $ts !== false ? $ts : 0;
        }
        return 0;
    }

    // Cron safety
    public static function process_auto_expire() {
        return;
    }
}
