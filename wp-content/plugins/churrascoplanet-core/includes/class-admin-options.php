<?php
/**
 * ChurrascoPlanet Core - Panel de Administracion SIMPLIFICADO
 */

if (!defined('ABSPATH')) {
    exit;
}

// Registrar menú directamente sin clase
add_action('admin_menu', function() {
    add_menu_page(
        'Aspecto PlanetaChurrascos',
        'PlanetaChurrascos',
        'manage_woocommerce',
        'churrascoplanet-options',
        'churrascoplanet_render_admin_page',
        'dashicons-star-filled',
        59
    );

    // Submenú de Migración DB
    add_submenu_page(
        'churrascoplanet-options',
        __('Migración de Datos', 'churrascoplanet-core'),
        __('Migración DB', 'churrascoplanet-core'),
        'manage_options',
        'churrascoplanet-migration',
        'churrascoplanet_render_migration_page'
    );
});

// Función para renderizar la página
function churrascoplanet_render_admin_page() {
    $admin_page = CHP_CORE_PATH . 'admin/views/admin-page.php';

    echo '<div class="wrap">';
    echo '<h1>PlanetaChurrascos</h1>';

    if (file_exists($admin_page)) {
        // Crear instancia temporal para los métodos helper
        if (!class_exists('ChurrascoPlanet_Admin_Options_Helper')) {
            class ChurrascoPlanet_Admin_Options_Helper {
                private $option_name = 'churrascoplanet_options';
                private $options = array();

                public function __construct() {
                    $this->options = get_option($this->option_name, array());
                }

                public function get_option($key, $default = '') {
                    if (function_exists('churrascoplanet_get_option')) {
                        return churrascoplanet_get_option($key, $default);
                    }
                    return isset($this->options[$key]) ? $this->options[$key] : $default;
                }

                public function get_option_name() {
                    return $this->option_name;
                }

                public function render_image_field($name) {
                    $value = $this->get_option($name, '');
                    ?>
                    <div class="image-field">
                        <div class="image-preview" style="<?php echo $value ? '' : 'display:none;'; ?>">
                            <img src="<?php echo esc_url($value); ?>" alt="">
                            <button type="button" class="remove-image"><i class="fas fa-times"></i></button>
                        </div>
                        <input type="hidden" name="<?php echo $this->option_name; ?>[<?php echo $name; ?>]" value="<?php echo esc_attr($value); ?>" class="image-url">
                        <button type="button" class="button select-image"><i class="fas fa-upload"></i> Seleccionar Imagen</button>
                    </div>
                    <?php
                }

                public function render_color_field($name, $default = '#ffffff') {
                    $value = $this->get_option($name, $default);
                    ?>
                    <div class="color-field">
                        <input type="text" name="<?php echo $this->option_name; ?>[<?php echo $name; ?>]" value="<?php echo esc_attr($value); ?>" class="color-picker" data-default-color="<?php echo esc_attr($default); ?>">
                    </div>
                    <?php
                }

                public function render_icon_field($name, $default = 'fas fa-star') {
                    $value = $this->get_option($name, $default);
                    if (empty($value)) $value = $default;
                    ?>
                    <div class="icon-field">
                        <span class="icon-preview"><i class="<?php echo esc_attr($value); ?>"></i></span>
                        <input type="text" name="<?php echo $this->option_name; ?>[<?php echo $name; ?>]" value="<?php echo esc_attr($value); ?>" class="regular-text icon-input" placeholder="fas fa-star">
                        <a href="https://fontawesome.com/icons" target="_blank" class="button button-small"><i class="fas fa-external-link-alt"></i></a>
                    </div>
                    <?php
                }

                public function render_editor_field($name, $default = '') {
                    $value = $this->get_option($name, $default);
                    $editor_id = 'editor_' . preg_replace('/[^a-z0-9]/', '_', strtolower($name));
                    wp_editor($value, $editor_id, array(
                        'textarea_name' => $this->option_name . '[' . $name . ']',
                        'textarea_rows' => 4,
                        'media_buttons' => false,
                        'teeny' => true,
                    ));
                }
            }
        }

        $admin = new ChurrascoPlanet_Admin_Options_Helper();
        include $admin_page;
    } else {
        echo '<p>Error: No se encontró el archivo de vista: ' . esc_html($admin_page) . '</p>';
    }

    echo '</div>';
}

