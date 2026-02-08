<?php
/**
 * Plugin Name: All Apartments on the Map
 * Version: 4.1.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AAOTM_KEY', 'AIzaSyAahEOAlwCKlEKriPYjEJzV6ZktWqJxFC4' );

add_action( 'wp_enqueue_scripts', function() {
    $url = plugin_dir_url( __FILE__ );
    wp_enqueue_style( 'aaotm-css', $url . 'assets/css/map.css', [], '4.1.6' );
    wp_enqueue_script( 'aaotm-js', $url . 'assets/js/map.js', ['jquery'], '4.1.6', true );
    wp_localize_script( 'aaotm-js', 'AAOTM', [
        'ajax_url' => admin_url( 'admin-ajax.php' )
    ]);
    wp_enqueue_script(
        'google-maps',
        'https://maps.googleapis.com/maps/api/js?key=' . AAOTM_KEY,
        [],
        null,
        true
    );
});

/* ============================================================
 * SHORTCODE
 * ============================================================ */
add_shortcode( 'all_apartments_on_map', function() {
    return '<div class="aaotm-wrapper">
        <div id="aaotm-map" style="width:100%; height:350px; background:#e5e3df; border-radius:12px;"></div>
    </div>';
});

/* ============================================================
 * AJAX
 * ============================================================ */
add_action( 'wp_ajax_aaotm_get_apartments', 'aaotm_ajax_handler' );
add_action( 'wp_ajax_nopriv_aaotm_get_apartments', 'aaotm_ajax_handler' );

function aaotm_ajax_handler() {

    if ( ob_get_length() ) ob_clean();

    $raw_in   = $_POST['check_in'] ?? '';
    $raw_out  = $_POST['check_out'] ?? '';

    $check_in  = is_array($raw_in)  ? end($raw_in)  : $raw_in;
    $check_out = is_array($raw_out) ? end($raw_out) : $raw_out;

    $formatted_in  = date('Y-m-d', strtotime($check_in));
    $formatted_out = date('Y-m-d', strtotime($check_out));

    $adults   = intval($_POST['adults'] ?? 1);
    $apt_type = $_POST['apt_type'] ?? '';

    $all_rooms = get_posts([
        'post_type'      => 'mphb_room_type',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ]);

    $room_ids = [];
    $room_type_model = class_exists('\MPHB\Models\RoomTypeModel')
        ? new \MPHB\Models\RoomTypeModel()
        : null;

    foreach ($all_rooms as $id) {

        /* Apartment type filter */
        if ( ! empty($apt_type) ) {
            $has_type = false;
            foreach ( get_object_taxonomies('mphb_room_type') as $tax ) {
                if ( has_term((int)$apt_type, $tax, $id) ) {
                    $has_type = true;
                    break;
                }
            }
            if ( ! $has_type ) continue;
        }

        /* Adults capacity */
        $capacity = (int) get_post_meta($id, 'mphb_adults_capacity', true);
        if ( $capacity > 0 && $capacity < $adults ) continue;

        /* Availability */
        if ( $formatted_in && $formatted_out && $room_type_model ) {
            if ( $room_type_model->getAvailableCount($id, $formatted_in, $formatted_out) <= 0 ) {
                continue;
            }
        }

        $room_ids[] = $id;
    }

    if ( empty($room_ids) ) {
        wp_send_json_success([]);
        wp_die();
    }

    $result = [];

    foreach ( array_unique($room_ids) as $id ) {

        $lat = get_post_meta($id, '_bsbt_lat', true);
        $lng = get_post_meta($id, '_bsbt_lng', true);

        if ( empty($lat) || empty($lng) ) continue;

        $terms = get_the_terms($id, 'mphb_room_type_category');

        /* PRICE FOR PERIOD */
        $price = '';
        if ( $formatted_in && $formatted_out ) {
            $price = aaotm_get_price_for_period(
                $id,
                $formatted_in,
                $formatted_out,
                $adults
            );
        }

        $result[] = [
            'id'       => $id,
            'title'    => get_the_title($id),
            'lat'      => (float)$lat,
            'lng'      => (float)$lng,
            'url'      => get_permalink($id),
            'rooms'    => ($terms && !is_wp_error($terms)) ? $terms[0]->name : 'Apartment',
            'capacity' => get_post_meta($id, 'mphb_adults_capacity', true) ?: '2',
            'img'      => get_the_post_thumbnail_url($id, 'medium') ?: '',
            'price'    => $price ?: 'Check price'
        ];
    }

    wp_send_json_success($result);
    wp_die();
}

/* ============================================================
 * PRICE CALCULATION (SAFE)
 * ============================================================ */
function aaotm_get_price_for_period( $room_type_id, $check_in, $check_out, $adults = 1 ) {

    if ( ! function_exists('mphb') ) return '';

    try {
        $checkIn  = new DateTime($check_in);
        $checkOut = new DateTime($check_out);

        $pricingService = mphb()->getPricingService();
        if ( ! $pricingService ) return '';

        $price = $pricingService->calcRoomTypePrice(
            $room_type_id,
            $checkIn,
            $checkOut,
            (int)$adults,
            0
        );

        if ( ! is_numeric($price) ) return '';

        return '€' . number_format_i18n($price, 0);

    } catch (Throwable $e) {
        return '';
    }
}
