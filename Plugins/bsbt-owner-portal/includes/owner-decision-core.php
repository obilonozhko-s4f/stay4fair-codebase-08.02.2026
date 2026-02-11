<?php
/**
 * BSBT – Owner Decision CORE (V9.5 – Stable Authorize Flow, MPHB Status SAFE)
 *
 * Fix:
 * - НЕ трогаем mphb статус вручную (ни confirmed, ни cancelled) — иначе MPHB может уйти в "Pending User Confirmation" / пустой статус.
 * - Делаем только: decision meta + Stripe capture (через gateway) + корректный статус Woo.
 * - При decline: отменяем Woo (это снимает authorisation hold в Stripe). MPHB статус пусть ведёт MPHB/его workflow.
 */

if ( ! defined('ABSPATH') ) exit;

final class BSBT_Owner_Decision_Core {

    const META_DECISION      = '_bsbt_owner_decision';
    const META_DECISION_TIME = '_bsbt_owner_decision_time';
    const META_CAPTURE_OK    = '_bsbt_capture_ok';
    const META_CAPTURE_AT    = '_bsbt_capture_at';
    const META_LAST_ERROR    = '_bsbt_capture_last_error';

    const ADMIN_ALERT_EMAIL  = 'business@stay4fair.com';

    /* ========================================================= */
    /* ===================== APPROVE FLOW ======================= */
    /* ========================================================= */

    public static function approve_and_send_payment(int $booking_id): array {

        if ( $booking_id <= 0 ) {
            return self::fail('Invalid booking id');
        }

        if ( ! self::current_user_can_act_on_booking($booking_id) ) {
            return self::fail('No access');
        }

        // Save decision immediately
        self::set_decision($booking_id, 'approved');

        $order = self::find_order_for_booking($booking_id);

        if ( ! $order ) {
            self::fail_with_admin_notice($booking_id, 'Woo order not found');
            return self::ok('Bestätigt – Order nicht gefunden', true);
        }

        try {

            // Already paid (captured ранее)
            if ( $order->is_paid() ) {
                self::mark_captured($booking_id);
                return self::ok('Already paid');
            }

            // Try Stripe capture (Authorize-only safe)
            $gateway = function_exists('wc_get_payment_gateway_by_order')
                ? wc_get_payment_gateway_by_order($order)
                : null;

            if ( $gateway && method_exists($gateway, 'capture_payment') ) {

                $order->add_order_note('BSBT: Attempting Stripe capture (authorize → capture)...');
                $gateway->capture_payment($order->get_id());

            } else {

                // Fallback (неидеально, но лучше чем ничего)
                $order->add_order_note('BSBT: Fallback payment_complete() used (no capture_payment on gateway).');
                $order->payment_complete();
            }

            // Reload order
            $order = wc_get_order($order->get_id());

            if ( $order && $order->is_paid() ) {

                self::mark_captured($booking_id);

                // ✅ ВАЖНО: MPHB статус НЕ трогаем — MPHB сам сделает Confirmed и разошлёт письма
                return self::ok('Approved & captured');
            }

            self::fail_with_admin_notice($booking_id, 'Capture attempted but order not paid', $order ? $order->get_id() : 0);
            return self::ok('Approved – Admin informed', true);

        } catch (\Throwable $e) {

            self::fail_with_admin_notice($booking_id, $e->getMessage(), $order ? $order->get_id() : 0);
            return self::ok('Approved – Error logged', true);
        }
    }

    /* ========================================================= */
    /* ===================== DECLINE FLOW ======================= */
    /* ========================================================= */