// Encolar assets
add_action('admin_enqueue_scripts', function($hook) {
    if ('toplevel_page_churrascoplanet-options' !== $hook) {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_enqueue_editor();

    wp_enqueue_style('font-awesome-admin', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0');
    wp_enqueue_style('churrascoplanet-admin', CHP_CORE_URL . 'admin/css/admin-options.css', array('wp-color-picker'), CHP_CORE_VERSION);
    wp_enqueue_script('churrascoplanet-admin', CHP_CORE_URL . 'admin/js/admin-options.js', array('jquery', 'wp-color-picker', 'jquery-ui-sortable'), CHP_CORE_VERSION, true);

    wp_localize_script('churrascoplanet-admin', 'churrascoplanetAdmin', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('churrascoplanet_admin_nonce'),
        'strings' => array(
            'confirmDelete' => '¿Estás seguro de eliminar este elemento?',
            'saved' => 'Cambios guardados correctamente',
            'error' => 'Error al guardar los cambios',
            'selectImage' => 'Seleccionar imagen',
            'useImage' => 'Usar esta imagen',
        ),
    ));
});

// AJAX para guardar
add_action('wp_ajax_churrascoplanet_save_options', function() {
    check_ajax_referer('churrascoplanet_admin_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    // El JS envía los datos serializados directamente desde el form.
    // Los campos llegan como churrascoplanet_options[key] en $_POST.
    // Soportar ambos formatos por retrocompatibilidad.
    if (isset($_POST['churrascoplanet_options']) && is_array($_POST['churrascoplanet_options'])) {
        $options = wp_unslash($_POST['churrascoplanet_options']);
    } elseif (isset($_POST['options']) && is_array($_POST['options'])) {
        $options = wp_unslash($_POST['options']);
    } else {
        $options = array();
    }

    // Sanitizar recursivamente
    $options = churrascoplanet_sanitize_options_recursive($options);

    update_option('churrascoplanet_options', $options);

    if (class_exists('ChurrascoPlanet_Compat')) {
        ChurrascoPlanet_Compat::get_instance()->save_all_options($options);
    }

    wp_send_json_success(array('message' => 'Guardado correctamente'));
});

// Sanitizar opciones recursivamente (permite HTML básico en editores, sanea el resto)
function churrascoplanet_sanitize_options_recursive($data) {
    if (!is_array($data)) {
        return sanitize_textarea_field($data);
    }
    $clean = array();
    foreach ($data as $key => $value) {
        $clean_key = sanitize_key($key);
        if (is_array($value)) {
            $clean[$clean_key] = churrascoplanet_sanitize_options_recursive($value);
        } else {
            // Permitir HTML en campos de editor (titulo, subtitulo)
            $editor_keys = array('inicio_hero_titulo', 'inicio_hero_subtitulo', 'promo_titulo', 'promo_subtitulo', 'menu_titulo', 'menu_subtitulo', 'locales_titulo', 'locales_subtitulo', 'delivery_parrafo');
            if (in_array($clean_key, $editor_keys)) {
                $clean[$clean_key] = wp_kses_post($value);
            } else {
                $clean[$clean_key] = sanitize_textarea_field($value);
            }
        }
    }
    return $clean;
}

// Registrar settings
add_action('admin_init', function() {
    register_setting('churrascoplanet_options_group', 'churrascoplanet_options');
});

// Permitir que shop_manager guarde opciones via settings API (options.php)
add_filter('option_page_capability_churrascoplanet_options_group', function() {
    return 'manage_woocommerce';
});

// Función para renderizar la página de migración
function churrascoplanet_render_migration_page() {
    // Usar la clase Migration si existe
    if (class_exists('ChurrascoPlanet_Migration')) {
        ChurrascoPlanet_Migration::get_instance()->render_migration_page();
        return;
    }

    // Fallback básico si la clase no existe
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-database"></span> Migración de Base de Datos</h1>
        <div class="notice notice-error">
            <p>Error: La clase ChurrascoPlanet_Migration no está disponible.</p>
            <p>Verifica que el archivo <code>includes/class-migration.php</code> se haya cargado correctamente.</p>
        </div>
    </div>
    <?php
}

// Encolar assets para la página de migración
add_action('admin_enqueue_scripts', function($hook) {
    if ('planetachurrascos_page_churrascoplanet-migration' !== $hook) {
        return;
    }

    wp_enqueue_style('font-awesome-admin', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0');
});

// AJAX: Guardar cliente (crear o actualizar)
add_action('wp_ajax_chp_save_customer', function() {
    check_ajax_referer('chp_customer_crud', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('No autorizado.', 'churrascoplanet-core')));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'chp_customers';

    $id         = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
    $first_name = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name  = sanitize_text_field($_POST['last_name'] ?? '');
    $email      = sanitize_email($_POST['email'] ?? '');
    $phone      = sanitize_text_field($_POST['phone'] ?? '');
    $whatsapp   = sanitize_text_field($_POST['whatsapp'] ?? '');
    $type       = sanitize_text_field($_POST['customer_type'] ?? 'registered');
    $source     = sanitize_text_field($_POST['registration_source'] ?? '');
    $marketing  = isset($_POST['accepts_marketing']) ? 1 : 0;
    $wa_consent = isset($_POST['accepts_whatsapp']) ? 1 : 0;
    $orders     = absint($_POST['total_orders'] ?? 0);
    $spent      = floatval($_POST['total_spent'] ?? 0);

    if (empty($first_name)) {
        wp_send_json_error(array('message' => __('El nombre es obligatorio.', 'churrascoplanet-core')));
    }
    if (empty($email) || !is_email($email)) {
        wp_send_json_error(array('message' => __('Email inválido.', 'churrascoplanet-core')));
    }

    $data = array(
        'first_name'          => $first_name,
        'last_name'           => $last_name,
        'email'               => $email,
        'phone'               => $phone,
        'whatsapp'            => $whatsapp,
        'customer_type'       => $type,
        'registration_source' => $source,
        'accepts_marketing'   => $marketing,
        'accepts_whatsapp'    => $wa_consent,
        'total_orders'        => $orders,
        'total_spent'         => $spent,
    );

    if ($id > 0) {
        // Verificar que no exista el email en otro registro
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE email = %s AND id != %d",
            $email, $id
        ));
        if ($exists) {
            wp_send_json_error(array('message' => __('Ya existe otro cliente con ese email.', 'churrascoplanet-core')));
        }
        $result = $wpdb->update($table, $data, array('id' => $id), null, array('%d'));
        if ($result === false) {
            wp_send_json_error(array('message' => __('Error al actualizar el cliente.', 'churrascoplanet-core')));
        }
        wp_send_json_success(array('message' => __('Cliente actualizado correctamente.', 'churrascoplanet-core')));
    } else {
        // Crear
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email = %s", $email));
        if ($exists) {
            wp_send_json_error(array('message' => __('Ya existe un cliente con ese email.', 'churrascoplanet-core')));
        }
        $data['created_at'] = current_time('mysql');
        $result = $wpdb->insert($table, $data);
        if (!$result) {
            wp_send_json_error(array('message' => __('Error al crear el cliente.', 'churrascoplanet-core')));
        }
        wp_send_json_success(array('message' => __('Cliente creado correctamente.', 'churrascoplanet-core'), 'id' => $wpdb->insert_id));
    }
});

