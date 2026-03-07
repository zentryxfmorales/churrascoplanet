<?php
/**
 * Sistema de Rastreo de Pedidos con Uber Direct
 *
 * Proporciona seguimiento en tiempo real de pedidos con delivery Uber.
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase ChurrascoPlanet_Order_Tracking
 *
 * Maneja el rastreo de pedidos con integración Uber Direct
 */
class ChurrascoPlanet_Order_Tracking {

    /**
     * Instancia única
     *
     * @var ChurrascoPlanet_Order_Tracking|null
     */
    private static ?ChurrascoPlanet_Order_Tracking $instance = null;

    /**
     * Mapeo de estados Uber a información de UI
     *
     * @var array
     */
    private array $status_map = [
        'pending' => [
            'label' => 'Confirmado',
            'icon'  => 'fa-check-circle',
            'color' => '#28a745',
            'order' => 1,
        ],
        'pickup' => [
            'label' => 'En preparación',
            'icon'  => 'fa-utensils',
            'color' => '#ffc107',
            'order' => 2,
        ],
        'pickup_complete' => [
            'label' => 'Recogido',
            'icon'  => 'fa-motorcycle',
            'color' => '#17a2b8',
            'order' => 3,
        ],
        'dropoff' => [
            'label' => 'En camino',
            'icon'  => 'fa-shipping-fast',
            'color' => '#007bff',
            'order' => 4,
        ],
        'delivered' => [
            'label' => 'Entregado',
            'icon'  => 'fa-flag-checkered',
            'color' => '#28a745',
            'order' => 5,
        ],
        'canceled' => [
            'label' => 'Cancelado',
            'icon'  => 'fa-times-circle',
            'color' => '#dc3545',
            'order' => 0,
        ],
    ];

    /**
     * Obtener instancia única
     *
     * @return ChurrascoPlanet_Order_Tracking
     */
    public static function get_instance(): ChurrascoPlanet_Order_Tracking {
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
        // Registrar endpoint REST
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Agregar sección de rastreo en vista de pedido
        add_action('woocommerce_order_details_after_order_table', [$this, 'add_tracking_section'], 10, 1);

        // Hook para actualizar estado cuando llega webhook
        add_action('wcudc_delivery_picked_up', [$this, 'on_delivery_picked_up'], 10, 2);
        add_action('wcudc_delivery_completed', [$this, 'on_delivery_completed'], 10, 2);
        add_action('wcudc_courier_approaching', [$this, 'on_courier_approaching'], 10, 2);
        add_action('wcudc_delivery_cancelled', [$this, 'on_delivery_cancelled'], 10, 2);

        // Email de actualización de estado
        add_action('chp_delivery_status_changed', [$this, 'send_status_notification'], 10, 3);
    }

    /**
     * Registrar rutas REST
     */
    public function register_rest_routes(): void {
        register_rest_route('chp/v1', '/order/(?P<id>\d+)/tracking', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_tracking_data'],
            'permission_callback' => [$this, 'check_tracking_permission'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
            ],
        ]);

