<?php
/**
 * Configuración de administración del plugin
 *
 * @package RestoHub
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase RestoHub_Admin_Settings
 *
 * Maneja la página de configuración del plugin en el admin de WordPress
 * Incluye gestión de tiendas con zonas de reparto por polígonos
 */
class RestoHub_Admin_Settings {

    /**
     * Slug de la página de opciones
     */
    private const MENU_SLUG = 'restohub-settings';

    /**
     * Grupo de opciones
     */
    private const OPTION_GROUP = 'restohub_settings_group';

    /**
     * Nombre de la opción en la BD (configuración general)
     */
    private const OPTION_NAME = 'restohub_settings';

    /**
     * Nonce para acciones AJAX
     */
    private const AJAX_NONCE = 'restohub_admin_nonce';

    /**
     * Repositorio de tiendas
     *
     * @var RestoHub_Store_Repository
     */
    private RestoHub_Store_Repository $store_repository;

    /**
     * Repositorio de cargos del checkout
     *
     * @var RestoHub_Checkout_Fees_Repository
     */
    private RestoHub_Checkout_Fees_Repository $fees_repository;

    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar repositorios
        $this->store_repository = restohub_store_repository();
        $this->fees_repository  = restohub_checkout_fees_repository();

        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_filter( 'plugin_action_links_' . RESTOHUB_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );

        // AJAX handlers para CRUD de tiendas
        add_action( 'wp_ajax_restohub_save_store', array( $this, 'ajax_save_store' ) );
        add_action( 'wp_ajax_restohub_delete_store', array( $this, 'ajax_delete_store' ) );
        add_action( 'wp_ajax_restohub_get_store', array( $this, 'ajax_get_store' ) );
        add_action( 'wp_ajax_restohub_get_stores', array( $this, 'ajax_get_stores' ) );

        // AJAX handler para pruebas de API
        add_action( 'wp_ajax_restohub_test_api', array( $this, 'ajax_test_api' ) );

