<?php
/**
 * ChurrascoPlanet - Product Addons (Extras)
 * Metabox en productos WooCommerce + AJAX handlers
 *
 * @package ChurrascoPlanet
 */

if (!defined('ABSPATH')) {
    exit;
}

// IMPORTANTE: Este archivo REQUIERE WooCommerce
// No cargar si WooCommerce no está activo
if (!class_exists('WooCommerce')) {
    return;
}

// Si la clase ya existe y los handlers están registrados, no cargar de nuevo
if (class_exists('ChurrascoPlanet_Product_Addons')) {
    // Verificar si los handlers AJAX ya están registrados
    if (has_action('wp_ajax_churrascoplanet_get_product_data')) {
        return; // Ya está todo configurado
    }
    // Si la clase existe pero los handlers no, forzar la inicialización
    ChurrascoPlanet_Product_Addons::get_instance();
    return;
}

/**
 * Clase para gestionar extras de productos
 */
class ChurrascoPlanet_Product_Addons {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Metabox en productos WooCommerce
        add_action('add_meta_boxes', array($this, 'add_product_metabox'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_metabox'));

        // AJAX para cargar datos del producto + extras
        add_action('wp_ajax_churrascoplanet_get_product_data', array($this, 'ajax_get_product_data'));
        add_action('wp_ajax_nopriv_churrascoplanet_get_product_data', array($this, 'ajax_get_product_data'));
        add_action('wc_ajax_chp_product_data', array($this, 'ajax_get_product_data'));

        // AJAX para agregar producto con extras al carrito
        add_action('wp_ajax_churrascoplanet_add_to_cart_with_extras', array($this, 'ajax_add_to_cart_with_extras'));
        add_action('wp_ajax_nopriv_churrascoplanet_add_to_cart_with_extras', array($this, 'ajax_add_to_cart_with_extras'));
        add_action('wc_ajax_chp_add_to_cart', array($this, 'ajax_add_to_cart_with_extras'));

        // Mostrar extras en el carrito
        add_filter('woocommerce_get_item_data', array($this, 'display_extras_in_cart'), 10, 2);

        // Calcular precio con extras
        add_action('woocommerce_before_calculate_totals', array($this, 'calculate_extras_price'), 20, 1);

        // Guardar extras en la orden
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_extras_to_order'), 10, 4);

        // Estilos del metabox
        add_action('admin_enqueue_scripts', array($this, 'enqueue_metabox_styles'));
    }

    /**
     * Estilos para el metabox
     */
    public function enqueue_metabox_styles($hook) {
        global $post;
        if (($hook === 'post.php' || $hook === 'post-new.php') && $post && $post->post_type === 'product') {
            wp_add_inline_style('woocommerce_admin_styles', '
                .cp-extras-metabox { padding: 10px; }
                .cp-extras-metabox .cp-extras-group { margin-bottom: 8px; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9; }
                .cp-extras-metabox .cp-extras-group label { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; cursor: pointer; font-weight: 500; }
                .cp-extras-metabox .cp-group-badge { font-size: 11px; padding: 2px 8px; border-radius: 3px; font-weight: 600; }
                .cp-extras-metabox .cp-badge-required { background: #fce4e4; color: #cc0000; }
                .cp-extras-metabox .cp-badge-optional { background: #e4f0fc; color: #0066cc; }
                .cp-extras-metabox .cp-group-count { font-size: 12px; color: #888; font-weight: 400; }
                .cp-extras-metabox .cp-no-groups { color: #999; font-style: italic; }
            ');
        }
    }

    /**
     * Agregar metabox a productos
     */
    public function add_product_metabox() {
        add_meta_box(
            'churrascoplanet_extras',
            __('Extras ChurrascoPlanet', 'churrascoplanet'),
            array($this, 'render_product_metabox'),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Renderizar metabox
     */
    public function render_product_metabox($post) {
        wp_nonce_field('churrascoplanet_extras_nonce', 'churrascoplanet_extras_nonce_field');

        // Obtener grupos asignados - primero intentar desde nueva tabla
        $assigned_group_ids = array();
        if (function_exists('chp_table')) {
            $assigned = ChurrascoPlanet_Producto_Extras_Model::get_instance()->get_by_product($post->ID);
            foreach ($assigned as $a) {
                $assigned_group_ids[] = (int)$a['id'];
            }
        }

        // Fallback: leer de postmeta si no hay datos en nueva tabla
        if (empty($assigned_group_ids)) {
            $legacy_assigned = get_post_meta($post->ID, '_churrascoplanet_extras_groups', true);
            if (is_array($legacy_assigned)) {
                $assigned_group_ids = array_map('intval', $legacy_assigned);
            }
        }

        // Obtener todos los grupos de extras
        $extras_grupos = array();
        if (function_exists('chp_get_all_extras_grupos')) {
            $extras_grupos = chp_get_all_extras_grupos();
        } else {
            $extras_grupos = churrascoplanet_get_option('extras_grupos', array());
        }

        if (empty($extras_grupos)) {
            echo '<div class="cp-extras-metabox">';
            echo '<p class="cp-no-groups">' . __('No hay grupos de extras configurados. Ve a PlanetaChurrascos > Extras para crearlos.', 'churrascoplanet') . '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="cp-extras-metabox">';
        echo '<p class="description">' . __('Selecciona los grupos de extras disponibles para este producto:', 'churrascoplanet') . '</p><br>';

        foreach ($extras_grupos as $grupo) {
            // Usar ID del grupo (nueva estructura) o índice (legacy)
            $grupo_id = isset($grupo['id']) ? (int)$grupo['id'] : 0;
            $checked = in_array($grupo_id, $assigned_group_ids) ? 'checked' : '';
            $nombre = esc_html($grupo['nombre'] ?? 'Grupo');
            $requerido = !empty($grupo['requerido']);
            $badge_class = $requerido ? 'cp-badge-required' : 'cp-badge-optional';
            $badge_text = $requerido ? __('Requerido', 'churrascoplanet') : __('Opcional', 'churrascoplanet');

            // Contar items del grupo
            $items_count = !empty($grupo['items']) ? count($grupo['items']) : 0;

            echo '<div class="cp-extras-group">';
            echo '<label>';
            echo '<input type="checkbox" name="churrascoplanet_extras_groups[]" value="' . esc_attr($grupo_id) . '" ' . $checked . '>';
            echo $nombre;
            echo ' <span class="cp-group-badge ' . $badge_class . '">' . $badge_text . '</span>';
            if ($items_count > 0) {
                echo ' <span class="cp-group-count">(' . $items_count . ' ' . _n('opción', 'opciones', $items_count, 'churrascoplanet') . ')</span>';
            }
            echo '</label>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Guardar metabox
     */
    public function save_product_metabox($post_id) {
        if (!isset($_POST['churrascoplanet_extras_nonce_field']) ||
            !wp_verify_nonce($_POST['churrascoplanet_extras_nonce_field'], 'churrascoplanet_extras_nonce')) {
            return;
        }

        $groups = isset($_POST['churrascoplanet_extras_groups']) ? array_map('intval', $_POST['churrascoplanet_extras_groups']) : array();

        // Guardar en la nueva tabla cp_chp_producto_extras
        if (function_exists('chp_table')) {
            $model = ChurrascoPlanet_Producto_Extras_Model::get_instance();
            $model->assign_to_product($post_id, $groups);
        }

        // También guardar en postmeta como backup temporal
        update_post_meta($post_id, '_churrascoplanet_extras_groups', $groups);
    }

    /**
     * AJAX: Obtener datos del producto + extras
     */
    public function ajax_get_product_data() {
        $product_id = intval($_POST['product_id'] ?? 0);

        if (!$product_id) {
            wp_send_json_error(array('message' => 'ID de producto inválido'));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => 'Producto no encontrado'));
        }

        // Datos del producto
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : wc_placeholder_img_src('medium');

        $categories = get_the_terms($product_id, 'product_cat');
        $cat_name = $categories && !is_wp_error($categories) ? $categories[0]->name : '';

        $data = array(
            'id' => $product_id,
            'name' => $product->get_name(),
            'description' => $product->get_short_description() ?: $product->get_description(),
            'price' => $product->get_price(),
            'price_html' => $product->get_price_html(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'image' => $image_url,
            'category' => $cat_name,
            'in_stock' => $product->is_in_stock(),
            'extras' => array(),
        );

        // Obtener grupos de extras asignados - Usar nuevas tablas si existen
        if (function_exists('chp_get_product_extras')) {
            $grupos = chp_get_product_extras($product_id);
            foreach ($grupos as $grupo) {
                $items = array();
                if (!empty($grupo['items'])) {
                    foreach ($grupo['items'] as $item) {
                        $items[] = array(
                            'id' => $item['id'] ?? 0,
                            'nombre' => $item['nombre'] ?? '',
                            'precio' => intval($item['precio'] ?? 0),
                        );
                    }
                }
                $data['extras'][] = array(
                    'id' => $grupo['id'] ?? 0,
                    'index' => $grupo['id'] ?? 0, // Para compatibilidad
                    'nombre' => $grupo['nombre'] ?? '',
                    'instruccion' => $grupo['instruccion'] ?? '',
                    'requerido' => (bool)($grupo['requerido'] ?? false),
                    'tipo_seleccion' => $grupo['tipo_seleccion'] ?? 'checkbox',
                    'min' => intval($grupo['min_selecciones'] ?? 0),
                    'max' => intval($grupo['max_selecciones'] ?? 0),
                    'items' => $items,
                );
            }
        } else {
            // Fallback: usar sistema antiguo
            $assigned_groups = get_post_meta($product_id, '_churrascoplanet_extras_groups', true);
            if (is_array($assigned_groups) && !empty($assigned_groups)) {
                $all_groups = churrascoplanet_get_option('extras_grupos', array());
                foreach ($assigned_groups as $group_index) {
                    if (isset($all_groups[$group_index])) {
                        $group = $all_groups[$group_index];
                        $data['extras'][] = array(
                            'index' => $group_index,
                            'nombre' => $group['nombre'] ?? '',
                            'instruccion' => $group['instruccion'] ?? '',
                            'requerido' => ($group['requerido'] ?? '0') === '1',
                            'tipo_seleccion' => $group['tipo_seleccion'] ?? 'checkbox',
                            'min' => intval($group['min'] ?? 0),
                            'max' => intval($group['max'] ?? 0),
                            'items' => array_values(array_map(function($item) {
                                return array(
                                    'nombre' => $item['nombre'] ?? '',
                                    'precio' => intval($item['precio'] ?? 0),
                                );
                            }, $group['items'] ?? array())),
                        );
                    }
                }
            }
        }

        wp_send_json_success($data);
    }

    /**
     * AJAX: Agregar producto con extras al carrito
     */
    public function ajax_add_to_cart_with_extras() {
        if ( function_exists('wc_load_cart') ) {
            wc_load_cart();
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 1);
        $extras = isset($_POST['extras']) ? $_POST['extras'] : array();

        if (!$product_id || $quantity < 1) {
            wp_send_json_error(array('message' => 'Datos inválidos'));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => 'Producto no encontrado'));
        }

        $validated_extras = array();
        $extras_total = 0;

        // Usar nuevas funciones del plugin si están disponibles
        if (function_exists('chp_get_product_extras')) {
            $assigned_groups = chp_get_product_extras($product_id);

            if (!empty($assigned_groups)) {
                // Crear un mapa de grupos por ID para acceso rápido
                $groups_by_id = array();
                foreach ($assigned_groups as $group) {
                    $group_id = $group['id'] ?? 0;
                    $groups_by_id[$group_id] = $group;
                }

                foreach ($assigned_groups as $group) {
                    $group_id = $group['id'] ?? 0;
                    $is_required = (bool)($group['requerido'] ?? false);
                    $min = intval($group['min_selecciones'] ?? $group['min'] ?? 0);
                    $max = intval($group['max_selecciones'] ?? $group['max'] ?? 0);

                    $selected = isset($extras[$group_id]) ? $extras[$group_id] : array();
                    if (!is_array($selected)) $selected = array($selected);

                    // Filtrar selecciones vacías
                    $selected = array_filter($selected, function($v) { return $v !== '' && $v !== null; });

                    $count = count($selected);

                    // Validar requerido
                    if ($is_required && $count === 0) {
                        wp_send_json_error(array(
                            'message' => sprintf(__('Debes seleccionar al menos una opción en "%s"', 'churrascoplanet'), $group['nombre'])
                        ));
                    }

                    // Validar mínimo
                    if ($min > 0 && $count > 0 && $count < $min) {
                        wp_send_json_error(array(
                            'message' => sprintf(__('Debes seleccionar al menos %d opciones en "%s"', 'churrascoplanet'), $min, $group['nombre'])
                        ));
                    }

                    // Validar máximo
                    if ($max > 0 && $count > $max) {
                        wp_send_json_error(array(
                            'message' => sprintf(__('Máximo %d opciones en "%s"', 'churrascoplanet'), $max, $group['nombre'])
                        ));
                    }

                    // Guardar extras validados
                    if ($count > 0) {
                        $group_items = $group['items'] ?? array();
                        $group_extras = array();

                        // Crear mapa de items por índice
                        $items_array = array_values($group_items);

                        foreach ($selected as $item_index) {
                            $item_index = intval($item_index);
                            if (isset($items_array[$item_index])) {
                                $item = $items_array[$item_index];
                                $precio = intval($item['precio'] ?? 0);
                                $group_extras[] = array(
                                    'id' => $item['id'] ?? 0,
                                    'nombre' => sanitize_text_field($item['nombre'] ?? ''),
                                    'precio' => $precio,
                                );
                                $extras_total += $precio;
                            }
                        }
                        if (!empty($group_extras)) {
                            $validated_extras[] = array(
                                'grupo_id' => $group_id,
                                'grupo' => sanitize_text_field($group['nombre'] ?? ''),
                                'items' => $group_extras,
                            );
                        }
                    }
                }
            }
        } else {
            // Fallback: usar sistema legacy
            $assigned_groups = get_post_meta($product_id, '_churrascoplanet_extras_groups', true);
            $all_groups = churrascoplanet_get_option('extras_grupos', array());

            if (is_array($assigned_groups)) {
                foreach ($assigned_groups as $group_index) {
                    if (!isset($all_groups[$group_index])) continue;

                    $group = $all_groups[$group_index];
                    $is_required = ($group['requerido'] ?? '0') === '1';
                    $min = intval($group['min'] ?? 0);
                    $max = intval($group['max'] ?? 0);

                    $selected = isset($extras[$group_index]) ? $extras[$group_index] : array();
                    if (!is_array($selected)) $selected = array($selected);

                    // Filtrar selecciones vacías
                    $selected = array_filter($selected, function($v) { return $v !== '' && $v !== null; });

                    $count = count($selected);

                    // Validar requerido
                    if ($is_required && $count === 0) {
                        wp_send_json_error(array(
                            'message' => sprintf(__('Debes seleccionar al menos una opción en "%s"', 'churrascoplanet'), $group['nombre'])
                        ));
                    }

                    // Validar mínimo
                    if ($min > 0 && $count > 0 && $count < $min) {
                        wp_send_json_error(array(
                            'message' => sprintf(__('Debes seleccionar al menos %d opciones en "%s"', 'churrascoplanet'), $min, $group['nombre'])
                        ));
                    }

                    // Validar máximo
                    if ($max > 0 && $count > $max) {
                        wp_send_json_error(array(
                            'message' => sprintf(__('Máximo %d opciones en "%s"', 'churrascoplanet'), $max, $group['nombre'])
                        ));
                    }

                    // Guardar extras validados
                    if ($count > 0) {
                        $group_items = $group['items'] ?? array();
                        $group_extras = array();
                        foreach ($selected as $item_index) {
                            $item_index = intval($item_index);
                            if (isset($group_items[$item_index])) {
                                $item = $group_items[$item_index];
                                $precio = intval($item['precio'] ?? 0);
                                $group_extras[] = array(
                                    'nombre' => sanitize_text_field($item['nombre'] ?? ''),
                                    'precio' => $precio,
                                );
                                $extras_total += $precio;
                            }
                        }
                        if (!empty($group_extras)) {
                            $validated_extras[] = array(
                                'grupo' => sanitize_text_field($group['nombre'] ?? ''),
                                'items' => $group_extras,
                            );
                        }
                    }
                }
            }
        }

        // Preparar cart_item_data con extras
        $cart_item_data = array();
        if (!empty($validated_extras)) {
            $cart_item_data['churrascoplanet_extras']       = $validated_extras;
            $cart_item_data['churrascoplanet_extras_total'] = $extras_total;

            // Hash determinístico de la selección de extras:
            //   • Mismos extras → mismo hash → WooCommerce agrupa el item (qty +1).
            //   • Extras distintos → hash distinto → WooCommerce crea línea separada.
            // NO se genera cuando el producto no tiene extras para que WooCommerce
            // agrupe productos simples sin extras de forma nativa.
            $cart_item_data['unique_key'] = md5( serialize( $validated_extras ) );
        }
        // Sin extras: $cart_item_data queda vacío → WC agrupa por product_id solo.

        // Agregar al carrito
        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_item_data);

        if ($cart_item_key) {
            wp_send_json_success( churrascoplanet_get_cart_fragments_data() );
        } else {
            wp_send_json_error(array('message' => __('Error al agregar al carrito', 'churrascoplanet')));
        }
    }

    /**
     * Mostrar extras en el carrito
     */
    public function display_extras_in_cart($item_data, $cart_item) {
        if (!empty($cart_item['churrascoplanet_extras'])) {
            foreach ($cart_item['churrascoplanet_extras'] as $extra_group) {
                $items_text = array();
                foreach ($extra_group['items'] as $item) {
                    $text = $item['nombre'];
                    if ($item['precio'] > 0) {
                        $text .= ' (+' . wc_price($item['precio']) . ')';
                    }
                    $items_text[] = $text;
                }
                $item_data[] = array(
                    'key'   => $extra_group['grupo'],
                    'value' => implode(', ', $items_text),
                );
            }
        }
        return $item_data;
    }

    /**
     * Calcular precio con extras
     */
    public function calculate_extras_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (did_action('woocommerce_before_calculate_totals') >= 2) return;

        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['churrascoplanet_extras_total'])) {
                $extras_total = floatval($cart_item['churrascoplanet_extras_total']);
                $base_price = floatval($cart_item['data']->get_price());
                $cart_item['data']->set_price($base_price + $extras_total);
            }
        }
    }

    /**
     * Guardar extras en la orden
     */
    public function save_extras_to_order($item, $cart_item_key, $values, $order) {
        if (!empty($values['churrascoplanet_extras'])) {
            $order_id = $order->get_id();
            $product_id = $item->get_product_id();

            foreach ($values['churrascoplanet_extras'] as $extra_group) {
                $items_text = array();
                foreach ($extra_group['items'] as $extra_item) {
                    $text = $extra_item['nombre'];
                    if ($extra_item['precio'] > 0) {
                        $text .= ' (+$' . number_format($extra_item['precio'], 0, ',', '.') . ')';
                    }
                    $items_text[] = $text;

                    // Guardar en la tabla de histórico cp_chp_pedido_extras
                    if (class_exists('ChurrascoPlanet_Pedido_Extras_Model')) {
                        $model = ChurrascoPlanet_Pedido_Extras_Model::get_instance();
                        $model->insert(array(
                            'order_id' => $order_id,
                            'order_item_id' => $item->get_id(),
                            'product_id' => $product_id,
                            'grupo_id' => $extra_group['grupo_id'] ?? null,
                            'grupo_nombre' => $extra_group['grupo'] ?? '',
                            'item_id' => $extra_item['id'] ?? null,
                            'item_nombre' => $extra_item['nombre'] ?? '',
                            'item_precio' => (int)($extra_item['precio'] ?? 0),
                            'cantidad' => $item->get_quantity(),
                            'subtotal' => (int)($extra_item['precio'] ?? 0) * $item->get_quantity(),
                        ));
                    }
                }
                $item->add_meta_data($extra_group['grupo'], implode(', ', $items_text));
            }
        }
    }
}

// Inicializar
ChurrascoPlanet_Product_Addons::get_instance();
