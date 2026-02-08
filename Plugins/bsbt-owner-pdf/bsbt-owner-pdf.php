<?php
/**
 * Plugin Name: BSBT – Owner PDF
 * Description: Owner booking confirmation + payout summary PDF. (V1.8.2 - Fixed Argument Error)
 * Version: 1.8.2
 * Author: BS Business Travelling / Stay4Fair.com
 */

if (!defined('ABSPATH')) exit;

final class BSBT_Owner_PDF {

    const META_LOG = '_bsbt_owner_pdf_log';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox'], 10, 2);
        add_action('add_meta_boxes_mphb_booking', [__CLASS__, 'register_metabox_direct'], 10, 1);
        add_action('admin_post_bsbt_owner_pdf_generate', [__CLASS__, 'admin_generate']);
        add_action('admin_post_bsbt_owner_pdf_open',     [__CLASS__, 'admin_open']);
        add_action('admin_post_bsbt_owner_pdf_resend',   [__CLASS__, 'admin_resend']);

        // ✅ Исправлено: принимаем любое кол-во аргументов, чтобы не падать в 500
        add_action('mphb_booking_status_changed', [__CLASS__, 'maybe_auto_send'], 20, 99);
    }

    /* --- AUTO SEND LOGIC (FIXED) --- */
    public static function maybe_auto_send(...$args) {
        $booking = null;
        $new_status = '';

        // Пытаемся найти объект бронирования в аргументах (MotoPress может менять их местами)
        foreach ($args as $arg) {
            if (is_object($arg) && method_exists($arg, 'getId')) {
                $booking = $arg;
            } elseif (is_string($arg) && in_array($arg, ['confirmed', 'new', 'pending', 'cancelled'])) {
                $new_status = $arg;
            }
        }

        // Нас интересует только переход в "confirmed"
        if ($new_status === 'confirmed' && $booking) {
            $bid = (int)$booking->getId();
            
            // Генерируем PDF
            $res = self::generate_pdf($bid, ['trigger' => 'auto_status_confirmed']);
            
            if ($res['ok'] && !empty($res['path']) && file_exists($res['path'])) {
                self::email_owner($bid, $res['path']);
            }
        }
    }

    /* --- UI & ACTIONS --- */
    public static function register_metabox($post_type) { if ($post_type === 'mphb_booking') self::add_metabox(); }
    public static function register_metabox_direct() { self::add_metabox(); }
    private static function add_metabox() {
        static $added = false;
        if ($added) return;
        add_meta_box('bsbt_owner_pdf', 'BSBT – Owner PDF', [__CLASS__, 'render_metabox'], 'mphb_booking', 'side', 'high');
        $added = true;
    }

    public static function render_metabox($post) {
        $bid = (int)$post->ID;
        $decision = (string)get_post_meta($bid, '_bsbt_owner_decision', true);
        $status = ($decision === 'approved') ? 'BESTÄTIGT' : (($decision === 'declined') ? 'ABGELEHNT' : 'OFFEN');
        $color = ($decision === 'approved') ? '#2e7d32' : (($decision === 'declined') ? '#c62828' : '#f9a825');
        $owner_email = self::get_owner_email($bid);
        $nonce = wp_create_nonce('bsbt_owner_pdf_'.$bid);
        $url_open = admin_url("admin-post.php?action=bsbt_owner_pdf_open&booking_id=$bid&_wpnonce=$nonce");
        $url_gen  = admin_url("admin-post.php?action=bsbt_owner_pdf_generate&booking_id=$bid&_wpnonce=$nonce");
        $url_mail = admin_url("admin-post.php?action=bsbt_owner_pdf_resend&booking_id=$bid&_wpnonce=$nonce");

        echo "<div style='font-size:12px;line-height:1.45'>";
        echo "<p><strong>Status:</strong> <span style='color:$color;font-weight:700'>$status</span></p>";
        echo "<p><strong>Owner E-Mail:</strong> ".($owner_email ? esc_html($owner_email) : "—")."</p>";
        echo "<p style='display:flex;gap:6px;flex-wrap:wrap;margin-top:10px'>";
        echo "<a class='button' target='_blank' href='".esc_url($url_open)."'>Öffnen</a>";
        echo "<a class='button button-primary' href='".esc_url($url_gen)."'>Erzeugen</a>";
        echo "<a class='button' href='".esc_url($url_mail)."'>Senden</a>";
        echo "</p></div>";
    }

    public static function admin_generate() { self::guard(); self::generate_pdf((int)$_GET['booking_id'], ['trigger'=>'admin_generate']); wp_safe_redirect(wp_get_referer()); exit; }
    public static function admin_open() { self::guard(); $last = self::get_last_log((int)$_GET['booking_id']); if (!$last || !file_exists($last['path'])) wp_die('PDF not found'); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="'.basename($last['path']).'"'); readfile($last['path']); exit; }
    public static function admin_resend() { self::guard(); $bid = (int)$_GET['booking_id']; $last = self::get_last_log($bid); $path = ($last && file_exists($last['path'])) ? $last['path'] : self::generate_pdf($bid, ['trigger'=>'admin_resend'])['path']; self::email_owner($bid, $path); wp_safe_redirect(wp_get_referer()); exit; }
    private static function guard() { if (!current_user_can('manage_options')) wp_die('No permission'); check_admin_referer('bsbt_owner_pdf_' . (int)($_GET['booking_id'] ?? 0)); }

    /* --- CORE LOGIC --- */
    private static function generate_pdf(int $bid, array $ctx): array {
        if (!function_exists('bs_bt_try_load_pdf_engine')) return ['ok'=>false];
        $data = self::collect_data($bid);
        if (!$data['ok']) return ['ok'=>false];
        $upload = wp_upload_dir(); $dir = trailingslashit($upload['basedir']).'bsbt-owner-pdf/'; wp_mkdir_p($dir);
        $path = $dir.'Owner_PDF_'.$bid.'.pdf';
        try {
            $engine = bs_bt_try_load_pdf_engine();
            $html = self::render_pdf_html($data['data']);
            if ($engine === 'mpdf') { $mpdf = new \Mpdf\Mpdf(['format'=>'A4']); $mpdf->WriteHTML($html); $mpdf->Output($path, 'F'); }
            else { $dom = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]); $dom->loadHtml($html, 'UTF-8'); $dom->render(); file_put_contents($path, $dom->output()); }
        } catch (\Throwable $e) { return ['ok'=>false]; }
        self::log($bid, ['path'=>$path,'generated_at'=>current_time('mysql'), 'trigger'=>$ctx['trigger']]);
        return ['ok'=>true,'path'=>$path];
    }

    private static function render_pdf_html($data) { ob_start(); $d = $data; include plugin_dir_path(__FILE__).'templates/owner-pdf.php'; return ob_get_clean(); }

    private static function email_owner($bid, $path) {
        $to = self::get_owner_email($bid);
        if (!$to || !file_exists($path)) return false;
        $subject = 'Buchungsbestätigung – Stay4Fair #' . $bid;
        $message = "Guten Tag,\n\nanbei erhalten Sie die Bestätigung für die neue Buchung #$bid.\n\nMit freundlichen Grüßen,\nStay4Fair Team";
        return wp_mail($to, $subject, $message, ['Content-Type: text/plain; charset=UTF-8'], [$path]);
    }

    private static function collect_data(int $bid): array {
        if (!function_exists('MPHB')) return ['ok'=>false];
        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return ['ok'=>false];
        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return ['ok'=>false];
        $room = $rooms[0];
        $rt   = $room->getRoomTypeId();
        $cc = get_post_meta($bid, 'mphb_country', true);
        $m = ['DE'=>'Deutschland','AT'=>'Österreich','CH'=>'Schweiz','FR'=>'Frankreich','IT'=>'Italien','ES'=>'Spanien','GB'=>'Vereinigtes Königreich','US'=>'USA','AU'=>'Australien'];
        $full_country = $m[$cc] ?? $cc;
        $in = get_post_meta($bid,'mphb_check_in_date',true);
        $out = get_post_meta($bid,'mphb_check_out_date',true);
        $n = max(1, (strtotime($out)-strtotime($in))/86400);
        $model_key = get_post_meta($rt, '_bsbt_business_model', true) ?: 'model_a';
        $ppn = floatval(get_post_meta($rt, 'owner_price_per_night', true));
        $total = $ppn * $n;
        $pricing = null;
        if ($model_key === 'model_b') {
            $f = defined('BSBT_FEE') ? BSBT_FEE : 0.15;
            $v = defined('BSBT_VAT_ON_FEE') ? BSBT_VAT_ON_FEE : 0.19;
            $net = $total * $f; $vat = $net * $v;
            $pricing = ['commission_rate'=>$f,'commission_net_total'=>$net,'commission_vat_total'=>$vat,'commission_gross_total'=>$net+$vat];
        }
        return ['ok'=>true, 'data'=>[
            'booking_id'=>$bid,'business_model'=>($model_key==='model_b'?'Modell B (Vermittlung)':'Modell A (Direkt)'),
            'document_type'=>'Abrechnung','apt_title'=>get_the_title($rt),'apt_id'=>$rt,'apt_address'=>get_post_meta($rt,'address',true),
            'owner_name'=>get_post_meta($rt,'owner_name',true) ?: '—','check_in'=>$in,'check_out'=>$out,'nights'=>$n,'guests'=>get_post_meta($bid,'mphb_adults',true) ?: 1,
            'guest_name'=>get_post_meta($bid,'mphb_first_name',true).' '.get_post_meta($bid,'mphb_last_name',true),
            'guest_company'=>get_post_meta($bid,'mphb_company',true),'guest_email'=>get_post_meta($bid,'mphb_email',true),'guest_phone'=>get_post_meta($bid,'mphb_phone',true),
            'guest_addr'=>get_post_meta($bid,'mphb_address1',true),'guest_zip'=>get_post_meta($bid,'mphb_zip',true),'guest_city'=>get_post_meta($bid,'mphb_city',true),'guest_country'=>$full_country,
            'payout'=>number_format($total, 2, ',', '.'),'pricing'=>$pricing,
        ]];
    }

    private static function get_owner_email(int $bid): string {
        if (!function_exists('MPHB')) return '';
        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return '';
        $rooms = $b->getReservedRooms();
        return !empty($rooms) ? (string)get_post_meta($rooms[0]->getRoomTypeId(),'owner_email',true) : '';
    }
    private static function log($bid, $row) { $log = get_post_meta($bid, self::META_LOG, true) ?: []; $log[] = $row; update_post_meta($bid, self::META_LOG, $log); }
    private static function get_last_log($bid) { $log = get_post_meta($bid, self::META_LOG, true); return (is_array($log) && $log) ? end($log) : null; }
}
BSBT_Owner_PDF::init();
