<?php
/**
 * Receptor y Procesador de Webhooks de SumUp
 *
 * Responsabilidades:
 *  1. Registrar el endpoint WC API (?wc-api=restohub_sumup).
 *  2. Recibir el evento CHECKOUT_STATUS_CHANGED de SumUp.
 *  3. Validar estructura del payload antes de encolar.
 *  4. Encolar el procesamiento via ActionScheduler (+60 s) y responder 200.
 *  5. Procesar el pago de forma segura:
 *       - Verificar que el monto de SumUp coincide con el total de la orden (anti-manipulación).
 *       - Verificar que el checkout_reference corresponde a la orden (anti-replay).
 *       - Idempotencia estricta: un pago no se procesa dos veces.
 *  6. Retry con backoff exponencial (máx. 5 intentos) para estados PENDING.
 *  7. Notificación al administrador si se agotan los reintentos.
 *
 * URL del endpoint (configurar en dashboard de SumUp):
 *   https://tu-dominio.com/?wc-api=restohub_sumup
 *
 * @package RestoHub
 * @subpackage Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class RestoHub_SumUp_Webhook
 */
class RestoHub_SumUp_Webhook {

    // =========================================================================
    // Constantes
    // =========================================================================

    /** Slug del endpoint WC API. La URL resultante es ?wc-api=restohub_sumup. */
    public const ENDPOINT = 'restohub_sumup';

    /**
     * Nombre de la acción en ActionScheduler.
     * También expuesto como constante pública para que el gateway
     * pueda cancelar acciones pendientes si fuera necesario.
     */
    public const ACTION_PROCESS = 'restohub_sumup_process_payment';

    /** Grupo de ActionScheduler para filtrar fácilmente desde el admin. */
    public const ACTION_GROUP = 'restohub_sumup_payments';

    /**
     * Número máximo de intentos de procesamiento para un estado PENDING.
     * Backoff: 60 s, 120 s, 240 s, 480 s (entre cada intento adicional).
     */
    private const MAX_ATTEMPTS = 5;

    /** Canal de log visible en WooCommerce > Estado > Registros. */
    private const LOG_SOURCE = 'restohub-sumup';

    // ── Claves de order meta ─────────────────────────────────────────────────

    /** UUID del checkout en SumUp. Guardado por el gateway al crear el checkout. */
    public const META_CHECKOUT_ID = '_restohub_sumup_checkout_id';

    /**
     * Referencia del checkout (formato RH_{order_id}_{timestamp}).
     * Guardado por RestoHub_SumUp_API::create_checkout() en el meta de la orden.
     */
    public const META_CHECKOUT_REF = '_restohub_sumup_checkout_reference';

    /**
     * Flag de idempotencia. Valor '1' indica que el pago ya fue procesado.
     * Impide que un webhook duplicado provoque un doble cobro.
     */
    public const META_PROCESSED = '_restohub_sumup_payment_processed';

    /** Código de transacción de SumUp cuando el pago es PAID. */
    public const META_TRANSACTION = '_restohub_sumup_transaction_code';

    // =========================================================================
    // Propiedades
    // =========================================================================

    /** @var RestoHub_SumUp_API Instancia del wrapper de la API. */
    private RestoHub_SumUp_API $api;

    /** @var WC_Logger_Interface|null Logger de WooCommerce. */
    private ?WC_Logger_Interface $logger = null;

    // =========================================================================
    // Constructor
    // =========================================================================

    public function __construct( RestoHub_SumUp_API $api ) {
        $this->api = $api;
        $this->init_logger();

        // Registrar endpoint WC API
        add_action( 'woocommerce_api_' . self::ENDPOINT, array( $this, 'receive_webhook' ) );

        // Registrar el procesador de la acción programada
        add_action( self::ACTION_PROCESS, array( $this, 'process_scheduled_payment' ) );
    }

    // =========================================================================
    // Endpoint: recepción inmediata del webhook
    // =========================================================================

