<?php
/**
 * Productos - Formulario Crear/Editar
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) exit;

$product_id = isset($product_id) ? absint($product_id) : 0;
$product    = $product_id ? wc_get_product($product_id) : null;
$is_edit    = (bool) $product;
$base_url   = admin_url('admin.php?page=churrascoplanet-productos');

// Datos del producto
$name              = $is_edit ? $product->get_name() : '';
$status            = $is_edit ? $product->get_status() : 'publish';
$regular_price     = $is_edit ? $product->get_regular_price() : '';
$sale_price        = $is_edit ? $product->get_sale_price() : '';
$sku               = $is_edit ? $product->get_sku() : '';
$manage_stock      = $is_edit ? $product->get_manage_stock() : false;
$stock_quantity    = $is_edit ? $product->get_stock_quantity() : '';
$stock_status      = $is_edit ? $product->get_stock_status() : 'instock';
$backorders        = $is_edit ? $product->get_backorders() : 'no';
$weight            = $is_edit ? $product->get_weight() : '';
$description       = $is_edit ? $product->get_description() : '';
$short_description = $is_edit ? $product->get_short_description() : '';
$image_id          = $is_edit ? $product->get_image_id() : 0;
$gallery_ids       = $is_edit ? $product->get_gallery_image_ids() : array();
$category_ids      = $is_edit ? $product->get_category_ids() : array();
$tag_ids           = $is_edit ? $product->get_tag_ids() : array();

// Extras asignados
$assigned_extras = array();
if ($is_edit) {
    $legacy = get_post_meta($product_id, '_churrascoplanet_extras_groups', true);
    if (is_array($legacy)) $assigned_extras = array_map('intval', $legacy);
}

// Todos los extras
$all_extras = array();
if (function_exists('chp_get_all_extras_grupos')) {
    $all_extras = chp_get_all_extras_grupos();
} else {
    $opts = get_option('churrascoplanet_options', array());
    $all_extras = $opts['extras_grupos'] ?? array();
}

// Categorías
$all_categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name'));

// Tags del producto
$product_tags = array();
if ($is_edit) {
    $terms = get_the_terms($product_id, 'product_tag');
    if ($terms && !is_wp_error($terms)) $product_tags = $terms;
}

// Notice
if (isset($_GET['saved'])) {
    echo '<div class="chp-notice chp-notice-success"><i class="fas fa-check-circle"></i> Producto guardado correctamente</div>';
}
?>

<div id="chp-product-form" class="chp-product-form">
    <!-- ─── Main Column ─── -->
    <div class="chp-form-main">
        <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

        <!-- Nombre -->
        <input type="text" name="product_name" class="chp-product-name-input"
               value="<?php echo esc_attr($name); ?>"
               placeholder="<?php esc_attr_e('Nombre del producto', 'churrascoplanet-core'); ?>">

        <!-- Product Data Tabs -->
        <div class="chp-widget">
            <div class="chp-data-tabs-nav">
                <a class="chp-data-tab-link active" data-tab="general"><i class="fas fa-cog"></i> <?php _e('General', 'churrascoplanet-core'); ?></a>
                <a class="chp-data-tab-link" data-tab="inventory"><i class="fas fa-warehouse"></i> <?php _e('Inventario', 'churrascoplanet-core'); ?></a>
                <a class="chp-data-tab-link" data-tab="shipping"><i class="fas fa-truck"></i> <?php _e('Envío', 'churrascoplanet-core'); ?></a>
            </div>

            <!-- General -->
            <div id="chp-data-general" class="chp-data-tab-content active">
                <div class="chp-field-inline">
                    <div class="chp-field">
                        <label><?php _e('Precio regular (CLP)', 'churrascoplanet-core'); ?></label>
                        <input type="number" name="regular_price" id="chp-regular-price" value="<?php echo esc_attr($regular_price); ?>" min="0" step="1" placeholder="0">
                    </div>
                    <div class="chp-field">
                        <label><?php _e('Precio oferta (CLP)', 'churrascoplanet-core'); ?></label>
                        <input type="number" name="sale_price" id="chp-sale-price" value="<?php echo esc_attr($sale_price); ?>" min="0" step="1" placeholder="<?php esc_attr_e('Dejar vacío si no aplica', 'churrascoplanet-core'); ?>">
                    </div>
                </div>
                <div id="chp-price-validation" class="chp-validation-msg" style="display:none;"></div>
            </div>

            <!-- Inventario -->
            <div id="chp-data-inventory" class="chp-data-tab-content">
                <div class="chp-field">
                    <label><?php _e('SKU', 'churrascoplanet-core'); ?></label>
                    <input type="text" name="sku" value="<?php echo esc_attr($sku); ?>" placeholder="<?php esc_attr_e('Código único del producto', 'churrascoplanet-core'); ?>">
                </div>
                <div class="chp-field">
                    <div class="chp-toggle">
                        <input type="checkbox" name="manage_stock" id="chp-manage-stock" <?php checked($manage_stock); ?>>
                        <label for="chp-manage-stock"><?php _e('Gestionar stock', 'churrascoplanet-core'); ?></label>
                    </div>
                </div>
                <div id="chp-stock-fields" style="<?php echo $manage_stock ? '' : 'display:none;'; ?>">
                    <div class="chp-field">
                        <label><?php _e('Cantidad en stock', 'churrascoplanet-core'); ?></label>
                        <input type="number" name="stock_quantity" value="<?php echo esc_attr($stock_quantity); ?>" min="0" step="1">
                    </div>
                    <div class="chp-field">
                        <label><?php _e('Permitir reservas', 'churrascoplanet-core'); ?></label>
                        <select name="backorders">
                            <option value="no" <?php selected($backorders, 'no'); ?>><?php _e('No permitir', 'churrascoplanet-core'); ?></option>
                            <option value="notify" <?php selected($backorders, 'notify'); ?>><?php _e('Permitir y notificar', 'churrascoplanet-core'); ?></option>
                            <option value="yes" <?php selected($backorders, 'yes'); ?>><?php _e('Permitir', 'churrascoplanet-core'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="chp-field">
                    <label><?php _e('Estado de stock', 'churrascoplanet-core'); ?></label>
                    <select name="stock_status">
                        <option value="instock" <?php selected($stock_status, 'instock'); ?>><?php _e('En stock', 'churrascoplanet-core'); ?></option>
                        <option value="outofstock" <?php selected($stock_status, 'outofstock'); ?>><?php _e('Agotado', 'churrascoplanet-core'); ?></option>
                        <option value="onbackorder" <?php selected($stock_status, 'onbackorder'); ?>><?php _e('Bajo pedido', 'churrascoplanet-core'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Envío -->
            <div id="chp-data-shipping" class="chp-data-tab-content">
                <div class="chp-field">
                    <label><?php _e('Peso (kg)', 'churrascoplanet-core'); ?></label>
                    <input type="number" name="weight" value="<?php echo esc_attr($weight); ?>" min="0" step="0.01" placeholder="0.00">
                    <p class="description"><?php _e('Se usa para calcular costo de envío vía Uber Direct', 'churrascoplanet-core'); ?></p>
                </div>
            </div>
        </div>

        <!-- Descripción -->
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-align-left"></i> <?php _e('Descripción', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body">
                <?php wp_editor($description, 'product_description', array(
                    'textarea_rows' => 8,
                    'media_buttons' => false,
                    'teeny'         => true,
                    'quicktags'     => true,
                )); ?>
            </div>
        </div>

        <!-- Descripción corta -->
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-file-alt"></i> <?php _e('Descripción Corta', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body">
                <?php wp_editor($short_description, 'product_short_description', array(
                    'textarea_rows' => 4,
                    'media_buttons' => false,
                    'teeny'         => true,
                    'quicktags'     => true,
                )); ?>
            </div>
        </div>
    </div>

    <!-- ─── Sidebar ─── -->
    <div class="chp-form-sidebar">

        <!-- Publicar -->
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-paper-plane"></i> <?php _e('Publicar', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body">
                <div class="chp-publish-actions">
                    <!-- Status Toggle -->
                    <input type="hidden" name="product_status" id="chp-product-status" value="<?php echo esc_attr($status); ?>">
                    <div class="chp-status-toggle" id="chp-status-toggle">
                        <button type="button" class="chp-status-opt <?php echo $status === 'publish' ? 'active' : ''; ?>" data-value="publish">
                            <i class="fas fa-eye"></i> <?php _e('Publicado', 'churrascoplanet-core'); ?>
                        </button>
                        <button type="button" class="chp-status-opt <?php echo $status === 'draft' ? 'active' : ''; ?>" data-value="draft">
                            <i class="fas fa-pencil-alt"></i> <?php _e('Borrador', 'churrascoplanet-core'); ?>
                        </button>
                    </div>
                    <button type="button" id="chp-save-product" class="chp-btn chp-btn-primary chp-publish-btn">
                        <i class="fas fa-save"></i> <?php _e('Guardar Producto', 'churrascoplanet-core'); ?>
                    </button>
                    <?php if ($is_edit) : ?>
                    <div class="chp-delete-link">
                        <a href="#" class="chp-delete-product" data-id="<?php echo $product_id; ?>"><?php _e('Mover a papelera', 'churrascoplanet-core'); ?></a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Categorías -->
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-folder"></i> <?php _e('Categorías', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body">
                <div class="chp-checklist" id="chp-category-checklist">
                    <?php
                    if ($all_categories && !is_wp_error($all_categories)) {
                        $cat_tree = array();
                        foreach ($all_categories as $cat) {
                            $cat_tree[$cat->parent][] = $cat;
                        }
                        chp_render_cat_checklist($cat_tree, 0, $category_ids);
                    }
                    ?>
                </div>
                <div class="chp-add-new-term">
                    <button type="button" class="chp-add-term-toggle">+ <?php _e('Agregar nueva categoría', 'churrascoplanet-core'); ?></button>
                    <div class="chp-add-term-form">
                        <input type="text" id="chp-new-cat-name" placeholder="<?php esc_attr_e('Nombre', 'churrascoplanet-core'); ?>">
                        <select id="chp-new-cat-parent">
                            <option value="0">— <?php _e('Sin padre', 'churrascoplanet-core'); ?> —</option>
                            <?php if ($all_categories && !is_wp_error($all_categories)) :
                                foreach ($all_categories as $cat) :
                                    echo '<option value="' . $cat->term_id . '">' . esc_html($cat->name) . '</option>';
                                endforeach;
                            endif; ?>
                        </select>
                        <button type="button" id="chp-add-cat-btn" class="chp-btn chp-btn-sm chp-btn-outline"><?php _e('Agregar', 'churrascoplanet-core'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Etiquetas -->
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-tags"></i> <?php _e('Etiquetas', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body">
                <div class="chp-tag-tokens" id="chp-tag-tokens">
                    <?php foreach ($product_tags as $tag) : ?>
                        <span class="chp-tag-token" data-id="<?php echo $tag->term_id; ?>">
                            <?php echo esc_html($tag->name); ?>
                            <input type="hidden" name="tag_ids[]" value="<?php echo $tag->term_id; ?>">
                            <button type="button" class="remove-tag"><i class="fas fa-times"></i></button>
                        </span>
                    <?php endforeach; ?>
                </div>
                <div class="chp-tag-input-row">
                    <input type="text" id="chp-new-tag-input" placeholder="<?php esc_attr_e('Nueva etiqueta', 'churrascoplanet-core'); ?>">
                    <button type="button" id="chp-add-tag-btn" class="chp-btn chp-btn-sm chp-btn-outline"><?php _e('Agregar', 'churrascoplanet-core'); ?></button>
                </div>
            </div>
        </div>

        <!-- Extras ChurrascoPlanet -->
        <?php if (!empty($all_extras)) : ?>
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-puzzle-piece"></i> <?php _e('Extras ChurrascoPlanet', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body">
                <?php foreach ($all_extras as $grupo) :
                    $grupo_id = isset($grupo['id']) ? (int)$grupo['id'] : 0;
                    $checked  = in_array($grupo_id, $assigned_extras) ? 'checked' : '';
                    $req      = !empty($grupo['requerido']);
                    $items_n  = !empty($grupo['items']) ? count($grupo['items']) : 0;
                ?>
                <div class="chp-extras-group">
                    <label>
                        <input type="checkbox" name="extras_groups[]" value="<?php echo $grupo_id; ?>" <?php echo $checked; ?>>
                        <?php echo esc_html($grupo['nombre'] ?? 'Grupo'); ?>
                        <span class="chp-extras-badge <?php echo $req ? 'required' : 'optional'; ?>">
                            <?php echo $req ? 'Requerido' : 'Opcional'; ?>
                        </span>
                        <?php if ($items_n) : ?>
                            <span class="chp-extras-count">(<?php echo $items_n; ?> opc.)</span>
                        <?php endif; ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Imagen -->
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-image"></i> <?php _e('Imagen del producto', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body chp-image-upload">
                <input type="hidden" name="image_id" id="chp-product-image-id" value="<?php echo $image_id; ?>">
                <div id="chp-image-preview" class="chp-image-preview" <?php echo $image_id ? '' : 'style="display:none;"'; ?>>
                    <?php if ($image_id) :
                        $img_url = wp_get_attachment_image_url($image_id, 'medium');
                    ?>
                        <img src="<?php echo esc_url($img_url); ?>" alt="">
                        <button type="button" class="chp-remove-image"><i class="fas fa-times"></i></button>
                    <?php endif; ?>
                </div>
                <button type="button" id="chp-select-image" class="chp-btn chp-btn-outline" style="width:100%;">
                    <i class="fas fa-upload"></i> <?php _e('Seleccionar imagen', 'churrascoplanet-core'); ?>
                </button>
            </div>
        </div>

        <!-- Galería -->
        <div class="chp-widget">
            <h3 class="chp-widget-title"><i class="fas fa-images"></i> <?php _e('Galería', 'churrascoplanet-core'); ?></h3>
            <div class="chp-widget-body">
                <div class="chp-gallery-images" id="chp-gallery-images">
                    <?php foreach ($gallery_ids as $gid) :
                        $gurl = wp_get_attachment_image_url($gid, 'thumbnail');
                        if (!$gurl) continue;
                    ?>
                    <div class="chp-gallery-item" data-id="<?php echo $gid; ?>">
                        <img src="<?php echo esc_url($gurl); ?>" alt="">
                        <button type="button" class="chp-remove-gallery-item"><i class="fas fa-times"></i></button>
                        <input type="hidden" name="gallery_ids[]" value="<?php echo $gid; ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="chp-add-gallery" class="chp-btn chp-btn-outline chp-btn-sm" style="width:100%;">
                    <i class="fas fa-plus"></i> <?php _e('Agregar imágenes', 'churrascoplanet-core'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle stock fields
jQuery(document).ready(function($) {
    $('#chp-manage-stock').on('change', function() {
        $('#chp-stock-fields').toggle($(this).is(':checked'));
    });
    // Delete from form
    $('.chp-delete-product').on('click', function(e) {
        e.preventDefault();
        if (!confirm(chpProductos.strings.confirmDelete)) return;
        $.post(chpProductos.ajaxUrl, {
            action: 'chp_producto_delete',
            nonce: chpProductos.nonce,
            product_id: $(this).data('id')
        }, function(res) {
            if (res.success) window.location.href = chpProductos.pageUrl;
        });
    });
});
</script>

<?php
// Helper: renderizar checklist jerárquico
function chp_render_cat_checklist($tree, $parent_id, $selected, $depth = 0) {
    if (!isset($tree[$parent_id])) return;
    foreach ($tree[$parent_id] as $cat) {
        $checked = in_array($cat->term_id, $selected) ? 'checked' : '';
        $class = 'chp-checklist-item' . ($depth > 0 ? ' depth-' . $depth : '');
        echo '<div class="' . $class . '">';
        echo '<input type="checkbox" name="category_ids[]" value="' . $cat->term_id . '" ' . $checked . '>';
        echo esc_html($cat->name);
        echo '</div>';
        chp_render_cat_checklist($tree, $cat->term_id, $selected, $depth + 1);
    }
}
?>
