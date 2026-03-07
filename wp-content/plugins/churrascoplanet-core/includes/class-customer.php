<?php
/**
 * Modelo de Cliente Extendido para ChurrascoPlanet
 *
 * Extiende WC_Customer con funcionalidades adicionales y manejo
 * de user meta con prefijo chp_.
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase ChurrascoPlanet_Customer
 *
 * Modelo de cliente extendido con campos personalizados
 */
class ChurrascoPlanet_Customer {

    /**
     * Prefijo para user meta personalizado
     */
    const META_PREFIX = 'chp_';

    /**
     * ID del cliente
     *
     * @var int
     */
    private int $customer_id;

    /**
     * Instancia de WC_Customer
     *
     * @var WC_Customer|null
     */
    private ?WC_Customer $wc_customer = null;

    /**
     * Constructor
     *
     * @param int $customer_id ID del usuario/cliente.
     */
    public function __construct(int $customer_id = 0) {
        $this->customer_id = $customer_id ?: get_current_user_id();

        if ($this->customer_id && class_exists('WC_Customer')) {
            try {
                $this->wc_customer = new WC_Customer($this->customer_id);
            } catch (Exception $e) {
                error_log('ChurrascoPlanet Customer: Error al cargar WC_Customer - ' . $e->getMessage());
            }
        }
    }

    /**
     * Obtener ID del cliente
     *
     * @return int
     */
    public function get_id(): int {
        return $this->customer_id;
    }

    /**
     * Obtener instancia de WC_Customer
     *
     * @return WC_Customer|null
     */
    public function get_wc_customer(): ?WC_Customer {
        return $this->wc_customer;
    }

    /**
     * Obtener local favorito del cliente
     *
     * @return int|null ID del local favorito
     */
    public function get_preferred_store_id(): ?int {
        $store_id = get_user_meta($this->customer_id, self::META_PREFIX . 'preferred_store_id', true);
        return $store_id ? absint($store_id) : null;
    }

    /**
     * Establecer local favorito del cliente
     *
     * @param int $store_id ID del local.
     * @return bool
     */
    public function set_preferred_store_id(int $store_id): bool {
        return (bool) update_user_meta($this->customer_id, self::META_PREFIX . 'preferred_store_id', absint($store_id));
    }

    /**
     * Verificar si el cliente quiere notificaciones por email
     *
     * @return bool
     */
    public function wants_email_notifications(): bool {
        $value = get_user_meta($this->customer_id, self::META_PREFIX . 'notification_email', true);
        return $value === '' ? true : (bool) $value; // Por defecto activado
    }

    /**
     * Establecer preferencia de notificaciones por email
     *
     * @param bool $enabled Activado/desactivado.
     * @return bool
     */
    public function set_email_notifications(bool $enabled): bool {
        return (bool) update_user_meta($this->customer_id, self::META_PREFIX . 'notification_email', $enabled ? '1' : '0');
    }

    /**
     * Verificar si el cliente quiere notificaciones por WhatsApp
     *
     * @return bool
     */
    public function wants_whatsapp_notifications(): bool {
        return (bool) get_user_meta($this->customer_id, self::META_PREFIX . 'notification_whatsapp', true);
    }

    /**
     * Establecer preferencia de notificaciones por WhatsApp
     *
     * @param bool $enabled Activado/desactivado.
     * @return bool
     */
    public function set_whatsapp_notifications(bool $enabled): bool {
        return (bool) update_user_meta($this->customer_id, self::META_PREFIX . 'notification_whatsapp', $enabled ? '1' : '0');
    }

    /**
     * Obtener total de pedidos del cliente (cacheado)
     *
     * @param bool $refresh Forzar recálculo.
     * @return int
     */
    public function get_total_orders(bool $refresh = false): int {
        $cached = get_user_meta($this->customer_id, self::META_PREFIX . 'total_orders', true);

        if ($cached !== '' && !$refresh) {
            return absint($cached);
        }

        // Calcular desde WooCommerce
        if ($this->wc_customer) {
            $count = $this->wc_customer->get_order_count();
            update_user_meta($this->customer_id, self::META_PREFIX . 'total_orders', $count);
            return $count;
        }

        return 0;
    }

