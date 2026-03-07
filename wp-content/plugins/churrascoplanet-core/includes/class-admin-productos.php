<?php
/**
 * ChurrascoPlanet Core - Módulo Productos
 *
 * Gestión de productos WooCommerce desde el panel ChurrascoPlanet.
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar menú
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'churrascoplanet-options',
        __('Productos', 'churrascoplanet-core'),
        __('Productos', 'churrascoplanet-core'),
        'manage_woocommerce',
        'churrascoplanet-productos',
        'chp_productos_render_page'
    );
}, 20);

/**
 * Renderizar página principal
 */
function chp_productos_render_page() {
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-error"><p>';
        _e('El módulo de Productos requiere WooCommerce activo.', 'churrascoplanet-core');
        echo '</p></div>';
        return;
    }

    $section    = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : 'list';
    $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;

    include CHP_CORE_PATH . 'admin/views/productos-page.php';
}

/**
 * Encolar assets
 */
add_action('admin_enqueue_scripts', function($hook) {
    if ('planetachurrascos_page_churrascoplanet-productos' !== $hook) {
        return;
    }

    wp_enqueue_media();

    wp_enqueue_style(
        'font-awesome-admin',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
        array(),
        '6.4.0'
    );

    wp_enqueue_style(
        'chp-productos',
        CHP_CORE_URL . 'admin/css/admin-productos.css',
        array(),
        CHP_CORE_VERSION
    );

    wp_enqueue_script(
        'chp-productos',
        CHP_CORE_URL . 'admin/js/admin-productos.js',
        array('jquery', 'jquery-ui-sortable'),
        CHP_CORE_VERSION,
        true
    );

    wp_localize_script('chp-productos', 'chpProductos', array(
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('chp_productos_nonce'),
        'pageUrl'  => admin_url('admin.php?page=churrascoplanet-productos'),
        'currency' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
        'strings'  => array(
            'confirmDelete'     => __('¿Estás seguro de eliminar este producto?', 'churrascoplanet-core'),
            'confirmBulkDelete' => __('¿Estás seguro de eliminar los productos seleccionados?', 'churrascoplanet-core'),
            'confirmDeleteCat'  => __('¿Estás seguro de eliminar esta categoría?', 'churrascoplanet-core'),
            'confirmDeleteTag'  => __('¿Estás seguro de eliminar esta etiqueta?', 'churrascoplanet-core'),
            'saved'             => __('Guardado correctamente', 'churrascoplanet-core'),
            'deleted'           => __('Eliminado correctamente', 'churrascoplanet-core'),
            'error'             => __('Error al procesar la solicitud', 'churrascoplanet-core'),
            'selectImage'       => __('Seleccionar imagen del producto', 'churrascoplanet-core'),
            'useImage'          => __('Usar esta imagen', 'churrascoplanet-core'),
            'selectGallery'     => __('Seleccionar imágenes de galería', 'churrascoplanet-core'),
            'addToGallery'      => __('Agregar a galería', 'churrascoplanet-core'),
            'noProducts'        => __('No se encontraron productos', 'churrascoplanet-core'),
            'saving'            => __('Guardando...', 'churrascoplanet-core'),
        ),
    ));
});

