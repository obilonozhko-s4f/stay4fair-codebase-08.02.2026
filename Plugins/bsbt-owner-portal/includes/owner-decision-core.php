<?php
/**
 * BSBT – Owner Decision CORE (V10.0 – Pending Admin Flow)
 */

if ( ! defined('ABSPATH') ) exit;

final class BSBT_Owner_Decision_Core {

    const META_DECISION      = '_bsbt_owner_decision';
    const ADMIN_ALERT_EMAIL  = 'business@stay4fair.com';

    /**
     * CRON: Проверка 24 часа. Переводит в Pending Admin.
     */
    public static function process_auto_expire() {
        $q = new WP_Query([
            'post_type'      => 'mphb_booking',
            'post_status'    => 'mphb-pending',
            'posts_per_page' => 10,
            'meta_query'     => [
                [
                    'key'     => self::META_DECISION,
                    'compare' => 'NOT EXISTS', 
                ]
            ],
            'date_query'     => [
                [
                    'before' => '24 hours ago',
                ]
            ]
        ]);

        if ( $q->have_posts() ) {
            foreach ( $q->posts as $post ) {
                self::decline_booking($post->ID, true); 
            }
        }
    }

    /* ========================================================= */
    /* ===================== APPROVE FLOW ======================= */
    /* ========================================================= */

    public static function approve_and_send_payment(int $booking_id): array {
        if ($booking_id <= 0) return ['ok'=>false];
        
        update_post_meta($booking_id, self::META_DECISION, 'approved');
        update_post_meta($booking_id, '_bsbt_owner_decision_time', current_time('mysql'));

        $order = self::find_order_for_booking($booking_id);
        if ($order) {
            try {
                if (!$order->is_paid()) {
                    $gateway = function_exists('wc_get_payment_gateway_by_order') ? wc_get_payment_gateway_by_order($order) : null;
                    if ($gateway && method_exists($gateway, 'capture_payment')) {
                        $gateway->capture_payment($order->get_id());
                    } else {
                        $order->payment_complete();
                    }
                }
            } catch (\Throwable $e) {}
        }
        return ['ok'=>true, 'message'=>'Approved'];
    }

    /* ========================================================= */
    /* ===================== DECLINE FLOW ======================= */
    /* ========================================================= */

    public static function decline_booking(int $booking_id, bool $is_auto = false): array {
        if ($booking_id <= 0) return ['ok'=>false];

        // 1. Помечаем в мете владельца
        update_post_meta($booking_id, self::META_DECISION, 'declined');
        update_post_meta($booking_id, '_bsbt_owner_decision_time', current_time('mysql'));

        // 2. СТАТУС В MOTOPRESS
        if (function_exists('MPHB')) {
            $booking = MPHB()->getBookingRepository()->findById($booking_id);
            if ($booking) {
                if ($is_auto) {
                    // АВТО-ОТМЕНА: Ставим Pending Admin. Даты ЗАНЯТЫ.
                    $booking->setStatus('mphb-pending-admin');
                    $booking->update();
                } else {
                    // ВЛАДЕЛЕЦ ОТКАЗАЛ: Освобождаем даты.
                    $booking->abandon(); 
                }
            }
        }

        // 3. WOOCOMMERCE: ВОЗВРАТ / ОТМЕНА
        $order = self::find_order_for_booking($booking_id);
        if ($order) {
            try {
                $note = $is_auto ? 'BSBT: 24h Timeout - Moved to Pending Admin.' : 'BSBT: Owner declined.';
                if ($order->is_paid()) {
                    wc_create_refund([
                        'amount' => $order->get_total(),
                        'reason' => $is_auto ? '24h Timeout' : 'Owner Declined',
                        'order_id' => $order->get_id(),
                        'refund_payment' => true
                    ]);
                    $order->add_order_note($note . ' Refunded.');
                } else {
                    $order->update_status('cancelled', $note);
                }
            } catch (\Throwable $e) {}
        }

        // 4. УВЕДОМЛЕНИЕ
        if ($is_auto) {
            wp_mail(
                self::ADMIN_ALERT_EMAIL, 
                "[Stay4Fair] PENDING ADMIN: #$booking_id", 
                "Бронирование #$booking_id переведено в статус Pending Admin (24ч без ответа). Деньги возвращены, даты ЗАНЯТЫ."
            );
        }

        return ['ok'=>true, 'message' => $is_auto ? 'Auto-processed' : 'Declined'];
    }

    /* ========================================================= */
    /* ======================= HELPERS ========================== */
    /* ========================================================= */

    private static function find_order_for_booking(int $booking_id): ?\WC_Order {
        if (!function_exists('wc_get_orders')) return null;
        $needle = 'Reservation #' . $booking_id;
        $orders = wc_get_orders(['limit' => 20, 'status' => array_keys(wc_get_order_statuses())]);
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if (strpos($item->get_name(), $needle) !== false) return $order;
            }
        }
        return null;
    }
}
