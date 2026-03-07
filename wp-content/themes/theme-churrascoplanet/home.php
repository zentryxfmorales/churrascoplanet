<?php
/**
 * ChurrascoPlanet - Front Page Template
 * Template para la página de inicio
 *
 * @package ChurrascoPlanet
 */

get_header();
?>

<!-- Hero Section -->
<section id="inicio" class="hero">
    <div class="hero-content">
        <div class="hero-badge">
            <i class="fas fa-rocket"></i>
            <span><?php _e('Sabor de Otro Planeta', 'churrascoplanet'); ?></span>
        </div>
        <h1 class="hero-title">
            <span class="highlight"><?php _e('Churrascos', 'churrascoplanet'); ?></span> <?php _e('que te llevan', 'churrascoplanet'); ?>
            <br><?php _e('a las', 'churrascoplanet'); ?> <span class="highlight"><?php _e('estrellas', 'churrascoplanet'); ?></span>
        </h1>
        <p class="hero-subtitle">
            <?php _e('Los mejores churrascos de Santiago, ahora con delivery hasta tu puerta.', 'churrascoplanet'); ?>
            <br><?php _e('4 locales listos para servirte.', 'churrascoplanet'); ?>
        </p>
        <div class="hero-buttons">
            <a href="#menu" class="btn btn-primary">
                <i class="fas fa-utensils"></i>
                <?php _e('Ver Menu', 'churrascoplanet'); ?>
            </a>
            <a href="#promociones" class="btn btn-secondary">
                <i class="fas fa-tags"></i>
                <?php _e('Promociones', 'churrascoplanet'); ?>
            </a>
        </div>
        <div class="hero-info">
            <div class="info-item">
                <i class="fas fa-clock"></i>
                <span><?php _e('Delivery Express', 'churrascoplanet'); ?></span>
            </div>
            <div class="info-item">
                <i class="fas fa-map-marker-alt"></i>
                <span><?php _e('4 Locales en Santiago', 'churrascoplanet'); ?></span>
            </div>
            <div class="info-item">
                <i class="fas fa-star"></i>
                <span><?php _e('+10.000 Clientes Felices', 'churrascoplanet'); ?></span>
            </div>
        </div>
    </div>
    <div class="hero-image">
        <div class="planet-ring"></div>
        <?php if (has_custom_logo()) : ?>
            <?php
            $custom_logo_id = get_theme_mod('custom_logo');
            $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
            ?>
            <img src="<?php echo esc_url($logo[0]); ?>" alt="<?php bloginfo('name'); ?>" class="floating">
        <?php else : ?>
            <img src="<?php echo CHURRASCOPLANET_URI; ?>/img/logo.png" alt="ChurrascoPlanet" class="floating">
        <?php endif; ?>
    </div>
</section>

<!-- Categorias -->
<section class="categories">
    <div class="container">
        <div class="categories-grid">
            <?php
            if (class_exists('WooCommerce')) :
                $categories = get_terms(array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'exclude'    => array(get_option('default_product_cat')),
                    'number'     => 4,
                ));

                $icons = array(
                    'churrascos' => 'fas fa-hamburger',
                    'promociones' => 'fas fa-percentage',
                    'acompañamientos' => 'fas fa-utensils',
                    'acompanamientos' => 'fas fa-utensils',
                    'bebidas' => 'fas fa-glass-cheers',
                );

                if ($categories && !is_wp_error($categories)) :
                    foreach ($categories as $cat) :
                        $icon_class = isset($icons[sanitize_title($cat->name)]) ? $icons[sanitize_title($cat->name)] : 'fas fa-star';
            ?>
            <a href="<?php echo get_term_link($cat); ?>" class="category-card" data-category="<?php echo esc_attr($cat->slug); ?>">
                <div class="category-icon">
                    <i class="<?php echo esc_attr($icon_class); ?>"></i>
                </div>
                <h3><?php echo esc_html($cat->name); ?></h3>
                <p><?php echo esc_html($cat->description ? $cat->description : 'Ver productos'); ?></p>
            </a>
            <?php
                    endforeach;
                endif;
            else :
            ?>
            <div class="category-card" data-category="churrascos">
                <div class="category-icon">
                    <i class="fas fa-hamburger"></i>
                </div>
                <h3><?php _e('Churrascos', 'churrascoplanet'); ?></h3>
                <p><?php _e('El clasico de la casa', 'churrascoplanet'); ?></p>
            </div>
            <div class="category-card" data-category="promociones">
                <div class="category-icon">
                    <i class="fas fa-percentage"></i>
                </div>
                <h3><?php _e('Promociones', 'churrascoplanet'); ?></h3>
                <p><?php _e('Ofertas de otro planeta', 'churrascoplanet'); ?></p>
            </div>
            <div class="category-card" data-category="acompanamiento">
                <div class="category-icon">
                    <i class="fas fa-utensils"></i>
                </div>
                <h3><?php _e('Acompañamientos', 'churrascoplanet'); ?></h3>
                <p><?php _e('Papas y mas', 'churrascoplanet'); ?></p>
            </div>
            <div class="category-card" data-category="bebidas">
                <div class="category-icon">
                    <i class="fas fa-glass-cheers"></i>
                </div>
                <h3><?php _e('Bebidas', 'churrascoplanet'); ?></h3>
                <p><?php _e('Hidratacion planetaria', 'churrascoplanet'); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Promociones Section -->