    public static function decline_booking(int $booking_id): array {

        if ( $booking_id <= 0 ) {
            return self::fail('Invalid booking id');
        }

        if ( ! self::current_user_can_act_on_booking($booking_id) ) {
            return self::fail('No access');
        }

        self::set_decision($booking_id, 'declined');

        // ✅ ВАЖНО: MPHB статус не меняем вручную.
        // MPHB/админ сможет отменить внутри MPHB, а мы гарантируем, что Stripe hold снимается отменой Woo.

        $order = self::find_order_for_booking($booking_id);

        if ( $order ) {

            try {

                if ( $order->is_paid() ) {

                    // Refund if already captured
                    if ( function_exists('wc_create_refund') ) {

                        wc_create_refund([
                            'amount'         => $order->get_total(),
                            'reason'         => 'Owner declined',
                            'order_id'       => $order->get_id(),
                            'refund_payment' => true,
                            'restock_items'  => false,
                        ]);

                        $order->add_order_note('BSBT: Refund requested (Owner declined).');
                    } else {
                        $order->add_order_note('BSBT: Owner declined (paid), but wc_create_refund() not available.');
                    }

                } else {

                    // Cancel order to release Stripe authorization hold
                    $order->update_status('cancelled', 'BSBT: Owner declined – authorization released.');
                }

            } catch (\Throwable $e) {
                self::fail_with_admin_notice($booking_id, 'Decline flow error: ' . $e->getMessage(), $order->get_id());
            }
        } else {
            self::fail_with_admin_notice($booking_id, 'Decline: Woo order not found');
        }

        wp_mail(
            self::ADMIN_ALERT_EMAIL,
            '[Stay4Fair] OWNER DECLINED – Booking #' . $booking_id,
            admin_url('post.php?post=' . $booking_id . '&action=edit')
        );

        return self::ok('Declined');
    }

    /* ========================================================= */
    /* ================= ORDER FINDING (ORIGINAL) ============== */
    /* ========================================================= */

    private static function find_order_for_booking(int $booking_id): ?\WC_Order {

        if ( ! function_exists('wc_get_orders') ) return null;

        $needle = 'Reservation #' . $booking_id;

        $orders = wc_get_orders([
            'limit'   => 50,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => array_keys(wc_get_order_statuses()),
        ]);

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                if ( strpos($item->get_name(), $needle) !== false ) {
                    return $order;
                }
            }
        }

        return null;
    }

    /* ========================================================= */
    /* ================= ACCESS CONTROL ========================= */
    /* ========================================================= */

    private static function current_user_can_act_on_booking(int $booking_id): bool {

        if ( current_user_can('manage_options') ) return true;

        if ( ! is_user_logged_in() ) return false;

        $user_id = get_current_user_id();

        // Primary: booking meta
        $owner_id = (int) get_post_meta($booking_id, 'bsbt_owner_id', true);

        // Fallback: room type meta
        if ( ! $owner_id && function_exists('MPHB') ) {

            $b = MPHB()->getBookingRepository()->findById($booking_id);
            if ( $b ) {
                $room = $b->getReservedRooms()[0] ?? null;
                if ( $room && method_exists($room,'getRoomTypeId') ) {
                    $owner_id = (int) get_post_meta($room->getRoomTypeId(), 'bsbt_owner_id', true);
                }
            }
        }

        return ( $owner_id > 0 && $owner_id === $user_id );
    }

    /* ========================================================= */
    /* ======================= HELPERS ========================== */
    /* ========================================================= */

    private static function mark_captured(int $booking_id): void {
        update_post_meta($booking_id, self::META_CAPTURE_OK, '1');
        update_post_meta($booking_id, self::META_CAPTURE_AT, current_time('mysql'));
        delete_post_meta($booking_id, self::META_LAST_ERROR);
    }

    private static function set_decision(int $booking_id, string $decision): void {
        update_post_meta($booking_id, self::META_DECISION, $decision);
        update_post_meta($booking_id, self::META_DECISION_TIME, current_time('mysql'));
    }

    private static function fail_with_admin_notice(int $booking_id, string $error, int $order_id = 0): void {

        update_post_meta($booking_id, self::META_LAST_ERROR, $error);

        $body =
            "Booking: #{$booking_id}\n" .
            ($order_id ? "Order: #{$order_id}\n" : "") .
            "Error: {$error}\n\n" .
            admin_url('post.php?post=' . $booking_id . '&action=edit');

        wp_mail(
            self::ADMIN_ALERT_EMAIL,
            '[Stay4Fair] ERROR – Booking #' . $booking_id,
            $body
        );
    }

    private static function ok(string $msg, bool $warning = false): array {
        return ['ok'=>true,'warning'=>$warning,'message'=>$msg];
    }

    private static function fail(string $msg): array {
        return ['ok'=>false,'message'=>$msg];
    }
}
