<?php
/**
 * Plugin Name: BSBT – Business Model Provider
 * Description: Single source of truth for business model (Model A / Model B) per accommodation type.
 * Version: 2.2.0
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

/* =========================================================
 * 3. ПОМОЩНИКИ ДЛЯ ЦЕНООБРАЗОВАНИЯ (MODEL B)
 * ======================================================= */

if ( ! function_exists( 'bsbt_get_commission_rate' ) ) {
    function bsbt_get_commission_rate( int $room_type_id = 0 ): float {
        return 0.15;
    }
}

if ( ! function_exists( 'bsbt_get_commission_vat_rate' ) ) {
    function bsbt_get_commission_vat_rate(): float {
        return 0.19;
    }
}

if ( ! function_exists( 'bsbt_calc_model_b_pricing' ) ) {
    function bsbt_calc_model_b_pricing( float $owner_net, int $room_type_id = 0 ): ?array {
        if ( $owner_net <= 0 ) {
            return null;
        }

        $commission_rate  = bsbt_get_commission_rate( $room_type_id );
        $commission_net   = $owner_net * $commission_rate;
        $commission_vat   = $commission_net * bsbt_get_commission_vat_rate();
        $commission_gross = $commission_net + $commission_vat;
        $guest_total      = $owner_net + $commission_gross;

        return array(
            'owner_net'        => round( (float) $owner_net, 2 ),
            'commission_rate'  => round( (float) $commission_rate, 4 ),
            'commission_net'   => round( (float) $commission_net, 2 ),
            'commission_vat'   => round( (float) $commission_vat, 2 ),
            'commission_gross' => round( (float) $commission_gross, 2 ),
            'guest_total'      => round( (float) $guest_total, 2 ),
        );
    }
}

if ( ! function_exists( 'bsbt_get_pricing' ) ) {
    function bsbt_get_pricing( int $booking_id ): ?array {
        $booking_id = (int) $booking_id;
        if ( $booking_id <= 0 ) {
            return null;
        }

        if ( ! function_exists( 'bsbt_get_booking_model' ) ) {
            return null;
        }

        if ( bsbt_get_booking_model( $booking_id ) !== 'model_b' ) {
            return null;
        }

        $room_type_id = 0;

        if ( function_exists('MPHB') ) {
            try {
                $booking = MPHB()->getBookingRepository()->findById($booking_id);
                if ($booking) {
                    $rooms = $booking->getReservedRooms();
                    if (!empty($rooms) && method_exists($rooms[0], 'getRoomTypeId')) {
                        $room_type_id = (int) $rooms[0]->getRoomTypeId();
                    }
                }
            } catch (\Throwable $e) {
                // silent fail
            }
        }

        if ( $room_type_id <= 0 ) {
            $room_type_id = (int) get_post_meta( $booking_id, 'mphb_room_type_id', true );
        }
        if ( $room_type_id <= 0 ) {
            $room_type_id = (int) get_post_meta( $booking_id, '_mphb_room_type_id', true );
        }

        $owner_net = 0.0;

        if ( $room_type_id > 0 ) {
            $owner_net = (float) get_post_meta( $room_type_id, 'owner_price_per_night', true );
        }

        if ( $owner_net <= 0 ) {
            $owner_net = (float) get_post_meta( $booking_id, 'bsbt_owner_price_per_night', true );
        }

        return bsbt_calc_model_b_pricing( (float) $owner_net, (int) $room_type_id );
    }
}

/* =========================================================
 * 4. VIEW MODEL (MODEL B)
 * ======================================================= */

if ( ! function_exists( 'bsbt_get_model_b_pricing_view' ) ) {
    function bsbt_get_model_b_pricing_view( int $booking_id ): ?array {
        $booking_id = (int) $booking_id;
        if ( $booking_id <= 0 ) {
            return null;
        }

        if ( ! function_exists( 'bsbt_get_booking_model' ) || bsbt_get_booking_model( $booking_id ) !== 'model_b' ) {
            return null;
        }

        $room_type_id = 0;

        if ( function_exists('MPHB') ) {
            try {
                $booking = MPHB()->getBookingRepository()->findById($booking_id);
                if ($booking) {
                    $rooms = $booking->getReservedRooms();
                    if (!empty($rooms) && method_exists($rooms[0], 'getRoomTypeId')) {
                        $room_type_id = (int) $rooms[0]->getRoomTypeId();
                    }
                }
            } catch (\Throwable $e) {
                // silent fail
            }
        }

        if ( $room_type_id <= 0 ) {
            $room_type_id = (int) get_post_meta( $booking_id, 'mphb_room_type_id', true );
        }
        if ( $room_type_id <= 0 ) {
            $room_type_id = (int) get_post_meta( $booking_id, '_mphb_room_type_id', true );
        }

        $check_in  = (string) get_post_meta( $booking_id, 'mphb_check_in_date', true );
        $check_out = (string) get_post_meta( $booking_id, 'mphb_check_out_date', true );

        if ( $check_in === '' ) {
            $check_in = (string) get_post_meta( $booking_id, '_mphb_check_in_date', true );
        }
        if ( $check_out === '' ) {
            $check_out = (string) get_post_meta( $booking_id, '_mphb_check_out_date', true );
        }

        $check_in_ts  = strtotime( $check_in );
        $check_out_ts = strtotime( $check_out );
        if ( ! $check_in_ts || ! $check_out_ts ) {
            return null;
        }

        $nights = (int) round( max( 0, $check_out_ts - $check_in_ts ) / DAY_IN_SECONDS );
        if ( $nights <= 0 ) {
            return null;
        }

        $owner_net_per_night = 0.0;

        if ( $room_type_id > 0 ) {
            $owner_net_per_night = (float) get_post_meta( $room_type_id, 'owner_price_per_night', true );
        }

        if ( $owner_net_per_night <= 0 ) {
            $owner_net_per_night = (float) get_post_meta( $booking_id, 'bsbt_owner_price_per_night', true );
        }

        $pricing = bsbt_calc_model_b_pricing( (float) $owner_net_per_night, (int) $room_type_id );
        if ( empty( $pricing ) ) {
            return null;
        }

        $owner_net_total = round( (float) $owner_net_per_night * $nights, 2 );

        $commission_net_total   = round( (float) $pricing['commission_net'] * $nights, 2 );
        $commission_vat_total   = round( (float) $pricing['commission_vat'] * $nights, 2 );
        $commission_gross_total = round( (float) $pricing['commission_gross'] * $nights, 2 );
        $guest_total            = round( (float) $pricing['guest_total'] * $nights, 2 );

        return array(
            'model'                  => 'model_b',
            'nights'                 => (int) $nights,
            'owner_net_per_night'    => round( (float) $owner_net_per_night, 2 ),
            'owner_net_total'        => (float) $owner_net_total,
            'commission_rate'        => round( (float) $pricing['commission_rate'], 4 ),
            'commission_net_total'   => (float) $commission_net_total,
            'commission_vat_total'   => (float) $commission_vat_total,
            'commission_gross_total' => (float) $commission_gross_total,
            'guest_total'            => (float) $guest_total,
        );
    }
}
