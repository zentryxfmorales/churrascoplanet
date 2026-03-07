<?php
/**
 * ChurrascoPlanet Theme Functions
 *
 * @package ChurrascoPlanet
 * @version 1.0.0
 * @author Zentryx SPA
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constantes del tema
define('CHURRASCOPLANET_VERSION', '1.3.13');
define('CHURRASCOPLANET_DIR', get_template_directory());
define('CHURRASCOPLANET_URI', get_template_directory_uri());

// Cargar sistema de base de datos personalizada (tablas cp_chp_*)
require_once CHURRASCOPLANET_DIR . '/inc/database/init.php';

/**
 * Configuración inicial del tema
 */
function churrascoplanet_setup() {
    // Soporte para traducciones
    load_theme_textdomain('churrascoplanet', CHURRASCOPLANET_DIR . '/languages');

    // Soporte para título dinámico
    add_theme_support('title-tag');

    // Soporte para imágenes destacadas
    add_theme_support('post-thumbnails');

    // Tamaños de imagen personalizados
    add_image_size('product-card', 400, 300, true);
    add_image_size('product-large', 800, 600, true);
    add_image_size('hero-banner', 1920, 800, true);

    // Soporte para logo personalizado
    add_theme_support('custom-logo', array(
        'height'      => 100,
        'width'       => 300,
        'flex-height' => true,
        'flex-width'  => true,
    ));

    // Soporte para HTML5
    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ));

    // Soporte para WooCommerce
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');

    // Registrar menús
    register_nav_menus(array(
        'primary'   => __('Menú Principal', 'churrascoplanet'),
        'footer'    => __('Menú Footer', 'churrascoplanet'),
        'mobile'    => __('Menú Móvil', 'churrascoplanet'),
    ));
}
add_action('after_setup_theme', 'churrascoplanet_setup');

/**
 * Encolar estilos y scripts
 */
function churrascoplanet_scripts() {
    // Google Fonts
    wp_enqueue_style(
        'churrascoplanet-fonts',
        'https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Poppins:wght@300;400;500;600;700&display=swap',
        array(),
        null
    );

    // Font Awesome
    wp_enqueue_style(
        'font-awesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
        array(),
        '6.4.0'
    );

    // Style.css del tema (requerido por WordPress)
    wp_enqueue_style(
        'churrascoplanet-style',
        get_stylesheet_uri(),
        array(),
        CHURRASCOPLANET_VERSION
    );

    // Estilos principales
    wp_enqueue_style(
        'churrascoplanet-main',
        CHURRASCOPLANET_URI . '/css/main.css',
        array('churrascoplanet-style'),
        CHURRASCOPLANET_VERSION . '.' . filemtime( get_template_directory() . '/css/main.css' )
    );

    // Estilos de WooCommerce personalizados
    if (class_exists('WooCommerce')) {
        wp_enqueue_style(
            'churrascoplanet-woocommerce',
            CHURRASCOPLANET_URI . '/css/woocommerce.css',
            array('churrascoplanet-main'),
            CHURRASCOPLANET_VERSION
        );
    }

    // Scripts principales - con dependencias de WooCommerce AJAX
    $script_deps = array('jquery');

    // Agregar dependencias de WooCommerce si está activo
    if (class_exists('WooCommerce')) {
        $script_deps[] = 'wc-add-to-cart';
    }

    wp_enqueue_script(
        'churrascoplanet-main',
        CHURRASCOPLANET_URI . '/js/main.js',
        $script_deps,
        CHURRASCOPLANET_VERSION . '.' . filemtime( get_template_directory() . '/js/main.js' ),
        true
    );

    // Localizar script para AJAX
    wp_localize_script('churrascoplanet-main', 'churrascoplanet_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('churrascoplanet_nonce'),
    ));
}
add_action('wp_enqueue_scripts', 'churrascoplanet_scripts');

/**
 * Registrar widgets
 */
