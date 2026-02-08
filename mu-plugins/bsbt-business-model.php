diff --git a/mu-plugins/bsbt-business-model.php b/mu-plugins/bsbt-business-model.php
index 3b5d49e7526bffbbbc5a6152483a5027db2095c6..d9af218baced3a71204b0d57592b31c2445b5927 100644
--- a/mu-plugins/bsbt-business-model.php
+++ b/mu-plugins/bsbt-business-model.php
@@ -1,30 +1,30 @@
 <?php
 /**
  * Plugin Name: BSBT – Business Model Provider
  * Description: Single source of truth for business model (Model A / Model B) per accommodation type.
- * Version: 2.0.0
+ * Version: 2.1.0
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
 
@@ -110,26 +110,99 @@ if ( ! function_exists('bsbt_get_booking_model') ) {
 
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
-}
\ No newline at end of file
+}
+
+/* =========================================================
+ * 3. ПОМОЩНИКИ ДЛЯ ЦЕНООБРАЗОВАНИЯ (MODEL B)
+ * ======================================================= */
+
+if ( ! function_exists( 'bsbt_get_commission_rate' ) ) {
+    function bsbt_get_commission_rate( int $room_type_id = 0 ): float {
+        return 0.15;
+    }
+}
+
+if ( ! function_exists( 'bsbt_get_commission_vat_rate' ) ) {
+    function bsbt_get_commission_vat_rate(): float {
+        return 0.19;
+    }
+}
+
+if ( ! function_exists( 'bsbt_calc_model_b_pricing' ) ) {
+    function bsbt_calc_model_b_pricing( float $owner_net, int $room_type_id = 0 ): ?array {
+        if ( $owner_net <= 0 ) {
+            return null;
+        }
+
+        $commission_rate  = bsbt_get_commission_rate( $room_type_id );
+        $commission_net   = $owner_net * $commission_rate;
+        $commission_vat   = $commission_net * bsbt_get_commission_vat_rate();
+        $commission_gross = $commission_net + $commission_vat;
+        $guest_total      = $owner_net + $commission_gross;
+
+        return array(
+            'owner_net'        => (float) $owner_net,
+            'commission_rate'  => (float) $commission_rate,
+            'commission_net'   => round( $commission_net, 2 ),
+            'commission_vat'   => round( $commission_vat, 2 ),
+            'commission_gross' => round( $commission_gross, 2 ),
+            'guest_total'      => round( $guest_total, 2 ),
+        );
+    }
+}
+
+if ( ! function_exists( 'bsbt_get_pricing' ) ) {
+    function bsbt_get_pricing( int $booking_id ): ?array {
+        $booking_id = (int) $booking_id;
+        if ( $booking_id <= 0 ) {
+            return null;
+        }
+
+        $room_type_id = (int) get_post_meta( $booking_id, 'mphb_room_type_id', true );
+        if ( $room_type_id <= 0 ) {
+            $room_type_id = (int) get_post_meta( $booking_id, '_mphb_room_type_id', true );
+        }
+
+        $model = get_post_meta( $booking_id, '_bsbt_applied_model', true );
+        if ( $model !== 'model_b' && $room_type_id > 0 ) {
+            $model = get_post_meta( $room_type_id, '_bsbt_business_model', true );
+        }
+
+        if ( $model !== 'model_b' ) {
+            return null;
+        }
+
+        $owner_net = 0.0;
+        if ( $room_type_id > 0 ) {
+            $owner_net = (float) get_post_meta( $room_type_id, 'owner_price_per_night', true );
+        }
+
+        if ( $owner_net <= 0 ) {
+            $owner_net = (float) get_post_meta( $booking_id, 'bsbt_owner_price_per_night', true );
+        }
+
+        return bsbt_calc_model_b_pricing( $owner_net, $room_type_id );
+    }
+}
