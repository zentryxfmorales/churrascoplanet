<?php
/**
 * ChurrascoPlanet - Data Models
 * Modelos para interactuar con las tablas personalizadas
 *
 * @package ChurrascoPlanet
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Modelo base abstracto
 */
abstract class ChurrascoPlanet_Model {

    /**
     * Nombre de la tabla (sin prefijo)
     */
    protected $table_name;

    /**
     * Clave primaria
     */
    protected $primary_key = 'id';

    /**
     * Obtener nombre completo de la tabla
     */
    protected function get_table() {
        return chp_table($this->table_name);
    }

    /**
     * Obtener un registro por ID
     */
    public function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->get_table()} WHERE {$this->primary_key} = %d",
            $id
        ), ARRAY_A);
    }

    /**
     * Obtener todos los registros
     */
    public function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'orderby' => 'orden',
            'order' => 'ASC',
            'limit' => 0,
            'offset' => 0,
            'where' => array(),
        );

        $args = wp_parse_args($args, $defaults);
        $table = $this->get_table();

        $sql = "SELECT * FROM {$table}";

        // WHERE clauses
        if (!empty($args['where'])) {
            $where_clauses = array();
            foreach ($args['where'] as $field => $value) {
                if (is_null($value)) {
                    $where_clauses[] = "{$field} IS NULL";
                } elseif (is_array($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '%s'));
                    $where_clauses[] = $wpdb->prepare("{$field} IN ({$placeholders})", $value);
                } else {
                    $where_clauses[] = $wpdb->prepare("{$field} = %s", $value);
                }
            }
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }

        // ORDER BY
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if ($orderby) {
            $sql .= " ORDER BY {$orderby}";
        }

        // LIMIT
        if ($args['limit'] > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $args['limit']);
            if ($args['offset'] > 0) {
                $sql .= $wpdb->prepare(" OFFSET %d", $args['offset']);
            }
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Insertar un registro
     */
    public function insert($data) {
        global $wpdb;

        $result = $wpdb->insert($this->get_table(), $data);

        if ($result === false) {
            return new WP_Error('db_insert_error', $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }

    /**
     * Actualizar un registro
     */
    public function update($id, $data) {
        global $wpdb;

        $result = $wpdb->update(
            $this->get_table(),
            $data,
            array($this->primary_key => $id)
        );

        if ($result === false) {
            return new WP_Error('db_update_error', $wpdb->last_error);
        }

        return $result;
    }

    /**
     * Eliminar un registro
     */
    public function delete($id) {
        global $wpdb;

        return $wpdb->delete(
            $this->get_table(),
            array($this->primary_key => $id),
            array('%d')
        );
    }

    /**
     * Contar registros
     */
    public function count($where = array()) {
        global $wpdb;
        $table = $this->get_table();

        $sql = "SELECT COUNT(*) FROM {$table}";

        if (!empty($where)) {
            $where_clauses = array();
            foreach ($where as $field => $value) {
                $where_clauses[] = $wpdb->prepare("{$field} = %s", $value);
            }
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }

        return (int) $wpdb->get_var($sql);
    }
}

/**
 * Modelo: Configuración
 */
class ChurrascoPlanet_Config_Model extends ChurrascoPlanet_Model {

    protected $table_name = 'configuracion';

    private static $instance = null;
    private $cache = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtener un valor de configuración
     *
     * @param string $seccion
     * @param string $clave
     * @param mixed $default
     * @return mixed
     */
    public function get_value($seccion, $clave, $default = '') {
        $cache_key = "{$seccion}.{$clave}";

        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        global $wpdb;
        $table = $this->get_table();

        $valor = $wpdb->get_var($wpdb->prepare(
            "SELECT valor FROM {$table} WHERE seccion = %s AND clave = %s",
            $seccion,
            $clave
        ));

        if ($valor === null) {
            return $default;
        }

        $this->cache[$cache_key] = $valor;
        return $valor;
    }

    /**
     * Establecer un valor de configuración
     *
     * @param string $seccion
     * @param string $clave
     * @param mixed $valor
     * @param string $tipo
     * @return bool
     */
    public function set_value($seccion, $clave, $valor, $tipo = 'text') {
        global $wpdb;
        $table = $this->get_table();

        $result = $wpdb->replace($table, array(
            'seccion' => $seccion,
            'clave' => $clave,
            'valor' => $valor,
            'tipo' => $tipo,
        ), array('%s', '%s', '%s', '%s'));

        // Limpiar cache
        $cache_key = "{$seccion}.{$clave}";
        unset($this->cache[$cache_key]);

        return $result !== false;
    }

    /**
     * Obtener todos los valores de una sección
     *
     * @param string $seccion
     * @return array
     */
    public function get_section($seccion) {
        global $wpdb;
        $table = $this->get_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT clave, valor, tipo FROM {$table} WHERE seccion = %s",
            $seccion
        ), ARRAY_A);

        $result = array();
        foreach ($rows as $row) {
            $result[$row['clave']] = $row['valor'];
            // Cache
            $this->cache["{$seccion}.{$row['clave']}"] = $row['valor'];
        }

        return $result;
    }

    /**
     * Eliminar toda una sección
     */
    public function delete_section($seccion) {
        global $wpdb;
        return $wpdb->delete($this->get_table(), array('seccion' => $seccion), array('%s'));
    }
}