// ─── AJAX: Guardar producto ───
add_action('wp_ajax_chp_producto_save', function() {
    check_ajax_referer('chp_productos_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $data       = $_POST;
    $product_id = !empty($data['product_id']) ? absint($data['product_id']) : 0;

    if ($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => 'Producto no encontrado'));
        }
    } else {
        $product = new WC_Product_Simple();
    }

    $product->set_name(sanitize_text_field($data['name'] ?? ''));
    $raw_status = sanitize_text_field($data['status'] ?? 'publish');
    $product->set_status(in_array($raw_status, array('publish', 'draft', 'pending'), true) ? $raw_status : 'publish');
    $product->set_catalog_visibility(sanitize_text_field($data['visibility'] ?? 'visible'));
    $product->set_description(wp_kses_post($data['description'] ?? ''));
    $product->set_short_description(wp_kses_post($data['short_description'] ?? ''));
    $product->set_regular_price(wc_format_decimal($data['regular_price'] ?? ''));

    $sale_price = $data['sale_price'] ?? '';
    if ($sale_price !== '') {
        $product->set_sale_price(wc_format_decimal($sale_price));
    } else {
        $product->set_sale_price('');
    }

    $product->set_sku(sanitize_text_field($data['sku'] ?? ''));

    $manage_stock = ($data['manage_stock'] ?? '') === 'yes';
    $product->set_manage_stock($manage_stock);
    if ($manage_stock) {
        $product->set_stock_quantity(absint($data['stock_quantity'] ?? 0));
        $product->set_backorders(sanitize_text_field($data['backorders'] ?? 'no'));
    }
    $product->set_stock_status(sanitize_text_field($data['stock_status'] ?? 'instock'));

    if (isset($data['weight'])) {
        $product->set_weight(wc_format_decimal($data['weight']));
    }

    if (!empty($data['image_id'])) {
        $product->set_image_id(absint($data['image_id']));
    } else {
        $product->set_image_id(0);
    }

    if (isset($data['gallery_ids'])) {
        $gallery = is_array($data['gallery_ids']) ? array_map('absint', $data['gallery_ids']) : array();
        $product->set_gallery_image_ids($gallery);
    }

    if (isset($data['category_ids'])) {
        $cats = is_array($data['category_ids']) ? array_map('absint', $data['category_ids']) : array();
        $product->set_category_ids($cats);
    }

    if (isset($data['tag_ids'])) {
        $tags = is_array($data['tag_ids']) ? array_map('absint', $data['tag_ids']) : array();
        $product->set_tag_ids($tags);
    }

    try {
        $saved_id = $product->save();

        // Guardar extras ChurrascoPlanet
        if (isset($data['extras_groups'])) {
            $extras = array_map('absint', (array) $data['extras_groups']);
            update_post_meta($saved_id, '_churrascoplanet_extras_groups', $extras);
            if (class_exists('ChurrascoPlanet_Producto_Extras_Model')) {
                ChurrascoPlanet_Producto_Extras_Model::get_instance()->assign_to_product($saved_id, $extras);
            }
        } else {
            update_post_meta($saved_id, '_churrascoplanet_extras_groups', array());
            if (class_exists('ChurrascoPlanet_Producto_Extras_Model')) {
                ChurrascoPlanet_Producto_Extras_Model::get_instance()->assign_to_product($saved_id, array());
            }
        }

        wp_send_json_success(array(
            'message'    => 'Producto guardado correctamente',
            'product_id' => $saved_id,
        ));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => $e->getMessage()));
    }
});

// ─── AJAX: Eliminar producto ───
add_action('wp_ajax_chp_producto_delete', function() {
    check_ajax_referer('chp_productos_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $product_id = absint($_POST['product_id'] ?? 0);
    $product    = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => 'Producto no encontrado'));
    }

    $product->delete(false); // trash
    wp_send_json_success(array('message' => 'Producto eliminado'));
});

// ─── AJAX: Toggle estado publish/draft ───
add_action('wp_ajax_chp_producto_toggle_status', function() {
    check_ajax_referer('chp_productos_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $product_id = absint($_POST['product_id'] ?? 0);
    $product    = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => 'Producto no encontrado'));
    }

    $new_status = $product->get_status() === 'publish' ? 'draft' : 'publish';
    $product->set_status($new_status);
    $product->save();

    wp_send_json_success(array(
        'message' => 'Estado actualizado',
        'status'  => $new_status,
    ));
});