    /**
     * Punto de entrada del webhook de SumUp.
     *
     * Ejecutado vía ?wc-api=restohub_sumup (sin autenticación HTTP, dado que
     * SumUp actualmente no firma sus webhooks con HMAC).
     * La seguridad se garantiza en el procesamiento diferido mediante
     * verificación cruzada de monto y referencia contra la API de SumUp.
     *
     * Flujo:
     *  1. Leer y decodificar body JSON.
     *  2. Validar campos mínimos: event_type, id.
     *  3. Ignorar eventos desconocidos (devolver 200 para no forzar retry).
     *  4. Buscar la orden por _restohub_sumup_checkout_id.
     *  5. Verificar idempotencia.
     *  6. Encolar con ActionScheduler (+60 s).
     *  7. Responder 200 inmediatamente.
     *
     * @return void
     */
    public function receive_webhook(): void {
        $raw_body = file_get_contents( 'php://input' );

        // ── 1. Decodificar JSON ─────────────────────────────────────────────
        $payload = json_decode( $raw_body, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
            $this->log( 'warning', 'Webhook recibido con body JSON inválido.' );
            $this->send_response( array( 'error' => 'Invalid JSON payload' ), 400 );
            return;
        }

        // ── 2. Validar campos mínimos ───────────────────────────────────────
        $event_type  = sanitize_text_field( $payload['event_type'] ?? '' );
        $checkout_id = sanitize_text_field( $payload['id']         ?? '' );

        if ( empty( $event_type ) || empty( $checkout_id ) ) {
            $this->log( 'warning', sprintf(
                'Webhook con campos faltantes — event_type="%s" | id="%s".',
                $event_type,
                $checkout_id
            ) );
            $this->send_response( array( 'error' => 'Missing required fields: event_type, id' ), 400 );
            return;
        }

        // ── 3. Solo procesar CHECKOUT_STATUS_CHANGED ────────────────────────
        if ( 'CHECKOUT_STATUS_CHANGED' !== $event_type ) {
            $this->log( 'debug', sprintf(
                'Evento "%s" ignorado (solo se procesa CHECKOUT_STATUS_CHANGED).',
                $event_type
            ) );
            // Devolver 200 para que SumUp no reintente el envío.
            $this->send_response( array( 'message' => 'Event type not handled' ), 200 );
            return;
        }

        // ── 4. Buscar la orden por checkout_id ──────────────────────────────
        $order = $this->get_order_by_checkout_id( $checkout_id );

        if ( ! $order ) {
            // Devolver 200 (no 404) para no filtrar información sobre qué
            // checkout IDs son válidos, y evitar reintentos de SumUp.
            $this->log( 'warning', sprintf(
                'Webhook sin orden asociada — checkout_id="%s".',
                $checkout_id
            ) );
            $this->send_response( array( 'message' => 'Webhook received' ), 200 );
            return;
        }

        // ── 5. Idempotencia: no encolar si ya está procesado ─────────────────
        if ( '1' === $order->get_meta( self::META_PROCESSED ) ) {
            $this->log( 'info', sprintf(
                'Webhook duplicado ignorado — Pedido #%d ya procesado (checkout_id="%s").',
                $order->get_id(),
                $checkout_id
            ) );
            $this->send_response( array( 'message' => 'Already processed' ), 200 );
            return;
        }

        // ── 6. Encolar en ActionScheduler ────────────────────────────────────
        // Delay de 60 s para dar margen a SumUp de finalizar su procesamiento
        // interno antes de que consultemos el estado vía GET /v0.1/checkouts/{id}.
        $action_id = as_schedule_single_action(
            time() + 60,
            self::ACTION_PROCESS,
            array(
                array(
                    'checkout_id' => $checkout_id,
                    'order_id'    => $order->get_id(),
                    'attempt'     => 1,
                ),
            ),
            self::ACTION_GROUP
        );

        $this->log( 'info', sprintf(
            'Webhook encolado — AS action_id=%d | Pedido #%d | checkout_id="%s".',
            $action_id,
            $order->get_id(),
            $checkout_id
        ) );

        // ── 7. Responder 200 inmediatamente ──────────────────────────────────
        $this->send_response( array( 'message' => 'Webhook queued successfully' ), 200 );
    }

    // =========================================================================
    // Procesador diferido (ActionScheduler)
    // =========================================================================

