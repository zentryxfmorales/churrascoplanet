<?php
/**
 * ChurrascoPlanet - Front Page Template
 * Template para la página de inicio
 * Usa las opciones del panel "Aspecto PlanetaChurrascos"
 *
 * @package ChurrascoPlanet
 */

get_header();

// Obtener opciones del panel de administración
$hero_badge = churrascoplanet_get_option('inicio_hero_badge', 'Sabor de Otro Planeta');
$hero_titulo = churrascoplanet_get_option('inicio_hero_titulo', '<span class="highlight">Churrascos</span> que te llevan<br>a las <span class="highlight">estrellas</span>');
$hero_subtitulo = churrascoplanet_get_option('inicio_hero_subtitulo', 'Los mejores churrascos de Santiago, ahora con delivery hasta tu puerta.<br>4 locales listos para servirte.');
$btn_primary_text = churrascoplanet_get_option('inicio_btn_primary_text', 'Ver Menú');
$btn_primary_url = churrascoplanet_get_option('inicio_btn_primary_url', '#menu');
$btn_secondary_text = churrascoplanet_get_option('inicio_btn_secondary_text', 'Promociones');
$btn_secondary_url = churrascoplanet_get_option('inicio_btn_secondary_url', '#promociones');

$info_items = churrascoplanet_get_option('inicio_info_items', array(
    array('icon' => 'fas fa-clock', 'text' => 'Delivery Express'),
    array('icon' => 'fas fa-map-marker-alt', 'text' => '4 Locales en Santiago'),
    array('icon' => 'fas fa-star', 'text' => '+10.000 Clientes Felices'),
));

// Banner
$banner_image = churrascoplanet_get_option('inicio_banner_image', '');
$banner_tag = churrascoplanet_get_option('inicio_banner_tag', 'Promoción');
$banner_title = churrascoplanet_get_option('inicio_banner_title', '');
$banner_subtitle = churrascoplanet_get_option('inicio_banner_subtitle', '');
$banner_url = churrascoplanet_get_option('inicio_banner_url', '');
$banner_btn = churrascoplanet_get_option('inicio_banner_btn', 'Ver Oferta');

// Fallback al customizer si no hay datos en el panel
if (empty($banner_image)) {
    $banner_image = get_theme_mod('hero_banner_image', '');
}
if (empty($banner_title)) {
    $banner_title = get_theme_mod('hero_banner_title', '');
}
if (empty($banner_url)) {
    $banner_url = get_theme_mod('hero_banner_link', '');
}

// Categorías personalizadas
$categorias_config = churrascoplanet_get_option('inicio_categorias', array());
?>

