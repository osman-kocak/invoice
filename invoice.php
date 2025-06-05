/**
 * Gelişmiş PDF Fatura Sistemi
 * Performans + Güvenlik + Professional Tasarım
 * functions.php dosyanıza ekleyiniz
 */

// ============================================
// A) PERFORMANS OPTİMİZASYONU
// ============================================

// Cache sistemi - versiyonlu
function get_invoice_cache_key($order_id) {
    return 'invoice_cache_v2_' . $order_id;
}

function get_cached_invoice($order_id) {
    return get_transient(get_invoice_cache_key($order_id));
}

function set_invoice_cache($order_id, $html, $expiry = 3600) {
    set_transient(get_invoice_cache_key($order_id), $html, $expiry);
}

function clear_invoice_cache($order_id) {
    delete_transient(get_invoice_cache_key($order_id));
}

// AJAX endpoint
add_action('wp_ajax_load_invoice', 'ajax_load_invoice');
add_action('wp_ajax_nopriv_load_invoice', 'ajax_load_invoice');

function ajax_load_invoice() {
    // Rate limiting check
    if (!check_invoice_rate_limit()) {
        wp_die('Too many requests. Please wait a moment.');
    }
    
    $order_id = intval($_POST['order_id']);
    $nonce = sanitize_text_field($_POST['nonce']);
    
    if (!wp_verify_nonce($nonce, 'invoice_nonce_' . $order_id)) {
        wp_die('Security check failed.');
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die('Order not found.');
    }
    
    // Cache kontrolü
    $cached_html = get_cached_invoice($order_id);
    if ($cached_html) {
        echo $cached_html;
        wp_die();
    }
    
    // Log activity
    log_invoice_activity($order_id, 'ajax_load');
    
    // HTML oluştur ve cache'le
    $html = generate_invoice_html_content($order);
    set_invoice_cache($order_id, $html, 1800); // 30 dakika cache
    
    echo $html;
    wp_die();
}

// ============================================
// B) GÜVENLİK & DOĞRULAMA
// ============================================

// JWT Token sistemi
function generate_invoice_token($order_id, $customer_email) {
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
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, 'invoice_secret_key_' . SECURE_AUTH_KEY, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

function verify_invoice_token($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    
    list($base64Header, $base64Payload, $base64Signature) = $parts;
    
    // Signature doğrula
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, 'invoice_secret_key_' . SECURE_AUTH_KEY, true);
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
function check_invoice_rate_limit($max_requests = 10, $time_window = 300) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = 'invoice_rate_limit_' . md5($ip);
    
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
function log_invoice_activity($order_id, $action, $details = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'invoice_logs';
    
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
// C) ADMIN VE MÜŞTERİ PANELİ
// ============================================

// Admin sipariş sayfasına fatura butonu
add_action('woocommerce_admin_order_data_after_order_details', 'add_invoice_button_admin');
function add_invoice_button_admin($order) {
    $cached = get_cached_invoice($order->get_id()) ? ' (Cached)' : '';
    
    echo '<p class="form-field form-field-wide">
        <a href="' . admin_url('admin-post.php?action=generate_invoice_pdf&order_id=' . $order->get_id()) . '" 
           class="button button-primary invoice-button" target="_blank">
           📄 PDF Fatura İndir' . $cached . '
        </a>
        <a href="' . admin_url('admin-post.php?action=clear_invoice_cache&order_id=' . $order->get_id()) . '" 
           class="button" style="margin-left: 10px;">
           🗑️ Cache Temizle
        </a>
    </p>';
}

// Cache temizleme
add_action('admin_post_clear_invoice_cache', 'clear_invoice_cache_admin');
function clear_invoice_cache_admin() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }
    
    $order_id = intval($_GET['order_id']);
    clear_invoice_cache($order_id);
    
    wp_redirect(admin_url('post.php?post=' . $order_id . '&action=edit'));
    exit;
}

