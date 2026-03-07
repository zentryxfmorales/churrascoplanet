<?php
/**
 * ChurrascoPlanet Customizer
 *
 * @package ChurrascoPlanet
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agregar opciones al Customizer
 */
function churrascoplanet_customize_register($wp_customize) {

    // Sección: Información del Negocio
    $wp_customize->add_section('churrascoplanet_business', array(
        'title'    => __('Información del Negocio', 'churrascoplanet'),
        'priority' => 30,
    ));

    // WhatsApp
    $wp_customize->add_setting('churrascoplanet_whatsapp', array(
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('churrascoplanet_whatsapp', array(
        'label'   => __('Número WhatsApp', 'churrascoplanet'),
        'section' => 'churrascoplanet_business',
        'type'    => 'text',
    ));

    // Horario
    $wp_customize->add_setting('churrascoplanet_hours', array(
        'default'           => 'Lun - Dom: 12:00 - 22:00',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('churrascoplanet_hours', array(
        'label'   => __('Horario de Atención', 'churrascoplanet'),
        'section' => 'churrascoplanet_business',
        'type'    => 'text',
    ));

    // Sección: Redes Sociales
    $wp_customize->add_section('churrascoplanet_social', array(
        'title'    => __('Redes Sociales', 'churrascoplanet'),
        'priority' => 35,
    ));

    $social_networks = array('facebook', 'instagram', 'tiktok');

    foreach ($social_networks as $network) {
        $wp_customize->add_setting("churrascoplanet_{$network}", array(
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
        ));
        $wp_customize->add_control("churrascoplanet_{$network}", array(
            'label'   => ucfirst($network),
            'section' => 'churrascoplanet_social',
            'type'    => 'url',
        ));
    }

    // Sección: Hero Banner
    $wp_customize->add_section('churrascoplanet_hero', array(
        'title'    => __('Banner Hero (Inicio)', 'churrascoplanet'),
        'priority' => 25,
    ));

    // Banner Promocional - Imagen
    $wp_customize->add_setting('hero_banner_image', array(
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ));
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'hero_banner_image', array(
        'label'       => __('Imagen Banner Promocional', 'churrascoplanet'),
        'description' => __('Imagen que aparece a la derecha del hero. Tamaño recomendado: 500x625px (ratio 4:5)', 'churrascoplanet'),
        'section'     => 'churrascoplanet_hero',
    )));

    // Banner Promocional - Etiqueta
    $wp_customize->add_setting('hero_banner_tag', array(
        'default'           => 'Promoción',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('hero_banner_tag', array(
        'label'   => __('Etiqueta del Banner', 'churrascoplanet'),
        'section' => 'churrascoplanet_hero',
        'type'    => 'text',
    ));

    // Banner Promocional - Título
    $wp_customize->add_setting('hero_banner_title', array(
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('hero_banner_title', array(
        'label'       => __('Título del Banner', 'churrascoplanet'),
        'description' => __('Ej: 2x1 en Churrascos', 'churrascoplanet'),
        'section'     => 'churrascoplanet_hero',
        'type'        => 'text',
    ));

    // Banner Promocional - Subtítulo
    $wp_customize->add_setting('hero_banner_subtitle', array(
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('hero_banner_subtitle', array(
        'label'       => __('Subtítulo del Banner', 'churrascoplanet'),
        'description' => __('Ej: Solo por este fin de semana', 'churrascoplanet'),
        'section'     => 'churrascoplanet_hero',
        'type'        => 'text',
    ));

    // Banner Promocional - Enlace
    $wp_customize->add_setting('hero_banner_link', array(
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ));
    $wp_customize->add_control('hero_banner_link', array(
        'label'       => __('Enlace del Banner', 'churrascoplanet'),
        'description' => __('URL a donde dirige al hacer clic (categoría, producto, etc.)', 'churrascoplanet'),
        'section'     => 'churrascoplanet_hero',
        'type'        => 'url',
    ));

    // Banner Promocional - Texto Botón
    $wp_customize->add_setting('hero_banner_button', array(
        'default'           => 'Ver Oferta',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('hero_banner_button', array(
        'label'   => __('Texto del Botón', 'churrascoplanet'),
        'section' => 'churrascoplanet_hero',
        'type'    => 'text',
    ));

    // Sección: Colores
    $wp_customize->add_setting('churrascoplanet_primary_color', array(
        'default'           => '#ffb268',
        'sanitize_callback' => 'sanitize_hex_color',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'churrascoplanet_primary_color', array(
        'label'   => __('Color Primario', 'churrascoplanet'),
        'section' => 'colors',
    )));

    $wp_customize->add_setting('churrascoplanet_secondary_color', array(
        'default'           => '#ffde59',
        'sanitize_callback' => 'sanitize_hex_color',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'churrascoplanet_secondary_color', array(
        'label'   => __('Color Secundario', 'churrascoplanet'),
        'section' => 'colors',
    )));
}
add_action('customize_register', 'churrascoplanet_customize_register');

/**
 * Inyectar CSS personalizado
 */
function churrascoplanet_customizer_css() {
    $primary   = get_theme_mod('churrascoplanet_primary_color', '#ffb268');
    $secondary = get_theme_mod('churrascoplanet_secondary_color', '#ffde59');
    ?>
    <style type="text/css">
        :root {
            --color-orange: <?php echo esc_attr($primary); ?>;
            --color-yellow: <?php echo esc_attr($secondary); ?>;
        }
    </style>
    <?php
}
add_action('wp_head', 'churrascoplanet_customizer_css');