/**
 * Modelo: Navegación
 */
class ChurrascoPlanet_Nav_Model extends ChurrascoPlanet_Model {

    protected $table_name = 'navegacion';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtener items de menú visibles ordenados
     */
    public function get_visible_items() {
        return $this->get_all(array(
            'where' => array('visible' => 1),
            'orderby' => 'orden',
            'order' => 'ASC',
        ));
    }

    /**
     * Reordenar items
     */
    public function reorder($item_ids) {
        global $wpdb;
        $table = $this->get_table();

        foreach ($item_ids as $orden => $id) {
            $wpdb->update($table, array('orden' => $orden), array('id' => $id));
        }

        return true;
    }
}

/**
 * Modelo: Locales
 */
class ChurrascoPlanet_Locales_Model extends ChurrascoPlanet_Model {

    protected $table_name = 'locales';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtener locales activos ordenados
     */
    public function get_active() {
        return $this->get_all(array(
            'where' => array('activo' => 1),
            'orderby' => 'orden',
            'order' => 'ASC',
        ));
    }

    /**
     * Obtener locales por comuna
     */
    public function get_by_comuna($comuna) {
        return $this->get_all(array(
            'where' => array('comuna' => $comuna, 'activo' => 1),
        ));
    }

    /**
     * Buscar locales cercanos (por radio de delivery)
     * Nota: Requiere latitud/longitud configuradas
     */
    public function get_nearby($lat, $lng, $max_km = 10) {
        global $wpdb;
        $table = $this->get_table();

        // Fórmula Haversine para calcular distancia
        $sql = $wpdb->prepare("
            SELECT *,
            (6371 * acos(cos(radians(%f)) * cos(radians(latitud)) * cos(radians(longitud) - radians(%f)) + sin(radians(%f)) * sin(radians(latitud)))) AS distance
            FROM {$table}
            WHERE activo = 1
            AND latitud IS NOT NULL
            AND longitud IS NOT NULL
            HAVING distance <= %f
            ORDER BY distance ASC
        ", $lat, $lng, $lat, $max_km);

        return $wpdb->get_results($sql, ARRAY_A);
    }
}

/**
 * Modelo: Grupos de Extras
 */
class ChurrascoPlanet_Extras_Grupos_Model extends ChurrascoPlanet_Model {

    protected $table_name = 'extras_grupos';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtener grupos activos con sus items
     */
    public function get_with_items($grupo_id = null) {
        global $wpdb;

        $grupos = $grupo_id
            ? array($this->get($grupo_id))
            : $this->get_all(array('where' => array('activo' => 1)));

        if (empty($grupos) || (count($grupos) === 1 && $grupos[0] === null)) {
            return array();
        }

        $items_model = ChurrascoPlanet_Extras_Items_Model::get_instance();

        foreach ($grupos as &$grupo) {
            if ($grupo) {
                $grupo['items'] = $items_model->get_by_grupo($grupo['id']);
            }
        }

        return $grupo_id ? $grupos[0] : $grupos;
    }

    /**
     * Obtener grupo por slug
     */
    public function get_by_slug($slug) {
        global $wpdb;
        $table = $this->get_table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE slug = %s",
            $slug
        ), ARRAY_A);
    }

    /**
     * Crear grupo con items
     */
    public function create_with_items($grupo_data, $items = array()) {
        // Generar slug si no existe
        if (empty($grupo_data['slug'])) {
            $grupo_data['slug'] = sanitize_title($grupo_data['nombre']);
        }

        // Insertar grupo
        $grupo_id = $this->insert($grupo_data);

        if (is_wp_error($grupo_id)) {
            return $grupo_id;
        }

        // Insertar items
        if (!empty($items)) {
            $items_model = ChurrascoPlanet_Extras_Items_Model::get_instance();
            foreach ($items as $orden => $item) {
                $item['grupo_id'] = $grupo_id;
                $item['orden'] = $orden;
                $items_model->insert($item);
            }
        }

        return $grupo_id;
    }
}