    /**
     * Obtener fecha del último pedido
     *
     * @return string|null Fecha en formato Y-m-d H:i:s
     */
    public function get_last_order_date(): ?string {
        $date = get_user_meta($this->customer_id, self::META_PREFIX . 'last_order_date', true);
        return $date ?: null;
    }

    /**
     * Actualizar fecha del último pedido
     *
     * @param string $date Fecha en formato Y-m-d H:i:s.
     * @return bool
     */
    public function set_last_order_date(string $date): bool {
        return (bool) update_user_meta($this->customer_id, self::META_PREFIX . 'last_order_date', sanitize_text_field($date));
    }

    /**
     * Obtener origen del registro del cliente
     *
     * @return string normal|facebook|google|guest
     */
    public function get_registration_source(): string {
        $source = get_user_meta($this->customer_id, self::META_PREFIX . 'registration_source', true);
        return $source ?: 'normal';
    }

    /**
     * Establecer origen del registro
     *
     * @param string $source normal|facebook|google|guest.
     * @return bool
     */
    public function set_registration_source(string $source): bool {
        $valid_sources = ['normal', 'facebook', 'google', 'guest', 'apple'];
        if (!in_array($source, $valid_sources, true)) {
            $source = 'normal';
        }
        return (bool) update_user_meta($this->customer_id, self::META_PREFIX . 'registration_source', $source);
    }

    /**
     * Obtener número de WhatsApp del cliente
     *
     * @return string
     */
    public function get_whatsapp_number(): string {
        return get_user_meta($this->customer_id, self::META_PREFIX . 'whatsapp_number', true) ?: '';
    }

    /**
     * Establecer número de WhatsApp
     *
     * @param string $number Número de teléfono.
     * @return bool
     */
    public function set_whatsapp_number(string $number): bool {
        // Limpiar número: solo dígitos y +
        $number = preg_replace('/[^0-9+]/', '', $number);
        return (bool) update_user_meta($this->customer_id, self::META_PREFIX . 'whatsapp_number', $number);
    }

    /**
     * Obtener total gastado por el cliente
     *
     * @return float
     */
    public function get_total_spent(): float {
        if ($this->wc_customer) {
            return (float) $this->wc_customer->get_total_spent();
        }
        return 0.0;
    }

