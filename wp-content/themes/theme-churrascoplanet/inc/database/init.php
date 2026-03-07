<?php
/**
 * ChurrascoPlanet - Database Initialization
 * Carga todos los componentes de la base de datos
 *
 * @package ChurrascoPlanet
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// VERIFICACIÓN DE PLUGIN ACTIVO
// El tema NO debe cargar las clases de base de datos si el plugin está activo
// ============================================================================

// Método 1: Verificar constante del plugin
if (defined('CHP_CORE_VERSION')) {
    return;
}

// Método 2: Verificar si las clases del plugin ya existen
if (class_exists('ChurrascoPlanet_Database') || class_exists('ChurrascoPlanet_Model')) {
    return;
}

// Método 3: Verificar si el plugin está en la lista de plugins activos
$active_plugins = get_option('active_plugins', array());
$plugin_file = 'churrascoplanet-core/churrascoplanet-core.php';
if (in_array($plugin_file, $active_plugins)) {
    // El plugin está activo pero aún no ha cargado sus clases
    // No cargar las del tema para evitar conflictos
    return;
}

// Verificar también en multisite
if (is_multisite()) {
    $network_plugins = get_site_option('active_sitewide_plugins', array());
    if (isset($network_plugins[$plugin_file])) {
        return;
    }
}

// IMPORTANTE: Si el plugin existe pero NO esta activo, necesitamos proporcionar
// la funcion churrascoplanet_get_option como fallback para evitar errores fatales
if (!function_exists('churrascoplanet_get_option')) {
    /**
     * Funcion de compatibilidad: churrascoplanet_get_option (fallback del tema)
     * Lee opciones del formato antiguo (wp_options) cuando el plugin no esta activo
     *
     * @param string $key Clave de la opcion
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    function churrascoplanet_get_option($key, $default = '') {
        static $options = null;

        if ($options === null) {
            $options = get_option('churrascoplanet_options', array());
        }

        return isset($options[$key]) ? $options[$key] : $default;
    }
}

// Cargar archivos de base de datos (solo si el plugin no existe)
require_once __DIR__ . '/class-database.php';
require_once __DIR__ . '/class-models.php';
require_once __DIR__ . '/class-migration.php';
require_once __DIR__ . '/class-compat.php';

/**
 * AJAX: Reinstalar tablas
 */
add_action('wp_ajax_churrascoplanet_install_tables', function() {
    check_ajax_referer('churrascoplanet_migration_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    ChurrascoPlanet_Database::get_instance()->install();
    wp_send_json_success(array('message' => 'Tablas instaladas correctamente'));
});

/**
 * Verificar e instalar tablas al cargar el tema
 */
add_action('after_setup_theme', function() {
    // Solo verificar en admin y si no está en AJAX
    if (is_admin() && !wp_doing_ajax()) {
        $db = ChurrascoPlanet_Database::get_instance();
        $db->check_version();
    }
}, 5);

/**
 * Agregar información de DB al panel de admin
 */
add_action('admin_notices', function() {
    // Solo mostrar en páginas de ChurrascoPlanet
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'churrascoplanet') === false) {
        return;
    }

    // Verificar si las tablas existen
    $tables = chp_db()->check_tables();
    $missing = array_filter($tables, function($t) { return !$t['exists']; });

    if (!empty($missing)) {
        $missing_names = array_map(function($t) { return $t['table']; }, $missing);
        ?>
        <div class="notice notice-error">
            <p>
                <strong>ChurrascoPlanet:</strong>
                Faltan las siguientes tablas: <code><?php echo implode('</code>, <code>', $missing_names); ?></code>
                <a href="<?php echo admin_url('admin.php?page=churrascoplanet-migration'); ?>" class="button button-small">
                    Instalar Tablas
                </a>
            </p>
        </div>
        <?php
    }

    // Verificar si hay migración pendiente
    $migration = ChurrascoPlanet_Migration::get_instance();
    if (!$migration->is_migration_done()) {
        $old_data = get_option('churrascoplanet_options', array());
        if (!empty($old_data)) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong>ChurrascoPlanet:</strong>
                    Hay datos pendientes de migrar a las nuevas tablas.
                    <a href="<?php echo admin_url('admin.php?page=churrascoplanet-migration'); ?>" class="button button-small">
                        Ver Migración
                    </a>
                </p>
            </div>
            <?php
        }
    }
});

/**
 * Agregar columna de diagnóstico en herramientas
 */
add_filter('debug_information', function($info) {
    $tables = chp_db()->check_tables();
    $db_version = get_option(ChurrascoPlanet_Database::DB_VERSION_OPTION, 'No instalado');

    $fields = array(
        'db_version' => array(
            'label' => 'Versión de DB',
            'value' => $db_version,
        ),
    );

    foreach ($tables as $key => $table) {
        $fields["table_{$key}"] = array(
            'label' => "Tabla: {$key}",
            'value' => $table['exists'] ? "OK ({$table['count']} registros)" : 'No existe',
        );
    }

    $info['churrascoplanet-db'] = array(
        'label' => 'ChurrascoPlanet Database',
        'fields' => $fields,
    );

    return $info;
});

/**
 * Limpiar al desactivar el tema (opcional)
 */
add_action('switch_theme', function($new_name, $new_theme, $old_theme) {
    // Comentado por seguridad - descomentar solo si se quiere eliminar todo
    // if ($old_theme->get_stylesheet() === 'theme-churrascoplanet') {
    //     ChurrascoPlanet_Database::get_instance()->uninstall();
    // }
}, 10, 3);

/**
 * Exportar datos de las tablas (para backups)
 */
add_action('wp_ajax_churrascoplanet_export_data', function() {
    check_ajax_referer('churrascoplanet_migration_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    global $wpdb;

    $export = array(
        'version' => ChurrascoPlanet_Database::DB_VERSION,
        'exported_at' => current_time('mysql'),
        'tables' => array(),
    );

    $tables = chp_db()->check_tables();

    foreach ($tables as $key => $info) {
        if ($info['exists']) {
            $export['tables'][$key] = $wpdb->get_results("SELECT * FROM {$info['table']}", ARRAY_A);
        }
    }

    // Enviar como descarga JSON
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="churrascoplanet-export-' . date('Y-m-d-H-i-s') . '.json"');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
});
