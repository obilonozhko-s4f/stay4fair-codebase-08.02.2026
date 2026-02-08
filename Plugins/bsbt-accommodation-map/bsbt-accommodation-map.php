<?php
/**
 * Plugin Name: BSBT Accommodation Map
 * Description: Шорткод [bsbt_map] для вывода Google Maps по адресу квартиры (метаполе "address" у mphb_room_type) без подписи номера дома. Координаты кешируются в метаполях.
 * Author: BS Business Travelling
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Твой Google Maps API ключ.
 * ВСТАВЬ СЮДА РЕАЛЬНЫЙ КЛЮЧ от Google (Geocoding + Maps Embed).
 * ВАЖНО: не оставляй здесь YOUR_GOOGLE_MAPS_API_KEY_HERE
 */
define( 'BSBT_MAPS_API_KEY', 'AIzaSyAahEOAlwCKlEKriPYjEJzV6ZktWqJxFC4' );

/**
 * Основной шорткод [bsbt_map]
 *
 * Пример использования:
 *   [bsbt_map]
 *   [bsbt_map zoom="15" height="350"]
 *   [bsbt_map address="Custom address, Hannover"]
 */
function bsbt_accommodation_map_shortcode( $atts = array(), $content = '' ) {

    // Если ключ вдруг не заменён
    if ( BSBT_MAPS_API_KEY === 'XXX_TUT_TVOY_API_KEY_XXX' ) {

        return '<p style="color:red;">BSBT Map: Please set your Google Maps API key in the plugin file.</p>';
    }

    global $post;

    if ( ! $post ) {
        return '';
    }

    // Настройки по умолчанию
    $atts = shortcode_atts(
        array(
            'address' => '',   // можно переопределить адрес прямо в шорткоде
            'zoom'    => 16,
            'height'  => 350,
        ),
        $atts,
        'bsbt_map'
    );

    $post_id = $post->ID;

    // Разрешаем использовать шорткод только на single mphb_room_type (квартира), если не передан кастомный address
    if ( ! is_singular( 'mphb_room_type' ) && empty( $atts['address'] ) ) {
        return '';
    }

    // Адрес: либо из атрибута, либо из кастомного поля "address"
    $address = trim( $atts['address'] );

    if ( $address === '' ) {
        $address = get_post_meta( $post_id, 'address', true );
    }

    if ( ! $address ) {
        return '<p>No address available for this accommodation.</p>';
    }

    // Проверяем, есть ли уже сохранённые координаты
    $lat = get_post_meta( $post_id, '_bsbt_lat', true );
    $lng = get_post_meta( $post_id, '_bsbt_lng', true );

    // Если нет — геокодим адрес через Geocoding API
    if ( empty( $lat ) || empty( $lng ) || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {

        $geocode_url = add_query_arg(
            array(
                'address' => $address,
                'key'     => BSBT_MAPS_API_KEY,
            ),
            'https://maps.googleapis.com/maps/api/geocode/json'
        );

        $response = wp_remote_get( $geocode_url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) ) {
            return '<p>Unable to load map coordinates.</p>';
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! isset( $data['status'] ) || $data['status'] !== 'OK' || empty( $data['results'][0]['geometry']['location'] ) ) {
            return '<p>Address cannot be geocoded for map.</p>';
        }

        $location = $data['results'][0]['geometry']['location'];
        $lat      = $location['lat'];
        $lng      = $location['lng'];

        // Кешируем координаты, чтобы каждый раз не дергать API
        if ( is_singular( 'mphb_room_type' ) ) {
            update_post_meta( $post_id, '_bsbt_lat', $lat );
            update_post_meta( $post_id, '_bsbt_lng', $lng );
        }
    }

    // Безопасность на всякий случай
    $lat    = floatval( $lat );
    $lng    = floatval( $lng );
    $zoom   = intval( $atts['zoom'] );
    $height = intval( $atts['height'] );

    if ( $zoom < 1 || $zoom > 20 ) {
        $zoom = 16;
    }
    if ( $height < 100 ) {
        $height = 350;
    }

  // Генерируем iframe через Maps Embed API (place) – с маркером по координатам
$src = add_query_arg(
    array(
        'key' => BSBT_MAPS_API_KEY,
        'q'   => $lat . ',' . $lng,
        'zoom' => $zoom,
    ),
    'https://www.google.com/maps/embed/v1/place'
);


    $iframe = sprintf(
        '<div class="bsbt-map-container">
            <iframe
                width="100%%"
                height="%d"
                frameborder="0"
                style="border:0; border-radius:16px;"
                src="%s"
                allowfullscreen
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>',
        $height,
        esc_url( $src )
    );

    return $iframe;
}
add_shortcode( 'bsbt_map', 'bsbt_accommodation_map_shortcode' );
