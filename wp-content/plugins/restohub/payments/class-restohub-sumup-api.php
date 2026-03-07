<?php
/**
 * Wrapper para la API REST de SumUp
 *
 * Responsabilidades:
 *  - Autenticación: API Key directa (preferida) u OAuth client_credentials (legacy).
 *  - Caché del access token con TTL dinámico (expires_in de SumUp - buffer).
 *  - Creación y consulta de checkouts.
 *  - Timeout de 15 s en todas las peticiones HTTP.
 *  - Traducción de errores de red y HTTP a WP_Error con mensajes amigables.
 *
 * Credenciales en wp-config.php (tienen precedencia sobre la BD):
 *   define( 'RESTOHUB_SUMUP_API_KEY',       'sup_sk_...' ); // Recomendado
 *   define( 'RESTOHUB_SUMUP_CLIENT_ID',     '...' );        // Alternativa OAuth
 *   define( 'RESTOHUB_SUMUP_CLIENT_SECRET', '...' );        // Alternativa OAuth
 *   define( 'RESTOHUB_SUMUP_MERCHANT_CODE', 'MXXXXXXX' );   // Obligatorio
 *
 * @package RestoHub
 * @subpackage Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class RestoHub_SumUp_API
 *
 * Único punto de contacto con la API de SumUp. No contiene lógica de
 * negocio de WooCommerce; esa responsabilidad es del gateway.
 */
class RestoHub_SumUp_API {

    // =========================================================================
    // Constantes
    // =========================================================================

    /**
     * URL base de la API de SumUp (producción).
     * SumUp no tiene sandbox de API REST pública; el testing se hace
     * con el modo "test card" del propio widget.
     */
    private const API_BASE = 'https://api.sumup.com';

    /** Endpoint OAuth para client_credentials. */
    private const AUTH_ENDPOINT = '/token';

    /** Endpoint para crear y consultar checkouts. */
    private const CHECKOUTS_ENDPOINT = '/v0.1/checkouts';

    /**
     * Clave del transient de WordPress para cachear el token OAuth.
     * Solo se usa cuando se autentica con client_credentials, no con api_key.
     */
    private const TOKEN_TRANSIENT = 'restohub_sumup_access_token';

    /**
     * Margen de seguridad (segundos) que se resta a expires_in al cachear
     * el token, para asegurarse de que nunca usemos un token a punto de expirar.
     */
    private const TOKEN_EXPIRY_BUFFER = 120;

    /** Timeout para todas las peticiones HTTP a SumUp (segundos). */
    private const REQUEST_TIMEOUT = 30;

    /** Canal de log visible en WooCommerce > Estado > Registros. */
    private const LOG_SOURCE = 'restohub-sumup';

    // =========================================================================
    // Propiedades
    // =========================================================================

    /** @var array Configuración combinada de BD + constantes de wp-config. */
    private array $settings;

    /** @var WC_Logger_Interface|null Logger de WooCommerce. */
    private ?WC_Logger_Interface $logger = null;

    // =========================================================================
    // Constructor
    // =========================================================================

    public function __construct() {
        $this->settings = get_option( 'restohub_settings', array() );
        $this->apply_wp_config_overrides();
        $this->init_logger();
    }

    // =========================================================================
    // API pública
    // =========================================================================

    /**
     * Verifica si las credenciales mínimas están configuradas.
     *
     * Útil para el gateway: si retorna false, el método de pago
     * no se debe mostrar en el checkout.
     *
     * @return bool
     */
    public function is_configured(): bool {
        $has_api_key = ! empty( $this->settings['sumup_api_key'] );
        $has_oauth   = ! empty( $this->settings['sumup_client_id'] )
                       && ! empty( $this->settings['sumup_client_secret'] );

        return $has_api_key || $has_oauth;
    }

