<?php
/**
 * Configuración de administración del plugin
 *
 * @package WC_Uber_Direct_Connect
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase WCUDC_Admin_Settings
 *
 * Maneja la página de configuración del plugin en el admin de WordPress
 * Incluye gestión de tiendas con zonas de reparto por polígonos
 */
class WCUDC_Admin_Settings {

    /**
     * Slug de la página de opciones
     */
    private const MENU_SLUG = 'wcudc-settings';

    /**
     * Grupo de opciones
     */
    private const OPTION_GROUP = 'wcudc_settings_group';

    /**
     * Nombre de la opción en la BD (configuración general)
     */
    private const OPTION_NAME = 'wcudc_settings';

    /**
     * Nombre de la opción para tiendas
     */
    private const STORES_OPTION = 'wcudc_stores';

    /**
     * Nonce para acciones AJAX
     */
    private const AJAX_NONCE = 'wcudc_admin_nonce';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_filter( 'plugin_action_links_' . WCUDC_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );

        // AJAX handlers para CRUD de tiendas
        add_action( 'wp_ajax_wcudc_save_store', array( $this, 'ajax_save_store' ) );
        add_action( 'wp_ajax_wcudc_delete_store', array( $this, 'ajax_delete_store' ) );
        add_action( 'wp_ajax_wcudc_get_store', array( $this, 'ajax_get_store' ) );
        add_action( 'wp_ajax_wcudc_get_stores', array( $this, 'ajax_get_stores' ) );
    }

    /**
     * Agrega la página de menú en WooCommerce
     */
    public function add_menu_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Uber Direct Connect', 'wc-uber-direct-connect' ),
            __( 'Uber Direct', 'wc-uber-direct-connect' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Encola assets para el admin (CSS, JS, Leaflet)
     *
     * @param string $hook Hook de la página actual.
     */
    public function enqueue_admin_assets( string $hook ): void {
        // Solo cargar en nuestra página
        if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
            return;
        }

        // Leaflet CSS
        wp_enqueue_style(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            array(),
            '1.9.4'
        );

        // Leaflet Draw CSS (para dibujar polígonos)
        wp_enqueue_style(
            'leaflet-draw',
            'https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css',
            array( 'leaflet' ),
            '1.0.4'
        );

        // CSS propio del admin
        wp_enqueue_style(
            'wcudc-admin',
            WCUDC_PLUGIN_URL . 'assets/css/admin.css',
            array( 'leaflet', 'leaflet-draw' ),
            WCUDC_VERSION
        );

        // Leaflet JS
        wp_enqueue_script(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            array(),
            '1.9.4',
            true
        );

        // Leaflet Draw JS
        wp_enqueue_script(
            'leaflet-draw',
            'https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js',
            array( 'leaflet' ),
            '1.0.4',
            true
        );

        // JS propio del admin
        wp_enqueue_script(
            'wcudc-admin',
            WCUDC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'leaflet', 'leaflet-draw' ),
            WCUDC_VERSION,
            true
        );

        // Pasar datos al JS
        wp_localize_script( 'wcudc-admin', 'wcudcAdmin', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( self::AJAX_NONCE ),
            'strings'   => array(
                'confirmDelete'  => __( '¿Estás seguro de eliminar esta tienda?', 'wc-uber-direct-connect' ),
                'saveSuccess'    => __( 'Tienda guardada correctamente.', 'wc-uber-direct-connect' ),
                'saveError'      => __( 'Error al guardar la tienda.', 'wc-uber-direct-connect' ),
                'deleteSuccess'  => __( 'Tienda eliminada.', 'wc-uber-direct-connect' ),
                'deleteError'    => __( 'Error al eliminar la tienda.', 'wc-uber-direct-connect' ),
                'drawPolygon'    => __( 'Dibuja un polígono en el mapa', 'wc-uber-direct-connect' ),
                'editPolygon'    => __( 'Editar polígono', 'wc-uber-direct-connect' ),
                'deletePolygon'  => __( 'Eliminar polígono', 'wc-uber-direct-connect' ),
                'noPolygon'      => __( 'Debes dibujar una zona de reparto.', 'wc-uber-direct-connect' ),
            ),
        ) );
    }

    /**
     * Agrega enlace de configuración en la lista de plugins
     *
     * @param array $links Enlaces existentes.
     * @return array
     */
    public function add_settings_link( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=' . self::MENU_SLUG ),
            __( 'Configuración', 'wc-uber-direct-connect' )
        );

        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Registra los campos de configuración
     */
    public function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            array( $this, 'sanitize_settings' )
        );

        // Sección: Credenciales API
        add_settings_section(
            'wcudc_api_credentials',
            __( 'Credenciales de API', 'wc-uber-direct-connect' ),
            array( $this, 'render_api_section' ),
            self::MENU_SLUG
        );

        add_settings_field(
            'client_id',
            __( 'Client ID', 'wc-uber-direct-connect' ),
            array( $this, 'render_text_field' ),
            self::MENU_SLUG,
            'wcudc_api_credentials',
            array( 'field' => 'client_id' )
        );

        add_settings_field(
            'client_secret',
            __( 'Client Secret', 'wc-uber-direct-connect' ),
            array( $this, 'render_password_field' ),
            self::MENU_SLUG,
            'wcudc_api_credentials',
            array( 'field' => 'client_secret' )
        );

        add_settings_field(
            'customer_id',
            __( 'Customer ID', 'wc-uber-direct-connect' ),
            array( $this, 'render_text_field' ),
            self::MENU_SLUG,
            'wcudc_api_credentials',
            array( 'field' => 'customer_id' )
        );

        add_settings_field(
            'webhook_secret',
            __( 'Webhook Secret', 'wc-uber-direct-connect' ),
            array( $this, 'render_password_field' ),
            self::MENU_SLUG,
            'wcudc_api_credentials',
            array( 'field' => 'webhook_secret' )
        );

        add_settings_field(
            'sandbox_mode',
            __( 'Modo Sandbox', 'wc-uber-direct-connect' ),
            array( $this, 'render_checkbox_field' ),
            self::MENU_SLUG,
            'wcudc_api_credentials',
            array(
                'field'       => 'sandbox_mode',
                'description' => __( 'Activar para usar el entorno de pruebas de Uber.', 'wc-uber-direct-connect' ),
            )
        );
    }

    /**
     * Sanitiza los valores de configuración
     *
     * @param array $input Valores de entrada.
     * @return array
     */
    public function sanitize_settings( array $input ): array {
        $sanitized = array();

        $sanitized['client_id']      = sanitize_text_field( $input['client_id'] ?? '' );
        $sanitized['client_secret']  = sanitize_text_field( $input['client_secret'] ?? '' );
        $sanitized['customer_id']    = sanitize_text_field( $input['customer_id'] ?? '' );
        $sanitized['webhook_secret'] = sanitize_text_field( $input['webhook_secret'] ?? '' );
        $sanitized['sandbox_mode']   = isset( $input['sandbox_mode'] ) ? 'yes' : 'no';

        return $sanitized;
    }

    /**
     * Renderiza la descripción de la sección de API
     */
    public function render_api_section(): void {
        echo '<p>' . esc_html__( 'Ingresa las credenciales de tu aplicación de Uber Direct.', 'wc-uber-direct-connect' ) . '</p>';
    }

    /**
     * Renderiza un campo de texto
     *
     * @param array $args Argumentos del campo.
     */
    public function render_text_field( array $args ): void {
        $options = get_option( self::OPTION_NAME, array() );
        $field   = $args['field'];
        $value   = $options[ $field ] ?? '';

        printf(
            '<input type="text" id="%s" name="%s[%s]" value="%s" class="regular-text">',
            esc_attr( $field ),
            esc_attr( self::OPTION_NAME ),
            esc_attr( $field ),
            esc_attr( $value )
        );

        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Renderiza un campo de contraseña
     *
     * @param array $args Argumentos del campo.
     */
    public function render_password_field( array $args ): void {
        $options = get_option( self::OPTION_NAME, array() );
        $field   = $args['field'];
        $value   = $options[ $field ] ?? '';

        printf(
            '<input type="password" id="%s" name="%s[%s]" value="%s" class="regular-text">',
            esc_attr( $field ),
            esc_attr( self::OPTION_NAME ),
            esc_attr( $field ),
            esc_attr( $value )
        );
    }

    /**
     * Renderiza un campo checkbox
     *
     * @param array $args Argumentos del campo.
     */
    public function render_checkbox_field( array $args ): void {
        $options = get_option( self::OPTION_NAME, array() );
        $field   = $args['field'];
        $checked = ( $options[ $field ] ?? 'yes' ) === 'yes';

        printf(
            '<input type="checkbox" id="%s" name="%s[%s]" value="yes" %s>',
            esc_attr( $field ),
            esc_attr( self::OPTION_NAME ),
            esc_attr( $field ),
            checked( $checked, true, false )
        );

        if ( ! empty( $args['description'] ) ) {
            printf( '<label for="%s"> %s</label>', esc_attr( $field ), esc_html( $args['description'] ) );
        }
    }

    /**
     * Renderiza la página de configuración con pestañas
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'api';
        $tabs        = array(
            'api'    => __( 'Configuración API', 'wc-uber-direct-connect' ),
            'stores' => __( 'Tiendas', 'wc-uber-direct-connect' ),
        );

        ?>
        <div class="wrap wcudc-admin-wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <!-- Navegación por pestañas -->
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $tab_id => $tab_name ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $tab_id ) ); ?>"
                       class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_name ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="wcudc-tab-content">
                <?php
                switch ( $current_tab ) {
                    case 'stores':
                        $this->render_stores_tab();
                        break;
                    default:
                        $this->render_api_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renderiza la pestaña de configuración API
     */
    private function render_api_tab(): void {
        $webhook_url = rest_url( 'wcudc/v1/webhook' );
        ?>
        <div class="notice notice-info">
            <p>
                <strong><?php esc_html_e( 'URL del Webhook:', 'wc-uber-direct-connect' ); ?></strong><br>
                <code><?php echo esc_url( $webhook_url ); ?></code>
            </p>
            <p class="description">
                <?php esc_html_e( 'Configura esta URL en el panel de desarrolladores de Uber para recibir actualizaciones de los deliveries.', 'wc-uber-direct-connect' ); ?>
            </p>
        </div>

        <form action="options.php" method="post">
            <?php
            settings_fields( self::OPTION_GROUP );
            do_settings_sections( self::MENU_SLUG );
            submit_button( __( 'Guardar Configuración', 'wc-uber-direct-connect' ) );
            ?>
        </form>
        <?php
    }

    /**
     * Renderiza la pestaña de gestión de tiendas
     */
    private function render_stores_tab(): void {
        $stores = $this->get_stores();
        ?>
        <div class="wcudc-stores-container">
            <!-- Lista de tiendas -->
            <div class="wcudc-stores-list">
                <h2>
                    <?php esc_html_e( 'Tiendas', 'wc-uber-direct-connect' ); ?>
                    <button type="button" class="page-title-action" id="wcudc-add-store">
                        <?php esc_html_e( 'Agregar Tienda', 'wc-uber-direct-connect' ); ?>
                    </button>
                </h2>

                <table class="wp-list-table widefat fixed striped" id="wcudc-stores-table">
                    <thead>
                        <tr>
                            <th scope="col" class="column-name"><?php esc_html_e( 'Nombre', 'wc-uber-direct-connect' ); ?></th>
                            <th scope="col" class="column-address"><?php esc_html_e( 'Dirección', 'wc-uber-direct-connect' ); ?></th>
                            <th scope="col" class="column-status"><?php esc_html_e( 'Estado', 'wc-uber-direct-connect' ); ?></th>
                            <th scope="col" class="column-polygon"><?php esc_html_e( 'Zona', 'wc-uber-direct-connect' ); ?></th>
                            <th scope="col" class="column-actions"><?php esc_html_e( 'Acciones', 'wc-uber-direct-connect' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $stores ) ) : ?>
                            <tr class="no-stores">
                                <td colspan="5"><?php esc_html_e( 'No hay tiendas configuradas.', 'wc-uber-direct-connect' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $stores as $store ) : ?>
                                <tr data-store-id="<?php echo esc_attr( $store['id'] ); ?>">
                                    <td class="column-name">
                                        <strong><?php echo esc_html( $store['name'] ); ?></strong>
                                    </td>
                                    <td class="column-address"><?php echo esc_html( $store['address'] ); ?></td>
                                    <td class="column-status">
                                        <?php if ( $store['active'] ) : ?>
                                            <span class="wcudc-status wcudc-status-active"><?php esc_html_e( 'Activa', 'wc-uber-direct-connect' ); ?></span>
                                        <?php else : ?>
                                            <span class="wcudc-status wcudc-status-inactive"><?php esc_html_e( 'Inactiva', 'wc-uber-direct-connect' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-polygon">
                                        <?php if ( ! empty( $store['polygon'] ) ) : ?>
                                            <span class="wcudc-polygon-set"><?php echo count( $store['polygon'] ); ?> <?php esc_html_e( 'puntos', 'wc-uber-direct-connect' ); ?></span>
                                        <?php else : ?>
                                            <span class="wcudc-polygon-missing"><?php esc_html_e( 'Sin zona', 'wc-uber-direct-connect' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-actions">
                                        <button type="button" class="button wcudc-edit-store" data-id="<?php echo esc_attr( $store['id'] ); ?>">
                                            <?php esc_html_e( 'Editar', 'wc-uber-direct-connect' ); ?>
                                        </button>
                                        <button type="button" class="button wcudc-delete-store" data-id="<?php echo esc_attr( $store['id'] ); ?>">
                                            <?php esc_html_e( 'Eliminar', 'wc-uber-direct-connect' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Modal/Formulario de edición de tienda -->
            <div id="wcudc-store-modal" class="wcudc-modal" style="display: none;">
                <div class="wcudc-modal-content">
                    <span class="wcudc-modal-close">&times;</span>
                    <h2 id="wcudc-modal-title"><?php esc_html_e( 'Nueva Tienda', 'wc-uber-direct-connect' ); ?></h2>

                    <form id="wcudc-store-form">
                        <input type="hidden" name="store_id" id="wcudc-store-id" value="">

                        <div class="wcudc-form-row">
                            <label for="wcudc-store-name"><?php esc_html_e( 'Nombre de la Tienda', 'wc-uber-direct-connect' ); ?> <span class="required">*</span></label>
                            <input type="text" id="wcudc-store-name" name="name" required class="regular-text">
                        </div>

                        <div class="wcudc-form-row">
                            <label for="wcudc-store-address"><?php esc_html_e( 'Dirección', 'wc-uber-direct-connect' ); ?> <span class="required">*</span></label>
                            <input type="text" id="wcudc-store-address" name="address" required class="regular-text">
                        </div>

                        <div class="wcudc-form-row wcudc-form-row-half">
                            <div>
                                <label for="wcudc-store-phone"><?php esc_html_e( 'Teléfono', 'wc-uber-direct-connect' ); ?> <span class="required">*</span></label>
                                <input type="tel" id="wcudc-store-phone" name="phone" required class="regular-text">
                            </div>
                            <div>
                                <label for="wcudc-store-active">
                                    <input type="checkbox" id="wcudc-store-active" name="active" value="1" checked>
                                    <?php esc_html_e( 'Tienda Activa', 'wc-uber-direct-connect' ); ?>
                                </label>
                            </div>
                        </div>

                        <div class="wcudc-form-row wcudc-form-row-half">
                            <div>
                                <label for="wcudc-store-lat"><?php esc_html_e( 'Latitud', 'wc-uber-direct-connect' ); ?> <span class="required">*</span></label>
                                <input type="text" id="wcudc-store-lat" name="latitude" required class="regular-text" placeholder="-12.0464">
                            </div>
                            <div>
                                <label for="wcudc-store-lng"><?php esc_html_e( 'Longitud', 'wc-uber-direct-connect' ); ?> <span class="required">*</span></label>
                                <input type="text" id="wcudc-store-lng" name="longitude" required class="regular-text" placeholder="-77.0428">
                            </div>
                        </div>

                        <div class="wcudc-form-row">
                            <label><?php esc_html_e( 'Zona de Reparto (Polígono)', 'wc-uber-direct-connect' ); ?> <span class="required">*</span></label>
                            <p class="description"><?php esc_html_e( 'Dibuja el polígono de la zona de reparto en el mapa. Haz clic en los vértices para crear la forma.', 'wc-uber-direct-connect' ); ?></p>
                            <div id="wcudc-map" class="wcudc-map"></div>
                            <input type="hidden" id="wcudc-store-polygon" name="polygon" value="">
                        </div>

                        <div class="wcudc-form-actions">
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar Tienda', 'wc-uber-direct-connect' ); ?></button>
                            <button type="button" class="button wcudc-modal-cancel"><?php esc_html_e( 'Cancelar', 'wc-uber-direct-connect' ); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // CRUD DE TIENDAS
    // =========================================================================

    /**
     * Obtiene todas las tiendas
     *
     * @return array
     */
    public function get_stores(): array {
        return get_option( self::STORES_OPTION, array() );
    }

    /**
     * Obtiene una tienda por ID
     *
     * @param string $store_id ID de la tienda.
     * @return array|null
     */
    public function get_store( string $store_id ): ?array {
        $stores = $this->get_stores();
        return $stores[ $store_id ] ?? null;
    }

    /**
     * Guarda una tienda (crear o actualizar)
     *
     * @param array $store_data Datos de la tienda.
     * @return array Tienda guardada con ID.
     */
    public function save_store( array $store_data ): array {
        $stores = $this->get_stores();

        // Generar ID si es nueva
        if ( empty( $store_data['id'] ) ) {
            $store_data['id'] = 'store_' . uniqid();
        }

        // Sanitizar datos
        $store = array(
            'id'        => sanitize_key( $store_data['id'] ),
            'name'      => sanitize_text_field( $store_data['name'] ?? '' ),
            'address'   => sanitize_text_field( $store_data['address'] ?? '' ),
            'phone'     => sanitize_text_field( $store_data['phone'] ?? '' ),
            'latitude'  => floatval( $store_data['latitude'] ?? 0 ),
            'longitude' => floatval( $store_data['longitude'] ?? 0 ),
            'active'    => (bool) ( $store_data['active'] ?? false ),
            'polygon'   => $this->sanitize_polygon( $store_data['polygon'] ?? array() ),
            'updated'   => current_time( 'mysql' ),
        );

        // Si es nueva, agregar fecha de creación
        if ( ! isset( $stores[ $store['id'] ] ) ) {
            $store['created'] = current_time( 'mysql' );
        } else {
            $store['created'] = $stores[ $store['id'] ]['created'] ?? current_time( 'mysql' );
        }

        $stores[ $store['id'] ] = $store;
        update_option( self::STORES_OPTION, $stores );

        return $store;
    }

    /**
     * Elimina una tienda
     *
     * @param string $store_id ID de la tienda.
     * @return bool
     */
    public function delete_store( string $store_id ): bool {
        $stores = $this->get_stores();

        if ( ! isset( $stores[ $store_id ] ) ) {
            return false;
        }

        unset( $stores[ $store_id ] );
        update_option( self::STORES_OPTION, $stores );

        return true;
    }

    /**
     * Sanitiza un array de polígono
     *
     * @param mixed $polygon Datos del polígono (array o JSON string).
     * @return array
     */
    private function sanitize_polygon( $polygon ): array {
        // Si viene como JSON string, decodificar
        if ( is_string( $polygon ) ) {
            $polygon = json_decode( $polygon, true );
        }

        if ( ! is_array( $polygon ) ) {
            return array();
        }

        $sanitized = array();
        foreach ( $polygon as $point ) {
            if ( isset( $point['lat'] ) && isset( $point['lng'] ) ) {
                $sanitized[] = array(
                    'lat' => floatval( $point['lat'] ),
                    'lng' => floatval( $point['lng'] ),
                );
            }
        }

        return $sanitized;
    }

    /**
     * Obtiene tiendas activas
     *
     * @return array
     */
    public function get_active_stores(): array {
        $stores = $this->get_stores();
        return array_filter( $stores, fn( $store ) => $store['active'] ?? false );
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Guardar tienda
     */
    public function ajax_save_store(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'wc-uber-direct-connect' ) ) );
        }

        $store_data = array(
            'id'        => sanitize_key( $_POST['store_id'] ?? '' ),
            'name'      => sanitize_text_field( $_POST['name'] ?? '' ),
            'address'   => sanitize_text_field( $_POST['address'] ?? '' ),
            'phone'     => sanitize_text_field( $_POST['phone'] ?? '' ),
            'latitude'  => floatval( $_POST['latitude'] ?? 0 ),
            'longitude' => floatval( $_POST['longitude'] ?? 0 ),
            'active'    => isset( $_POST['active'] ) && $_POST['active'] === '1',
            'polygon'   => $_POST['polygon'] ?? '',
        );

        // Validaciones
        if ( empty( $store_data['name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'El nombre es obligatorio.', 'wc-uber-direct-connect' ) ) );
        }

        if ( empty( $store_data['address'] ) ) {
            wp_send_json_error( array( 'message' => __( 'La dirección es obligatoria.', 'wc-uber-direct-connect' ) ) );
        }

        if ( $store_data['latitude'] === 0.0 || $store_data['longitude'] === 0.0 ) {
            wp_send_json_error( array( 'message' => __( 'Las coordenadas son obligatorias.', 'wc-uber-direct-connect' ) ) );
        }

        $store = $this->save_store( $store_data );

        wp_send_json_success( array(
            'message' => __( 'Tienda guardada correctamente.', 'wc-uber-direct-connect' ),
            'store'   => $store,
        ) );
    }

    /**
     * AJAX: Eliminar tienda
     */
    public function ajax_delete_store(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'wc-uber-direct-connect' ) ) );
        }

        $store_id = sanitize_key( $_POST['store_id'] ?? '' );

        if ( empty( $store_id ) ) {
            wp_send_json_error( array( 'message' => __( 'ID de tienda inválido.', 'wc-uber-direct-connect' ) ) );
        }

        if ( $this->delete_store( $store_id ) ) {
            wp_send_json_success( array( 'message' => __( 'Tienda eliminada.', 'wc-uber-direct-connect' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'No se pudo eliminar la tienda.', 'wc-uber-direct-connect' ) ) );
        }
    }

    /**
     * AJAX: Obtener una tienda
     */
    public function ajax_get_store(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'wc-uber-direct-connect' ) ) );
        }

        $store_id = sanitize_key( $_POST['store_id'] ?? '' );
        $store    = $this->get_store( $store_id );

        if ( $store ) {
            wp_send_json_success( array( 'store' => $store ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Tienda no encontrada.', 'wc-uber-direct-connect' ) ) );
        }
    }

    /**
     * AJAX: Obtener todas las tiendas
     */
    public function ajax_get_stores(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'wc-uber-direct-connect' ) ) );
        }

        wp_send_json_success( array( 'stores' => $this->get_stores() ) );
    }
}
