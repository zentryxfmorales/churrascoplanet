<?php
/**
 * Tab: Inicio
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

$option_name = $admin->get_option_name();
?>

<!-- Hero Principal -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-star"></i>
        <?php _e('Hero Principal', 'churrascoplanet-core'); ?>
    </h2>

    <div class="options-grid">
        <!-- Logo -->
        <div class="option-field">
            <label><?php _e('Logo del Hero', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_image_field('inicio_hero_logo'); ?>
        </div>

        <!-- Badge -->
        <div class="option-field">
            <label><?php _e('Texto del Badge', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_hero_badge]"
                   value="<?php echo esc_attr($admin->get_option('inicio_hero_badge', 'Sabor de Otro Planeta')); ?>"
                   class="regular-text">
            <?php $admin->render_color_field('inicio_hero_badge_color', '#ffb268'); ?>
        </div>

        <!-- Titulo -->
        <div class="option-field full-width">
            <label><?php _e('Titulo Principal', 'churrascoplanet-core'); ?></label>
            <?php
            $admin->render_editor_field(
                'inicio_hero_titulo',
                '<span class="highlight">Churrascos</span> que te llevan<br>a las <span class="highlight">estrellas</span>'
            );
            ?>
            <?php $admin->render_color_field('inicio_hero_titulo_color', '#ffffff'); ?>
        </div>

        <!-- Subtitulo -->
        <div class="option-field full-width">
            <label><?php _e('Subtitulo', 'churrascoplanet-core'); ?></label>
            <?php
            $admin->render_editor_field(
                'inicio_hero_subtitulo',
                'Los mejores churrascos de Santiago, ahora con delivery hasta tu puerta.<br>4 locales listos para servirte.'
            );
            ?>
            <?php $admin->render_color_field('inicio_hero_subtitulo_color', 'rgba(255,255,255,0.8)'); ?>
        </div>

        <!-- Botones -->
        <div class="option-field">
            <label><?php _e('Boton Primario - Texto', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_btn_primary_text]"
                   value="<?php echo esc_attr($admin->get_option('inicio_btn_primary_text', 'Ver Menu')); ?>"
                   class="regular-text">
        </div>
        <div class="option-field">
            <label><?php _e('Boton Primario - Enlace', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_btn_primary_url]"
                   value="<?php echo esc_attr($admin->get_option('inicio_btn_primary_url', '#menu')); ?>"
                   class="regular-text">
        </div>
        <div class="option-field">
            <label><?php _e('Boton Secundario - Texto', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_btn_secondary_text]"
                   value="<?php echo esc_attr($admin->get_option('inicio_btn_secondary_text', 'Promociones')); ?>"
                   class="regular-text">
        </div>
        <div class="option-field">
            <label><?php _e('Boton Secundario - Enlace', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_btn_secondary_url]"
                   value="<?php echo esc_attr($admin->get_option('inicio_btn_secondary_url', '#promociones')); ?>"
                   class="regular-text">
        </div>
    </div>
</div>

<!-- Info Items -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-info-circle"></i>
        <?php _e('Informacion del Hero', 'churrascoplanet-core'); ?>
    </h2>

    <div class="repeater-field" data-repeater="inicio_info_items">
        <div class="repeater-items">
            <?php
            $info_items = $admin->get_option('inicio_info_items', array(
                array('icon' => 'fas fa-clock', 'text' => 'Delivery Express'),
                array('icon' => 'fas fa-map-marker-alt', 'text' => '4 Locales en Santiago'),
                array('icon' => 'fas fa-star', 'text' => '+10.000 Clientes Felices'),
            ));
            foreach ($info_items as $index => $item) :
            ?>
            <div class="repeater-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php echo esc_html($item['text'] ?? 'Item'); ?></span>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content">
                    <div class="option-field">
                        <label><?php _e('Icono (clase Font Awesome)', 'churrascoplanet-core'); ?></label>
                        <?php $admin->render_icon_field("inicio_info_items][{$index}][icon", $item['icon'] ?? 'fas fa-star'); ?>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Texto', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[inicio_info_items][<?php echo $index; ?>][text]"
                               value="<?php echo esc_attr($item['text'] ?? ''); ?>"
                               class="regular-text">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button add-repeater-item">
            <i class="fas fa-plus"></i> <?php _e('Agregar Item', 'churrascoplanet-core'); ?>
        </button>
        <script type="text/html" class="repeater-template">
            <div class="repeater-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php _e('Nuevo Item', 'churrascoplanet-core'); ?></span>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content">
                    <div class="option-field">
                        <label><?php _e('Icono (clase Font Awesome)', 'churrascoplanet-core'); ?></label>
                        <div class="icon-field">
                            <span class="icon-preview"><i class="fas fa-star"></i></span>
                            <input type="text" name="<?php echo $option_name; ?>[inicio_info_items][{{index}}][icon]" value="fas fa-star" class="regular-text icon-input">
                        </div>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Texto', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[inicio_info_items][{{index}}][text]" value="" class="regular-text">
                    </div>
                </div>
            </div>
        </script>
    </div>
</div>

<!-- Banner Promocional -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-image"></i>
        <?php _e('Banner Promocional', 'churrascoplanet-core'); ?>
    </h2>

    <div class="options-grid">
        <div class="option-field">
            <label><?php _e('Imagen del Banner', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_image_field('inicio_banner_image'); ?>
        </div>
        <div class="option-field">
            <label><?php _e('Etiqueta (Badge)', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_banner_tag]"
                   value="<?php echo esc_attr($admin->get_option('inicio_banner_tag', 'Promocion')); ?>"
                   class="regular-text">
        </div>
        <div class="option-field">
            <label><?php _e('Titulo del Banner', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_banner_title]"
                   value="<?php echo esc_attr($admin->get_option('inicio_banner_title', '')); ?>"
                   class="regular-text">
        </div>
        <div class="option-field">
            <label><?php _e('Subtitulo del Banner', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_banner_subtitle]"
                   value="<?php echo esc_attr($admin->get_option('inicio_banner_subtitle', '')); ?>"
                   class="regular-text">
        </div>
        <div class="option-field">
            <label><?php _e('Enlace del Banner', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_banner_url]"
                   value="<?php echo esc_attr($admin->get_option('inicio_banner_url', '')); ?>"
                   class="regular-text"
                   placeholder="https://">
        </div>
        <div class="option-field">
            <label><?php _e('Texto del Boton', 'churrascoplanet-core'); ?></label>
            <input type="text"
                   name="<?php echo $option_name; ?>[inicio_banner_btn]"
                   value="<?php echo esc_attr($admin->get_option('inicio_banner_btn', 'Ver Oferta')); ?>"
                   class="regular-text">
        </div>
    </div>
</div>

<!-- Categorias -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-th-large"></i>
        <?php _e('Cajas de Categorias', 'churrascoplanet-core'); ?>
    </h2>

    <p class="description"><?php _e('Las categorias se obtienen automaticamente de WooCommerce. Aqui puedes personalizar los iconos.', 'churrascoplanet-core'); ?></p>

    <div class="repeater-field" data-repeater="inicio_categorias">
        <div class="repeater-items">
            <?php
            $categorias = $admin->get_option('inicio_categorias', array(
                array('slug' => 'churrascos', 'icon' => 'fas fa-hamburger', 'custom_name' => '', 'custom_desc' => ''),
                array('slug' => 'promociones', 'icon' => 'fas fa-percentage', 'custom_name' => '', 'custom_desc' => ''),
                array('slug' => 'bebidas', 'icon' => 'fas fa-glass-cheers', 'custom_name' => '', 'custom_desc' => ''),
                array('slug' => 'acompanamiento', 'icon' => 'fas fa-utensils', 'custom_name' => '', 'custom_desc' => ''),
            ));
            foreach ($categorias as $index => $cat) :
            ?>
            <div class="repeater-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php echo esc_html($cat['slug'] ?? 'Categoria'); ?></span>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content">
                    <div class="option-field">
                        <label><?php _e('Slug de Categoria WooCommerce', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[inicio_categorias][<?php echo $index; ?>][slug]"
                               value="<?php echo esc_attr($cat['slug'] ?? ''); ?>"
                               class="regular-text">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Icono', 'churrascoplanet-core'); ?></label>
                        <?php $admin->render_icon_field("inicio_categorias][{$index}][icon", $cat['icon'] ?? 'fas fa-star'); ?>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Nombre Personalizado (opcional)', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[inicio_categorias][<?php echo $index; ?>][custom_name]"
                               value="<?php echo esc_attr($cat['custom_name'] ?? ''); ?>"
                               class="regular-text"
                               placeholder="<?php _e('Dejar vacio para usar el de WooCommerce', 'churrascoplanet-core'); ?>">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Descripcion Personalizada (opcional)', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[inicio_categorias][<?php echo $index; ?>][custom_desc]"
                               value="<?php echo esc_attr($cat['custom_desc'] ?? ''); ?>"
                               class="regular-text">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button add-repeater-item">
            <i class="fas fa-plus"></i> <?php _e('Agregar Categoria', 'churrascoplanet-core'); ?>
        </button>
        <script type="text/html" class="repeater-template">
            <div class="repeater-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php _e('Nueva Categoria', 'churrascoplanet-core'); ?></span>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content">
                    <div class="option-field">
                        <label><?php _e('Slug de Categoria WooCommerce', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[inicio_categorias][{{index}}][slug]" value="" class="regular-text">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Icono', 'churrascoplanet-core'); ?></label>
                        <div class="icon-field">
                            <span class="icon-preview"><i class="fas fa-star"></i></span>
                            <input type="text" name="<?php echo $option_name; ?>[inicio_categorias][{{index}}][icon]" value="fas fa-star" class="regular-text icon-input">
                        </div>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Nombre Personalizado (opcional)', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[inicio_categorias][{{index}}][custom_name]" value="" class="regular-text">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Descripcion Personalizada (opcional)', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[inicio_categorias][{{index}}][custom_desc]" value="" class="regular-text">
                    </div>
                </div>
            </div>
        </script>
    </div>
</div>
