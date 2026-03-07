<?php
/**
 * Tab: Colores
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
        <i class="fas fa-palette"></i>
        <?php _e('Colores Globales', 'churrascoplanet-core'); ?>
    </h2>

    <p class="description"><?php _e('Estos colores se aplicaran globalmente a todo el sitio.', 'churrascoplanet-core'); ?></p>

    <div class="options-grid colors-grid">
        <div class="option-field">
            <label><?php _e('Color Principal (Naranja)', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_primary', '#ffb268'); ?>
        </div>
        <div class="option-field">
            <label><?php _e('Color Secundario (Amarillo)', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_secondary', '#ffde59'); ?>
        </div>
        <div class="option-field">
            <label><?php _e('Color de Fondo', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_background', '#000000'); ?>
        </div>
        <div class="option-field">
            <label><?php _e('Color de Texto', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_text', '#ffffff'); ?>
        </div>
        <div class="option-field">
            <label><?php _e('Color de Cajas/Cards', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_cards', '#1a1a1a'); ?>
        </div>
        <div class="option-field">
            <label><?php _e('Color de Bordes', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_borders', 'rgba(255,178,104,0.1)'); ?>
        </div>
    </div>
</div>

<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-font"></i>
        <?php _e('Colores de Tipografia por Seccion', 'churrascoplanet-core'); ?>
    </h2>

    <div class="options-grid">
        <div class="option-field">
            <label><?php _e('Titulos Principales', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_titulos', '#ffffff'); ?>
        </div>
        <div class="option-field">
            <label><?php _e('Subtitulos', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_subtitulos', 'rgba(255,255,255,0.8)'); ?>
        </div>
        <div class="option-field">
            <label><?php _e('Parrafos', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_parrafos', 'rgba(255,255,255,0.7)'); ?>
        </div>
        <div class="option-field">
            <label><?php _e('Texto Destacado (highlight)', 'churrascoplanet-core'); ?></label>
            <?php $admin->render_color_field('color_highlight', '#ffb268'); ?>
        </div>
    </div>
</div>
