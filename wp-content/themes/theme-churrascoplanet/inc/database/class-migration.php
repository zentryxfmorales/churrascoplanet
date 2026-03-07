<?php
/**
 * ChurrascoPlanet - Migration Manager
 * Migra datos de churrascoplanet_options a las nuevas tablas
 *
 * @package ChurrascoPlanet
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para migrar datos del formato antiguo (options) al nuevo (tablas)
 */
class ChurrascoPlanet_Migration {

    /**
     * Instancia única
     */
    private static $instance = null;

    /**
     * Nombre de la opción antigua
     */
    const OLD_OPTION_NAME = 'churrascoplanet_options';

    /**
     * Opción para marcar migración completada
     */
    const MIGRATION_DONE_OPTION = 'churrascoplanet_migration_v1_done';

    /**
     * Obtener instancia
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Hook para ejecutar migración después de instalar tablas
        add_action('admin_init', array($this, 'maybe_run_migration'), 20);

        // Página de administración para migración manual
        add_action('admin_menu', array($this, 'add_migration_page'), 99);

        // AJAX para migración manual
        add_action('wp_ajax_churrascoplanet_run_migration', array($this, 'ajax_run_migration'));
        add_action('wp_ajax_churrascoplanet_check_migration', array($this, 'ajax_check_migration'));
    }

    /**
     * Agregar página de migración en el admin
     */
    public function add_migration_page() {
        add_submenu_page(
            'churrascoplanet-options',
            __('Migración de Datos', 'churrascoplanet'),
            __('Migración DB', 'churrascoplanet'),
            'manage_options',
            'churrascoplanet-migration',
            array($this, 'render_migration_page')
        );
    }

    /**
     * Verificar si la migración debe ejecutarse
     */
    public function maybe_run_migration() {
        // Solo ejecutar si no se ha migrado y hay datos antiguos
        if ($this->is_migration_done()) {
            return;
        }

        $old_data = get_option(self::OLD_OPTION_NAME, array());
        if (empty($old_data)) {
            // No hay datos para migrar, marcar como completado
            update_option(self::MIGRATION_DONE_OPTION, true);
            return;
        }

        // No ejecutar automáticamente, requerir acción manual por seguridad
    }

    /**
     * Verificar si la migración está completada
     */
    public function is_migration_done() {
        return get_option(self::MIGRATION_DONE_OPTION, false);
    }