// AJAX: Eliminar cliente
add_action('wp_ajax_chp_delete_customer', function() {
    check_ajax_referer('chp_customer_crud', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('No autorizado.', 'churrascoplanet-core')));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'chp_customers';
    $id    = absint($_POST['customer_id'] ?? 0);

    if (!$id) {
        wp_send_json_error(array('message' => __('ID inválido.', 'churrascoplanet-core')));
    }

    $result = $wpdb->delete($table, array('id' => $id), array('%d'));
    if (!$result) {
        wp_send_json_error(array('message' => __('Error al eliminar el cliente.', 'churrascoplanet-core')));
    }
    wp_send_json_success(array('message' => __('Cliente eliminado.', 'churrascoplanet-core')));
});

// Exportación CSV de clientes
add_action('admin_init', function() {
    if (!isset($_GET['chp_export']) || $_GET['chp_export'] !== 'customers') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'chp_customers';

    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    if (!$table_exists) {
        return;
    }

    $filter_type = isset($_GET['customer_type']) ? sanitize_text_field($_GET['customer_type']) : '';
    $search = isset($_GET['chp_search']) ? sanitize_text_field($_GET['chp_search']) : '';

    $where = ['1=1'];
    $prepare_args = [];

    if ($filter_type) {
        $where[] = 'customer_type = %s';
        $prepare_args[] = $filter_type;
    }
    if ($search) {
        $where[] = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s)';
        $like = '%' . $wpdb->esc_like($search) . '%';
        $prepare_args = array_merge($prepare_args, [$like, $like, $like, $like]);
    }

    $where_clause = implode(' AND ', $where);
    $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC";
    if (!empty($prepare_args)) {
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$prepare_args), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($sql, ARRAY_A);
    }

    $filename = 'clientes-churrascoplanet-' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

    // Encabezados
    fputcsv($output, [
        'ID', 'User ID', 'Email', 'Nombre', 'Apellido', 'Teléfono', 'WhatsApp',
        'Tipo', 'Origen', 'Pedidos', 'Total Gastado', 'Último Pedido',
        'Acepta Marketing', 'Acepta WhatsApp', 'Registrado'
    ]);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['user_id'] ?? '',
            $row['email'],
            $row['first_name'],
            $row['last_name'],
            $row['phone'],
            $row['whatsapp'],
            $row['customer_type'],
            $row['registration_source'],
            $row['total_orders'],
            $row['total_spent'],
            $row['last_order_date'] ?? '',
            $row['accepts_marketing'] ? 'Sí' : 'No',
            $row['accepts_whatsapp'] ? 'Sí' : 'No',
            $row['created_at'],
        ]);
    }

    fclose($output);
    exit;
});

