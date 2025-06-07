<?php
/**
 * Bi-Siparis Cookieless Personalization System
 * Version: 1.0
 * Description: WooCommerce için cookieless kişiselleştirme sistemi
 */

// 1. ANA CLASS - functions.php veya custom plugin'e ekleyin
class BiSiparisPersonalization {
    
    private static $instance = null;
    private $session_id;
    private $user_profile;
    
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new BiSiparisPersonalization();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hook'ları ekle
        add_action('init', array($this, 'init_tracking'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_bs_track_interaction', array($this, 'ajax_track_interaction'));
        add_action('wp_ajax_nopriv_bs_track_interaction', array($this, 'ajax_track_interaction'));
        add_action('wp_ajax_bs_get_recommendations', array($this, 'ajax_get_recommendations'));
        add_action('wp_ajax_nopriv_bs_get_recommendations', array($this, 'ajax_get_recommendations'));
        
        // WooCommerce hook'ları
        add_action('woocommerce_after_single_product', array($this, 'track_product_view'));
        add_action('woocommerce_after_shop_loop_item', array($this, 'track_category_browse'));
        
        // Elementor entegrasyonu
        add_action('elementor/dynamic_tags/register', array($this, 'register_elementor_tags'));
        
        // Database tablolarını oluştur
        add_action('init', array($this, 'create_tables'));
    }
    
    // 2. DATABASE TABLOLARI OLUŞTUR
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Kullanıcı profilleri tablosu
        $table_profiles = $wpdb->prefix . 'bs_user_profiles';
        $sql_profiles = "CREATE TABLE IF NOT EXISTS $table_profiles (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(50) NOT NULL,
            fingerprint varchar(50) NOT NULL,
            category_interests longtext,
            brand_preferences longtext,
            price_range_min decimal(10,2) DEFAULT 0,
            price_range_max decimal(10,2) DEFAULT 0,
            interaction_count int DEFAULT 0,
            last_active timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_session (session_id),
            KEY fingerprint_idx (fingerprint),
            KEY last_active_idx (last_active)
        ) $charset_collate;";
        
