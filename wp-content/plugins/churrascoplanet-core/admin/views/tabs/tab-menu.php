<?php
/**
 * Tab: Menu
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

$option_name = $admin->get_option_name();
?>

<!-- Header Menu -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-heading"></i>
        <?php _e('Encabezado de Menu', 'churrascoplanet-core'); ?>
    </h2>

    <div class="options-grid">
        <div class="option-field">
            <label><?php _e('Texto del Badge', 'churrascoplanet-core'); ?></label>
            <div class="input-with-icon">
                <?php $admin->render_icon_field('menu_badge_icon', $admin->get_option('menu_badge_icon', 'fas fa-meteor')); ?>
                <input type="text"
                       name="<?php echo $option_name; ?>[menu_badge_text]"
                       value="<?php echo esc_attr($admin->get_option('menu_badge_text', 'Nuestro Menu')); ?>"
                       class="regular-text">
            </div>
        </div>

        <div class="option-field full-width">
            <label><?php _e('Titulo', 'churrascoplanet-core'); ?></label>
            <?php
            $admin->render_editor_field(
                'menu_titulo',
                'Planeta de <span class="highlight">Sabores</span>'
            );
            ?>
            <?php $admin->render_color_field('menu_titulo_color', '#ffffff'); ?>
        </div>

        <div class="option-field full-width">
            <label><?php _e('Subtitulo/Parrafo', 'churrascoplanet-core'); ?></label>
            <?php
            $admin->render_editor_field(
                'menu_subtitulo',
                'Explora nuestra galaxia de churrascos y mas'
            );
            ?>
            <?php $admin->render_color_field('menu_subtitulo_color', 'rgba(255,255,255,0.7)'); ?>
        </div>
    </div>
</div>

<!-- Delivery Banner -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-motorcycle"></i>
        <?php _e('Banner de Delivery', 'churrascoplanet-core'); ?>
    </h2>

    <div class="options-grid">
        <div class="option-field">
            <label><?php _e('Titulo', 'churrascoplanet-core'); ?></label>
            <div class="input-with-icon">
                <?php $admin->render_icon_field('delivery_titulo_icon', $admin->get_option('delivery_titulo_icon', 'fas fa-rocket')); ?>
                <input type="text"
                       name="<?php echo $option_name; ?>[delivery_titulo]"
                       value="<?php echo esc_attr($admin->get_option('delivery_titulo', 'Delivery a Velocidad Luz')); ?>"
                       class="regular-text">
            </div>
        </div>

        <div class="option-field full-width">
            <label><?php _e('Parrafo', 'churrascoplanet-core'); ?></label>
            <?php
            $admin->render_editor_field(
                'delivery_parrafo',
                'Pedidos hasta 4km con codigo promocional. Delivery gratis en combos seleccionados.'
            );
            ?>
        </div>
    </div>
</div>

<!-- Opciones de Delivery -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-boxes"></i>
        <?php _e('Opciones de Entrega', 'churrascoplanet-core'); ?>
    </h2>

    <div class="repeater-field" data-repeater="delivery_opciones">
        <div class="repeater-items">
            <?php
            $delivery_opciones = $admin->get_option('delivery_opciones', array(
                array('icon' => 'fas fa-store', 'text' => 'Retiro en Tienda'),
                array('icon' => 'fas fa-motorcycle', 'text' => 'Delivery Express'),
                array('icon' => 'fas fa-calendar-alt', 'text' => 'Programar Pedido'),
            ));
            foreach ($delivery_opciones as $index => $opcion) :
            ?>
            <div class="repeater-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php echo esc_html($opcion['text'] ?? 'Opcion'); ?></span>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content">
                    <div class="option-field">
                        <label><?php _e('Icono', 'churrascoplanet-core'); ?></label>
                        <?php $admin->render_icon_field("delivery_opciones][{$index}][icon", $opcion['icon'] ?? 'fas fa-box'); ?>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Texto', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[delivery_opciones][<?php echo $index; ?>][text]"
                               value="<?php echo esc_attr($opcion['text'] ?? ''); ?>"
                               class="regular-text">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button add-repeater-item">
            <i class="fas fa-plus"></i> <?php _e('Agregar Opcion', 'churrascoplanet-core'); ?>
        </button>
        <script type="text/html" class="repeater-template">
            <div class="repeater-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php _e('Nueva Opcion', 'churrascoplanet-core'); ?></span>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content">
                    <div class="option-field">
                        <label><?php _e('Icono', 'churrascoplanet-core'); ?></label>
                        <div class="icon-field">
                            <span class="icon-preview"><i class="fas fa-box"></i></span>
                            <input type="text" name="<?php echo $option_name; ?>[delivery_opciones][{{index}}][icon]" value="fas fa-box" class="regular-text icon-input">
                        </div>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Texto', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[delivery_opciones][{{index}}][text]" value="" class="regular-text">
                    </div>
                </div>
            </div>
        </script>
    </div>
</div>