// ── AJAX: CRUD Cupones WooCommerce ────────────────────────────────────────────

// Obtener datos de un cupón para edición
add_action('wp_ajax_chp_get_coupon', function() {
    check_ajax_referer('churrascoplanet_admin_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $id = absint($_POST['coupon_id'] ?? 0);
    if (!$id) {
        wp_send_json_error(array('message' => 'ID inválido'));
    }

    $coupon = new WC_Coupon($id);
    if (!$coupon->get_id()) {
        wp_send_json_error(array('message' => 'Cupón no encontrado'));
    }

    $expires = $coupon->get_date_expires();
    wp_send_json_success(array(
        'id'                   => $coupon->get_id(),
        'code'                 => $coupon->get_code(),
        'description'          => $coupon->get_description(),
        'discount_type'        => $coupon->get_discount_type(),
        'coupon_amount'        => $coupon->get_amount(),
        'minimum_amount'       => $coupon->get_minimum_amount(),
        'maximum_amount'       => $coupon->get_maximum_amount(),
        'date_expires'         => $expires ? $expires->date('Y-m-d') : '',
        'usage_limit'          => $coupon->get_usage_limit(),
        'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
        'individual_use'       => $coupon->get_individual_use() ? '1' : '',
        'free_shipping'        => $coupon->get_free_shipping() ? '1' : '',
        'status'               => get_post_status($id),
    ));
});

// Crear cupón
add_action('wp_ajax_chp_create_coupon', function() {
    check_ajax_referer('churrascoplanet_admin_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $code = strtolower(sanitize_text_field($_POST['code'] ?? ''));
    if (empty($code)) {
        wp_send_json_error(array('message' => 'El código es obligatorio'));
    }
    if (wc_get_coupon_id_by_code($code)) {
        wp_send_json_error(array('message' => 'Ya existe un cupón con ese código'));
    }

    $coupon = new WC_Coupon();
    $coupon->set_code($code);
    $coupon->set_description(sanitize_text_field($_POST['description'] ?? ''));
    $coupon->set_discount_type(sanitize_text_field($_POST['discount_type'] ?? 'percent'));
    $coupon->set_amount(floatval($_POST['coupon_amount'] ?? 0));
    $min = floatval($_POST['minimum_amount'] ?? 0);
    $coupon->set_minimum_amount($min > 0 ? $min : '');
    $max = floatval($_POST['maximum_amount'] ?? 0);
    $coupon->set_maximum_amount($max > 0 ? $max : '');
    $coupon->set_individual_use(!empty($_POST['individual_use']));
    $coupon->set_free_shipping(!empty($_POST['free_shipping']));

    $usage_limit = intval($_POST['usage_limit'] ?? 0);
    if ($usage_limit > 0) $coupon->set_usage_limit($usage_limit);

    $usage_limit_user = intval($_POST['usage_limit_per_user'] ?? 0);
    if ($usage_limit_user > 0) $coupon->set_usage_limit_per_user($usage_limit_user);

    if (!empty($_POST['date_expires'])) {
        $coupon->set_date_expires(strtotime($_POST['date_expires']));
    }

    $id = $coupon->save();

    $status = sanitize_text_field($_POST['status'] ?? 'publish');
    wp_update_post(array('ID' => $id, 'post_status' => $status));

    wp_send_json_success(array_merge(
        array('message' => 'Cupón creado'),
        chp_coupon_table_data()
    ));
});

// Actualizar cupón
add_action('wp_ajax_chp_update_coupon', function() {
    check_ajax_referer('churrascoplanet_admin_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $id = absint($_POST['coupon_id'] ?? 0);
    if (!$id) {
        wp_send_json_error(array('message' => 'ID inválido'));
    }

    $coupon = new WC_Coupon($id);
    if (!$coupon->get_id()) {
        wp_send_json_error(array('message' => 'Cupón no encontrado'));
    }

    $code = strtolower(sanitize_text_field($_POST['code'] ?? ''));
    if (empty($code)) {
        wp_send_json_error(array('message' => 'El código es obligatorio'));
    }

    $existing_id = wc_get_coupon_id_by_code($code);
    if ($existing_id && $existing_id !== $id) {
        wp_send_json_error(array('message' => 'Ya existe otro cupón con ese código'));
    }

    $coupon->set_code($code);
    $coupon->set_description(sanitize_text_field($_POST['description'] ?? ''));
    $coupon->set_discount_type(sanitize_text_field($_POST['discount_type'] ?? 'percent'));
    $coupon->set_amount(floatval($_POST['coupon_amount'] ?? 0));
    $min = floatval($_POST['minimum_amount'] ?? 0);
    $coupon->set_minimum_amount($min > 0 ? $min : '');
    $max = floatval($_POST['maximum_amount'] ?? 0);
    $coupon->set_maximum_amount($max > 0 ? $max : '');
    $coupon->set_individual_use(!empty($_POST['individual_use']));
    $coupon->set_free_shipping(!empty($_POST['free_shipping']));

    $usage_limit = intval($_POST['usage_limit'] ?? 0);
    $coupon->set_usage_limit($usage_limit > 0 ? $usage_limit : '');

    $usage_limit_user = intval($_POST['usage_limit_per_user'] ?? 0);
    $coupon->set_usage_limit_per_user($usage_limit_user > 0 ? $usage_limit_user : '');

    if (!empty($_POST['date_expires'])) {
        $coupon->set_date_expires(strtotime($_POST['date_expires']));
    } else {
        $coupon->set_date_expires('');
    }

    $coupon->save();

    $status = sanitize_text_field($_POST['status'] ?? 'publish');
    wp_update_post(array('ID' => $id, 'post_status' => $status));

    wp_send_json_success(array_merge(
        array('message' => 'Cupón actualizado'),
        chp_coupon_table_data()
    ));
});

// Eliminar cupón
add_action('wp_ajax_chp_delete_coupon', function() {
    check_ajax_referer('churrascoplanet_admin_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    $id = absint($_POST['coupon_id'] ?? 0);
    if (!$id) {
        wp_send_json_error(array('message' => 'ID inválido'));
    }

    $result = wp_delete_post($id, true);
    if (!$result) {
        wp_send_json_error(array('message' => 'Error al eliminar el cupón'));
    }

    wp_send_json_success(array_merge(
        array('message' => 'Cupón eliminado'),
        chp_coupon_table_data()
    ));
});

/**
 * Genera el HTML del tbody y estadísticas para actualizar la UI tras operaciones CRUD.
 */
if (!function_exists('chp_coupon_table_data')) {
    function chp_coupon_table_data() {
        global $wpdb;
        $now = current_time('timestamp');

        $coupons_raw = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_status,
                    MAX(CASE WHEN pm.meta_key='discount_type'   THEN pm.meta_value END) AS discount_type,
                    MAX(CASE WHEN pm.meta_key='coupon_amount'   THEN pm.meta_value END) AS coupon_amount,
                    MAX(CASE WHEN pm.meta_key='usage_count'     THEN pm.meta_value END) AS usage_count,
                    MAX(CASE WHEN pm.meta_key='usage_limit'     THEN pm.meta_value END) AS usage_limit,
                    MAX(CASE WHEN pm.meta_key='date_expires'    THEN pm.meta_value END) AS date_expires,
                    MAX(CASE WHEN pm.meta_key='minimum_amount'  THEN pm.meta_value END) AS minimum_amount,
                    MAX(CASE WHEN pm.meta_key='free_shipping'   THEN pm.meta_value END) AS free_shipping,
                    MAX(CASE WHEN pm.meta_key='individual_use'  THEN pm.meta_value END) AS individual_use
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'shop_coupon'
             GROUP BY p.ID
             ORDER BY p.post_date DESC"
        );

        $discount_labels = array(
            'percent'       => '% Porcentaje',
            'fixed_cart'    => '$ Fijo en carrito',
            'fixed_product' => '$ Fijo en producto',
        );

        $total = count($coupons_raw);
        $activos = $expirados = $uso_total = 0;
        $tbody = '';

        foreach ($coupons_raw as $coupon) {
            $uso_total += (int) $coupon->usage_count;
            $is_expired = $coupon->post_status === 'publish' && !empty($coupon->date_expires) && (int) $coupon->date_expires < $now;
            $is_active  = $coupon->post_status === 'publish' && !$is_expired;
            if ($is_active) $activos++;
            if ($is_expired) $expirados++;

            $amount_fmt = $coupon->discount_type === 'percent'
                ? number_format((float) $coupon->coupon_amount, 0) . '%'
                : '$' . number_format((float) $coupon->coupon_amount, 0, ',', '.');

            $usos     = (int) $coupon->usage_count;
            $limit    = !empty($coupon->usage_limit) ? (int) $coupon->usage_limit : null;
            $usos_str = $usos . ' / ' . ($limit ?: '∞');
            $uso_pct  = $limit ? min(100, round($usos / $limit * 100)) : 0;
            $uso_color = $uso_pct >= 90 ? '#dc3232' : ($uso_pct >= 60 ? '#ffb268' : '#00a32a');

            $expiry_str   = '—';
            $expiry_class = '';
            if (!empty($coupon->date_expires)) {
                $ts = (int) $coupon->date_expires;
                $expiry_str = date_i18n('d/m/Y', $ts);
                $days_left = ($ts - $now) / DAY_IN_SECONDS;
                $expiry_class = $days_left < 0 ? 'chp-expiry-past' : ($days_left <= 7 ? 'chp-expiry-soon' : '');
            }

            $min_str = !empty($coupon->minimum_amount) && (float) $coupon->minimum_amount > 0
                ? '$' . number_format((float) $coupon->minimum_amount, 0, ',', '.')
                : '—';

            $row_class = $is_expired ? 'chp-row-expired' : ($coupon->post_status !== 'publish' ? 'chp-row-draft' : '');

            $badge = $is_active
                ? '<span class="chp-badge chp-badge-active"><i class="fas fa-circle"></i> Activo</span>'
                : ($is_expired
                    ? '<span class="chp-badge chp-badge-expired"><i class="fas fa-times-circle"></i> Expirado</span>'
                    : '<span class="chp-badge chp-badge-draft"><i class="fas fa-pause-circle"></i> Borrador</span>');

            $shipping_tag   = $coupon->free_shipping === 'yes' ? '<span class="chp-tag chp-tag-shipping"><i class="fas fa-shipping-fast"></i> Envío gratis</span>' : '';
            $individual_tag = $coupon->individual_use === 'yes' ? '<span class="chp-tag chp-tag-individual"><i class="fas fa-user"></i> Uso individual</span>' : '';
            $usage_bar      = $limit ? '<div class="chp-usage-bar"><div class="chp-usage-fill" style="width:' . $uso_pct . '%;background:' . esc_attr($uso_color) . ';"></div></div>' : '';

            $tbody .= '<tr class="' . esc_attr($row_class) . '" data-id="' . $coupon->ID . '">';
            $tbody .= '<td><div class="chp-code-wrap"><code class="chp-coupon-code" title="Copiar" onclick="chpCopyCode(this)">' . esc_html(strtoupper($coupon->post_title)) . '</code><i class="fas fa-copy chp-copy-hint"></i></div>' . $shipping_tag . $individual_tag . '</td>';
            $tbody .= '<td>' . esc_html($discount_labels[$coupon->discount_type] ?? $coupon->discount_type) . '</td>';
            $tbody .= '<td><strong>' . esc_html($amount_fmt) . '</strong></td>';
            $tbody .= '<td>' . esc_html($min_str) . '</td>';
            $tbody .= '<td>' . esc_html($usos_str) . $usage_bar . '</td>';
            $tbody .= '<td class="' . esc_attr($expiry_class) . '">' . esc_html($expiry_str) . '</td>';
            $tbody .= '<td>' . $badge . '</td>';
            $tbody .= '<td class="chp-actions-cell">';
            $tbody .= '<button type="button" class="button button-small chp-btn-edit" data-id="' . $coupon->ID . '" title="Editar"><i class="fas fa-edit"></i></button> ';
            $tbody .= '<button type="button" class="button button-small chp-btn-delete" data-id="' . $coupon->ID . '" data-code="' . esc_attr(strtoupper($coupon->post_title)) . '" title="Eliminar"><i class="fas fa-trash"></i></button>';
            $tbody .= '</td></tr>';
        }

        return array(
            'tbody' => $tbody,
            'stats' => array(
                'total'     => $total,
                'activos'   => $activos,
                'expirados' => $expirados,
                'uso_total' => $uso_total,
            ),
        );
    }
}