<!-- Hero Section -->
<section id="inicio" class="hero">
    <div class="container">
        <div class="hero-grid">
            <!-- Columna Izquierda: Logo + Contenido -->
            <div class="hero-left">
                <div class="hero-logo-float">
                    <div class="planet-ring"></div>
                    <?php
                    $hero_logo = churrascoplanet_get_option('inicio_hero_logo', '');
                    if ($hero_logo) :
                    ?>
                        <img src="<?php echo esc_url($hero_logo); ?>" alt="<?php bloginfo('name'); ?>" class="floating">
                    <?php elseif (has_custom_logo()) : ?>
                        <?php
                        $custom_logo_id = get_theme_mod('custom_logo');
                        $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
                        ?>
                        <img src="<?php echo esc_url($logo[0]); ?>" alt="<?php bloginfo('name'); ?>" class="floating">
                    <?php else : ?>
                        <img src="<?php echo CHURRASCOPLANET_URI; ?>/img/logo.png" alt="ChurrascoPlanet" class="floating">
                    <?php endif; ?>
                </div>
                <div class="hero-content">
                    <div class="hero-badge" style="<?php echo churrascoplanet_get_option('inicio_hero_badge_color') ? 'color:' . esc_attr(churrascoplanet_get_option('inicio_hero_badge_color')) . ';' : ''; ?>">
                        <i class="fas fa-rocket"></i>
                        <span><?php echo esc_html($hero_badge); ?></span>
                    </div>
                    <h1 class="hero-title" style="<?php echo churrascoplanet_get_option('inicio_hero_titulo_color') ? 'color:' . esc_attr(churrascoplanet_get_option('inicio_hero_titulo_color')) . ';' : ''; ?>">
                        <?php echo wp_kses_post($hero_titulo); ?>
                    </h1>
                    <p class="hero-subtitle" style="<?php echo churrascoplanet_get_option('inicio_hero_subtitulo_color') ? 'color:' . esc_attr(churrascoplanet_get_option('inicio_hero_subtitulo_color')) . ';' : ''; ?>">
                        <?php echo wp_kses_post($hero_subtitulo); ?>
                    </p>
                    <div class="hero-buttons">
                        <a href="<?php echo esc_url($btn_primary_url); ?>" class="btn btn-primary">
                            <i class="fas fa-utensils"></i>
                            <?php echo esc_html($btn_primary_text); ?>
                        </a>
                        <a href="<?php echo esc_url($btn_secondary_url); ?>" class="btn btn-secondary">
                            <i class="fas fa-tags"></i>
                            <?php echo esc_html($btn_secondary_text); ?>
                        </a>
                    </div>
                    <div class="hero-info">
                        <?php if (is_array($info_items)) : foreach ($info_items as $item) : ?>
                        <div class="info-item">
                            <i class="<?php echo esc_attr($item['icon'] ?? 'fas fa-star'); ?>"></i>
                            <span><?php echo esc_html($item['text'] ?? ''); ?></span>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha: Banner Promocional -->
            <div class="hero-right">
                <?php
                $banner_wrapper_tag = $banner_url ? 'a' : 'div';
                $banner_wrapper_attr = $banner_url ? 'href="' . esc_url($banner_url) . '"' : '';
                ?>
                <<?php echo $banner_wrapper_tag; ?> <?php echo $banner_wrapper_attr; ?> class="hero-banner<?php echo $banner_url ? ' hero-banner-link' : ''; ?>">
                    <?php if ($banner_image) : ?>
                        <img src="<?php echo esc_url($banner_image); ?>" alt="<?php echo esc_attr($banner_title ? $banner_title : __('Promoción Especial', 'churrascoplanet')); ?>" class="banner-img">
                    <?php else : ?>
                        <div class="banner-placeholder">
                            <div class="banner-placeholder-content">
                                <i class="fas fa-image"></i>
                                <span><?php _e('Banner Promocional', 'churrascoplanet'); ?></span>
                                <p><?php _e('Configura desde PlanetaChurrascos > Inicio', 'churrascoplanet'); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="banner-overlay">
                        <?php if ($banner_tag) : ?>
                            <span class="banner-tag"><?php echo esc_html($banner_tag); ?></span>
                        <?php endif; ?>

                        <?php if ($banner_title || $banner_subtitle) : ?>
                            <div class="banner-content">
                                <?php if ($banner_title) : ?>
                                    <h3 class="banner-title"><?php echo esc_html($banner_title); ?></h3>
                                <?php endif; ?>
                                <?php if ($banner_subtitle) : ?>
                                    <p class="banner-subtitle"><?php echo esc_html($banner_subtitle); ?></p>
                                <?php endif; ?>
                                <?php if ($banner_url && $banner_btn) : ?>
                                    <span class="banner-btn"><?php echo esc_html($banner_btn); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </<?php echo $banner_wrapper_tag; ?>>
            </div>
        </div>
    </div>
</section>

