<?php
/**
 * Sistema de Recopilación de Clientes Invitados
 *
 * Almacena datos de clientes que compran como invitados para
 * análisis y marketing posterior.
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase ChurrascoPlanet_Guest_Tracker
 *
 * Rastrea y almacena información de clientes invitados y registrados
 */
class ChurrascoPlanet_Guest_Tracker {

    /**
     * Nombre de la tabla de clientes
     */
    const TABLE_NAME = 'chp_customers';

    /**
     * Instancia única
     *
     * @var ChurrascoPlanet_Guest_Tracker|null
     */
    private static ?ChurrascoPlanet_Guest_Tracker $instance = null;

    /**
     * Obtener instancia única
     *
     * @return ChurrascoPlanet_Guest_Tracker
     */
    public static function get_instance(): ChurrascoPlanet_Guest_Tracker {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Inicializar hooks
     */
    private function init_hooks(): void {
        // Capturar datos del cliente al crear pedido
        add_action('woocommerce_checkout_order_created', [$this, 'track_customer_from_order'], 10, 1);

        // Capturar datos al registrar usuario (WordPress general)
        add_action('user_register', [$this, 'track_registered_customer'], 10, 1);

        // Capturar registro desde WooCommerce My Account (formulario de registro)
        add_action('woocommerce_created_customer', [$this, 'track_woocommerce_registration'], 10, 3);

        // Capturar registro desde checkout con crear cuenta
        add_action('woocommerce_checkout_update_user_meta', [$this, 'track_checkout_registration'], 10, 2);

        // Capturar login social (Nextend)
        add_action('nsl_registration', [$this, 'track_social_registration'], 10, 3);
        add_action('nsl_login', [$this, 'track_social_login'], 10, 2);

        // Actualizar después de completar pedido
        add_action('woocommerce_order_status_completed', [$this, 'update_customer_stats'], 10, 1);

        // Actualizar también con otros estados de pedido completado
        add_action('woocommerce_order_status_processing', [$this, 'update_customer_stats'], 10, 1);

        // Hook para WooCommerce cuando un invitado crea cuenta después de comprar
        add_action('woocommerce_created_customer', [$this, 'link_guest_to_account'], 10, 3);

        // Capturar checkbox de marketing en checkout
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_marketing_preference'], 10, 2);
    }

    /**
     * Obtener nombre completo de la tabla
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Crear tabla de clientes
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            email VARCHAR(200) NOT NULL,
            first_name VARCHAR(100) DEFAULT '',
            last_name VARCHAR(100) DEFAULT '',
            phone VARCHAR(50) DEFAULT '',
            whatsapp VARCHAR(50) DEFAULT '',
            customer_type ENUM('guest', 'registered', 'social_facebook', 'social_google', 'social_apple') DEFAULT 'guest',
            registration_source VARCHAR(50) DEFAULT 'checkout',
            total_orders INT(11) UNSIGNED DEFAULT 0,
            total_spent DECIMAL(19,4) DEFAULT 0.0000,
            last_order_id BIGINT(20) UNSIGNED DEFAULT NULL,
            last_order_date DATETIME DEFAULT NULL,
            preferred_store_id BIGINT(20) UNSIGNED DEFAULT NULL,
            accepts_marketing TINYINT(1) DEFAULT 0,
            accepts_whatsapp TINYINT(1) DEFAULT 0,
            notes TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            user_agent TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY user_id (user_id),
            KEY customer_type (customer_type),
            KEY last_order_date (last_order_date),
            KEY total_orders (total_orders)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Capturar datos del cliente desde un pedido
     *
     * @param WC_Order $order Pedido de WooCommerce.
     */
    public function track_customer_from_order(WC_Order $order): void {
        $email = $order->get_billing_email();
        if (empty($email)) {
            return;
        }

        $customer_id = $order->get_customer_id();
        $is_guest = empty($customer_id);

        $data = [
            'email'         => sanitize_email($email),
            'first_name'    => sanitize_text_field($order->get_billing_first_name()),
            'last_name'     => sanitize_text_field($order->get_billing_last_name()),
            'phone'         => sanitize_text_field($order->get_billing_phone()),
            'customer_type' => $is_guest ? 'guest' : $this->get_customer_type($customer_id),
            'user_id'       => $customer_id ?: null,
            'ip_address'    => $order->get_customer_ip_address(),
            'user_agent'    => $order->get_customer_user_agent(),
        ];

        // Verificar si acepta marketing (checkbox en checkout)
        $accepts_marketing = $order->get_meta('_chp_accepts_marketing');
        if ($accepts_marketing !== '') {
            $data['accepts_marketing'] = absint($accepts_marketing);
        }

        $this->upsert_customer($data);
    }

    /**
     * Capturar datos al registrar usuario normal
     *
     * @param int $user_id ID del usuario.
     */
    public function track_registered_customer(int $user_id): void {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        // Evitar duplicados si viene de WooCommerce (se maneja en track_woocommerce_registration)
        if (did_action('woocommerce_created_customer')) {
            return;
        }

        $data = [
            'email'              => $user->user_email,
            'first_name'         => $user->first_name,
            'last_name'          => $user->last_name,
            'customer_type'      => 'registered',
            'user_id'            => $user_id,
            'registration_source'=> 'wordpress_registration',
            'ip_address'         => $this->get_client_ip(),
        ];

        $this->upsert_customer($data);

        // Guardar meta de origen
        update_user_meta($user_id, 'chp_registration_source', 'normal');
        update_user_meta($user_id, 'chp_registration_date', current_time('mysql'));
    }

    /**
     * Capturar registro desde WooCommerce (formulario Mi Cuenta)
     *
     * @param int    $customer_id        ID del cliente.
     * @param array  $new_customer_data  Datos del nuevo cliente.
     * @param string $password_generated Contraseña generada.
     */
    public function track_woocommerce_registration(int $customer_id, array $new_customer_data, string $password_generated): void {
        $user = get_userdata($customer_id);
        if (!$user) {
            return;
        }

        // Determinar fuente de registro
        $registration_source = 'woocommerce_myaccount';
        if (is_checkout()) {
            $registration_source = 'woocommerce_checkout';
        }

        $data = [
            'email'              => $user->user_email,
            'first_name'         => $user->first_name ?: ($new_customer_data['first_name'] ?? ''),
            'last_name'          => $user->last_name ?: ($new_customer_data['last_name'] ?? ''),
            'customer_type'      => 'registered',
            'user_id'            => $customer_id,
            'registration_source'=> $registration_source,
            'ip_address'         => $this->get_client_ip(),
        ];

        $this->upsert_customer($data);

        // Guardar meta de origen
        update_user_meta($customer_id, 'chp_registration_source', 'normal');
        update_user_meta($customer_id, 'chp_registration_date', current_time('mysql'));
        update_user_meta($customer_id, 'chp_notification_email', '1'); // Activar notificaciones por defecto
    }

    /**
     * Capturar registro desde checkout con crear cuenta
     *
     * @param int   $customer_id ID del cliente.
     * @param array $data        Datos del checkout.
     */
    public function track_checkout_registration(int $customer_id, array $data): void {
        // Solo procesar si es un nuevo registro (primera vez que se actualiza meta)
        $already_tracked = get_user_meta($customer_id, 'chp_registration_date', true);
        if ($already_tracked) {
            return;
        }

        $user = get_userdata($customer_id);
        if (!$user) {
            return;
        }

        $customer_data = [
            'email'              => $user->user_email,
            'first_name'         => $data['billing_first_name'] ?? $user->first_name,
            'last_name'          => $data['billing_last_name'] ?? $user->last_name,
            'phone'              => $data['billing_phone'] ?? '',
            'customer_type'      => 'registered',
            'user_id'            => $customer_id,
            'registration_source'=> 'woocommerce_checkout',
            'ip_address'         => $this->get_client_ip(),
        ];

        $this->upsert_customer($customer_data);

        // Guardar metas
        update_user_meta($customer_id, 'chp_registration_source', 'normal');
        update_user_meta($customer_id, 'chp_registration_date', current_time('mysql'));
    }

    /**
     * Guardar preferencia de marketing desde checkout
     *
     * @param int   $order_id ID del pedido.
     * @param array $data     Datos del checkout.
     */
    public function save_marketing_preference(int $order_id, array $data): void {
        $accepts_marketing = isset($_POST['chp_accepts_marketing']) ? 1 : 0;

        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_chp_accepts_marketing', $accepts_marketing);
            $order->save();
        }
    }