<section id="promociones" class="promos-section">
    <div class="container">
        <div class="section-header">
            <span class="section-badge">
                <i class="fas fa-fire"></i>
                <?php _e('Ofertas Especiales', 'churrascoplanet'); ?>
            </span>
            <h2 class="section-title"><?php _e('Promociones de', 'churrascoplanet'); ?> <span class="highlight"><?php _e('Otro Planeta', 'churrascoplanet'); ?></span></h2>
            <p class="section-subtitle"><?php _e('Aprovecha nuestras ofertas galacticas antes de que despeguen', 'churrascoplanet'); ?></p>
        </div>
        <div class="promos-grid">
            <?php
            if (class_exists('WooCommerce')) :
                $args = array(
                    'post_type'      => 'product',
                    'posts_per_page' => 3,
                    'meta_query'     => array(
                        'relation' => 'OR',
                        array(
                            'key'     => '_sale_price',
                            'value'   => 0,
                            'compare' => '>',
                            'type'    => 'NUMERIC'
                        ),
                    ),
                    'tax_query'      => array(
                        array(
                            'taxonomy' => 'product_cat',
                            'field'    => 'slug',
                            'terms'    => 'promociones',
                        ),
                    ),
                );

                $promos = new WP_Query($args);

                if ($promos->have_posts()) :
                    $count = 0;
                    while ($promos->have_posts()) : $promos->the_post();
                        global $product;
                        $count++;
                        $regular_price = $product->get_regular_price();
                        $sale_price = $product->get_sale_price();
                        $discount = $sale_price ? round((($regular_price - $sale_price) / $regular_price) * 100) : 0;
            ?>
            <div class="promo-card <?php echo $count === 1 ? 'featured' : ''; ?>">
                <?php if ($discount > 0) : ?>
                <div class="promo-badge">-<?php echo $discount; ?>%</div>
                <?php endif; ?>
                <div class="promo-image">
                    <?php if (has_post_thumbnail()) : ?>
                        <?php the_post_thumbnail('product-card'); ?>
                    <?php else : ?>
                        <img src="https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400" alt="<?php the_title_attribute(); ?>">
                    <?php endif; ?>
                </div>
                <div class="promo-content">
                    <h3><?php the_title(); ?></h3>
                    <p><?php echo wp_trim_words(get_the_excerpt(), 6); ?></p>
                    <div class="promo-prices">
                        <?php if ($sale_price) : ?>
                            <span class="old-price"><?php echo wc_price($regular_price); ?></span>
                            <span class="new-price"><?php echo wc_price($sale_price); ?></span>
                        <?php else : ?>
                            <span class="new-price"><?php echo wc_price($regular_price); ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" class="btn btn-add-cart" data-product_id="<?php echo get_the_ID(); ?>">
                        <i class="fas fa-cart-plus"></i>
                        <?php _e('Agregar', 'churrascoplanet'); ?>
                    </a>
                </div>
            </div>
            <?php
                    endwhile;
                    wp_reset_postdata();
                else :
                    // Mostrar promos de ejemplo si no hay productos
            ?>
            <div class="promo-card featured">
                <div class="promo-badge">-34%</div>
                <div class="promo-image">
                    <img src="https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400" alt="Combo Doble">
                </div>
                <div class="promo-content">
                    <h3><?php _e('Combo Doble Planetario', 'churrascoplanet'); ?></h3>
                    <p><?php _e('2 Sandwich + 2 Bebidas', 'churrascoplanet'); ?></p>
                    <div class="promo-prices">
                        <span class="old-price">$15.000</span>
                        <span class="new-price">$9.800</span>
                    </div>
                    <button class="btn btn-add-cart">
                        <i class="fas fa-cart-plus"></i>
                        <?php _e('Agregar', 'churrascoplanet'); ?>
                    </button>
                </div>
            </div>
            <div class="promo-card">
                <div class="promo-badge">-27%</div>
                <div class="promo-image">
                    <img src="https://images.unsplash.com/photo-1551782450-17144efb9c50?w=400" alt="2 Churrascos">
                </div>
                <div class="promo-content">
                    <h3><?php _e('Duo Churrasco', 'churrascoplanet'); ?></h3>
                    <p><?php _e('2 Churrascos completos', 'churrascoplanet'); ?></p>
                    <div class="promo-prices">
                        <span class="old-price">$12.000</span>
                        <span class="new-price">$8.750</span>
                    </div>
                    <button class="btn btn-add-cart">
                        <i class="fas fa-cart-plus"></i>
                        <?php _e('Agregar', 'churrascoplanet'); ?>
                    </button>
                </div>
            </div>
            <div class="promo-card">
                <div class="promo-badge">-52%</div>
                <div class="promo-image">
                    <img src="https://images.unsplash.com/photo-1594212699903-ec8a3eca50f5?w=400" alt="2 Lomitos">
                </div>
                <div class="promo-content">
                    <h3><?php _e('Duo Lomito', 'churrascoplanet'); ?></h3>
                    <p><?php _e('2 Sandwich de Lomo', 'churrascoplanet'); ?></p>
                    <div class="promo-prices">
                        <span class="old-price">$17.600</span>
                        <span class="new-price">$8.500</span>
                    </div>
                    <button class="btn btn-add-cart">
                        <i class="fas fa-cart-plus"></i>
                        <?php _e('Agregar', 'churrascoplanet'); ?>
                    </button>
                </div>
            </div>
            <?php
                endif;
            endif;
            ?>
        </div>
    </div>