function churrascoplanet_widgets_init() {
    register_sidebar(array(
        'name'          => __('Sidebar Tienda', 'churrascoplanet'),
        'id'            => 'shop-sidebar',
        'description'   => __('Widgets para la tienda', 'churrascoplanet'),
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ));

    register_sidebar(array(
        'name'          => __('Footer Col 1', 'churrascoplanet'),
        'id'            => 'footer-1',
        'before_widget' => '<div class="footer-widget">',
        'after_widget'  => '</div>',
        'before_title'  => '<h4>',
        'after_title'   => '</h4>',
    ));

    register_sidebar(array(
        'name'          => __('Footer Col 2', 'churrascoplanet'),
        'id'            => 'footer-2',
        'before_widget' => '<div class="footer-widget">',
        'after_widget'  => '</div>',
        'before_title'  => '<h4>',
        'after_title'   => '</h4>',
    ));
}
add_action('widgets_init', 'churrascoplanet_widgets_init');

/**
 * Personalizar número de productos por página
 */
function churrascoplanet_products_per_page($cols) {
    return 9;
}
add_filter('loop_shop_per_page', 'churrascoplanet_products_per_page');

/**
 * Cambiar columnas de productos
 */
function churrascoplanet_loop_columns() {
    return 3;
}
add_filter('loop_shop_columns', 'churrascoplanet_loop_columns');

/**
 * Agregar clase al body
 */
function churrascoplanet_body_classes($classes) {
    $classes[] = 'churrascoplanet-theme';

    if (function_exists('is_shop') && (is_shop() || is_product_category() || is_product())) {
        $classes[] = 'woocommerce-active';
    }

    return $classes;
}
add_filter('body_class', 'churrascoplanet_body_classes');

/**
 * Formato de precio para Chile (sin decimales)
 */
function churrascoplanet_price_format($format, $currency_pos) {
    return '%1$s%2$s';
}
add_filter('woocommerce_price_format', 'churrascoplanet_price_format', 10, 2);

/**
 * Desactivar WooCommerce Coming Soon / Launch Your Store
 */
function churrascoplanet_disable_coming_soon() {
    // Desactivar coming soon de WooCommerce
    update_option('woocommerce_coming_soon', 'no');

    // Remover el filtro de coming soon si existe
    if (class_exists('Automattic\WooCommerce\Admin\Features\LaunchYourStore')) {
        remove_all_filters('woocommerce_coming_soon_exclude');
    }
}
add_action('init', 'churrascoplanet_disable_coming_soon', 1);

/**
 * Forzar que el tema no use el editor de bloques para templates
 */
function churrascoplanet_disable_block_templates() {
    // Remover soporte para block templates si fue agregado
    remove_theme_support('block-templates');
    return false;
}
add_filter('use_block_editor_for_post_type', '__return_false', 100);

/**
 * Desactivar el renderizado del bloque coming-soon
 */
function churrascoplanet_remove_coming_soon_block($content) {
    // Si estamos en el front-end y hay contenido de coming-soon, lo removemos
    if (!is_admin()) {
        $content = preg_replace('/<div[^>]*data-block-name="woocommerce\/coming-soon"[^>]*>.*?<\/div>/is', '', $content);
    }
    return $content;
}
add_filter('the_content', 'churrascoplanet_remove_coming_soon_block', 1);

/**
 * Remover el bloque coming-soon del output
 */
function churrascoplanet_ob_start() {
    if (!is_admin()) {
        ob_start('churrascoplanet_filter_output');
    }
}
function churrascoplanet_filter_output($buffer) {
    // Si el buffer contiene el bloque coming-soon, lo procesamos
    if (strpos($buffer, 'woocommerce/coming-soon') !== false) {
        // Remover todo el contenido del bloque coming-soon
        $buffer = preg_replace('/<div[^>]*class="[^"]*wp-site-blocks[^"]*"[^>]*>.*?<\/div>\s*$/is', '', $buffer);
    }
    return $buffer;
}

/**
 * Forzar el uso del template page-tienda.php para la página de tienda
 */
function churrascoplanet_shop_template($template) {
    if (function_exists('is_shop') && (is_shop() || is_post_type_archive('product'))) {
        $custom_template = CHURRASCOPLANET_DIR . '/page-tienda.php';
        if (file_exists($custom_template)) {
            return $custom_template;
        }
    }
    return $template;
}
add_filter('template_include', 'churrascoplanet_shop_template', 999);

/**
 * Incluir archivos adicionales
 */
require_once CHURRASCOPLANET_DIR . '/inc/customizer.php';
require_once CHURRASCOPLANET_DIR . '/inc/woocommerce.php';
require_once CHURRASCOPLANET_DIR . '/inc/admin-options.php';
require_once CHURRASCOPLANET_DIR . '/inc/product-addons.php';
