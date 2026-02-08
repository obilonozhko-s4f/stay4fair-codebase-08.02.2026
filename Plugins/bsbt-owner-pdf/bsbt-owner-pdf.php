<?php
/**
 * Plugin Name: BSBT – Owner PDF
 * Description: Owner booking confirmation + payout summary PDF.
 * Version: 1.6.3
 * Author: BS Business Travelling / Stay4Fair.com
 */

if (!defined('ABSPATH')) exit;

final class BSBT_Owner_PDF {

    const META_LOG = '_bsbt_owner_pdf_log';

    /* =========================================================
     * INIT
     * ======================================================= */
    public static function init() {

        add_action('add_meta_boxes', [__CLASS__, 'register_metabox'], 10, 2);
        add_action('add_meta_boxes_mphb_booking', [__CLASS__, 'register_metabox_direct'], 10, 1);

        add_action('admin_post_bsbt_owner_pdf_generate', [__CLASS__, 'admin_generate']);
        add_action('admin_post_bsbt_owner_pdf_open',     [__CLASS__, 'admin_open']);
        add_action('admin_post_bsbt_owner_pdf_resend',   [__CLASS__, 'admin_resend']);
    }

    /* =========================================================
     * METABOX
     * ======================================================= */

    public static function register_metabox($post_type) {
        if ($post_type === 'mphb_booking') {
            self::add_metabox();
        }
    }

    public static function register_metabox_direct() {
        self::add_metabox();
    }

    private static function add_metabox() {
        static $added = false;
        if ($added) return;

        add_meta_box(
            'bsbt_owner_pdf',
            'BSBT – Owner PDF',
            [__CLASS__, 'render_metabox'],
            'mphb_booking',
            'side',
            'high'
        );
        $added = true;
    }

    public static function render_metabox($post) {

        $bid = (int)$post->ID;

        $decision = (string)get_post_meta($bid, '_bsbt_owner_decision', true);
        $status = 'OFFEN'; $color = '#f9a825';
        if ($decision === 'approved') { $status = 'BESTÄTIGT'; $color = '#2e7d32'; }
        if ($decision === 'declined') { $status = 'ABGELEHNT'; $color = '#c62828'; }

        $owner_email = self::get_owner_email($bid);

        $engine = function_exists('bs_bt_try_load_pdf_engine')
            ? bs_bt_try_load_pdf_engine()
            : '';
        $engine_ok = (bool)$engine;

        $log = get_post_meta($bid, self::META_LOG, true);
        if (!is_array($log)) $log = [];

        $nonce = wp_create_nonce('bsbt_owner_pdf_'.$bid);
        $url_open = admin_url("admin-post.php?action=bsbt_owner_pdf_open&booking_id=$bid&_wpnonce=$nonce");
        $url_gen  = admin_url("admin-post.php?action=bsbt_owner_pdf_generate&booking_id=$bid&_wpnonce=$nonce");
        $url_mail = admin_url("admin-post.php?action=bsbt_owner_pdf_resend&booking_id=$bid&_wpnonce=$nonce");

        echo "<div style='font-size:12px;line-height:1.45'>";
        echo "<p><strong>Status:</strong> <span style='color:$color;font-weight:700'>$status</span></p>";
        echo "<p><strong>Owner E-Mail:</strong> ".($owner_email ? esc_html($owner_email) : "<span style='color:#c62828'>— fehlt</span>")."</p>";

        echo $engine_ok
            ? "<div style='margin:10px 0;padding:8px;border:1px solid #d3d7e0;background:#f8f9fb;border-radius:8px'>PDF Engine: <strong>".esc_html(strtoupper($engine))."</strong></div>"
            : "<div style='margin:10px 0;padding:8px;border:1px solid #ffcdd2;background:#fff6f6;border-radius:8px'><strong>PDF Engine fehlt.</strong></div>";

        echo "<p style='display:flex;gap:6px;flex-wrap:wrap'>";
        echo "<a class='button' target='_blank' href='".esc_url($url_open)."'>PDF öffnen</a>";
        echo "<a class='button button-primary' href='".esc_url($url_gen)."' ".(!$engine_ok ? "style='opacity:.5;pointer-events:none'" : "").">PDF erzeugen</a>";
        echo "<a class='button' href='".esc_url($url_mail)."' ".(!$engine_ok || !$owner_email ? "style='opacity:.5;pointer-events:none'" : "").">E-Mail erneut senden</a>";
        echo "</p>";

        echo "</div>";
    }

    /* =========================================================
     * ACTIONS
     * ======================================================= */

    public static function admin_generate() {
        self::guard();
        self::generate_pdf((int)$_GET['booking_id'], ['trigger'=>'admin_generate']);
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public static function admin_open() {
        self::guard();
        $last = self::get_last_log((int)$_GET['booking_id']);
        if (!$last || !file_exists($last['path'])) wp_die('PDF not found');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.basename($last['path']).'"');
        readfile($last['path']);
        exit;
    }