    /**
     * Capturar registro via login social (Nextend)
     *
     * @param int    $user_id  ID del usuario.
     * @param string $provider Proveedor (facebook, google, etc).
     * @param mixed  $data_raw Datos del proveedor.
     */
    public function track_social_registration(int $user_id, string $provider, $data_raw): void {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $customer_type = 'social_' . strtolower($provider);
        if (!in_array($customer_type, ['social_facebook', 'social_google', 'social_apple'])) {
            $customer_type = 'registered';
        }

        $data = [
            'email'              => $user->user_email,
            'first_name'         => $user->first_name,
            'last_name'          => $user->last_name,
            'customer_type'      => $customer_type,
            'user_id'            => $user_id,
            'registration_source'=> 'social_' . strtolower($provider),
            'ip_address'         => $this->get_client_ip(),
        ];

        $this->upsert_customer($data);

        // Guardar también en user meta
        update_user_meta($user_id, 'chp_registration_source', $customer_type);
    }

    /**
     * Capturar login via social (actualizar última actividad)
     *
     * @param int    $user_id  ID del usuario.
     * @param string $provider Proveedor.
     */
    public function track_social_login(int $user_id, string $provider): void {
        global $wpdb;

        $table = self::get_table_name();
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        // Solo actualizar si ya existe
        $wpdb->update(
            $table,
            [
                'updated_at' => current_time('mysql'),
                'ip_address' => $this->get_client_ip(),
            ],
            ['email' => $user->user_email],
            ['%s', '%s'],
            ['%s']
        );
    }