    /**
     * Crea un checkout en SumUp.
     *
     * Genera una checkout_reference única con formato RH_{order_id}_{timestamp}
     * para trazabilidad. El gateway debe verificar '_restohub_sumup_checkout_id'
     * en el order meta ANTES de llamar a este método (para reutilizar si existe).
     *
     * @param WC_Order $order        Orden de WooCommerce con total y datos del cliente.
     * @param string   $redirect_url URL de retorno post-3DS (construida por el gateway).
     * @return array|WP_Error        Array completo de respuesta de SumUp o WP_Error.
     */
    public function create_checkout( WC_Order $order, string $redirect_url ): array|WP_Error {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $order_id  = $order->get_id();
        $reference = 'RH_' . $order_id . '_' . time();
        $amount    = round( (float) $order->get_total(), 2 );
        $currency  = get_woocommerce_currency();

        // ── Construir payload ───────────────────────────────────────────────
        $payload = array(
            'checkout_reference' => $reference,
            'amount'             => $amount,
            'currency'           => $currency,
            'description'        => sprintf(
                // translators: 1: número de pedido  2: nombre del sitio
                __( 'Pedido #%1$s — %2$s', 'restohub' ),
                $order->get_order_number(),
                get_bloginfo( 'name' )
            ),
            'redirect_url'       => $redirect_url,
            'personal_details'   => array(
                'email'      => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
            ),
        );

        // merchant_code tiene precedencia sobre pay_to_email
        $merchant_code = $this->settings['sumup_merchant_code'] ?? '';
        if ( ! empty( $merchant_code ) ) {
            $payload['merchant_code'] = $merchant_code;
        } else {
            $pay_to_email = $this->settings['sumup_pay_to_email'] ?? '';
            if ( ! empty( $pay_to_email ) ) {
                $payload['pay_to_email'] = $pay_to_email;
            } else {
                $this->log(
                    'error',
                    'Falta merchant_code y pay_to_email. No se puede crear el checkout.'
                );
                return new WP_Error(
                    'missing_merchant_config',
                    __( 'La pasarela de pago no está configurada correctamente. Contacta al administrador.', 'restohub' )
                );
            }
        }

        $this->log( 'info', sprintf(
            'Creando checkout — Pedido #%d | Ref: %s | Monto: %.2f %s',
            $order_id,
            $reference,
            $amount,
            $currency
        ) );

        $response = $this->request( 'POST', self::CHECKOUTS_ENDPOINT, $payload, $token );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $this->log( 'info', sprintf(
            'Checkout creado — SumUp ID: %s | Pedido #%d | Ref: %s',
            $response['id'] ?? 'N/A',
            $order_id,
            $reference
        ) );

        // Guardar la referencia en el meta de la orden para auditoría y debug
        $order->update_meta_data( '_restohub_sumup_checkout_reference', $reference );
        $order->save_meta_data();

        return $response;
    }

    /**
     * Consulta el estado actual de un checkout en SumUp.
     *
     * Retorna el objeto completo del checkout. Los campos relevantes son:
     *   - 'status':           'PENDING' | 'PAID' | 'FAILED'
     *   - 'transaction_code': presente cuando status === 'PAID'
     *   - 'amount':           monto del checkout (usar para validación en webhook)
     *
     * @param string $checkout_id UUID del checkout (guardado en order meta).
     * @return array|WP_Error
     */
    public function get_checkout( string $checkout_id ): array|WP_Error {
        if ( empty( $checkout_id ) ) {
            return new WP_Error(
                'invalid_checkout_id',
                __( 'ID de checkout inválido.', 'restohub' )
            );
        }

        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $this->log( 'debug', sprintf( 'Consultando checkout: %s', $checkout_id ) );

        return $this->request(
            'GET',
            self::CHECKOUTS_ENDPOINT . '/' . rawurlencode( $checkout_id ),
            array(),
            $token
        );
    }

    /**
     * Elimina el transient del token OAuth, forzando una renovación en la
     * siguiente operación. Útil desde un botón "Reconectar" en el admin.
     *
     * @return void
     */
    public function invalidate_token_cache(): void {
        delete_transient( self::TOKEN_TRANSIENT );
        $this->log( 'info', 'Caché del token OAuth eliminado manualmente.' );
    }

    // =========================================================================
    // Autenticación
    // =========================================================================

    /**
     * Obtiene un access token válido.
     *
     * Estrategia de resolución:
     *   1. Si hay api_key configurada → la retorna directamente (sin red).
     *   2. Si no → lee el transient de caché.
     *   3. Si el transient expiró o no existe → llama a fetch_oauth_token().
     *
     * @return string|WP_Error Token listo para usar en el header Authorization.
     */
    private function get_access_token(): string|WP_Error {
        // ── Ruta 1: API Key directa (método moderno, sin OAuth) ─────────────
        $api_key = trim( $this->settings['sumup_api_key'] ?? '' );
        if ( ! empty( $api_key ) ) {
            $this->log( 'debug', 'Autenticando con API Key directa.' );
            return $api_key;
        }

        // ── Ruta 2: OAuth client_credentials con caché ──────────────────────
        $cached = get_transient( self::TOKEN_TRANSIENT );
        if ( false !== $cached && ! empty( $cached ) ) {
            $this->log( 'debug', 'Token OAuth recuperado del caché.' );
            return $cached;
        }

        // ── Ruta 3: Solicitar nuevo token ────────────────────────────────────
        return $this->fetch_oauth_token();
    }