    /**
     * Procesa el pago de forma diferida.
     *
     * Llamado por ActionScheduler ~60 s después de recibir el webhook.
     * Lanzar una Exception provoca que ActionScheduler marque la acción como
     * fallida y la reintente automáticamente (hasta 3 veces por defecto).
     * Para PENDING, manejamos el backoff manual con MAX_ATTEMPTS.
     *
     * @param array $args Argumentos: { checkout_id, order_id, attempt }.
     * @return void
     * @throws \Exception Si hay error de red/servidor para activar el retry de AS.
     */
    public function process_scheduled_payment( array $args ): void {
        $checkout_id = sanitize_text_field( $args['checkout_id'] ?? '' );
        $order_id    = absint( $args['order_id']    ?? 0 );
        $attempt     = absint( $args['attempt']     ?? 1 );

        if ( empty( $checkout_id ) || 0 === $order_id ) {
            $this->log( 'error', sprintf(
                'Acción programada con argumentos inválidos — checkout_id="%s" | order_id=%d.',
                $checkout_id,
                $order_id
            ) );
            return;
        }

        // ── Cargar la orden ──────────────────────────────────────────────────
        $order = wc_get_order( $order_id );

        if ( ! $order || ! ( $order instanceof WC_Order ) ) {
            $this->log( 'error', sprintf(
                'Pedido #%d no encontrado al procesar pago SumUp (intento %d).',
                $order_id,
                $attempt
            ) );
            return;
        }

        // ── Idempotencia (segunda verificación) ─────────────────────────────
        // Puede ocurrir si dos webhooks llegaron casi simultáneamente y
        // ambas acciones de AS se procesaron en paralelo.
        if ( '1' === $order->get_meta( self::META_PROCESSED ) ) {
            $this->log( 'info', sprintf(
                'Pedido #%d ya procesado. Acción ignorada (intento %d).',
                $order_id,
                $attempt
            ) );
            return;
        }

        $this->log( 'info', sprintf(
            'Procesando pago SumUp — Pedido #%d | checkout_id="%s" | intento %d/%d.',
            $order_id,
            $checkout_id,
            $attempt,
            self::MAX_ATTEMPTS
        ) );

        // ── Consultar estado actual del checkout en la API de SumUp ─────────
        $checkout_data = $this->api->get_checkout( $checkout_id );

        if ( is_wp_error( $checkout_data ) ) {
            $error_code = $checkout_data->get_error_code();

            // Errores de red o de servidor: lanzar Exception para activar
            // el mecanismo de retry automático de ActionScheduler.
            if ( in_array( $error_code, array( 'sumup_timeout', 'sumup_network_error', 'sumup_server_error' ), true ) ) {
                $this->log( 'error', sprintf(
                    'Error de red al consultar SumUp (Pedido #%d, intento %d): %s.',
                    $order_id,
                    $attempt,
                    $checkout_data->get_error_message()
                ) );
                throw new \Exception( sprintf(
                    '[RestoHub SumUp] Error consultando checkout "%s": %s',
                    $checkout_id,
                    $checkout_data->get_error_message()
                ) );
            }

            // Otros errores API (auth_expired, not_found, etc.): no reintentar.
            $this->log( 'error', sprintf(
                'Error API SumUp no recuperable (Pedido #%d): [%s] %s.',
                $order_id,
                $error_code,
                $checkout_data->get_error_message()
            ) );
            $order->add_order_note( sprintf(
                /* translators: error message */
                __( 'RestoHub SumUp: error consultando el estado del pago — %s', 'restohub' ),
                $checkout_data->get_error_message()
            ) );
            return;
        }

        // ── VALIDACIÓN DE SEGURIDAD 1: Verificar monto ───────────────────────
        // Compara el monto que SumUp tiene registrado con el total de la orden.
        // Un atacante podría crear un checkout de $1 y manipular el webhook
        // para referenciarlo a una orden de $10.000. Esta validación lo impide.
        if ( ! $this->validate_amount( $checkout_data, $order ) ) {
            $sumup_amount = (float) ( $checkout_data['amount'] ?? 0 );
            $order_total  = (float) $order->get_total();

            $this->log( 'critical', sprintf(
                'ALERTA DE SEGURIDAD — Manipulación de monto detectada. ' .
                'Pedido #%d | Monto en SumUp: %.2f | Total orden: %.2f | checkout_id="%s".',
                $order_id,
                $sumup_amount,
                $order_total,
                $checkout_id
            ) );
            $order->add_order_note( sprintf(
                __( '⚠️ ALERTA: El monto del pago en SumUp (%.2f %s) no coincide con el total de la orden (%.2f %s). Pago NO procesado. Revisa manualmente.', 'restohub' ),
                $sumup_amount,
                $checkout_data['currency'] ?? '',
                $order_total,
                get_woocommerce_currency()
            ) );
            $this->notify_admin_security_alert( $order, $checkout_id, $sumup_amount, $order_total );
            return;
        }

        // ── VALIDACIÓN DE SEGURIDAD 2: Verificar referencia del checkout ─────
        // El checkout_reference guardado en el meta de la orden debe coincidir
        // con el que SumUp tiene. Previene ataques de replay donde se reutiliza
        // un checkout_id de otra orden.
        if ( ! $this->validate_reference( $checkout_data, $order ) ) {
            $sumup_ref = sanitize_text_field( $checkout_data['checkout_reference'] ?? '' );
            $meta_ref  = $order->get_meta( self::META_CHECKOUT_REF );

            $this->log( 'critical', sprintf(
                'ALERTA DE SEGURIDAD — Referencia de checkout no coincide. ' .
                'Pedido #%d | Ref SumUp: "%s" | Ref guardada: "%s" | checkout_id="%s".',
                $order_id,
                $sumup_ref,
                $meta_ref,
                $checkout_id
            ) );
            $order->add_order_note( sprintf(
                __( '⚠️ ALERTA: La referencia del checkout de SumUp ("%s") no coincide con la registrada ("%s"). Pago NO procesado. Revisa manualmente.', 'restohub' ),
                $sumup_ref,
                $meta_ref
            ) );
            $this->notify_admin_security_alert( $order, $checkout_id, null, null, 'reference_mismatch' );
            return;
        }

        // ── Actuar según el estado del checkout ──────────────────────────────
        $status           = strtoupper( sanitize_text_field( $checkout_data['status'] ?? '' ) );
        $transaction_code = sanitize_text_field( $checkout_data['transaction_code'] ?? '' );

        $this->log( 'info', sprintf(
            'Estado SumUp: "%s" — Pedido #%d | checkout_id="%s".',
            $status,
            $order_id,
            $checkout_id
        ) );

        switch ( $status ) {

            case 'PAID':
                $this->handle_paid( $order, $checkout_id, $transaction_code );
                break;

            case 'FAILED':
                $this->handle_failed( $order, $checkout_id );
                break;

            case 'PENDING':
                $this->handle_pending( $order, $checkout_id, $attempt );
                break;

            default:
                $this->log( 'warning', sprintf(
                    'Estado desconocido de SumUp: "%s" — Pedido #%d.',
                    $status,
                    $order_id
                ) );
                $order->add_order_note( sprintf(
                    /* translators: payment status */
                    __( 'RestoHub SumUp: estado de pago desconocido recibido — "%s".', 'restohub' ),
                    $status
                ) );
                break;
        }
    }