// Müşteri tarafı fatura butonu
add_action('woocommerce_order_details_after_order_table', 'add_invoice_button_customer');
function add_invoice_button_customer($order) {
    if ($order->get_status() === 'completed' || $order->get_status() === 'processing') {
        $nonce = wp_create_nonce('invoice_nonce_' . $order->get_id());
        $token = generate_invoice_token($order->get_id(), $order->get_billing_email());
        
        echo '<p style="margin-top: 20px;">
            <a href="' . home_url('pdf-fatura/?order_id=' . $order->get_id() . '&token=' . $token) . '" 
               class="button invoice-btn-download" target="_blank">
               📄 PDF Fatura İndir
            </a>
        </p>
        
        <script>
        jQuery(document).ready(function($) {
            $(".invoice-btn-download").click(function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.text("⏳ Hazırlanıyor...");
                
                $.post("' . admin_url('admin-ajax.php') . '", {
                    action: "load_invoice",
                    order_id: ' . $order->get_id() . ',
                    nonce: "' . $nonce . '"
                }, function(data) {
                    var newWindow = window.open("", "_blank");
                    newWindow.document.write(data);
                    newWindow.document.close();
                    btn.text("📄 PDF Fatura İndir");
                }).fail(function() {
                    alert("Bir hata oluştu. Lütfen tekrar deneyin.");
                    btn.text("📄 PDF Fatura İndir");
                });
            });
        });
        </script>';
    }
}

// ============================================
// D) PDF FATURA OLUŞTURMA
// ============================================

// Ana fatura oluşturma
add_action('admin_post_generate_invoice_pdf', 'generate_invoice_certificate');
add_action('admin_post_nopriv_generate_invoice_pdf', 'generate_invoice_certificate');

