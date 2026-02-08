<?php
/**
 * Plugin Name: BSBT – Owner Bookings (V7.6 – DIRECT CAPTURE, UI SAFE)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class BSBT_Owner_Bookings {

    public function __construct() {
        remove_shortcode('bsbt_owner_bookings');
        add_shortcode('bsbt_owner_bookings', [$this, 'render']);

        add_action('wp_ajax_bsbt_confirm_booking', [$this, 'ajax_confirm']);
        add_action('wp_ajax_bsbt_reject_booking',  [$this, 'ajax_reject']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /* =========================
     * ASSETS (FIXED)
     * ========================= */
    public function enqueue_assets() {
        if ( ! is_user_logged_in() ) return;
        if ( ! $this->is_owner_or_admin() ) return;

        wp_enqueue_style(
            'bsbt-owner-bookings',
            plugin_dir_url(__FILE__) . 'assets/css/owner-bookings.css',
            [],
            '7.6'
        );
    }

    /* =========================
     * HELPERS
     * ========================= */
    private function is_owner_or_admin(): bool {
        if ( current_user_can('manage_options') ) return true;
        $u = wp_get_current_user();
        return in_array('owner', (array)$u->roles, true);
    }

    private function get_booking_owner_id(int $booking_id): int {
        $oid = (int) get_post_meta($booking_id, 'bsbt_owner_id', true);
        if ($oid) return $oid;

        if (!function_exists('MPHB')) return 0;
        $b = MPHB()->getBookingRepository()->findById($booking_id);
        if (!$b) return 0;

        $room = $b->getReservedRooms()[0] ?? null;
        if (!$room || !method_exists($room,'getRoomTypeId')) return 0;

        return (int) get_post_meta($room->getRoomTypeId(), 'bsbt_owner_id', true);
    }

    private function get_booking_data(int $booking_id): array {
        $apt_id = 0; $apt_title = '—'; $guests = 0;

        if (function_exists('MPHB')) {
            $b = MPHB()->getBookingRepository()->findById($booking_id);
            if ($b) {
                $room = $b->getReservedRooms()[0] ?? null;
                if ($room && method_exists($room,'getRoomTypeId')) {
                    $apt_id = (int)$room->getRoomTypeId();
                    $apt_title = get_the_title($apt_id) ?: '—';
                }
                if ($room && method_exists($room,'getAdults'))   $guests += (int)$room->getAdults();
                if ($room && method_exists($room,'getChildren')) $guests += (int)$room->getChildren();
            }
        }

        return [$apt_id, $apt_title, $guests];
    }

    private function get_dates(int $booking_id): array {
        return [
            get_post_meta($booking_id,'mphb_check_in_date',true),
            get_post_meta($booking_id,'mphb_check_out_date',true)
        ];
    }

    private function nights(string $in, string $out): int {
        if (!$in || !$out) return 0;
        return max(0,(strtotime($out)-strtotime($in))/86400);
    }

    private function payout(int $booking_id, int $nights): ?float {
        if ($nights <= 0 || !function_exists('MPHB')) return null;

        $b = MPHB()->getBookingRepository()->findById($booking_id);
        if (!$b) return null;

        $room = $b->getReservedRooms()[0] ?? null;
        if (!$room || !method_exists($room,'getRoomTypeId')) return null;

        $room_type_id = (int)$room->getRoomTypeId();

        $ppn = function_exists('get_field')
            ? (float) get_field('owner_price_per_night', $room_type_id)
            : 0.0;

        return $ppn > 0 ? $ppn * $nights : null;
    }

    /* === FIND WC ORDER BY BOOKING ID === */
    private function find_wc_order_for_booking(int $booking_id): int {
        if ( ! function_exists('wc_get_orders') ) return 0;

        $needle = 'Reservation #' . $booking_id;

        $orders = wc_get_orders([
            'limit'   => 50,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => array_keys(wc_get_order_statuses()),
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if (strpos($item->get_name(), $needle) !== false) {
                    return (int)$order->get_id();
                }
            }
        }
        return 0;
    }

    /* =========================
     * ADMIN NOTIFY (NEW, minimal)
     * ========================= */
    private function notify_admin_reject(int $booking_id): void {
        $to = 'business@stay4fair.com';
        $subject = 'BSBT: Booking abgelehnt – Alternative benötigt';

        $guest = trim(
            (string) get_post_meta($booking_id,'mphb_first_name',true) . ' ' .
            (string) get_post_meta($booking_id,'mphb_last_name',true)
        );

        $check_in  = (string) get_post_meta($booking_id,'mphb_check_in_date',true);
        $check_out = (string) get_post_meta($booking_id,'mphb_check_out_date',true);

        $apt_id = 0;
        if (function_exists('MPHB')) {
            $b = MPHB()->getBookingRepository()->findById($booking_id);
            if ($b) {
                $room = $b->getReservedRooms()[0] ?? null;
                if ($room && method_exists($room,'getRoomTypeId')) {
                    $apt_id = (int) $room->getRoomTypeId();
                }
            }
        }

        $message =
            "Owner hat eine Buchung abgelehnt.\n\n" .
            "Booking ID: #{$booking_id}\n" .
            ($apt_id ? "Wohnungs ID: {$apt_id}\n" : "") .
            ($guest ? "Gast: {$guest}\n" : "") .
            (($check_in || $check_out) ? "Zeitraum: {$check_in} – {$check_out}\n" : "") .
            "\nBitte Alternative prüfen und dem Gast anbieten.\n";

        wp_mail($to, $subject, $message);
    }

    /* =========================
     * RENDER (RESTORED HTML + JS)
     * ========================= */
    public function render() {
        if ( ! is_user_logged_in() || ! $this->is_owner_or_admin() ) return 'Zugriff verweigert.';

        $user_id  = get_current_user_id();
        $is_admin = current_user_can('manage_options');

        $ajax  = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('bsbt_owner_action');

        $countries = class_exists('WC_Countries') ? new WC_Countries() : null;

        $q = new WP_Query([
            'post_type'=>'mphb_booking',
            'post_status'=>'any',
            'posts_per_page'=>-1,
            'orderby'=>'date',
            'order'=>'DESC'
        ]);

        ob_start(); ?>

        <div class="bsbt-container">
            
            <?php /* <div class="bsbt-header">
                <h1>Meine Buchungen</h1>
                <a href="/owner-dashboard/" class="button btn-gold-stable bsbt-btn-base">← Dashboard</a>
            </div>
            */ ?>

            <div class="bsbt-card">
                <table class="bsbt-table">
                    <thead>
                        <tr>
                            <th>ID / Apt</th>
                            <th>Gast & Kontakt</th>
                            <th>Aufenthalt</th>
                            <th>Status</th>
                            <th>Auszahlung</th>
                            <th style="text-align:center;">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php while($q->have_posts()): $q->the_post();
                        $bid = get_the_ID();
                        if(!$is_admin && $this->get_booking_owner_id($bid) !== $user_id) continue;

                        $owner_decision = get_post_meta($bid,'_bsbt_owner_decision',true);
                        $confirmed = ($owner_decision === 'approved');

                        [$apt_id,$apt_title,$guests_count] = $this->get_booking_data($bid);
                        [$in,$out] = $this->get_dates($bid);
                        $nights = $this->nights($in,$out);
                        $payout = $this->payout($bid,$nights);

                        $checkin_time = get_post_meta($bid,'mphb_checkin_time',true);

                        $guest = trim(
                            (string) get_post_meta($bid,'mphb_first_name',true) . ' ' .
                            (string) get_post_meta($bid,'mphb_last_name',true)
                        ) ?: 'Gast';

                        $country_code = get_post_meta($bid,'mphb_country',true);
                        $country = $country_code ?: '—';
                        if ($country_code && $countries instanceof WC_Countries) {
                            $list = $countries->get_countries();
                            $country = $list[$country_code] ?? $country_code;
                        }

                        $company = get_post_meta($bid,'mphb_company',true);
                        $addr1   = get_post_meta($bid,'mphb_address1',true);
                        $zip     = get_post_meta($bid,'mphb_zip',true);
                        $city    = get_post_meta($bid,'mphb_city',true);

                        $email = get_post_meta($bid,'mphb_email',true);
                        $phone = get_post_meta($bid,'mphb_phone',true);
                    ?>

                        <tr>
                            <td>
                                <span class="t-bold">Booking ID: #<?= $bid ?></span>
                                <span class="t-gray">Wohnungs ID: <?= $apt_id ?></span>
                                <span class="apt-name-static"><?= esc_html($apt_title) ?></span>
                            </td>

                            <td>
                                <?php if(!$confirmed): ?><span class="badge-new">NEUE ANFRAGE</span><?php endif; ?>
                                <span class="t-bold"><?= esc_html($guest) ?></span>
                                <span class="t-gray"><?= esc_html($country) ?> · <?= (int)$guests_count ?> Gäste</span>

                                <?php if($confirmed && ($company || $addr1 || $zip || $city)): ?>
                                    <div class="t-gray" style="margin-top:6px;">
                                        <?php if($company): ?><strong><?= esc_html($company) ?></strong><br><?php endif; ?>
                                        <?= esc_html(trim((string)$addr1)) ?><br>
                                        <?= esc_html(trim((string)$zip.' '.(string)$city)) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if($confirmed): ?>
                                    <div class="contact-box">
                                        <a href="https://wa.me/<?= preg_replace('/\D+/','',(string)$phone) ?>">WhatsApp</a>
                                        <a href="tel:<?= esc_attr((string)$phone) ?>">Call</a>
                                        <a href="mailto:<?= esc_attr((string)$email) ?>">Email</a>
                                    </div>
                                <?php else: ?>
                                    <div class="locked-info">Kontaktdaten werden nach Bestätigung freigeschaltet</div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="t-bold"><?= esc_html((string)$in) ?> – <?= esc_html((string)$out) ?></span>
                                <span class="t-gray"><?= (int)$nights ?> Nächte</span>
                            </td>

                            <td>
                                <span style="color:<?= $confirmed?'#25D366':'#d32f2f' ?>;font-weight:900;">
                                    <?= $confirmed?'BESTÄTIGT':'OFFEN' ?>
                                </span>
                            </td>

                            <td>
                                <span class="t-bold"><?= $payout ? number_format($payout,2,',','.') . ' €' : '— €' ?></span>
                            </td>

                            <td style="text-align:center;">
                                <?php if(!$confirmed): ?>
                                    <button class="button btn-action-confirm bsbt-btn-base"
                                            data-id="<?= $bid ?>"
                                            data-nonce="<?= $nonce ?>">Bestätigen</button>
                                    <button class="button btn-action-reject bsbt-btn-base"
                                            data-id="<?= $bid ?>"
                                            data-nonce="<?= $nonce ?>">Ablehnen</button>
                                <?php else: ?>
                                    <div style="color:#25D366;font-weight:600;line-height:1.4;">
                                        ✔ Bestätigung erhalten.<br>
                                        Wir übernehmen nun die weitere Organisation.<br>
                                        Bitte bereiten Sie die Wohnung vor und organisieren Sie die Schlüsselübergabe.
                                        <?php if($checkin_time): ?><br><strong>Ankunftszeit: <?= esc_html((string)$checkin_time) ?></strong><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endwhile; wp_reset_postdata(); ?>

                    </tbody>
                </table>
            </div>
        </div>

        <script>
        (function(){
            const ajax = <?= json_encode($ajax) ?>;
            document.querySelectorAll('.btn-action-confirm,.btn-action-reject').forEach(btn=>{
                btn.addEventListener('click',()=>{
                    if(!confirm('Aktion bestätigen?')) return;
                    const d=new URLSearchParams();
                    d.append('action',btn.classList.contains('btn-action-confirm')?'bsbt_confirm_booking':'bsbt_reject_booking');
                    d.append('booking_id',btn.dataset.id);
                    d.append('_wpnonce',btn.dataset.nonce);
                    fetch(ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:d})
                        .then(()=>location.reload());
                });
            });
        })();
        </script>

        <?php return ob_get_clean();
    }

    /* =========================
     * AJAX (SECURED)
     * ========================= */
    public function ajax_confirm() {
        check_ajax_referer('bsbt_owner_action');

        if ( ! $this->is_owner_or_admin() ) {
            wp_send_json_error(['message'=>'No permission']);
        }

        $id = (int)($_POST['booking_id'] ?? 0);
        if ($id<=0) wp_send_json_error();

        if ( ! current_user_can('manage_options') ) {
            if ( $this->get_booking_owner_id($id) !== get_current_user_id() ) {
                wp_send_json_error(['message'=>'Not your booking']);
            }
        }

        update_post_meta($id,'_bsbt_owner_decision','approved');
        update_post_meta($id,'_bsbt_owner_decision_time',current_time('mysql'));

        if (function_exists('wc_get_order')) {
            $order_id = $this->find_wc_order_for_booking($id);
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order && in_array($order->get_status(),['pending','on-hold'],true)) {
                    $order->update_status('processing','BSBT: Owner approved booking');
                }
            }
        }

        wp_send_json_success();
    }

    public function ajax_reject() {
        check_ajax_referer('bsbt_owner_action');

        if ( ! $this->is_owner_or_admin() ) {
            wp_send_json_error(['message'=>'No permission']);
        }

        $id = (int)($_POST['booking_id'] ?? 0);
        if ($id<=0) wp_send_json_error();

        if ( ! current_user_can('manage_options') ) {
            if ( $this->get_booking_owner_id($id) !== get_current_user_id() ) {
                wp_send_json_error(['message'=>'Not your booking']);
            }
        }

        update_post_meta($id,'_bsbt_owner_decision','declined');
        update_post_meta($id,'_bsbt_owner_decision_time',current_time('mysql'));

        wp_update_post([
            'ID'          => $id,
            'post_status' => 'mphb-cancelled',
        ]);

        $this->notify_admin_reject($id);

        wp_send_json_success();
    }
}

new BSBT_Owner_Bookings();