    // =========================================================================
    // Manejadores de estado
    // =========================================================================

    /**
     * Procesa un pago confirmado (PAID).
     *
     * Llama a payment_complete() que:
     *  - Vacía el carrito.
     *  - Cambia el estado de la orden a 'processing' o 'completed'.
     *  - Dispara los emails de confirmación.
     *  - Reduce el stock.
     *
     * @param WC_Order $order            La orden de WooCommerce.
     * @param string   $checkout_id      UUID del checkout.
     * @param string   $transaction_code Código de transacción de SumUp.
     * @return void
     */
    private function handle_paid( WC_Order $order, string $checkout_id, string $transaction_code ): void {
        $order_id = $order->get_id();

        $this->log( 'info', sprintf(
            'Pago CONFIRMADO — Pedido #%d | transaction_code="%s".',
            $order_id,
            $transaction_code
        ) );

        // Guardar datos del pago y marcar como procesado ANTES de llamar
        // payment_complete() para garantizar idempotencia incluso si el hook
        // dispara otro webhook.
        $order->update_meta_data( self::META_TRANSACTION, $transaction_code );
        $order->update_meta_data( self::META_PROCESSED, '1' );
        $order->save_meta_data();

        // payment_complete() acepta el transaction_id para guardarlo en
        // _transaction_id del pedido y añadirlo al order note.
        $order->payment_complete( $transaction_code );

        $order->add_order_note( sprintf(
            /* translators: transaction code */
            __( 'Pago confirmado por SumUp. Código de transacción: %s', 'restohub' ),
            $transaction_code
        ) );

        /**
         * Acción para integraciones externas cuando SumUp confirma el pago.
         *
         * @param WC_Order $order            La orden completada.
         * @param string   $transaction_code Código de transacción.
         * @param string   $checkout_id      UUID del checkout.
         */
        do_action( 'restohub_sumup_payment_complete', $order, $transaction_code, $checkout_id );
    }

