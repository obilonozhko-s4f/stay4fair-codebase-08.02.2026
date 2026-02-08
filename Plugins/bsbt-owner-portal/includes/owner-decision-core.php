<?php
/**
 * BSBT – Owner Decision CORE (V9.1 – Stripe Authorize → Capture FIXED)
 * SAFE VERSION – payment_complete() used
 */

if ( ! defined('ABSPATH') ) exit;

final class BSBT_Owner_Decision_Core {

    const META_DECISION        = '_bsbt_owner_decision';      // approved|declined
    const META_DECISION_TIME   = '_bsbt_owner_decision_time';
    const META_CAPTURE_OK      = '_bsbt_capture_ok';          // 1
    const META_CAPTURE_AT      = '_bsbt_capture_at';
    const META_LAST_ERROR      = '_bsbt_capture_last_error';
    const META_FLOW_MODE       = '_bsbt_flow_mode';           // auto|manual

    const ADMIN_ALERT_EMAIL    = 'business@stay4fair.com';

    /* ========================================================= */
    /* PUBLIC API                                                 */
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

        // Manual flow → stop here
        $flow = get_post_meta($booking_id, self::META_FLOW_MODE, true);
        if ( $flow && $flow !== 'auto' ) {
            return self::ok('Approved (manual flow)');
        }

        // Already captured
        if ( get_post_meta($booking_id, self::META_CAPTURE_OK, true) === '1' ) {
            return self::ok('Already captured');
        }

        // Find Woo order
        $order = self::find_order_for_booking($booking_id);
        if ( ! $order ) {
            self::fail_with_admin_notice($booking_id, 'WooCommerce order not found');
            return self::ok('Bestätigt – Admin informiert', true);
        }

        // Only Stripe
        if ( $order->get_payment_method() !== 'stripe' ) {
            self::fail_with_admin_notice($booking_id, 'Order is not Stripe');
            return self::ok('Bestätigt – Admin informiert', true);
        }

        // === 🔥 THE FIX 🔥 ===
        try {
            if ( ! $order->is_paid() ) {
                $order->payment_complete(); // <-- CRITICAL
                $order->add_order_note('BSBT: Owner approved – Stripe capture triggered.');
            }

            if ( $order->is_paid() ) {

                update_post_meta($booking_id, self::META_CAPTURE_OK, '1');
                update_post_meta($booking_id, self::META_CAPTURE_AT, current_time('mysql'));
                delete_post_meta($booking_id, self::META_LAST_ERROR);

                // MPHB confirmation (because you use "By admin manually")
                wp_update_post([
                    'ID'          => $booking_id,
                    'post_status' => 'mphb-confirmed',
                ]);

                return self::ok('Approved & captured');
            }

            self::fail_with_admin_notice($booking_id, 'payment_complete() called but order not paid');
            return self::ok('Bestätigt – Admin informiert', true);

        } catch (\Throwable $e) {

            self::fail_with_admin_notice(
                $booking_id,
                'payment_complete() error: ' . $e->getMessage(),
                $order->get_id()
            );

            return self::ok('Bestätigt – Admin informiert', true);
        }
    }

    public static function decline_booking(int $booking_id): array {

        if ( ! self::current_user_can_act_on_booking($booking_id) ) {
            return self::fail('No access');
        }

        self::set_decision($booking_id, 'declined');

        wp_mail(
            self::ADMIN_ALERT_EMAIL,
            '[Stay4Fair] OWNER DECLINED – Booking #' . $booking_id,
            'Owner declined booking #' . $booking_id . "\n\n" .
            admin_url('post.php?post=' . $booking_id . '&action=edit')
        );

        return self::ok('Declined');
    }

    /* ========================================================= */
    /* ORDER FINDING                                              */
    /* ========================================================= */

    private static function find_order_for_booking(int $booking_id): ?\WC_Order {

        if ( ! function_exists('wc_get_order') ) return null;

        // PRIMARY (correct one)
        $order_id = (int) get_post_meta($booking_id, '_mphb_wc_order_id', true);
        if ( $order_id ) {
            $order = wc_get_order($order_id);
            if ( $order ) return $order;
        }

        return null;
    }

    /* ========================================================= */
    /* ACCESS CONTROL                                             */
    /* ========================================================= */

    private static function current_user_can_act_on_booking(int $booking_id): bool {

        if ( current_user_can('manage_options') ) return true;

        if ( ! is_user_logged_in() ) return false;

        $user = wp_get_current_user();
        if ( ! in_array('owner', (array) $user->roles, true) ) return false;

        $owner_id = (int) get_post_meta($booking_id, 'bsbt_owner_id', true);
        return $owner_id === get_current_user_id();
    }

    /* ========================================================= */
    /* HELPERS                                                    */
    /* ========================================================= */

    private static function set_decision(int $booking_id, string $decision): void {
        update_post_meta($booking_id, self::META_DECISION, $decision);
        update_post_meta($booking_id, self::META_DECISION_TIME, current_time('mysql'));
    }

    private static function fail_with_admin_notice(int $booking_id, string $error, int $order_id = 0): void {

        update_post_meta($booking_id, self::META_LAST_ERROR, $error);

        $body =
            "Owner approved booking, but automatic capture failed.\n\n" .
            "Booking: #{$booking_id}\n" .
            ($order_id ? "Order: #{$order_id}\n" : "") .
            "Error: {$error}\n\n" .
            admin_url('post.php?post=' . $booking_id . '&action=edit');

        wp_mail(
            self::ADMIN_ALERT_EMAIL,
            '[Stay4Fair] CAPTURE FAILED – Booking #' . $booking_id,
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
