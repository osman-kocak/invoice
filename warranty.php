/**
 * Gelişmiş Garanti Belgesi Sistemi - Güncel Tema
 * Performans + Güvenlik + QR Doğrulama + Ultra Kompakt Tasarım
 * functions.php dosyanıza ekleyiniz
 */

// ============================================
// A) PERFORMANS OPTİMİZASYONU
// ============================================

// Cache sistemi - versiyonlu
function get_warranty_cache_key($order_id) {
    return 'warranty_cache_v5_' . $order_id; // v5 = yeni ultra kompakt tasarım
}

function get_cached_warranty($order_id) {
    return get_transient(get_warranty_cache_key($order_id));
}

function set_warranty_cache($order_id, $html, $expiry = 3600) {
    set_transient(get_warranty_cache_key($order_id), $html, $expiry);
}

function clear_warranty_cache($order_id) {
    delete_transient(get_warranty_cache_key($order_id));
}

// Lazy loading için AJAX endpoint
add_action('wp_ajax_load_warranty', 'ajax_load_warranty');
add_action('wp_ajax_nopriv_load_warranty', 'ajax_load_warranty');

function ajax_load_warranty() {
    // Rate limiting check
    if (!check_warranty_rate_limit()) {
        wp_die('Too many requests. Please wait a moment.');
    }
    
    $order_id = intval($_POST['order_id']);
    $nonce = sanitize_text_field($_POST['nonce']);
    
    if (!wp_verify_nonce($nonce, 'warranty_nonce_' . $order_id)) {
        wp_die('Security check failed.');
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die('Order not found.');
    }
    
    // Cache kontrolü
    $cached_html = get_cached_warranty($order_id);
    if ($cached_html) {
        echo $cached_html;
        wp_die();
    }
    
    // Log activity
    log_warranty_activity($order_id, 'ajax_load');
    
    // HTML oluştur ve cache'le
    $html = generate_warranty_html_content($order);
    set_warranty_cache($order_id, $html, 1800); // 30 dakika cache
    
    echo $html;
    wp_die();
}

// ============================================
// B) GÜVENLİK & DOĞRULAMA
// ============================================

// JWT Token sistemi
function generate_warranty_token($order_id, $customer_email) {
    $payload = array(
        'order_id' => $order_id,
        'email' => $customer_email,
        'timestamp' => time(),
        'expire' => time() + (24 * 60 * 60) // 24 saat
    );
    
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode($payload);
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, 'warranty_secret_key_' . SECURE_AUTH_KEY, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

function verify_warranty_token($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    
    list($base64Header, $base64Payload, $base64Signature) = $parts;
    
    // Signature doğrula
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, 'warranty_secret_key_' . SECURE_AUTH_KEY, true);
    $expectedSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    if (!hash_equals($base64Signature, $expectedSignature)) {
        return false;
    }
    
    // Payload decode
    $payload = json_decode(base64_decode($base64Payload), true);
    
    // Expiry check
    if ($payload['expire'] < time()) {
        return false;
    }
    
    return $payload;
}

// Rate limiting
function check_warranty_rate_limit($max_requests = 10, $time_window = 300) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = 'warranty_rate_limit_' . md5($ip);
    
    $requests = get_transient($key);
    if ($requests === false) {
        set_transient($key, 1, $time_window);
        return true;
    }
    
    if ($requests >= $max_requests) {
        return false;
    }
    
    set_transient($key, $requests + 1, $time_window);
    return true;
}

// Activity logging
function log_warranty_activity($order_id, $action, $details = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'warranty_logs';
    
    // Tablo yoksa oluştur
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id mediumint(9) NOT NULL,
            action varchar(50) NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_agent text,
            details text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    $wpdb->insert(
        $table_name,
        array(
            'order_id' => $order_id,
            'action' => $action,
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'details' => $details
        )
    );
}

// ============================================
// C) QR KOD & DOĞRULAMA
// ============================================

// QR kod oluşturma - Çoklu alternatif
function generate_warranty_qr_code($order_id, $warranty_code) {
    $verification_url = home_url('garanti-dogrula/?kod=' . urlencode($warranty_code) . '&siparis=' . $order_id);
    
    // Birden fazla QR servis alternatifi
    $qr_services = array(
        // 1. QR Server (En güvenilir)
        'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($verification_url),
        
        // 2. Google Charts (Backup)
        'https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl=' . urlencode($verification_url) . '&choe=UTF-8',
    );
    
    // İlk alternatifi dene
    return $qr_services[0];
}

// QR kod doğrulama sayfası
add_action('init', 'warranty_verification_endpoints');
function warranty_verification_endpoints() {
    add_rewrite_rule('^garanti-dogrula/?$', 'index.php?warranty_verify=1', 'top');
    add_rewrite_rule('^garanti-api/verify/?$', 'index.php?warranty_api=1', 'top');
}

