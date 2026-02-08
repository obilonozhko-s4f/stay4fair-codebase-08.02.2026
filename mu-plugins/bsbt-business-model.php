<?php
/**
 * Plugin Name: BSBT – Business Model Provider
 * Description: Single source of truth for business model (Model A / Model B) per accommodation type.
 * Version: 2.1.1
 * Author: BS Business Travelling / Stay4Fair.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =========================================================
 * 1. BUSINESS MODEL META (mphb_room_type)
 * ======================================================= */

/**
 * Add metabox to Accommodation Type
 */
add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'bsbt_model_box',
        'BSBT – Business Model',
        'bsbt_render_business_model_box',
        'mphb_room_type',
        'side',
        'high'
    );
} );

/**
 * Render business model selector
 */
function bsbt_render_business_model_box( $post ) {

    $model = get_post_meta( $post->ID, '_bsbt_business_model', true );
    if ( ! $model ) {
        $model = 'model_a';
    }

    wp_nonce_field( 'bsbt_save_business_model', 'bsbt_business_model_nonce' );
    ?>
    <p>
        <label>
            <input type="radio" name="bsbt_business_model" value="model_a" <?php checked( $model, 'model_a' ); ?>>
            <strong>Modell A</strong><br>
            <span style="color:#666;font-size:11px">
                Direkt / Resell (Stay4Fair als Vertragspartner, 7% MwSt)
            </span>
        </label>
    </p>
    <p>
        <label>
            <input type="radio" name="bsbt_business_model" value="model_b" <?php checked( $model, 'model_b' ); ?>>
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
add_action( 'save_post_mphb_room_type', function ( $post_id ) {

    if (
        ! isset( $_POST['bsbt_business_model_nonce'] ) ||
        ! wp_verify_nonce( $_POST['bsbt_business_model_nonce'], 'bsbt_save_business_model' )
    ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( isset( $_POST['bsbt_business_model'] ) ) {
        update_post_meta(
            $post_id,
            '_bsbt_business_model',
            sanitize_text_field( $_POST['bsbt_business_model'] )
        );
    }
} );

/* =========================================================
 * 2. BUSINESS MODEL RESOLVER
 * ======================================================= */

if ( ! function_exists( 'bsbt_get_booking_model' ) ) {

    /**
     * Resolve business model for booking
     */
    function bsbt_get_booking_model( int $booking_id ): string {

        // 1) Cached / applied model
        $cached = get_post_meta( $booking_id, '_bsbt_applied_model', true );
        if ( $cached === 'model_a' || $cached === 'model_b' ) {
            return $cached;
        }

        // 2) Resolve via MPHB booking → room type
        if ( function_exists( 'MPHB' ) ) {
            try {
                $booking = MPHB()->getBookingRepository()->findById( $booking_id );
                if ( $booking ) {
                    $rooms = $booking->getReservedRooms();
                    if ( ! empty( $rooms ) && method_exists( $rooms[0], 'getRoomTypeId' ) ) {
                        $room_type_id = (int) $rooms[0]->getRoomTypeId();
                        if ( $room_type_id > 0 ) {
                            $model = get_post_meta( $room_type_id, '_bsbt_business_model', true );
                            if ( $model === 'model_a' || $model === 'model_b' ) {
                                return $model;
                            }
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                // silent fail
            }
        }

        return 'model_a';
    }
}

/* =========================================================
 * 3. PRICING HELPERS (MODEL B, PURE)
 * ======================================================= */

/**
 * Commission rate (default 15%)
 */
if ( ! function_exists( 'bsbt_get_commission_rate' ) ) {
    function bsbt_get_commission_rate( int $room_type_id = 0 ): float {
        return 0.15;
    }
}

/**
 * VAT rate on commission (default 19%)
 */
if ( ! function_exists( 'bsbt_get_commission_vat_rate' ) ) {
    function bsbt_get_commission_vat_rate(): float {
        return 0.19;
    }
}

/**
 * Calculate Model B pricing (pure, no side effects)
 */
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

        return [
            'owner_net'        => round( $owner_net, 2 ),
            'commission_rate'  => round( $commission_rate, 4 ),
            'commission_net'   => round( $commission_net, 2 ),
            'commission_vat'   => round( $commission_vat, 2 ),
            'commission_gross' => round( $commission_gross, 2 ),
            'guest_total'      => round( $guest_total, 2 ),
        ];
    }
}