<!-- Categorias -->
<section class="categories">
    <div class="container">
        <div class="categories-grid">
            <?php
            if (class_exists('WooCommerce') && !empty($categorias_config)) :
                // Usar configuración del panel
                foreach ($categorias_config as $cat_config) :
                    $cat_slug = $cat_config['slug'] ?? '';
                    $cat_icon = $cat_config['icon'] ?? 'fas fa-star';
                    $custom_name = $cat_config['custom_name'] ?? '';
                    $custom_desc = $cat_config['custom_desc'] ?? '';

                    // Obtener categoría de WooCommerce
                    $wc_cat = get_term_by('slug', $cat_slug, 'product_cat');

                    if ($wc_cat) :
                        $cat_name = $custom_name ?: $wc_cat->name;
                        $cat_desc = $custom_desc ?: ($wc_cat->description ?: __('Ver productos', 'churrascoplanet'));
            ?>
            <a href="<?php echo get_term_link($wc_cat); ?>" class="category-card" data-category="<?php echo esc_attr($cat_slug); ?>">
                <div class="category-icon">
                    <i class="<?php echo esc_attr($cat_icon); ?>"></i>
                </div>
                <h3><?php echo esc_html($cat_name); ?></h3>
                <p><?php echo esc_html($cat_desc); ?></p>
            </a>
            <?php
                    endif;
                endforeach;
            elseif (class_exists('WooCommerce')) :
                // Fallback: obtener categorías automáticamente
                $categories = get_terms(array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'exclude'    => array(get_option('default_product_cat')),
                    'number'     => 4,
                ));

                $icons = array(
                    'churrascos' => 'fas fa-hamburger',
                    'promociones' => 'fas fa-percentage',
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
                <p><?php echo esc_html($cat->description ? $cat->description : __('Ver productos', 'churrascoplanet')); ?></p>
            </a>
            <?php
                    endforeach;
                endif;
            endif;
            ?>
        </div>
    </div>
</section>

<?php
// Opciones de Promociones
$promo_badge_icon = churrascoplanet_get_option('promo_badge_icon', 'fas fa-fire');
$promo_badge_text = churrascoplanet_get_option('promo_badge_text', 'Ofertas Especiales');
$promo_titulo = churrascoplanet_get_option('promo_titulo', 'Promociones de <span class="highlight">Otro Planeta</span>');
$promo_subtitulo = churrascoplanet_get_option('promo_subtitulo', 'Aprovecha nuestras ofertas galácticas antes de que despeguen');
?>

<!-- Promociones Section -->
<section id="promociones" class="promos-section">
    <div class="container">
        <div class="section-header">
            <span class="section-badge">
                <i class="<?php echo esc_attr($promo_badge_icon); ?>"></i>
                <?php echo esc_html($promo_badge_text); ?>
            </span>
            <h2 class="section-title" style="<?php echo churrascoplanet_get_option('promo_titulo_color') ? 'color:' . esc_attr(churrascoplanet_get_option('promo_titulo_color')) . ';' : ''; ?>">
                <?php echo wp_kses_post($promo_titulo); ?>
            </h2>
            <p class="section-subtitle" style="<?php echo churrascoplanet_get_option('promo_subtitulo_color') ? 'color:' . esc_attr(churrascoplanet_get_option('promo_subtitulo_color')) . ';' : ''; ?>">
                <?php echo wp_kses_post($promo_subtitulo); ?>
            </p>
        </div>
        <div class="promos-grid">
            <?php
            if (class_exists('WooCommerce')) :
                // Mostrar productos con precio de oferta O de la categoría "promociones"
                $args = array(
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => 3,
                    'meta_query'     => array(
                        array(
                            'key'     => '_sale_price',
                            'value'   => '',
                            'compare' => '!='
                        ),
                    ),
                );

                $promos = new WP_Query($args);

                // Si no hay productos con descuento, buscar en categoría "promociones"
                if (!$promos->have_posts()) {
                    $args = array(
                        'post_type'      => 'product',
                        'post_status'    => 'publish',
                        'posts_per_page' => 3,
                        'tax_query'      => array(
                            array(
                                'taxonomy' => 'product_cat',
                                'field'    => 'slug',
                                'terms'    => 'promociones',
                            ),
                        ),
                    );
                    $promos = new WP_Query($args);
                }

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
                    <button type="button" class="btn btn-add-cart btn-open-modal" data-product-id="<?php echo get_the_ID(); ?>">
                        <i class="fas fa-cart-plus"></i>
                        <?php _e('Agregar', 'churrascoplanet'); ?>
                    </button>
                </div>
            </div>
            <?php
                    endwhile;
                    wp_reset_postdata();
                else :
            ?>
            <div class="empty-promos">
                <i class="fas fa-tags"></i>
                <p><?php _e('No hay promociones disponibles actualmente.', 'churrascoplanet'); ?></p>
            </div>
            <?php
                endif;
            endif;
            ?>
        </div>
    </div>
</section>

<?php
// Opciones de Menú
$menu_badge_icon = churrascoplanet_get_option('menu_badge_icon', 'fas fa-meteor');
$menu_badge_text = churrascoplanet_get_option('menu_badge_text', 'Nuestro Menú');
$menu_titulo = churrascoplanet_get_option('menu_titulo', 'Planeta de <span class="highlight">Sabores</span>');
$menu_subtitulo = churrascoplanet_get_option('menu_subtitulo', 'Explora nuestra galaxia de churrascos y más');
?>

<!-- Menu Section -->
<section id="menu" class="menu-section">
    <div class="container">
        <div class="section-header">
            <span class="section-badge">
                <i class="<?php echo esc_attr($menu_badge_icon); ?>"></i>
                <?php echo esc_html($menu_badge_text); ?>
            </span>
            <h2 class="section-title" style="<?php echo churrascoplanet_get_option('menu_titulo_color') ? 'color:' . esc_attr(churrascoplanet_get_option('menu_titulo_color')) . ';' : ''; ?>">
                <?php echo wp_kses_post($menu_titulo); ?>
            </h2>
            <p class="section-subtitle" style="<?php echo churrascoplanet_get_option('menu_subtitulo_color') ? 'color:' . esc_attr(churrascoplanet_get_option('menu_subtitulo_color')) . ';' : ''; ?>">
                <?php echo wp_kses_post($menu_subtitulo); ?>
            </p>
        </div>

        <!-- Menu Filter -->
        <div class="menu-filter">
            <button class="filter-btn active" data-filter="all"><?php _e('Todos', 'churrascoplanet'); ?></button>
            <?php
            if (class_exists('WooCommerce')) :
                // Orden definido de categorías
                $cat_order_slugs = array(
                    'promociones-de-otro-planeta',
                    'planeta-sabores',
                    'misiles-planetarios',
                    'planeta-frito',
                    'hidratacion-planetaria',
                    'aderezo-interestelar',
                );
                foreach ($cat_order_slugs as $cat_slug) :
                    $cat = get_term_by('slug', $cat_slug, 'product_cat');
                    if ($cat && !is_wp_error($cat)) :
            ?>
            <button class="filter-btn" data-filter="<?php echo esc_attr($cat->slug); ?>"><?php echo esc_html($cat->name); ?></button>
            <?php
                    endif;
                endforeach;
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
            <div class="product-card" data-category="<?php echo esc_attr($cat_slug); ?>" data-product-id="<?php echo get_the_ID(); ?>">
                <div class="product-image">
                    <?php if (has_post_thumbnail()) : ?>
                        <?php the_post_thumbnail('product-card'); ?>
                    <?php else : ?>
                        <img src="https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400" alt="<?php the_title_attribute(); ?>">
                    <?php endif; ?>
                    <?php if ($product->is_on_sale()) :
                        $regular = (float) $product->get_regular_price();
                        $sale = (float) $product->get_sale_price();
                        $disc = ($regular > 0 && $sale) ? round((($regular - $sale) / $regular) * 100) : 0;
                    ?>
                        <span class="promo-badge"><?php echo $disc > 0 ? '-' . $disc . '%' : esc_html__('Oferta', 'churrascoplanet'); ?></span>
                    <?php endif; ?>
                    <div class="product-overlay">
                        <button type="button" class="btn-quick-view btn-open-modal" data-product-id="<?php echo get_the_ID(); ?>">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="product-content">
                    <span class="product-category"><?php echo esc_html($cat_name); ?></span>
                    <h3 class="product-name"><?php the_title(); ?></h3>
                    <p class="product-description"><?php echo wp_trim_words(get_the_excerpt(), 8); ?></p>
                    <div class="product-footer">
                        <span class="product-price"><?php echo $product->get_price_html(); ?></span>
                        <button type="button" class="btn btn-add-cart btn-open-modal" data-product-id="<?php echo get_the_ID(); ?>">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php
                    endwhile;
                    wp_reset_postdata();
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

<?php
// Opciones de Delivery
$delivery_titulo_icon = churrascoplanet_get_option('delivery_titulo_icon', 'fas fa-rocket');
$delivery_titulo = churrascoplanet_get_option('delivery_titulo', 'Delivery a Velocidad Luz');
$delivery_parrafo = churrascoplanet_get_option('delivery_parrafo', 'Pedidos hasta 4km con código promocional. Delivery gratis en combos seleccionados.');
$delivery_opciones = churrascoplanet_get_option('delivery_opciones', array(
    array('icon' => 'fas fa-store', 'text' => 'Retiro en Tienda'),
    array('icon' => 'fas fa-motorcycle', 'text' => 'Delivery Express'),
    array('icon' => 'fas fa-calendar-alt', 'text' => 'Programar Pedido'),
));
?>

<!-- Delivery Banner -->
<section class="delivery-banner">
    <div class="container">
        <div class="delivery-content">
            <div class="delivery-text">
                <h2><i class="<?php echo esc_attr($delivery_titulo_icon); ?>"></i> <?php echo esc_html($delivery_titulo); ?></h2>
                <p><?php echo wp_kses_post($delivery_parrafo); ?></p>
            </div>
            <div class="delivery-options">
                <?php if (is_array($delivery_opciones)) : foreach ($delivery_opciones as $opcion) : ?>
                <div class="option">
                    <i class="<?php echo esc_attr($opcion['icon'] ?? 'fas fa-box'); ?>"></i>
                    <span><?php echo esc_html($opcion['text'] ?? ''); ?></span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</section>

<?php
// Opciones de Locales
$locales_badge_icon = churrascoplanet_get_option('locales_badge_icon', 'fas fa-map-marker-alt');
$locales_badge_text = churrascoplanet_get_option('locales_badge_text', 'Encuéntranos');
$locales_titulo = churrascoplanet_get_option('locales_titulo', 'Nuestros <span class="highlight">Locales</span>');
$locales_subtitulo = churrascoplanet_get_option('locales_subtitulo', '4 estaciones espaciales en Santiago listas para servirte');
$locales_lista = churrascoplanet_get_option('locales_lista', array(
    array('nombre' => 'Ñuñoa', 'subtitulo' => 'Estación Ñuñoa', 'whatsapp' => '', 'icon' => 'fas fa-satellite'),
    array('nombre' => 'Providencia', 'subtitulo' => 'Estación Providencia', 'whatsapp' => '', 'icon' => 'fas fa-satellite'),
    array('nombre' => 'Santiago Centro', 'subtitulo' => 'Estación Central', 'whatsapp' => '', 'icon' => 'fas fa-satellite'),
    array('nombre' => 'Maipú', 'subtitulo' => 'Estación Maipú', 'whatsapp' => '', 'icon' => 'fas fa-satellite'),
));
?>

<!-- Locales Section -->
<section id="locales" class="locations-section">
    <div class="container">
        <div class="section-header">
            <span class="section-badge">
                <i class="<?php echo esc_attr($locales_badge_icon); ?>"></i>
                <?php echo esc_html($locales_badge_text); ?>
            </span>
            <h2 class="section-title" style="<?php echo churrascoplanet_get_option('locales_titulo_color') ? 'color:' . esc_attr(churrascoplanet_get_option('locales_titulo_color')) . ';' : ''; ?>">
                <?php echo wp_kses_post($locales_titulo); ?>
            </h2>
            <p class="section-subtitle" style="<?php echo churrascoplanet_get_option('locales_subtitulo_color') ? 'color:' . esc_attr(churrascoplanet_get_option('locales_subtitulo_color')) . ';' : ''; ?>">
                <?php echo wp_kses_post($locales_subtitulo); ?>
            </p>
        </div>
        <div class="locations-grid">
            <?php if (is_array($locales_lista)) : foreach ($locales_lista as $local) :
                $whatsapp_link = !empty($local['whatsapp']) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $local['whatsapp']) : '#';
            ?>
            <div class="location-card">
                <div class="location-icon">
                    <i class="<?php echo esc_attr($local['icon'] ?? 'fas fa-satellite'); ?>"></i>
                </div>
                <h3><?php echo esc_html($local['nombre'] ?? ''); ?></h3>
                <p><?php echo esc_html($local['subtitulo'] ?? ''); ?></p>
                <a href="<?php echo esc_url($whatsapp_link); ?>" class="btn btn-location" <?php echo $whatsapp_link !== '#' ? 'target="_blank"' : ''; ?>>
                    <i class="fab fa-whatsapp"></i>
                    <?php _e('Contactar', 'churrascoplanet'); ?>
                </a>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</section>

<?php
// El carrito sidebar se carga vía wp_footer en inc/woocommerce.php
get_footer();
