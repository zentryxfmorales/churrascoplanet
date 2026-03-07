<?php
/**
 * Productos - Listado
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) exit;

// Parámetros de filtro
$paged       = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$per_page    = 20;
$search      = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$cat_filter  = isset($_GET['product_cat']) ? absint($_GET['product_cat']) : 0;
$stock_filter = isset($_GET['stock_status']) ? sanitize_text_field($_GET['stock_status']) : '';
$base_url    = admin_url('admin.php?page=churrascoplanet-productos');

// Query args
$args = array(
    'limit'  => $per_page,
    'page'   => $paged,
    'status' => array('publish', 'draft', 'pending'),
    'orderby' => 'date',
    'order'   => 'DESC',
    'return'  => 'objects',
);
if ($search) $args['s'] = $search;
if ($cat_filter) {
    $cat_term = get_term($cat_filter, 'product_cat');
    if ($cat_term && !is_wp_error($cat_term)) {
        $args['category'] = array($cat_term->slug);
    }
}
if ($stock_filter) $args['stock_status'] = $stock_filter;

$products = wc_get_products($args);

// Total para paginación
$count_args = $args;
$count_args['limit'] = -1;
$count_args['return'] = 'ids';
$count_args['page'] = 1;
$total = count(wc_get_products($count_args));
$total_pages = ceil($total / $per_page);

// Stats rápidos
$total_all   = count(wc_get_products(array('limit' => -1, 'return' => 'ids', 'status' => array('publish', 'draft'))));
$published   = count(wc_get_products(array('limit' => -1, 'return' => 'ids', 'status' => 'publish')));
$drafts      = count(wc_get_products(array('limit' => -1, 'return' => 'ids', 'status' => 'draft')));
$on_sale_ids = wc_get_product_ids_on_sale();
$on_sale     = 0;
if (!empty($on_sale_ids)) {
    $on_sale = count(wc_get_products(array(
        'limit'   => -1,
        'return'  => 'ids',
        'status'  => array('publish', 'draft'),
        'include' => $on_sale_ids,
    )));
}

// Categorías para filtro
$categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name'));

// Notice de guardado
if (isset($_GET['saved'])) {
    echo '<div class="chp-notice chp-notice-success"><i class="fas fa-check-circle"></i> Producto guardado correctamente</div>';
}
?>

<div class="chp-products-list">

    <!-- Stats -->
    <div class="chp-stats-row">
        <div class="chp-stat-card">
            <div class="chp-stat-icon total"><i class="fas fa-box"></i></div>
            <div class="chp-stat-info">
                <span class="chp-stat-number"><?php echo $total_all; ?></span>
                <span class="chp-stat-label"><?php _e('Total', 'churrascoplanet-core'); ?></span>
            </div>
        </div>
        <div class="chp-stat-card">
            <div class="chp-stat-icon published"><i class="fas fa-check-circle"></i></div>
            <div class="chp-stat-info">
                <span class="chp-stat-number"><?php echo $published; ?></span>
                <span class="chp-stat-label"><?php _e('Publicados', 'churrascoplanet-core'); ?></span>
            </div>
        </div>
        <div class="chp-stat-card">
            <div class="chp-stat-icon draft"><i class="fas fa-pencil-alt"></i></div>
            <div class="chp-stat-info">
                <span class="chp-stat-number"><?php echo $drafts; ?></span>
                <span class="chp-stat-label"><?php _e('Borradores', 'churrascoplanet-core'); ?></span>
            </div>
        </div>
        <div class="chp-stat-card">
            <div class="chp-stat-icon sale"><i class="fas fa-percentage"></i></div>
            <div class="chp-stat-info">
                <span class="chp-stat-number"><?php echo $on_sale; ?></span>
                <span class="chp-stat-label"><?php _e('En Oferta', 'churrascoplanet-core'); ?></span>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form class="chp-filters" method="get" action="<?php echo admin_url('admin.php'); ?>">
        <input type="hidden" name="page" value="churrascoplanet-productos">
        <select name="product_cat">
            <option value=""><?php _e('Todas las categorías', 'churrascoplanet-core'); ?></option>
            <?php foreach ($categories as $cat) : ?>
                <option value="<?php echo $cat->term_id; ?>" <?php selected($cat_filter, $cat->term_id); ?>><?php echo esc_html($cat->name); ?> (<?php echo $cat->count; ?>)</option>
            <?php endforeach; ?>
        </select>
        <select name="stock_status">
            <option value=""><?php _e('Cualquier stock', 'churrascoplanet-core'); ?></option>
            <option value="instock" <?php selected($stock_filter, 'instock'); ?>><?php _e('En stock', 'churrascoplanet-core'); ?></option>
            <option value="outofstock" <?php selected($stock_filter, 'outofstock'); ?>><?php _e('Agotado', 'churrascoplanet-core'); ?></option>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Buscar producto...', 'churrascoplanet-core'); ?>">
        <button type="submit" class="chp-btn chp-btn-outline"><i class="fas fa-search"></i> <?php _e('Filtrar', 'churrascoplanet-core'); ?></button>
        <?php if ($search || $cat_filter || $stock_filter) : ?>
            <a href="<?php echo esc_url($base_url); ?>" class="chp-btn chp-btn-outline"><?php _e('Limpiar', 'churrascoplanet-core'); ?></a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="chp-table-wrap">
        <!-- Bulk bar -->
        <div class="chp-bulk-bar">
            <select id="chp-bulk-action">
                <option value=""><?php _e('Acciones en lote', 'churrascoplanet-core'); ?></option>
                <option value="publish"><?php _e('Publicar', 'churrascoplanet-core'); ?></option>
                <option value="draft"><?php _e('Borrador', 'churrascoplanet-core'); ?></option>
                <option value="delete"><?php _e('Eliminar', 'churrascoplanet-core'); ?></option>
            </select>
            <button type="button" id="chp-bulk-apply" class="chp-btn chp-btn-sm chp-btn-outline"><?php _e('Aplicar', 'churrascoplanet-core'); ?></button>
        </div>

        <table class="chp-table">
            <thead>
                <tr>
                    <th class="col-cb"><input type="checkbox" id="chp-select-all"></th>
                    <th class="col-thumb"><?php _e('Imagen', 'churrascoplanet-core'); ?></th>
                    <th><?php _e('Nombre', 'churrascoplanet-core'); ?></th>
                    <th class="col-price"><?php _e('Precio', 'churrascoplanet-core'); ?></th>
                    <th class="col-cats"><?php _e('Categorías', 'churrascoplanet-core'); ?></th>
                    <th class="col-stock"><?php _e('Stock', 'churrascoplanet-core'); ?></th>
                    <th class="col-status"><?php _e('Estado', 'churrascoplanet-core'); ?></th>
                    <th class="col-date"><?php _e('Fecha', 'churrascoplanet-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)) : ?>
                    <tr><td colspan="8">
                        <div class="chp-empty-state">
                            <i class="fas fa-box-open"></i>
                            <p><?php _e('No se encontraron productos', 'churrascoplanet-core'); ?></p>
                            <a href="<?php echo esc_url($base_url . '&section=add'); ?>" class="chp-btn chp-btn-primary"><?php _e('Agregar Producto', 'churrascoplanet-core'); ?></a>
                        </div>
                    </td></tr>
                <?php else : ?>
                    <?php foreach ($products as $product) :
                        $thumb_id = $product->get_image_id();
                        $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
                        $cats = get_the_terms($product->get_id(), 'product_cat');
                        $edit_url = $base_url . '&section=edit&product_id=' . $product->get_id();
                        $view_url = get_permalink($product->get_id());
                        $status = $product->get_status();
                        $stock = $product->get_stock_status();
                    ?>
                    <tr>
                        <td class="col-cb"><input type="checkbox" class="chp-product-cb" value="<?php echo $product->get_id(); ?>"></td>
                        <td><img src="<?php echo esc_url($thumb_url); ?>" class="chp-product-thumb" alt=""></td>
                        <td>
                            <div class="chp-product-name"><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($product->get_name()); ?></a></div>
                            <div class="chp-row-actions">
                                <a href="<?php echo esc_url($edit_url); ?>"><?php _e('Editar', 'churrascoplanet-core'); ?></a>
                                <span class="sep">|</span>
                                <a href="<?php echo esc_url($view_url); ?>" target="_blank"><?php _e('Ver', 'churrascoplanet-core'); ?></a>
                                <span class="sep">|</span>
                                <a href="#" class="delete chp-delete-product" data-id="<?php echo $product->get_id(); ?>"><?php _e('Eliminar', 'churrascoplanet-core'); ?></a>
                            </div>
                        </td>
                        <td>
                            <?php if ($product->is_on_sale()) : ?>
                                <span class="chp-price-sale"><?php echo wc_price($product->get_regular_price()); ?></span>
                                <span class="chp-price-current"><?php echo wc_price($product->get_sale_price()); ?></span>
                            <?php else : ?>
                                <span class="chp-price-regular"><?php echo $product->get_price() ? wc_price($product->get_price()) : '—'; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="col-cats">
                            <?php if ($cats && !is_wp_error($cats)) :
                                foreach ($cats as $cat) :
                                    echo '<span class="chp-badge chp-badge-cat">' . esc_html($cat->name) . '</span>';
                                endforeach;
                            else : echo '—'; endif; ?>
                        </td>
                        <td><span class="chp-badge chp-badge-<?php echo $stock; ?>"><?php
                            echo $stock === 'instock' ? 'En stock' : ($stock === 'outofstock' ? 'Agotado' : 'Reserva');
                        ?></span></td>
                        <td>
                            <span class="chp-badge chp-badge-<?php echo $status; ?> chp-toggle-status" data-id="<?php echo $product->get_id(); ?>" style="cursor:pointer;" title="Click para cambiar"><?php
                                echo $status === 'publish' ? 'Publicado' : 'Borrador';
                            ?></span>
                        </td>
                        <td class="col-date"><span class="chp-date"><?php echo $product->get_date_created() ? $product->get_date_created()->date_i18n('d M Y') : '—'; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1) : ?>
        <div class="chp-pagination">
            <div class="chp-pagination-info">
                <?php printf(__('Mostrando %d-%d de %d productos', 'churrascoplanet-core'),
                    (($paged - 1) * $per_page) + 1,
                    min($paged * $per_page, $total),
                    $total
                ); ?>
            </div>
            <div class="chp-pagination-links">
                <?php
                $filter_params = array('page' => 'churrascoplanet-productos');
                if ($search) $filter_params['s'] = $search;
                if ($cat_filter) $filter_params['product_cat'] = $cat_filter;
                if ($stock_filter) $filter_params['stock_status'] = $stock_filter;

                if ($paged > 1) {
                    echo '<a href="' . esc_url(add_query_arg(array_merge($filter_params, array('paged' => $paged - 1)), admin_url('admin.php'))) . '">&laquo; Anterior</a>';
                } else {
                    echo '<span class="disabled">&laquo; Anterior</span>';
                }

                for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i == $paged) {
                        echo '<span class="current">' . $i . '</span>';
                    } else {
                        echo '<a href="' . esc_url(add_query_arg(array_merge($filter_params, array('paged' => $i)), admin_url('admin.php'))) . '">' . $i . '</a>';
                    }
                }

                if ($paged < $total_pages) {
                    echo '<a href="' . esc_url(add_query_arg(array_merge($filter_params, array('paged' => $paged + 1)), admin_url('admin.php'))) . '">Siguiente &raquo;</a>';
                } else {
                    echo '<span class="disabled">Siguiente &raquo;</span>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