        // Etkileşim geçmişi tablosu
        $table_history = $wpdb->prefix . 'bs_interaction_history';
        $sql_history = "CREATE TABLE IF NOT EXISTS $table_history (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(50) NOT NULL,
            product_id bigint(20) NOT NULL,
            interaction_type varchar(20) NOT NULL,
            category_id bigint(20),
            brand varchar(100),
            price decimal(10,2),
            timestamp timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_idx (session_id),
            KEY product_idx (product_id),
            KEY timestamp_idx (timestamp)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_profiles);
        dbDelta($sql_history);
    }
    
    // 3. TRACKING BAŞLAT
    public function init_tracking() {
        $this->session_id = $this->get_or_create_session();
        $this->load_user_profile();
    }
    
    private function get_or_create_session() {
        // Session cookie kontrolü
        if (isset($_COOKIE['bs_session'])) {
            return sanitize_text_field($_COOKIE['bs_session']);
        }
        
        // Yeni session oluştur
        $session_id = 'bs_' . wp_generate_password(16, false) . '_' . time();
        setcookie('bs_session', $session_id, time() + (86400 * 30), '/', '', true, true);
        
        return $session_id;
    }
    
    // 4. JAVASCRIPT DOSYALARINI YÜKLE
    public function enqueue_scripts() {
        wp_enqueue_script(
            'bs-tracker',
            plugin_dir_url(__FILE__) . 'assets/bs-tracker.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        wp_localize_script('bs-tracker', 'bs_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bs_tracker_nonce'),
            'session_id' => $this->session_id,
            'is_product' => is_product(),
            'is_category' => is_product_category(),
            'current_product' => is_product() ? get_the_ID() : 0
        ));
    }
    
    // 5. ÜRÜN GÖRÜNTÜLEME TAKIBI
    public function track_product_view() {
        global $product;
        
        if (!$product) return;
        
        $product_data = array(
            'product_id' => $product->get_id(),
            'category_id' => $this->get_primary_category_id($product->get_id()),
            'brand' => $this->get_product_brand($product->get_id()),
            'price' => $product->get_price(),
            'interaction_type' => 'product_view'
        );
        
        $this->save_interaction($product_data);
        $this->update_user_interests($product_data);
    }
    
    // 6. KATEGORİ GEZİNME TAKİBİ
    public function track_category_browse() {
        if (!is_product_category()) return;
        
        $category = get_queried_object();
        
        $category_data = array(
            'category_id' => $category->term_id,
            'interaction_type' => 'category_browse'
        );
        
        $this->save_interaction($category_data);
    }
    
    // 7. AJAX İLE ETKİLEŞİM KAYDI
    public function ajax_track_interaction() {
        check_ajax_referer('bs_tracker_nonce', 'nonce');
        
        $interaction_data = array(
            'product_id' => intval($_POST['product_id']),
            'interaction_type' => sanitize_text_field($_POST['interaction_type']),
            'category_id' => intval($_POST['category_id']),
            'brand' => sanitize_text_field($_POST['brand']),
            'price' => floatval($_POST['price'])
        );
        
        $this->save_interaction($interaction_data);
        $this->update_user_interests($interaction_data);
        
        wp_send_json_success('Interaction tracked');
    }
    
    // 8. ETKİLEŞİMİ KAYDET
    private function save_interaction($data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bs_interaction_history';
        
        $wpdb->insert(
            $table_name,
            array(
                'session_id' => $this->session_id,
                'product_id' => $data['product_id'] ?? 0,
                'interaction_type' => $data['interaction_type'],
                'category_id' => $data['category_id'] ?? null,
                'brand' => $data['brand'] ?? null,
                'price' => $data['price'] ?? null
            )
        );
    }
    
    // 9. KULLANICI İLGİ ALANLARINI GÜNCELLE
    private function update_user_interests($data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bs_user_profiles';
        
        // Mevcut profili al
        $profile = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE session_id = %s",
            $this->session_id
        ));
        
        if (!$profile) {
            // Yeni profil oluştur
            $wpdb->insert(
                $table_name,
                array(
                    'session_id' => $this->session_id,
                    'fingerprint' => $this->get_fingerprint(),
                    'category_interests' => json_encode(array()),
                    'brand_preferences' => json_encode(array()),
                    'price_range_min' => $data['price'] ?? 0,
                    'price_range_max' => $data['price'] ?? 0,
                    'interaction_count' => 1
                )
            );
        } else {
            // Mevcut profili güncelle
            $category_interests = json_decode($profile->category_interests, true) ?: array();
            $brand_preferences = json_decode($profile->brand_preferences, true) ?: array();
            
            // Kategori ilgisini güncelle
            if (!empty($data['category_id'])) {
                $category_interests[$data['category_id']] = 
                    ($category_interests[$data['category_id']] ?? 0) + 1;
            }
            
            // Marka tercihini güncelle
            if (!empty($data['brand'])) {
                $brand_preferences[$data['brand']] = 
                    ($brand_preferences[$data['brand']] ?? 0) + 1;
            }
            
            // Fiyat aralığını güncelle
            $price = $data['price'] ?? 0;
            $price_min = min($profile->price_range_min, $price);
            $price_max = max($profile->price_range_max, $price);
            
            $wpdb->update(
                $table_name,
                array(
                    'category_interests' => json_encode($category_interests),
                    'brand_preferences' => json_encode($brand_preferences),
                    'price_range_min' => $price_min,
                    'price_range_max' => $price_max,
                    'interaction_count' => $profile->interaction_count + 1,
                    'last_active' => current_time('mysql')
                ),
                array('session_id' => $this->session_id)
            );
        }
    }
    
    // 10. KİŞİSELLEŞTİRİLMİŞ ÖNERİLER
    public function get_personalized_recommendations($limit = 8) {
        global $wpdb;
        
        // Kullanıcı profilini al
        $profile = $this->get_user_profile();
        
        if (!$profile) {
            // Yeni kullanıcı için popüler ürünler
            return $this->get_popular_products($limit);
        }
        
        // Kullanıcı ilgi alanlarına göre ürünler
        $category_interests = json_decode($profile->category_interests, true) ?: array();
        $brand_preferences = json_decode($profile->brand_preferences, true) ?: array();
        
        // En çok ilgi duyulan kategoriler
        arsort($category_interests);
        $top_categories = array_slice(array_keys($category_interests), 0, 3);
        
        // En çok tercih edilen markalar
        arsort($brand_preferences);
        $top_brands = array_slice(array_keys($brand_preferences), 0, 3);
        
        // Fiyat aralığı hesapla (ortalama +-30%)
        $avg_price = ($profile->price_range_min + $profile->price_range_max) / 2;
        $price_min = $avg_price * 0.7;
        $price_max = $avg_price * 1.3;
        
        // Önerileri oluştur
        $recommendations = array();
        
        // %60 bütçe dahilinde
        $budget_products = $this->get_products_by_criteria(
            $top_categories,
            $top_brands,
            $avg_price * 0.8,
            $avg_price * 1.2,
            ceil($limit * 0.6)
        );
        
        // %30 biraz üstünde (upsell)
        $upsell_products = $this->get_products_by_criteria(
            $top_categories,
            $top_brands,
            $avg_price * 1.2,
            $avg_price * 1.5,
            ceil($limit * 0.3)
        );
        
        // %10 değer odaklı
        $value_products = $this->get_products_by_criteria(
            $top_categories,
            $top_brands,
            $avg_price * 0.5,
            $avg_price * 0.8,
            ceil($limit * 0.1)
        );
        
        $recommendations = array_merge($budget_products, $upsell_products, $value_products);
        
        // Tekrarları kaldır ve karıştır
        $recommendations = array_unique($recommendations, SORT_REGULAR);
        shuffle($recommendations);
        
        return array_slice($recommendations, 0, $limit);
    }
    
    // 11. KRİTERLERE GÖRE ÜRÜN GETIR
    private function get_products_by_criteria($categories, $brands, $price_min, $price_max, $limit) {
        global $wpdb;
        
        $query_args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'rand',
            'meta_query' => array(
                array(
                    'key' => '_price',
                    'value' => array($price_min, $price_max),
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                )
            )
        );
        
        // Kategori filtresi
        if (!empty($categories)) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $categories
            );
        }
        
        // Marka filtresi (custom taxonomy varsayarak)
        if (!empty($brands)) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'product_brand',
                'field' => 'slug',
                'terms' => $brands
            );
        }
        
        $products = new WP_Query($query_args);
        
        $results = array();
        if ($products->have_posts()) {
            while ($products->have_posts()) {
                $products->the_post();
                $product = wc_get_product(get_the_ID());
                
                $results[] = array(
                    'id' => $product->get_id(),
                    'title' => $product->get_name(),
                    'price' => $product->get_price_html(),
                    'image' => wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail'),
                    'link' => $product->get_permalink()
                );
            }
        }
        wp_reset_postdata();
        
        return $results;
    }
    
    // 12. AJAX İLE ÖNERİLER
    public function ajax_get_recommendations() {
        check_ajax_referer('bs_tracker_nonce', 'nonce');
        
        $limit = intval($_POST['limit']) ?: 8;
        $recommendations = $this->get_personalized_recommendations($limit);
        
        wp_send_json_success(array(
            'products' => $recommendations,
            'session_id' => $this->session_id
        ));
    }
    
    // 13. ELEMENTOR DYNAMIC TAG
    public function register_elementor_tags($dynamic_tags_manager) {
        require_once(__DIR__ . '/includes/elementor-personalized-products-tag.php');
        $dynamic_tags_manager->register(new \BiSiparis_Personalized_Products_Tag());
    }
    
    // 14. YARDIMCI METODLAR
    private function get_primary_category_id($product_id) {
        $terms = wp_get_post_terms($product_id, 'product_cat');
        return !empty($terms) ? $terms[0]->term_id : 0;
    }
    
    private function get_product_brand($product_id) {
        $terms = wp_get_post_terms($product_id, 'product_brand');
        return !empty($terms) ? $terms[0]->slug : '';
    }
    
    private function get_fingerprint() {
        // JavaScript'ten gelen fingerprint'i al
        return isset($_COOKIE['bs_fingerprint']) ? 
            sanitize_text_field($_COOKIE['bs_fingerprint']) : 
            'unknown';
    }
    
    private function get_user_profile() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bs_user_profiles';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE session_id = %s",
            $this->session_id
        ));
    }
    
    private function load_user_profile() {
        $this->user_profile = $this->get_user_profile();
    }
    
    private function get_popular_products($limit) {
        $query_args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_key' => 'total_sales',
            'orderby' => 'meta_value_num',
            'order' => 'DESC'
        );
        
        $products = new WP_Query($query_args);
        
        $results = array();
        if ($products->have_posts()) {
            while ($products->have_posts()) {
                $products->the_post();
                $product = wc_get_product(get_the_ID());
                
                $results[] = array(
                    'id' => $product->get_id(),
                    'title' => $product->get_name(),
                    'price' => $product->get_price_html(),
                    'image' => wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail'),
                    'link' => $product->get_permalink()
                );
            }
        }
        wp_reset_postdata();
        
        return $results;
    }
}