    /**
     * Obtener cupones disponibles para el cliente
     *
     * @return array Array de WC_Coupon
     */
    public function get_available_coupons(): array {
        if (!function_exists('wc_get_coupon_id_by_code')) {
            return [];
        }

        $coupons = [];
        $user_email = '';

        if ($this->wc_customer) {
            $user_email = $this->wc_customer->get_email();
        }

        // Obtener cupones con restricción de email
        $coupon_posts = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => 'customer_email',
                    'value'   => '',
                    'compare' => '='
                ],
                [
                    'key'     => 'customer_email',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key'     => 'customer_email',
                    'value'   => $user_email,
                    'compare' => 'LIKE'
                ],
            ],
        ]);

        foreach ($coupon_posts as $coupon_post) {
            try {
                $coupon = new WC_Coupon($coupon_post->ID);

                // Verificar que el cupón sea válido
                if (!$coupon->is_valid()) {
                    continue;
                }

                // Verificar límite de uso por usuario
                $usage_limit = $coupon->get_usage_limit_per_user();
                if ($usage_limit > 0 && $this->customer_id) {
                    $used = $coupon->get_data_store()->get_usage_by_user_id($coupon, $this->customer_id);
                    if ($used >= $usage_limit) {
                        continue;
                    }
                }

                $coupons[] = $coupon;
            } catch (Exception $e) {
                continue;
            }
        }

        return $coupons;
    }

    /**
     * Actualizar estadísticas del cliente después de un pedido
     *
     * @param int $order_id ID del pedido.
     */
    public function update_order_stats(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Actualizar contador de pedidos
        $current_count = absint(get_user_meta($this->customer_id, self::META_PREFIX . 'total_orders', true));
        update_user_meta($this->customer_id, self::META_PREFIX . 'total_orders', $current_count + 1);

        // Actualizar fecha del último pedido
        update_user_meta($this->customer_id, self::META_PREFIX . 'last_order_date', $order->get_date_created()->format('Y-m-d H:i:s'));
    }

    /**
     * Obtener todos los datos del cliente para exportación GDPR
     *
     * @return array
     */
    public function get_gdpr_export_data(): array {
        return [
            'preferred_store_id'      => $this->get_preferred_store_id(),
            'notification_email'      => $this->wants_email_notifications(),
            'notification_whatsapp'   => $this->wants_whatsapp_notifications(),
            'whatsapp_number'         => $this->get_whatsapp_number(),
            'total_orders'            => $this->get_total_orders(),
            'last_order_date'         => $this->get_last_order_date(),
            'registration_source'     => $this->get_registration_source(),
        ];
    }

    /**
     * Eliminar todos los datos del cliente para GDPR eraser
     *
     * @return bool
     */
    public function erase_gdpr_data(): bool {
        $meta_keys = [
            'preferred_store_id',
            'notification_email',
            'notification_whatsapp',
            'whatsapp_number',
            'total_orders',
            'last_order_date',
            'registration_source',
        ];

        foreach ($meta_keys as $key) {
            delete_user_meta($this->customer_id, self::META_PREFIX . $key);
        }

        return true;
    }

    /**
     * Obtener cliente por email
     *
     * @param string $email Email del cliente.
     * @return ChurrascoPlanet_Customer|null
     */
    public static function get_by_email(string $email): ?ChurrascoPlanet_Customer {
        $user = get_user_by('email', $email);
        if ($user) {
            return new self($user->ID);
        }
        return null;
    }

    /**
     * Registrar hooks de la clase
     */
    public static function init(): void {
        // Actualizar estadísticas después de completar un pedido
        add_action('woocommerce_order_status_completed', [__CLASS__, 'on_order_completed']);

        // GDPR exportador
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_gdpr_exporter']);

        // GDPR eraser
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_gdpr_eraser']);
    }

    /**
     * Hook cuando se completa un pedido
     *
     * @param int $order_id ID del pedido.
     */
    public static function on_order_completed(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            $customer = new self($customer_id);
            $customer->update_order_stats($order_id);
        }
    }

    /**
     * Registrar exportador GDPR
     *
     * @param array $exporters Lista de exportadores.
     * @return array
     */
    public static function register_gdpr_exporter(array $exporters): array {
        $exporters['churrascoplanet-customer'] = [
            'exporter_friendly_name' => __('ChurrascoPlanet - Datos de Cliente', 'churrascoplanet-core'),
            'callback'               => [__CLASS__, 'gdpr_exporter_callback'],
        ];
        return $exporters;
    }

    /**
     * Callback del exportador GDPR
     *
     * @param string $email Email del usuario.
     * @param int    $page  Página actual.
     * @return array
     */
    public static function gdpr_exporter_callback(string $email, int $page = 1): array {
        $customer = self::get_by_email($email);

        if (!$customer) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $data = $customer->get_gdpr_export_data();
        $export_items = [];

        foreach ($data as $key => $value) {
            if ($value !== null && $value !== '') {
                $export_items[] = [
                    'name'  => ucwords(str_replace('_', ' ', $key)),
                    'value' => is_bool($value) ? ($value ? 'Sí' : 'No') : $value,
                ];
            }
        }

        return [
            'data' => [
                [
                    'group_id'          => 'churrascoplanet_customer',
                    'group_label'       => __('Preferencias ChurrascoPlanet', 'churrascoplanet-core'),
                    'group_description' => __('Datos de preferencias del cliente en ChurrascoPlanet.', 'churrascoplanet-core'),
                    'item_id'           => 'customer-' . $customer->get_id(),
                    'data'              => $export_items,
                ],
            ],
            'done' => true,
        ];
    }

    /**
     * Registrar eraser GDPR
     *
     * @param array $erasers Lista de erasers.
     * @return array
     */
    public static function register_gdpr_eraser(array $erasers): array {
        $erasers['churrascoplanet-customer'] = [
            'eraser_friendly_name' => __('ChurrascoPlanet - Datos de Cliente', 'churrascoplanet-core'),
            'callback'             => [__CLASS__, 'gdpr_eraser_callback'],
        ];
        return $erasers;
    }

    /**
     * Callback del eraser GDPR
     *
     * @param string $email Email del usuario.
     * @param int    $page  Página actual.
     * @return array
     */
    public static function gdpr_eraser_callback(string $email, int $page = 1): array {
        $customer = self::get_by_email($email);

        if (!$customer) {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $erased = $customer->erase_gdpr_data();

        return [
            'items_removed'  => $erased,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
    }
}
