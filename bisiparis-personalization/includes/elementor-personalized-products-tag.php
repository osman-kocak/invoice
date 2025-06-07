<?php
/**
 * Elementor Dynamic Tag for Personalized Products
 * includes/elementor-personalized-products-tag.php dosyası olarak kaydedin
 */

if (!defined('ABSPATH')) {
    exit;
}

class BiSiparis_Personalized_Products_Tag extends \Elementor\Core\DynamicTags\Tag {
    
    public function get_name() {
        return 'bs-personalized-products';
    }
    
    public function get_title() {
        return __('Kişiselleştirilmiş Ürünler', 'bisiparis');
    }
    
    public function get_group() {
        return 'woocommerce';
    }
    
    public function get_categories() {
        return ['text'];
    }
    
    protected function register_controls() {
        $this->add_control(
            'recommendation_type',
            [
                'label' => __('Öneri Tipi', 'bisiparis'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'personalized' => __('Kişiselleştirilmiş', 'bisiparis'),
                    'similar' => __('Benzer Ürünler', 'bisiparis'),
                    'budget' => __('Bütçe Bazlı', 'bisiparis'),
                    'category' => __('Kategori Bazlı', 'bisiparis'),
                    'trending' => __('Trend Ürünler', 'bisiparis'),
                    'viewed' => __('Son Görüntülenenler', 'bisiparis')
                ],
                'default' => 'personalized'
            ]
        );
        
        $this->add_control(
            'product_count',
            [
                'label' => __('Ürün Sayısı', 'bisiparis'),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'default' => 8,
                'min' => 1,
                'max' => 20
            ]
        );
        
        $this->add_control(
            'columns',
            [
                'label' => __('Kolon Sayısı', 'bisiparis'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                    '5' => '5',
                    '6' => '6'
                ],
                'default' => '4'
            ]
        );
        
        $this->add_control(
            'show_title',
            [
                'label' => __('Başlık Göster', 'bisiparis'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes'
            ]
        );
        
        $this->add_control(
            'title_text',
            [
                'label' => __('Başlık Metni', 'bisiparis'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => __('Sizin İçin Öneriler', 'bisiparis'),
                'condition' => [
                    'show_title' => 'yes'
                ]
            ]
        );
        
        $this->add_control(
            'enable_carousel',
            [
                'label' => __('Carousel Aktif', 'bisiparis'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes'
            ]
        );
        
        $this->add_control(
            'autoplay',
            [
                'label' => __('Otomatik Oynat', 'bisiparis'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
                'condition' => [
                    'enable_carousel' => 'yes'
                ]
            ]
        );
        
        $this->add_control(
            'autoplay_speed',
            [
                'label' => __('Otomatik Oynatma Hızı (ms)', 'bisiparis'),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'default' => 5000,
                'condition' => [
                    'enable_carousel' => 'yes',
                    'autoplay' => 'yes'
                ]
            ]
        );
    }
    
    public function render() {
        $settings = $this->get_settings();
        $personalization = BiSiparisPersonalization::getInstance();
        
        // Öneri tipine göre ürünleri al
        switch ($settings['recommendation_type']) {
            case 'similar':
                $products = $this->getSimilarProducts($settings['product_count']);
                break;
            case 'budget':
                $products = $this->getBudgetBasedProducts($settings['product_count']);
                break;
            case 'category':
                $products = $this->getCategoryBasedProducts($settings['product_count']);
                break;
            case 'trending':
                $products = $this->getTrendingProducts($settings['product_count']);
                break;
            case 'viewed':
                $products = $this->getRecentlyViewedProducts($settings['product_count']);
                break;
            default:
                $products = $personalization->get_personalized_recommendations($settings['product_count']);
        }
        
        // HTML çıktısı
        $this->renderProducts($products, $settings);
    }
    
    private function renderProducts($products, $settings) {
        $carousel_class = $settings['enable_carousel'] === 'yes' ? 'bs-carousel' : '';
        $carousel_attrs = '';
        
        if ($settings['enable_carousel'] === 'yes') {
            $carousel_attrs = sprintf(
                'data-autoplay="%s" data-autoplay-speed="%d" data-columns="%s"',
                $settings['autoplay'],
                $settings['autoplay_speed'],
                $settings['columns']
            );
        }
        ?>
        
        <div class="bs-personalized-products-widget <?php echo esc_attr($carousel_class); ?>" <?php echo $carousel_attrs; ?>>
            <?php if ($settings['show_title'] === 'yes' && !empty($settings['title_text'])): ?>
                <h2 class="bs-products-title"><?php echo esc_html($settings['title_text']); ?></h2>
            <?php endif; ?>
            
            <div class="bs-products-container columns-<?php echo esc_attr($settings['columns']); ?>">
                <?php foreach ($products as $product): ?>
                    <div class="bs-product-item" data-product-id="<?php echo esc_attr($product['id']); ?>">
                        <a href="<?php echo esc_url($product['link']); ?>" class="bs-product-link">
                            <div class="bs-product-image">
                                <img src="<?php echo esc_url($product['image']); ?>" 
                                     alt="<?php echo esc_attr($product['title']); ?>"
                                     loading="lazy">
                                <?php if ($this->hasDiscount($product['id'])): ?>
                                    <span class="bs-badge-discount">İndirim!</span>
                                <?php endif; ?>
                            </div>
                            <div class="bs-product-info">
                                <h3 class="bs-product-title"><?php echo esc_html($product['title']); ?></h3>
                                <div class="bs-product-price"><?php echo $product['price']; ?></div>
                                
                                <?php 
                                // Kategori ve marka bilgisi
                                $terms = wp_get_post_terms($product['id'], 'product_cat');
                                if (!empty($terms)): ?>
                                    <span class="bs-product-category">
                                        <?php echo esc_html($terms[0]->name); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <div class="bs-product-actions">
                            <?php 
                            echo do_shortcode('[add_to_cart id="' . $product['id'] . '" show_price="false"]');
                            ?>
                            <button class="bs-whatsapp-order" data-product-id="<?php echo esc_attr($product['id']); ?>">
                                <i class="fab fa-whatsapp"></i> WhatsApp
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($settings['enable_carousel'] === 'yes'): ?>
                <div class="bs-carousel-navigation">
                    <button class="bs-carousel-prev"><i class="fas fa-chevron-left"></i></button>
                    <button class="bs-carousel-next"><i class="fas fa-chevron-right"></i></button>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
        .bs-personalized-products-widget {
            margin: 30px 0;
        }
        
        .bs-products-title {
            font-size: 24px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .bs-products-container {
            display: grid;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .bs-products-container.columns-2 { grid-template-columns: repeat(2, 1fr); }
        .bs-products-container.columns-3 { grid-template-columns: repeat(3, 1fr); }
        .bs-products-container.columns-4 { grid-template-columns: repeat(4, 1fr); }
        .bs-products-container.columns-5 { grid-template-columns: repeat(5, 1fr); }
        .bs-products-container.columns-6 { grid-template-columns: repeat(6, 1fr); }
        
        @media (max-width: 768px) {
            .bs-products-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .bs-products-container {
                grid-template-columns: 1fr;
            }
        }
        
        .bs-product-item {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .bs-product-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .bs-product-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .bs-product-image {
            position: relative;
            padding-bottom: 100%;
            overflow: hidden;
        }
        
        .bs-product-image img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .bs-product-item:hover .bs-product-image img {
            transform: scale(1.1);
        }
        
        .bs-badge-discount {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #ff4444;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .bs-product-info {
            padding: 15px;
        }
        
        .bs-product-title {
            font-size: 16px;
            margin: 0 0 10px;
            font-weight: 500;
            line-height: 1.3;
            height: 42px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        
        .bs-product-price {
            font-size: 18px;
            font-weight: bold;
            color: #ff6b6b;
            margin-bottom: 5px;
        }
        
        .bs-product-category {
            font-size: 12px;
            color: #666;
            display: inline-block;
            background: #f5f5f5;
            padding: 3px 8px;
            border-radius: 3px;
        }
        
        .bs-product-actions {
            padding: 0 15px 15px;
            display: flex;
            gap: 10px;
        }
        
        .bs-product-actions .button,
        .bs-whatsapp-order {
            flex: 1;
            padding: 8px 12px;
            font-size: 14px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .bs-whatsapp-order {
            background: #25d366;
            color: white;
        }
        
        .bs-whatsapp-order:hover {
            background: #1da851;
        }
        
        /* Carousel stilleri */
        .bs-carousel {
            position: relative;
        }
        
        .bs-carousel .bs-products-container {
            overflow: hidden;
        }
        
        .bs-carousel-navigation {
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            transform: translateY(-50%);
            display: flex;
            justify-content: space-between;
            pointer-events: none;
        }
        
        .bs-carousel-prev,
        .bs-carousel-next {
            background: rgba(0,0,0,0.8);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            pointer-events: all;
            transition: all 0.3s ease;
        }
        
        .bs-carousel-prev:hover,
        .bs-carousel-next:hover {
            background: rgba(0,0,0,0.9);
            transform: scale(1.1);
        }
        
        /* Fade in animasyonu */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .bs-product-item.fade-in {
            animation: fadeIn 0.5s ease forwards;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Carousel başlat
            if ($('.bs-carousel').length) {
                $('.bs-carousel').each(function() {
                    const $carousel = $(this);
                    const autoplay = $carousel.data('autoplay') === 'yes';
                    const speed = $carousel.data('autoplay-speed') || 5000;
                    
                    // Basit carousel mantığı
                    let currentIndex = 0;
                    const $container = $carousel.find('.bs-products-container');
                    const $items = $container.find('.bs-product-item');
                    const columns = parseInt($carousel.data('columns'));
                    const itemsToShow = columns;
                    
                    // Navigation
                    $carousel.find('.bs-carousel-next').on('click', function() {
                        currentIndex += itemsToShow;
                        if (currentIndex >= $items.length) currentIndex = 0;
                        updateCarousel();
                    });
                    
                    $carousel.find('.bs-carousel-prev').on('click', function() {
                        currentIndex -= itemsToShow;
                        if (currentIndex < 0) currentIndex = Math.max(0, $items.length - itemsToShow);
                        updateCarousel();
                    });
                    
                    function updateCarousel() {
                        const translateX = -(currentIndex * (100 / itemsToShow));
                        $container.css('transform', `translateX(${translateX}%)`);
                    }
                    
                    // Autoplay
                    if (autoplay) {
                        setInterval(function() {
                            $carousel.find('.bs-carousel-next').click();
                        }, speed);
                    }
                });
            }
            
            // WhatsApp order tracking
            $('.bs-whatsapp-order').on('click', function(e) {
                e.preventDefault();
                const productId = $(this).data('product-id');
                
                // Track event
                if (window.bsTracker) {
                    window.bsTracker.trackWhatsAppOrder(productId);
                }
                
                // WhatsApp URL oluştur
                const phone = '905xxxxxxxxx'; // Telefon numaranızı girin
                const text = 'Merhaba, ' + productId + ' numaralı ürün hakkında bilgi almak istiyorum.';
                const whatsappUrl = `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
                
                window.open(whatsappUrl, '_blank');
            });
        });
        </script>
        <?php
    }
    
    // Yardımcı metodlar
    private function getSimilarProducts($limit) {
        global $product;
        
        if (!is_product() || !$product) {
            return $this->getTrendingProducts($limit);
        }
        
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $limit,
            'post__not_in' => array($product->get_id()),
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'ids'))
                )
            )
        );
        
        return $this->queryProducts($args);
    }
    
    private function getBudgetBasedProducts($limit) {
        $personalization = BiSiparisPersonalization::getInstance();
        $profile = $personalization->get_user_profile();
        
        if (!$profile) {
            return $this->getTrendingProducts($limit);
        }
        
        $avg_price = ($profile->price_range_min + $profile->price_range_max) / 2;
        
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $limit,
            'meta_query' => array(
                array(
                    'key' => '_price',
                    'value' => array($avg_price * 0.7, $avg_price * 1.3),
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                )
            )
        );
        
        return $this->queryProducts($args);
    }
    
    private function getCategoryBasedProducts($limit) {
        $personalization = BiSiparisPersonalization::getInstance();
        $profile = $personalization->get_user_profile();
        
        if (!$profile) {
            return $this->getTrendingProducts($limit);
        }
        
        $category_interests = json_decode($profile->category_interests, true) ?: array();
        arsort($category_interests);
        $top_categories = array_slice(array_keys($category_interests), 0, 3);
        
        if (empty($top_categories)) {
            return $this->getTrendingProducts($limit);
        }
        
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $limit,
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $top_categories
                )
            )
        );
        
        return $this->queryProducts($args);
    }
    
    private function getTrendingProducts($limit) {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $limit,
            'meta_key' => 'total_sales',
            'orderby' => 'meta_value_num',
            'order' => 'DESC'
        );
        
        return $this->queryProducts($args);
    }
    
    private function getRecentlyViewedProducts($limit) {
        $viewed_products = ! empty( $_COOKIE['woocommerce_recently_viewed'] ) ? 
            (array) explode( '|', $_COOKIE['woocommerce_recently_viewed'] ) : array();
        
        if (empty($viewed_products)) {
            return $this->getTrendingProducts($limit);
        }
        
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $limit,
            'post__in' => $viewed_products,
            'orderby' => 'post__in'
        );
        
        return $this->queryProducts($args);
    }
    
    private function queryProducts($args) {
        $products = new WP_Query($args);
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
    
    private function hasDiscount($product_id) {
        $product = wc_get_product($product_id);
        return $product && $product->is_on_sale();
    }
}