    /**
     * Procesa un pago fallido (FAILED).
     *
     * Marca la orden como fallida. El cliente puede reintentar el pago
     * desde la página "Mi cuenta > Pedidos" usando el link de pago de WC.
     *
     * @param WC_Order $order       La orden de WooCommerce.
     * @param string   $checkout_id UUID del checkout.
     * @return void
     */
    private function handle_failed( WC_Order $order, string $checkout_id ): void {
        $order_id = $order->get_id();

        $this->log( 'info', sprintf(
            'Pago FALLIDO — Pedido #%d | checkout_id="%s".',
            $order_id,
            $checkout_id
        ) );

        // Marcar como procesado para evitar reintentos del webhook.
        // Un estado FAILED es final en SumUp; no hay transición a PAID después.
        $order->update_meta_data( self::META_PROCESSED, '1' );
        $order->save_meta_data();

        $order->update_status(
            'failed',
            __( 'Pago rechazado por SumUp.', 'restohub' )
        );

        /**
         * Acción para integraciones externas cuando SumUp reporta pago fallido.
         *
         * @param WC_Order $order       La orden fallida.
         * @param string   $checkout_id UUID del checkout.
         */
        do_action( 'restohub_sumup_payment_failed', $order, $checkout_id );
    }

    /**
     * Maneja un estado PENDING con backoff exponencial.
     *
     * PENDING puede ocurrir si el checkout aún no fue procesado por SumUp
     * (ej: el banco está validando la transacción). Reintentamos hasta
     * MAX_ATTEMPTS veces con delays crecientes.
     *
     * Tabla de delays:
     *   Intento 2: +60 s
     *   Intento 3: +120 s
     *   Intento 4: +240 s
     *   Intento 5: +480 s
     *   → Total espera acumulada: ~15 minutos antes del último intento.
     *
     * @param WC_Order $order       La orden de WooCommerce.
     * @param string   $checkout_id UUID del checkout.
     * @param int      $attempt     Número de intento actual (1-based).
     * @return void
     */
    private function handle_pending( WC_Order $order, string $checkout_id, int $attempt ): void {
        $order_id = $order->get_id();

        if ( $attempt < self::MAX_ATTEMPTS ) {
            $next_attempt = $attempt + 1;
            // Backoff exponencial: 60 * 2^(attempt-1)
            // Intento 2 → 60*1=60s, intento 3 → 60*2=120s, etc.
            $delay = 60 * intval( pow( 2, $attempt - 1 ) );

            as_schedule_single_action(
                time() + $delay,
                self::ACTION_PROCESS,
                array(
                    array(
                        'checkout_id' => $checkout_id,
                        'order_id'    => $order_id,
                        'attempt'     => $next_attempt,
                    ),
                ),
                self::ACTION_GROUP
            );

            $this->log( 'info', sprintf(
                'Pago PENDIENTE — Pedido #%d | Reintento %d/%d programado en %d s.',
                $order_id,
                $next_attempt,
                self::MAX_ATTEMPTS,
                $delay
            ) );

        } else {
            // Reintentos agotados: notificar al administrador para intervención manual.
            $this->log( 'error', sprintf(
                'Reintentos agotados (%d/%d) para Pedido #%d | checkout_id="%s". Intervención manual requerida.',
                $attempt,
                self::MAX_ATTEMPTS,
                $order_id,
                $checkout_id
            ) );

            $order->add_order_note(
                __( '⚠️ RestoHub SumUp: Se agotaron los reintentos de verificación de pago (estado PENDING persistente). Verifica manualmente en el dashboard de SumUp e interviene si el pago fue acreditado.', 'restohub' )
            );

            $this->notify_admin_pending_timeout( $order, $checkout_id );
        }
    }

    // =========================================================================
    // Validaciones de seguridad
    // =========================================================================

