<?php
/**
 * Productos - Gestión de Categorías
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) exit;

$categories = get_terms(array(
    'taxonomy'   => 'product_cat',
    'hide_empty' => false,
    'orderby'    => 'name',
));

// Build tree for parent dropdown
$cat_tree = array();
if ($categories && !is_wp_error($categories)) {
    foreach ($categories as $cat) {
        $cat_tree[$cat->parent][] = $cat;
    }
}
?>

<div class="chp-taxonomy-page">
    <!-- Form -->
    <div class="chp-taxonomy-form" data-taxonomy="category">
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-plus"></i> <?php _e('Agregar Categoría', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body">
                <input type="hidden" name="term_id" value="">

                <div class="chp-field">
                    <label><?php _e('Nombre', 'churrascoplanet-core'); ?></label>
                    <input type="text" name="term_name" placeholder="<?php esc_attr_e('Nombre de la categoría', 'churrascoplanet-core'); ?>">
                </div>

                <div class="chp-field">
                    <label><?php _e('Slug', 'churrascoplanet-core'); ?></label>
                    <input type="text" name="term_slug" placeholder="<?php esc_attr_e('Se genera automáticamente', 'churrascoplanet-core'); ?>">
                </div>

                <div class="chp-field">
                    <label><?php _e('Categoría padre', 'churrascoplanet-core'); ?></label>
                    <select name="term_parent">
                        <option value="0">— <?php _e('Ninguna', 'churrascoplanet-core'); ?> —</option>
                        <?php if ($categories && !is_wp_error($categories)) :
                            foreach ($categories as $cat) :
                                echo '<option value="' . $cat->term_id . '">' . str_repeat('— ', chp_get_cat_depth($cat->term_id, $categories)) . esc_html($cat->name) . '</option>';
                            endforeach;
                        endif; ?>
                    </select>
                </div>

                <div class="chp-field">
                    <label><?php _e('Descripción', 'churrascoplanet-core'); ?></label>
                    <textarea name="term_description" rows="3" placeholder="<?php esc_attr_e('Descripción opcional', 'churrascoplanet-core'); ?>"></textarea>
                </div>

                <div class="chp-field">
                    <label><?php _e('Imagen', 'churrascoplanet-core'); ?></label>
                    <input type="hidden" name="thumbnail_id" id="chp-cat-thumbnail-id" value="">
                    <div id="chp-cat-image-preview"></div>
                    <button type="button" id="chp-select-cat-image" class="chp-btn chp-btn-sm chp-btn-outline">
                        <i class="fas fa-image"></i> <?php _e('Seleccionar imagen', 'churrascoplanet-core'); ?>
                    </button>
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
                        <th style="width:50px;"><?php _e('Imagen', 'churrascoplanet-core'); ?></th>
                        <th><?php _e('Nombre', 'churrascoplanet-core'); ?></th>
                        <th><?php _e('Descripción', 'churrascoplanet-core'); ?></th>
                        <th style="width:100px;"><?php _e('Slug', 'churrascoplanet-core'); ?></th>
                        <th style="width:70px;"><?php _e('Productos', 'churrascoplanet-core'); ?></th>
                        <th style="width:120px;"><?php _e('Acciones', 'churrascoplanet-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categories && !is_wp_error($categories)) :
                        chp_render_cat_rows($cat_tree, 0, 0);
                    else : ?>
                        <tr><td colspan="6" style="text-align:center;color:var(--cp-text-muted);padding:30px;"><?php _e('No hay categorías', 'churrascoplanet-core'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
function chp_render_cat_rows($tree, $parent_id, $depth) {
    if (!isset($tree[$parent_id])) return;
    foreach ($tree[$parent_id] as $cat) {
        $thumb_id = get_term_meta($cat->term_id, 'thumbnail_id', true);
        $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'thumbnail') : '';
        $prefix = str_repeat('— ', $depth);
        ?>
        <tr data-id="<?php echo $cat->term_id; ?>"
            data-name="<?php echo esc_attr($cat->name); ?>"
            data-slug="<?php echo esc_attr($cat->slug); ?>"
            data-parent="<?php echo $cat->parent; ?>"
            data-description="<?php echo esc_attr($cat->description); ?>">
            <td>
                <?php if ($thumb_url) : ?>
                    <img src="<?php echo esc_url($thumb_url); ?>" class="chp-cat-thumb" alt="">
                <?php else : ?>
                    <div class="chp-cat-thumb-placeholder"><i class="fas fa-image"></i></div>
                <?php endif; ?>
            </td>
            <td><strong><?php echo $prefix . esc_html($cat->name); ?></strong></td>
            <td style="color:var(--cp-text-muted);font-size:12px;"><?php echo esc_html(wp_trim_words($cat->description, 10, '...')); ?></td>
            <td style="color:var(--cp-text-muted);font-size:12px;"><?php echo esc_html($cat->slug); ?></td>
            <td style="text-align:center;"><?php echo $cat->count; ?></td>
            <td>
                <a href="#" class="chp-edit-term" style="color:var(--cp-primary);text-decoration:none;font-size:12px;"><?php _e('Editar', 'churrascoplanet-core'); ?></a>
                <span style="color:rgba(255,255,255,0.2);margin:0 4px;">|</span>
                <a href="#" class="chp-delete-term" data-id="<?php echo $cat->term_id; ?>" data-taxonomy="category" style="color:#dc3545;text-decoration:none;font-size:12px;"><?php _e('Eliminar', 'churrascoplanet-core'); ?></a>
            </td>
        </tr>
        <?php
        chp_render_cat_rows($tree, $cat->term_id, $depth + 1);
    }
}

function chp_get_cat_depth($term_id, $all_terms) {
    $depth = 0;
    $terms_by_id = array();
    foreach ($all_terms as $t) $terms_by_id[$t->term_id] = $t;
    $current = $terms_by_id[$term_id] ?? null;
    while ($current && $current->parent > 0) {
        $depth++;
        $current = $terms_by_id[$current->parent] ?? null;
    }
    return $depth;
}
?>
