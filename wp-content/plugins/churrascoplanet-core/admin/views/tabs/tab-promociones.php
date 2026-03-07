<?php
/**
 * Tab: Promociones
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
        <i class="fas fa-tags"></i>
        <?php _e('Seccion de Promociones', 'churrascoplanet-core'); ?>
    </h2>

    <div class="options-grid">
        <div class="option-field">
            <label><?php _e('Texto del Badge', 'churrascoplanet-core'); ?></label>
            <div class="input-with-icon">
                <?php $admin->render_icon_field('promo_badge_icon', $admin->get_option('promo_badge_icon', 'fas fa-fire')); ?>
                <input type="text"
                       name="<?php echo $option_name; ?>[promo_badge_text]"
                       value="<?php echo esc_attr($admin->get_option('promo_badge_text', 'Ofertas Especiales')); ?>"
                       class="regular-text">
            </div>
        </div>

        <div class="option-field full-width">
            <label><?php _e('Titulo', 'churrascoplanet-core'); ?></label>
            <?php
            $admin->render_editor_field(
                'promo_titulo',
                'Promociones de <span class="highlight">Otro Planeta</span>'
            );
            ?>
            <?php $admin->render_color_field('promo_titulo_color', '#ffffff'); ?>
        </div>

        <div class="option-field full-width">
            <label><?php _e('Subtitulo/Parrafo', 'churrascoplanet-core'); ?></label>
            <?php
            $admin->render_editor_field(
                'promo_subtitulo',
                'Aprovecha nuestras ofertas galacticas antes de que despeguen'
            );
            ?>
            <?php $admin->render_color_field('promo_subtitulo_color', 'rgba(255,255,255,0.7)'); ?>
        </div>
    </div>

    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <p><?php _e('Las promociones se obtienen automaticamente de los productos en oferta de WooCommerce.', 'churrascoplanet-core'); ?></p>
    </div>
</div>