    /**
     * Verifica que el monto reportado por SumUp coincide con el total de la orden.
     *
     * Usa una tolerancia de $0.01 para absorber diferencias de redondeo de
     * punto flotante. Para CLP (enteros), esto es en la práctica una
     * comparación exacta.
     *
     * @param array    $checkout_data Respuesta completa de GET /v0.1/checkouts/{id}.
     * @param WC_Order $order         La orden de WooCommerce.
     * @return bool True si los montos coinciden; false si hay discrepancia.
     */
    private function validate_amount( array $checkout_data, WC_Order $order ): bool {
        if ( ! isset( $checkout_data['amount'] ) ) {
            $this->log( 'warning', sprintf(
                'Campo "amount" ausente en la respuesta de SumUp para Pedido #%d.',
                $order->get_id()
            ) );
            return false;
        }

        $sumup_amount = round( (float) $checkout_data['amount'], 2 );
        $order_total  = round( (float) $order->get_total(), 2 );
        $tolerance    = 0.01;

        $is_valid = ( abs( $sumup_amount - $order_total ) <= $tolerance );

        if ( ! $is_valid ) {
            $this->log( 'critical', sprintf(
                'Discrepancia de monto — SumUp: %.2f | Orden: %.2f | Diff: %.4f',
                $sumup_amount,
                $order_total,
                abs( $sumup_amount - $order_total )
            ) );
        }

        return $is_valid;
    }