</section>

<!-- Menu Section -->
<section id="menu" class="menu-section">
    <div class="container">
        <div class="section-header">
            <span class="section-badge">
                <i class="fas fa-meteor"></i>
                <?php _e('Nuestro Menu', 'churrascoplanet'); ?>
            </span>
            <h2 class="section-title"><?php _e('Planeta de', 'churrascoplanet'); ?> <span class="highlight"><?php _e('Sabores', 'churrascoplanet'); ?></span></h2>
            <p class="section-subtitle"><?php _e('Explora nuestra galaxia de churrascos y mas', 'churrascoplanet'); ?></p>
        </div>

        <!-- Menu Filter -->
        <div class="menu-filter">
            <button class="filter-btn active" data-filter="all"><?php _e('Todos', 'churrascoplanet'); ?></button>
            <?php
            if (class_exists('WooCommerce')) :
                $categories = get_terms(array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => true,
                    'exclude'    => array(get_option('default_product_cat')),
                ));

                if ($categories && !is_wp_error($categories)) :
                    foreach ($categories as $cat) :
            ?>
            <button class="filter-btn" data-filter="<?php echo esc_attr($cat->slug); ?>"><?php echo esc_html($cat->name); ?></button>
            <?php
                    endforeach;
                endif;
            endif;
            ?>
        </div>

        <!-- Products Grid -->
        <div class="products-grid">
            <?php
            if (class_exists('WooCommerce')) :
                $args = array(
                    'post_type'      => 'product',
                    'posts_per_page' => 9,
                    'orderby'        => 'menu_order',
                    'order'          => 'ASC',
                );

                $products = new WP_Query($args);

                if ($products->have_posts()) :
                    while ($products->have_posts()) : $products->the_post();
                        global $product;
                        $categories = get_the_terms(get_the_ID(), 'product_cat');
                        $cat_slug = $categories ? $categories[0]->slug : '';
                        $cat_name = $categories ? $categories[0]->name : '';
            ?>
            <div class="product-card" data-category="<?php echo esc_attr($cat_slug); ?>">
                <div class="product-image">
                    <?php if (has_post_thumbnail()) : ?>
                        <?php the_post_thumbnail('product-card'); ?>
                    <?php else : ?>
                        <img src="https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400" alt="<?php the_title_attribute(); ?>">
                    <?php endif; ?>
                    <div class="product-overlay">
                        <a href="<?php the_permalink(); ?>" class="btn-quick-view">
                            <i class="fas fa-eye"></i>
                        </a>
                    </div>
                </div>
                <div class="product-content">
                    <span class="product-category"><?php echo esc_html($cat_name); ?></span>
                    <h3 class="product-name"><?php the_title(); ?></h3>
                    <p class="product-description"><?php echo wp_trim_words(get_the_excerpt(), 8); ?></p>
                    <div class="product-footer">
                        <span class="product-price"><?php echo $product->get_price_html(); ?></span>
                        <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" class="btn btn-add-cart ajax_add_to_cart" data-product_id="<?php echo get_the_ID(); ?>">
                            <i class="fas fa-plus"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php
                    endwhile;
                    wp_reset_postdata();
                else :
            ?>
            <!-- Productos de ejemplo si no hay productos -->
            <div class="product-card" data-category="churrascos">
                <div class="product-image">
                    <img src="https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400" alt="Churrasco Completo">
                    <div class="product-overlay">
                        <button class="btn-quick-view">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="product-content">
                    <span class="product-category"><?php _e('Churrascos', 'churrascoplanet'); ?></span>
                    <h3 class="product-name"><?php _e('Churrasco Completo', 'churrascoplanet'); ?></h3>
                    <p class="product-description"><?php _e('Carne de vacuno, tomate, mayo, palta, chucrut', 'churrascoplanet'); ?></p>
                    <div class="product-footer">
                        <span class="product-price">$6.000</span>
                        <button class="btn btn-add-cart">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php
                endif;
            endif;
            ?>
        </div>

        <?php if (class_exists('WooCommerce')) : ?>
        <div style="text-align: center; margin-top: 50px;">
            <a href="<?php echo get_permalink(wc_get_page_id('shop')); ?>" class="btn btn-primary">
                <i class="fas fa-store"></i>
                <?php _e('Ver Tienda Completa', 'churrascoplanet'); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Delivery Banner -->
