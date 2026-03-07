<?php
/**
 * ChurrascoPlanet - Compatibility Layer
 * Proporciona compatibilidad con el sistema anterior (churrascoplanet_options)
 * mientras se migra al nuevo sistema de tablas
 *
 * @package ChurrascoPlanet
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase de compatibilidad que permite usar churrascoplanet_get_option()
 * pero leyendo/escribiendo desde las nuevas tablas
 */
class ChurrascoPlanet_Compat {

    private static $instance = null;

    /**
     * Mapeo de claves antiguas a nuevas (seccion.clave)
     */
    private $key_mapping = array(
        // Hero
        'inicio_hero_logo' => 'hero.logo',
        'inicio_hero_badge' => 'hero.badge_texto',
        'inicio_hero_badge_color' => 'hero.badge_color',
        'inicio_hero_titulo' => 'hero.titulo',
        'inicio_hero_titulo_color' => 'hero.titulo_color',
        'inicio_hero_subtitulo' => 'hero.subtitulo',
        'inicio_hero_subtitulo_color' => 'hero.subtitulo_color',
        'inicio_btn_primary_text' => 'hero.btn_primary_texto',
        'inicio_btn_primary_url' => 'hero.btn_primary_url',
        'inicio_btn_secondary_text' => 'hero.btn_secondary_texto',
        'inicio_btn_secondary_url' => 'hero.btn_secondary_url',

        // Banner
        'inicio_banner_image' => 'banner.imagen',
        'inicio_banner_tag' => 'banner.etiqueta',
        'inicio_banner_title' => 'banner.titulo',
        'inicio_banner_subtitle' => 'banner.subtitulo',
        'inicio_banner_url' => 'banner.url',
        'inicio_banner_btn' => 'banner.btn_texto',

        // Menu
        'menu_badge_icon' => 'menu.badge_icono',
        'menu_badge_text' => 'menu.badge_texto',
        'menu_titulo' => 'menu.titulo',
        'menu_titulo_color' => 'menu.titulo_color',
        'menu_subtitulo' => 'menu.subtitulo',
        'menu_subtitulo_color' => 'menu.subtitulo_color',

        // Delivery
        'delivery_titulo_icon' => 'delivery.titulo_icono',
        'delivery_titulo' => 'delivery.titulo',
        'delivery_parrafo' => 'delivery.parrafo',

        // Promociones
        'promo_badge_icon' => 'promociones.badge_icono',
        'promo_badge_text' => 'promociones.badge_texto',
        'promo_titulo' => 'promociones.titulo',
        'promo_titulo_color' => 'promociones.titulo_color',
        'promo_subtitulo' => 'promociones.subtitulo',
        'promo_subtitulo_color' => 'promociones.subtitulo_color',

        // Locales Header
        'locales_badge_icon' => 'locales.badge_icono',
        'locales_badge_text' => 'locales.badge_texto',
        'locales_titulo' => 'locales.titulo',
        'locales_titulo_color' => 'locales.titulo_color',
        'locales_subtitulo' => 'locales.subtitulo',
        'locales_subtitulo_color' => 'locales.subtitulo_color',

        // Colores
        'color_primary' => 'colores.primary',
        'color_secondary' => 'colores.secondary',
        'color_background' => 'colores.background',
        'color_text' => 'colores.text',
        'color_cards' => 'colores.cards',
        'color_borders' => 'colores.borders',
        'color_titulos' => 'colores.titulos',
        'color_subtitulos' => 'colores.subtitulos',
        'color_parrafos' => 'colores.parrafos',
        'color_highlight' => 'colores.highlight',
    );

    /**
     * Claves que son arrays/listas especiales
     */
    private $array_keys = array(
        'inicio_info_items',
        'inicio_categorias',
        'nav_menu_items',
        'delivery_opciones',
        'locales_lista',
        'extras_grupos',
    );