function generate_invoice_certificate() {
    // Rate limiting
    if (!check_invoice_rate_limit()) {
        wp_die('Too many requests. Please wait a moment.');
    }
    
    // Token veya yetki kontrolü
    if (isset($_GET['token'])) {
        $token_data = verify_invoice_token($_GET['token']);
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
    $cached_html = get_cached_invoice($order_id);
    if ($cached_html) {
        echo $cached_html;
        log_invoice_activity($order_id, 'generate_cached');
        exit;
    }
    
    // Log activity
    log_invoice_activity($order_id, 'generate_new');
    
    // HTML oluştur ve cache'le
    $html = generate_invoice_html_content($order);
    set_invoice_cache($order_id, $html, 3600); // 1 saat cache
    
    echo $html;
    exit;
}

// Fatura HTML içeriği
function generate_invoice_html_content($order) {
    // Sipariş bilgilerini al
    $order_date = $order->get_date_created();
    $order_number = $order->get_order_number();
    $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $customer_email = $order->get_billing_email();
    $customer_phone = $order->get_billing_phone();
    
    // Fatura numarası oluştur - Yeni format: INV-sipariş-no-randnumber
    $random_code = strtoupper(substr(md5(uniqid() . $order->get_id()), 0, 6));
    $invoice_number = 'INV-' . $order_number . '-' . $random_code;
    
    // Ürün bilgileri
    $items = $order->get_items();
    $subtotal = 0;
    $product_rows = '';
    $item_count = 1;
    
    foreach ($items as $item) {
        $product_name = $item->get_name();
        $quantity = $item->get_quantity();
        $unit_price = $item->get_total() / $quantity;
        $line_total = $item->get_total();
        $subtotal += $line_total;
        
        $product_rows .= '<tr>
            <td style="padding: 8px; border-bottom: 1px solid #eee; text-align: center;">' . $item_count . '</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: 500;">' . htmlspecialchars($product_name) . '</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee; text-align: center;">' . $quantity . '</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee; text-align: right;">₺' . number_format($unit_price, 2) . '</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee; text-align: right; font-weight: 600;">₺' . number_format($line_total, 2) . '</td>
        </tr>';
        $item_count++;
    }
    
    // Vergi hesaplamaları
    $tax_amount = $order->get_total_tax();
    $shipping_cost = $order->get_shipping_total();
    $total = $order->get_total();
    
    // Güvenlik kodu
    $security_hash = strtoupper(substr(md5($invoice_number . $customer_email . SECURE_AUTH_KEY), 0, 8));
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Fatura - <?php echo $invoice_number; ?></title>
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
            
            .invoice-container {
                max-width: 210mm;
                margin: 0 auto;
                background: white;
                min-height: 297mm;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
                display: flex;
                flex-direction: column;
            }
            
            /* HEADER - Kompakt */
            .invoice-header {
                background: #f8f9fa;
                color: #666;
                padding: 20px 25px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-top: 3px solid #FF6000;
                border-bottom: 1px solid #ddd;
            }
            
            .company-info h1 {
                font-size: 24px;
                font-weight: 700;
                color: #FF6000;
                margin-bottom: 5px;
            }
            
            .company-info p {
                font-size: 11px;
                color: #666;
                margin-bottom: 2px;
            }
            
            .invoice-meta {
                text-align: right;
            }
            
            .invoice-number {
                font-size: 16px;
                font-weight: 600;
                background: rgba(255, 96, 0, 0.15);
                color: #FF6000;
                padding: 8px 12px;
                border-radius: 4px;
                border: 1px solid #FF6000;
                display: inline-block;
            }
            
            /* BODY CONTENT */
            .invoice-body {
                flex: 1;
                padding: 20px 25px;
                display: flex;
                flex-direction: column;
            }
            
            /* Müşteri Bilgileri - Tek satır, kompakt */
            .customer-info {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 20px;
                border-left: 3px solid #FF6000;
            }
            
            .customer-info h3 {
                font-size: 13px;
                font-weight: 600;
                color: #333;
                margin-bottom: 8px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .customer-details {
                display: grid;
                grid-template-columns: 2fr 2fr 1.5fr;
                gap: 15px;
                font-size: 12px;
            }
            
            .detail-item {
                display: flex;
                flex-direction: column;
            }
            
            .detail-label {
                font-weight: 500;
                color: #666;
                font-size: 11px;
                margin-bottom: 2px;
            }
            
            .detail-value {
                font-weight: 600;
                color: #1a1a1a;
            }
            
            /* ÜRÜN TABLOSU - Kompakt */
            .items-section {
                flex: 1;
                margin-bottom: 20px;
            }
            
            .items-table {
                width: 100%;
                border-collapse: collapse;
                border: 1px solid #ddd;
                border-radius: 6px;
                overflow: hidden;
            }
            
            .items-table thead {
                background: #f8f9fa;
                color: #666;
                border-bottom: 2px solid #ddd;
            }
            
            .items-table th {
                padding: 10px 8px;
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            
            .items-table tbody tr {
                border-bottom: 1px solid #eee;
            }
            
            .items-table tbody tr:nth-child(even) {
                background: #fafafa;
            }
            
            .items-table tbody tr:hover {
                background: #fff5f0;
            }
            
            .items-table td {
                font-size: 12px;
                vertical-align: middle;
            }
            
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            
            /* TOPLAM BÖLÜMÜ */
            .totals-section {
                display: flex;
                justify-content: flex-end;
                margin-top: auto;
            }
            
            .totals-table {
                width: 250px;
                border: 1px solid #ddd;
                border-radius: 6px;
                overflow: hidden;
            }
            
            .totals-table tr {
                border-bottom: 1px solid #eee;
            }
            
            .totals-table td {
                padding: 8px 12px;
                font-size: 12px;
            }
            
            .totals-table .label {
                font-weight: 500;
                color: #666;
            }
            
            .totals-table .value {
                text-align: right;
                font-weight: 600;
                color: #1a1a1a;
            }
            
            .totals-table .final-row {
                background: #f8f9fa;
                color: #666;
                font-weight: 700;
                font-size: 14px;
                border-top: 2px solid #ddd;
            }
            
            .totals-table .final-row .value {
                color: #FF6000;
            }
            
            /* FOOTER - Her sayfa için */
            .invoice-footer {
                margin-top: auto;
                background: #f8f9fa;
                padding: 15px 25px;
                border-top: 1px solid #ddd;
                font-size: 11px;
                color: #666;
                text-align: center;
                page-break-inside: avoid;
            }
            
            .footer-note {
                background: white;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                padding: 12px;
                margin-bottom: 12px;
                text-align: left;
                line-height: 1.5;
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
                font-size: 10px;
                opacity: 0.7;
            }
            
            .footer-company {
                font-weight: 600;
                color: #1a1a1a;
                margin-bottom: 2px;
            }
            
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
                .invoice-header {
                    flex-direction: column;
                    gap: 15px;
                    text-align: center;
                }
                
                .customer-details {
                    grid-template-columns: 1fr;
                    gap: 10px;
                }
                
                .items-table th,
                .items-table td {
                    padding: 6px 4px;
                    font-size: 10px;
                }
                
                .totals-table { width: 100%; }
            }
            
            /* Print optimizasyonu */
            @media print {
                body { 
                    background: white !important; 
                    padding: 0 !important;
                    font-size: 12px !important;
                }
                
                .invoice-container { 
                    box-shadow: none !important;
                    max-width: none !important;
                    margin: 0 !important;
                    min-height: auto !important;
                }
                
                .print-button { display: none !important; }
                
                /* Print'te ekranla aynı layout, sadece gri → beyaz */
                .invoice-header {
                    background: white !important;
                    color: #666 !important;
                    border-top: 3px solid #FF6000 !important;
                    border-bottom: 1px solid #ddd !important;
                }
                
                .company-info h1 {
                    color: #FF6000 !important;
                }
                
                .invoice-number {
                    background: white !important;
                    color: #FF6000 !important;
                    border: 1px solid #FF6000 !important;
                }
                
                .customer-info {
                    background: white !important;
                    border: 1px solid #ddd !important;
                    border-left: 3px solid #FF6000 !important;
                }
                
                .customer-info h3 {
                    color: #333 !important;
                }
                
                .detail-label {
                    color: #666 !important;
                }
                
                .detail-value {
                    color: #1a1a1a !important;
                }
                
                .items-table {
                    border: 1px solid #ddd !important;
                }
                
                .items-table thead {
                    background: white !important;
                    color: #666 !important;
                    border-bottom: 2px solid #ddd !important;
                }
                
                .items-table tbody tr:nth-child(even) {
                    background: #fafafa !important;
                    -webkit-print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }
                
                .items-table tbody tr:hover {
                    background: white !important;
                }
                
                .totals-table {
                    border: 1px solid #ddd !important;
                }
                
                .totals-table .final-row {
                    background: white !important;
                    color: #666 !important;
                    border-top: 2px solid #ddd !important;
                }
                
                .totals-table .final-row .value {
                    color: #FF6000 !important;
                }
                
                .invoice-footer {
                    background: white !important;
                    color: #666 !important;
                    border-top: 1px solid #ddd !important;
                    position: fixed;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    margin-top: 0 !important;
                }
                
                .footer-note {
                    background: white !important;
                    border: 1px solid #e0e0e0 !important;
                    color: #495057 !important;
                }
                
                .footer-company {
                    color: #1a1a1a !important;
                }
                
                @page {
                    margin: 15mm !important;
                    size: A4 !important;
                }
                
                /* Bazı tarayıcılarda çalışabilir */
                * {
                    -webkit-print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }
            }
        </style>
        <script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
            
            function printInvoice() {
                window.print();
            }
        </script>
    </head>
    <body>
        <button class="print-button" onclick="printInvoice()">🖨️ PDF İndir</button>
        
        <div class="invoice-container">
            <!-- HEADER -->
            <div class="invoice-header">
                <div class="company-info">
                    <h1><?php echo get_bloginfo('name'); ?></h1>
                    <p><?php echo get_option('woocommerce_store_address', 'Adres Bilgisi Yok'); ?></p>
                    <p>E-posta: <?php echo get_bloginfo('admin_email'); ?></p>
                    <p>Web: <?php echo home_url(); ?></p>
                </div>
                <div class="invoice-meta">
                    <div class="invoice-number"><?php echo $invoice_number; ?></div>
                </div>
            </div>

            <!-- BODY -->
            <div class="invoice-body">
                <!-- Müşteri Bilgileri -->
                <div class="customer-info">
                    <h3>Detaylar</h3>
                    <div class="customer-details">
                        <div class="detail-item">
                            <span class="detail-label">Müşteri</span>
                            <span class="detail-value"><?php echo htmlspecialchars($customer_name); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">E-posta</span>
                            <span class="detail-value"><?php echo htmlspecialchars($customer_email); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Telefon</span>
                            <span class="detail-value"><?php echo htmlspecialchars($customer_phone); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Ürün Tablosu -->
                <div class="items-section">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th style="width: 6%;">#</th>
                                <th style="width: 54%;">Ürün</th>
                                <th style="width: 8%;">Adet</th>
                                <th style="width: 16%;">Birim Fiyat</th>
                                <th style="width: 16%;">Toplam</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php echo $product_rows; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Toplam -->
                <div class="totals-section">
                    <table class="totals-table">
                        <tr>
                            <td class="label">Ara Toplam:</td>
                            <td class="value">₺<?php echo number_format($subtotal, 2); ?></td>
                        </tr>
                        <?php if ($shipping_cost > 0): ?>
                        <tr>
                            <td class="label">Kargo:</td>
                            <td class="value">₺<?php echo number_format($shipping_cost, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($tax_amount > 0): ?>
                        <tr>
                            <td class="label">KDV (%18):</td>
                            <td class="value">₺<?php echo number_format($tax_amount, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="final-row">
                            <td class="label">TOPLAM:</td>
                            <td class="value">₺<?php echo number_format($total, 2); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- FOOTER -->
            <div class="invoice-footer">
                <div class="footer-note">
                    İade politikamızı hakkımızda sayfasından veya aşağıdaki telefondan bizim ile iletişime geçerek öğrenebilirsiniz. 
                    Bizim ile <strong>+90 539 103 0333</strong> numaradan bize ulaşabilirsiniz.
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
// E) REWRITE RULES VE ENDPOINTS
// ============================================

// Rewrite rules
add_action('init', 'add_invoice_rewrite_rules');
function add_invoice_rewrite_rules() {
    add_rewrite_rule('^pdf-fatura/?$', 'index.php?invoice_page=1', 'top');
}

add_filter('query_vars', 'add_invoice_query_vars');
function add_invoice_query_vars($vars) {
    $vars[] = 'invoice_page';
    return $vars;
}

add_action('template_redirect', 'load_invoice_templates');
function load_invoice_templates() {
    if (get_query_var('invoice_page')) {
        if (isset($_GET['order_id']) && isset($_GET['token'])) {
            generate_invoice_certificate();
        } else {
            wp_die('Invalid parameters.');
        }
    }
}

// ============================================
// F) ADMIN STİLLERİ VE DEBUG
// ============================================

// Admin stilleri
add_action('admin_head', 'invoice_admin_styles');
function invoice_admin_styles() {
    ?>
    <style>
        .invoice-button {
            background: linear-gradient(135deg, #495057 0%, #343a40 100%) !important;
            border-color: #FF6000 !important;
            color: white !important;
            text-shadow: none !important;
            box-shadow: 0 2px 8px rgba(255, 96, 0, 0.3) !important;
            font-weight: 600 !important;
        }
        .invoice-button:hover {
            background: linear-gradient(135deg, #FF6000 0%, #e55a00 100%) !important;
            border-color: #e55a00 !important;
            color: white !important;
        }
    </style>
    <?php
}

// Aktivasyon hook
register_activation_hook(__FILE__, 'invoice_activation');
function invoice_activation() {
    add_invoice_rewrite_rules();
    flush_rewrite_rules();
}

// WordPress yüklendiğinde rewrite rules'ı kontrol et
add_action('wp_loaded', 'invoice_check_rewrite_rules');
function invoice_check_rewrite_rules() {
    $rules = get_option('rewrite_rules');
    if (!isset($rules['^pdf-fatura/?$'])) {
        add_invoice_rewrite_rules();
        flush_rewrite_rules();
    }
}

// Debug fonksiyonu - Admin menüsüne ekleme
add_action('admin_menu', 'invoice_admin_menu');
function invoice_admin_menu() {
    if (current_user_can('manage_options')) {
        add_submenu_page(
            'tools.php',
            'PDF Fatura Debug',
            'Fatura Debug',
            'manage_options',
            'invoice-debug',
            'invoice_debug_page'
        );
    }
}

function invoice_debug_page() {
    if (isset($_POST['flush_rules'])) {
        add_invoice_rewrite_rules();
        flush_rewrite_rules();
        echo '<div class="notice notice-success"><p>Rewrite rules yenilendi!</p></div>';
    }
    
    if (isset($_POST['clear_all_cache'])) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_invoice_cache_%'");
        echo '<div class="notice notice-success"><p>Tüm fatura cache\'i temizlendi!</p></div>';
    }
    
    ?>
    <div class="wrap">
        <h1>PDF Fatura Sistemi Debug</h1>
        
        <div class="card">
            <h2>Sistem Durumu</h2>
            <p><strong>Rewrite Rules:</strong> 
                <?php echo isset(get_option('rewrite_rules')['^pdf-fatura/?$']) ? '✅ Aktif' : '❌ Pasif'; ?>
            </p>
            <p><strong>Cache Sistemi:</strong> ✅ Aktif</p>
            <p><strong>Rate Limiting:</strong> ✅ Aktif</p>
            
            <form method="post" style="margin-top: 15px;">
                <input type="hidden" name="flush_rules" value="1">
                <?php submit_button('Rewrite Rules Yenile', 'primary'); ?>
            </form>
            
            <form method="post" style="margin-top: 10px;">
                <input type="hidden" name="clear_all_cache" value="1">
                <?php submit_button('Tüm Cache Temizle', 'secondary'); ?>
            </form>
        </div>
    </div>
    <?php
}