    /**
     * Verifica que el checkout_reference de SumUp coincide con el guardado
     * en el meta de la orden.
     *
     * Previene ataques de replay donde un atacante intenta reutilizar el
     * checkout_id de una orden ajena para confirmar otra.
     *
     * @param array    $checkout_data Respuesta completa de GET /v0.1/checkouts/{id}.
     * @param WC_Order $order         La orden de WooCommerce.
     * @return bool True si la referencia coincide; false si hay discrepancia.
     */
    private function validate_reference( array $checkout_data, WC_Order $order ): bool {
        $sumup_ref = sanitize_text_field( $checkout_data['checkout_reference'] ?? '' );
        $meta_ref  = $order->get_meta( self::META_CHECKOUT_REF );

        if ( empty( $sumup_ref ) || empty( $meta_ref ) ) {
            $this->log( 'warning', sprintf(
                'Referencia de checkout vacía — SumUp: "%s" | Meta: "%s" | Pedido #%d.',
                $sumup_ref,
                $meta_ref,
                $order->get_id()
            ) );
            return false;
        }

        $is_valid = hash_equals( $meta_ref, $sumup_ref );

        if ( ! $is_valid ) {
            $this->log( 'critical', sprintf(
                'Referencia no coincide — SumUp: "%s" | Meta: "%s" | Pedido #%d.',
                $sumup_ref,
                $meta_ref,
                $order->get_id()
            ) );
        }

        return $is_valid;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Busca una orden WooCommerce por el ID de checkout de SumUp.
     *
     * @param string $checkout_id UUID del checkout.
     * @return WC_Order|null La orden encontrada o null.
     */
    private function get_order_by_checkout_id( string $checkout_id ): ?WC_Order {
        if ( empty( $checkout_id ) ) {
            return null;
        }

        $orders = wc_get_orders( array(
            'meta_key'     => self::META_CHECKOUT_ID,
            'meta_value'   => $checkout_id,
            'meta_compare' => '=',
            'limit'        => 1,
            'return'       => 'objects',
            'status'       => array( 'wc-pending', 'wc-on-hold', 'wc-processing' ),
        ) );

        return ! empty( $orders ) ? $orders[0] : null;
    }

    /**
     * Retorna la URL del endpoint webhook para mostrar en el panel de admin.
     *
     * @return string URL completa del endpoint.
     */
    public static function get_webhook_url(): string {
        return add_query_arg( 'wc-api', self::ENDPOINT, trailingslashit( home_url() ) );
    }

    /**
     * Envía una notificación al administrador cuando se detecta una
     * posible manipulación de pago (monto o referencia incorrectos).
     *
     * @param WC_Order    $order        La orden afectada.
     * @param string      $checkout_id  UUID del checkout.
     * @param float|null  $sumup_amount Monto de SumUp (null si la alerta es por referencia).
     * @param float|null  $order_total  Total de la orden (null si la alerta es por referencia).
     * @param string      $alert_type   Tipo de alerta: 'amount_mismatch' | 'reference_mismatch'.
     * @return void
     */
    private function notify_admin_security_alert(
        WC_Order $order,
        string $checkout_id,
        ?float $sumup_amount,
        ?float $order_total,
        string $alert_type = 'amount_mismatch'
    ): void {
        $admin_email = get_option( 'admin_email' );
        $site_name   = get_bloginfo( 'name' );
        $order_id    = $order->get_id();
        $order_url   = $order->get_edit_order_url();

        if ( 'amount_mismatch' === $alert_type ) {
            $subject = sprintf( '[%s] ⚠️ ALERTA: Posible manipulación de pago — Pedido #%d', $site_name, $order_id );
            $message = sprintf(
                "Se detectó una discrepancia de monto en el pago SumUp para el Pedido #%d.\n\n" .
                "Monto reportado por SumUp: %.2f %s\n" .
                "Total de la orden en WooCommerce: %.2f %s\n" .
                "Checkout ID: %s\n\n" .
                "El pago NO fue procesado automáticamente.\n" .
                "Revisa la orden manualmente: %s",
                $order_id,
                $sumup_amount,
                get_woocommerce_currency(),
                $order_total,
                get_woocommerce_currency(),
                $checkout_id,
                $order_url
            );
        } else {
            $subject = sprintf( '[%s] ⚠️ ALERTA: Referencia de checkout inválida — Pedido #%d', $site_name, $order_id );
            $message = sprintf(
                "Se detectó una referencia de checkout inválida en el pago SumUp para el Pedido #%d.\n\n" .
                "Checkout ID: %s\n\n" .
                "El pago NO fue procesado automáticamente.\n" .
                "Revisa la orden manualmente: %s",
                $order_id,
                $checkout_id,
                $order_url
            );
        }

        wp_mail( $admin_email, $subject, $message );
    }

    /**
     * Notifica al administrador cuando se agotaron los reintentos para
     * un pago en estado PENDING.
     *
     * @param WC_Order $order       La orden afectada.
     * @param string   $checkout_id UUID del checkout.
     * @return void
     */
    private function notify_admin_pending_timeout( WC_Order $order, string $checkout_id ): void {
        $admin_email = get_option( 'admin_email' );
        $site_name   = get_bloginfo( 'name' );
        $order_id    = $order->get_id();
        $order_url   = $order->get_edit_order_url();

        $subject = sprintf(
            '[%s] Pago SumUp pendiente sin resolución — Pedido #%d',
            $site_name,
            $order_id
        );

        $message = sprintf(
            "El pago SumUp del Pedido #%d lleva %d intentos en estado PENDING sin resolverse.\n\n" .
            "Checkout ID: %s\n\n" .
            "Acciones recomendadas:\n" .
            "1. Verifica el estado en tu dashboard de SumUp.\n" .
            "2. Si el pago fue acreditado, actualiza manualmente el estado del pedido a 'Procesando'.\n" .
            "3. Si el pago falló, actualiza el estado a 'Fallido'.\n\n" .
            "Ver pedido: %s",
            $order_id,
            self::MAX_ATTEMPTS,
            $checkout_id,
            $order_url
        );

        wp_mail( $admin_email, $subject, $message );
    }

    /**
     * Envía la respuesta HTTP y detiene la ejecución.
     *
     * @param array $data       Datos de la respuesta (serializados a JSON).
     * @param int   $http_code  Código HTTP de la respuesta.
     * @return void
     */
    private function send_response( array $data, int $http_code ): void {
        wp_send_json( $data, $http_code );
    }

    /**
     * Inicializa el logger de WooCommerce.
     *
     * @return void
     */
    private function init_logger(): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Registra un mensaje prefijado en el log de WooCommerce.
     *
     * @param string $level   Nivel PSR-3: emergency|alert|critical|error|warning|notice|info|debug.
     * @param string $message Mensaje a registrar.
     * @param array  $context Datos adicionales opcionales.
     * @return void
     */
    private function log( string $level, string $message, array $context = array() ): void {
        if ( ! $this->logger ) {
            return;
        }

        $context['source'] = self::LOG_SOURCE;
        $this->logger->log( $level, '[RestoHub SumUp Webhook] ' . $message, $context );
    }
}