<section class="delivery-banner">
    <div class="container">
        <div class="delivery-content">
            <div class="delivery-text">
                <h2><i class="fas fa-rocket"></i> <?php _e('Delivery a Velocidad Luz', 'churrascoplanet'); ?></h2>
                <p><?php _e('Pedidos hasta 4km con codigo promocional. Delivery gratis en combos seleccionados.', 'churrascoplanet'); ?></p>
            </div>
            <div class="delivery-options">
                <div class="option">
                    <i class="fas fa-store"></i>
                    <span><?php _e('Retiro en Tienda', 'churrascoplanet'); ?></span>
                </div>
                <div class="option">
                    <i class="fas fa-motorcycle"></i>
                    <span><?php _e('Delivery Express', 'churrascoplanet'); ?></span>
                </div>
                <div class="option">
                    <i class="fas fa-calendar-alt"></i>
                    <span><?php _e('Programar Pedido', 'churrascoplanet'); ?></span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Locales Section -->
<section id="locales" class="locations-section">
    <div class="container">
        <div class="section-header">
            <span class="section-badge">
                <i class="fas fa-map-marker-alt"></i>
                <?php _e('Encuentranos', 'churrascoplanet'); ?>
            </span>
            <h2 class="section-title"><?php _e('Nuestros', 'churrascoplanet'); ?> <span class="highlight"><?php _e('Locales', 'churrascoplanet'); ?></span></h2>
            <p class="section-subtitle"><?php _e('4 estaciones espaciales en Santiago listas para servirte', 'churrascoplanet'); ?></p>
        </div>
        <div class="locations-grid">
            <div class="location-card">
                <div class="location-icon">
                    <i class="fas fa-satellite"></i>
                </div>
                <h3><?php _e('Nunoa', 'churrascoplanet'); ?></h3>
                <p><?php _e('Estacion Nunoa', 'churrascoplanet'); ?></p>
                <a href="#" class="btn btn-location">
                    <i class="fab fa-whatsapp"></i>
                    <?php _e('Contactar', 'churrascoplanet'); ?>
                </a>
            </div>
            <div class="location-card">
                <div class="location-icon">
                    <i class="fas fa-satellite"></i>
                </div>
                <h3><?php _e('Providencia', 'churrascoplanet'); ?></h3>
                <p><?php _e('Estacion Providencia', 'churrascoplanet'); ?></p>
                <a href="#" class="btn btn-location">
                    <i class="fab fa-whatsapp"></i>
                    <?php _e('Contactar', 'churrascoplanet'); ?>
                </a>
            </div>
            <div class="location-card">
                <div class="location-icon">
                    <i class="fas fa-satellite"></i>
                </div>
                <h3><?php _e('Santiago Centro', 'churrascoplanet'); ?></h3>
                <p><?php _e('Estacion Central', 'churrascoplanet'); ?></p>
                <a href="#" class="btn btn-location">
                    <i class="fab fa-whatsapp"></i>
                    <?php _e('Contactar', 'churrascoplanet'); ?>
                </a>
            </div>
            <div class="location-card">
                <div class="location-icon">
                    <i class="fas fa-satellite"></i>
                </div>
                <h3><?php _e('Maipu', 'churrascoplanet'); ?></h3>
                <p><?php _e('Estacion Maipu', 'churrascoplanet'); ?></p>
                <a href="#" class="btn btn-location">
                    <i class="fab fa-whatsapp"></i>
                    <?php _e('Contactar', 'churrascoplanet'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<?php
// El carrito sidebar se carga vía wp_footer en inc/woocommerce.php
get_footer();