    /**
     * Ejecutar la migración completa
     *
     * @return array Resultado de la migración
     */
    public function run_migration() {
        global $wpdb;

        $result = array(
            'success' => true,
            'errors' => array(),
            'migrated' => array(),
            'skipped' => array(),
        );

        // Obtener datos antiguos
        $old_data = get_option(self::OLD_OPTION_NAME, array());

        if (empty($old_data)) {
            $result['success'] = true;
            $result['message'] = 'No hay datos para migrar';
            update_option(self::MIGRATION_DONE_OPTION, true);
            return $result;
        }

        // Iniciar transacción
        $wpdb->query('START TRANSACTION');

        try {
            // 1. Migrar configuración del Hero/Inicio
            $this->migrate_hero_config($old_data, $result);

            // 2. Migrar Info Items del Hero
            $this->migrate_info_items($old_data, $result);

            // 3. Migrar Banner Promocional
            $this->migrate_banner_config($old_data, $result);

            // 4. Migrar Navegación
            $this->migrate_navegacion($old_data, $result);

            // 5. Migrar configuración de Menú
            $this->migrate_menu_config($old_data, $result);

            // 6. Migrar opciones de Delivery
            $this->migrate_delivery_options($old_data, $result);

            // 7. Migrar configuración de Promociones
            $this->migrate_promociones_config($old_data, $result);

            // 8. Migrar Locales
            $this->migrate_locales($old_data, $result);

            // 9. Migrar Grupos de Extras
            $this->migrate_extras_grupos($old_data, $result);

            // 10. Migrar Colores
            $this->migrate_colores($old_data, $result);

            // 11. Migrar categorías personalizadas
            $this->migrate_categorias($old_data, $result);

            // Si llegamos aquí, confirmar transacción
            $wpdb->query('COMMIT');

            // Marcar migración como completada
            update_option(self::MIGRATION_DONE_OPTION, true);

            // Renombrar opción antigua como backup
            $backup_name = self::OLD_OPTION_NAME . '_backup_' . date('Y-m-d_H-i-s');
            update_option($backup_name, $old_data);

            $result['message'] = 'Migración completada exitosamente';
            $result['backup_option'] = $backup_name;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $result['success'] = false;
            $result['errors'][] = 'Error en migración: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Migrar configuración del Hero
     */
    private function migrate_hero_config($old_data, &$result) {
        $hero_fields = array(
            'inicio_hero_logo' => array('seccion' => 'hero', 'clave' => 'logo', 'tipo' => 'url'),
            'inicio_hero_badge' => array('seccion' => 'hero', 'clave' => 'badge_texto', 'tipo' => 'text'),
            'inicio_hero_badge_color' => array('seccion' => 'hero', 'clave' => 'badge_color', 'tipo' => 'color'),
            'inicio_hero_titulo' => array('seccion' => 'hero', 'clave' => 'titulo', 'tipo' => 'html'),
            'inicio_hero_titulo_color' => array('seccion' => 'hero', 'clave' => 'titulo_color', 'tipo' => 'color'),
            'inicio_hero_subtitulo' => array('seccion' => 'hero', 'clave' => 'subtitulo', 'tipo' => 'html'),
            'inicio_hero_subtitulo_color' => array('seccion' => 'hero', 'clave' => 'subtitulo_color', 'tipo' => 'color'),
            'inicio_btn_primary_text' => array('seccion' => 'hero', 'clave' => 'btn_primary_texto', 'tipo' => 'text'),
            'inicio_btn_primary_url' => array('seccion' => 'hero', 'clave' => 'btn_primary_url', 'tipo' => 'url'),
            'inicio_btn_secondary_text' => array('seccion' => 'hero', 'clave' => 'btn_secondary_texto', 'tipo' => 'text'),
            'inicio_btn_secondary_url' => array('seccion' => 'hero', 'clave' => 'btn_secondary_url', 'tipo' => 'url'),
        );

        $this->migrate_config_fields($hero_fields, $old_data, $result);
    }

    /**
     * Migrar Info Items (hero_info, delivery_opciones, etc.)
     */
    private function migrate_info_items($old_data, &$result) {
        global $wpdb;
        $table = chp_table('info_items');

        // Hero Info Items
        if (!empty($old_data['inicio_info_items']) && is_array($old_data['inicio_info_items'])) {
            foreach ($old_data['inicio_info_items'] as $orden => $item) {
                $inserted = $wpdb->insert($table, array(
                    'seccion' => 'hero_info',
                    'tipo' => 'feature',
                    'icono' => sanitize_text_field($item['icon'] ?? 'fas fa-star'),
                    'texto' => sanitize_text_field($item['text'] ?? ''),
                    'orden' => (int) $orden,
                    'activo' => 1,
                ), array('%s', '%s', '%s', '%s', '%d', '%d'));

                if ($inserted) {
                    $result['migrated'][] = "info_item_hero_{$orden}";
                } else {
                    $result['errors'][] = "Error migrando info_item_hero_{$orden}";
                }
            }
        }
    }

    /**
     * Migrar Banner Promocional
     */
    private function migrate_banner_config($old_data, &$result) {
        $banner_fields = array(
            'inicio_banner_image' => array('seccion' => 'banner', 'clave' => 'imagen', 'tipo' => 'url'),
            'inicio_banner_tag' => array('seccion' => 'banner', 'clave' => 'etiqueta', 'tipo' => 'text'),
            'inicio_banner_title' => array('seccion' => 'banner', 'clave' => 'titulo', 'tipo' => 'text'),
            'inicio_banner_subtitle' => array('seccion' => 'banner', 'clave' => 'subtitulo', 'tipo' => 'text'),
            'inicio_banner_url' => array('seccion' => 'banner', 'clave' => 'url', 'tipo' => 'url'),
            'inicio_banner_btn' => array('seccion' => 'banner', 'clave' => 'btn_texto', 'tipo' => 'text'),
        );

        $this->migrate_config_fields($banner_fields, $old_data, $result);
    }

    /**
     * Migrar Navegación
     */
    private function migrate_navegacion($old_data, &$result) {
        global $wpdb;
        $table = chp_table('navegacion');

        if (!empty($old_data['nav_menu_items']) && is_array($old_data['nav_menu_items'])) {
            foreach ($old_data['nav_menu_items'] as $orden => $item) {
                $inserted = $wpdb->insert($table, array(
                    'texto' => sanitize_text_field($item['texto'] ?? 'Item'),
                    'url' => esc_url_raw($item['url'] ?? '#'),
                    'icono' => sanitize_text_field($item['icon'] ?? ''),
                    'target' => ($item['target'] ?? '') === '_blank' ? '_blank' : '_self',
                    'visible' => ($item['visible'] ?? '1') === '1' ? 1 : 0,
                    'orden' => (int) $orden,
                ), array('%s', '%s', '%s', '%s', '%d', '%d'));

                if ($inserted) {
                    $result['migrated'][] = "navegacion_{$orden}";
                } else {
                    $result['errors'][] = "Error migrando navegacion_{$orden}";
                }
            }
        }
    }

    /**
     * Migrar configuración de Menú
     */
    private function migrate_menu_config($old_data, &$result) {
        $menu_fields = array(
            'menu_badge_icon' => array('seccion' => 'menu', 'clave' => 'badge_icono', 'tipo' => 'text'),
            'menu_badge_text' => array('seccion' => 'menu', 'clave' => 'badge_texto', 'tipo' => 'text'),
            'menu_titulo' => array('seccion' => 'menu', 'clave' => 'titulo', 'tipo' => 'html'),
            'menu_titulo_color' => array('seccion' => 'menu', 'clave' => 'titulo_color', 'tipo' => 'color'),
            'menu_subtitulo' => array('seccion' => 'menu', 'clave' => 'subtitulo', 'tipo' => 'html'),
            'menu_subtitulo_color' => array('seccion' => 'menu', 'clave' => 'subtitulo_color', 'tipo' => 'color'),
            'delivery_titulo_icon' => array('seccion' => 'delivery', 'clave' => 'titulo_icono', 'tipo' => 'text'),
            'delivery_titulo' => array('seccion' => 'delivery', 'clave' => 'titulo', 'tipo' => 'text'),
            'delivery_parrafo' => array('seccion' => 'delivery', 'clave' => 'parrafo', 'tipo' => 'html'),
        );

        $this->migrate_config_fields($menu_fields, $old_data, $result);
    }

    /**
     * Migrar opciones de Delivery
     */
    private function migrate_delivery_options($old_data, &$result) {
        global $wpdb;
        $table = chp_table('info_items');

        if (!empty($old_data['delivery_opciones']) && is_array($old_data['delivery_opciones'])) {
            foreach ($old_data['delivery_opciones'] as $orden => $item) {
                $inserted = $wpdb->insert($table, array(
                    'seccion' => 'delivery_opciones',
                    'tipo' => 'option',
                    'icono' => sanitize_text_field($item['icon'] ?? 'fas fa-box'),
                    'texto' => sanitize_text_field($item['text'] ?? ''),
                    'orden' => (int) $orden,
                    'activo' => 1,
                ), array('%s', '%s', '%s', '%s', '%d', '%d'));

                if ($inserted) {
                    $result['migrated'][] = "delivery_opcion_{$orden}";
                } else {
                    $result['errors'][] = "Error migrando delivery_opcion_{$orden}";
                }
            }
        }
    }

    /**
     * Migrar configuración de Promociones
     */
    private function migrate_promociones_config($old_data, &$result) {
        $promo_fields = array(
            'promo_badge_icon' => array('seccion' => 'promociones', 'clave' => 'badge_icono', 'tipo' => 'text'),
            'promo_badge_text' => array('seccion' => 'promociones', 'clave' => 'badge_texto', 'tipo' => 'text'),
            'promo_titulo' => array('seccion' => 'promociones', 'clave' => 'titulo', 'tipo' => 'html'),
            'promo_titulo_color' => array('seccion' => 'promociones', 'clave' => 'titulo_color', 'tipo' => 'color'),
            'promo_subtitulo' => array('seccion' => 'promociones', 'clave' => 'subtitulo', 'tipo' => 'html'),
            'promo_subtitulo_color' => array('seccion' => 'promociones', 'clave' => 'subtitulo_color', 'tipo' => 'color'),
        );

        $this->migrate_config_fields($promo_fields, $old_data, $result);
    }

    /**
     * Migrar Locales
     */
    private function migrate_locales($old_data, &$result) {
        global $wpdb;
        $table = chp_table('locales');

        // Migrar encabezado de locales a configuración
        $locales_header = array(
            'locales_badge_icon' => array('seccion' => 'locales', 'clave' => 'badge_icono', 'tipo' => 'text'),
            'locales_badge_text' => array('seccion' => 'locales', 'clave' => 'badge_texto', 'tipo' => 'text'),
            'locales_titulo' => array('seccion' => 'locales', 'clave' => 'titulo', 'tipo' => 'html'),
            'locales_titulo_color' => array('seccion' => 'locales', 'clave' => 'titulo_color', 'tipo' => 'color'),
            'locales_subtitulo' => array('seccion' => 'locales', 'clave' => 'subtitulo', 'tipo' => 'html'),
            'locales_subtitulo_color' => array('seccion' => 'locales', 'clave' => 'subtitulo_color', 'tipo' => 'color'),
        );
        $this->migrate_config_fields($locales_header, $old_data, $result);

        // Migrar lista de locales
        if (!empty($old_data['locales_lista']) && is_array($old_data['locales_lista'])) {
            foreach ($old_data['locales_lista'] as $orden => $local) {
                $inserted = $wpdb->insert($table, array(
                    'nombre' => sanitize_text_field($local['nombre'] ?? 'Local'),
                    'subtitulo' => sanitize_text_field($local['subtitulo'] ?? ''),
                    'whatsapp' => sanitize_text_field($local['whatsapp'] ?? ''),
                    'icono' => sanitize_text_field($local['icon'] ?? 'fas fa-satellite'),
                    'activo' => 1,
                    'orden' => (int) $orden,
                ), array('%s', '%s', '%s', '%s', '%d', '%d'));

                if ($inserted) {
                    $result['migrated'][] = "local_{$orden}";
                } else {
                    $result['errors'][] = "Error migrando local_{$orden}";
                }
            }
        }
    }

    /**
     * Migrar Grupos de Extras e Items
     */
    private function migrate_extras_grupos($old_data, &$result) {
        global $wpdb;
        $table_grupos = chp_table('extras_grupos');
        $table_items = chp_table('extras_items');

        if (!empty($old_data['extras_grupos']) && is_array($old_data['extras_grupos'])) {
            foreach ($old_data['extras_grupos'] as $g_orden => $grupo) {
                // Crear slug único
                $nombre = sanitize_text_field($grupo['nombre'] ?? 'Grupo');
                $slug = sanitize_title($nombre);

                // Verificar unicidad del slug
                $slug_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_grupos} WHERE slug = %s",
                    $slug
                ));
                if ($slug_exists) {
                    $slug = $slug . '-' . ($g_orden + 1);
                }

                // Insertar grupo
                $inserted = $wpdb->insert($table_grupos, array(
                    'nombre' => $nombre,
                    'slug' => $slug,
                    'instruccion' => sanitize_text_field($grupo['instruccion'] ?? ''),
                    'requerido' => ($grupo['requerido'] ?? '0') === '1' ? 1 : 0,
                    'tipo_seleccion' => ($grupo['tipo_seleccion'] ?? 'checkbox'),
                    'min_selecciones' => (int) ($grupo['min'] ?? 0),
                    'max_selecciones' => (int) ($grupo['max'] ?? 0),
                    'activo' => 1,
                    'orden' => (int) $g_orden,
                ), array('%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d'));

                if ($inserted) {
                    $grupo_id = $wpdb->insert_id;
                    $result['migrated'][] = "extras_grupo_{$g_orden}";

                    // Migrar items del grupo
                    if (!empty($grupo['items']) && is_array($grupo['items'])) {
                        foreach ($grupo['items'] as $i_orden => $item) {
                            $item_inserted = $wpdb->insert($table_items, array(
                                'grupo_id' => $grupo_id,
                                'nombre' => sanitize_text_field($item['nombre'] ?? 'Item'),
                                'precio' => (int) ($item['precio'] ?? 0),
                                'activo' => 1,
                                'orden' => (int) $i_orden,
                            ), array('%d', '%s', '%d', '%d', '%d'));

                            if ($item_inserted) {
                                $result['migrated'][] = "extras_item_{$g_orden}_{$i_orden}";
                            } else {
                                $result['errors'][] = "Error migrando extras_item_{$g_orden}_{$i_orden}";
                            }
                        }
                    }

                    // Guardar mapeo antiguo -> nuevo ID para migrar producto_extras
                    $this->old_to_new_grupo_map[$g_orden] = $grupo_id;
                } else {
                    $result['errors'][] = "Error migrando extras_grupo_{$g_orden}";
                }
            }

            // Migrar relaciones producto-extras desde postmeta
            $this->migrate_producto_extras($result);
        }
    }

    /**
     * Mapeo de índices antiguos a IDs nuevos de grupos
     */
    private $old_to_new_grupo_map = array();

    /**
     * Migrar relaciones producto-extras desde postmeta
     */
    private function migrate_producto_extras(&$result) {
        global $wpdb;
        $table = chp_table('producto_extras');

        // Obtener todos los productos con extras asignados
        $products_with_extras = $wpdb->get_results(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_churrascoplanet_extras_groups'"
        );

        if ($products_with_extras) {
            foreach ($products_with_extras as $row) {
                $product_id = (int) $row->post_id;
                $old_groups = maybe_unserialize($row->meta_value);

                if (is_array($old_groups)) {
                    foreach ($old_groups as $orden => $old_index) {
                        // Buscar el nuevo ID del grupo
                        $old_index = (int) $old_index;

                        if (isset($this->old_to_new_grupo_map[$old_index])) {
                            $new_grupo_id = $this->old_to_new_grupo_map[$old_index];

                            // Verificar que no exista ya
                            $exists = $wpdb->get_var($wpdb->prepare(
                                "SELECT id FROM {$table} WHERE product_id = %d AND grupo_id = %d",
                                $product_id,
                                $new_grupo_id
                            ));

                            if (!$exists) {
                                $inserted = $wpdb->insert($table, array(
                                    'product_id' => $product_id,
                                    'grupo_id' => $new_grupo_id,
                                    'orden' => (int) $orden,
                                ), array('%d', '%d', '%d'));

                                if ($inserted) {
                                    $result['migrated'][] = "producto_extra_{$product_id}_{$new_grupo_id}";
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Migrar Colores
     */
    private function migrate_colores($old_data, &$result) {
        $color_fields = array(
            'color_primary' => array('seccion' => 'colores', 'clave' => 'primary', 'tipo' => 'color'),
            'color_secondary' => array('seccion' => 'colores', 'clave' => 'secondary', 'tipo' => 'color'),
            'color_background' => array('seccion' => 'colores', 'clave' => 'background', 'tipo' => 'color'),
            'color_text' => array('seccion' => 'colores', 'clave' => 'text', 'tipo' => 'color'),
            'color_cards' => array('seccion' => 'colores', 'clave' => 'cards', 'tipo' => 'color'),
            'color_borders' => array('seccion' => 'colores', 'clave' => 'borders', 'tipo' => 'color'),
            'color_titulos' => array('seccion' => 'colores', 'clave' => 'titulos', 'tipo' => 'color'),
            'color_subtitulos' => array('seccion' => 'colores', 'clave' => 'subtitulos', 'tipo' => 'color'),
            'color_parrafos' => array('seccion' => 'colores', 'clave' => 'parrafos', 'tipo' => 'color'),
            'color_highlight' => array('seccion' => 'colores', 'clave' => 'highlight', 'tipo' => 'color'),
        );

        $this->migrate_config_fields($color_fields, $old_data, $result);
    }

    /**
     * Migrar categorías personalizadas
     */
    private function migrate_categorias($old_data, &$result) {
        global $wpdb;
        $table = chp_table('info_items');

        if (!empty($old_data['inicio_categorias']) && is_array($old_data['inicio_categorias'])) {
            foreach ($old_data['inicio_categorias'] as $orden => $cat) {
                $datos_extra = json_encode(array(
                    'slug' => $cat['slug'] ?? '',
                    'custom_name' => $cat['custom_name'] ?? '',
                    'custom_desc' => $cat['custom_desc'] ?? '',
                ));

                $inserted = $wpdb->insert($table, array(
                    'seccion' => 'inicio_categorias',
                    'tipo' => 'category',
                    'icono' => sanitize_text_field($cat['icon'] ?? 'fas fa-star'),
                    'texto' => sanitize_text_field($cat['slug'] ?? ''),
                    'texto_secundario' => sanitize_text_field($cat['custom_name'] ?? ''),
                    'datos_extra' => $datos_extra,
                    'orden' => (int) $orden,
                    'activo' => 1,
                ), array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d'));

                if ($inserted) {
                    $result['migrated'][] = "categoria_{$orden}";
                } else {
                    $result['errors'][] = "Error migrando categoria_{$orden}";
                }
            }
        }
    }

    /**
     * Helper: Migrar campos de configuración
     */
    private function migrate_config_fields($fields, $old_data, &$result) {
        global $wpdb;
        $table = chp_table('configuracion');

        foreach ($fields as $old_key => $config) {
            if (isset($old_data[$old_key]) && $old_data[$old_key] !== '') {
                $valor = $old_data[$old_key];

                // Sanitizar según tipo
                switch ($config['tipo']) {
                    case 'html':
                        $valor = wp_kses_post($valor);
                        break;
                    case 'url':
                        $valor = esc_url_raw($valor);
                        break;
                    case 'color':
                        // Mantener como está (puede incluir rgba)
                        $valor = sanitize_text_field($valor);
                        break;
                    case 'number':
                        $valor = (string) intval($valor);
                        break;
                    default:
                        $valor = sanitize_text_field($valor);
                }

                // Usar REPLACE INTO para evitar duplicados
                $inserted = $wpdb->replace($table, array(
                    'seccion' => $config['seccion'],
                    'clave' => $config['clave'],
                    'valor' => $valor,
                    'tipo' => $config['tipo'],
                ), array('%s', '%s', '%s', '%s'));

                if ($inserted !== false) {
                    $result['migrated'][] = "{$config['seccion']}.{$config['clave']}";
                } else {
                    $result['errors'][] = "Error migrando {$old_key}";
                }
            } else {
                $result['skipped'][] = $old_key;
            }
        }
    }

    /**
     * AJAX: Ejecutar migración
     */
    public function ajax_run_migration() {
        check_ajax_referer('churrascoplanet_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $result = $this->run_migration();
        wp_send_json($result);
    }

    /**
     * AJAX: Verificar estado de migración
     */
    public function ajax_check_migration() {
        check_ajax_referer('churrascoplanet_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $status = array(
            'is_done' => $this->is_migration_done(),
            'has_old_data' => !empty(get_option(self::OLD_OPTION_NAME, array())),
            'tables' => chp_db()->check_tables(),
            'db_version' => get_option(ChurrascoPlanet_Database::DB_VERSION_OPTION, 'No instalado'),
        );

        wp_send_json_success($status);
    }

    /**
     * Renderizar página de migración
     */
    public function render_migration_page() {
        $is_done = $this->is_migration_done();
        $has_old_data = !empty(get_option(self::OLD_OPTION_NAME, array()));
        $tables_status = chp_db()->check_tables();
        $db_version = get_option(ChurrascoPlanet_Database::DB_VERSION_OPTION, 'No instalado');
        ?>
        <div class="wrap">
            <h1><i class="fas fa-database"></i> Migración de Base de Datos - ChurrascoPlanet</h1>

            <div class="notice notice-info">
                <p><strong>Versión del esquema:</strong> <?php echo esc_html($db_version); ?></p>
            </div>

            <!-- Estado de las tablas -->
            <h2>Estado de las Tablas</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Tabla</th>
                        <th>Estado</th>
                        <th>Registros</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tables_status as $key => $info) : ?>
                    <tr>
                        <td><code><?php echo esc_html($info['table']); ?></code></td>
                        <td>
                            <?php if ($info['exists']) : ?>
                                <span style="color: green;"><i class="fas fa-check-circle"></i> Existe</span>
                            <?php else : ?>
                                <span style="color: red;"><i class="fas fa-times-circle"></i> No existe</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($info['count']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <hr>

            <!-- Estado de la migración -->
            <h2>Migración de Datos</h2>

            <?php if ($is_done) : ?>
                <div class="notice notice-success">
                    <p><i class="fas fa-check"></i> <strong>La migración ya fue completada.</strong></p>
                </div>
            <?php elseif (!$has_old_data) : ?>
                <div class="notice notice-warning">
                    <p><i class="fas fa-info-circle"></i> No hay datos antiguos para migrar.</p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning">
                    <p><i class="fas fa-exclamation-triangle"></i> <strong>Hay datos pendientes de migrar.</strong></p>
                    <p>Se encontraron datos en la opción <code>churrascoplanet_options</code> que deben migrarse a las nuevas tablas.</p>
                </div>

                <p>
                    <button type="button" id="run-migration-btn" class="button button-primary button-hero">
                        <i class="fas fa-play"></i> Ejecutar Migración
                    </button>
                </p>

                <div id="migration-result" style="display: none; margin-top: 20px;"></div>
            <?php endif; ?>

            <hr>

            <!-- Acciones de mantenimiento -->
            <h2>Mantenimiento</h2>
            <p>
                <button type="button" id="reinstall-tables-btn" class="button">
                    <i class="fas fa-sync"></i> Reinstalar Tablas
                </button>
                <span class="description">Verifica y crea las tablas si no existen.</span>
            </p>

            <script>
            jQuery(document).ready(function($) {
                var nonce = '<?php echo wp_create_nonce('churrascoplanet_migration_nonce'); ?>';

                $('#run-migration-btn').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#migration-result');

                    if (!confirm('¿Estás seguro de ejecutar la migración? Se creará un backup de los datos antiguos.')) {
                        return;
                    }

                    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Migrando...');
                    $result.hide();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'churrascoplanet_run_migration',
                            nonce: nonce
                        },
                        success: function(response) {
                            $result.show();

                            if (response.success) {
                                $result.html(
                                    '<div class="notice notice-success"><p><i class="fas fa-check"></i> ' + response.message + '</p>' +
                                    '<p><strong>Elementos migrados:</strong> ' + response.migrated.length + '</p>' +
                                    '<p><strong>Backup guardado en:</strong> <code>' + response.backup_option + '</code></p></div>'
                                );
                                location.reload();
                            } else {
                                $result.html(
                                    '<div class="notice notice-error"><p><i class="fas fa-times"></i> Error en la migración</p>' +
                                    '<pre>' + JSON.stringify(response.errors, null, 2) + '</pre></div>'
                                );
                            }
                        },
                        error: function() {
                            $result.show().html('<div class="notice notice-error"><p>Error de conexión</p></div>');
                        },
                        complete: function() {
                            $btn.prop('disabled', false).html('<i class="fas fa-play"></i> Ejecutar Migración');
                        }
                    });
                });

                $('#reinstall-tables-btn').on('click', function() {
                    var $btn = $(this);

                    if (!confirm('¿Reinstalar las tablas? Los datos existentes se mantendrán.')) {
                        return;
                    }

                    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Instalando...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'churrascoplanet_install_tables',
                            nonce: nonce
                        },
                        success: function() {
                            alert('Tablas reinstaladas correctamente');
                            location.reload();
                        },
                        error: function() {
                            alert('Error al reinstalar tablas');
                        },
                        complete: function() {
                            $btn.prop('disabled', false).html('<i class="fas fa-sync"></i> Reinstalar Tablas');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
}

// Inicializar
ChurrascoPlanet_Migration::get_instance();