        // Endpoint para obtener estado en tiempo real desde Uber
        register_rest_route('chp/v1', '/order/(?P<id>\d+)/tracking/refresh', [
            'methods'             => 'POST',
            'callback'            => [$this, 'refresh_tracking_data'],
            'permission_callback' => [$this, 'check_tracking_permission'],
        ]);
    }

    /**
     * Verificar permiso para acceder al tracking
     *
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    public function check_tracking_permission(WP_REST_Request $request): bool {
        $order_id = absint($request->get_param('id'));
        $order = wc_get_order($order_id);

        if (!$order) {
            return false;
        }

        // Administradores siempre tienen acceso
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        // Verificar que sea el dueño del pedido
        $customer_id = get_current_user_id();
        if (!$customer_id) {
            return false;
        }

        return $order->get_customer_id() === $customer_id;
    }

    /**
     * Obtener datos de tracking
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_tracking_data(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $order_id = absint($request->get_param('id'));
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error(
                'order_not_found',
                __('Pedido no encontrado.', 'churrascoplanet-core'),
                ['status' => 404]
            );
        }

        $uber_delivery_id = $order->get_meta('_uber_delivery_id');

        if (!$uber_delivery_id) {
            return new WP_Error(
                'no_delivery',
                __('Este pedido no tiene información de delivery.', 'churrascoplanet-core'),
                ['status' => 404]
            );
        }

        // Obtener estado cacheado
        $cached_status = $order->get_meta('_uber_status');
        $last_update = $order->get_meta('_uber_status_updated');

        // Información del courier
        $courier_info = [
            'name'  => $order->get_meta('_uber_courier_name') ?: null,
            'phone' => $order->get_meta('_uber_courier_phone') ?: null,
            'photo' => $order->get_meta('_uber_courier_photo') ?: null,
            'vehicle' => $order->get_meta('_uber_courier_vehicle') ?: null,
        ];

        // ETA
        $eta_minutes = $order->get_meta('_uber_eta_minutes');

        // Construir timeline
        $timeline = $this->build_timeline($cached_status);

        $response_data = [
            'order_id'         => $order_id,
            'delivery_id'      => $uber_delivery_id,
            'status'           => $cached_status ?: 'pending',
            'status_info'      => $this->get_status_info($cached_status ?: 'pending'),
            'last_update'      => $last_update,
            'courier'          => $courier_info,
            'eta_minutes'      => $eta_minutes ? absint($eta_minutes) : null,
            'timeline'         => $timeline,
            'store_name'       => $order->get_meta('_wcudc_store_name') ?: null,
            'can_refresh'      => $this->can_refresh_status($last_update),
        ];

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Refrescar datos de tracking desde Uber
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function refresh_tracking_data(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $order_id = absint($request->get_param('id'));
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('order_not_found', __('Pedido no encontrado.', 'churrascoplanet-core'), ['status' => 404]);
        }

        $uber_delivery_id = $order->get_meta('_uber_delivery_id');
        if (!$uber_delivery_id) {
            return new WP_Error('no_delivery', __('Sin delivery asociado.', 'churrascoplanet-core'), ['status' => 404]);
        }

        // Rate limiting: solo permitir refresh cada 15 segundos
        $last_refresh = $order->get_meta('_uber_last_refresh');
        if ($last_refresh && (time() - strtotime($last_refresh)) < 15) {
            return new WP_Error(
                'rate_limited',
                __('Por favor espera unos segundos antes de actualizar.', 'churrascoplanet-core'),
                ['status' => 429]
            );
        }

        // Consultar API de Uber
        if (!function_exists('wcudc') || !wcudc()->api) {
            return new WP_Error('api_unavailable', __('API de Uber no disponible.', 'churrascoplanet-core'), ['status' => 503]);
        }

        $result = wcudc()->api->get_delivery_status($uber_delivery_id);

        if (is_wp_error($result)) {
            return new WP_Error(
                'api_error',
                $result->get_error_message(),
                ['status' => 500]
            );
        }

        // Actualizar metadata del pedido
        $old_status = $order->get_meta('_uber_status');
        $new_status = $result['status'] ?? 'pending';

        $order->update_meta_data('_uber_status', $new_status);
        $order->update_meta_data('_uber_status_updated', current_time('mysql'));
        $order->update_meta_data('_uber_last_refresh', current_time('mysql'));

        // Actualizar info del courier si está disponible
        if (!empty($result['courier'])) {
            $order->update_meta_data('_uber_courier_name', $result['courier']['name'] ?? '');
            $order->update_meta_data('_uber_courier_phone', $result['courier']['phone_number'] ?? '');
            $order->update_meta_data('_uber_courier_photo', $result['courier']['picture_url'] ?? '');
            $order->update_meta_data('_uber_courier_vehicle', $result['courier']['vehicle_type'] ?? '');
        }

        // ETA
        if (!empty($result['dropoff_eta'])) {
            $eta = strtotime($result['dropoff_eta']);
            if ($eta) {
                $minutes = max(0, round(($eta - time()) / 60));
                $order->update_meta_data('_uber_eta_minutes', $minutes);
            }
        }

        $order->save();

        // Disparar acción si el estado cambió
        if ($old_status !== $new_status) {
            do_action('chp_delivery_status_changed', $order, $old_status, $new_status);
        }

        // Retornar datos actualizados
        return $this->get_tracking_data($request);
    }

    /**
     * Verificar si se puede hacer refresh
     *
     * @param string|null $last_update Última actualización.
     * @return bool
     */
    private function can_refresh_status(?string $last_update): bool {
        if (!$last_update) {
            return true;
        }

        $last_time = strtotime($last_update);
        return (time() - $last_time) > 15;
    }

    /**
     * Obtener información de un estado
     *
     * @param string $status Código de estado.
     * @return array
     */
    public function get_status_info(string $status): array {
        return $this->status_map[$status] ?? [
            'label' => ucfirst($status),
            'icon'  => 'fa-question-circle',
            'color' => '#6c757d',
            'order' => 0,
        ];
    }

    /**
     * Construir timeline de estados
     *
     * @param string|null $current_status Estado actual.
     * @return array
     */
    public function build_timeline(?string $current_status): array {
        $current_order = $this->status_map[$current_status]['order'] ?? 0;

        // Si está cancelado, mostrar solo cancelado
        if ($current_status === 'canceled') {
            return [
                [
                    'status'  => 'canceled',
                    'label'   => $this->status_map['canceled']['label'],
                    'icon'    => $this->status_map['canceled']['icon'],
                    'color'   => $this->status_map['canceled']['color'],
                    'active'  => true,
                    'completed'=> true,
                ],
            ];
        }

        $timeline = [];
        $steps = ['pending', 'pickup', 'pickup_complete', 'dropoff', 'delivered'];

        foreach ($steps as $step) {
            $step_info = $this->status_map[$step];
            $step_order = $step_info['order'];

            $timeline[] = [
                'status'   => $step,
                'label'    => $step_info['label'],
                'icon'     => $step_info['icon'],
                'color'    => $step_info['color'],
                'active'   => $step === $current_status,
                'completed'=> $step_order < $current_order,
            ];
        }

        return $timeline;
    }

    /**
     * Agregar sección de tracking en vista de pedido
     *
     * @param WC_Order $order Pedido.
     */
    public function add_tracking_section(WC_Order $order): void {
        $uber_delivery_id = $order->get_meta('_uber_delivery_id');

        if (!$uber_delivery_id) {
            return;
        }

        // Solo mostrar si el pedido no está completado
        if ($order->has_status('completed')) {
            // Mostrar resumen de entrega completada
            $this->render_completed_delivery_summary($order);
            return;
        }

        $status = $order->get_meta('_uber_status') ?: 'pending';
        $status_info = $this->get_status_info($status);
        $timeline = $this->build_timeline($status);

        include CHP_CORE_PATH . 'templates/myaccount/order-tracking-section.php';
    }

    /**
     * Renderizar resumen de entrega completada
     *
     * @param WC_Order $order Pedido.
     */
    private function render_completed_delivery_summary(WC_Order $order): void {
        $courier_name = $order->get_meta('_uber_courier_name');
        $delivered_date = $order->get_date_completed();

        if (!$delivered_date) {
            return;
        }

        ?>
        <section class="chp-delivery-completed">
            <h2><?php esc_html_e('Entrega Completada', 'churrascoplanet-core'); ?></h2>
            <p class="delivery-info">
                <i class="fas fa-check-circle" style="color: #28a745;"></i>
                <?php
                printf(
                    esc_html__('Tu pedido fue entregado el %s', 'churrascoplanet-core'),
                    esc_html($delivered_date->date_i18n(get_option('date_format') . ' ' . get_option('time_format')))
                );
                if ($courier_name) {
                    printf(' ' . esc_html__('por %s', 'churrascoplanet-core'), esc_html($courier_name));
                }
                ?>
            </p>
        </section>
        <?php
    }

    /**
     * Hook cuando el delivery es recogido
     *
     * @param WC_Order $order Pedido.
     * @param array    $data  Datos del webhook.
     */
    public function on_delivery_picked_up(WC_Order $order, array $data): void {
        $old_status = $order->get_meta('_uber_status');
        $order->update_meta_data('_uber_status', 'pickup_complete');
        $order->update_meta_data('_uber_status_updated', current_time('mysql'));

        if (!empty($data['courier'])) {
            $order->update_meta_data('_uber_courier_name', $data['courier']['name'] ?? '');
            $order->update_meta_data('_uber_courier_phone', $data['courier']['phone_number'] ?? '');
        }

        $order->save();

        do_action('chp_delivery_status_changed', $order, $old_status, 'pickup_complete');
    }

    /**
     * Hook cuando el delivery es completado
     *
     * @param WC_Order $order Pedido.
     * @param array    $data  Datos del webhook.
     */
    public function on_delivery_completed(WC_Order $order, array $data): void {
        $old_status = $order->get_meta('_uber_status');
        $order->update_meta_data('_uber_status', 'delivered');
        $order->update_meta_data('_uber_status_updated', current_time('mysql'));
        $order->save();

        do_action('chp_delivery_status_changed', $order, $old_status, 'delivered');
    }

    /**
     * Hook cuando el courier se aproxima
     *
     * @param WC_Order $order Pedido.
     * @param array    $data  Datos del webhook.
     */
    public function on_courier_approaching(WC_Order $order, array $data): void {
        $old_status = $order->get_meta('_uber_status');
        $order->update_meta_data('_uber_status', 'pickup');
        $order->update_meta_data('_uber_status_updated', current_time('mysql'));

        if (!empty($data['eta_minutes'])) {
            $order->update_meta_data('_uber_eta_minutes', absint($data['eta_minutes']));
        }

        $order->save();

        do_action('chp_delivery_status_changed', $order, $old_status, 'pickup');
    }

    /**
     * Hook cuando el delivery es cancelado
     *
     * @param WC_Order $order Pedido.
     * @param array    $data  Datos del webhook.
     */
    public function on_delivery_cancelled(WC_Order $order, array $data): void {
        $old_status = $order->get_meta('_uber_status');
        $order->update_meta_data('_uber_status', 'canceled');
        $order->update_meta_data('_uber_status_updated', current_time('mysql'));
        $order->save();

        do_action('chp_delivery_status_changed', $order, $old_status, 'canceled');
    }

    /**
     * Enviar notificación de cambio de estado
     *
     * @param WC_Order    $order      Pedido.
     * @param string|null $old_status Estado anterior.
     * @param string      $new_status Nuevo estado.
     */
    public function send_status_notification(WC_Order $order, ?string $old_status, string $new_status): void {
        $customer_id = $order->get_customer_id();

        // Verificar si el cliente quiere notificaciones
        if ($customer_id) {
            $customer = new ChurrascoPlanet_Customer($customer_id);
            if (!$customer->wants_email_notifications()) {
                return;
            }
        }

        // Estados que generan notificación
        $notify_states = ['pickup_complete', 'dropoff', 'delivered', 'canceled'];

        if (!in_array($new_status, $notify_states, true)) {
            return;
        }

        $status_info = $this->get_status_info($new_status);

        // Preparar email
        $to = $order->get_billing_email();
        $subject = sprintf(
            __('[ChurrascoPlanet] Tu pedido #%d - %s', 'churrascoplanet-core'),
            $order->get_id(),
            $status_info['label']
        );

        $message = $this->get_notification_message($order, $new_status, $status_info);

        // Headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ChurrascoPlanet <noreply@' . wp_parse_url(home_url(), PHP_URL_HOST) . '>',
        ];

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Generar mensaje de notificación
     *
     * @param WC_Order $order       Pedido.
     * @param string   $status      Estado.
     * @param array    $status_info Info del estado.
     * @return string
     */
    private function get_notification_message(WC_Order $order, string $status, array $status_info): string {
        $tracking_url = wc_get_account_endpoint_url('order-tracking') . $order->get_id();

        $messages = [
            'pickup_complete' => sprintf(
                __('Tu pedido #%d ha sido recogido por el repartidor y está en camino.', 'churrascoplanet-core'),
                $order->get_id()
            ),
            'dropoff' => sprintf(
                __('Tu pedido #%d está muy cerca. El repartidor llegará pronto.', 'churrascoplanet-core'),
                $order->get_id()
            ),
            'delivered' => sprintf(
                __('Tu pedido #%d ha sido entregado. ¡Disfruta tu comida!', 'churrascoplanet-core'),
                $order->get_id()
            ),
            'canceled' => sprintf(
                __('Lamentamos informarte que el delivery de tu pedido #%d ha sido cancelado. Te contactaremos pronto.', 'churrascoplanet-core'),
                $order->get_id()
            ),
        ];

        $body_message = $messages[$status] ?? '';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: <?php echo esc_attr($status_info['color']); ?>; margin: 0;">
                        <?php echo esc_html($status_info['label']); ?>
                    </h1>
                </div>

                <p><?php echo esc_html($body_message); ?></p>

                <?php if ($status !== 'canceled' && $status !== 'delivered'): ?>
                <p style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo esc_url($tracking_url); ?>"
                       style="display: inline-block; background-color: #C41E3A; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">
                        <?php esc_html_e('Ver Rastreo en Tiempo Real', 'churrascoplanet-core'); ?>
                    </a>
                </p>
                <?php endif; ?>

                <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">

                <p style="font-size: 12px; color: #666; text-align: center;">
                    <?php esc_html_e('Este es un mensaje automático, por favor no respondas a este correo.', 'churrascoplanet-core'); ?>
                </p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Obtener mapa de estados
     *
     * @return array
     */
    public function get_status_map(): array {
        return $this->status_map;
    }
}

/**
 * Función helper para acceder al tracking
 *
 * @return ChurrascoPlanet_Order_Tracking
 */
function chp_order_tracking(): ChurrascoPlanet_Order_Tracking {
    return ChurrascoPlanet_Order_Tracking::get_instance();
}
