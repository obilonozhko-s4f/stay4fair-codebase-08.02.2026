<?php
/**
 * Plugin Name: BSBT – Business Model Provider
 * Description: Single source of truth for business model (Model A / Model B) per accommodation type.
 * Version: 2.0.0
 * Author: BS Business Travelling / Stay4Fair.com
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

/* =========================================================
 * 1. BUSINESS MODEL META (mphb_room_type)
 * ======================================================= */

/**
 * Add metabox to Accommodation Type (mphb_room_type)
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'bsbt_model_box',
        'BSBT – Business Model',
        'bsbt_render_business_model_box',
        'mphb_room_type',
        'side',
        'high'
    );
});

/**
 * Render metabox
 */
function bsbt_render_business_model_box($post) {

    $model = get_post_meta($post->ID, '_bsbt_business_model', true);
    if (!$model) {
        $model = 'model_a';
    }

    wp_nonce_field('bsbt_save_business_model', 'bsbt_business_model_nonce');
    ?>
    <p>
        <label>
            <input type="radio" name="bsbt_business_model" value="model_a" <?php checked($model, 'model_a'); ?>>
            <strong>Modell A</strong><br>
            <span style="color:#666;font-size:11px">
                Direkt / Resell (Stay4Fair als Vertragspartner, 7% MwSt)
            </span>
        </label>
    </p>
    <p>
        <label>
            <input type="radio" name="bsbt_business_model" value="model_b" <?php checked($model, 'model_b'); ?>>
            <strong>Modell B</strong><br>
            <span style="color:#666;font-size:11px">
                Plattform / Vermittlung (15% Provision + 19% MwSt auf Provision)
            </span>
        </label>
    </p>
    <?php
}

/**
 * Save business model
 */
add_action('save_post_mphb_room_type', function ($post_id) {

    if (
        ! isset($_POST['bsbt_business_model_nonce']) ||
        ! wp_verify_nonce($_POST['bsbt_business_model_nonce'], 'bsbt_save_business_model')
    ) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (isset($_POST['bsbt_business_model'])) {
        update_post_meta(
            $post_id,
            '_bsbt_business_model',
            sanitize_text_field($_POST['bsbt_business_model'])
        );
    }
});

/* =========================================================
 * 2. PUBLIC HELPER: GET MODEL FOR BOOKING
 * ======================================================= */

/**
 * Get business model for booking
 *
 * Priority:
 * 1) Applied/cached booking model (if set elsewhere)
 * 2) Room type meta: _bsbt_business_model
 * 3) Fallback: model_a
 */
if ( ! function_exists('bsbt_get_booking_model') ) {

    function bsbt_get_booking_model(int $booking_id): string {

        // 1) Cached / explicitly applied model (optional, future-proof)
        $cached = get_post_meta($booking_id, '_bsbt_applied_model', true);
        if ($cached === 'model_a' || $cached === 'model_b') {
            return $cached;
        }

        // 2) Resolve via MPHB booking → room type
        if (function_exists('MPHB')) {
            try {
                $booking = MPHB()->getBookingRepository()->findById($booking_id);
                if ($booking) {
                    $rooms = $booking->getReservedRooms();
                    if (!empty($rooms) && method_exists($rooms[0], 'getRoomTypeId')) {
                        $room_type_id = (int) $rooms[0]->getRoomTypeId();
                        if ($room_type_id > 0) {
                            $model = get_post_meta($room_type_id, '_bsbt_business_model', true);
                            if ($model === 'model_a' || $model === 'model_b') {
                                return $model;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // silent fail
            }
        }

        // 3) Fallback
        return 'model_a';
    }
}