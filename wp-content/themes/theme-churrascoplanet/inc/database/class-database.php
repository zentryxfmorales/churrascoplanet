<?php
/**
 * ChurrascoPlanet - Database Manager
 * Gestión de tablas personalizadas con prefijo cp_chp_
 *
 * @package ChurrascoPlanet
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase principal para gestionar la base de datos de ChurrascoPlanet
 */
class ChurrascoPlanet_Database {

    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;

    /**
     * Prefijo de las tablas personalizadas
     */
    private $prefix;

    /**
     * Prefijo de WordPress
     */
    private $wp_prefix;

    /**
     * Versión del esquema de base de datos
     */
    const DB_VERSION = '1.0.0';

    /**
     * Opción para guardar la versión instalada
     */
    const DB_VERSION_OPTION = 'churrascoplanet_db_version';

    /**
     * Nombres de las tablas (sin prefijo)
     */
    private $table_names = array(
        'configuracion'     => 'chp_configuracion',
        'navegacion'        => 'chp_navegacion',
        'locales'           => 'chp_locales',
        'extras_grupos'     => 'chp_extras_grupos',
        'extras_items'      => 'chp_extras_items',
        'producto_extras'   => 'chp_producto_extras',
        'pedido_extras'     => 'chp_pedido_extras',
        'info_items'        => 'chp_info_items',
    );

    /**
     * Obtener instancia única
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
        global $wpdb;
        $this->wp_prefix = $wpdb->prefix;
        $this->prefix = $wpdb->prefix; // cp_chp_ será cp_ + chp_

        // Registrar tablas en $wpdb
        $this->register_tables();

        // Hook de activación del tema
        add_action('after_switch_theme', array($this, 'install'));

        // Hook para verificar actualizaciones
        add_action('admin_init', array($this, 'check_version'));
    }

    /**
     * Registrar tablas en $wpdb para fácil acceso
     */
    private function register_tables() {
        global $wpdb;

        foreach ($this->table_names as $key => $name) {
            $wpdb->{'chp_' . $key} = $this->prefix . $name;
        }
    }

    /**
     * Obtener nombre completo de una tabla
     *
     * @param string $table Nombre corto de la tabla (configuracion, locales, etc.)
     * @return string Nombre completo con prefijo
     */
    public function get_table_name($table) {
        global $wpdb;
        if (isset($this->table_names[$table])) {
            return $this->prefix . $this->table_names[$table];
        }
        return $this->prefix . 'chp_' . $table;
    }

    /**
     * Alias corto para get_table_name
     */
    public function table($table) {
        return $this->get_table_name($table);
    }

    /**
     * Verificar si la versión de DB necesita actualización
     */
    public function check_version() {
        $installed_version = get_option(self::DB_VERSION_OPTION, '0.0.0');

        if (version_compare($installed_version, self::DB_VERSION, '<')) {
            $this->install();
        }
    }

    /**
     * Instalar/actualizar las tablas
     */
    public function install() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Requerido para dbDelta
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Crear todas las tablas
        $this->create_table_configuracion($charset_collate);
        $this->create_table_navegacion($charset_collate);
        $this->create_table_locales($charset_collate);
        $this->create_table_extras_grupos($charset_collate);
        $this->create_table_extras_items($charset_collate);
        $this->create_table_producto_extras($charset_collate);
        $this->create_table_pedido_extras($charset_collate);
        $this->create_table_info_items($charset_collate);

        // Crear las foreign keys (después de que todas las tablas existan)
        $this->create_foreign_keys();

        // Actualizar versión
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

