<?php
/**
 * Tab: Locales
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

$option_name = $admin->get_option_name();
?>

<!-- Header Locales -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-map-marker-alt"></i>
        <?php _e('Encabezado de Locales', 'churrascoplanet-core'); ?>
    </h2>

    <div class="options-grid">
        <div class="option-field">
            <label><?php _e('Texto del Badge', 'churrascoplanet-core'); ?></label>
            <div class="input-with-icon">
                <?php $admin->render_icon_field('locales_badge_icon', $admin->get_option('locales_badge_icon', 'fas fa-map-marker-alt')); ?>
                <input type="text"
                       name="<?php echo $option_name; ?>[locales_badge_text]"
                       value="<?php echo esc_attr($admin->get_option('locales_badge_text', 'Encuentranos')); ?>"
                       class="regular-text">
            </div>
        </div>

        <div class="option-field full-width">
            <label><?php _e('Titulo', 'churrascoplanet-core'); ?></label>
            <?php
            $admin->render_editor_field(
                'locales_titulo',
                'Nuestros <span class="highlight">Locales</span>'
            );
            ?>
            <?php $admin->render_color_field('locales_titulo_color', '#ffffff'); ?>
        </div>

        <div class="option-field full-width">
            <label><?php _e('Subtitulo/Parrafo', 'churrascoplanet-core'); ?></label>
            <?php
            $admin->render_editor_field(
                'locales_subtitulo',
                '4 estaciones espaciales en Santiago listas para servirte'
            );
            ?>
            <?php $admin->render_color_field('locales_subtitulo_color', 'rgba(255,255,255,0.7)'); ?>
        </div>
    </div>
</div>

<!-- Lista de Locales -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-store"></i>
        <?php _e('Locales', 'churrascoplanet-core'); ?>
    </h2>

    <div class="repeater-field" data-repeater="locales_lista">
        <div class="repeater-items">
            <?php
            $locales = $admin->get_option('locales_lista', array(
                array('nombre' => 'Nunoa', 'subtitulo' => 'Estacion Nunoa', 'whatsapp' => '', 'icon' => 'fas fa-satellite'),
                array('nombre' => 'Providencia', 'subtitulo' => 'Estacion Providencia', 'whatsapp' => '', 'icon' => 'fas fa-satellite'),
                array('nombre' => 'Santiago Centro', 'subtitulo' => 'Estacion Central', 'whatsapp' => '', 'icon' => 'fas fa-satellite'),
                array('nombre' => 'Maipu', 'subtitulo' => 'Estacion Maipu', 'whatsapp' => '', 'icon' => 'fas fa-satellite'),
            ));
            foreach ($locales as $index => $local) :
            ?>
            <div class="repeater-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php echo esc_html($local['nombre'] ?? 'Local'); ?></span>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content">
                    <div class="option-field">
                        <label><?php _e('Icono', 'churrascoplanet-core'); ?></label>
                        <?php $admin->render_icon_field("locales_lista][{$index}][icon", $local['icon'] ?? 'fas fa-satellite'); ?>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Nombre del Local', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[locales_lista][<?php echo $index; ?>][nombre]"
                               value="<?php echo esc_attr($local['nombre'] ?? ''); ?>"
                               class="regular-text">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Subtitulo', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[locales_lista][<?php echo $index; ?>][subtitulo]"
                               value="<?php echo esc_attr($local['subtitulo'] ?? ''); ?>"
                               class="regular-text">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Numero WhatsApp (con codigo pais, ej: 56912345678)', 'churrascoplanet-core'); ?></label>
                        <input type="text"
                               name="<?php echo $option_name; ?>[locales_lista][<?php echo $index; ?>][whatsapp]"
                               value="<?php echo esc_attr($local['whatsapp'] ?? ''); ?>"
                               class="regular-text"
                               placeholder="56912345678">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button add-repeater-item">
            <i class="fas fa-plus"></i> <?php _e('Agregar Local', 'churrascoplanet-core'); ?>
        </button>
        <script type="text/html" class="repeater-template">
            <div class="repeater-item">
                <div class="repeater-item-header">
                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="item-title"><?php _e('Nuevo Local', 'churrascoplanet-core'); ?></span>
                    <button type="button" class="button-link remove-item"><i class="fas fa-trash"></i></button>
                </div>
                <div class="repeater-item-content">
                    <div class="option-field">
                        <label><?php _e('Icono', 'churrascoplanet-core'); ?></label>
                        <div class="icon-field">
                            <span class="icon-preview"><i class="fas fa-satellite"></i></span>
                            <input type="text" name="<?php echo $option_name; ?>[locales_lista][{{index}}][icon]" value="fas fa-satellite" class="regular-text icon-input">
                        </div>
                    </div>
                    <div class="option-field">
                        <label><?php _e('Nombre del Local', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[locales_lista][{{index}}][nombre]" value="" class="regular-text">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Subtitulo', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[locales_lista][{{index}}][subtitulo]" value="" class="regular-text">
                    </div>
                    <div class="option-field">
                        <label><?php _e('Numero WhatsApp', 'churrascoplanet-core'); ?></label>
                        <input type="text" name="<?php echo $option_name; ?>[locales_lista][{{index}}][whatsapp]" value="" class="regular-text" placeholder="56912345678">
                    </div>
                </div>
            </div>
        </script>
    </div>
</div>