    /**
     * Cache de valores
     */
    private $cache = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Cargar valores en cache si las tablas existen
        $this->preload_cache();
    }

    /**
     * Pre-cargar valores frecuentes en cache
     */
    private function preload_cache() {
        // Solo pre-cargar si estamos en el frontend y las tablas existen
        if (is_admin() || !$this->tables_exist()) {
            return;
        }

        // Pre-cargar configuración de hero
        $config_model = ChurrascoPlanet_Config_Model::get_instance();
        $hero = $config_model->get_section('hero');
        foreach ($hero as $clave => $valor) {
            $this->cache["hero.{$clave}"] = $valor;
        }
    }

    /**
     * Verificar si las tablas existen
     */
    private function tables_exist() {
        static $exists = null;
        if ($exists === null) {
            global $wpdb;
            $table = chp_table('configuracion');
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
        }
        return $exists;
    }

    /**
     * Obtener una opción (compatible con churrascoplanet_get_option)
     *
     * @param string $key Clave de la opción
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    public function get_option($key, $default = '') {
        // Si las tablas no existen, usar sistema antiguo
        if (!$this->tables_exist()) {
            return $this->get_legacy_option($key, $default);
        }

        // Si está en cache, devolver
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Verificar si es un array especial
        if (in_array($key, $this->array_keys)) {
            return $this->get_array_option($key, $default);
        }

        // Verificar si hay mapeo
        if (isset($this->key_mapping[$key])) {
            $new_key = $this->key_mapping[$key];
            $value = chp_config($new_key, $default);
            $this->cache[$key] = $value;
            return $value;
        }

        // Si no hay mapeo, buscar en configuración general
        $value = chp_config("general.{$key}", $default);
        $this->cache[$key] = $value;
        return $value;
    }

    /**
     * Obtener opción del sistema antiguo (fallback)
     */
    private function get_legacy_option($key, $default) {
        $options = get_option('churrascoplanet_options', array());

        if (isset($options[$key])) {
            return $options[$key];
        }

        return $default;
    }

    /**
     * Obtener opciones tipo array
     */
    private function get_array_option($key, $default = array()) {
        switch ($key) {
            case 'inicio_info_items':
                return $this->get_info_items_as_legacy('hero_info');

            case 'inicio_categorias':
                return $this->get_info_items_as_legacy('inicio_categorias');

            case 'nav_menu_items':
                return $this->get_nav_items_as_legacy();

            case 'delivery_opciones':
                return $this->get_info_items_as_legacy('delivery_opciones');

            case 'locales_lista':
                return $this->get_locales_as_legacy();

            case 'extras_grupos':
                return $this->get_extras_grupos_as_legacy();

            default:
                return $default;
        }
    }

    /**
     * Obtener info_items en formato legacy
     */
    private function get_info_items_as_legacy($seccion) {
        $items = chp_get_info_items($seccion);
        $legacy = array();

        foreach ($items as $item) {
            if ($seccion === 'inicio_categorias') {
                $datos = json_decode($item['datos_extra'] ?? '{}', true);
                $legacy[] = array(
                    'slug' => $item['texto'] ?? '',
                    'icon' => $item['icono'] ?? 'fas fa-star',
                    'custom_name' => $datos['custom_name'] ?? '',
                    'custom_desc' => $datos['custom_desc'] ?? '',
                );
            } else {
                $legacy[] = array(
                    'icon' => $item['icono'] ?? 'fas fa-star',
                    'text' => $item['texto'] ?? '',
                );
            }
        }

        return $legacy;
    }

    /**
     * Obtener navegación en formato legacy
     */
    private function get_nav_items_as_legacy() {
        $items = chp_get_nav_items();
        $legacy = array();

        foreach ($items as $item) {
            $legacy[] = array(
                'texto' => $item['texto'] ?? '',
                'url' => $item['url'] ?? '#',
                'icon' => $item['icono'] ?? '',
                'target' => $item['target'] ?? '',
                'visible' => $item['visible'] ? '1' : '0',
            );
        }

        return $legacy;
    }

    /**
     * Obtener locales en formato legacy
     */
    private function get_locales_as_legacy() {
        $locales = chp_get_locales();
        $legacy = array();

        foreach ($locales as $local) {
            $legacy[] = array(
                'nombre' => $local['nombre'] ?? '',
                'subtitulo' => $local['subtitulo'] ?? '',
                'whatsapp' => $local['whatsapp'] ?? '',
                'icon' => $local['icono'] ?? 'fas fa-satellite',
            );
        }

        return $legacy;
    }

    /**
     * Obtener grupos de extras en formato legacy
     */
    private function get_extras_grupos_as_legacy() {
        $grupos = chp_get_all_extras_grupos();
        $legacy = array();

        foreach ($grupos as $grupo) {
            $items = array();
            if (!empty($grupo['items'])) {
                foreach ($grupo['items'] as $item) {
                    $items[] = array(
                        'nombre' => $item['nombre'] ?? '',
                        'precio' => $item['precio'] ?? 0,
                    );
                }
            }

            $legacy[] = array(
                'nombre' => $grupo['nombre'] ?? '',
                'instruccion' => $grupo['instruccion'] ?? '',
                'requerido' => $grupo['requerido'] ? '1' : '0',
                'tipo_seleccion' => $grupo['tipo_seleccion'] ?? 'checkbox',
                'min' => $grupo['min_selecciones'] ?? 0,
                'max' => $grupo['max_selecciones'] ?? 0,
                'items' => $items,
            );
        }

        return $legacy;
    }

    /**
     * Guardar una opción (compatible con el panel admin)
     */
    public function set_option($key, $value) {
        if (!$this->tables_exist()) {
            return false;
        }

        // Limpiar cache
        unset($this->cache[$key]);

        // Si es un array especial
        if (in_array($key, $this->array_keys)) {
            return $this->set_array_option($key, $value);
        }

        // Si hay mapeo
        if (isset($this->key_mapping[$key])) {
            $new_key = $this->key_mapping[$key];
            $parts = explode('.', $new_key);
            return chp_set_config($new_key, $value, $this->get_tipo_for_key($key));
        }

        // Guardar en general
        return chp_set_config("general.{$key}", $value);
    }

    /**
     * Guardar opción tipo array
     */
    private function set_array_option($key, $value) {
        if (!is_array($value)) {
            return false;
        }

        switch ($key) {
            case 'inicio_info_items':
                return $this->set_info_items_from_legacy('hero_info', $value);

            case 'inicio_categorias':
                return $this->set_info_items_from_legacy('inicio_categorias', $value, true);

            case 'nav_menu_items':
                return $this->set_nav_items_from_legacy($value);

            case 'delivery_opciones':
                return $this->set_info_items_from_legacy('delivery_opciones', $value);

            case 'locales_lista':
                return $this->set_locales_from_legacy($value);

            case 'extras_grupos':
                return $this->set_extras_grupos_from_legacy($value);

            default:
                return false;
        }
    }

    /**
     * Guardar info_items desde formato legacy
     */
    private function set_info_items_from_legacy($seccion, $items, $is_categoria = false) {
        $model = ChurrascoPlanet_Info_Items_Model::get_instance();
        $new_items = array();

        foreach ($items as $item) {
            if ($is_categoria) {
                $new_items[] = array(
                    'tipo' => 'category',
                    'icono' => $item['icon'] ?? 'fas fa-star',
                    'texto' => $item['slug'] ?? '',
                    'texto_secundario' => $item['custom_name'] ?? '',
                    'datos_extra' => json_encode(array(
                        'slug' => $item['slug'] ?? '',
                        'custom_name' => $item['custom_name'] ?? '',
                        'custom_desc' => $item['custom_desc'] ?? '',
                    )),
                    'activo' => 1,
                );
            } else {
                $new_items[] = array(
                    'tipo' => 'item',
                    'icono' => $item['icon'] ?? 'fas fa-star',
                    'texto' => $item['text'] ?? '',
                    'activo' => 1,
                );
            }
        }

        return $model->replace_section($seccion, $new_items);
    }

    /**
     * Guardar navegación desde formato legacy
     */
    private function set_nav_items_from_legacy($items) {
        global $wpdb;
        $table = chp_table('navegacion');

        // Limpiar tabla
        $wpdb->query("TRUNCATE TABLE {$table}");

        $model = ChurrascoPlanet_Nav_Model::get_instance();

        foreach ($items as $orden => $item) {
            $model->insert(array(
                'texto' => $item['texto'] ?? 'Item',
                'url' => $item['url'] ?? '#',
                'icono' => $item['icon'] ?? '',
                'target' => ($item['target'] ?? '') === '_blank' ? '_blank' : '_self',
                'visible' => ($item['visible'] ?? '1') === '1' ? 1 : 0,
                'orden' => $orden,
            ));
        }

        return true;
    }

    /**
     * Guardar locales desde formato legacy
     */
    private function set_locales_from_legacy($items) {
        global $wpdb;
        $table = chp_table('locales');

        // Limpiar tabla
        $wpdb->query("TRUNCATE TABLE {$table}");

        $model = ChurrascoPlanet_Locales_Model::get_instance();

        foreach ($items as $orden => $item) {
            $model->insert(array(
                'nombre' => $item['nombre'] ?? 'Local',
                'subtitulo' => $item['subtitulo'] ?? '',
                'whatsapp' => $item['whatsapp'] ?? '',
                'icono' => $item['icon'] ?? 'fas fa-satellite',
                'activo' => 1,
                'orden' => $orden,
            ));
        }

        return true;
    }

    /**
     * Guardar grupos de extras desde formato legacy
     */
    private function set_extras_grupos_from_legacy($grupos) {
        global $wpdb;

        // Limpiar tablas relacionadas (con CASCADE se eliminan los items)
        $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
        $wpdb->query("TRUNCATE TABLE " . chp_table('producto_extras'));
        $wpdb->query("TRUNCATE TABLE " . chp_table('extras_items'));
        $wpdb->query("TRUNCATE TABLE " . chp_table('extras_grupos'));
        $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");

        $grupos_model = ChurrascoPlanet_Extras_Grupos_Model::get_instance();

        foreach ($grupos as $orden => $grupo) {
            $items = array();
            if (!empty($grupo['items'])) {
                foreach ($grupo['items'] as $item) {
                    $items[] = array(
                        'nombre' => $item['nombre'] ?? '',
                        'precio' => (int)($item['precio'] ?? 0),
                        'activo' => 1,
                    );
                }
            }

            $grupos_model->create_with_items(array(
                'nombre' => $grupo['nombre'] ?? 'Grupo',
                'slug' => sanitize_title($grupo['nombre'] ?? 'grupo-' . $orden),
                'instruccion' => $grupo['instruccion'] ?? '',
                'requerido' => ($grupo['requerido'] ?? '0') === '1' ? 1 : 0,
                'tipo_seleccion' => $grupo['tipo_seleccion'] ?? 'checkbox',
                'min_selecciones' => (int)($grupo['min'] ?? 0),
                'max_selecciones' => (int)($grupo['max'] ?? 0),
                'activo' => 1,
                'orden' => $orden,
            ), $items);
        }

        return true;
    }

    /**
     * Obtener el tipo de dato para una clave
     */
    private function get_tipo_for_key($key) {
        if (strpos($key, '_color') !== false) {
            return 'color';
        }
        if (strpos($key, '_url') !== false || strpos($key, '_image') !== false || strpos($key, '_logo') !== false) {
            return 'url';
        }
        if (strpos($key, '_titulo') !== false || strpos($key, '_subtitulo') !== false || strpos($key, '_parrafo') !== false) {
            return 'html';
        }
        return 'text';
    }

    /**
     * Guardar todas las opciones del panel admin a las nuevas tablas
     *
     * @param array $options Array de opciones del formato antiguo
     * @return bool
     */
    public function save_all_options($options) {
        if (!is_array($options) || !$this->tables_exist()) {
            return false;
        }

        foreach ($options as $key => $value) {
            $this->set_option($key, $value);
        }

        // Limpiar cache
        $this->cache = array();

        return true;
    }
}

// Inicializar
ChurrascoPlanet_Compat::get_instance();

/**
 * Función de compatibilidad: churrascoplanet_get_option
 * Esta función ahora lee de las nuevas tablas
 */
if (!function_exists('churrascoplanet_get_option')) {
    function churrascoplanet_get_option($key, $default = '') {
        return ChurrascoPlanet_Compat::get_instance()->get_option($key, $default);
    }
}
