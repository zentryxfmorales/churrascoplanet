<?php
/**
 * Tab: Navegacion
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

$option_name = $admin->get_option_name();
?>

<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-bars"></i>
        <?php _e('Menu Principal de Navegacion', 'churrascoplanet-core'); ?>
    </h2>

    <p class="description"><?php _e('Configura los enlaces del menu principal que aparece en el header del sitio. Puedes agregar, quitar y reordenar los items.', 'churrascoplanet-core'); ?></p>

    <div class="repeater-field" data-repeater="nav_menu_items">
        <div class="repeater-items">
            <?php
            $nav_items = $admin->get_option('nav_menu_items', array(
                array('texto' => 'Inicio', 'url' => '#inicio', 'icon' => '', 'target' => '', 'visible' => '1'),
                array('texto' => 'Menu', 'url' => '#menu', 'icon' => '', 'target' => '', 'visible' => '1'),
                array('texto' => 'Promociones', 'url' => '#promociones', 'icon' => '', 'target' => '', 'visible' => '1'),
                array('texto' => 'Locales', 'url' => '#locales', 'icon' => '', 'target' => '', 'visible' => '1'),
                array('texto' => 'Tienda', 'url' => '/tienda', 'icon' => '', 'target' => '', 'visible' => '1'),
            ));
            foreach ($nav_items as $index => $item) :
            ?>
            <div class="repeater-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php echo esc_html($item['texto'] ?? 'Item'); ?></span>
                    <?php if (empty($item['visible']) || $item['visible'] === '0') : ?>
                        <span class="item-badge-hidden"><i class="fas fa-eye-slash"></i></span>
                    <?php endif; ?>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content">
                    <div class="option-field">
                        <label><?php _e('Texto del Enlace', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[nav_menu_items][<?php echo $index; ?>][texto]"
                               value="<?php echo esc_attr($item['texto'] ?? ''); ?>"
                               class="regular-text">
                    </div>
                    <div class="option-field">
                        <label><?php _e('URL / Enlace', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[nav_menu_items][<?php echo $index; ?>][url]"
                               value="<?php echo esc_attr($item['url'] ?? ''); ?>"
                               class="regular-text"
                               placeholder="#seccion o https://...">
                        <p class="description"><?php _e('Usa # para secciones de inicio (ej: #menu) o URL completa para otras paginas', 'churrascoplanet-core'); ?></p>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Icono (opcional)', 'churrascoplanet-core'); ?></label>
                        <?php $admin->render_icon_field("nav_menu_items][{$index}][icon", $item['icon'] ?? ''); ?>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Abrir en nueva pestana', 'churrascoplanet-core'); ?></label>
                        <select name="<?php echo $option_name; ?>[nav_menu_items][<?php echo $index; ?>][target]">
                            <option value="" <?php selected($item['target'] ?? '', ''); ?>><?php _e('No (misma ventana)', 'churrascoplanet-core'); ?></option>
                            <option value="_blank" <?php selected($item['target'] ?? '', '_blank'); ?>><?php _e('Si (nueva pestana)', 'churrascoplanet-core'); ?></option>
                        </select>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Visible', 'churrascoplanet-core'); ?></label>
                        <select name="<?php echo $option_name; ?>[nav_menu_items][<?php echo $index; ?>][visible]">
                            <option value="1" <?php selected($item['visible'] ?? '1', '1'); ?>><?php _e('Si - Visible', 'churrascoplanet-core'); ?></option>
                            <option value="0" <?php selected($item['visible'] ?? '1', '0'); ?>><?php _e('No - Oculto', 'churrascoplanet-core'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button add-repeater-item">
            <i class="fas fa-plus"></i> <?php _e('Agregar Item de Menu', 'churrascoplanet-core'); ?>
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
                        <label><?php _e('Texto del Enlace', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[nav_menu_items][{{index}}][texto]" value="" class="regular-text">
                    </div>
                    <div class="option-field">
                        <label><?php _e('URL / Enlace', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[nav_menu_items][{{index}}][url]" value="" class="regular-text" placeholder="#seccion o https://...">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Icono (opcional)', 'churrascoplanet-core'); ?></label>
                        <div class="icon-field">
                            <span class="icon-preview"><i class="fas fa-link"></i></span>
                            <input type="text" name="<?php echo $option_name; ?>[nav_menu_items][{{index}}][icon]" value="" class="regular-text icon-input" placeholder="fas fa-star">
                        </div>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Abrir en nueva pestana', 'churrascoplanet-core'); ?></label>
                        <select name="<?php echo $option_name; ?>[nav_menu_items][{{index}}][target]">
                            <option value=""><?php _e('No (misma ventana)', 'churrascoplanet-core'); ?></option>
                            <option value="_blank"><?php _e('Si (nueva pestana)', 'churrascoplanet-core'); ?></option>
                        </select>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Visible', 'churrascoplanet-core'); ?></label>
                        <select name="<?php echo $option_name; ?>[nav_menu_items][{{index}}][visible]">
                            <option value="1"><?php _e('Si - Visible', 'churrascoplanet-core'); ?></option>
                            <option value="0"><?php _e('No - Oculto', 'churrascoplanet-core'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
        </script>
    </div>
</div>

<div class="info-box">
    <i class="fas fa-info-circle"></i>
    <p><?php _e('Los enlaces con # (ej: #menu, #promociones) hacen scroll suave en la pagina de inicio. Para otras paginas usa la URL completa (ej: /tienda o https://...).', 'churrascoplanet-core'); ?></p>
</div>
