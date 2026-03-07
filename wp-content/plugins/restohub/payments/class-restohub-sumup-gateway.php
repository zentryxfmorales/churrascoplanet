<?php
/**
 * WooCommerce Payment Gateway: SumUp (RestoHub)
 *
 * Integra la API REST de SumUp directamente en el checkout de RestoHub,
 * sin depender del plugin de terceros "SumUp Payment Gateway for WooCommerce".
 *
 * Flujo de pago completo:
 *  1. El cliente llena el checkout y hace clic en "Pagar".
 *  2. nuestro JS intercepta el evento WC checkout_place_order_restohub_sumup
 *     y hace su propio AJAX POST al endpoint de checkout de WooCommerce.
 *  3. process_payment() crea el pedido WC + el checkout en SumUp y devuelve
 *     { result:'success', checkoutId, redirectUrl }.
 *  4. El JS monta SumUpCard.mount({ checkoutId }) en el contenedor inline.
 *  5. El cliente ingresa su tarjeta. SumUp gestiona 3DS si aplica.
 *  6. onResponse('success', { status:'PAID' }) → JS redirige a thank-you.
 *  7. En paralelo, el webhook recibe CHECKOUT_STATUS_CHANGED y confirma
 *     el pedido vía ActionScheduler (resiliencia ante fallos de red del cliente).
 *  8. Para 3DS con redirect: check_redirect_flow() lee el retorno y actúa.
 *
 * @package RestoHub
 * @subpackage Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class RestoHub_SumUp_Gateway
 *
 * @extends WC_Payment_Gateway
 */
class RestoHub_SumUp_Gateway extends WC_Payment_Gateway {

    // =========================================================================
    // Constantes
    // =========================================================================

    /** ID único del método de pago en WooCommerce. */
    public const METHOD_ID = 'restohub_sumup';

    /** Canal de log visible en WooCommerce > Estado > Registros. */
    private const LOG_SOURCE = 'restohub-sumup';

    /**
     * Parámetro GET usado para identificar el retorno de 3DS.
     * URL resultante: ?sumup-rh-return=1&order_id=X&order_key=Y
     */
    private const RETURN_PARAM = 'sumup-rh-return';

    /** URL del SDK de SumUp Card (CDN oficial). */
    private const SUMUP_SDK_URL = 'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js';

    // =========================================================================
    // Propiedades
    // =========================================================================

    /** @var RestoHub_SumUp_API Wrapper de la API REST de SumUp. */
    private RestoHub_SumUp_API $api;

    /** @var WC_Logger_Interface|null Logger de WooCommerce. */
    private ?WC_Logger_Interface $logger = null;

    // =========================================================================
    // Constructor
    // =========================================================================

    public function __construct() {
        // ── Propiedades requeridas por WC_Payment_Gateway ───────────────────
        $this->id                 = self::METHOD_ID;
        $this->method_title       = __( 'SumUp — RestoHub', 'restohub' );
        $this->method_description = __( 'Acepta pagos con tarjeta de crédito y débito a través de SumUp, sin comisiones por plataforma de terceros.', 'restohub' );
        $this->has_fields         = true;
        $this->supports           = array( 'products', 'refunds' );

        // ── Cargar configuración ─────────────────────────────────────────────
        $this->init_form_fields();
        $this->init_settings();

        // ── Leer configuración en propiedades de la clase ────────────────────
        $this->title       = $this->get_option( 'title', __( 'Pago con tarjeta', 'restohub' ) );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled', 'no' );

        // ── Dependencias ─────────────────────────────────────────────────────
        $this->api = new RestoHub_SumUp_API();
        $this->init_logger();

        // ── Hooks ────────────────────────────────────────────────────────────

        // Guardar configuración desde el formulario de admin
        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            array( $this, 'process_admin_options' )
        );