// ─── AJAX: Bulk action ───
add_action('wp_ajax_chp_producto_bulk_action', function() {
    check_ajax_referer('chp_productos_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $action = sanitize_text_field($_POST['bulk_action'] ?? '');
    $ids    = isset($_POST['product_ids']) ? array_map('absint', (array) $_POST['product_ids']) : array();

    if (empty($ids) || empty($action)) {
        wp_send_json_error(array('message' => 'Parámetros inválidos'));
    }

    $count = 0;
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if (!$product) continue;

        switch ($action) {
            case 'delete':
                $product->delete(false);
                $count++;
                break;
            case 'publish':
                $product->set_status('publish');
                $product->save();
                $count++;
                break;
            case 'draft':
                $product->set_status('draft');
                $product->save();
                $count++;
                break;
        }
    }

    wp_send_json_success(array(
        'message' => sprintf('%d producto(s) actualizado(s)', $count),
        'count'   => $count,
    ));
});

// ─── AJAX: Guardar categoría ───
add_action('wp_ajax_chp_categoria_save', function() {
    check_ajax_referer('chp_productos_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $term_id = absint($_POST['term_id'] ?? 0);
    $name    = sanitize_text_field($_POST['name'] ?? '');

    if (empty($name)) {
        wp_send_json_error(array('message' => 'El nombre es obligatorio'));
    }

    $args = array(
        'description' => sanitize_textarea_field($_POST['description'] ?? ''),
        'parent'      => absint($_POST['parent'] ?? 0),
        'slug'        => sanitize_title($_POST['slug'] ?? ''),
    );

    if ($term_id) {
        $result = wp_update_term($term_id, 'product_cat', array_merge($args, array('name' => $name)));
    } else {
        $result = wp_insert_term($name, 'product_cat', $args);
    }

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    $saved_id = is_array($result) ? $result['term_id'] : $term_id;

    if (isset($_POST['thumbnail_id'])) {
        update_term_meta($saved_id, 'thumbnail_id', absint($_POST['thumbnail_id']));
    }

    wp_send_json_success(array(
        'message' => 'Categoría guardada',
        'term_id' => $saved_id,
    ));
});

// ─── AJAX: Eliminar categoría ───
add_action('wp_ajax_chp_categoria_delete', function() {
    check_ajax_referer('chp_productos_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $term_id = absint($_POST['term_id'] ?? 0);
    if (!$term_id) {
        wp_send_json_error(array('message' => 'ID inválido'));
    }

    $result = wp_delete_term($term_id, 'product_cat');
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('message' => 'Categoría eliminada'));
});

// ─── AJAX: Guardar etiqueta ───
add_action('wp_ajax_chp_etiqueta_save', function() {
    check_ajax_referer('chp_productos_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $term_id = absint($_POST['term_id'] ?? 0);
    $name    = sanitize_text_field($_POST['name'] ?? '');

    if (empty($name)) {
        wp_send_json_error(array('message' => 'El nombre es obligatorio'));
    }

    $args = array(
        'description' => sanitize_textarea_field($_POST['description'] ?? ''),
        'slug'        => sanitize_title($_POST['slug'] ?? ''),
    );

    if ($term_id) {
        $result = wp_update_term($term_id, 'product_tag', array_merge($args, array('name' => $name)));
    } else {
        $result = wp_insert_term($name, 'product_tag', $args);
    }

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    $saved_id = is_array($result) ? $result['term_id'] : $term_id;

    wp_send_json_success(array(
        'message' => 'Etiqueta guardada',
        'term_id' => $saved_id,
    ));
});

// ─── AJAX: Eliminar etiqueta ───
add_action('wp_ajax_chp_etiqueta_delete', function() {
    check_ajax_referer('chp_productos_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $term_id = absint($_POST['term_id'] ?? 0);
    if (!$term_id) {
        wp_send_json_error(array('message' => 'ID inválido'));
    }

    $result = wp_delete_term($term_id, 'product_tag');
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('message' => 'Etiqueta eliminada'));
});