        // AJAX handler para cargos del checkout
        add_action( 'wp_ajax_restohub_save_checkout_fees', array( $this, 'ajax_save_checkout_fees' ) );
    }

    /**
     * Agrega la página de menú en WooCommerce
     */
    public function add_menu_page(): void {
        add_menu_page(
            __( 'RestoHub', 'restohub' ),
            __( 'RestoHub', 'restohub' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( $this, 'render_settings_page' ),
            'dashicons-car',
            56
        );
    }

    /**
     * Encola assets para el admin (CSS, JS, Leaflet)
     *
     * @param string $hook Hook de la página actual.
     */
    public function enqueue_admin_assets( string $hook ): void {
        // Solo cargar en nuestra página
        if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
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
            'restohub-admin',
            RESTOHUB_PLUGIN_URL . 'assets/css/admin.css',
            array( 'leaflet', 'leaflet-draw' ),
            RESTOHUB_VERSION
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
            'restohub-admin',
            RESTOHUB_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'leaflet', 'leaflet-draw' ),
            RESTOHUB_VERSION,
            true
        );

        // Pasar datos al JS
        wp_localize_script( 'restohub-admin', 'restoHubAdmin', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( self::AJAX_NONCE ),
            'strings'   => array(
                'confirmDelete'  => __( '¿Estás seguro de eliminar esta tienda?', 'restohub' ),
                'saveSuccess'    => __( 'Tienda guardada correctamente.', 'restohub' ),
                'saveError'      => __( 'Error al guardar la tienda.', 'restohub' ),
                'deleteSuccess'  => __( 'Tienda eliminada.', 'restohub' ),
                'deleteError'    => __( 'Error al eliminar la tienda.', 'restohub' ),
                'drawPolygon'    => __( 'Dibuja un polígono en el mapa', 'restohub' ),
                'editPolygon'    => __( 'Editar polígono', 'restohub' ),
                'deletePolygon'  => __( 'Eliminar polígono', 'restohub' ),
                'noPolygon'      => __( 'Debes dibujar una zona de reparto.', 'restohub' ),
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
            __( 'Configuración', 'restohub' )
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
            'restohub_api_credentials',
            __( 'Credenciales de API', 'restohub' ),
            array( $this, 'render_api_section' ),
            self::MENU_SLUG
        );

        add_settings_field(
            'client_id',
            __( 'Client ID', 'restohub' ),
            array( $this, 'render_text_field' ),
            self::MENU_SLUG,
            'restohub_api_credentials',
            array( 'field' => 'client_id' )
        );

        add_settings_field(
            'client_secret',
            __( 'Client Secret', 'restohub' ),
            array( $this, 'render_password_field' ),
            self::MENU_SLUG,
            'restohub_api_credentials',
            array( 'field' => 'client_secret' )
        );

        add_settings_field(
            'customer_id',
            __( 'Customer ID', 'restohub' ),
            array( $this, 'render_text_field' ),
            self::MENU_SLUG,
            'restohub_api_credentials',
            array( 'field' => 'customer_id' )
        );

        add_settings_field(
            'webhook_secret',
            __( 'Webhook Secret', 'restohub' ),
            array( $this, 'render_password_field' ),
            self::MENU_SLUG,
            'restohub_api_credentials',
            array( 'field' => 'webhook_secret' )
        );

        add_settings_field(
            'sandbox_mode',
            __( 'Modo Sandbox', 'restohub' ),
            array( $this, 'render_checkbox_field' ),
            self::MENU_SLUG,
            'restohub_api_credentials',
            array(
                'field'       => 'sandbox_mode',
                'description' => __( 'Activar para usar el entorno de pruebas de Uber.', 'restohub' ),
            )
        );

        add_settings_field(
            'google_client_id',
            __( 'Google Client ID', 'restohub' ),
            array( $this, 'render_text_field' ),
            self::MENU_SLUG,
            'restohub_api_credentials',
            array(
                'field'       => 'google_client_id',
                'description' => __( 'Google Cloud Console > APIs & Services > Credentials > OAuth 2.0 Client ID. Usado para Google Sign-In en el checkout.', 'restohub' ),
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
        $sanitized['sandbox_mode']     = isset( $input['sandbox_mode'] ) ? 'yes' : 'no';
        $sanitized['google_client_id'] = sanitize_text_field( $input['google_client_id'] ?? '' );

        return $sanitized;
    }

    /**
     * Renderiza la descripción de la sección de API
     */
    public function render_api_section(): void {
        echo '<p>' . esc_html__( 'Ingresa las credenciales de tu aplicación de Uber Direct.', 'restohub' ) . '</p>';
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
            'api'      => __( 'Configuración API', 'restohub' ),
            'stores'   => __( 'Tiendas', 'restohub' ),
            'hours'    => __( 'Horarios', 'restohub' ),
            'advanced' => __( 'Opciones Avanzadas', 'restohub' ),
        );

        ?>
        <div class="wrap restohub-admin-wrap">
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

            <div class="restohub-tab-content">
                <?php
                switch ( $current_tab ) {
                    case 'stores':
                        $this->render_stores_tab();
                        break;
                    case 'hours':
                        $this->render_hours_tab();
                        break;
                    case 'advanced':
                        $this->render_advanced_tab();
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
        $webhook_url = add_query_arg( 'wc-api', 'restohub_uber_webhook', trailingslashit( home_url() ) );
        ?>
        <div class="notice notice-info">
            <p>
                <strong><?php esc_html_e( 'URL del Webhook:', 'restohub' ); ?></strong><br>
                <code><?php echo esc_url( $webhook_url ); ?></code>
            </p>
            <p class="description">
                <?php esc_html_e( 'Configura esta URL en el panel de desarrolladores de Uber para recibir actualizaciones de los deliveries.', 'restohub' ); ?>
            </p>
        </div>

        <form action="options.php" method="post">
            <?php
            settings_fields( self::OPTION_GROUP );
            do_settings_sections( self::MENU_SLUG );
            submit_button( __( 'Guardar Configuración', 'restohub' ) );
            ?>
        </form>

        <!-- Sección de Pruebas de API -->
        <div class="restohub-api-test-section" style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h2 style="margin-top: 0;"><?php esc_html_e( 'Pruebas de API', 'restohub' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Ejecuta pruebas para verificar la conexión con la API de Uber Direct. Esto probará la autenticación y obtendrá una cotización de prueba.', 'restohub' ); ?>
            </p>

            <div class="restohub-test-params" style="margin: 20px 0; padding: 15px; background: #f6f7f7; border-radius: 4px;">
                <h4 style="margin-top: 0;"><?php esc_html_e( 'Parámetros de Prueba (Opcional)', 'restohub' ); ?></h4>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e( 'Si tienes tiendas configuradas, se usará la primera tienda activa como origen. La dirección de destino es de prueba.', 'restohub' ); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="restohub-test-dropoff-address"><?php esc_html_e( 'Dirección de Destino', 'restohub' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="restohub-test-dropoff-address" class="regular-text" value="Mario Vergara 234, Maipú, Chile" placeholder="Dirección de prueba">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restohub-test-dropoff-lat"><?php esc_html_e( 'Latitud Destino', 'restohub' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="restohub-test-dropoff-lat" class="small-text" value="-33.5117" placeholder="-33.5117">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restohub-test-dropoff-lng"><?php esc_html_e( 'Longitud Destino', 'restohub' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="restohub-test-dropoff-lng" class="small-text" value="-70.7578" placeholder="-70.7578">
                        </td>
                    </tr>
                </table>
            </div>

            <button type="button" id="restohub-run-api-test" class="button button-primary button-hero">
                <?php esc_html_e( 'Ejecutar Pruebas de API', 'restohub' ); ?>
            </button>

            <div id="restohub-api-test-results" style="margin-top: 20px; display: none;">
                <!-- Los resultados se cargarán aquí via AJAX -->
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#restohub-run-api-test').on('click', function() {
                var $btn = $(this);
                var $results = $('#restohub-api-test-results');

                $btn.prop('disabled', true).text('<?php esc_html_e( 'Ejecutando pruebas...', 'restohub' ); ?>');
                $results.html('<p><span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span><?php esc_html_e( 'Conectando con Uber API...', 'restohub' ); ?></p>').show();

                $.ajax({
                    url: restoHubAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'restohub_test_api',
                        nonce: restoHubAdmin.nonce,
                        dropoff_address: $('#restohub-test-dropoff-address').val(),
                        dropoff_lat: $('#restohub-test-dropoff-lat').val(),
                        dropoff_lng: $('#restohub-test-dropoff-lng').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $results.html(response.data.html);
                        } else {
                            $results.html('<div class="notice notice-error"><p>' + (response.data || 'Error desconocido') + '</p></div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $results.html('<div class="notice notice-error"><p>Error de conexión: ' + error + '</p></div>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('<?php esc_html_e( 'Ejecutar Pruebas de API', 'restohub' ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Renderiza la pestaña de gestión de tiendas
     */
    private function render_stores_tab(): void {
        $stores = $this->store_repository->get_all();
        ?>
        <div class="restohub-stores-container">
            <!-- Lista de tiendas -->
            <div class="restohub-stores-list">
                <h2>
                    <?php esc_html_e( 'Tiendas', 'restohub' ); ?>
                    <button type="button" class="page-title-action" id="restohub-add-store">
                        <?php esc_html_e( 'Agregar Tienda', 'restohub' ); ?>
                    </button>
                </h2>

                <table class="wp-list-table widefat fixed striped" id="restohub-stores-table">
                    <thead>
                        <tr>
                            <th scope="col" class="column-name"><?php esc_html_e( 'Nombre', 'restohub' ); ?></th>
                            <th scope="col" class="column-address"><?php esc_html_e( 'Dirección', 'restohub' ); ?></th>
                            <th scope="col" class="column-status"><?php esc_html_e( 'Estado', 'restohub' ); ?></th>
                            <th scope="col" class="column-polygon"><?php esc_html_e( 'Zona', 'restohub' ); ?></th>
                            <th scope="col" class="column-actions"><?php esc_html_e( 'Acciones', 'restohub' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $stores ) ) : ?>
                            <tr class="no-stores">
                                <td colspan="5"><?php esc_html_e( 'No hay tiendas configuradas.', 'restohub' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $stores as $store ) : ?>
                                <tr data-store-id="<?php echo esc_attr( $store['id'] ); ?>">
                                    <td class="column-name">
                                        <strong><?php echo esc_html( $store['name'] ); ?></strong>
                                        <div class="row-actions">
                                            <span class="id"><?php printf( 'ID: %d', $store['id'] ); ?></span>
                                        </div>
                                    </td>
                                    <td class="column-address"><?php echo esc_html( $store['address'] ); ?></td>
                                    <td class="column-status">
                                        <?php if ( $store['is_active'] ) : ?>
                                            <span class="restohub-status restohub-status-active"><?php esc_html_e( 'Activa', 'restohub' ); ?></span>
                                        <?php else : ?>
                                            <span class="restohub-status restohub-status-inactive"><?php esc_html_e( 'Inactiva', 'restohub' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-polygon">
                                        <?php if ( ! empty( $store['polygon'] ) && count( $store['polygon'] ) >= 3 ) : ?>
                                            <span class="restohub-polygon-set"><?php echo count( $store['polygon'] ); ?> <?php esc_html_e( 'puntos', 'restohub' ); ?></span>
                                        <?php else : ?>
                                            <span class="restohub-polygon-missing"><?php esc_html_e( 'Sin zona', 'restohub' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-actions">
                                        <button type="button" class="button restohub-edit-store" data-id="<?php echo esc_attr( $store['id'] ); ?>">
                                            <?php esc_html_e( 'Editar', 'restohub' ); ?>
                                        </button>
                                        <button type="button" class="button button-link-delete restohub-delete-store" data-id="<?php echo esc_attr( $store['id'] ); ?>">
                                            <?php esc_html_e( 'Eliminar', 'restohub' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Modal/Formulario de edición de tienda -->
            <div id="restohub-store-modal" class="restohub-modal" style="display: none;">
                <div class="restohub-modal-content">
                    <span class="restohub-modal-close">&times;</span>
                    <h2 id="restohub-modal-title"><?php esc_html_e( 'Nueva Tienda', 'restohub' ); ?></h2>

                    <form id="restohub-store-form">
                        <input type="hidden" name="store_id" id="restohub-store-id" value="">

                        <div class="restohub-form-row">
                            <label for="restohub-store-name"><?php esc_html_e( 'Nombre de la Tienda', 'restohub' ); ?> <span class="required">*</span></label>
                            <input type="text" id="restohub-store-name" name="name" required class="regular-text">
                        </div>

                        <div class="restohub-form-row restohub-address-autocomplete-wrapper">
                            <label for="restohub-store-address"><?php esc_html_e( 'Dirección', 'restohub' ); ?> <span class="required">*</span></label>
                            <input type="text" id="restohub-store-address" name="address" required class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Escribe para buscar dirección...', 'restohub' ); ?>">
                            <div id="restohub-address-results" class="restohub-address-results" style="display: none;"></div>
                            <p class="description" style="margin-top: 5px;"><?php esc_html_e( 'Escribe la dirección y selecciona de la lista para autocompletar coordenadas.', 'restohub' ); ?></p>
                        </div>

                        <div class="restohub-form-row restohub-form-row-half">
                            <div>
                                <label for="restohub-store-phone"><?php esc_html_e( 'Teléfono', 'restohub' ); ?> <span class="required">*</span></label>
                                <input type="tel" id="restohub-store-phone" name="phone" required class="regular-text">
                            </div>
                            <div>
                                <label for="restohub-store-active">
                                    <input type="checkbox" id="restohub-store-active" name="active" value="1" checked>
                                    <?php esc_html_e( 'Tienda Activa', 'restohub' ); ?>
                                </label>
                            </div>
                        </div>

                        <div class="restohub-form-row restohub-form-row-half">
                            <div>
                                <label for="restohub-store-lat"><?php esc_html_e( 'Latitud', 'restohub' ); ?> <span class="required">*</span></label>
                                <input type="text" id="restohub-store-lat" name="latitude" required class="regular-text" placeholder="-12.0464">
                            </div>
                            <div>
                                <label for="restohub-store-lng"><?php esc_html_e( 'Longitud', 'restohub' ); ?> <span class="required">*</span></label>
                                <input type="text" id="restohub-store-lng" name="longitude" required class="regular-text" placeholder="-77.0428">
                            </div>
                        </div>

                        <div class="restohub-form-row">
                            <label><?php esc_html_e( 'Zona de Reparto (Polígono)', 'restohub' ); ?> <span class="required">*</span></label>
                            <p class="description"><?php esc_html_e( 'Dibuja el polígono de la zona de reparto en el mapa. Haz clic en los vértices para crear la forma.', 'restohub' ); ?></p>
                            <div id="restohub-map" class="restohub-map"></div>
                            <input type="hidden" id="restohub-store-polygon" name="polygon" value="">
                        </div>

                        <div class="restohub-form-actions">
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar Tienda', 'restohub' ); ?></button>
                            <button type="button" class="button restohub-modal-cancel"><?php esc_html_e( 'Cancelar', 'restohub' ); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php $this->render_checkout_fees_section(); ?>
        <?php
    }

    /**
     * Renderiza la sección de cargos del checkout en la pestaña Tiendas
     */
    private function render_checkout_fees_section(): void {
        $service_fee = $this->fees_repository->get_service_fee( 0 );
        $tip_options = $this->fees_repository->get_tip_options( 0 );
        $tips_active = ! empty( $tip_options );

        $sf_label      = $service_fee['label'] ?? 'Cuota de servicio';
        $sf_percentage = $service_fee['percentage'] ?? '5.00';
        $sf_active     = ! empty( $service_fee['is_active'] );
        ?>
        <div class="restohub-section" style="margin-top: 30px;">
            <div class="restohub-section-header">
                <h2><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Cargos del Checkout', 'restohub' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Configura la cuota de servicio y las opciones de propina que se muestran en el checkout.', 'restohub' ); ?>
                </p>
            </div>

            <div id="restohub-checkout-fees-form" style="padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <!-- Cuota de servicio -->
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Cuota de Servicio', 'restohub' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="restohub-sf-active"><?php esc_html_e( 'Activa', 'restohub' ); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="restohub-sf-active" value="1" <?php checked( $sf_active ); ?>>
                                <?php esc_html_e( 'Cobrar cuota de servicio en el checkout', 'restohub' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restohub-sf-label"><?php esc_html_e( 'Etiqueta', 'restohub' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="restohub-sf-label" class="regular-text" value="<?php echo esc_attr( $sf_label ); ?>">
                            <p class="description"><?php esc_html_e( 'Texto que verá el cliente en el resumen del pedido.', 'restohub' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restohub-sf-percentage"><?php esc_html_e( 'Porcentaje', 'restohub' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="restohub-sf-percentage" class="small-text" value="<?php echo esc_attr( $sf_percentage ); ?>" step="0.01" min="0" max="100"> %
                            <p class="description"><?php esc_html_e( 'Se aplica sobre (subtotal + envío).', 'restohub' ); ?></p>
                        </td>
                    </tr>
                </table>

                <hr style="margin: 20px 0;">

                <!-- Propina -->
                <h3><?php esc_html_e( 'Propina', 'restohub' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="restohub-tip-active"><?php esc_html_e( 'Activa', 'restohub' ); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="restohub-tip-active" value="1" <?php checked( $tips_active ); ?>>
                                <?php esc_html_e( 'Mostrar selector de propina en el checkout', 'restohub' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Opciones', 'restohub' ); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e( 'Opciones fijas: 0%, 5%, 10%, 15% (se aplica sobre el subtotal).', 'restohub' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="button" id="restohub-save-checkout-fees" class="button button-primary">
                        <?php esc_html_e( 'Guardar Cargos', 'restohub' ); ?>
                    </button>
                    <span id="restohub-fees-save-status" style="margin-left: 10px;"></span>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Guardar configuración de cargos del checkout
     */
    public function ajax_save_checkout_fees(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'restohub' ) ) );
        }

        // Cuota de servicio
        $sf_data = array(
            'label'      => sanitize_text_field( $_POST['sf_label'] ?? 'Cuota de servicio' ),
            'percentage' => floatval( $_POST['sf_percentage'] ?? 0 ),
            'is_active'  => ! empty( $_POST['sf_active'] ),
        );

        $this->fees_repository->update_service_fee( $sf_data );

        // Propinas: activar/desactivar
        $tips_active = ! empty( $_POST['tip_active'] );
        $this->fees_repository->set_tips_active( $tips_active );

        wp_send_json_success( array(
            'message' => __( 'Cargos del checkout guardados correctamente.', 'restohub' ),
        ) );
    }

    /**
     * Renderiza la pestaña de horarios
     */
    private function render_hours_tab(): void {
        $settings      = get_option( self::OPTION_NAME, array() );
        $hours         = $settings['store_hours'] ?? array();
        $special_hours = $settings['special_hours'] ?? array();

        // Procesar formulario de programación de pedidos
        if ( isset( $_POST['restohub_save_scheduling'] ) && check_admin_referer( 'restohub_save_scheduling_nonce' ) ) {
            $settings['scheduling_enabled']      = ! empty( $_POST['scheduling_enabled'] );
            $settings['scheduling_slot_interval'] = absint( $_POST['scheduling_slot_interval'] ?? 30 );
            $settings['scheduling_lead_time']     = absint( $_POST['scheduling_lead_time'] ?? 45 );
            $settings['scheduling_max_days']      = absint( $_POST['scheduling_max_days'] ?? 1 );
            $settings['scheduling_prep_buffer']   = absint( $_POST['scheduling_prep_buffer'] ?? 15 );

            update_option( self::OPTION_NAME, $settings );

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configuración de programación guardada.', 'restohub' ) . '</p></div>';
        }

        $days = array(
            'lunes'     => __( 'Lunes', 'restohub' ),
            'martes'    => __( 'Martes', 'restohub' ),
            'miercoles' => __( 'Miércoles', 'restohub' ),
            'jueves'    => __( 'Jueves', 'restohub' ),
            'viernes'   => __( 'Viernes', 'restohub' ),
            'sabado'    => __( 'Sábado', 'restohub' ),
            'domingo'   => __( 'Domingo', 'restohub' ),
        );

        // Procesar formulario de horarios regulares
        if ( isset( $_POST['restohub_save_hours'] ) && check_admin_referer( 'restohub_save_hours_nonce' ) ) {
            $new_hours = array();
            foreach ( $days as $day_key => $day_name ) {
                $is_closed = ! empty( $_POST['closed'][ $day_key ] ) && $_POST['closed'][ $day_key ] === '1';
                $new_hours[ $day_key ] = array(
                    'open'   => $is_closed ? '' : sanitize_text_field( $_POST['open'][ $day_key ] ?? '' ),
                    'close'  => $is_closed ? '' : sanitize_text_field( $_POST['close'][ $day_key ] ?? '' ),
                    'closed' => $is_closed,
                );
            }

            $settings['store_hours'] = $new_hours;
            update_option( self::OPTION_NAME, $settings );
            $hours = $new_hours;

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Horarios guardados correctamente.', 'restohub' ) . '</p></div>';
        }

        // Procesar formulario de horarios especiales
        if ( isset( $_POST['restohub_save_special_hours'] ) && check_admin_referer( 'restohub_save_special_hours_nonce' ) ) {
            $new_special_hours = array();

            if ( ! empty( $_POST['special_date'] ) && is_array( $_POST['special_date'] ) ) {
                foreach ( $_POST['special_date'] as $index => $date ) {
                    $date = sanitize_text_field( $date );

                    // Ignorar fechas vacías
                    if ( empty( $date ) ) {
                        continue;
                    }

                    $is_closed = isset( $_POST['special_closed'][ $index ] );

                    $new_special_hours[] = array(
                        'date'        => $date,
                        'name'        => sanitize_text_field( $_POST['special_name'][ $index ] ?? '' ),
                        'open'        => $is_closed ? '' : sanitize_text_field( $_POST['special_open'][ $index ] ?? '' ),
                        'close'       => $is_closed ? '' : sanitize_text_field( $_POST['special_close'][ $index ] ?? '' ),
                        'closed'      => $is_closed,
                        'repeat_yearly' => isset( $_POST['special_repeat'][ $index ] ),
                    );
                }
            }

            // Ordenar por fecha
            usort( $new_special_hours, function( $a, $b ) {
                return strcmp( $a['date'], $b['date'] );
            } );

            $settings['special_hours'] = $new_special_hours;
            update_option( self::OPTION_NAME, $settings );
            $special_hours = $new_special_hours;

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Horarios especiales guardados correctamente.', 'restohub' ) . '</p></div>';
        }

        // Procesar eliminación de fecha especial
        if ( isset( $_POST['restohub_delete_special'] ) && check_admin_referer( 'restohub_save_special_hours_nonce' ) ) {
            $delete_index = absint( $_POST['restohub_delete_special'] );

            if ( isset( $special_hours[ $delete_index ] ) ) {
                unset( $special_hours[ $delete_index ] );
                $special_hours = array_values( $special_hours ); // Reindexar

                $settings['special_hours'] = $special_hours;
                update_option( self::OPTION_NAME, $settings );

                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Fecha especial eliminada.', 'restohub' ) . '</p></div>';
            }
        }
        ?>
        <!-- Horario Regular -->
        <div class="restohub-section">
            <div class="restohub-section-header">
                <h2><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Horario de Atención', 'restohub' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Configura el horario de atención de tu tienda. Si la tienda está cerrada, los clientes no podrán realizar pedidos.', 'restohub' ); ?>
                </p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field( 'restohub_save_hours_nonce' ); ?>

                <div class="restohub-schedule-grid">
                    <?php foreach ( $days as $day_key => $day_name ) :
                        $day_hours = $hours[ $day_key ] ?? array( 'open' => '09:00', 'close' => '21:00', 'closed' => false );
                        $is_closed = ! empty( $day_hours['closed'] );
                    ?>
                        <div class="restohub-day-card <?php echo $is_closed ? 'is-closed' : ''; ?>">
                            <div class="restohub-day-header">
                                <span class="restohub-day-name"><?php echo esc_html( $day_name ); ?></span>
                                <label class="restohub-toggle">
                                    <input type="checkbox"
                                           class="restohub-open-toggle"
                                           data-day="<?php echo esc_attr( $day_key ); ?>"
                                           <?php checked( ! $is_closed ); ?>>
                                    <span class="restohub-toggle-slider"></span>
                                </label>
                                <input type="hidden"
                                       name="closed[<?php echo esc_attr( $day_key ); ?>]"
                                       class="restohub-closed-hidden"
                                       value="<?php echo $is_closed ? '1' : '0'; ?>">
                            </div>
                            <div class="restohub-day-times">
                                <div class="restohub-time-field">
                                    <label><?php esc_html_e( 'Apertura', 'restohub' ); ?></label>
                                    <input type="time"
                                           name="open[<?php echo esc_attr( $day_key ); ?>]"
                                           value="<?php echo esc_attr( $day_hours['open'] ?? '09:00' ); ?>">
                                </div>
                                <span class="restohub-time-separator">&ndash;</span>
                                <div class="restohub-time-field">
                                    <label><?php esc_html_e( 'Cierre', 'restohub' ); ?></label>
                                    <input type="time"
                                           name="close[<?php echo esc_attr( $day_key ); ?>]"
                                           value="<?php echo esc_attr( $day_hours['close'] ?? '21:00' ); ?>">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="restohub-section-footer">
                    <button type="submit" name="restohub_save_hours" class="button button-primary">
                        <?php esc_html_e( 'Guardar Horarios', 'restohub' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Horarios Especiales -->
        <div class="restohub-section">
            <div class="restohub-section-header">
                <h2><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Horarios Especiales', 'restohub' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Configura horarios especiales para días festivos, feriados o eventos. Estos tienen prioridad sobre los horarios regulares.', 'restohub' ); ?>
                </p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field( 'restohub_save_special_hours_nonce' ); ?>

                <table class="wp-list-table widefat fixed striped restohub-special-hours-table" id="restohub-special-hours-table">
                    <thead>
                        <tr>
                            <th class="column-date"><?php esc_html_e( 'Fecha', 'restohub' ); ?></th>
                            <th class="column-name"><?php esc_html_e( 'Nombre/Motivo', 'restohub' ); ?></th>
                            <th class="column-open"><?php esc_html_e( 'Apertura', 'restohub' ); ?></th>
                            <th class="column-close"><?php esc_html_e( 'Cierre', 'restohub' ); ?></th>
                            <th class="column-closed"><?php esc_html_e( 'Cerrado', 'restohub' ); ?></th>
                            <th class="column-repeat"><?php esc_html_e( 'Repetir', 'restohub' ); ?></th>
                            <th class="column-actions"><?php esc_html_e( 'Acciones', 'restohub' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="restohub-special-hours-body">
                        <?php if ( empty( $special_hours ) ) : ?>
                            <tr class="no-special-hours">
                                <td colspan="7"><?php esc_html_e( 'No hay horarios especiales configurados.', 'restohub' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $special_hours as $index => $special ) :
                                $is_closed = ! empty( $special['closed'] );
                                $is_past   = strtotime( $special['date'] ) < strtotime( 'today' ) && empty( $special['repeat_yearly'] );
                            ?>
                                <tr class="<?php echo $is_past ? 'restohub-past-date' : ''; ?>">
                                    <td class="column-date">
                                        <input type="date"
                                               name="special_date[<?php echo esc_attr( $index ); ?>]"
                                               value="<?php echo esc_attr( $special['date'] ); ?>"
                                               required
                                               class="restohub-date-input">
                                    </td>
                                    <td class="column-name">
                                        <input type="text"
                                               name="special_name[<?php echo esc_attr( $index ); ?>]"
                                               value="<?php echo esc_attr( $special['name'] ?? '' ); ?>"
                                               placeholder="<?php esc_attr_e( 'Ej: Navidad', 'restohub' ); ?>"
                                               class="regular-text">
                                    </td>
                                    <td class="column-open">
                                        <input type="time"
                                               name="special_open[<?php echo esc_attr( $index ); ?>]"
                                               value="<?php echo esc_attr( $special['open'] ?? '09:00' ); ?>"
                                               class="restohub-time-input restohub-special-time"
                                               <?php echo $is_closed ? 'disabled' : ''; ?>>
                                    </td>
                                    <td class="column-close">
                                        <input type="time"
                                               name="special_close[<?php echo esc_attr( $index ); ?>]"
                                               value="<?php echo esc_attr( $special['close'] ?? '21:00' ); ?>"
                                               class="restohub-time-input restohub-special-time"
                                               <?php echo $is_closed ? 'disabled' : ''; ?>>
                                    </td>
                                    <td class="column-closed">
                                        <label>
                                            <input type="checkbox"
                                                   name="special_closed[<?php echo esc_attr( $index ); ?>]"
                                                   value="1"
                                                   class="restohub-special-closed-checkbox"
                                                   <?php checked( $is_closed ); ?>>
                                        </label>
                                    </td>
                                    <td class="column-repeat">
                                        <label title="<?php esc_attr_e( 'Repetir cada año', 'restohub' ); ?>">
                                            <input type="checkbox"
                                                   name="special_repeat[<?php echo esc_attr( $index ); ?>]"
                                                   value="1"
                                                   <?php checked( ! empty( $special['repeat_yearly'] ) ); ?>>
                                            <span class="dashicons dashicons-update"></span>
                                        </label>
                                    </td>
                                    <td class="column-actions">
                                        <button type="submit"
                                                name="restohub_delete_special"
                                                value="<?php echo esc_attr( $index ); ?>"
                                                class="button button-link-delete"
                                                onclick="return confirm('<?php esc_attr_e( '¿Eliminar esta fecha especial?', 'restohub' ); ?>');">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="restohub-add-row">
                            <td colspan="7">
                                <button type="button" class="button" id="restohub-add-special-hour">
                                    <span class="dashicons dashicons-plus-alt2"></span>
                                    <?php esc_html_e( 'Agregar Fecha Especial', 'restohub' ); ?>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <div class="restohub-section-footer">
                    <button type="submit" name="restohub_save_special_hours" class="button button-primary">
                        <?php esc_html_e( 'Guardar Horarios Especiales', 'restohub' ); ?>
                    </button>
                </div>
            </form>

            <!-- Template para nueva fila (Special Hours) -->
            <template id="restohub-special-hour-template">
                <tr>
                    <td class="column-date">
                        <input type="date" name="special_date[__INDEX__]" required class="restohub-date-input">
                    </td>
                    <td class="column-name">
                        <input type="text" name="special_name[__INDEX__]" placeholder="<?php esc_attr_e( 'Ej: Navidad', 'restohub' ); ?>" class="regular-text">
                    </td>
                    <td class="column-open">
                        <input type="time" name="special_open[__INDEX__]" value="09:00" class="restohub-time-input restohub-special-time">
                    </td>
                    <td class="column-close">
                        <input type="time" name="special_close[__INDEX__]" value="21:00" class="restohub-time-input restohub-special-time">
                    </td>
                    <td class="column-closed">
                        <label>
                            <input type="checkbox" name="special_closed[__INDEX__]" value="1" class="restohub-special-closed-checkbox">
                        </label>
                    </td>
                    <td class="column-repeat">
                        <label title="<?php esc_attr_e( 'Repetir cada año', 'restohub' ); ?>">
                            <input type="checkbox" name="special_repeat[__INDEX__]" value="1">
                            <span class="dashicons dashicons-update"></span>
                        </label>
                    </td>
                    <td class="column-actions">
                        <button type="button" class="button button-link-delete restohub-remove-special-row">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>
            </template>
        </div>

        <!-- =================================================================
             Sección 3: Programar Pedidos
             ================================================================= -->
        <div class="restohub-section">
            <h2>
                <span class="dashicons dashicons-calendar-alt" style="margin-right: 8px;"></span>
                <?php esc_html_e( 'Programar Pedidos', 'restohub' ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'Permite a los clientes programar entregas y retiros para un horario futuro. Cuando la tienda está cerrada, solo se permite programar.', 'restohub' ); ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field( 'restohub_save_scheduling_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Activar programación', 'restohub' ); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="scheduling_enabled"
                                       value="1"
                                       <?php checked( ! isset( $settings['scheduling_enabled'] ) || ! empty( $settings['scheduling_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Permitir a los clientes programar entregas y retiros', 'restohub' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="scheduling_slot_interval">
                                <?php esc_html_e( 'Intervalo de franjas', 'restohub' ); ?>
                            </label>
                        </th>
                        <td>
                            <select name="scheduling_slot_interval" id="scheduling_slot_interval">
                                <?php
                                $interval = (int) ( $settings['scheduling_slot_interval'] ?? 30 );
                                foreach ( array( 15, 30, 60 ) as $opt ) :
                                    ?>
                                    <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $interval, $opt ); ?>>
                                        <?php echo esc_html( $opt . ' ' . __( 'minutos', 'restohub' ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Cada cuántos minutos se genera una franja horaria disponible.', 'restohub' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="scheduling_lead_time">
                                <?php esc_html_e( 'Tiempo mínimo de anticipación', 'restohub' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number"
                                   name="scheduling_lead_time"
                                   id="scheduling_lead_time"
                                   value="<?php echo esc_attr( $settings['scheduling_lead_time'] ?? 45 ); ?>"
                                   min="15"
                                   max="180"
                                   step="5"
                                   class="small-text">
                            <?php esc_html_e( 'minutos', 'restohub' ); ?>
                            <p class="description">
                                <?php esc_html_e( 'Minutos mínimos entre el momento actual y la primera franja disponible para hoy.', 'restohub' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="scheduling_max_days">
                                <?php esc_html_e( 'Días de anticipación', 'restohub' ); ?>
                            </label>
                        </th>
                        <td>
                            <select name="scheduling_max_days" id="scheduling_max_days">
                                <?php
                                $max_days = (int) ( $settings['scheduling_max_days'] ?? 1 );
                                ?>
                                <option value="0" <?php selected( $max_days, 0 ); ?>>
                                    <?php esc_html_e( 'Solo hoy', 'restohub' ); ?>
                                </option>
                                <option value="1" <?php selected( $max_days, 1 ); ?>>
                                    <?php esc_html_e( 'Hoy + Mañana', 'restohub' ); ?>
                                </option>
                                <option value="2" <?php selected( $max_days, 2 ); ?>>
                                    <?php esc_html_e( 'Hoy + 2 días', 'restohub' ); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="scheduling_prep_buffer">
                                <?php esc_html_e( 'Buffer de preparación (Uber)', 'restohub' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number"
                                   name="scheduling_prep_buffer"
                                   id="scheduling_prep_buffer"
                                   value="<?php echo esc_attr( $settings['scheduling_prep_buffer'] ?? 15 ); ?>"
                                   min="5"
                                   max="60"
                                   step="5"
                                   class="small-text">
                            <?php esc_html_e( 'minutos', 'restohub' ); ?>
                            <p class="description">
                                <?php esc_html_e( 'Minutos antes del horario programado para solicitar el delivery a Uber.', 'restohub' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="restohub-section-footer">
                    <button type="submit" name="restohub_save_scheduling" class="button button-primary">
                        <?php esc_html_e( 'Guardar Configuración de Programación', 'restohub' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Toggle switch para horarios regulares
            // Toggle ON = Abierto, Toggle OFF = Cerrado
            $('.restohub-open-toggle').on('change', function() {
                var $card = $(this).closest('.restohub-day-card');
                var $hiddenInput = $card.find('.restohub-closed-hidden');

                if ($(this).is(':checked')) {
                    // Toggle ON = Abierto
                    $card.removeClass('is-closed');
                    $hiddenInput.val('0');
                } else {
                    // Toggle OFF = Cerrado
                    $card.addClass('is-closed');
                    $hiddenInput.val('1');
                }
            });

            // Toggle de inputs cuando se marca "Cerrado" en horarios especiales
            $(document).on('change', '.restohub-special-closed-checkbox', function() {
                var $row = $(this).closest('tr');
                var $inputs = $row.find('.restohub-special-time');

                if ($(this).is(':checked')) {
                    $inputs.prop('disabled', true).css('opacity', '0.5');
                } else {
                    $inputs.prop('disabled', false).css('opacity', '1');
                }
            });

            // Agregar nueva fila de horario especial
            var specialIndex = <?php echo count( $special_hours ); ?>;

            $('#restohub-add-special-hour').on('click', function() {
                var template = $('#restohub-special-hour-template').html();
                template = template.replace(/__INDEX__/g, specialIndex);

                // Remover fila de "No hay horarios especiales" si existe
                $('#restohub-special-hours-body .no-special-hours').remove();

                // Agregar nueva fila
                $('#restohub-special-hours-body').append(template);
                specialIndex++;

                // Enfocar el input de fecha
                $('#restohub-special-hours-body tr:last .restohub-date-input').focus();
            });

            // Eliminar fila de horario especial (nueva, no guardada)
            $(document).on('click', '.restohub-remove-special-row', function() {
                $(this).closest('tr').remove();

                // Si no quedan filas, mostrar mensaje
                if ($('#restohub-special-hours-body tr').length === 0) {
                    $('#restohub-special-hours-body').append(
                        '<tr class="no-special-hours"><td colspan="7"><?php echo esc_js( __( 'No hay horarios especiales configurados.', 'restohub' ) ); ?></td></tr>'
                    );
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Renderiza la pestaña de opciones avanzadas
     */
    private function render_advanced_tab(): void {
        $settings = get_option( self::OPTION_NAME, array() );

        // Procesar formulario
        if ( isset( $_POST['restohub_save_advanced'] ) && check_admin_referer( 'restohub_save_advanced_nonce' ) ) {
            // Registro de clientes
            $settings['send_welcome_email'] = ! empty( $_POST['send_welcome_email'] ) ? 'yes' : 'no';

            // Logística Uber Direct
            $settings['uber_prep_time']   = min( 120, max( 1, absint( $_POST['uber_prep_time'] ?? 20 ) ) );
            $settings['uber_max_retries'] = min( 10,  max( 1, absint( $_POST['uber_max_retries'] ?? 3 ) ) );

            // Correos de alerta: sanitizar cada dirección individualmente.
            $raw_alert_emails   = sanitize_text_field( wp_unslash( $_POST['uber_alert_emails'] ?? '' ) );
            $validated_emails   = array_filter(
                array_map( 'trim', explode( ',', $raw_alert_emails ) ),
                'is_email'
            );
            $settings['uber_alert_emails'] = implode( ', ', $validated_emails );

            update_option( self::OPTION_NAME, $settings );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Opciones avanzadas guardadas.', 'restohub' ) . '</p></div>';
        }

        $send_welcome_email = ( $settings['send_welcome_email'] ?? 'yes' ) === 'yes';
        ?>
        <!-- Registro de Clientes -->
        <div class="restohub-section">
            <div class="restohub-section-header">
                <h2><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Registro de Clientes', 'restohub' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Opciones para el modal de registro del checkout.', 'restohub' ); ?>
                </p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field( 'restohub_save_advanced_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Email de Bienvenida', 'restohub' ); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="send_welcome_email"
                                       value="1"
                                       <?php checked( $send_welcome_email ); ?>>
                                <?php esc_html_e( 'Enviar email de bienvenida al registrar un nuevo cliente', 'restohub' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Cuando está activo, se envía un correo de bienvenida al cliente al crear su cuenta desde el modal de registro del checkout.', 'restohub' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td colspan="2"><hr style="margin: 5px 0;"></td>
                    </tr>
                    <!-- ── Logística Uber Direct ────────────────────────────── -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h3 style="margin: 10px 0 5px; display:flex; align-items:center; gap:8px;">
                                <span class="dashicons dashicons-car"></span>
                                <?php esc_html_e( 'Logística Uber Direct', 'restohub' ); ?>
                            </h3>
                            <p class="description">
                                <?php esc_html_e( 'Parámetros del Motor Logístico: tiempo de preparación, reintentos automáticos y correos de alerta crítica.', 'restohub' ); ?>
                            </p>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="uber_prep_time">
                                <?php esc_html_e( 'Tiempo de preparación (min)', 'restohub' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number"
                                   name="uber_prep_time"
                                   id="uber_prep_time"
                                   value="<?php echo esc_attr( $settings['uber_prep_time'] ?? 20 ); ?>"
                                   min="1"
                                   max="120"
                                   step="1"
                                   class="small-text">
                            <p class="description">
                                <?php esc_html_e( 'Minutos desde el pago hasta que el pedido estará listo para el repartidor (pickup_ready_dt). Se aplica a pedidos ASAP; los pedidos programados usan su propio cálculo de slot.', 'restohub' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="uber_max_retries">
                                <?php esc_html_e( 'Reintentos máximos', 'restohub' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number"
                                   name="uber_max_retries"
                                   id="uber_max_retries"
                                   value="<?php echo esc_attr( $settings['uber_max_retries'] ?? 3 ); ?>"
                                   min="1"
                                   max="10"
                                   step="1"
                                   class="small-text">
                            <p class="description">
                                <?php esc_html_e( 'Número máximo de reintentos automáticos (cada 5 min) si Uber devuelve "sin repartidores disponibles" o un error 5xx. Al agotarse, el pedido pasa a "En espera" y se envían las alertas.', 'restohub' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="uber_alert_emails">
                                <?php esc_html_e( 'Correos de alerta crítica', 'restohub' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   name="uber_alert_emails"
                                   id="uber_alert_emails"
                                   value="<?php echo esc_attr( $settings['uber_alert_emails'] ?? '' ); ?>"
                                   class="large-text"
                                   placeholder="ops@restaurante.cl, gerente@restaurante.cl">
                            <p class="description">
                                <?php esc_html_e( 'Correos separados por coma que recibirán la alerta cuando los reintentos se agoten. El administrador del sitio siempre recibe una copia. Las direcciones inválidas se descartan al guardar.', 'restohub' ); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <div class="restohub-section-footer">
                    <button type="submit" name="restohub_save_advanced" class="button button-primary">
                        <?php esc_html_e( 'Guardar Opciones Avanzadas', 'restohub' ); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // MÉTODOS HELPER PARA TIENDAS (delegados al repositorio)
    // =========================================================================

    /**
     * Obtiene todas las tiendas
     *
     * @return array
     */
    public function get_stores(): array {
        return $this->store_repository->get_all();
    }

    /**
     * Obtiene una tienda por ID
     *
     * @param int $store_id ID de la tienda.
     * @return array|null
     */
    public function get_store( int $store_id ): ?array {
        return $this->store_repository->get( $store_id );
    }

    /**
     * Obtiene tiendas activas
     *
     * @return array
     */
    public function get_active_stores(): array {
        return $this->store_repository->get_active();
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Guardar tienda (crear o actualizar)
     */
    public function ajax_save_store(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'restohub' ) ) );
        }

        // ID puede venir vacío (crear) o con valor (actualizar)
        $store_id = ! empty( $_POST['store_id'] ) ? absint( $_POST['store_id'] ) : 0;

        // Preparar datos
        // IMPORTANTE: wp_unslash() es necesario porque WordPress agrega slashes
        // automáticamente a $_POST, lo que corrompe el JSON del polígono
        $polygon_raw = isset( $_POST['polygon'] ) ? wp_unslash( $_POST['polygon'] ) : '';

        $store_data = array(
            'name'         => sanitize_text_field( $_POST['name'] ?? '' ),
            'address'      => sanitize_textarea_field( $_POST['address'] ?? '' ),
            'phone'        => sanitize_text_field( $_POST['phone'] ?? '' ),
            'latitude'     => floatval( $_POST['latitude'] ?? 0 ),
            'longitude'    => floatval( $_POST['longitude'] ?? 0 ),
            'is_active'    => isset( $_POST['active'] ) && $_POST['active'] === '1',
            'polygon_data' => $polygon_raw,
        );

        // Validaciones
        if ( empty( $store_data['name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'El nombre es obligatorio.', 'restohub' ) ) );
        }

        if ( empty( $store_data['address'] ) ) {
            wp_send_json_error( array( 'message' => __( 'La dirección es obligatoria.', 'restohub' ) ) );
        }

        if ( $store_data['latitude'] === 0.0 || $store_data['longitude'] === 0.0 ) {
            wp_send_json_error( array( 'message' => __( 'Las coordenadas son obligatorias.', 'restohub' ) ) );
        }

        // Crear o actualizar
        if ( $store_id > 0 ) {
            // Actualizar existente
            $result = $this->store_repository->update( $store_id, $store_data );

            if ( ! $result ) {
                wp_send_json_error( array( 'message' => __( 'Error al actualizar la tienda.', 'restohub' ) ) );
            }

            $store = $this->store_repository->get( $store_id );
        } else {
            // Crear nueva
            $new_id = $this->store_repository->create( $store_data );

            if ( ! $new_id ) {
                wp_send_json_error( array( 'message' => __( 'Error al crear la tienda.', 'restohub' ) ) );
            }

            $store = $this->store_repository->get( $new_id );
        }

        wp_send_json_success( array(
            'message' => __( 'Tienda guardada correctamente.', 'restohub' ),
            'store'   => $store,
        ) );
    }

    /**
     * AJAX: Eliminar tienda
     */
    public function ajax_delete_store(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'restohub' ) ) );
        }

        $store_id = absint( $_POST['store_id'] ?? 0 );

        if ( $store_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'ID de tienda inválido.', 'restohub' ) ) );
        }

        if ( $this->store_repository->delete( $store_id ) ) {
            wp_send_json_success( array( 'message' => __( 'Tienda eliminada.', 'restohub' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'No se pudo eliminar la tienda.', 'restohub' ) ) );
        }
    }

    /**
     * AJAX: Obtener una tienda
     */
    public function ajax_get_store(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'restohub' ) ) );
        }

        $store_id = absint( $_POST['store_id'] ?? 0 );
        $store    = $this->store_repository->get( $store_id );

        if ( $store ) {
            wp_send_json_success( array( 'store' => $store ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Tienda no encontrada.', 'restohub' ) ) );
        }
    }

    /**
     * AJAX: Obtener todas las tiendas
     */
    public function ajax_get_stores(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'restohub' ) ) );
        }

        wp_send_json_success( array( 'stores' => $this->store_repository->get_all() ) );
    }

    /**
     * AJAX handler para probar la API de Uber
     */
    public function ajax_test_api(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'restohub' ) );
        }

        // Obtener parámetros de prueba
        $test_params = array();

        $dropoff_address = sanitize_text_field( wp_unslash( $_POST['dropoff_address'] ?? '' ) );
        $dropoff_lat     = floatval( $_POST['dropoff_lat'] ?? -33.5117 );
        $dropoff_lng     = floatval( $_POST['dropoff_lng'] ?? -70.7578 );

        if ( ! empty( $dropoff_address ) ) {
            $test_params['dropoff'] = array(
                'address'   => $dropoff_address,
                'latitude'  => $dropoff_lat,
                'longitude' => $dropoff_lng,
            );
        }

        // Ejecutar pruebas
        $tester  = restohub_api_tester();
        $results = $tester->run_all_tests( $test_params );
        $html    = $tester->get_results_html();

        wp_send_json_success( array(
            'html'    => $html,
            'results' => $results,
        ) );
    }
}