        // Encolar assets solo en la página de checkout
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );

        // Manejar el retorno del banco después de 3DS (prioridad alta)
        add_action( 'template_redirect', array( $this, 'check_redirect_flow' ), 99 );

        // Plan B: chequeo síncrono en la página de confirmación.
        // Prioridad 50 → se ejecuta ANTES que check_redirect_flow (99) y
        // ANTES de que WordPress empiece a renderizar la plantilla, lo que
        // permite hacer un redirect limpio si el pago ya fue acreditado.
        add_action( 'template_redirect', array( $this, 'maybe_sync_payment_status' ), 50 );
    }

    // =========================================================================
    // Configuración del panel de administración
    // =========================================================================

    /**
     * Define los campos del formulario de configuración del gateway en
     * WooCommerce > Ajustes > Pagos > SumUp — RestoHub.
     *
     * @return void
     */
    public function init_form_fields(): void {
        $webhook_url = class_exists( 'RestoHub_SumUp_Webhook' )
            ? RestoHub_SumUp_Webhook::get_webhook_url()
            : home_url( '/?wc-api=restohub_sumup' );

        $this->form_fields = array(

            // ── General ──────────────────────────────────────────────────────
            'enabled' => array(
                'title'   => __( 'Activar / Desactivar', 'restohub' ),
                'type'    => 'checkbox',
                'label'   => __( 'Activar pagos con SumUp', 'restohub' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Título', 'restohub' ),
                'type'        => 'text',
                'description' => __( 'Nombre visible para el cliente en el checkout.', 'restohub' ),
                'default'     => __( 'Pago con tarjeta', 'restohub' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Descripción', 'restohub' ),
                'type'        => 'textarea',
                'description' => __( 'Texto de apoyo visible al seleccionar este método de pago.', 'restohub' ),
                'default'     => __( 'Paga de forma segura con tu tarjeta de crédito o débito.', 'restohub' ),
                'desc_tip'    => true,
            ),

            // ── Credenciales ─────────────────────────────────────────────────
            'credentials_title' => array(
                'title'       => __( 'Credenciales de SumUp', 'restohub' ),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: URL to SumUp developer portal */
                    __( 'Obtén tu API Key en <a href="%s" target="_blank" rel="noopener">developer.sumup.com</a>. Si las credenciales están definidas en <code>wp-config.php</code>, estos campos son ignorados.', 'restohub' ),
                    'https://developer.sumup.com'
                ),
            ),
            'sumup_api_key' => array(
                'title'       => __( 'API Key', 'restohub' ),
                'type'        => 'password',
                'description' => __( 'Recomendado. Constante alternativa en wp-config.php: <code>RESTOHUB_SUMUP_API_KEY</code>.', 'restohub' ),
                'default'     => '',
            ),
            'sumup_merchant_code' => array(
                'title'       => __( 'Merchant Code', 'restohub' ),
                'type'        => 'text',
                'description' => __( 'Código de comercio SumUp (ej: MXXXXXXX). Constante alternativa: <code>RESTOHUB_SUMUP_MERCHANT_CODE</code>.', 'restohub' ),
                'default'     => '',
                'desc_tip'    => false,
            ),
            'sumup_pay_to_email' => array(
                'title'       => __( 'Email del comercio (fallback)', 'restohub' ),
                'type'        => 'email',
                'description' => __( 'Solo si no tienes Merchant Code configurado.', 'restohub' ),
                'default'     => '',
                'desc_tip'    => true,
            ),

            // ── Webhook ───────────────────────────────────────────────────────
            'webhook_title' => array(
                'title'       => __( 'URL del Webhook', 'restohub' ),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: webhook URL */
                    __( 'Configura esta URL en tu <a href="https://me.sumup.com/developers" target="_blank" rel="noopener">dashboard de SumUp</a> para recibir notificaciones de pago:<br><code>%s</code>', 'restohub' ),
                    esc_url( $webhook_url )
                ),
            ),

            // ── OAuth (alternativa a API Key) ─────────────────────────────────
            'oauth_title' => array(
                'title'       => __( 'OAuth — Alternativa a API Key', 'restohub' ),
                'type'        => 'title',
                'description' => __( 'Solo si no usas API Key directa. El par Client ID + Client Secret se usa con OAuth client_credentials. Las constantes en wp-config.php tienen precedencia.', 'restohub' ),
            ),
            'sumup_client_id' => array(
                'title'   => __( 'Client ID', 'restohub' ),
                'type'    => 'text',
                'default' => '',
            ),
            'sumup_client_secret' => array(
                'title'   => __( 'Client Secret', 'restohub' ),
                'type'    => 'password',
                'default' => '',
            ),
        );
    }

    /**
     * Sobreescribe process_admin_options para sincronizar la configuración
     * del gateway con las opciones globales de RestoHub que usa la API.
     *
     * @return bool
     */
    public function process_admin_options(): bool {
        $saved = parent::process_admin_options();

        if ( $saved ) {
            // Sincronizar campos de SumUp al array de opciones globales de RestoHub
            // para que RestoHub_SumUp_API los lea desde 'restohub_settings'.
            $global_settings = get_option( 'restohub_settings', array() );

            $sync_keys = array(
                'sumup_api_key',
                'sumup_merchant_code',
                'sumup_pay_to_email',
                'sumup_client_id',
                'sumup_client_secret',
            );

            foreach ( $sync_keys as $key ) {
                $value = $this->get_option( $key );
                if ( ! empty( $value ) ) {
                    $global_settings[ $key ] = $value;
                }
            }

            update_option( 'restohub_settings', $global_settings );

            // Limpiar el caché del token para que se regenere con las nuevas credenciales
            $this->api->invalidate_token_cache();

            $this->log( 'info', 'Configuración de SumUp actualizada. Caché de token limpiado.' );
        }

        return $saved;
    }

    // =========================================================================
    // Disponibilidad del método de pago
    // =========================================================================

    /**
     * Determina si el gateway está disponible para mostrarse en el checkout.
     *
     * Condiciones para estar disponible:
     *  - Gateway habilitado en la configuración.
     *  - Credenciales configuradas (API Key u OAuth).
     *  - Conexión SSL activa (no exponer datos de tarjeta sin HTTPS).
     *
     * @return bool
     */
    public function is_available(): bool {
        if ( ! parent::is_available() ) {
            return false;
        }

        // Requerir HTTPS en producción
        if ( ! is_ssl() && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            $this->log( 'debug', 'Gateway no disponible: SSL no activo.' );
            return false;
        }

        if ( ! $this->api->is_configured() ) {
            $this->log( 'debug', 'Gateway no disponible: credenciales no configuradas.' );
            return false;
        }

        return true;
    }

    // =========================================================================
    // Formulario de pago en el checkout (frontend)
    // =========================================================================

    /**
     * Renderiza el área de pago que el cliente ve cuando selecciona SumUp.
     *
     * En esta fase solo se muestra la descripción e iconos. El widget de tarjeta
     * (SumUpCard) se monta dinámicamente por el JS en #restohub-sumup-card
     * DESPUÉS de que process_payment() devuelve el checkoutId vía AJAX.
     *
     * @return void
     */
    public function payment_fields(): void {
        // Descripción del método de pago
        if ( $this->description ) {
            echo '<p class="restohub-sumup-desc">' . wp_kses_post( $this->description ) . '</p>';
        }

        // Iconos de tarjetas aceptadas
        echo '<p class="restohub-sumup-cards">';
        echo '<span class="restohub-sumup-card-icon" title="Visa">VISA</span>';
        echo '<span class="restohub-sumup-card-icon" title="Mastercard">MC</span>';
        echo '<span class="restohub-sumup-card-icon" title="American Express">AMEX</span>';
        echo '</p>';

        // Contenedor del widget SumUpCard (oculto hasta que el AJAX devuelve el checkoutId)
        echo '<div id="restohub-sumup-widget-wrap" style="display:none;">';
        echo '  <div id="restohub-sumup-card"></div>';
        echo '  <p id="restohub-sumup-widget-error" class="restohub-sumup-error" style="display:none;"></p>';
        echo '</div>';

        // Indicador de carga (visible mientras se procesa el AJAX)
        echo '<div id="restohub-sumup-loading" class="restohub-sumup-loading" style="display:none;">';
        echo '  <span class="restohub-sumup-spinner"></span>';
        echo '  <span>' . esc_html__( 'Preparando pasarela de pago...', 'restohub' ) . '</span>';
        echo '</div>';
    }

    // =========================================================================
    // Proceso de pago
    // =========================================================================

    /**
     * Crea el pedido WC y el checkout en SumUp.
     *
     * Llamado por WooCommerce cuando el cliente envía el formulario de checkout.
     * Retorna un array que nuestro JS interpreta para montar el widget.
     *
     * Idempotencia:
     *   Si ya existe un _restohub_sumup_checkout_id con estado PENDING,
     *   se reutiliza sin llamar a la API. Esto cubre el caso de recargar
     *   la página o de reintentar tras un error de red en el cliente.
     *
     * Resiliencia:
     *   Si la API de SumUp devuelve 5xx o timeout, se agrega wc_add_notice()
     *   y se retorna 'failure'. El pedido queda en 'pending-payment' y el
     *   carrito NO se vacía, permitiendo al cliente reintentar.
     *
     * @param int $order_id ID del pedido de WooCommerce.
     * @return array { result: 'success'|'failure', checkoutId?, redirectUrl?, redirect? }
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order || ! ( $order instanceof WC_Order ) ) {
            $this->log( 'error', sprintf( 'Pedido #%d no encontrado en process_payment.', $order_id ) );
            wc_add_notice( __( 'Error al procesar el pedido. Por favor intenta nuevamente.', 'restohub' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // ── Idempotencia: reutilizar checkout existente si está PENDING ───────
        $existing_checkout_id = $order->get_meta( RestoHub_SumUp_Webhook::META_CHECKOUT_ID );

        if ( ! empty( $existing_checkout_id ) ) {
            $reuse = $this->try_reuse_checkout( $order, $existing_checkout_id );
            if ( null !== $reuse ) {
                return $reuse;
            }
            // Si no se puede reutilizar (PAID/FAILED/error), crear uno nuevo
        }

        // ── Construir URL de retorno 3DS ──────────────────────────────────────
        $redirect_url = $this->get_3ds_return_url( $order );

        // ── Crear checkout en SumUp ───────────────────────────────────────────
        $checkout_data = $this->api->create_checkout( $order, $redirect_url );

        if ( is_wp_error( $checkout_data ) ) {
            $this->log( 'error', sprintf(
                'Fallo al crear checkout SumUp para Pedido #%d: [%s] %s',
                $order_id,
                $checkout_data->get_error_code(),
                $checkout_data->get_error_message()
            ) );

            // Mensaje amigable al cliente (ya viene formateado desde la API)
            wc_add_notice( $checkout_data->get_error_message(), 'error' );

            // El pedido queda en pending-payment; el carrito NO se vacía.
            // WooCommerce preserva el carrito cuando process_payment retorna 'failure'.
            return array( 'result' => 'failure' );
        }

        $checkout_id = sanitize_text_field( $checkout_data['id'] ?? '' );

        if ( empty( $checkout_id ) ) {
            $this->log( 'error', sprintf(
                'SumUp devolvió respuesta exitosa sin "id" para Pedido #%d.',
                $order_id
            ) );
            wc_add_notice( __( 'Respuesta inesperada del servicio de pago. Por favor intenta nuevamente.', 'restohub' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // ── Guardar checkout_id en el order meta ──────────────────────────────
        $order->update_meta_data( RestoHub_SumUp_Webhook::META_CHECKOUT_ID, $checkout_id );
        $order->add_order_note( sprintf(
            /* translators: SumUp checkout UUID */
            __( 'Checkout SumUp iniciado. ID: %s', 'restohub' ),
            $checkout_id
        ) );
        $order->save();

        $this->log( 'info', sprintf(
            'process_payment exitoso — Pedido #%d | checkout_id="%s".',
            $order_id,
            $checkout_id
        ) );

        return $this->build_success_response( $order, $checkout_id );
    }

    // =========================================================================
    // Plan B: Chequeo síncrono de pago en la página de confirmación
    // =========================================================================

    /**
     * Valida el estado del pago en SumUp al cargar la página de confirmación.
     *
     * Problema que resuelve:
     *   El flujo normal confía en el webhook de SumUp para confirmar el pedido.
     *   Si el webhook llega tarde o falla, el pedido queda en 'pending' aunque
     *   SumUp ya procesó el pago, y el cliente ve la thank-you page sin
     *   confirmación clara.
     *
     * Estrategia:
     *   Al cargar la página order-received, si el pedido es nuestro y sigue
     *   en 'pending' o 'on-hold', consultamos la API de SumUp directamente.
     *   Si SumUp confirma PAID, llamamos payment_complete() y redirigimos a
     *   la misma URL — la segunda carga renderiza el estado correcto.
     *   Si la API falla o el estado es PENDING, fallamos silenciosamente y
     *   dejamos que el webhook resuelva en background.
     *
     * Seguridad:
     *   - Se excluye el flujo 3DS (RETURN_PARAM en la URL) para no interferir
     *     con check_redirect_flow() que ya maneja ese caso.
     *   - Se valida order_key con hash_equals() para evitar forzar confirmación
     *     de pedidos ajenos.
     *   - META_PROCESSED previene doble procesamiento si el webhook llega justo
     *     antes de este chequeo.
     *
     * Hook: template_redirect (prioridad 50 — antes del rendering y de 3DS).
     *
     * @return void
     */
    public function maybe_sync_payment_status(): void {
        // 1. Salir rápido si no estamos en la página de confirmación
        if ( ! is_order_received_page() ) {
            return;
        }

        // 2. Excluir retornos de 3DS — check_redirect_flow() los maneja (prioridad 99)
        if ( ! empty( $_GET[ self::RETURN_PARAM ] ) ) {
            return;
        }

        // 3. Obtener y validar el pedido desde la URL
        $order_id  = absint( get_query_var( 'order-received' ) );
        $order_key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );

        if ( ! $order_id || ! $order_key ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Validar order_key con tiempo constante para evitar timing attacks
        if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
            $this->log( 'warning', sprintf(
                'Chequeo síncrono: order_key inválida para Pedido #%d. Abortando.',
                $order_id
            ) );
            return;
        }

        // 4. Solo nuestro gateway
        if ( $order->get_payment_method() !== self::METHOD_ID ) {
            return;
        }

        // 5. Solo estados que indican pago pendiente de confirmar
        if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
            return;
        }

        // 6. Idempotencia: si ya fue procesado por webhook u otro hilo, no actuar
        if ( '1' === $order->get_meta( RestoHub_SumUp_Webhook::META_PROCESSED ) ) {
            return;
        }

        // 7. Necesitamos el checkout_id para consultar SumUp
        $checkout_id = $order->get_meta( RestoHub_SumUp_Webhook::META_CHECKOUT_ID );

        if ( empty( $checkout_id ) ) {
            $this->log( 'warning', sprintf(
                'Chequeo síncrono: Pedido #%d sin checkout_id en meta. Omitido.',
                $order_id
            ) );
            return;
        }

        // 8. Consultar el estado actual en SumUp
        $this->log( 'info', sprintf(
            'Chequeo síncrono iniciado — Pedido #%d | checkout_id="%s" | status="%s".',
            $order_id,
            $checkout_id,
            $order->get_status()
        ) );

        $checkout_data = $this->api->get_checkout( $checkout_id );

        if ( is_wp_error( $checkout_data ) ) {
            // Fallo de red o API — dejar que el webhook lo resuelva en background
            $this->log( 'warning', sprintf(
                'Chequeo síncrono: API SumUp no disponible para Pedido #%d: %s. El webhook resolverá.',
                $order_id,
                $checkout_data->get_error_message()
            ) );
            return;
        }

        $sumup_status     = strtoupper( sanitize_text_field( $checkout_data['status']           ?? '' ) );
        $transaction_code = sanitize_text_field(             $checkout_data['transaction_code'] ?? '' );

        $this->log( 'info', sprintf(
            'Chequeo síncrono — Pedido #%d | SumUp status="%s" | tx="%s".',
            $order_id,
            $sumup_status,
            $transaction_code
        ) );

        // Solo actuar si SumUp confirma el pago
        if ( 'PAID' !== $sumup_status ) {
            // PENDING o FAILED: dejar que el webhook lo resuelva
            return;
        }

        // 9. Confirmar el pago (idempotente gracias a META_PROCESSED)
        $order->update_meta_data( RestoHub_SumUp_Webhook::META_TRANSACTION, $transaction_code );
        $order->update_meta_data( RestoHub_SumUp_Webhook::META_PROCESSED, '1' );
        $order->save_meta_data();
        $order->payment_complete( $transaction_code );
        $order->add_order_note( sprintf(
            /* translators: SumUp transaction code */
            __( 'Pago confirmado vía chequeo síncrono (Plan B). El webhook llegó tarde o falló. Código: %s', 'restohub' ),
            $transaction_code ?: __( 'no disponible', 'restohub' )
        ) );

        $this->log( 'info', sprintf(
            'Chequeo síncrono exitoso — Pedido #%d confirmado. Redirigiendo.',
            $order_id
        ) );

        // 10. Redirigir a la misma URL para que la página renderice con el nuevo estado
        wp_safe_redirect( $order->get_checkout_order_received_url() );
        exit;
    }

    // =========================================================================
    // Retorno de 3DS
    // =========================================================================

    /**
     * Maneja el retorno del banco tras la autenticación 3D Secure.
     *
     * SumUp redirige al navegador del cliente a la redirect_url configurada
     * en el checkout cuando el flujo 3DS termina. Este método se ejecuta en
     * template_redirect (prioridad 99), lee el parámetro GET, consulta el
     * estado actual del checkout en SumUp y toma la acción correspondiente.
     *
     * Seguridad: valida el order_key para evitar que un atacante fuerce la
     * confirmación de pedidos ajenos manipulando el order_id en la URL.
     *
     * @return void
     */
    public function check_redirect_flow(): void {
        // Solo actuar cuando está presente nuestro parámetro de retorno
        if ( empty( $_GET[ self::RETURN_PARAM ] ) ) {
            return;
        }

        $order_id  = absint( $_GET['order_id']  ?? 0 );
        $order_key = sanitize_text_field( $_GET['order_key'] ?? '' );

        if ( ! $order_id || ! $order_key ) {
            $this->log( 'warning', 'Retorno 3DS con parámetros incompletos.' );
            wp_safe_redirect( home_url() );
            exit;
        }

        $order = wc_get_order( $order_id );

        // Validar que la order_key coincide (constante en tiempo para evitar timing attack)
        if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
            $this->log( 'warning', sprintf(
                'Retorno 3DS con order_key inválida para Pedido #%d.',
                $order_id
            ) );
            wp_safe_redirect( home_url() );
            exit;
        }

        $checkout_id = $order->get_meta( RestoHub_SumUp_Webhook::META_CHECKOUT_ID );

        if ( empty( $checkout_id ) ) {
            $this->log( 'error', sprintf(
                'Retorno 3DS: checkout_id vacío para Pedido #%d.',
                $order_id
            ) );
            wp_safe_redirect( $order->get_checkout_payment_url() );
            exit;
        }

        // ── Consultar estado actual en SumUp ──────────────────────────────────
        $checkout_data = $this->api->get_checkout( $checkout_id );

        if ( is_wp_error( $checkout_data ) ) {
            $this->log( 'error', sprintf(
                'Retorno 3DS: error consultando checkout para Pedido #%d: %s',
                $order_id,
                $checkout_data->get_error_message()
            ) );
            wc_add_notice( $checkout_data->get_error_message(), 'error' );
            wp_safe_redirect( $order->get_checkout_payment_url() );
            exit;
        }

        $status           = strtoupper( sanitize_text_field( $checkout_data['status'] ?? '' ) );
        $transaction_code = sanitize_text_field( $checkout_data['transaction_code'] ?? '' );

        $this->log( 'info', sprintf(
            'Retorno 3DS — Pedido #%d | status="%s" | transaction_code="%s".',
            $order_id,
            $status,
            $transaction_code
        ) );

        switch ( $status ) {

            case 'PAID':
                // Confirmar inmediatamente el pago en lugar de esperar el webhook.
                // El webhook también intentará confirmar, pero la idempotencia
                // (META_PROCESSED) garantiza que no se procese dos veces.
                if ( '1' !== $order->get_meta( RestoHub_SumUp_Webhook::META_PROCESSED ) ) {
                    $order->update_meta_data( RestoHub_SumUp_Webhook::META_TRANSACTION, $transaction_code );
                    $order->update_meta_data( RestoHub_SumUp_Webhook::META_PROCESSED, '1' );
                    $order->save_meta_data();
                    $order->payment_complete( $transaction_code );
                    $order->add_order_note( sprintf(
                        /* translators: transaction code */
                        __( 'Pago confirmado vía retorno 3DS. Código: %s', 'restohub' ),
                        $transaction_code
                    ) );
                }
                wp_safe_redirect( $order->get_checkout_order_received_url() );
                exit;

            case 'FAILED':
                $order->update_status(
                    'failed',
                    __( 'Pago rechazado tras autenticación 3DS.', 'restohub' )
                );
                wc_add_notice(
                    __( 'Tu pago fue rechazado. Por favor intenta con otra tarjeta.', 'restohub' ),
                    'error'
                );
                wp_safe_redirect( $order->get_checkout_payment_url() );
                exit;

            case 'PENDING':
                // El banco está procesando; redirigir a thank-you con aviso.
                // El webhook confirmará el estado final cuando SumUp lo resuelva.
                $order->add_order_note(
                    __( 'Cliente retornó de 3DS. Pago en estado PENDING — confirmación pendiente del banco vía webhook.', 'restohub' )
                );
                wc_add_notice(
                    __( 'Tu pago está siendo verificado por el banco. Te notificaremos por email cuando se confirme.', 'restohub' ),
                    'notice'
                );
                wp_safe_redirect( $order->get_checkout_order_received_url() );
                exit;

            default:
                $this->log( 'error', sprintf(
                    'Retorno 3DS con estado desconocido "%s" para Pedido #%d.',
                    $status,
                    $order_id
                ) );
                wc_add_notice(
                    __( 'Estado de pago desconocido. Contacta al soporte con tu número de pedido.', 'restohub' ),
                    'error'
                );
                wp_safe_redirect( $order->get_checkout_payment_url() );
                exit;
        }
    }

    // =========================================================================
    // Assets (JS / CSS)
    // =========================================================================

    /**
     * Encola el SDK de SumUp y nuestros assets de checkout.
     *
     * Solo se encolan en la página de checkout (is_checkout()) y cuando
     * el gateway está disponible, para no afectar el rendimiento del resto
     * del sitio.
     *
     * @return void
     */
    public function enqueue_checkout_assets(): void {
        if ( ! is_checkout() || ! $this->is_available() ) {
            return;
        }

        // ── SumUp Card SDK (CDN oficial) ─────────────────────────────────────
        wp_enqueue_script(
            'sumup-card-sdk',
            self::SUMUP_SDK_URL,
            array(),
            null, // sin versión; SumUp gestiona la suya
            false // en <head>, necesario para que SumUpCard esté disponible al cargar el DOM
        );

        // ── Nuestro JS del checkout ───────────────────────────────────────────
        wp_enqueue_script(
            'restohub-sumup-checkout',
            RESTOHUB_PLUGIN_URL . 'assets/js/restohub-sumup-checkout.js',
            array( 'jquery', 'sumup-card-sdk' ),
            RESTOHUB_VERSION,
            true // en footer
        );

        // ── CSS del widget adaptado al tema oscuro ────────────────────────────
        wp_enqueue_style(
            'restohub-sumup-checkout',
            RESTOHUB_PLUGIN_URL . 'assets/css/restohub-sumup.css',
            array(),
            RESTOHUB_VERSION
        );

        // ── Localizar variables para el JS ────────────────────────────────────
        wp_localize_script(
            'restohub-sumup-checkout',
            'restoHubSumUp',
            array(
                'methodId'  => self::METHOD_ID,
                'locale'    => $this->get_sumup_locale(),
                'country'   => $this->get_sumup_country(),
                'i18n'      => array(
                    'loading'        => __( 'Preparando pasarela de pago...', 'restohub' ),
                    'generalError'   => __( 'Error al procesar el pago. Por favor intenta nuevamente.', 'restohub' ),
                    'cardError'      => __( 'Datos de tarjeta inválidos. Verifica e intenta nuevamente.', 'restohub' ),
                    'paymentFailed'  => __( 'Pago rechazado. Por favor intenta con otra tarjeta.', 'restohub' ),
                    'paymentPending' => __( 'Tu pago está siendo verificado. Te notificaremos por email.', 'restohub' ),
                ),
            )
        );
    }

    // =========================================================================
    // Registro del gateway en WooCommerce
    // =========================================================================

    /**
     * Agrega el gateway a la lista de métodos de pago de WooCommerce.
     *
     * Llamar desde el hook 'woocommerce_payment_gateways' en restohub.php:
     *   add_filter('woocommerce_payment_gateways', ['RestoHub_SumUp_Gateway', 'register']);
     *
     * @param array $methods Métodos de pago existentes.
     * @return array Métodos actualizados.
     */
    public static function register( array $methods ): array {
        $methods[] = self::class;
        return $methods;
    }

    // =========================================================================
    // Métodos privados auxiliares
    // =========================================================================

    /**
     * Intenta reutilizar un checkout SumUp existente para evitar duplicados.
     *
     * Solo reutiliza si el checkout está en estado PENDING (no pagado, no fallido).
     * Si está PAID o FAILED, retorna null para que process_payment cree uno nuevo.
     *
     * @param WC_Order $order       La orden de WooCommerce.
     * @param string   $checkout_id UUID del checkout existente.
     * @return array|null Array de respuesta exitosa si se reutiliza, null si no.
     */
    private function try_reuse_checkout( WC_Order $order, string $checkout_id ): ?array {
        $checkout_data = $this->api->get_checkout( $checkout_id );

        if ( is_wp_error( $checkout_data ) ) {
            // No se puede consultar el checkout existente; crear uno nuevo
            $this->log( 'warning', sprintf(
                'No se pudo consultar checkout existente "%s" para reutilización: %s.',
                $checkout_id,
                $checkout_data->get_error_message()
            ) );
            return null;
        }

        $status = strtoupper( $checkout_data['status'] ?? '' );

        if ( 'PENDING' === $status ) {
            $this->log( 'info', sprintf(
                'Reutilizando checkout PENDING existente "%s" para Pedido #%d.',
                $checkout_id,
                $order->get_id()
            ) );
            return $this->build_success_response( $order, $checkout_id );
        }

        // PAID o FAILED: no reutilizar
        $this->log( 'info', sprintf(
            'Checkout existente "%s" tiene estado "%s" — se creará uno nuevo.',
            $checkout_id,
            $status
        ) );
        return null;
    }

    /**
     * Construye el array de respuesta exitosa que nuestro JS necesita para
     * montar el widget de SumUpCard.
     *
     * WooCommerce leerá 'result' = 'success' y normalmente redirigiría a 'redirect'.
     * Nuestro JS intercepta el evento checkout_place_order_restohub_sumup y
     * retorna false antes de que WC haga el submit, así que WC nunca ve esta
     * respuesta directamente — la procesa nuestro propio AJAX handler.
     *
     * @param WC_Order $order       La orden de WooCommerce.
     * @param string   $checkout_id UUID del checkout de SumUp.
     * @return array
     */
    private function build_success_response( WC_Order $order, string $checkout_id ): array {
        return array(
            'result'      => 'success',
            // 'redirect' es requerido por WC pero nuestro JS nunca navega a él;
            // '#' evita que WC lo interprete como una URL de redirect real.
            'redirect'    => '#',
            'checkoutId'  => $checkout_id,
            'redirectUrl' => $order->get_checkout_order_received_url(),
            'orderId'     => $order->get_id(),
            'orderKey'    => $order->get_order_key(),
        );
    }

    /**
     * Construye la URL de retorno para el flujo 3DS.
     *
     * SumUp redirige el navegador del cliente a esta URL tras la
     * autenticación 3D Secure. Incluye order_id y order_key para
     * que check_redirect_flow() pueda identificar y validar el pedido.
     *
     * @param WC_Order $order La orden de WooCommerce.
     * @return string URL absoluta.
     */
    private function get_3ds_return_url( WC_Order $order ): string {
        return add_query_arg(
            array(
                self::RETURN_PARAM => '1',
                'order_id'         => $order->get_id(),
                'order_key'        => $order->get_order_key(),
            ),
            home_url( '/' )
        );
    }

    /**
     * Determina el locale de SumUp a partir del locale de WordPress.
     *
     * Devuelve etiquetas IETF BCP 47 completas (p.ej. 'es-CL', 'pt-BR')
     * convirtiendo el formato WordPress ('es_CL') a BCP 47 ('es-CL').
     * Si el idioma base no está soportado por SumUp, cae a 'es-CL'.
     *
     * Idiomas base soportados por el widget de SumUp:
     * 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr',
     * 'hr', 'hu', 'it', 'lt', 'lv', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv', 'tr'
     *
     * @return string Etiqueta IETF BCP 47 soportada por SumUp (ej: 'es-CL').
     */
    private function get_sumup_locale(): string {
        $wp_locale = get_locale(); // ej: 'es_CL', 'es_ES', 'en_US', 'pt_BR'

        // Convertir formato WordPress ('es_CL') → IETF BCP 47 ('es-CL')
        $ietf_locale = str_replace( '_', '-', $wp_locale );

        // Extraer idioma base para validar soporte de SumUp
        $lang = strtolower( substr( $wp_locale, 0, 2 ) );

        $supported = array( 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et',
                            'fi', 'fr', 'hr', 'hu', 'it', 'lt', 'lv', 'nl',
                            'pl', 'pt', 'ro', 'sk', 'sl', 'sv', 'tr' );

        // Si el idioma está soportado, devolver el tag completo; si no, Chile como fallback
        return in_array( $lang, $supported, true ) ? $ietf_locale : 'es-CL';
    }

    /**
     * Determina el código de país para el widget de SumUp.
     *
     * Usa el base country configurado en WooCommerce.
     *
     * @return string Código ISO de país (ej: 'CL', 'AR', 'CO').
     */
    private function get_sumup_country(): string {
        $base_location = wc_get_base_location();
        return strtoupper( $base_location['country'] ?? 'CL' );
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
     * @param string $level   Nivel PSR-3.
     * @param string $message Mensaje a registrar.
     * @param array  $context Datos adicionales opcionales.
     * @return void
     */
    private function log( string $level, string $message, array $context = array() ): void {
        if ( ! $this->logger ) {
            return;
        }

        $context['source'] = self::LOG_SOURCE;
        $this->logger->log( $level, '[RestoHub SumUp Gateway] ' . $message, $context );
    }
}
