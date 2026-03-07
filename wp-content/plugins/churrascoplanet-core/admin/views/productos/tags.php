<?php
/**
 * Productos - Gestión de Etiquetas
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) exit;

$tags = get_terms(array(
    'taxonomy'   => 'product_tag',
    'hide_empty' => false,
    'orderby'    => 'name',
));
?>

<div class="chp-taxonomy-page">
    <!-- Form -->
    <div class="chp-taxonomy-form" data-taxonomy="tag">
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-plus"></i> <?php _e('Agregar Etiqueta', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body">
                <input type="hidden" name="term_id" value="">

                <div class="chp-field">
                    <label><?php _e('Nombre', 'churrascoplanet-core'); ?></label>
                    <input type="text" name="term_name" placeholder="<?php esc_attr_e('Nombre de la etiqueta', 'churrascoplanet-core'); ?>">
                </div>

                <div class="chp-field">
                    <label><?php _e('Slug', 'churrascoplanet-core'); ?></label>
                    <input type="text" name="term_slug" placeholder="<?php esc_attr_e('Se genera automáticamente', 'churrascoplanet-core'); ?>">
                </div>

                <div class="chp-field">
                    <label><?php _e('Descripción', 'churrascoplanet-core'); ?></label>
                    <textarea name="term_description" rows="3" placeholder="<?php esc_attr_e('Descripción opcional', 'churrascoplanet-core'); ?>"></textarea>
                </div>

                <button type="button" id="chp-save-term" class="chp-btn chp-btn-primary" style="width:100%;">
                    <i class="fas fa-save"></i> <?php _e('Agregar', 'churrascoplanet-core'); ?>
                </button>
                <a href="#" class="chp-cancel-edit chp-btn chp-btn-outline chp-btn-sm" style="width:100%;text-align:center;margin-top:8px;display:none;">
                    <?php _e('Cancelar edición', 'churrascoplanet-core'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div>
        <div class="chp-table-wrap">
            <table class="chp-table">
                <thead>
                    <tr>
                        <th><?php _e('Nombre', 'churrascoplanet-core'); ?></th>
                        <th><?php _e('Descripción', 'churrascoplanet-core'); ?></th>
                        <th style="width:120px;"><?php _e('Slug', 'churrascoplanet-core'); ?></th>
                        <th style="width:70px;"><?php _e('Productos', 'churrascoplanet-core'); ?></th>
                        <th style="width:120px;"><?php _e('Acciones', 'churrascoplanet-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tags && !is_wp_error($tags)) :
                        foreach ($tags as $tag) : ?>
                        <tr data-id="<?php echo $tag->term_id; ?>"
                            data-name="<?php echo esc_attr($tag->name); ?>"
                            data-slug="<?php echo esc_attr($tag->slug); ?>"
                            data-description="<?php echo esc_attr($tag->description); ?>">
                            <td><strong><?php echo esc_html($tag->name); ?></strong></td>
                            <td style="color:var(--cp-text-muted);font-size:12px;"><?php echo esc_html(wp_trim_words($tag->description, 10, '...')); ?></td>
                            <td style="color:var(--cp-text-muted);font-size:12px;"><?php echo esc_html($tag->slug); ?></td>
                            <td style="text-align:center;"><?php echo $tag->count; ?></td>
                            <td>
                                <a href="#" class="chp-edit-term" style="color:var(--cp-primary);text-decoration:none;font-size:12px;"><?php _e('Editar', 'churrascoplanet-core'); ?></a>
                                <span style="color:rgba(255,255,255,0.2);margin:0 4px;">|</span>
                                <a href="#" class="chp-delete-term" data-id="<?php echo $tag->term_id; ?>" data-taxonomy="tag" style="color:#dc3545;text-decoration:none;font-size:12px;"><?php _e('Eliminar', 'churrascoplanet-core'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach;
                    else : ?>
                        <tr><td colspan="5" style="text-align:center;color:var(--cp-text-muted);padding:30px;"><?php _e('No hay etiquetas', 'churrascoplanet-core'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