    /**
     * Solicita un nuevo token OAuth via client_credentials y lo almacena
     * en caché con el TTL dinámico que devuelve SumUp menos el buffer.
     *
     * Formula del TTL:
     *   ttl = max( 60, expires_in - TOKEN_EXPIRY_BUFFER )
     *
     * Esto garantiza que el token en caché nunca venza antes de que WordPress
     * lo lea, incluso bajo carga alta o relojes ligeramente desfasados.
     *
     * @return string|WP_Error
     */
    private function fetch_oauth_token(): string|WP_Error {
        $client_id     = trim( $this->settings['sumup_client_id'] ?? '' );
        $client_secret = trim( $this->settings['sumup_client_secret'] ?? '' );

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            $this->log( 'error', 'Faltan credenciales OAuth (client_id / client_secret). Configura la pasarela.' );
            return new WP_Error(
                'missing_credentials',
                __( 'La pasarela de pago no está configurada. Contacta al administrador.', 'restohub' )
            );
        }

        $this->log( 'info', 'Solicitando nuevo token OAuth a SumUp.' );

        $response = wp_remote_post(
            self::API_BASE . self::AUTH_ENDPOINT,
            array(
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                ),
                'body'    => array(
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                ),
            )
        );

        // ── Error de red / timeout ───────────────────────────────────────────
        if ( is_wp_error( $response ) ) {
            $this->log( 'error', sprintf(
                'Error de red al obtener token OAuth: %s',
                $response->get_error_message()
            ) );
            return new WP_Error(
                'network_error',
                __( 'Problemas de conexión con el banco. Por favor intenta nuevamente.', 'restohub' )
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        // ── Error de servidor SumUp (5xx) ────────────────────────────────────
        if ( $http_code >= 500 ) {
            $this->log( 'error', sprintf(
                'SumUp respondió con HTTP %d al solicitar token. Servicio no disponible.',
                $http_code
            ) );
            return new WP_Error(
                'sumup_server_error',
                __( 'El servicio de pago no está disponible en este momento. Intenta en unos minutos.', 'restohub' )
            );
        }

        // ── Error de autenticación (4xx) o respuesta malformada ──────────────
        if ( $http_code >= 400 || empty( $body['access_token'] ) ) {
            $detail = $body['error_description'] ?? $body['message'] ?? 'Error desconocido';
            $this->log( 'error', sprintf(
                'Autenticación OAuth fallida. HTTP %d: %s',
                $http_code,
                $detail
            ) );
            return new WP_Error(
                'auth_failed',
                __( 'No se pudo autenticar con el servicio de pago. Contacta al administrador.', 'restohub' )
            );
        }

        // ── Éxito: guardar en caché con TTL dinámico ─────────────────────────
        $expires_in = (int) ( $body['expires_in'] ?? 3600 );
        $ttl        = max( 60, $expires_in - self::TOKEN_EXPIRY_BUFFER );

        set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );

        $this->log( 'info', sprintf(
            'Token OAuth obtenido y cacheado. expires_in=%ds | TTL caché=%ds.',
            $expires_in,
            $ttl
        ) );

        return $body['access_token'];
    }

    // =========================================================================
    // HTTP Helper
    // =========================================================================

    /**
     * Realiza una petición HTTP autenticada a la API de SumUp.
     *
     * Centraliza: headers de autenticación, Content-Type, timeout,
     * detección de errores de red, clasificación de errores HTTP,
     * y deserialización segura del JSON de respuesta.
     *
     * @param string $method    Método HTTP: 'GET' o 'POST'.
     * @param string $endpoint  Path del endpoint (ej: '/v0.1/checkouts').
     * @param array  $body      Payload para POST; array vacío para GET.
     * @param string $token     Access token (se agrega como Bearer).
     * @return array|WP_Error   Array decodificado de la respuesta o WP_Error.
     */
    private function request(
        string $method,
        string $endpoint,
        array $body,
        string $token
    ): array|WP_Error {
        $url    = self::API_BASE . $endpoint;
        $method = strtoupper( $method );

        $args = array(
            'method'  => $method,
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
        );

        if ( 'POST' === $method && ! empty( $body ) ) {
            $encoded = wp_json_encode( $body );
            if ( false === $encoded ) {
                return new WP_Error(
                    'json_encode_error',
                    __( 'Error al preparar los datos del pago.', 'restohub' )
                );
            }
            $args['body'] = $encoded;
        }

        $response = wp_remote_request( $url, $args );

        // ── Error de red o timeout ───────────────────────────────────────────
        if ( is_wp_error( $response ) ) {
            return $this->handle_network_error( $method, $endpoint, $response );
        }

        $http_code    = (int) wp_remote_retrieve_response_code( $response );
        $raw_body     = wp_remote_retrieve_body( $response );
        $decoded_body = json_decode( $raw_body, true );

        $this->log( 'debug', sprintf(
            '[%s %s] HTTP %d — Respuesta: %s',
            $method,
            $endpoint,
            $http_code,
            // Truncar para no saturar los logs con payloads grandes
            substr( wp_json_encode( $decoded_body ) ?: $raw_body, 0, 512 )
        ) );

        // ── Error de servidor SumUp (5xx) ────────────────────────────────────
        if ( $http_code >= 500 ) {
            $this->log( 'error', sprintf(
                '[%s %s] SumUp error de servidor HTTP %d.',
                $method,
                $endpoint,
                $http_code
            ) );
            return new WP_Error(
                'sumup_server_error',
                __( 'El servicio de pago no está disponible en este momento. Tu pedido quedó guardado; intenta el pago nuevamente en unos minutos.', 'restohub' )
            );
        }

        // ── Errores de negocio (4xx) ─────────────────────────────────────────
        if ( $http_code >= 400 ) {
            return $this->handle_api_error(
                $method,
                $endpoint,
                $http_code,
                is_array( $decoded_body ) ? $decoded_body : array()
            );
        }

        // ── Validar que la respuesta sea JSON válido ─────────────────────────
        if ( ! is_array( $decoded_body ) ) {
            $this->log( 'error', sprintf(
                '[%s %s] Respuesta no es JSON válido. Raw (primeros 200 chars): %s',
                $method,
                $endpoint,
                substr( $raw_body, 0, 200 )
            ) );
            return new WP_Error(
                'invalid_response',
                __( 'Respuesta inesperada del servicio de pago. Contacta al administrador.', 'restohub' )
            );
        }

        return $decoded_body;
    }

    /**
     * Traduce un WP_Error de red (timeout, DNS, TLS, etc.) en un WP_Error
     * con mensaje amigable para el usuario, diferenciando timeouts de otros
     * errores de conectividad.
     *
     * @param string   $method   Método HTTP para el log.
     * @param string   $endpoint Endpoint para el log.
     * @param WP_Error $error    Error original de wp_remote_request.
     * @return WP_Error
     */
    private function handle_network_error(
        string $method,
        string $endpoint,
        WP_Error $error
    ): WP_Error {
        $error_message = $error->get_error_message();
        $is_timeout    = (
            str_contains( strtolower( $error_message ), 'timed out' )
            || 'http_request_timeout' === $error->get_error_code()
            || str_contains( strtolower( $error_message ), 'operation timed out' )
        );

        $this->log( 'error', sprintf(
            '[%s %s] Error de %s: %s',
            $method,
            $endpoint,
            $is_timeout ? 'timeout' : 'red',
            $error_message
        ) );

        if ( $is_timeout ) {
            return new WP_Error(
                'sumup_timeout',
                __( 'El banco tardó demasiado en responder. Tu pedido fue guardado; por favor intenta el pago nuevamente.', 'restohub' )
            );
        }

        return new WP_Error(
            'sumup_network_error',
            __( 'No se pudo conectar con el servicio de pago. Verifica tu conexión e intenta nuevamente.', 'restohub' )
        );
    }

    /**
     * Traduce errores HTTP 4xx de la API de SumUp a WP_Error con mensajes
     * apropiados en español, sin exponer detalles técnicos al cliente final.
     *
     * Para el código 401/403, elimina el transient del token para forzar
     * una renovación en la siguiente petición.
     *
     * @param string $method    Método HTTP (para log).
     * @param string $endpoint  Endpoint (para log).
     * @param int    $http_code Código HTTP de la respuesta.
     * @param array  $body      Cuerpo decodificado (puede estar vacío).
     * @return WP_Error
     */
    private function handle_api_error(
        string $method,
        string $endpoint,
        int $http_code,
        array $body
    ): WP_Error {
        $error_code  = $body['error_code'] ?? '';
        $api_message = $body['message']    ?? '';
        $api_param   = $body['param']      ?? '';

        $this->log( 'error', sprintf(
            '[%s %s] Error API HTTP %d | error_code="%s" | param="%s" | message="%s"',
            $method,
            $endpoint,
            $http_code,
            $error_code,
            $api_param,
            $api_message
        ) );

        // Errores de autenticación: limpiar caché para forzar renovación
        if ( in_array( $http_code, array( 401, 403 ), true ) ) {
            delete_transient( self::TOKEN_TRANSIENT );
            $this->log( 'warning', sprintf(
                'Token rechazado por SumUp (HTTP %d). Caché del token eliminado.',
                $http_code
            ) );
            return new WP_Error(
                'auth_expired',
                __( 'Sesión de pago expirada. Por favor recarga la página e intenta nuevamente.', 'restohub' )
            );
        }

        // Mapa de error_code documentados por SumUp
        switch ( $error_code ) {

            case 'DUPLICATED_CHECKOUT':
                // Normalmente el gateway previene esto comprobando el order meta.
                // Si llega aquí es una condición de carrera; el gateway debe
                // consultar get_checkout() con el ID guardado en el meta.
                $this->log( 'warning', sprintf(
                    'DUPLICATED_CHECKOUT para pedido. El gateway debería haber reutilizado el checkout existente.'
                ) );
                return new WP_Error(
                    'duplicated_checkout',
                    __( 'Ya existe una solicitud de pago activa para este pedido. Recarga la página e intenta nuevamente.', 'restohub' )
                );

            case 'INSUFFICIENT_SCOPES':
                $this->log( 'critical', 'La API Key de SumUp no tiene los scopes requeridos (payments:write).' );
                return new WP_Error(
                    'insufficient_scopes',
                    __( 'Error de configuración de la pasarela de pago. Contacta al administrador.', 'restohub' )
                );

            case 'INVALID':
                if ( 'currency' === $api_param ) {
                    $this->log( 'error', sprintf( 'Moneda "%s" no soportada por SumUp.', $api_param ) );
                    return new WP_Error(
                        'unsupported_currency',
                        __( 'La moneda de la tienda no es compatible con esta pasarela de pago.', 'restohub' )
                    );
                }
                return new WP_Error(
                    'invalid_request',
                    __( 'Datos de pago inválidos. Verifica la información e intenta nuevamente.', 'restohub' )
                );

            case 'NOT_FOUND':
                return new WP_Error(
                    'checkout_not_found',
                    __( 'La sesión de pago no fue encontrada. Recarga la página.', 'restohub' )
                );

            default:
                return new WP_Error(
                    'sumup_api_error',
                    __( 'No se pudo procesar el pago en este momento. Intenta nuevamente o usa otro método de pago.', 'restohub' )
                );
        }
    }

    // =========================================================================
    // Helpers internos
    // =========================================================================

    /**
     * Sobreescribe la configuración de BD con las constantes de wp-config.php.
     *
     * Las constantes tienen precedencia absoluta y son el método recomendado
     * para gestionar credenciales en entornos de producción/staging, ya que
     * el archivo wp-config.php está fuera del webroot y no se incluye en
     * backups de la BD.
     *
     * @return void
     */
    private function apply_wp_config_overrides(): void {
        $map = array(
            'RESTOHUB_SUMUP_API_KEY'       => 'sumup_api_key',
            'RESTOHUB_SUMUP_CLIENT_ID'     => 'sumup_client_id',
            'RESTOHUB_SUMUP_CLIENT_SECRET' => 'sumup_client_secret',
            'RESTOHUB_SUMUP_MERCHANT_CODE' => 'sumup_merchant_code',
        );

        foreach ( $map as $constant => $setting_key ) {
            if ( defined( $constant ) ) {
                $this->settings[ $setting_key ] = constant( $constant );
            }
        }
    }

    /**
     * Inicializa el logger de WooCommerce.
     *
     * Los registros aparecen en WooCommerce > Estado > Registros,
     * filtrados por fuente 'restohub-sumup'.
     *
     * @return void
     */
    private function init_logger(): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Registra un mensaje de log prefijado con '[RestoHub SumUp]'.
     *
     * @param string $level   Nivel PSR-3: emergency|alert|critical|error|warning|notice|info|debug.
     * @param string $message Mensaje descriptivo.
     * @param array  $context Datos adicionales opcionales.
     * @return void
     */
    private function log( string $level, string $message, array $context = array() ): void {
        if ( ! $this->logger ) {
            return;
        }

        $context['source'] = self::LOG_SOURCE;
        $this->logger->log( $level, '[RestoHub SumUp] ' . $message, $context );
    }
}