        // Log de instalación
        error_log('ChurrascoPlanet DB: Tablas instaladas/actualizadas a versión ' . self::DB_VERSION);
    }

    /**
     * Tabla: Configuración general por secciones
     */
    private function create_table_configuracion($charset_collate) {
        global $wpdb;
        $table = $this->table('configuracion');

        $sql = "CREATE TABLE {$table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            seccion VARCHAR(50) NOT NULL,
            clave VARCHAR(100) NOT NULL,
            valor LONGTEXT,
            tipo ENUM('text', 'html', 'color', 'url', 'json', 'number', 'boolean') DEFAULT 'text',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY seccion_clave (seccion, clave),
            KEY idx_seccion (seccion)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Tabla: Items de navegación del menú principal
     */
    private function create_table_navegacion($charset_collate) {
        global $wpdb;
        $table = $this->table('navegacion');

        $sql = "CREATE TABLE {$table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            texto VARCHAR(100) NOT NULL,
            url VARCHAR(255) NOT NULL,
            icono VARCHAR(50) DEFAULT NULL,
            target VARCHAR(10) DEFAULT '_self',
            visible TINYINT(1) DEFAULT 1,
            orden INT UNSIGNED DEFAULT 0,
            parent_id INT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_orden (orden),
            KEY idx_visible (visible),
            KEY idx_parent (parent_id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Tabla: Locales/sucursales
     */
    private function create_table_locales($charset_collate) {
        global $wpdb;
        $table = $this->table('locales');

        $sql = "CREATE TABLE {$table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(100) NOT NULL,
            subtitulo VARCHAR(150) DEFAULT NULL,
            direccion VARCHAR(255) DEFAULT NULL,
            comuna VARCHAR(100) DEFAULT NULL,
            ciudad VARCHAR(100) DEFAULT 'Santiago',
            telefono VARCHAR(20) DEFAULT NULL,
            whatsapp VARCHAR(20) DEFAULT NULL,
            email VARCHAR(100) DEFAULT NULL,
            icono VARCHAR(50) DEFAULT 'fas fa-satellite',
            imagen_url VARCHAR(255) DEFAULT NULL,
            horario_lunes_viernes VARCHAR(50) DEFAULT NULL,
            horario_sabado VARCHAR(50) DEFAULT NULL,
            horario_domingo VARCHAR(50) DEFAULT NULL,
            latitud DECIMAL(10, 8) DEFAULT NULL,
            longitud DECIMAL(11, 8) DEFAULT NULL,
            radio_delivery_km DECIMAL(5,2) DEFAULT 4.00,
            activo TINYINT(1) DEFAULT 1,
            orden INT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_activo (activo),
            KEY idx_orden (orden),
            KEY idx_comuna (comuna)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Tabla: Grupos de extras/adicionales
     */
    private function create_table_extras_grupos($charset_collate) {
        global $wpdb;
        $table = $this->table('extras_grupos');

        $sql = "CREATE TABLE {$table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(100) NOT NULL,
            slug VARCHAR(100) DEFAULT NULL,
            instruccion VARCHAR(255) DEFAULT NULL,
            requerido TINYINT(1) DEFAULT 0,
            tipo_seleccion VARCHAR(20) DEFAULT 'checkbox',
            min_selecciones INT UNSIGNED DEFAULT 0,
            max_selecciones INT UNSIGNED DEFAULT 0,
            activo TINYINT(1) DEFAULT 1,
            orden INT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_slug (slug),
            KEY idx_activo (activo),
            KEY idx_orden (orden)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Tabla: Items de extras (opciones dentro de cada grupo)
     */
    private function create_table_extras_items($charset_collate) {
        global $wpdb;
        $table = $this->table('extras_items');

        $sql = "CREATE TABLE {$table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            grupo_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            descripcion VARCHAR(255) DEFAULT NULL,
            precio INT DEFAULT 0,
            precio_original INT DEFAULT NULL,
            sku VARCHAR(50) DEFAULT NULL,
            imagen_url VARCHAR(255) DEFAULT NULL,
            activo TINYINT(1) DEFAULT 1,
            orden INT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_grupo (grupo_id),
            KEY idx_activo (activo),
            KEY idx_orden (orden),
            KEY idx_sku (sku)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Tabla: Relación Producto-Extras (muchos a muchos)
     * Relaciona productos de WooCommerce con grupos de extras
     */
    private function create_table_producto_extras($charset_collate) {
        global $wpdb;
        $table = $this->table('producto_extras');

        // Nota: product_id referencia a cp_posts.ID (productos WooCommerce)
        $sql = "CREATE TABLE {$table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            grupo_id INT UNSIGNED NOT NULL,
            orden INT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY producto_grupo (product_id, grupo_id),
            KEY idx_product (product_id),
            KEY idx_grupo (grupo_id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Tabla: Histórico de extras en pedidos
     * Guarda los extras seleccionados en cada orden para reportes y auditoría
     */
    private function create_table_pedido_extras($charset_collate) {
        global $wpdb;
        $table = $this->table('pedido_extras');

        // Relaciones:
        // - order_id: cp_wc_orders.id (HPOS) o cp_posts.ID (legacy)
        // - order_item_id: cp_woocommerce_order_items.order_item_id
        // - product_id: cp_posts.ID
        // - grupo_id: cp_chp_extras_grupos.id
        // - item_id: cp_chp_extras_items.id

        $sql = "CREATE TABLE {$table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            order_item_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            grupo_id INT UNSIGNED DEFAULT NULL,
            grupo_nombre VARCHAR(100) NOT NULL,
            item_id INT UNSIGNED DEFAULT NULL,
            item_nombre VARCHAR(100) NOT NULL,
            item_precio INT DEFAULT 0,
            cantidad INT UNSIGNED DEFAULT 1,
            subtotal INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order (order_id),
            KEY idx_order_item (order_item_id),
            KEY idx_product (product_id),
            KEY idx_grupo (grupo_id),
            KEY idx_item (item_id),
            KEY idx_created (created_at)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Tabla: Info items genéricos (hero info, delivery options, etc.)
     */
    private function create_table_info_items($charset_collate) {
        global $wpdb;
        $table = $this->table('info_items');

        $sql = "CREATE TABLE {$table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            seccion VARCHAR(50) NOT NULL,
            tipo VARCHAR(30) DEFAULT 'item',
            icono VARCHAR(50) DEFAULT 'fas fa-star',
            texto VARCHAR(255) NOT NULL,
            texto_secundario VARCHAR(255) DEFAULT NULL,
            url VARCHAR(255) DEFAULT NULL,
            imagen_url VARCHAR(255) DEFAULT NULL,
            color VARCHAR(20) DEFAULT NULL,
            datos_extra LONGTEXT DEFAULT NULL,
            orden INT UNSIGNED DEFAULT 0,
            activo TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_seccion (seccion),
            KEY idx_tipo (tipo),
            KEY idx_orden (orden),
            KEY idx_activo (activo)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Crear Foreign Keys
     * Nota: dbDelta no soporta FK, se crean manualmente
     */
    private function create_foreign_keys() {
        global $wpdb;

        // Desactivar verificación de FK temporalmente
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

        // FK: extras_items.grupo_id -> extras_grupos.id
        $this->add_foreign_key(
            'extras_items',
            'fk_extras_items_grupo',
            'grupo_id',
            'extras_grupos',
            'id',
            'CASCADE'
        );

        // FK: producto_extras.grupo_id -> extras_grupos.id
        $this->add_foreign_key(
            'producto_extras',
            'fk_producto_extras_grupo',
            'grupo_id',
            'extras_grupos',
            'id',
            'CASCADE'
        );

        // FK: producto_extras.product_id -> cp_posts.ID (productos WooCommerce)
        $this->add_foreign_key(
            'producto_extras',
            'fk_producto_extras_product',
            'product_id',
            'posts', // Sin prefijo chp_, es tabla de WordPress
            'ID',
            'CASCADE',
            false // Indicar que es tabla de WordPress
        );

        // FK: navegacion.parent_id -> navegacion.id (auto-referencia)
        $this->add_foreign_key(
            'navegacion',
            'fk_navegacion_parent',
            'parent_id',
            'navegacion',
            'id',
            'SET NULL'
        );

        // Reactivar verificación de FK
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Agregar una Foreign Key si no existe
     *
     * @param string $table Tabla origen
     * @param string $fk_name Nombre de la FK
     * @param string $column Columna origen
     * @param string $ref_table Tabla referenciada
     * @param string $ref_column Columna referenciada
     * @param string $on_delete Acción ON DELETE
     * @param bool $is_chp_table Si la tabla referenciada es del proyecto (true) o de WordPress (false)
     */
    private function add_foreign_key($table, $fk_name, $column, $ref_table, $ref_column, $on_delete = 'CASCADE', $is_chp_table = true) {
        global $wpdb;

        $full_table = $this->table($table);
        $full_ref_table = $is_chp_table ? $this->table($ref_table) : $this->wp_prefix . $ref_table;

        // Verificar si la FK ya existe
        $fk_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
             AND TABLE_NAME = %s
             AND CONSTRAINT_NAME = %s
             AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            $full_table,
            $fk_name
        ));

        if (!$fk_exists) {
            $sql = "ALTER TABLE {$full_table}
                    ADD CONSTRAINT {$fk_name}
                    FOREIGN KEY ({$column})
                    REFERENCES {$full_ref_table}({$ref_column})
                    ON DELETE {$on_delete}";

            $result = $wpdb->query($sql);

            if ($result === false) {
                error_log("ChurrascoPlanet DB: Error creando FK {$fk_name} - " . $wpdb->last_error);
            }
        }
    }

    /**
     * Verificar si las tablas existen
     *
     * @return array Estado de cada tabla
     */
    public function check_tables() {
        global $wpdb;
        $status = array();

        foreach ($this->table_names as $key => $name) {
            $table = $this->prefix . $name;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
            $count = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : 0;

            $status[$key] = array(
                'table'  => $table,
                'exists' => $exists,
                'count'  => (int) $count,
            );
        }

        return $status;
    }

    /**
     * Eliminar todas las tablas (usar con precaución)
     */
    public function uninstall() {
        global $wpdb;

        // Desactivar FK checks
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

        // Eliminar en orden inverso (por dependencias)
        $tables_order = array(
            'pedido_extras',
            'producto_extras',
            'extras_items',
            'extras_grupos',
            'info_items',
            'navegacion',
            'locales',
            'configuracion',
        );

        foreach ($tables_order as $table) {
            $full_table = $this->table($table);
            $wpdb->query("DROP TABLE IF EXISTS {$full_table}");
        }

        // Reactivar FK checks
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

        // Eliminar versión
        delete_option(self::DB_VERSION_OPTION);

        error_log('ChurrascoPlanet DB: Todas las tablas eliminadas');
    }

    /**
     * Obtener información de las relaciones con tablas WP/WC
     */
    public function get_relationships_info() {
        return array(
            'producto_extras' => array(
                'product_id' => array(
                    'table' => $this->wp_prefix . 'posts',
                    'column' => 'ID',
                    'condition' => "post_type = 'product'",
                    'description' => 'Productos de WooCommerce',
                ),
            ),
            'pedido_extras' => array(
                'order_id' => array(
                    'table' => $this->wp_prefix . 'wc_orders',
                    'column' => 'id',
                    'fallback_table' => $this->wp_prefix . 'posts',
                    'fallback_condition' => "post_type = 'shop_order'",
                    'description' => 'Órdenes de WooCommerce (HPOS o Legacy)',
                ),
                'order_item_id' => array(
                    'table' => $this->wp_prefix . 'woocommerce_order_items',
                    'column' => 'order_item_id',
                    'description' => 'Items de la orden',
                ),
                'product_id' => array(
                    'table' => $this->wp_prefix . 'posts',
                    'column' => 'ID',
                    'condition' => "post_type = 'product'",
                    'description' => 'Producto ordenado',
                ),
            ),
        );
    }
}

// Inicializar
ChurrascoPlanet_Database::get_instance();

/**
 * Función helper para obtener la instancia de la base de datos
 *
 * @return ChurrascoPlanet_Database
 */
function chp_db() {
    return ChurrascoPlanet_Database::get_instance();
}

/**
 * Función helper para obtener el nombre de una tabla
 *
 * @param string $table
 * @return string
 */
function chp_table($table) {
    return ChurrascoPlanet_Database::get_instance()->table($table);
}
