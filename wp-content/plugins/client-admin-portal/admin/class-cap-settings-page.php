<?php
/**
 * Admin Settings Page
 *
 * Handles the settings page UI for Client Admin Portal configuration.
 *
 * @package Client_Admin_Portal
 * @since   2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CAP_Settings_Page
 *
 * Renders and handles the admin settings page.
 */
class CAP_Settings_Page {

    /**
     * Menu slug.
     */
    const MENU_SLUG = 'cap-settings';

    /**
     * Required capability.
     */
    const CAPABILITY = 'manage_options';

    /**
     * Settings instance.
     *
     * @var CAP_Settings
     */
    private CAP_Settings $settings;

    /**
     * Branding instance.
     *
     * @var CAP_Branding
     */
    private CAP_Branding $branding;

    /**
     * Module registry instance.
     *
     * @var CAP_Module_Registry
     */
    private CAP_Module_Registry $modules;

    /**
     * Access control instance.
     *
     * @var CAP_Access_Control
     */
    private CAP_Access_Control $access;

    /**
     * Audit log instance.
     *
     * @var CAP_Audit_Log
     */
    private CAP_Audit_Log $audit;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->settings = cap_settings();
        $this->branding = cap_branding();
        $this->modules  = cap_modules();
        $this->access   = cap_access();
        $this->audit    = cap_audit();
    }

    /**
     * Initialize hooks.
     */
    public function init(): void {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'handle_form_submissions' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_cap_save_branding', array( $this, 'ajax_save_branding' ) );
        add_action( 'wp_ajax_cap_save_module', array( $this, 'ajax_save_module' ) );
        add_action( 'wp_ajax_cap_save_module_access', array( $this, 'ajax_save_module_access' ) );
        add_action( 'wp_ajax_cap_export_settings', array( $this, 'ajax_export_settings' ) );
        add_action( 'wp_ajax_cap_export_audit_log', array( $this, 'ajax_export_audit_log' ) );
        add_action( 'wp_ajax_cap_get_audit_logs', array( $this, 'ajax_get_audit_logs' ) );
        add_action( 'wp_ajax_cap_get_branding_settings', array( $this, 'ajax_get_branding_settings' ) );
        add_action( 'wp_ajax_cap_save_login_redirect', array( $this, 'ajax_save_login_redirect' ) );

        // Module management AJAX handlers.
        add_action( 'wp_ajax_cap_add_module', array( $this, 'ajax_add_module' ) );
        add_action( 'wp_ajax_cap_update_module', array( $this, 'ajax_update_module' ) );
        add_action( 'wp_ajax_cap_delete_module', array( $this, 'ajax_delete_module' ) );
        add_action( 'wp_ajax_cap_get_module', array( $this, 'ajax_get_module' ) );
    }

    /**
     * Add menu page.
     */
    public function add_menu_page(): void {
        add_menu_page(
            __( 'Client Portal', 'client-admin-portal' ),
            __( 'Client Portal', 'client-admin-portal' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_page' ),
            'dashicons-admin-customizer',
            3
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Configuración', 'client-admin-portal' ),
            __( 'Configuración', 'client-admin-portal' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue assets.
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
            return;
        }

        // WordPress color picker.
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        // Media uploader.
        wp_enqueue_media();

        // Settings page CSS.
        wp_enqueue_style(
            'cap-settings-page',
            CAP_PLUGIN_URL . 'assets/css/settings-page.css',
            array( 'wp-color-picker' ),
            CAP_VERSION
        );

        // Settings page JS.
        wp_enqueue_script(
            'cap-settings-page',
            CAP_PLUGIN_URL . 'assets/js/settings-page.js',
            array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ),
            CAP_VERSION,
            true
        );

        // Localize script.
        wp_localize_script( 'cap-settings-page', 'capSettings', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'cap_settings_nonce' ),
            'strings'   => array(
                'saved'         => __( 'Guardado correctamente', 'client-admin-portal' ),
                'error'         => __( 'Error al guardar', 'client-admin-portal' ),
                'confirmReset'  => __( '¿Estás seguro de restablecer a los valores predeterminados?', 'client-admin-portal' ),
                'confirmDelete' => __( '¿Estás seguro de eliminar este módulo?', 'client-admin-portal' ),
                'selectImage'   => __( 'Seleccionar imagen', 'client-admin-portal' ),
                'useImage'      => __( 'Usar esta imagen', 'client-admin-portal' ),
                'loading'       => __( 'Cargando...', 'client-admin-portal' ),
            ),
            'presets'   => $this->branding->get_presets(),
        ) );
    }

    /**
     * Handle form submissions.
     */
    public function handle_form_submissions(): void {
        // Handle import.
        if ( isset( $_POST['cap_import_settings'] ) && isset( $_FILES['cap_import_file'] ) ) {
            $this->handle_import();
        }
    }

    /**
     * Render settings page.
     */
    public function render_page(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'client-admin-portal' ) );
        }

        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'branding';

        $tabs = array(
            'branding' => array(
                'title' => __( 'Branding', 'client-admin-portal' ),
                'icon'  => 'dashicons-admin-appearance',
            ),
            'modules'  => array(
                'title' => __( 'Módulos', 'client-admin-portal' ),
                'icon'  => 'dashicons-screenoptions',
            ),
            'access'   => array(
                'title' => __( 'Acceso', 'client-admin-portal' ),
                'icon'  => 'dashicons-lock',
            ),
            'audit'    => array(
                'title' => __( 'Auditoría', 'client-admin-portal' ),
                'icon'  => 'dashicons-list-view',
            ),
            'import_export' => array(
                'title' => __( 'Importar/Exportar', 'client-admin-portal' ),
                'icon'  => 'dashicons-download',
            ),
        );

        ?>
        <div class="wrap cap-settings-wrap">
            <h1>
                <span class="dashicons dashicons-admin-customizer"></span>
                <?php esc_html_e( 'Client Admin Portal', 'client-admin-portal' ); ?>
            </h1>

            <nav class="nav-tab-wrapper cap-tabs">
                <?php foreach ( $tabs as $tab_id => $tab ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $tab_id ) ); ?>"
                       class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                        <?php echo esc_html( $tab['title'] ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="cap-settings-content">
                <?php
                switch ( $current_tab ) {
                    case 'modules':
                        $this->render_modules_tab();
                        break;
                    case 'access':
                        $this->render_access_tab();
                        break;
                    case 'audit':
                        $this->render_audit_tab();
                        break;
                    case 'import_export':
                        $this->render_import_export_tab();
                        break;
                    default:
                        $this->render_branding_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render branding tab.
     */
    private function render_branding_tab(): void {
        include CAP_PLUGIN_DIR . 'admin/views/settings-branding.php';
    }

    /**
     * Render modules tab.
     */
    private function render_modules_tab(): void {
        include CAP_PLUGIN_DIR . 'admin/views/settings-modules.php';
    }

    /**
     * Render access tab.
     */
    private function render_access_tab(): void {
        include CAP_PLUGIN_DIR . 'admin/views/settings-access.php';
    }

    /**
     * Render audit tab.
     */
    private function render_audit_tab(): void {
        include CAP_PLUGIN_DIR . 'admin/views/settings-audit.php';
    }

    /**
     * Render import/export tab.
     */
    private function render_import_export_tab(): void {
        include CAP_PLUGIN_DIR . 'admin/views/settings-import-export.php';
    }

    /**
     * AJAX: Save branding settings.
     */
    public function ajax_save_branding(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $role_slug = isset( $_POST['role_slug'] ) && ! empty( $_POST['role_slug'] )
            ? sanitize_key( $_POST['role_slug'] )
            : null;

        $settings = isset( $_POST['settings'] ) ? $_POST['settings'] : array();

        // Sanitize settings.
        $sanitized = array();
        foreach ( $settings as $key => $value ) {
            $key = sanitize_key( $key );

            // Skip empty values for role-specific settings (will inherit from global).
            if ( null !== $role_slug && '' === $value ) {
                continue;
            }

            $sanitized[ $key ] = $value; // Further sanitization in branding->save()
        }

        // Get old values for audit.
        $old_values = $this->settings->get_group( 'branding', $role_slug );

        // Save.
        $result = $this->branding->save( $sanitized, $role_slug );

        if ( $result ) {
            // Log changes.
            foreach ( $sanitized as $key => $value ) {
                $old_value = $old_values[ $key ] ?? null;
                if ( $old_value !== $value ) {
                    $this->audit->log_branding_change( $key, $old_value, $value, $role_slug ?? 'global' );
                }
            }

            wp_send_json_success( array( 'message' => __( 'Configuración guardada.', 'client-admin-portal' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error al guardar.', 'client-admin-portal' ) ) );
        }
    }

    /**
     * AJAX: Save module settings.
     */
    public function ajax_save_module(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $module_slug = isset( $_POST['module_slug'] ) ? sanitize_key( $_POST['module_slug'] ) : '';

        // Handle is_active from various sources (string 'true'/'false', '1'/'0', or actual boolean).
        $is_active_raw = isset( $_POST['is_active'] ) ? $_POST['is_active'] : false;
        $is_active     = filter_var( $is_active_raw, FILTER_VALIDATE_BOOLEAN );

        if ( empty( $module_slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Módulo inválido.', 'client-admin-portal' ) ) );
        }

        $result = $this->modules->set_active( $module_slug, $is_active );

        if ( $result ) {
            $this->audit->log_module_toggle( $module_slug, $is_active );
            wp_send_json_success( array(
                'message'   => __( 'Módulo actualizado.', 'client-admin-portal' ),
                'is_active' => $is_active,
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error al actualizar módulo.', 'client-admin-portal' ) ) );
        }
    }

    /**
     * AJAX: Save module access.
     */
    public function ajax_save_module_access(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $module_slug = isset( $_POST['module_slug'] ) ? sanitize_key( $_POST['module_slug'] ) : '';
        $role_slug   = isset( $_POST['role_slug'] ) ? sanitize_key( $_POST['role_slug'] ) : '';
        $can_view    = isset( $_POST['can_view'] ) && $_POST['can_view'] === 'true';

        if ( empty( $module_slug ) || empty( $role_slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Datos inválidos.', 'client-admin-portal' ) ) );
        }

        $result = $this->modules->set_role_access( $module_slug, $role_slug, $can_view );

        if ( $result ) {
            $this->audit->log_module_access( $module_slug, $role_slug, $can_view );
            wp_send_json_success( array( 'message' => __( 'Acceso actualizado.', 'client-admin-portal' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error al actualizar acceso.', 'client-admin-portal' ) ) );
        }
    }

    /**
     * AJAX: Export settings.
     */
    public function ajax_export_settings(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $data = array(
            'version'  => CAP_VERSION,
            'exported' => gmdate( 'Y-m-d H:i:s' ),
            'site_url' => get_site_url(),
            'settings' => $this->settings->export_all(),
            'modules'  => $this->modules->get_all(),
            'access'   => $this->modules->get_access_matrix(),
        );

        $this->audit->log_export( 'settings' );

        wp_send_json_success( array(
            'filename' => 'cap-settings-' . gmdate( 'Y-m-d' ) . '.json',
            'data'     => $data,
        ) );
    }

    /**
     * AJAX: Export audit log.
     */
    public function ajax_export_audit_log(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $args = array();

        if ( isset( $_POST['date_from'] ) && ! empty( $_POST['date_from'] ) ) {
            $args['date_from'] = sanitize_text_field( $_POST['date_from'] ) . ' 00:00:00';
        }

        if ( isset( $_POST['date_to'] ) && ! empty( $_POST['date_to'] ) ) {
            $args['date_to'] = sanitize_text_field( $_POST['date_to'] ) . ' 23:59:59';
        }

        if ( isset( $_POST['action_filter'] ) && ! empty( $_POST['action_filter'] ) ) {
            $args['action'] = sanitize_key( $_POST['action_filter'] );
        }

        $csv = $this->audit->export_csv( $args );

        $this->audit->log_export( 'audit_log' );

        wp_send_json_success( array(
            'filename' => 'cap-audit-log-' . gmdate( 'Y-m-d' ) . '.csv',
            'data'     => $csv,
        ) );
    }

    /**
     * AJAX: Get audit logs.
     */
    public function ajax_get_audit_logs(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $args = array(
            'limit'  => isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50,
            'offset' => isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0,
        );

        if ( isset( $_POST['search'] ) && ! empty( $_POST['search'] ) ) {
            $args['search'] = sanitize_text_field( $_POST['search'] );
        }

        if ( isset( $_POST['action_filter'] ) && ! empty( $_POST['action_filter'] ) ) {
            $args['action'] = sanitize_key( $_POST['action_filter'] );
        }

        if ( isset( $_POST['date_from'] ) && ! empty( $_POST['date_from'] ) ) {
            $args['date_from'] = sanitize_text_field( $_POST['date_from'] ) . ' 00:00:00';
        }

        if ( isset( $_POST['date_to'] ) && ! empty( $_POST['date_to'] ) ) {
            $args['date_to'] = sanitize_text_field( $_POST['date_to'] ) . ' 23:59:59';
        }

        $logs  = $this->audit->query( $args );
        $total = $this->audit->count( $args );

        wp_send_json_success( array(
            'logs'  => $logs,
            'total' => $total,
        ) );
    }

    /**
     * AJAX: Save login redirect for a role.
     */
    public function ajax_save_login_redirect(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $role_slug = isset( $_POST['role_slug'] ) ? sanitize_key( $_POST['role_slug'] ) : '';
        $redirect  = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';

        if ( empty( $role_slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Rol inválido.', 'client-admin-portal' ) ) );
        }

        $result = $this->access->set_login_redirect( $role_slug, $redirect );

        if ( $result ) {
            wp_send_json_success( array( 'message' => __( 'Guardado.', 'client-admin-portal' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error al guardar.', 'client-admin-portal' ) ) );
        }
    }

    /**
     * AJAX: Get branding settings for a role (or global).
     */
    public function ajax_get_branding_settings(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $role_slug = isset( $_POST['role_slug'] ) && ! empty( $_POST['role_slug'] )
            ? sanitize_key( $_POST['role_slug'] )
            : null;

        // Get branding settings — role-specific values merged with global fallback.
        $settings = $this->settings->get_group( 'branding', $role_slug );

        // Fill any missing keys with hardcoded defaults.
        $settings = wp_parse_args( $settings, $this->branding->get_defaults() );

        // Check if this role has any explicit overrides stored in DB.
        $has_overrides = false;
        if ( $role_slug ) {
            global $wpdb;
            $table         = $wpdb->prefix . 'cap_settings';
            $has_overrides = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE setting_group = 'branding' AND role_slug = %s",
                    $role_slug
                )
            ) > 0;
        }

        wp_send_json_success( array(
            'settings'      => $settings,
            'has_overrides' => $has_overrides,
            'role_slug'     => $role_slug,
        ) );
    }

    /**
     * Handle settings import.
     */
    private function handle_import(): void {
        if ( ! wp_verify_nonce( $_POST['cap_import_nonce'] ?? '', 'cap_import_settings' ) ) {
            wp_die( esc_html__( 'Nonce inválido.', 'client-admin-portal' ) );
        }

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'client-admin-portal' ) );
        }

        $file = $_FILES['cap_import_file'];

        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            add_settings_error( 'cap_import', 'upload_error', __( 'Error al subir el archivo.', 'client-admin-portal' ) );
            return;
        }

        $content = file_get_contents( $file['tmp_name'] );
        $data    = json_decode( $content, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            add_settings_error( 'cap_import', 'json_error', __( 'Archivo JSON inválido.', 'client-admin-portal' ) );
            return;
        }

        $replace = isset( $_POST['cap_import_replace'] ) && $_POST['cap_import_replace'] === '1';

        $count = 0;

        // Import settings.
        if ( isset( $data['settings'] ) ) {
            $count += $this->settings->import_all( $data['settings'], $replace );
        }

        $this->audit->log_import( 'settings', $count );

        add_settings_error(
            'cap_import',
            'import_success',
            sprintf( __( 'Importación completada. %d configuraciones importadas.', 'client-admin-portal' ), $count ),
            'success'
        );
    }

    /**
     * AJAX: Add a manual module.
     */
    public function ajax_add_module(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $data = array(
            'name'          => isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '',
            'description'   => isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '',
            'menu_slug'     => isset( $_POST['menu_slug'] ) ? sanitize_text_field( $_POST['menu_slug'] ) : '',
            'plugin_source' => isset( $_POST['plugin_source'] ) ? sanitize_text_field( $_POST['plugin_source'] ) : __( 'Manual', 'client-admin-portal' ),
            'capability'    => isset( $_POST['capability'] ) ? sanitize_key( $_POST['capability'] ) : 'manage_options',
            'icon'          => isset( $_POST['icon'] ) ? sanitize_text_field( $_POST['icon'] ) : 'dashicons-admin-generic',
            'sort_order'    => isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 50,
        );

        if ( empty( $data['name'] ) || empty( $data['menu_slug'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Nombre y URL del menú son requeridos.', 'client-admin-portal' ) ) );
        }

        $result = $this->modules->register_manual( $data );

        if ( $result ) {
            $this->audit->log( 'module_create', 'manual_module', null, wp_json_encode( $data ) );
            wp_send_json_success( array(
                'message'   => __( 'Módulo agregado correctamente.', 'client-admin-portal' ),
                'module_id' => $result,
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error al agregar módulo. Puede que ya exista.', 'client-admin-portal' ) ) );
        }
    }

    /**
     * AJAX: Update an existing module.
     */
    public function ajax_update_module(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Módulo inválido.', 'client-admin-portal' ) ) );
        }

        // Get old values for audit.
        $old_module = $this->modules->get( $slug );

        $data = array(
            'name'        => isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '',
            'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '',
            'menu_slug'   => isset( $_POST['menu_slug'] ) ? sanitize_text_field( $_POST['menu_slug'] ) : '',
            'icon'        => isset( $_POST['icon'] ) ? sanitize_text_field( $_POST['icon'] ) : '',
            'sort_order'  => isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 50,
        );

        $result = $this->modules->update( $slug, $data );

        if ( $result ) {
            $this->audit->log(
                'module_update',
                $slug,
                wp_json_encode( $old_module ),
                wp_json_encode( $data )
            );
            wp_send_json_success( array( 'message' => __( 'Módulo actualizado.', 'client-admin-portal' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error al actualizar módulo.', 'client-admin-portal' ) ) );
        }
    }

    /**
     * AJAX: Delete a module.
     */
    public function ajax_delete_module(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Módulo inválido.', 'client-admin-portal' ) ) );
        }

        // Get module info for audit.
        $module = $this->modules->get( $slug );

        if ( ! $module ) {
            wp_send_json_error( array( 'message' => __( 'Módulo no encontrado.', 'client-admin-portal' ) ) );
        }

        // Don't allow deleting core modules.
        if ( ! empty( $module['is_core'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No se pueden eliminar módulos del sistema.', 'client-admin-portal' ) ) );
        }

        $result = $this->modules->delete( $slug );

        if ( $result ) {
            $this->audit->log( 'module_delete', $slug, wp_json_encode( $module ), null );
            wp_send_json_success( array( 'message' => __( 'Módulo eliminado.', 'client-admin-portal' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error al eliminar módulo.', 'client-admin-portal' ) ) );
        }
    }

    /**
     * AJAX: Get module details.
     */
    public function ajax_get_module(): void {
        check_ajax_referer( 'cap_settings_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'client-admin-portal' ) ) );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Módulo inválido.', 'client-admin-portal' ) ) );
        }

        $module = $this->modules->get( $slug );

        if ( ! $module ) {
            wp_send_json_error( array( 'message' => __( 'Módulo no encontrado.', 'client-admin-portal' ) ) );
        }

        wp_send_json_success( array( 'module' => $module ) );
    }
}