add_filter('query_vars', 'add_warranty_query_vars');
function add_warranty_query_vars($vars) {
    $vars[] = 'warranty_verify';
    $vars[] = 'warranty_api';
    return $vars;
}

add_action('template_redirect', 'handle_warranty_endpoints');
function handle_warranty_endpoints() {
    if (get_query_var('warranty_verify')) {
        handle_warranty_verification_page();
    }
    
    if (get_query_var('warranty_api')) {
        handle_warranty_api_verification();
    }
}

function handle_warranty_verification_page() {
    $warranty_code = sanitize_text_field($_GET['kod']);
    $order_id = intval($_GET['siparis']);
    
    // Rate limiting
    if (!check_warranty_rate_limit(5, 60)) {
        wp_die('Too many verification attempts. Please wait a moment.');
    }
    
    $order = wc_get_order($order_id);
    $is_valid = false;
    $message = '';
    
    if ($order) {
        // WR-sipariş-no-random formatında kontrol
        $order_number = $order->get_order_number();
        $expected_pattern = 'WR-' . $order_number . '-';
        
        if (strpos($warranty_code, $expected_pattern) === 0) {
            $is_valid = true;
            $order_date = $order->get_date_created();
            $warranty_end = clone $order_date;
            $warranty_end->add(new DateInterval('P24M'));
            
            $message = array(
                'order_number' => $order->get_order_number(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'order_date' => $order_date->format('d F Y'),
                'warranty_end' => $warranty_end->format('d F Y'),
                'is_active' => $warranty_end > new DateTime()
            );
            
            log_warranty_activity($order_id, 'qr_verify_success');
        } else {
            log_warranty_activity($order_id, 'qr_verify_failed', 'Invalid code: ' . $warranty_code);
        }
    } else {
        log_warranty_activity($order_id, 'qr_verify_failed', 'Order not found');
    }
    
    // Doğrulama sayfası HTML'i
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Garanti Doğrulama - <?php echo get_bloginfo('name'); ?></title>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
            .success { color: #27ae60; border-left: 4px solid #27ae60; padding: 15px; background: #f8fff8; }
            .error { color: #e74c3c; border-left: 4px solid #e74c3c; padding: 15px; background: #fff8f8; }
            .info-box { background: #f8f9ff; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .back-btn { display: inline-block; background: #FF6000; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>Garanti Belgesi Doğrulama</h2>
            
            <?php if ($is_valid): ?>
                <div class="success">
                    <h3>✅ Garanti Belgesi Geçerli</h3>
                    <div class="info-box">
                        <p><strong>Sipariş No:</strong> #<?php echo $message['order_number']; ?></p>
                        <p><strong>Müşteri:</strong> <?php echo $message['customer_name']; ?></p>
                        <p><strong>Sipariş Tarihi:</strong> <?php echo $message['order_date']; ?></p>
                        <p><strong>Garanti Bitiş:</strong> <?php echo $message['warranty_end']; ?></p>
                        <p><strong>Durum:</strong> 
                            <?php if ($message['is_active']): ?>
                                <span style="color: #27ae60;">🟢 Aktif</span>
                            <?php else: ?>
                                <span style="color: #e74c3c;">🔴 Süresi Dolmuş</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="error">
                    <h3>❌ Geçersiz Garanti Belgesi</h3>
                    <p>Bu garanti kodu doğrulanamadı. Lütfen garanti belgenizdeki QR kodu tekrar tarayınız.</p>
                </div>
            <?php endif; ?>
            
            <a href="<?php echo home_url(); ?>" class="back-btn">← Ana Sayfaya Dön</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// API endpoint for mobile apps
function handle_warranty_api_verification() {
    header('Content-Type: application/json');
    
    if (!check_warranty_rate_limit(20, 60)) {
        echo json_encode(['success' => false, 'message' => 'Rate limit exceeded']);
        exit;
    }
    
    $warranty_code = sanitize_text_field($_GET['code']);
    $order_id = intval($_GET['order']);
    
    $order = wc_get_order($order_id);
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    
    $order_number = $order->get_order_number();
    $expected_pattern = 'WR-' . $order_number . '-';
    
    if (strpos($warranty_code, $expected_pattern) === 0) {
        $order_date = $order->get_date_created();
        $warranty_end = clone $order_date;
        $warranty_end->add(new DateInterval('P24M'));
        
        echo json_encode([
            'success' => true,
            'data' => [
                'order_number' => $order->get_order_number(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'order_date' => $order_date->format('Y-m-d'),
                'warranty_end' => $warranty_end->format('Y-m-d'),
                'is_active' => $warranty_end > new DateTime()
            ]
        ]);
        
        log_warranty_activity($order_id, 'api_verify_success');
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid warranty code']);
        log_warranty_activity($order_id, 'api_verify_failed');
    }
    
    exit;
}

// ============================================
// D) ADMIN VE MÜŞTERİ PANELİ
// ============================================

// Admin sipariş sayfasına garanti butonu
add_action('woocommerce_admin_order_data_after_order_details', 'add_warranty_button_admin');
function add_warranty_button_admin($order) {
    $cached = get_cached_warranty($order->get_id()) ? ' (Cached)' : '';
    
    echo '<p class="form-field form-field-wide">
        <a href="' . admin_url('admin-post.php?action=generate_warranty_advanced&order_id=' . $order->get_id()) . '" 
           class="button button-primary warranty-button" target="_blank">
           🛡️ Garanti Belgesi İndir' . $cached . '
        </a>
        <a href="' . admin_url('admin-post.php?action=clear_warranty_cache&order_id=' . $order->get_id()) . '" 
           class="button" style="margin-left: 10px;">
           🗑️ Cache Temizle
        </a>
    </p>';
}

// Cache temizleme
add_action('admin_post_clear_warranty_cache', 'clear_warranty_cache_admin');
function clear_warranty_cache_admin() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }
    
    $order_id = intval($_GET['order_id']);
    clear_warranty_cache($order_id);
    
    wp_redirect(admin_url('post.php?post=' . $order_id . '&action=edit'));
    exit;
}

// Müşteri tarafı garanti butonu
add_action('woocommerce_order_details_after_order_table', 'add_warranty_button_customer');
function add_warranty_button_customer($order) {
    if ($order->get_status() === 'completed') {
        $nonce = wp_create_nonce('warranty_nonce_' . $order->get_id());
        $token = generate_warranty_token($order->get_id(), $order->get_billing_email());
        
        echo '<p style="margin-top: 20px;">
            <a href="' . home_url('garanti-belgesi-gelismis/?order_id=' . $order->get_id() . '&token=' . $token) . '" 
               class="button warranty-btn-advanced" target="_blank">
               🛡️ Garanti Belgesi İndir
            </a>
        </p>
        
        <script>
        jQuery(document).ready(function($) {
            $(".warranty-btn-advanced").click(function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.text("⏳ Yükleniyor...");
                
                $.post("' . admin_url('admin-ajax.php') . '", {
                    action: "load_warranty",
                    order_id: ' . $order->get_id() . ',
                    nonce: "' . $nonce . '"
                }, function(data) {
                    var newWindow = window.open("", "_blank");
                    newWindow.document.write(data);
                    newWindow.document.close();
                    btn.text("🛡️ Garanti Belgesi İndir");
                }).fail(function() {
                    alert("Bir hata oluştu. Lütfen tekrar deneyin.");
                    btn.text("🛡️ Garanti Belgesi İndir");
                });
            });
        });
        </script>';
    }
}

// ============================================
// E) GARANTİ BELGESİ OLUŞTURMA - YENİ TEMA
// ============================================

// Ana garanti belgesi oluşturma
add_action('admin_post_generate_warranty_advanced', 'generate_warranty_certificate_advanced');
add_action('admin_post_nopriv_generate_warranty_advanced', 'generate_warranty_certificate_advanced');

function generate_warranty_certificate_advanced() {
    // Rate limiting
    if (!check_warranty_rate_limit()) {
        wp_die('Too many requests. Please wait a moment.');
    }
    
    // Token veya yetki kontrolü
    if (isset($_GET['token'])) {
        $token_data = verify_warranty_token($_GET['token']);
        if (!$token_data) {
            wp_die('Invalid or expired token.');
        }
        $order_id = $token_data['order_id'];
    } else {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized access.');
        }
        $order_id = intval($_GET['order_id']);
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die('Order not found.');
    }
    
    // Cache kontrolü
    $cached_html = get_cached_warranty($order_id);
    if ($cached_html) {
        echo $cached_html;
        log_warranty_activity($order_id, 'generate_cached');
        exit;
    }
    
    // Log activity
    log_warranty_activity($order_id, 'generate_new');
    
    // HTML oluştur ve cache'le
    $html = generate_warranty_html_content($order, true);
    set_warranty_cache($order_id, $html, 3600); // 1 saat cache
    
    echo $html;
    exit;
}

// Garanti HTML içeriği - YENİ ULTRA KOMPAKT TEMA
function generate_warranty_html_content($order, $include_qr = false) {
    // Sipariş bilgilerini al
    $order_date = $order->get_date_created();
    $customer = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $email = $order->get_billing_email();
    $phone = $order->get_billing_phone();
    
    // Garanti bitiş tarihi hesapla
    $warranty_start = $order_date->format('d M Y');
    $warranty_end_date = clone $order_date;
    $warranty_end_date->add(new DateInterval('P24M'));
    $warranty_end = $warranty_end_date->format('d M Y');
    
    // Ürün bilgileri - Akıllı ürün listesi
    $items = $order->get_items();
    $product_rows = '';
    $item_count = 1;
    
    foreach ($items as $item) {
        $product = $item->get_product();
        $product_name = $item->get_name();
        $quantity = $item->get_quantity();
        
        
        
        // Ürün adını akıllı kısaltma (40 karakter)
        if (strlen($product_name) > 40) {
            $product_name = substr($product_name, 0, 37) . '...';
        }
        
        $product_rows .= '<tr>
            <td class="text-center">' . $item_count . '</td>
            <td class="product-name">' . htmlspecialchars($product_name) . '</td>
            <td class="text-center">' . $quantity . '</td>
            
        </tr>';
        $item_count++;
    }
    
    // Garanti kodu oluştur
    $warranty_code = 'WR-' . $order->get_order_number() . '-' . strtoupper(substr(md5(uniqid() . $order->get_id()), 0, 6));
    
    // QR kod
    $qr_url = $include_qr ? generate_warranty_qr_code($order->get_id(), $warranty_code) : '';
    $verification_url = home_url('garanti-dogrula/?kod=' . urlencode($warranty_code) . '&siparis=' . $order->get_id());
    
    // Güvenlik token
    $security_hash = strtoupper(substr(md5($warranty_code . $order->get_billing_email() . SECURE_AUTH_KEY), 0, 8));
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Garanti Belgesi - <?php echo $warranty_code; ?></title>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
            
            * { margin: 0; padding: 0; box-sizing: border-box; }
            
            body {
                font-family: 'Inter', Arial, sans-serif;
                background: #f5f5f5;
                color: #1a1a1a;
                line-height: 1.4;
                padding: 15px;
                font-size: 14px;
            }
            
            .warranty-container {
                max-width: 210mm;
                margin: 0 auto;
                background: white;
                min-height: 297mm;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
                display: flex;
                flex-direction: column;
            }
            
            /* HEADER - Horizontal Layout like Invoice */
            .warranty-header {
                background: #f8f9fa;
                color: #666;
                padding: 20px 25px;
                border-top: 3px solid #FF6000;
                border-bottom: 1px solid #ddd;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .company-info h1 {
                font-size: 24px;
                font-weight: 700;
                color: #FF6000;
                margin-bottom: 8px;
            }
            
            .company-info p {
                font-size: 11px;
                color: #666;
                margin-bottom: 4px;
            }
            
            .warranty-meta {
                text-align: right;
            }
            
            .warranty-number {
                font-size: 16px;
                font-weight: 600;
                background: rgba(255, 96, 0, 0.15);
                color: #FF6000;
                padding: 8px 12px;
                border-radius: 4px;
                border: 1px solid #FF6000;
            }
            
            /* BODY CONTENT - Ultra Compact */
            .warranty-body {
                flex: 1;
                padding: 15px 20px;
                display: flex;
                flex-direction: column;
            }
            
            /* Customer Info - Single Line Horizontal */
            .customer-info {
                background: #f8f9fa;
                padding: 6px 15px;
                border-radius: 6px;
                margin-bottom: 12px;
                border-left: 3px solid #FF6000;
            }
            
            .customer-info h3 {
                font-size: 10px;
                font-weight: 600;
                color: #333;
                margin-bottom: 4px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .customer-details {
                display: flex;
                gap: 10px;
                font-size: 10px;
                align-items: center;
                overflow: hidden;
            }
            
            .customer-item {
                display: flex;
                align-items: center;
                gap: 3px;
                white-space: nowrap;
                flex-shrink: 0;
            }
            
            .customer-label {
                font-weight: 500;
                color: #666;
                font-size: 9px;
            }
            
            .customer-value {
                font-weight: 600;
                color: #1a1a1a;
                font-size: 10px;
            }
            
            /* Warranty Period - Ultra Horizontal Single Line */
            .warranty-period {
                background: white;
                border: 1px solid #FF6000;
                border-radius: 6px;
                padding: 8px 15px;
                margin-bottom: 12px;
                display: flex;
                align-items: center;
                gap: 15px;
                flex-wrap: wrap;
            }
            
            .warranty-period h2 {
                font-size: 14px;
                font-weight: 700;
                color: #FF6000;
                margin: 0;
                white-space: nowrap;
            }
            
            .period-dates {
                display: flex;
                align-items: center;
                gap: 8px;
                flex: 1;
            }
            
            .date-item {
                display: flex;
                align-items: center;
                gap: 4px;
                font-size: 11px;
                color: #1a1a1a;
            }
            
            .date-label {
                font-size: 9px;
                color: #666;
                font-weight: 500;
            }
            
            .date-value {
                font-weight: 600;
                color: #1a1a1a;
            }
            
            .arrow {
                font-size: 14px;
                color: #FF6000;
                font-weight: bold;
                margin: 0 4px;
            }
            
            .warranty-status {
                background: rgba(255, 96, 0, 0.1);
                color: #FF6000;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 10px;
                font-weight: 600;
                text-transform: uppercase;
                border: 1px solid #FF6000;
                white-space: nowrap;
            }
            
            /* Products Table - Ultra Compact */
            .products-section {
                margin-bottom: 12px;
            }
            
            .products-table {
                width: 100%;
                border-collapse: collapse;
                border: 1px solid #ddd;
                border-radius: 6px;
                overflow: hidden;
                margin-bottom: 12px;
            }
            
            .products-table thead {
                background: #f8f9fa;
                color: #666;
                border-bottom: 2px solid #ddd;
            }
            
            .products-table th {
                padding: 8px 6px;
                font-weight: 600;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                text-align: left;
            }
            
            .products-table tbody tr {
                border-bottom: 1px solid #eee;
            }
            
            .products-table tbody tr:nth-child(even) {
                background: #fafafa;
            }
            
            .products-table td {
                padding: 6px;
                font-size: 10px;
                vertical-align: top;
            }
            
            .product-name {
                font-weight: 600;
                color: #1a1a1a;
                max-width: 200px;
                word-wrap: break-word;
            }
            
            .text-center { text-align: center; }
            
            /* Terms & QR Section - Side by Side Ultra Compact */
            .terms-verification-section {
                display: flex;
                gap: 15px;
                margin-bottom: 12px;
                margin-top: auto;
            }
            
            .warranty-terms {
                background: #f8f9fa;
                padding: 12px 15px;
                border-radius: 6px;
                border-left: 3px solid #FF6000;
                flex: 2;
            }
            
            .warranty-terms h3 {
                font-size: 11px;
                font-weight: 600;
                color: #333;
                margin-bottom: 8px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .terms-list {
                list-style: none;
                padding: 0;
            }
            
            .terms-list li {
                font-size: 10px;
                line-height: 1.4;
                margin-bottom: 4px;
                padding-left: 12px;
                position: relative;
                color: #333;
            }
            
            .terms-list li::before {
                content: '•';
                color: #FF6000;
                font-weight: bold;
                position: absolute;
                left: 0;
            }
            
            /* QR & Verification - Ultra Compact Side */
            .verification-panel {
                flex: 1;
                background: #f8f9fa;
                padding: 12px 15px;
                border-radius: 6px;
                border-left: 3px solid #FF6000;
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .qr-code {
                width: 70px;
                height: 70px;
                background: white;
                border: 2px solid #FF6000;
                border-radius: 6px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 6px;
                overflow: hidden;
            }
            
            .qr-code img {
                width: 90%;
                height: 90%;
                object-fit: contain;
            }
            
            .qr-fallback {
                font-size: 8px;
                color: #FF6000;
                font-weight: 600;
                text-decoration: none;
                text-align: center;
            }
            
            .qr-label {
                font-size: 9px;
                color: #666;
                font-weight: 500;
                margin-bottom: 6px;
            }
            
            .verification-info {
                width: 100%;
            }
            
            .verification-info h4 {
                font-size: 11px;
                font-weight: 600;
                color: #333;
                margin-bottom: 6px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .verification-code {
                font-family: 'Courier New', monospace;
                font-size: 12px;
                font-weight: bold;
                color: #FF6000;
                background: rgba(255, 96, 0, 0.1);
                padding: 4px 6px;
                border-radius: 4px;
                border: 1px solid #FF6000;
                display: inline-block;
                margin-bottom: 6px;
            }
            
            .verification-url {
                font-size: 8px;
                color: #666;
                line-height: 1.3;
            }
            
            /* FOOTER - Ultra Compact */
            .warranty-footer {
                margin-top: auto;
                background: #f8f9fa;
                padding: 12px 20px;
                border-top: 1px solid #ddd;
                font-size: 10px;
                color: #666;
                text-align: center;
            }
            
            .footer-note {
                background: white;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                padding: 10px;
                margin-bottom: 10px;
                text-align: left;
                line-height: 1.4;
            }
            
            .footer-content {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .footer-left {
                text-align: left;
            }
            
            .footer-right {
                text-align: right;
                font-size: 9px;
                opacity: 0.7;
            }
            
            .footer-company {
                font-weight: 600;
                color: #1a1a1a;
                margin-bottom: 2px;
                font-size: 10px;
            }
            
            /* Print Button */
            .print-button {
                position: fixed;
                top: 20px;
                right: 20px;
                background: #f8f9fa;
                color: #666;
                border: 2px solid #FF6000;
                padding: 12px 20px;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 600;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                transition: all 0.3s ease;
                z-index: 1000;
                font-size: 12px;
            }
            
            .print-button:hover {
                background: #FF6000;
                color: white;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(255, 96, 0, 0.3);
            }
            
            /* Mobile responsive */
            @media (max-width: 768px) {
                .warranty-header {
                    flex-direction: column;
                    gap: 15px;
                    text-align: center;
                }
                
                .warranty-meta {
                    text-align: center;
                }
                
                .customer-details {
                    flex-direction: column;
                    gap: 4px;
                    align-items: flex-start;
                }
                
                .customer-item {
                    gap: 5px;
                }
                
                .warranty-period {
                    flex-wrap: wrap;
                    gap: 8px;
                    justify-content: center;
                    text-align: center;
                }
                
                .warranty-period h2 {
                    order: 1;
                    width: 100%;
                    margin-bottom: 5px;
                }
                
                .period-dates {
                    order: 2;
                    flex-wrap: wrap;
                    justify-content: center;
                }
                
                .warranty-status {
                    order: 3;
                }
                
                .products-table th,
                .products-table td {
                    padding: 6px 4px;
                    font-size: 10px;
                }
                
                .product-name {
                    max-width: 120px;
                }
                
                .terms-verification-section {
                    flex-direction: column;
                    gap: 15px;
                }
                
                .verification-panel {
                    flex-direction: row;
                    text-align: left;
                    gap: 15px;
                    align-items: center;
                }
                
                .qr-code {
                    flex-shrink: 0;
                    margin-bottom: 0;
                }
                
                .verification-info {
                    text-align: left;
                }
            }
            
            /* Print optimization */
            @media print {
                body { 
                    background: white !important; 
                    padding: 0 !important;
                    font-size: 12px !important;
                }
                
                .warranty-container { 
                    box-shadow: none !important;
                    max-width: none !important;
                    margin: 0 !important;
                    min-height: auto !important;
                }
                
                .print-button { display: none !important; }
                
                .warranty-header {
                    background: white !important;
                    color: #666 !important;
                    border-top: 3px solid #FF6000 !important;
                    border-bottom: 1px solid #ddd !important;
                    display: flex !important;
                    justify-content: space-between !important;
                    align-items: center !important;
                    padding: 20px 25px !important;
                }
                
                .warranty-body {
                    padding: 15px 20px !important;
                }
                
                .company-info h1 {
                    color: #FF6000 !important;
                }
                
                .warranty-number {
                    background: white !important;
                    color: #FF6000 !important;
                    border: 1px solid #FF6000 !important;
                }
                
                .customer-info {
                    background: white !important;
                    border: 1px solid #ddd !important;
                    border-left: 3px solid #FF6000 !important;
                    padding: 6px 15px !important;
                }
                
                .warranty-period {
                    background: white !important;
                    border: 1px solid #FF6000 !important;
                    display: flex !important;
                    align-items: center !important;
                    gap: 15px !important;
                    padding: 8px 15px !important;
                }
                
                .warranty-status {
                    background: white !important;
                    color: #FF6000 !important;
                    border: 1px solid #FF6000 !important;
                }
                
                .products-table {
                    border: 1px solid #ddd !important;
                }
                
                .products-table thead {
                    background: white !important;
                    color: #666 !important;
                    border-bottom: 2px solid #ddd !important;
                }
                
                .products-table tbody tr:nth-child(even) {
                    background: #fafafa !important;
                    -webkit-print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }
                
                .warranty-terms {
                    background: white !important;
                    border: 1px solid #ddd !important;
                    border-left: 3px solid #FF6000 !important;
                }
                
                .verification-panel {
                    background: white !important;
                    border: 1px solid #ddd !important;
                    border-left: 3px solid #FF6000 !important;
                }
                
                .qr-code {
                    width: 70px !important;
                    height: 70px !important;
                    background: white !important;
                    border: 2px solid #FF6000 !important;
                }
                
                .warranty-footer {
                    background: white !important;
                    color: #666 !important;
                    border-top: 1px solid #ddd !important;
                    padding: 12px 20px !important;
                }
                
                .footer-note {
                    background: white !important;
                    border: 1px solid #e0e0e0 !important;
                    padding: 10px !important;
                }
                
                @page {
                    margin: 15mm !important;
                    size: A4 !important;
                }
            }
        </style>
        <script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
            
            function printWarranty() {
                window.print();
            }
        </script>
    </head>
    <body>
        <button class="print-button" onclick="printWarranty()">🖨️ PDF İndir</button>
        
        <div class="warranty-container">
            <!-- HEADER - Horizontal Layout like Invoice -->
            <div class="warranty-header">
                <div class="company-info">
                    <h1><?php echo get_bloginfo('name'); ?></h1>
                    <p>E-posta: <?php echo get_bloginfo('admin_email'); ?></p>
                    <p>Web: <?php echo home_url(); ?></p>
                </div>
                <div class="warranty-meta">
                    <div class="warranty-number"><?php echo $warranty_code; ?></div>
                </div>
            </div>

            <!-- BODY -->
            <div class="warranty-body">
                <!-- Customer Info - Single Line Horizontal -->
                <div class="customer-info">
                    <h3>Müşteri Bilgileri</h3>
                    <div class="customer-details">
                        <div class="customer-item">
                            <span class="customer-label">Ad:</span>
                            <span class="customer-value"><?php echo htmlspecialchars($customer); ?></span>
                        </div>
                        <div class="customer-item">
                            <span class="customer-label">Email:</span>
                            <span class="customer-value"><?php echo htmlspecialchars($email); ?></span>
                        </div>
                        <div class="customer-item">
                            <span class="customer-label">Tel:</span>
                            <span class="customer-value"><?php echo htmlspecialchars($phone); ?></span>
                        </div>
                        <div class="customer-item">
                            <span class="customer-label">Sipariş:</span>
                            <span class="customer-value">#<?php echo $order->get_order_number(); ?></span>
                        </div>
                        <div class="customer-item">
                            <span class="customer-label">Tarih:</span>
                            <span class="customer-value"><?php echo $warranty_start; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Warranty Period - Ultra Horizontal Single Line -->
                <div class="warranty-period">
                    <h2>Garanti: 24 Ay</h2>
                    <div class="period-dates">
                        <div class="date-item">
                            <span class="date-label">Başlangıç:</span>
                            <span class="date-value"><?php echo $warranty_start; ?></span>
                        </div>
                        <div class="arrow">→</div>
                        <div class="date-item">
                            <span class="date-label">Bitiş:</span>
                            <span class="date-value"><?php echo $warranty_end; ?></span>
                        </div>
                    </div>
                    <div class="warranty-status"><?php echo ($warranty_end_date > new DateTime()) ? 'Aktif' : 'Süresi Dolmuş'; ?></div>
                </div>

                <!-- Products Table - Ultra Compact -->
                <div class="products-section">
                    <table class="products-table">
                        <thead>
                            <tr>
                                <th style="width: 6%;">#</th>
                                <th style="width: 40%;">Ürün Adı</th>
                                <th style="width: 8%;">Adet</th>
                                
                            </tr>
                        </thead>
                        <tbody>
                            <?php echo $product_rows; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Terms & QR Side by Side - Ultra Compact -->
                <div class="terms-verification-section">
                    <div class="warranty-terms">
                        <h3>Garanti Koşulları</h3>
                        <ul class="terms-list">
                            <li>Bu garanti belgesi, ürünün satın alındığı tarihten itibaren 24 ay süreyle geçerlidir.</li>
                            <li>Garanti kapsamında üretici hatalarından kaynaklanan arızalar giderilir.</li>
                            <li>Fiziksel darbe, su hasarı ve yanlış kullanımdan kaynaklanan arızalar garanti kapsamı dışındadır.</li>
                            <li>Garanti hizmeti için bu belgenin asıl nüshasının ibrazı gereklidir.</li>
                            <li>Yetkisiz servislerde yapılan müdahaleler garantiyi geçersiz kılar.</li>
                            <li>Garanti süresi içinde meydana gelen arızalar ücretsiz onarılır veya ürün değiştirilir.</li>
                        </ul>
                    </div>

                    <div class="verification-panel">
                        <div class="qr-code">
                            <?php if ($qr_url): ?>
                                <img src="<?php echo $qr_url; ?>" alt="QR Kod" 
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" />
                                <a href="<?php echo $verification_url; ?>" class="qr-fallback" style="display: none;" target="_blank">
                                    🔍 Online<br>Doğrula
                                </a>
                            <?php else: ?>
                                <a href="<?php echo $verification_url; ?>" class="qr-fallback" target="_blank">
                                    🔍 Online<br>Doğrulama
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="qr-label">Online Doğrulama</div>
                        
                        <div class="verification-info">
                            <h4>Doğrulama</h4>
                            <div class="verification-code"><?php echo $warranty_code; ?></div>
                            <div class="verification-url">
                                QR kodu tarayın veya <?php echo parse_url(home_url(), PHP_URL_HOST); ?>/garanti-dogrula adresinden doğrulayın
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER - Ultra Compact -->
            <div class="warranty-footer">
                <div class="footer-note">
                    Garanti şartları ve koşulları hakkında detaylı bilgi için müşteri hizmetlerimizle iletişime geçebilirsiniz. 
                    <strong>+90 539 103 0333</strong> numaradan bize ulaşabilirsiniz.
                </div>
                <div class="footer-content">
                    <div class="footer-left">
                        <div class="footer-company"><?php echo get_bloginfo('name'); ?></div>
                        <div><?php echo get_bloginfo('description'); ?></div>
                    </div>
                    <div class="footer-right">
                        <div>Sayfa 1/1</div>
                        <div>Güvenlik: <?php echo $security_hash; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    
    return ob_get_clean();
}

// ============================================
// F) REWRITE RULES VE ENDPOINTS
// ============================================

// Rewrite rules
add_action('init', 'add_warranty_rewrite_rules');
function add_warranty_rewrite_rules() {
    add_rewrite_rule('^garanti-belgesi/?$', 'index.php?warranty_page=1', 'top');
    add_rewrite_rule('^garanti-belgesi-gelismis/?$', 'index.php?warranty_page_advanced=1', 'top');
}

add_filter('query_vars', 'add_warranty_query_vars_main');
function add_warranty_query_vars_main($vars) {
    $vars[] = 'warranty_page';
    $vars[] = 'warranty_page_advanced';
    return $vars;
}

add_action('template_redirect', 'load_warranty_templates');
function load_warranty_templates() {
    if (get_query_var('warranty_page_advanced')) {
        if (isset($_GET['order_id']) && isset($_GET['token'])) {
            generate_warranty_certificate_advanced();
        } else {
            wp_die('Invalid parameters.');
        }
    }
}

// ============================================
// G) ADMIN STİLLERİ VE DEBUG
// ============================================

// Admin stilleri
add_action('admin_head', 'warranty_admin_styles_advanced');
function warranty_admin_styles_advanced() {
    ?>
    <style>
        .warranty-button {
            background: linear-gradient(135deg, #FFB088 0%, #FFA07A 100%) !important;
            border-color: #FF6000 !important;
            color: #8B4513 !important;
            text-shadow: none !important;
            box-shadow: 0 2px 8px rgba(255, 96, 0, 0.3) !important;
            font-weight: 600 !important;
        }
        .warranty-button:hover {
            background: linear-gradient(135deg, #FF9A66 0%, #FF8C5A 100%) !important;
            border-color: #e55a2b !important;
            color: #654321 !important;
        }
    </style>
    <?php
}

// Aktivasyon hook ve rewrite rules yenileme
register_activation_hook(__FILE__, 'warranty_activation_advanced');
function warranty_activation_advanced() {
    add_warranty_rewrite_rules();
    warranty_verification_endpoints();
    flush_rewrite_rules();
}

// WordPress yüklendiğinde rewrite rules'ı kontrol et
add_action('wp_loaded', 'warranty_check_rewrite_rules');
function warranty_check_rewrite_rules() {
    $rules = get_option('rewrite_rules');
    if (!isset($rules['^garanti-dogrula/?$'])) {
        add_warranty_rewrite_rules();
        warranty_verification_endpoints();
        flush_rewrite_rules();
    }
}

// Manuel rewrite rules yenileme fonksiyonu
function warranty_flush_rewrite_rules_manual() {
    add_warranty_rewrite_rules();
    warranty_verification_endpoints();
    flush_rewrite_rules();
    
    // Debug için
    if (current_user_can('manage_options')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>Garanti sistemi rewrite rules yenilendi!</p></div>';
        });
    }
}

// Admin menüsüne debug seçeneği ekle
add_action('admin_menu', 'warranty_admin_menu');
function warranty_admin_menu() {
    if (current_user_can('manage_options')) {
        add_submenu_page(
            'tools.php',
            'Garanti Sistemi Debug',
            'Garanti Debug',
            'manage_options',
            'warranty-debug',
            'warranty_debug_page'
        );
    }
}

function warranty_debug_page() {
    if (isset($_POST['flush_rules'])) {
        warranty_flush_rewrite_rules_manual();
    }
    
    if (isset($_POST['clear_all_cache'])) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_warranty_cache_%'");
        echo '<div class="notice notice-success"><p>Tüm garanti cache\'i temizlendi!</p></div>';
    }
    
    $verification_url = home_url('garanti-dogrula/?kod=WR-2025-000001&siparis=1');
    $rules = get_option('rewrite_rules');
    
    ?>
    <div class="wrap">
        <h1>Garanti Sistemi Debug - Güncel Tema</h1>
        
        <div class="card">
            <h2>Sistem Durumu</h2>
            <p><strong>Tema:</strong> ✅ Ultra Kompakt Horizontal</p>
            <p><strong>Cache:</strong> ✅ Aktif (v5)</p>
            <p><strong>QR Doğrulama:</strong> ✅ Aktif</p>
            <p><strong>Rate Limiting:</strong> ✅ Aktif</p>
            
            <p><strong>Rewrite Rules:</strong></p>
            <?php 
            if (isset($rules['^garanti-dogrula/?$'])) {
                echo "✅ garanti-dogrula rule mevcut<br>";
            } else {
                echo "❌ garanti-dogrula rule bulunamadı!<br>";
            }
            
            if (isset($rules['^garanti-api/verify/?$'])) {
                echo "✅ garanti-api rule mevcut<br>";
            } else {
                echo "❌ garanti-api rule bulunamadı!<br>";
            }
            ?>
            
            <form method="post" style="margin-top: 15px;">
                <input type="hidden" name="flush_rules" value="1">
                <?php submit_button('Rewrite Rules Yenile', 'primary'); ?>
            </form>
            
            <form method="post" style="margin-top: 10px;">
                <input type="hidden" name="clear_all_cache" value="1">
                <?php submit_button('Tüm Cache Temizle', 'secondary'); ?>
            </form>
        </div>
        
        <div class="card">
            <h2>Test URL</h2>
            <p><strong>Doğrulama Test:</strong> <a href="<?php echo $verification_url; ?>" target="_blank"><?php echo $verification_url; ?></a></p>
        </div>
    </div>
    <?php
}