// 15. SHORTCODE İÇİN WRAPPER
function bs_personalized_products_shortcode($atts) {
    $atts = shortcode_atts(array(
        'limit' => 8,
        'title' => 'Sizin İçin Öneriler',
        'columns' => 4
    ), $atts);
    
    $personalization = BiSiparisPersonalization::getInstance();
    $products = $personalization->get_personalized_recommendations($atts['limit']);
    
    ob_start();
    ?>
    <div class="bs-personalized-products">
        <?php if ($atts['title']): ?>
            <h2><?php echo esc_html($atts['title']); ?></h2>
        <?php endif; ?>
        
        <div class="products columns-<?php echo esc_attr($atts['columns']); ?>">
            <?php foreach ($products as $product): ?>
                <div class="product">
                    <a href="<?php echo esc_url($product['link']); ?>">
                        <img src="<?php echo esc_url($product['image']); ?>" 
                             alt="<?php echo esc_attr($product['title']); ?>">
                        <h3><?php echo esc_html($product['title']); ?></h3>
                        <span class="price"><?php echo $product['price']; ?></span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('bs_personalized_products', 'bs_personalized_products_shortcode');

// 16. NITROPACK CACHE VARY HEADER
add_filter('nitropack_cacheable_vary_headers', function($headers) {
    $headers[] = 'X-BS-User-Segment';
    return $headers;
});

// 17. CLOUDFLARE WORKER İÇİN API ENDPOINT
add_action('rest_api_init', function() {
    register_rest_route('bs/v1', '/recommendations', array(
        'methods' => 'GET',
        'callback' => 'bs_api_get_recommendations',
        'permission_callback' => '__return_true'
    ));
});

function bs_api_get_recommendations($request) {
    $session_id = $request->get_param('session_id');
    $limit = $request->get_param('limit') ?: 8;
    
    // Session ID ile personalization instance oluştur
    $personalization = BiSiparisPersonalization::getInstance();
    
    // Önerileri al
    $recommendations = $personalization->get_personalized_recommendations($limit);
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => $recommendations
    ), 200);
}

// 18. INSTANCE'I BAŞLAT
BiSiparisPersonalization::getInstance();

