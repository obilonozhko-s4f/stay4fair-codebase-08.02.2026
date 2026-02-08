<?php
/**
 * Plugin Name: BSBT – Owner Dashboard (Total 3D Aligned)
 * Version: 18.0.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class BSBT_Aligned_3D_Dashboard {

    public function __construct() {
        add_shortcode( 'bsbt_owner_dashboard', [ $this, 'render' ] );
    }

    public function render() {
        if ( ! is_user_logged_in() ) return 'Bitte loggen Sie sich ein.';
        $user = wp_get_current_user();
        $navy = '#082567'; 
        $gold = '#E0B849'; 
        
        ob_start(); ?>

        <style>
            /* 1. TOTAL 3D VOLUME SYSTEM */
            .bsbt-3d-btn {
                position: relative !important;
                overflow: hidden !important;
                border-radius: 10px !important;
                border: none !important;
                box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important;
                transition: all 0.25s ease !important;
                cursor: pointer !important;
                display: inline-block;
                padding: 12px 24px;
                background-color: <?php echo $gold; ?> !important;
                color: <?php echo $navy; ?> !important;
                background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important;
                background-blend-mode: overlay;
                font-weight: 700;
                text-decoration: none;
                text-align: center;
            }
            .bsbt-3d-btn::before {
                content: "" !important;
                position: absolute !important;
                top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important;
                background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important;
                transform: scaleY(0.48) !important;
                filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important;
            }
            .bsbt-3d-btn:hover { background-color: <?php echo $navy; ?> !important; color: <?php echo $gold; ?> !important; transform: translateY(-2px) !important; }

            /* 2. LAYOUT & VIEWPORT */
            .bsbt-viewport { padding-top: 18vh; padding-bottom: 40px; background: #ffffff; width: 100%; box-sizing: border-box; overflow-x: hidden; }
            #bsbt-container { font-family: 'Segoe UI', Roboto, sans-serif; max-width: 1150px; margin: 0 auto; padding: 0 25px; box-sizing: border-box; }

            /* 3. HEADER ROW */
            .bsbt-header-row {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-bottom: 15px !important;
                flex-wrap: nowrap !important;
            }

            .bsbt-title { font-size: 32px; font-weight: 800; margin: 0; color: <?php echo $navy; ?>; white-space: nowrap; }
            
            .bsbt-lang-box {
                border: 1px solid #f1f5f9;
                padding: 5px 15px;
                border-radius: 20px;
                font-size: 11px;
                color: #94a3b8;
                font-weight: 600;
                background: #fff;
                white-space: nowrap;
            }

            .bsbt-header-right {
                display: flex;
                flex-direction: column;
                align-items: flex-end;
                gap: 8px;
            }

            .bsbt-partner-tag {
                background: <?php echo $navy; ?>;
                color: #fff;
                padding: 7px 15px;
                border-radius: 8px;
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 1px;
                white-space: nowrap;
            }

            /* 4. GRID & CARDS */
            .bsbt-grid { display: grid !important; grid-template-columns: repeat(3, 1fr) !important; gap: 20px !important; margin: 30px 0 !important; }
            .bsbt-glass-card {
                background: #ffffff !important; border: 1px solid #f1f5f9 !important; border-radius: 24px !important;
                padding: 30px 15px !important; text-align: center !important; text-decoration: none !important;
                transition: 0.4s !important; display: flex !important; flex-direction: column !important; align-items: center !important;
                box-shadow: 0 4px 20px rgba(0,0,0,0.03) !important;
                box-sizing: border-box;
            }
            .bsbt-glass-card:hover { transform: translateY(-8px) !important; border-color: <?php echo $gold; ?> !important; box-shadow: 0 20px 40px rgba(8, 37, 103, 0.1) !important; }

            .bsbt-bubble-icon {
                width: 70px; height: 70px; background: radial-gradient(circle at 30% 30%, #ffffff 0%, #f1f5f9 100%);
                border-radius: 20px; display: flex; align-items: center; justify-content: center;
                font-size: 32px; margin-bottom: 15px; box-shadow: 0 8px 15px rgba(0,0,0,0.05);
            }

            /* 5. FOOTER CARD */
            .bsbt-footer {
                background: #f8fafc !important; border-radius: 24px !important; padding: 25px 35px !important;
                display: flex !important; justify-content: space-between !important; align-items: center !important;
                border: 1px solid #e2e8f0 !important; margin-top: 30px;
                box-sizing: border-box;
            }

            /* 6. RESPONSIVE – MOBILE & TABLET FIX */
            @media (max-width: 800px) {
                .bsbt-viewport { padding-top: 10vh; }
                #bsbt-container { padding: 0 15px; }
                
                .bsbt-header-row { 
                    flex-direction: row !important; 
                    flex-wrap: wrap !important; 
                    justify-content: space-between !important;
                    align-items: center !important;
                    gap: 10px !important;
                    margin-bottom: 20px !important;
                }

                .bsbt-title { font-size: 22px; order: 1; }
                .bsbt-header-right { order: 2; align-items: flex-end; }
                .bsbt-partner-tag { font-size: 9px; }

                .bsbt-lang-box { 
                    order: 3; 
                    width: 100% !important; 
                    text-align: center !important; 
                    margin-top: 5px;
                    border: none;
                    background: #f1f5f9; 
                }

                .bsbt-grid { 
                    grid-template-columns: repeat(2, 1fr) !important; 
                    gap: 12px !important; 
                    margin: 20px 0 !important;
                }
                
                .bsbt-glass-card { padding: 20px 10px !important; border-radius: 18px !important; }
                .bsbt-bubble-icon { width: 45px; height: 45px; font-size: 22px; margin-bottom: 10px; }
                .bsbt-glass-card h4 { font-size: 14px !important; }

                .bsbt-footer {
                    flex-direction: column !important; 
                    text-align: center !important;
                    padding: 20px !important;
                    gap: 20px;
                }
                .bsbt-footer > div { max-width: 100% !important; text-align: center !important; }
                .bsbt-footer div[style*="text-align: right"] { text-align: center !important; width: 100%; }
                .bsbt-3d-btn { width: 100% !important; box-sizing: border-box; }
            }

            @media (max-width: 370px) {
                .bsbt-grid { gap: 8px !important; }
                .bsbt-glass-card h4 { font-size: 12px !important; }
            }
        </style>

        <div class="bsbt-viewport">
            <div id="bsbt-container">
                
                <div class="bsbt-header-row">
                    <h2 class="bsbt-title">Owner Dashboard</h2>
                    <div class="bsbt-lang-box">🌐 DE / EN / RU</div>
                    <div class="bsbt-header-right">
                        <div class="bsbt-partner-tag">Stay4Fair Partner</div>
                        <?php echo do_shortcode('[bsbt_logout_button]'); ?>
                    </div>
                </div>

                <p style="font-size: 15px; color: #64748b; margin: 0 0 30px 0;">Willkommen zurück, <span style="font-weight: 700; color: <?php echo $navy; ?>;"><?php echo esc_html($user->display_name); ?></span></p>

                <div class="bsbt-grid">
                    <?php
                    $items = [
                        ['Meine Buchungen', '📅', '/owner-bookings/'],
                        ['Apartments', '🏢', '#'],
                        ['Finanzen', '💳', '#'],
                        ['Kalender', '🗓️', '#'],
                        ['Mein Profil', '👤', '#'],
                        ['Support', '🎧', '#']
                    ];
                    foreach ($items as $item) : ?>
                        <a href="<?php echo $item[2]; ?>" class="bsbt-glass-card">
                            <div class="bsbt-bubble-icon"><?php echo $item[1]; ?></div>
                            <h4 style="margin:0 0 5px 0; font-size: 18px; color: <?php echo $navy; ?>;"><?php echo $item[0]; ?></h4>
                            <span style="font-size: 10px; color: #cbd5e1; font-weight: 700; text-transform: uppercase;">Öffnen</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="bsbt-footer">
                    <div style="max-width: 60%;">
                        <div style="font-weight: 800; font-size: 17px; color: <?php echo $navy; ?>; margin-bottom: 5px;">Modell A (Full-Service)</div>
                        <p style="font-size: 13px; color: #475569; line-height: 1.5; margin: 0;">
                            Wir übernehmen die gesamte Kommunikation und Zahlungsabwicklung für Sie. Sie kümmern sich lediglich um die Reinigung und Schlüsselübergabe. 
                            <strong>Maximale Privatsphäre für Sie.</strong>
                        </p>
                        <div style="margin-top: 8px; font-size: 11px; color: #94a3b8; font-style: italic;">
                            * im Testmodus, Funktion derzeit eingeschränkt
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <button class="bsbt-3d-btn" onclick="alert('Anfrage gesendet!')">Modell Änderung anfragen</button>
                        <div style="margin-top: 10px;">
                            <a href="https://stay4fair.com/owner-terms-agb/" style="font-size: 11px; color: <?php echo $navy; ?>; text-decoration: underline; font-weight: 600;">AGB anzeigen</a>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php
        return ob_get_clean();
    }
}

new BSBT_Aligned_3D_Dashboard();