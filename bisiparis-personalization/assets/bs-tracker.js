/**
 * Bi-Siparis Tracker - Browser Fingerprinting + localStorage
 * assets/bs-tracker.js dosyası olarak kaydedin
 */

(function($) {
    'use strict';
    
    class BiSiparisTracker {
        constructor() {
            this.storageKey = 'bs_user_profile';
            this.fingerprintKey = 'bs_fingerprint';
            this.sessionKey = bs_ajax.session_id;
            this.fingerprint = null;
            this.userProfile = null;
            
            this.init();
        }
        
        async init() {
            // Fingerprint oluştur
            this.fingerprint = await this.generateFingerprint();
            this.setCookie(this.fingerprintKey, this.fingerprint, 365);
            
            // Kullanıcı profilini yükle
            this.loadUserProfile();
            
            // Event listener'ları ekle
            this.bindEvents();
            
            // Sayfa görüntüleme takibi
            this.trackPageView();
        }
        
        // 1. BROWSER FINGERPRINTING
        async generateFingerprint() {
            const components = [];
            
            // Canvas fingerprinting
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.textBaseline = 'alphabetic';
            ctx.fillStyle = '#f60';
            ctx.fillRect(125, 1, 62, 20);
            ctx.fillStyle = '#069';
            ctx.fillText('BiSiparis.com 🛒', 2, 15);
            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.fillText('BiSiparis.com 🛒', 4, 17);
            
            const canvasData = canvas.toDataURL();
            components.push(canvasData);
            
            // WebGL fingerprinting
            const webglCanvas = document.createElement('canvas');
            const gl = webglCanvas.getContext('webgl') || webglCanvas.getContext('experimental-webgl');
            
            if (gl) {
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                if (debugInfo) {
                    components.push(gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL));
                    components.push(gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL));
                }
            }
            
            // Device özellikleri
            components.push(navigator.userAgent);
            components.push(navigator.language);
            components.push(screen.width + 'x' + screen.height + 'x' + screen.colorDepth);
            components.push(new Date().getTimezoneOffset());
            components.push(navigator.hardwareConcurrency || 'unknown');
            components.push(navigator.platform);
            
            // Audio fingerprinting
            if (window.AudioContext || window.webkitAudioContext) {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const analyser = audioContext.createAnalyser();
                const gainNode = audioContext.createGain();
                const scriptProcessor = audioContext.createScriptProcessor(4096, 1, 1);
                
                gainNode.gain.value = 0;
                oscillator.connect(analyser);
                analyser.connect(scriptProcessor);
                scriptProcessor.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.start(0);
                const audioData = new Float32Array(analyser.frequencyBinCount);
                analyser.getFloatFrequencyData(audioData);
                components.push(audioData.slice(0, 30).toString());
                
                oscillator.stop();
                audioContext.close();
            }
            
            // Hash oluştur
            const fingerprintString = components.join('|||');
            return this.simpleHash(fingerprintString);
        }
        
        simpleHash(str) {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return 'fp_' + Math.abs(hash).toString(36);
        }
        
        // 2. LOCALSTORAGE KULLANICI PROFİLİ
        loadUserProfile() {
            let profile = localStorage.getItem(this.storageKey);
            
            if (!profile) {
                // Yeni profil oluştur
                profile = {
                    id: this.fingerprint,
                    session_id: this.sessionKey,
                    created: Date.now(),
                    last_visit: Date.now(),
                    sessions: 1,
                    categories: {},
                    brands: {},
                    products_viewed: [],
                    price_preferences: {
                        views: [],
                        min: 0,
                        max: 0,
                        average: 0
                    },
                    behavior: {
                        avg_session_duration: 0,
                        bounce_rate: 100,
                        pages_per_session: 1,
                        preferred_view_time: null
                    }
                };
            } else {
                profile = JSON.parse(profile);
                profile.sessions++;
                profile.last_visit = Date.now();
            }
            
            this.userProfile = profile;
            this.saveProfile();
        }
        
        saveProfile() {
            localStorage.setItem(this.storageKey, JSON.stringify(this.userProfile));
        }
        
        // 3. SAYFA GÖRÜNTÜLEME TAKİBİ
        trackPageView() {
            // Ürün sayfası
            if (bs_ajax.is_product && bs_ajax.current_product) {
                this.trackProductView();
            }
            
            // Kategori sayfası
            if (bs_ajax.is_category) {
                this.trackCategoryBrowse();
            }
            
            // Session başlangıcını kaydet
            if (!sessionStorage.getItem('bs_session_start')) {
                sessionStorage.setItem('bs_session_start', Date.now());
            }
        }
        
        // 4. ÜRÜN GÖRÜNTÜLEME TAKİBİ
        trackProductView() {
            const productData = this.extractProductData();
            
            if (!productData) return;
            
            // Profili güncelle
            this.updateCategoryInterest(productData.category);
            this.updateBrandPreference(productData.brand);
            this.updatePricePreference(productData.price);
            
            // Son görüntülenen ürünlere ekle
            this.userProfile.products_viewed.unshift({
                id: productData.id,
                timestamp: Date.now()
            });
            
            // Maksimum 50 ürün sakla
            if (this.userProfile.products_viewed.length > 50) {
                this.userProfile.products_viewed = this.userProfile.products_viewed.slice(0, 50);
            }
            
            this.saveProfile();
            
            // Sunucuya gönder
            this.sendToServer({
                action: 'bs_track_interaction',
                interaction_type: 'product_view',
                product_id: productData.id,
                category_id: productData.category_id,
                brand: productData.brand,
                price: productData.price
            });
        }
        
        // 5. KATEGORİ GEZİNME TAKİBİ
        trackCategoryBrowse() {
            const categoryData = this.extractCategoryData();
            
            if (!categoryData) return;
            
            this.updateCategoryInterest(categoryData.name);
            this.saveProfile();
            
            // Sunucuya gönder
            this.sendToServer({
                action: 'bs_track_interaction',
                interaction_type: 'category_browse',
                category_id: categoryData.id
            });
        }
        
        // 6. EVENT LISTENERS
        bindEvents() {
            // Ürün tıklama takibi
            $(document).on('click', '.products .product a, .product-grid .product a', (e) => {
                const $product = $(e.currentTarget).closest('.product');
                const productId = $product.data('product-id') || 
                                $product.find('.add_to_cart_button').data('product_id');
                
                if (productId) {
                    this.trackProductClick(productId);
                }
            });
            
            // Sepete ekleme takibi
            $(document).on('added_to_cart', (e, fragments, cart_hash, $button) => {
                const productId = $button.data('product_id');
                if (productId) {
                    this.trackAddToCart(productId);
                }
            });
            
            // WhatsApp sipariş butonu takibi
            $(document).on('click', '.whatsapp-order-button', (e) => {
                const productId = $(e.currentTarget).data('product-id');
                if (productId) {
                    this.trackWhatsAppOrder(productId);
                }
            });
            
            // Scroll derinliği takibi
            let maxScroll = 0;
            $(window).on('scroll', () => {
                const scrollPercent = ($(window).scrollTop() / ($(document).height() - $(window).height())) * 100;
                maxScroll = Math.max(maxScroll, scrollPercent);
            });
            
            // Sayfa çıkış takibi
            $(window).on('beforeunload', () => {
                this.trackSessionEnd(maxScroll);
            });
        }
        
        // 7. VERI ÇIKARMA YARDIMCI METODLAR
        extractProductData() {
            // JSON-LD structured data'dan al
            const jsonLd = $('script[type="application/ld+json"]').text();
            let productData = null;
            
            try {
                const data = JSON.parse(jsonLd);
                if (data['@type'] === 'Product') {
                    productData = {
                        id: bs_ajax.current_product,
                        name: data.name,
                        price: parseFloat(data.offers?.price || 0),
                        category: $('.product-category a').first().text() || 'Uncategorized',
                        category_id: $('.product-category a').first().data('cat-id') || 0,
                        brand: $('.product-brand').text() || data.brand?.name || ''
                    };
                }
            } catch (e) {
                // Fallback: DOM'dan al
                productData = {
                    id: bs_ajax.current_product,
                    name: $('h1.product_title').text(),
                    price: parseFloat($('.price .amount').first().text().replace(/[^\d.]/g, '')),
                    category: $('.posted_in a').first().text(),
                    category_id: 0,
                    brand: $('.product_meta .brand a').text() || ''
                };
            }
            
            return productData;
        }
        
        extractCategoryData() {
            return {
                id: $('.product-category').data('cat-id') || 0,
                name: $('.woocommerce-products-header__title').text() || 
                      $('h1.page-title').text() || 
                      document.title.split(' – ')[0]
            };
        }
        
        // 8. PROFİL GÜNCELLEME METODLARI
        updateCategoryInterest(category) {
            if (!category) return;
            
            this.userProfile.categories[category] = 
                (this.userProfile.categories[category] || 0) + 1;
        }
        
        updateBrandPreference(brand) {
            if (!brand) return;
            
            this.userProfile.brands[brand] = 
                (this.userProfile.brands[brand] || 0) + 1;
        }
        
        updatePricePreference(price) {
            if (!price || price <= 0) return;
            
            const prefs = this.userProfile.price_preferences;
            prefs.views.push(price);
            
            // Son 20 fiyatı sakla
            if (prefs.views.length > 20) {
                prefs.views = prefs.views.slice(-20);
            }
            
            // İstatistikleri güncelle
            prefs.min = Math.min(...prefs.views);
            prefs.max = Math.max(...prefs.views);
            prefs.average = prefs.views.reduce((a, b) => a + b, 0) / prefs.views.length;
        }
        
        // 9. ETKİLEŞİM TAKİP METODLARI
        trackProductClick(productId) {
            this.sendToServer({
                action: 'bs_track_interaction',
                interaction_type: 'product_click',
                product_id: productId
            });
        }
        
        trackAddToCart(productId) {
            this.sendToServer({
                action: 'bs_track_interaction',
                interaction_type: 'add_to_cart',
                product_id: productId
            });
            
            // Conversion tracking
            this.userProfile.behavior.conversion_events = 
                (this.userProfile.behavior.conversion_events || 0) + 1;
            this.saveProfile();
        }
        
        trackWhatsAppOrder(productId) {
            this.sendToServer({
                action: 'bs_track_interaction',
                interaction_type: 'whatsapp_order',
                product_id: productId
            });
        }
        
        trackSessionEnd(scrollDepth) {
            const sessionStart = parseInt(sessionStorage.getItem('bs_session_start'));
            const sessionDuration = Date.now() - sessionStart;
            
            // Davranış metriklerini güncelle
            const behavior = this.userProfile.behavior;
            behavior.avg_session_duration = 
                ((behavior.avg_session_duration * (this.userProfile.sessions - 1)) + sessionDuration) / 
                this.userProfile.sessions;
            
            behavior.last_scroll_depth = scrollDepth;
            
            // Tercih edilen ziyaret zamanı
            const hour = new Date().getHours();
            behavior.preferred_visit_time = hour;
            
            this.saveProfile();
        }
        
        // 10. SUNUCUYA VERİ GÖNDERME
        sendToServer(data) {
            data.nonce = bs_ajax.nonce;
            data.fingerprint = this.fingerprint;
            
            $.post(bs_ajax.ajax_url, data, (response) => {
                console.log('BS Tracker:', response);
            });
        }
        
        // 11. COOKIE YARDIMCI METODU
        setCookie(name, value, days) {
            const expires = new Date();
            expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
            document.cookie = `${name}=${value};expires=${expires.toUTCString()};path=/;SameSite=Lax;Secure`;
        }
        
        // 12. PUBLIC API - Öneriler için
        getRecommendations(callback, limit = 8) {
            $.post(bs_ajax.ajax_url, {
                action: 'bs_get_recommendations',
                nonce: bs_ajax.nonce,
                limit: limit,
                user_profile: JSON.stringify(this.userProfile)
            }, callback);
        }
        
        // 13. KULLANICI SEGMENTİ BELİRLEME
        getUserSegment() {
            const sessions = this.userProfile.sessions;
            const avgPrice = this.userProfile.price_preferences.average;
            const conversionEvents = this.userProfile.behavior.conversion_events || 0;
            
            if (sessions === 1) {
                return 'new_visitor';
            } else if (sessions > 5 && avgPrice > 500) {
                return 'high_value';
            } else if (sessions > 3 && conversionEvents > 0) {
                return 'returning_customer';
            } else if (avgPrice < 100) {
                return 'budget_conscious';
            } else {
                return 'returning_visitor';
            }
        }
    }
    
    // 14. PERSONALIZED CAROUSEL SINIFI
    class PersonalizedCarousel {
        constructor(element) {
            this.$element = $(element);
            this.tracker = window.bsTracker;
            this.init();
        }
        
        init() {
            // İlk yükleme
            this.loadRecommendations();
            
            // 5 dakikada bir güncelle
            setInterval(() => {
                this.loadRecommendations();
            }, 300000);
        }
        
        loadRecommendations() {
            this.tracker.getRecommendations((response) => {
                if (response.success) {
                    this.updateCarousel(response.data.products);
                }
            }, 12);
        }
        
        updateCarousel(products) {
            const $container = this.$element.find('.products');
            $container.empty();
            
            products.forEach(product => {
                const $product = $(`
                    <div class="product" data-product-id="${product.id}">
                        <a href="${product.link}">
                            <img src="${product.image}" alt="${product.title}" loading="lazy">
                            <h3>${product.title}</h3>
                            <span class="price">${product.price}</span>
                        </a>
                    </div>
                `);
                
                $container.append($product);
            });
            
            // Animasyon
            $container.find('.product').each((index, el) => {
                setTimeout(() => {
                    $(el).addClass('fade-in');
                }, index * 50);
            });
        }
    }
    
    // 15. BAŞLAT
    $(document).ready(() => {
        // Tracker'ı başlat
        window.bsTracker = new BiSiparisTracker();
        
        // Personalized carousel'leri başlat
        $('.bs-personalized-carousel').each(function() {
            new PersonalizedCarousel(this);
        });
        
        // Debug modu
        if (window.location.hash === '#bs-debug') {
            console.log('BS Tracker Profile:', window.bsTracker.userProfile);
            console.log('BS Tracker Fingerprint:', window.bsTracker.fingerprint);
        }
    });
    
})(jQuery);