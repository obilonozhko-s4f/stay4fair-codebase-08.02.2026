<?php
/**
 * Plugin Name: BSBT – Owner Login & Portal Access
 * Description: Версия 2.2.8: Интеграция STAY4FAIR TOTAL 3D VOLUME SYSTEM (V3.1).
 * Version: 2.2.8
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'logout-button.php';

final class BSBT_Owner_Login_System {

    private $navy = '#082567';
    private $gold = '#E0B849';

    public function __construct() {
        add_shortcode( 'bsbt_owner_login', [ $this, 'render_login_form' ] );
        add_action( 'wp_head', [ $this, 'inject_global_styles' ] );
        add_filter( 'gettext', [ $this, 'translate_wc_texts' ], 999, 3 );
        add_action( 'init', [$this, 'handle_owner_logout'] );
        add_action( 'woocommerce_lostpassword_form', [ $this, 'add_back_to_login_link' ] );
    }

    public function handle_owner_logout() {
        if ( isset($_GET['action']) && $_GET['action'] === 'owner_logout' ) {
            wp_logout();
            wp_safe_redirect( site_url('/owner-login/?logout=success') );
            exit;
        }
    }

    public function translate_wc_texts( $translated, $text, $domain ) {
        if ( $domain === 'woocommerce' || $domain === 'default' ) {
            $maps = [
                'Lost your password? Please enter your username or email address. You will receive a link to create a new password via email.' => 'Passwort vergessen? Bitte gib deinen Benutzernamen oder deine E-Mail-Adresse ein. Du erhältst einen Link, um ein neues Passwort per E-Mail zu erstellen.',
                'Username or email' => 'Benutzername oder E-Mail',
                'Reset password' => 'Passwort zurücksetzen'
            ];
            if ( isset($maps[$text]) ) return $maps[$text];
        }
        return $translated;
    }

    public function add_back_to_login_link() {
        echo '<div class="bsbt-back-to-login">
                <a href="'.site_url('/owner-login/').'">← Zurück zum Login</a>
              </div>';
    }

    public function render_login_form() {
        $error = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bsbt_login_submit']) ) {
            $creds = ['user_login' => sanitize_text_field($_POST['log']), 'user_password' => $_POST['pwd'], 'remember' => isset($_POST['rememberme'])];
            $user = wp_signon($creds, is_ssl());
            if ( is_wp_error($user) ) { $error = 'Falsche Zugangsdaten.'; } 
            else { wp_safe_redirect( site_url('/owner-dashboard/') ); exit; }
        }
        ob_start(); ?>
        <div class="bsbt-login-page-wrapper">
            <div class="bsbt-login-card">
                <div class="bsbt-login-header">
                    <h2>Eigentümer Login</h2>
                    <p>Partner-Portal Stay4Fair</p>
                </div>
                <?php if ( $error ) : ?><div class="bsbt-error-box"><?php echo $error; ?></div><?php endif; ?>
                <?php if ( isset($_GET['logout']) ) : ?><div class="bsbt-success-box">Abgemeldet.</div><?php endif; ?>
                <form method="post">
                    <div class="bsbt-input-group"><label>Benutzername oder E-Mail</label><input type="text" name="log" required></div>
                    <div class="bsbt-input-group"><label>Passwort</label><input type="password" name="pwd" required></div>
                    <button type="submit" name="bsbt_login_submit" class="bsbt-cta-button">Einloggen</button>
                    <div class="bsbt-form-footer"><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Passwort vergessen?</a></div>
                </form>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    public function inject_global_styles() {
        if ( ! strpos($_SERVER['REQUEST_URI'], 'owner-login') && ! strpos($_SERVER['REQUEST_URI'], 'lost-password') ) return;
        ?>
        <style>
            /* ЦЕНТРОВКА ПЛИТКИ */
            .bsbt-login-page-wrapper, .woocommerce-lost-password .site-main { 
                display: flex !important; align-items: center !important; justify-content: center !important;
                min-height: 80vh !important; padding: 40px 20px !important; background: #fff !important;
            }

            .bsbt-login-card, form.woocommerce-ResetPassword { 
                width: 100% !important; max-width: 440px !important; padding: 40px !important;
                border-radius: 20px !important; background: #fff !important; 
                box-shadow: 0 20px 50px rgba(0,0,0,0.1) !important; border: 1px solid #f0f0f0 !important;
                margin: 0 auto !important; float: none !important;
            }

            /* ПОЛЯ ВВОДА */
            form.woocommerce-ResetPassword .form-row, .bsbt-input-group { width: 100% !important; margin-bottom: 25px !important; }
            form.woocommerce-ResetPassword label, .bsbt-input-group label { display: block !important; margin-bottom: 12px !important; font-weight: 600 !important; line-height: 1.4 !important; height: auto !important; }
            input[type="text"], input[type="password"], input[type="email"], .woocommerce-Input { width: 100% !important; height: 56px !important; border-radius: 10px !important; border: 1px solid #ddd !important; padding: 0 15px !important; box-sizing: border-box !important; }

            /* =========================================================
               STAY4FAIR - TOTAL 3D VOLUME SYSTEM (V3.1 INTEGRATION)
               ========================================================= */
            .bsbt-cta-button, .woocommerce-Button, .woocommerce-ResetPassword button.button {
                position: relative !important;
                overflow: hidden !important;
                width: 100% !important;
                height: 60px !important;
                border-radius: 10px !important;
                border: none !important;
                cursor: pointer !important;
                display: flex !important; align-items: center !important; justify-content: center !important;
                z-index: 2;
                font-weight: 800 !important;
                text-transform: uppercase !important;
                transition: all 0.25s ease !important;
                margin: 20px 0 !important;

                /* Логика цветов: СИН_ЗОЛ для карточек */
                background-color: <?php echo $this->navy; ?> !important;
                color: <?php echo $this->gold; ?> !important;
                background-image: linear-gradient(180deg, rgba(255,255,255,0.2) 0%, rgba(0,0,0,0.15) 100%) !important;
                background-blend-mode: overlay !important;

                /* Твой Глобальный Box-Shadow */
                box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), 
                            inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), 
                            inset 0 0 0 1px rgba(255,255,255,0.06) !important;
            }

            /* ЭФФЕКТ КУПОЛА (Radial Gloss) */
            .bsbt-cta-button::before, .woocommerce-Button::before, .woocommerce-ResetPassword button.button::before {
                content: "" !important;
                position: absolute !important;
                top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important;
                background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important;
                transform: scaleY(0.48) !important;
                filter: blur(5px) !important;
                opacity: 0.55 !important;
                z-index: 1 !important;
                pointer-events: none !important;
            }

            /* ХОВЕР (ЗОЛОТОЙ -> СИНИЙ) */
            .bsbt-cta-button:hover, .woocommerce-Button:hover, .woocommerce-ResetPassword button.button:hover {
                background-color: <?php echo $this->gold; ?> !important;
                color: <?php echo $this->navy; ?> !important;
                background-image: linear-gradient(180deg, rgba(255,255,255,0.4) 0%, rgba(0,0,0,0.1) 100%) !important;
                transform: translateY(-2px) !important;
                box-shadow: 0 18px 35px rgba(0,0,0,0.5) !important;
            }

            /* ССЫЛКИ И ПЕРЕХОДЫ */
            .bsbt-back-to-login { margin-top: 30px !important; text-align: center !important; }
            .bsbt-back-to-login a { color: #64748b !important; font-weight: 600; text-decoration: none; font-size: 14px; }
            .bsbt-form-footer { text-align: center; }
            .bsbt-form-footer a { color: #b91c1c !important; font-weight: 700; text-decoration: none; }

            .woocommerce-breadcrumb, .entry-header, .woocommerce-MyAccount-navigation { display: none !important; }
            .bsbt-error-box, .bsbt-success-box { padding: 15px; border-radius: 10px; margin-bottom: 20px; text-align: center; border: 1px solid; }
            .bsbt-error-box { background: #fef2f2; color: #b91c1c; border-color: #fee2e2; }
            .bsbt-success-box { background: #f0fdf4; color: #166534; border-color: #dcfce7; }
        </style>
        <?php
    }
}
new BSBT_Owner_Login_System();