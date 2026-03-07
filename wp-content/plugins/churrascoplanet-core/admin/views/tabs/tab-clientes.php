<?php
/**
 * Tab de Clientes - Panel de Administración
 *
 * Muestra la tabla chp_customers con filtros y estadísticas.
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'chp_customers';

// Verificar si la tabla existe
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

if (!$table_exists) {
    ?>
    <div class="chp-admin-section">
        <h2><i class="fas fa-users"></i> <?php _e('Clientes', 'churrascoplanet-core'); ?></h2>
        <div class="notice notice-warning inline">
            <p><?php _e('La tabla de clientes no ha sido creada. Desactiva y reactiva el plugin para crearla.', 'churrascoplanet-core'); ?></p>
        </div>
    </div>
    <?php
    return;
}

// Filtros
$filter_type = isset($_GET['customer_type']) ? sanitize_text_field($_GET['customer_type']) : '';
$search = isset($_GET['chp_search']) ? sanitize_text_field($_GET['chp_search']) : '';
$paged = isset($_GET['chp_paged']) ? max(1, absint($_GET['chp_paged'])) : 1;
$per_page = 20;
$offset = ($paged - 1) * $per_page;

// Construir query
$where = ['1=1'];
$prepare_args = [];

if ($filter_type) {
    $where[] = 'customer_type = %s';
    $prepare_args[] = $filter_type;
}

if ($search) {
    $where[] = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s)';
    $like = '%' . $wpdb->esc_like($search) . '%';
    $prepare_args[] = $like;
    $prepare_args[] = $like;
    $prepare_args[] = $like;
    $prepare_args[] = $like;
}

$where_clause = implode(' AND ', $where);

// Total de resultados
$total_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
if (!empty($prepare_args)) {
    $total = $wpdb->get_var($wpdb->prepare($total_sql, ...$prepare_args));
} else {
    $total = $wpdb->get_var($total_sql);
}
$total_pages = ceil($total / $per_page);

// Obtener clientes
$sql = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}";
if (!empty($prepare_args)) {
    $customers = $wpdb->get_results($wpdb->prepare($sql, ...$prepare_args), ARRAY_A);
} else {
    $customers = $wpdb->get_results($sql, ARRAY_A);
}

// Conteos por tipo
$counts = $wpdb->get_results(
    "SELECT customer_type, COUNT(*) as count FROM {$table_name} GROUP BY customer_type",
    ARRAY_A
);
$type_counts = [];
$total_all = 0;
foreach ($counts as $row) {
    $type_counts[$row['customer_type']] = absint($row['count']);
    $total_all += absint($row['count']);
}

// Etiquetas de tipos
$type_labels = [
    'guest'           => __('Invitados', 'churrascoplanet-core'),
    'registered'      => __('Registrados', 'churrascoplanet-core'),
    'social_facebook' => __('Facebook', 'churrascoplanet-core'),
    'social_google'   => __('Google', 'churrascoplanet-core'),
    'social_apple'    => __('Apple', 'churrascoplanet-core'),
];

$type_icons = [
    'guest'           => 'fas fa-user-secret',
    'registered'      => 'fas fa-user-check',
    'social_facebook' => 'fab fa-facebook',
    'social_google'   => 'fab fa-google',
    'social_apple'    => 'fab fa-apple',
];

$type_colors = [
    'guest'           => '#6c757d',
    'registered'      => '#28a745',
    'social_facebook' => '#1877F2',
    'social_google'   => '#DB4437',
    'social_apple'    => '#000000',
];

// Etiquetas para el origen de registro (solo para tipo 'registered')
$source_labels = [
    'woocommerce_myaccount'  => __('Formulario web', 'churrascoplanet-core'),
    'woocommerce_checkout'   => __('Checkout', 'churrascoplanet-core'),
    'wordpress_registration' => __('Registro WP', 'churrascoplanet-core'),
    'social_facebook'        => '',  // cubierto por customer_type
    'social_google'          => '',  // cubierto por customer_type
];

$admin_url = admin_url('admin.php?page=churrascoplanet-options#tab-clientes');
?>

<div class="chp-admin-section chp-clientes-section">
    <h2><i class="fas fa-users"></i> <?php _e('Clientes', 'churrascoplanet-core'); ?></h2>
    <p class="description"><?php _e('Gestión y visualización de todos los clientes (registrados e invitados).', 'churrascoplanet-core'); ?></p>

    <!-- Tarjetas de resumen -->
    <div class="chp-admin-stats" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin: 20px 0;">
        <div class="chp-admin-stat-card" style="background:#f8f9fa; border-left: 4px solid #C41E3A; padding:15px; border-radius:6px;">
            <div style="font-size:24px; font-weight:700; color:#333;"><?php echo esc_html($total_all); ?></div>
            <div style="font-size:13px; color:#666;"><?php _e('Total Clientes', 'churrascoplanet-core'); ?></div>
        </div>
        <?php foreach ($type_labels as $type_key => $type_label): ?>
        <div class="chp-admin-stat-card" style="background:#f8f9fa; border-left: 4px solid <?php echo esc_attr($type_colors[$type_key]); ?>; padding:15px; border-radius:6px;">
            <div style="font-size:24px; font-weight:700; color:#333;"><?php echo esc_html($type_counts[$type_key] ?? 0); ?></div>
            <div style="font-size:13px; color:#666;">
                <i class="<?php echo esc_attr($type_icons[$type_key]); ?>"></i>
                <?php echo esc_html($type_label); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filtros -->
    <div class="chp-admin-filters" style="display:flex; gap:10px; align-items:center; margin:20px 0; flex-wrap:wrap;">
        <form method="get" action="<?php echo admin_url('admin.php'); ?>" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="page" value="churrascoplanet-options">

            <select name="customer_type" style="min-width:160px;">
                <option value=""><?php _e('Todos los tipos', 'churrascoplanet-core'); ?></option>
                <?php foreach ($type_labels as $type_key => $type_label): ?>
                <option value="<?php echo esc_attr($type_key); ?>" <?php selected($filter_type, $type_key); ?>>
                    <?php echo esc_html($type_label); ?> (<?php echo esc_html($type_counts[$type_key] ?? 0); ?>)
                </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="chp_search" value="<?php echo esc_attr($search); ?>"
                   placeholder="<?php esc_attr_e('Buscar por email, nombre o teléfono...', 'churrascoplanet-core'); ?>"
                   style="min-width:250px;">

            <button type="submit" class="button button-secondary">
                <i class="fas fa-search"></i> <?php _e('Filtrar', 'churrascoplanet-core'); ?>
            </button>

            <?php if ($filter_type || $search): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=churrascoplanet-options')); ?>" class="button">
                <i class="fas fa-times"></i> <?php _e('Limpiar', 'churrascoplanet-core'); ?>
            </a>
            <?php endif; ?>
        </form>

        <div style="margin-left:auto; display:flex; gap:8px;">
            <button type="button" class="button button-primary" onclick="chpOpenModal('add')">
                <i class="fas fa-plus"></i> <?php _e('Agregar cliente', 'churrascoplanet-core'); ?>
            </button>
            <button type="button" class="button" id="chp-export-customers" onclick="chpExportCustomers()">
                <i class="fas fa-download"></i> <?php _e('Exportar CSV', 'churrascoplanet-core'); ?>
            </button>
        </div>
    </div>

    <!-- Tabla de clientes -->
    <?php if (empty($customers)): ?>
    <div class="notice notice-info inline">
        <p><?php _e('No se encontraron clientes con los filtros seleccionados.', 'churrascoplanet-core'); ?></p>
    </div>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
        <thead>
            <tr>
                <th style="width:30px;">ID</th>
                <th><?php _e('Cliente', 'churrascoplanet-core'); ?></th>
                <th><?php _e('Email', 'churrascoplanet-core'); ?></th>
                <th><?php _e('Teléfono', 'churrascoplanet-core'); ?></th>
                <th style="width:120px;"><?php _e('Tipo', 'churrascoplanet-core'); ?></th>
                <th style="width:80px;"><?php _e('Pedidos', 'churrascoplanet-core'); ?></th>
                <th style="width:100px;"><?php _e('Total', 'churrascoplanet-core'); ?></th>
                <th style="width:100px;"><?php _e('Marketing', 'churrascoplanet-core'); ?></th>
                <th style="width:130px;"><?php _e('Registrado', 'churrascoplanet-core'); ?></th>
                <th style="width:100px;"><?php _e('Acciones', 'churrascoplanet-core'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $c):
                $full_name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                $type_key = $c['customer_type'] ?? 'guest';
            ?>
            <tr>
                <td><?php echo esc_html($c['id']); ?></td>
                <td>
                    <strong>
                    <?php if (!empty($c['user_id'])): ?>
                        <a href="<?php echo esc_url(get_edit_user_link($c['user_id'])); ?>">
                            <?php echo esc_html($full_name ?: __('(sin nombre)', 'churrascoplanet-core')); ?>
                        </a>
                    <?php else: ?>
                        <?php echo esc_html($full_name ?: __('(sin nombre)', 'churrascoplanet-core')); ?>
                    <?php endif; ?>
                    </strong>
                    <?php if (empty($c['user_id'])): ?>
                        <br><small style="color:#999;"><?php _e('Sin cuenta', 'churrascoplanet-core'); ?></small>
                    <?php else: ?>
                        <br><small style="color:#999;">WP ID: <?php echo esc_html($c['user_id']); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="mailto:<?php echo esc_attr($c['email']); ?>"><?php echo esc_html($c['email']); ?></a>
                </td>
                <td><?php echo esc_html($c['phone'] ?: '—'); ?></td>
                <td>
                    <span style="display:inline-flex; align-items:center; gap:4px; background:<?php echo esc_attr($type_colors[$type_key] ?? '#666'); ?>; color:#fff; padding:3px 8px; border-radius:12px; font-size:12px;">
                        <i class="<?php echo esc_attr($type_icons[$type_key] ?? 'fas fa-user'); ?>" style="font-size:11px;"></i>
                        <?php echo esc_html($type_labels[$type_key] ?? $type_key); ?>
                    </span>
                    <?php
                    if ($type_key === 'registered') {
                        $src = $c['registration_source'] ?? '';
                        $src_label = $source_labels[$src] ?? '';
                        if ($src_label) {
                            echo '<br><small style="color:#888; font-size:11px;">' . esc_html($src_label) . '</small>';
                        }
                    }
                    ?>
                </td>
                <td style="text-align:center;"><?php echo esc_html($c['total_orders'] ?? 0); ?></td>
                <td><?php echo function_exists('wc_price') ? wc_price($c['total_spent'] ?? 0) : '$' . number_format($c['total_spent'] ?? 0, 0); ?></td>
                <td style="text-align:center;">
                    <?php if (!empty($c['accepts_marketing'])): ?>
                        <span style="color:#28a745;" title="<?php esc_attr_e('Acepta marketing', 'churrascoplanet-core'); ?>"><i class="fas fa-check-circle"></i></span>
                    <?php else: ?>
                        <span style="color:#ccc;" title="<?php esc_attr_e('No acepta marketing', 'churrascoplanet-core'); ?>"><i class="fas fa-minus-circle"></i></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    if (!empty($c['created_at'])) {
                        echo esc_html(date_i18n('d/m/Y', strtotime($c['created_at'])));
                        echo '<br><small style="color:#999;">' . esc_html(date_i18n('H:i', strtotime($c['created_at']))) . '</small>';
                    } else {
                        echo '—';
                    }
                    ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php
                    $edit_data = json_encode(array(
                        'id'                   => $c['id'],
                        'first_name'           => $c['first_name'] ?? '',
                        'last_name'            => $c['last_name'] ?? '',
                        'email'                => $c['email'] ?? '',
                        'phone'                => $c['phone'] ?? '',
                        'whatsapp'             => $c['whatsapp'] ?? '',
                        'customer_type'        => $c['customer_type'] ?? 'guest',
                        'registration_source'  => $c['registration_source'] ?? '',
                        'accepts_marketing'    => $c['accepts_marketing'] ?? '0',
                        'accepts_whatsapp'     => $c['accepts_whatsapp'] ?? '0',
                        'total_orders'         => $c['total_orders'] ?? 0,
                        'total_spent'          => $c['total_spent'] ?? 0,
                    ));
                    $delete_name = esc_js(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: ($c['email'] ?? ''));
                    ?>
                    <button type="button" class="button button-small"
                            onclick='chpOpenModal("edit", <?php echo $edit_data; ?>)'
                            title="<?php esc_attr_e('Editar', 'churrascoplanet-core'); ?>">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button" class="button button-small"
                            onclick='chpOpenDeleteModal(<?php echo (int)$c["id"]; ?>, "<?php echo $delete_name; ?>")'
                            style="color:#e74c3c; border-color:#e74c3c;"
                            title="<?php esc_attr_e('Eliminar', 'churrascoplanet-core'); ?>">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <div class="tablenav bottom" style="margin-top:15px;">
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php printf(__('%s elementos', 'churrascoplanet-core'), number_format_i18n($total)); ?>
            </span>
            <span class="pagination-links" style="display:inline-flex; gap:4px;">
                <?php if ($paged > 1): ?>
                <a class="button" href="<?php echo esc_url(add_query_arg('chp_paged', $paged - 1)); ?>">&laquo; <?php _e('Anterior', 'churrascoplanet-core'); ?></a>
                <?php endif; ?>

                <span class="button disabled">
                    <?php printf(__('Página %d de %d', 'churrascoplanet-core'), $paged, $total_pages); ?>
                </span>

                <?php if ($paged < $total_pages): ?>
                <a class="button" href="<?php echo esc_url(add_query_arg('chp_paged', $paged + 1)); ?>"><?php _e('Siguiente', 'churrascoplanet-core'); ?> &raquo;</a>
                <?php endif; ?>
            </span>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ==========================================
     Modal Agregar / Editar Cliente
     ========================================== -->
<div id="chpCustomerModal" style="display:none; position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:min(620px,95vw); max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:20px 24px 16px; border-bottom:1px solid #eee;">
            <h2 id="chpModalTitle" style="margin:0; font-size:18px;"></h2>
            <button type="button" onclick="chpCloseModal()" style="background:none; border:none; font-size:22px; cursor:pointer; color:#666; line-height:1; padding:0 4px;">&times;</button>
        </div>
        <form id="chpCustomerForm" style="padding:24px;">
            <input type="hidden" id="chpCustomerId" name="customer_id" value="">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:5px;"><?php _e('Nombre *', 'churrascoplanet-core'); ?></label>
                    <input type="text" id="chpFirstName" name="first_name" required maxlength="100"
                           style="width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;"
                           placeholder="<?php esc_attr_e('Nombre', 'churrascoplanet-core'); ?>">
                    <span class="chp-field-error" data-field="first_name" style="color:#d63031; font-size:12px; display:none;"></span>
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:5px;"><?php _e('Apellido', 'churrascoplanet-core'); ?></label>
                    <input type="text" id="chpLastName" name="last_name" maxlength="100"
                           style="width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;"
                           placeholder="<?php esc_attr_e('Apellido', 'churrascoplanet-core'); ?>">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:5px;"><?php _e('Email *', 'churrascoplanet-core'); ?></label>
                    <input type="email" id="chpEmail" name="email" required maxlength="200"
                           style="width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;"
                           placeholder="correo@ejemplo.com">
                    <span class="chp-field-error" data-field="email" style="color:#d63031; font-size:12px; display:none;"></span>
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:5px;"><?php _e('Teléfono', 'churrascoplanet-core'); ?></label>
                    <input type="text" id="chpPhone" name="phone" maxlength="30"
                           style="width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;"
                           placeholder="+56 9 1234 5678">
                    <span class="chp-field-error" data-field="phone" style="color:#d63031; font-size:12px; display:none;"></span>
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:5px;"><?php _e('WhatsApp', 'churrascoplanet-core'); ?></label>
                    <input type="text" id="chpWhatsapp" name="whatsapp" maxlength="30"
                           style="width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;"
                           placeholder="+56 9 1234 5678">
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:5px;"><?php _e('Tipo de cliente', 'churrascoplanet-core'); ?></label>
                    <select id="chpCustomerType" name="customer_type"
                            style="width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;">
                        <option value="registered"><?php _e('Registrado', 'churrascoplanet-core'); ?></option>
                        <option value="guest"><?php _e('Invitado', 'churrascoplanet-core'); ?></option>
                        <option value="social_facebook"><?php _e('Facebook', 'churrascoplanet-core'); ?></option>
                        <option value="social_google"><?php _e('Google', 'churrascoplanet-core'); ?></option>
                        <option value="social_apple"><?php _e('Apple', 'churrascoplanet-core'); ?></option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:5px;"><?php _e('Origen de registro', 'churrascoplanet-core'); ?></label>
                    <select id="chpRegSource" name="registration_source"
                            style="width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;">
                        <option value=""><?php _e('Desconocido', 'churrascoplanet-core'); ?></option>
                        <option value="woocommerce_myaccount"><?php _e('Formulario web (Mi cuenta)', 'churrascoplanet-core'); ?></option>
                        <option value="woocommerce_checkout"><?php _e('Checkout', 'churrascoplanet-core'); ?></option>
                        <option value="wordpress_registration"><?php _e('Registro WordPress', 'churrascoplanet-core'); ?></option>
                        <option value="social_facebook"><?php _e('Facebook', 'churrascoplanet-core'); ?></option>
                        <option value="social_google"><?php _e('Google', 'churrascoplanet-core'); ?></option>
                    </select>
                </div>
                <div style="grid-column:1/-1; display:flex; gap:24px; flex-wrap:wrap;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px;">
                        <input type="checkbox" id="chpAcceptsMarketing" name="accepts_marketing" value="1">
                        <?php _e('Acepta marketing por email', 'churrascoplanet-core'); ?>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px;">
                        <input type="checkbox" id="chpAcceptsWhatsapp" name="accepts_whatsapp" value="1">
                        <?php _e('Acepta WhatsApp', 'churrascoplanet-core'); ?>
                    </label>
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:5px;"><?php _e('Total pedidos', 'churrascoplanet-core'); ?></label>
                    <input type="number" id="chpTotalOrders" name="total_orders" min="0"
                           style="width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;"
                           value="0">
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:5px;"><?php _e('Total gastado ($)', 'churrascoplanet-core'); ?></label>
                    <input type="number" id="chpTotalSpent" name="total_spent" min="0" step="1"
                           style="width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;"
                           value="0">
                </div>
            </div>
            <div id="chpFormMsg" style="margin-top:14px; padding:10px 14px; border-radius:4px; display:none; font-size:14px;"></div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #eee;">
                <button type="button" class="button" onclick="chpCloseModal()"><?php _e('Cancelar', 'churrascoplanet-core'); ?></button>
                <button type="submit" id="chpSubmitBtn" class="button button-primary">
                    <span id="chpSubmitLabel"><?php _e('Guardar', 'churrascoplanet-core'); ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal confirmación eliminar -->
<div id="chpDeleteModal" style="display:none; position:fixed; inset:0; z-index:100001; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; padding:28px 32px; width:min(420px,95vw); text-align:center; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
        <div style="font-size:48px; color:#e74c3c; margin-bottom:12px;"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 style="margin:0 0 10px;"><?php _e('¿Eliminar cliente?', 'churrascoplanet-core'); ?></h3>
        <p id="chpDeleteName" style="color:#666; margin-bottom:20px;"></p>
        <div style="display:flex; justify-content:center; gap:12px;">
            <button type="button" class="button" onclick="chpCloseDeleteModal()"><?php _e('Cancelar', 'churrascoplanet-core'); ?></button>
            <button type="button" class="button button-primary" id="chpConfirmDelete" style="background:#e74c3c !important; border-color:#c0392b !important; color:#fff !important;">
                <i class="fas fa-trash"></i> <?php _e('Sí, eliminar', 'churrascoplanet-core'); ?>
            </button>
        </div>
    </div>
</div>

<script>
var chpAjaxUrl  = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
var chpNonce    = '<?php echo esc_js(wp_create_nonce('chp_customer_crud')); ?>';
var chpDeleteId = 0;

function chpOpenModal(mode, data) {
    var modal = document.getElementById('chpCustomerModal');
    document.getElementById('chpModalTitle').textContent = mode === 'add'
        ? '<?php echo esc_js(__('Agregar cliente', 'churrascoplanet-core')); ?>'
        : '<?php echo esc_js(__('Editar cliente', 'churrascoplanet-core')); ?>';
    document.getElementById('chpCustomerForm').reset();
    document.getElementById('chpFormMsg').style.display = 'none';
    document.querySelectorAll('.chp-field-error').forEach(function(el){ el.style.display = 'none'; });

    if (mode === 'edit' && data) {
        document.getElementById('chpCustomerId').value         = data.id || '';
        document.getElementById('chpFirstName').value          = data.first_name || '';
        document.getElementById('chpLastName').value           = data.last_name || '';
        document.getElementById('chpEmail').value              = data.email || '';
        document.getElementById('chpPhone').value              = data.phone || '';
        document.getElementById('chpWhatsapp').value           = data.whatsapp || '';
        document.getElementById('chpCustomerType').value       = data.customer_type || 'registered';
        document.getElementById('chpRegSource').value          = data.registration_source || '';
        document.getElementById('chpAcceptsMarketing').checked = data.accepts_marketing == '1';
        document.getElementById('chpAcceptsWhatsapp').checked  = data.accepts_whatsapp == '1';
        document.getElementById('chpTotalOrders').value        = data.total_orders || 0;
        document.getElementById('chpTotalSpent').value         = data.total_spent || 0;
    } else {
        document.getElementById('chpCustomerId').value = '';
    }
    modal.style.display = 'flex';
}

function chpCloseModal() {
    document.getElementById('chpCustomerModal').style.display = 'none';
}

function chpOpenDeleteModal(id, name) {
    chpDeleteId = id;
    document.getElementById('chpDeleteName').textContent = name;
    document.getElementById('chpDeleteModal').style.display = 'flex';
}

function chpCloseDeleteModal() {
    document.getElementById('chpDeleteModal').style.display = 'none';
    chpDeleteId = 0;
}

function chpValidateForm() {
    var valid = true;
    document.querySelectorAll('.chp-field-error').forEach(function(el){ el.style.display = 'none'; });

    var firstName = document.getElementById('chpFirstName').value.trim();
    var email     = document.getElementById('chpEmail').value.trim();
    var phone     = document.getElementById('chpPhone').value.trim();

    if (!firstName) {
        chpShowFieldError('first_name', '<?php echo esc_js(__('El nombre es obligatorio.', 'churrascoplanet-core')); ?>');
        valid = false;
    }
    if (!email) {
        chpShowFieldError('email', '<?php echo esc_js(__('El email es obligatorio.', 'churrascoplanet-core')); ?>');
        valid = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        chpShowFieldError('email', '<?php echo esc_js(__('Formato de email inválido.', 'churrascoplanet-core')); ?>');
        valid = false;
    }
    if (phone && !/^[+\d\s\-()]+$/.test(phone)) {
        chpShowFieldError('phone', '<?php echo esc_js(__('Teléfono inválido (solo números, +, espacios y guiones).', 'churrascoplanet-core')); ?>');
        valid = false;
    }
    return valid;
}

function chpShowFieldError(field, msg) {
    var el = document.querySelector('.chp-field-error[data-field="' + field + '"]');
    if (el) { el.textContent = msg; el.style.display = 'block'; }
}

document.getElementById('chpCustomerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (!chpValidateForm()) return;

    var btn = document.getElementById('chpSubmitBtn');
    var lbl = document.getElementById('chpSubmitLabel');
    btn.disabled = true;
    lbl.textContent = '<?php echo esc_js(__('Guardando...', 'churrascoplanet-core')); ?>';

    var fd = new FormData(this);
    fd.append('action', 'chp_save_customer');
    fd.append('nonce', chpNonce);

    fetch(chpAjaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            lbl.textContent = '<?php echo esc_js(__('Guardar', 'churrascoplanet-core')); ?>';
            var msgEl = document.getElementById('chpFormMsg');
            if (data.success) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#d4edda';
                msgEl.style.color = '#155724';
                msgEl.style.border = '1px solid #c3e6cb';
                msgEl.textContent = data.data.message;
                setTimeout(function(){ window.location.reload(); }, 1000);
            } else {
                msgEl.style.display = 'block';
                msgEl.style.background = '#f8d7da';
                msgEl.style.color = '#721c24';
                msgEl.style.border = '1px solid #f5c6cb';
                msgEl.textContent = data.data && data.data.message ? data.data.message : '<?php echo esc_js(__('Error al guardar.', 'churrascoplanet-core')); ?>';
            }
        })
        .catch(function() {
            btn.disabled = false;
            lbl.textContent = '<?php echo esc_js(__('Guardar', 'churrascoplanet-core')); ?>';
        });
});

document.getElementById('chpConfirmDelete').addEventListener('click', function() {
    if (!chpDeleteId) return;
    this.disabled = true;

    var fd = new FormData();
    fd.append('action', 'chp_delete_customer');
    fd.append('nonce', chpNonce);
    fd.append('customer_id', chpDeleteId);

    fetch(chpAjaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.success) {
                chpCloseDeleteModal();
                window.location.reload();
            } else {
                alert(data.data && data.data.message ? data.data.message : 'Error al eliminar.');
                document.getElementById('chpConfirmDelete').disabled = false;
            }
        });
});

document.getElementById('chpCustomerModal').addEventListener('click', function(e) {
    if (e.target === this) chpCloseModal();
});
document.getElementById('chpDeleteModal').addEventListener('click', function(e) {
    if (e.target === this) chpCloseDeleteModal();
});

function chpExportCustomers() {
    var params = new URLSearchParams(window.location.search);
    params.set('chp_export', 'customers');
    window.location.href = window.location.pathname + '?' + params.toString();
}
</script>
