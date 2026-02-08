<?php
/**
 * Plugin Name: BSBT – Business Model Provider (V5.4 - Nuclear Tax Fix)
 * Description: Полностью отключает налоговый статус для Model B.
 * Version: 5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BSBT_FEE', 0.15 ); 
define( 'BSBT_VAT_ON_FEE', 0.19 ); 
define( 'BSBT_META_MODEL', '_bsbt_business_model' );
define( 'BSBT_META_OWNER_PRICE', 'owner_price_per_night' );

/**
 * 1. РАСЧЕТ И СИНХРОНИЗАЦИЯ (Model B)
 */
function bsbt_calculate_final_gross_price( $owner_price ) {
    $owner_price = (float) $owner_price;
    if ( $owner_price <= 0 ) return 0;
    return round( $owner_price + ($owner_price * BSBT_FEE * (1 + BSBT_VAT_ON_FEE)), 2 );
}

add_action( 'acf/save_post', function( $post_id ) {
    if ( get_post_type($post_id) !== 'mphb_room_type' ) return;
    $model = get_post_meta( $post_id, BSBT_META_MODEL, true ) ?: 'model_a';
    if ( $model === 'model_b' ) {
        $owner_price = get_post_meta( $post_id, BSBT_META_OWNER_PRICE, true );
        $final_price = bsbt_calculate_final_gross_price( $owner_price );
        if ( $final_price > 0 ) {
            bsbt_sync_to_mphb_database( $post_id, $final_price );
        }
    }
}, 30 );

function bsbt_sync_to_mphb_database( $room_type_id, $price ) {
    if ( ! function_exists( 'MPHB' ) ) return;
    $repo = MPHB()->getRateRepository();
    $rates = $repo->findAllByRoomType( (int) $room_type_id );
    foreach ( $rates as $rate ) {
        $rate_id = (int) $rate->getId();
        update_post_meta( $rate_id, 'mphb_price', $price );
    }
}

/**
 * 2. ЯДЕРНЫЙ ФИКС НАЛОГОВ (Nuclear Tax Fix)
 */

// Фильтр: является ли товар налогооблагаемым?
add_filter( 'woocommerce_product_is_taxable', 'bsbt_make_model_b_non_taxable', 10, 2 );
function bsbt_make_model_b_non_taxable( $is_taxable, $product ) {
    $room_type_id = get_post_meta( $product->get_id(), '_mphb_room_type_id', true );
    if ( $room_type_id ) {
        $model = get_post_meta( $room_type_id, BSBT_META_MODEL, true ) ?: 'model_a';
        if ( $model === 'model_b' ) {
            return false; // Налог? Нет, не слышали.
        }
    }
    return $is_taxable;
}

// Принудительно ставим "No Tax" для айтемов в корзине
add_action( 'woocommerce_before_calculate_totals', function( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    foreach ( $cart->get_cart() as $item ) {
        $product = $item['data'];
        $room_type_id = get_post_meta( $product->get_id(), '_mphb_room_type_id', true );
        if ( $room_type_id ) {
            $model = get_post_meta( $room_type_id, BSBT_META_MODEL, true ) ?: 'model_a';
            if ( $model === 'model_b' ) {
                $product->set_tax_status( 'none' );
                $product->set_tax_class( '' );
            }
        }
    }
}, 99 );

/**
 * 3. АДМИНКА
 */
add_action( 'add_meta_boxes', function () {
    add_meta_box('bsbt_m', 'BSBT Model', function($post){
        $m = get_post_meta($post->ID, BSBT_META_MODEL, true) ?: 'model_a';
        ?>
        <label><input type="radio" name="bsbt_model" value="model_a" <?php checked($m,'model_a')?>> Model A</label><br>
        <label><input type="radio" name="bsbt_model" value="model_b" <?php checked($m,'model_b')?>> Model B (Tax Off)</label>
        <input type="hidden" name="bsbt_nonce" value="<?php echo wp_create_nonce('bsbt_s')?>">
        <?php
    }, 'mphb_room_type', 'side');
});

add_action('save_post_mphb_room_type', function($post_id){
    if (isset($_POST['bsbt_model']) && wp_verify_nonce($_POST['bsbt_nonce'], 'bsbt_s')) {
        update_post_meta($post_id, BSBT_META_MODEL, sanitize_key($_POST['bsbt_model']));
    }
});