    public static function admin_resend() {
        self::guard();
        $bid = (int)$_GET['booking_id'];
        $last = self::get_last_log($bid);
        $path = ($last && file_exists($last['path']))
            ? $last['path']
            : self::generate_pdf($bid, ['trigger'=>'admin_resend'])['path'];
        self::email_owner($bid, $path);
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    private static function guard() {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('bsbt_owner_pdf_' . (int)($_GET['booking_id'] ?? 0));
    }

    /* =========================================================
     * PDF
     * ======================================================= */

    private static function generate_pdf(int $booking_id, array $ctx): array {

        if (!function_exists('bs_bt_try_load_pdf_engine')) return ['ok'=>false];
        $engine = bs_bt_try_load_pdf_engine();
        if (!$engine) return ['ok'=>false];

        $data = self::collect_data($booking_id);
        if (!$data['ok']) return ['ok'=>false];

        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']).'bsbt-owner-pdf/';
        wp_mkdir_p($dir);

        $path = $dir.'Owner_PDF_'.$booking_id.'.pdf';
        $html = self::render_pdf_html($data['data']);

        try {
            if ($engine === 'mpdf') {
                $mpdf = new \Mpdf\Mpdf(['format'=>'A4']);
                $mpdf->WriteHTML($html);
                $mpdf->Output($path, 'F');
            } else {
                $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->render();
                file_put_contents($path, $dompdf->output());
            }
        } catch (\Throwable $e) {
            return ['ok'=>false];
        }

        self::log($booking_id, ['path'=>$path,'generated_at'=>current_time('mysql'),'trigger'=>$ctx['trigger'] ?? 'system']);
        return ['ok'=>true,'path'=>$path];
    }

    private static function render_pdf_html(array $data): string {
        ob_start();
        $d = $data;
        include plugin_dir_path(__FILE__).'templates/owner-pdf.php';
        return ob_get_clean();
    }

    private static function email_owner(int $booking_id, string $path): bool {
        $to = self::get_owner_email($booking_id);
        return ($to && file_exists($path))
            ? wp_mail($to,'Stay4Fair – Buchungsbestätigung','Anbei erhalten Sie die Buchungsbestätigung.',['Content-Type: text/plain; charset=UTF-8'],[$path])
            : false;
    }

    /* =========================================================
     * DATA (FULL + MODELL B SAFE)
     * ======================================================= */

    private static function collect_data(int $booking_id): array {

        if (!function_exists('MPHB')) return ['ok'=>false];

        $b = MPHB()->getBookingRepository()->findById($booking_id);
        if (!$b) return ['ok'=>false];

        $room = $b->getReservedRooms()[0];
        $rt   = $room->getRoomTypeId();

        // ===== OWNER NAME (ACF FIRST) =====
        $owner_name = '—';
        if (function_exists('get_field')) {
            $acf_owner = get_field('owner_name', $rt);
            if (!empty($acf_owner)) {
                $owner_name = trim($acf_owner);
            }
        }
        if ($owner_name === '—') {
            $meta_owner = trim((string)get_post_meta($rt, 'owner_name', true));
            if ($meta_owner !== '') {
                $owner_name = $meta_owner;
            }
        }

        $in  = get_post_meta($booking_id,'mphb_check_in_date',true);
        $out = get_post_meta($booking_id,'mphb_check_out_date',true);
        $n   = max(1,(strtotime($out)-strtotime($in))/86400);

        $ppn    = (float)get_post_meta($rt,'owner_price_per_night',true);
        $payout = number_format($ppn*$n,2,',','.');

        $model_key = function_exists('bsbt_get_booking_model')
            ? bsbt_get_booking_model($booking_id)
            : 'model_a';

        $model_label = ($model_key === 'model_b')
            ? 'Modell B (Vermittlung)'
            : 'Modell A (Direkt / Resell)';

        $pricing = null;
        if ($model_key === 'model_b' && function_exists('bsbt_get_pricing')) {
            $pricing = bsbt_get_pricing($booking_id);
        }

        return [
            'ok'=>true,
            'data'=>[
                'booking_id'=>$booking_id,
                'business_model'=>$model_label,
                'document_type'=>'Abrechnung / Quittung',

                'apt_title'=>get_the_title($rt),
                'apt_id'=>$rt,
                'apt_address'=>get_post_meta($rt,'address',true),
                'owner_name'=>$owner_name,

                'check_in'=>$in,
                'check_out'=>$out,
                'nights'=>$n,
                'guests'=>1,

                'guest_name'=>get_post_meta($booking_id,'mphb_first_name',true).' '.get_post_meta($booking_id,'mphb_last_name',true),
                'guest_company'=>get_post_meta($booking_id,'mphb_company',true),
                'guest_email'=>get_post_meta($booking_id,'mphb_email',true),
                'guest_phone'=>get_post_meta($booking_id,'mphb_phone',true),
                'guest_addr'=>get_post_meta($booking_id,'mphb_address1',true),
                'guest_zip'=>get_post_meta($booking_id,'mphb_zip',true),
                'guest_city'=>get_post_meta($booking_id,'mphb_city',true),
                'guest_country'=>get_post_meta($booking_id,'mphb_country',true),

                'payout'=>$payout,
                'pricing'=>$pricing,
            ]
        ];
    }

    private static function get_owner_email(int $booking_id): string {
        if (!function_exists('MPHB')) return '';
        $b = MPHB()->getBookingRepository()->findById($booking_id);
        $room = $b ? $b->getReservedRooms()[0] : null;
        return $room ? (string)get_post_meta($room->getRoomTypeId(),'owner_email',true) : '';
    }

    private static function log(int $booking_id, array $row) {
        $log = get_post_meta($booking_id,self::META_LOG,true);
        $log = is_array($log) ? $log : [];
        $log[] = $row;
        update_post_meta($booking_id,self::META_LOG,$log);
    }

    private static function get_last_log(int $booking_id) {
        $log = get_post_meta($booking_id,self::META_LOG,true);
        return (is_array($log) && $log) ? end($log) : null;
    }
}

BSBT_Owner_PDF::init();