/**
 * Modelo: Items de Extras
 */
class ChurrascoPlanet_Extras_Items_Model extends ChurrascoPlanet_Model {

    protected $table_name = 'extras_items';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtener items de un grupo
     */
    public function get_by_grupo($grupo_id) {
        return $this->get_all(array(
            'where' => array('grupo_id' => $grupo_id, 'activo' => 1),
            'orderby' => 'orden',
            'order' => 'ASC',
        ));
    }

    /**
     * Eliminar todos los items de un grupo
     */
    public function delete_by_grupo($grupo_id) {
        global $wpdb;
        return $wpdb->delete($this->get_table(), array('grupo_id' => $grupo_id), array('%d'));
    }
}

/**
 * Modelo: Relación Producto-Extras
 */
class ChurrascoPlanet_Producto_Extras_Model extends ChurrascoPlanet_Model {

    protected $table_name = 'producto_extras';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtener grupos asignados a un producto
     */
    public function get_by_product($product_id) {
        global $wpdb;
        $table = $this->get_table();
        $grupos_table = chp_table('extras_grupos');

        return $wpdb->get_results($wpdb->prepare("
            SELECT g.*, pe.orden as asignacion_orden
            FROM {$table} pe
            INNER JOIN {$grupos_table} g ON pe.grupo_id = g.id
            WHERE pe.product_id = %d AND g.activo = 1
            ORDER BY pe.orden ASC
        ", $product_id), ARRAY_A);
    }

    /**
     * Obtener grupos con items para un producto
     */
    public function get_groups_with_items_for_product($product_id) {
        $grupos = $this->get_by_product($product_id);

        if (empty($grupos)) {
            return array();
        }

        $items_model = ChurrascoPlanet_Extras_Items_Model::get_instance();

        foreach ($grupos as &$grupo) {
            $grupo['items'] = $items_model->get_by_grupo($grupo['id']);
        }

        return $grupos;
    }

    /**
     * Asignar grupos a un producto
     */
    public function assign_to_product($product_id, $grupo_ids) {
        global $wpdb;
        $table = $this->get_table();

        // Eliminar asignaciones actuales
        $wpdb->delete($table, array('product_id' => $product_id), array('%d'));

        // Insertar nuevas asignaciones
        foreach ($grupo_ids as $orden => $grupo_id) {
            $wpdb->insert($table, array(
                'product_id' => $product_id,
                'grupo_id' => $grupo_id,
                'orden' => $orden,
            ), array('%d', '%d', '%d'));
        }

        return true;
    }

    /**
     * Obtener productos que tienen un grupo asignado
     */
    public function get_products_by_grupo($grupo_id) {
        global $wpdb;
        $table = $this->get_table();

        return $wpdb->get_col($wpdb->prepare(
            "SELECT product_id FROM {$table} WHERE grupo_id = %d",
            $grupo_id
        ));
    }
}

/**
 * Modelo: Histórico de Extras en Pedidos
 */
class ChurrascoPlanet_Pedido_Extras_Model extends ChurrascoPlanet_Model {

    protected $table_name = 'pedido_extras';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtener extras de una orden
     */
    public function get_by_order($order_id) {
        return $this->get_all(array(
            'where' => array('order_id' => $order_id),
            'orderby' => 'id',
            'order' => 'ASC',
        ));
    }

    /**
     * Obtener extras de un item de orden
     */
    public function get_by_order_item($order_item_id) {
        return $this->get_all(array(
            'where' => array('order_item_id' => $order_item_id),
            'orderby' => 'id',
            'order' => 'ASC',
        ));
    }

    /**
     * Guardar extras de un pedido
     */
    public function save_order_extras($order_id, $order_item_id, $product_id, $extras) {
        foreach ($extras as $extra_group) {
            foreach ($extra_group['items'] as $item) {
                $this->insert(array(
                    'order_id' => $order_id,
                    'order_item_id' => $order_item_id,
                    'product_id' => $product_id,
                    'grupo_id' => $extra_group['grupo_id'] ?? null,
                    'grupo_nombre' => $extra_group['grupo'] ?? '',
                    'item_id' => $item['id'] ?? null,
                    'item_nombre' => $item['nombre'],
                    'item_precio' => $item['precio'],
                    'cantidad' => 1,
                    'subtotal' => $item['precio'],
                ));
            }
        }
    }

    /**
     * Obtener resumen de extras más vendidos
     */
    public function get_top_extras($limit = 10, $from_date = null, $to_date = null) {
        global $wpdb;
        $table = $this->get_table();

        $sql = "SELECT item_nombre, grupo_nombre, SUM(cantidad) as total_vendido, SUM(subtotal) as total_ingresos
                FROM {$table}
                WHERE 1=1";

        if ($from_date) {
            $sql .= $wpdb->prepare(" AND created_at >= %s", $from_date);
        }
        if ($to_date) {
            $sql .= $wpdb->prepare(" AND created_at <= %s", $to_date);
        }

        $sql .= " GROUP BY item_nombre, grupo_nombre
                  ORDER BY total_vendido DESC
                  LIMIT %d";

        return $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
    }
}

/**
 * Modelo: Info Items
 */
class ChurrascoPlanet_Info_Items_Model extends ChurrascoPlanet_Model {

    protected $table_name = 'info_items';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtener items de una sección
     */
    public function get_by_section($seccion) {
        return $this->get_all(array(
            'where' => array('seccion' => $seccion, 'activo' => 1),
            'orderby' => 'orden',
            'order' => 'ASC',
        ));
    }

    /**
     * Reemplazar items de una sección
     */
    public function replace_section($seccion, $items) {
        global $wpdb;
        $table = $this->get_table();

        // Eliminar items actuales
        $wpdb->delete($table, array('seccion' => $seccion), array('%s'));

        // Insertar nuevos
        foreach ($items as $orden => $item) {
            $item['seccion'] = $seccion;
            $item['orden'] = $orden;
            $this->insert($item);
        }

        return true;
    }
}

// ============================================================================
// FUNCIONES HELPER GLOBALES
// ============================================================================

/**
 * Obtener valor de configuración
 *
 * @param string $key Formato: "seccion.clave" o solo "clave" (seccion = 'general')
 * @param mixed $default
 * @return mixed
 */
if (!function_exists('chp_config')) {
    function chp_config($key, $default = '') {
        $parts = explode('.', $key, 2);

        if (count($parts) === 2) {
            $seccion = $parts[0];
            $clave = $parts[1];
        } else {
            $seccion = 'general';
            $clave = $key;
        }

        return ChurrascoPlanet_Config_Model::get_instance()->get_value($seccion, $clave, $default);
    }
}

/**
 * Establecer valor de configuración
 */
if (!function_exists('chp_set_config')) {
    function chp_set_config($key, $value, $tipo = 'text') {
        $parts = explode('.', $key, 2);

        if (count($parts) === 2) {
            $seccion = $parts[0];
            $clave = $parts[1];
        } else {
            $seccion = 'general';
            $clave = $key;
        }

        return ChurrascoPlanet_Config_Model::get_instance()->set_value($seccion, $clave, $value, $tipo);
    }
}

/**
 * Obtener locales activos
 */
if (!function_exists('chp_get_locales')) {
    function chp_get_locales() {
        return ChurrascoPlanet_Locales_Model::get_instance()->get_active();
    }
}

/**
 * Obtener items de navegación
 */
if (!function_exists('chp_get_nav_items')) {
    function chp_get_nav_items() {
        return ChurrascoPlanet_Nav_Model::get_instance()->get_visible_items();
    }
}

/**
 * Obtener grupos de extras para un producto
 */
if (!function_exists('chp_get_product_extras')) {
    function chp_get_product_extras($product_id) {
        return ChurrascoPlanet_Producto_Extras_Model::get_instance()->get_groups_with_items_for_product($product_id);
    }
}

/**
 * Obtener todos los grupos de extras activos
 */
if (!function_exists('chp_get_all_extras_grupos')) {
    function chp_get_all_extras_grupos() {
        return ChurrascoPlanet_Extras_Grupos_Model::get_instance()->get_with_items();
    }
}

/**
 * Obtener info items por sección
 */
if (!function_exists('chp_get_info_items')) {
    function chp_get_info_items($seccion) {
        return ChurrascoPlanet_Info_Items_Model::get_instance()->get_by_section($seccion);
    }
}