    /**
     * Actualizar estadísticas del cliente después de completar pedido
     *
     * @param int $order_id ID del pedido.
     */
    public function update_customer_stats(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $email = $order->get_billing_email();
        if (empty($email)) {
            return;
        }

        global $wpdb;
        $table = self::get_table_name();

        // Obtener registro actual
        $customer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE email = %s",
                $email
            ),
            ARRAY_A
        );

        if (!$customer) {
            // Crear si no existe
            $this->track_customer_from_order($order);
            $customer = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE email = %s",
                    $email
                ),
                ARRAY_A
            );
        }

        if ($customer) {
            $wpdb->update(
                $table,
                [
                    'total_orders'   => absint($customer['total_orders']) + 1,
                    'total_spent'    => floatval($customer['total_spent']) + floatval($order->get_total()),
                    'last_order_id'  => $order_id,
                    'last_order_date'=> $order->get_date_created()->format('Y-m-d H:i:s'),
                ],
                ['id' => $customer['id']],
                ['%d', '%f', '%d', '%s'],
                ['%d']
            );
        }
    }

    /**
     * Vincular cliente invitado a cuenta recién creada
     *
     * @param int    $customer_id ID del cliente.
     * @param array  $new_customer_data Datos del nuevo cliente.
     * @param string $password_generated Contraseña generada.
     */
    public function link_guest_to_account(int $customer_id, array $new_customer_data, string $password_generated): void {
        if (empty($new_customer_data['user_email'])) {
            return;
        }

        global $wpdb;
        $table = self::get_table_name();

        // Actualizar registro de invitado a registrado
        $wpdb->update(
            $table,
            [
                'user_id'       => $customer_id,
                'customer_type' => 'registered',
            ],
            ['email' => $new_customer_data['user_email']],
            ['%d', '%s'],
            ['%s']
        );
    }

    /**
     * Insertar o actualizar cliente
     *
     * @param array $data Datos del cliente.
     * @return int|false ID del registro o false en error.
     */
    public function upsert_customer(array $data) {
        global $wpdb;
        $table = self::get_table_name();

        if (empty($data['email'])) {
            return false;
        }

        $email = sanitize_email($data['email']);

        // Verificar si existe
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE email = %s",
                $email
            )
        );

        if ($existing) {
            // Actualizar
            unset($data['email']); // No actualizar email
            unset($data['created_at']);

            if (!empty($data)) {
                $wpdb->update(
                    $table,
                    $data,
                    ['id' => $existing]
                );
            }

            return (int) $existing;
        } else {
            // Insertar
            $data['email'] = $email;
            $data['created_at'] = current_time('mysql');

            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Obtener cliente por email
     *
     * @param string $email Email del cliente.
     * @return array|null
     */
    public function get_customer_by_email(string $email): ?array {
        global $wpdb;
        $table = self::get_table_name();

        $customer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE email = %s",
                sanitize_email($email)
            ),
            ARRAY_A
        );

        return $customer ?: null;
    }

    /**
     * Obtener cliente por ID de usuario
     *
     * @param int $user_id ID del usuario.
     * @return array|null
     */
    public function get_customer_by_user_id(int $user_id): ?array {
        global $wpdb;
        $table = self::get_table_name();

        $customer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d",
                $user_id
            ),
            ARRAY_A
        );

        return $customer ?: null;
    }

    /**
     * Obtener todos los clientes con filtros
     *
     * @param array $args Argumentos de filtro.
     * @return array
     */
    public function get_customers(array $args = []): array {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = [
            'customer_type'    => '',
            'has_orders'       => null,
            'accepts_marketing'=> null,
            'orderby'          => 'created_at',
            'order'            => 'DESC',
            'limit'            => 50,
            'offset'           => 0,
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $prepare_args = [];

        if (!empty($args['customer_type'])) {
            $where[] = 'customer_type = %s';
            $prepare_args[] = $args['customer_type'];
        }

        if ($args['has_orders'] !== null) {
            if ($args['has_orders']) {
                $where[] = 'total_orders > 0';
            } else {
                $where[] = 'total_orders = 0';
            }
        }

        if ($args['accepts_marketing'] !== null) {
            $where[] = 'accepts_marketing = %d';
            $prepare_args[] = $args['accepts_marketing'] ? 1 : 0;
        }

        $where_clause = implode(' AND ', $where);

        $allowed_orderby = ['created_at', 'updated_at', 'total_orders', 'total_spent', 'last_order_date', 'email'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $limit = absint($args['limit']);
        $offset = absint($args['offset']);

        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT {$limit} OFFSET {$offset}";

        if (!empty($prepare_args)) {
            $sql = $wpdb->prepare($sql, ...$prepare_args);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Obtener conteos por tipo de cliente
     *
     * @return array
     */
    public function get_customer_counts(): array {
        global $wpdb;
        $table = self::get_table_name();

        $results = $wpdb->get_results(
            "SELECT customer_type, COUNT(*) as count FROM {$table} GROUP BY customer_type",
            ARRAY_A
        );

        $counts = [
            'guest'           => 0,
            'registered'      => 0,
            'social_facebook' => 0,
            'social_google'   => 0,
            'social_apple'    => 0,
            'total'           => 0,
        ];

        foreach ($results as $row) {
            $counts[$row['customer_type']] = absint($row['count']);
            $counts['total'] += absint($row['count']);
        }

        return $counts;
    }

    /**
     * Obtener clientes invitados que podrían convertirse
     *
     * @param int $min_orders Mínimo de pedidos.
     * @return array
     */
    public function get_convertible_guests(int $min_orders = 2): array {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE customer_type = 'guest'
                AND user_id IS NULL
                AND total_orders >= %d
                ORDER BY total_spent DESC",
                $min_orders
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Obtener tipo de cliente basado en user meta
     *
     * @param int $user_id ID del usuario.
     * @return string
     */
    private function get_customer_type(int $user_id): string {
        $source = get_user_meta($user_id, 'chp_registration_source', true);

        if ($source && strpos($source, 'social_') === 0) {
            return $source;
        }

        // Verificar si vino de Nextend Social Login
        $nsl_provider = get_user_meta($user_id, 'nsl_provider', true);
        if ($nsl_provider) {
            return 'social_' . strtolower($nsl_provider);
        }

        return 'registered';
    }

    /**
     * Obtener IP del cliente
     *
     * @return string
     */
    private function get_client_ip(): string {
        $ip = '';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        // Si hay múltiples IPs, tomar la primera
        if (strpos($ip, ',') !== false) {
            $ip = explode(',', $ip)[0];
        }

        return sanitize_text_field(trim($ip));
    }

    /**
     * Actualizar preferencia de marketing
     *
     * @param string $email    Email del cliente.
     * @param bool   $accepts  Acepta marketing.
     * @return bool
     */
    public function update_marketing_preference(string $email, bool $accepts): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            self::get_table_name(),
            ['accepts_marketing' => $accepts ? 1 : 0],
            ['email' => sanitize_email($email)],
            ['%d'],
            ['%s']
        );
    }

    /**
     * Exportar datos para GDPR
     *
     * @param string $email Email del cliente.
     * @return array
     */
    public function export_gdpr_data(string $email): array {
        $customer = $this->get_customer_by_email($email);

        if (!$customer) {
            return [];
        }

        // Eliminar datos sensibles internos
        unset($customer['id'], $customer['ip_address'], $customer['user_agent']);

        return $customer;
    }

    /**
     * Eliminar datos para GDPR (anonimizar)
     *
     * @param string $email Email del cliente.
     * @return bool
     */
    public function erase_gdpr_data(string $email): bool {
        global $wpdb;

        // Anonimizar en lugar de eliminar para mantener estadísticas
        return (bool) $wpdb->update(
            self::get_table_name(),
            [
                'email'      => 'deleted_' . wp_hash($email) . '@anonymized.local',
                'first_name' => '',
                'last_name'  => '',
                'phone'      => '',
                'whatsapp'   => '',
                'ip_address' => '',
                'user_agent' => '',
                'notes'      => '',
            ],
            ['email' => sanitize_email($email)]
        );
    }
}

/**
 * Función helper para acceder al tracker
 *
 * @return ChurrascoPlanet_Guest_Tracker
 */
function chp_guest_tracker(): ChurrascoPlanet_Guest_Tracker {
    return ChurrascoPlanet_Guest_Tracker::get_instance();
}
