<?php
/**
 * Interfaz de Usuario para Tracking de Deliveries
 *
 * Renderiza el estado y el enlace de seguimiento de Uber Direct en:
 *  - Página de "Gracias" (woocommerce_thankyou)
 *  - Página "Ver pedido" de Mi Cuenta (woocommerce_view_order)
 *  - Emails de WooCommerce (woocommerce_email_after_order_table)
 *  - Endpoint "Mis Entregas" en Mi Cuenta (/mi-cuenta/rastrear-pedido/)
 *
 * Meta keys relevantes (escritas por Motor Logístico + Webhook Handler):
 *  _uber_delivery_id      → ID asignado por Uber (creado en Logistics).
 *  _uber_tracking_url     → URL de seguimiento en tiempo real.
 *  _uber_delivery_status  → Estado del Motor Logístico (pending|created|failed…).
 *  _uber_status           → Estado del webhook de Uber (picked_up|delivered…).
 *
 * @package RestoHub
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestoHub_Delivery_UI
 */
final class RestoHub_Delivery_UI {

	// =========================================================================
	// Constantes
	// =========================================================================

	/**
	 * Slug del endpoint de WooCommerce Mi Cuenta.
	 */
	private const ENDPOINT_SLUG = 'rastrear-pedido';

	/**
	 * Máximo de pedidos a consultar en la página de Mi Cuenta.
	 */
	private const ORDERS_LIMIT = 15;

	/**
	 * Ventana de tiempo para considerar un pedido como "activo" (segundos).
	 * 72 h: muestra deliveries creados en los últimos 3 días.
	 */
	private const ACTIVE_WINDOW_SECS = 72 * HOUR_IN_SECONDS;

	/**
	 * Mapa de estados → etiqueta legible + clase CSS modificadora.
	 *
	 * Prioridad de lectura: _uber_status (webhook) > _uber_delivery_status (Motor).
	 */
	private const STATUS_MAP = array(
		// ── Estados del Motor Logístico ─────────────────────────────────────
		'pending'             => array( 'label' => 'Buscando repartidor…',          'mod' => 'searching' ),
		'processing'          => array( 'label' => 'Contactando a Uber…',            'mod' => 'searching' ),
		'retrying'            => array( 'label' => 'Reintentando asignación…',       'mod' => 'searching' ),
		'created'             => array( 'label' => 'Repartidor asignado',            'mod' => 'assigned'  ),
		'failed'              => array( 'label' => 'Sin repartidor disponible',      'mod' => 'failed'    ),
		// ── Estados del Webhook de Uber ─────────────────────────────────────
		'courier_approaching' => array( 'label' => 'Repartidor llegando al local 📍', 'mod' => 'approaching' ),
		'picked_up'           => array( 'label' => 'Pedido recogido del local 🛵',   'mod' => 'pickup'    ),
		'delivered'           => array( 'label' => '¡Entregado con éxito! 🎉',       'mod' => 'delivered' ),
		'cancelled'           => array( 'label' => 'Delivery cancelado',             'mod' => 'failed'    ),
	);

	// =========================================================================
	// Constructor
	// =========================================================================

	public function __construct() {
		$this->register_hooks();
	}

	// =========================================================================
	// Registro de hooks
	// =========================================================================

	private function register_hooks(): void {
		// ── Endpoint Mi Cuenta ───────────────────────────────────────────────
		add_action( 'init',                               array( $this, 'register_endpoint' ) );
		add_filter( 'query_vars',                         array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items',     array( $this, 'add_menu_item' ), 15 );
		add_action( 'woocommerce_account_' . self::ENDPOINT_SLUG . '_endpoint',
		                                                  array( $this, 'render_account_page' ) );
		add_filter( 'the_title',                          array( $this, 'filter_endpoint_title' ), 10, 2 );

		// ── Páginas de pedido ────────────────────────────────────────────────
		add_action( 'woocommerce_thankyou',   array( $this, 'render_thankyou_block'   ), 20 );
		add_action( 'woocommerce_view_order', array( $this, 'render_view_order_block' ), 20 );

		// ── Emails ───────────────────────────────────────────────────────────
		add_action( 'woocommerce_email_after_order_table',
		            array( $this, 'inject_email_tracking' ), 10, 4 );

		// ── Assets ───────────────────────────────────────────────────────────
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// =========================================================================
	// 1. Endpoint Mi Cuenta
	// =========================================================================

	/**
	 * Registra el endpoint de rewrite para WooCommerce Mi Cuenta.
	 */
	public function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT_SLUG, EP_ROOT | EP_PAGES );
	}

	/**
	 * Añade el slug del endpoint como query var reconocida por WordPress.
	 *
	 * @param array $vars Query vars existentes.
	 * @return array
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::ENDPOINT_SLUG;
		return $vars;
	}

	/**
	 * Añade "Mis Entregas" al menú lateral de Mi Cuenta, justo después de
	 * "Mis Pedidos" (orders). Si el endpoint ya aparece en el menú (ej. porque
	 * otro plugin lo registró con el mismo slug), no lo duplica.
	 *
	 * @param array $items Elementos del menú existentes.
	 * @return array
	 */
	public function add_menu_item( array $items ): array {
		// Evitar duplicado si ya existe en el menú.
		if ( array_key_exists( self::ENDPOINT_SLUG, $items ) ) {
			return $items;
		}

		$new_items = array();

		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;

			if ( 'orders' === $key ) {
				$new_items[ self::ENDPOINT_SLUG ] = __( 'Mis Entregas', 'restohub' );
			}
		}

		// Si 'orders' no estaba (menú muy personalizado), añadirlo antes de logout.
		if ( ! array_key_exists( self::ENDPOINT_SLUG, $new_items ) ) {
			$logout = $new_items['customer-logout'] ?? null;
			unset( $new_items['customer-logout'] );
			$new_items[ self::ENDPOINT_SLUG ] = __( 'Mis Entregas', 'restohub' );
			if ( null !== $logout ) {
				$new_items['customer-logout'] = $logout;
			}
		}

		return $new_items;
	}

	/**
	 * Sobrescribe el título de la página cuando se está en el endpoint.
	 *
	 * @param string   $title   Título actual.
	 * @param int|null $post_id ID del post (puede ser null).
	 * @return string
	 */
	public function filter_endpoint_title( string $title, ?int $post_id = null ): string {
		global $wp_query;

		if ( ! is_account_page() || ! isset( $wp_query->query_vars[ self::ENDPOINT_SLUG ] ) ) {
			return $title;
		}

		// Sólo cambiar el título del post de Mi Cuenta, no el de otros elementos.
		if ( (int) $post_id !== (int) wc_get_page_id( 'myaccount' ) ) {
			return $title;
		}

		return __( 'Mis Entregas', 'restohub' );
	}

	/**
	 * Renderiza la página del endpoint "Mis Entregas" en Mi Cuenta.
	 */
	public function render_account_page(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Obtener pedidos recientes en estados activos.
		$orders = wc_get_orders( array(
			'customer_id' => $user_id,
			'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
			'limit'       => self::ORDERS_LIMIT,
			'orderby'     => 'date',
			'order'       => 'DESC',
		) );

		// Filtrar: solo los que tienen un delivery de Uber asignado y son recientes.
		$cutoff         = time() - self::ACTIVE_WINDOW_SECS;
		$delivery_orders = array_filter(
			$orders,
			function ( WC_Order $order ) use ( $cutoff ) {
				// Debe tener delivery ID (Uber aceptó la solicitud).
				if ( ! $order->get_meta( '_uber_delivery_id' ) ) {
					return false;
				}
				// Dentro de la ventana de tiempo activa.
				return $order->get_date_created() &&
					$order->get_date_created()->getTimestamp() >= $cutoff;
			}
		);

		?>
		<div class="restohub-deliveries-page">

			<div class="restohub-deliveries-page__header">
				<h2 class="restohub-deliveries-page__title">
					🛵 <?php esc_html_e( 'Mis Entregas Activas', 'restohub' ); ?>
				</h2>
				<p class="restohub-deliveries-page__subtitle">
					<?php esc_html_e( 'Pedidos con delivery de Uber Direct en los últimos 3 días.', 'restohub' ); ?>
				</p>
			</div>

			<?php if ( empty( $delivery_orders ) ) : ?>

				<div class="restohub-deliveries-empty">
					<div class="restohub-deliveries-empty__icon">🏍️</div>
					<p class="restohub-deliveries-empty__title">
						<?php esc_html_e( 'No hay pedidos en camino en este momento', 'restohub' ); ?>
					</p>
					<p class="restohub-deliveries-empty__text">
						<?php esc_html_e( 'Cuando realices un pedido con delivery, podrás rastrearlo aquí en tiempo real.', 'restohub' ); ?>
					</p>
					<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
					   class="restohub-btn restohub-btn--primary">
						<?php esc_html_e( 'Ver el Menú', 'restohub' ); ?>
					</a>
				</div>

			<?php else : ?>

				<div class="restohub-delivery-cards">
					<?php foreach ( $delivery_orders as $order ) : ?>
						<?php $this->render_delivery_card( $order ); ?>
					<?php endforeach; ?>
				</div>

			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Renderiza una tarjeta de delivery individual para la página de Mi Cuenta.
	 *
	 * @param WC_Order $order Pedido.
	 */
	private function render_delivery_card( WC_Order $order ): void {
		$order_id     = $order->get_id();
		$tracking_url = $this->get_tracking_url( $order );
		$status_info  = $this->get_status_info( $order );
		$order_url    = $order->get_view_order_url();
		?>
		<div class="restohub-delivery-card restohub-delivery-card--<?php echo esc_attr( $status_info['mod'] ); ?>">

			<div class="restohub-delivery-card__header">
				<span class="restohub-delivery-card__order-num">
					<?php
					printf(
						/* translators: %d: order number */
						esc_html__( 'Pedido #%d', 'restohub' ),
						$order_id
					);
					?>
				</span>
				<span class="restohub-delivery-card__total">
					<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
				</span>
			</div>

			<div class="restohub-delivery-card__status">
				<span class="restohub-status-badge restohub-status-badge--<?php echo esc_attr( $status_info['mod'] ); ?>">
					<?php echo esc_html( $status_info['label'] ); ?>
				</span>
			</div>

			<div class="restohub-delivery-card__footer">
				<?php if ( $tracking_url ) : ?>
					<a href="<?php echo esc_url( $tracking_url ); ?>"
					   class="restohub-btn restohub-btn--primary restohub-btn--icon"
					   target="_blank"
					   rel="noopener noreferrer">
						📍 <?php esc_html_e( 'Rastrear en Uber', 'restohub' ); ?>
					</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $order_url ); ?>"
				   class="restohub-btn restohub-btn--ghost">
					<?php esc_html_e( 'Ver pedido', 'restohub' ); ?>
				</a>
			</div>

		</div>
		<?php
	}

	// =========================================================================
	// 2. Página de "Gracias" y "Ver pedido"
	// =========================================================================

	/**
	 * Añade el bloque de tracking en la página de "Gracias" post-compra.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function render_thankyou_block( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! $this->order_uses_uber_shipping( $order ) ) {
			return;
		}

		$this->render_tracking_block( $order );
	}

	/**
	 * Añade el bloque de tracking en la página "Ver pedido" de Mi Cuenta.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function render_view_order_block( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! $this->order_uses_uber_shipping( $order ) ) {
			return;
		}

		$this->render_tracking_block( $order );
	}

	/**
	 * Renderiza el bloque de estado y tracking de Uber para un pedido.
	 * Usado tanto en thank you como en view order.
	 *
	 * @param WC_Order $order Pedido.
	 */
	private function render_tracking_block( WC_Order $order ): void {
		$tracking_url = $this->get_tracking_url( $order );
		$status_info  = $this->get_status_info( $order );
		$has_tracking = ! empty( $tracking_url );

		// Si no hay delivery iniciado aún, no mostrar nada.
		if ( ! $order->get_meta( '_uber_delivery_status' ) && ! $has_tracking ) {
			return;
		}
		?>
		<div class="restohub-tracking-block" role="region"
		     aria-label="<?php esc_attr_e( 'Estado del delivery', 'restohub' ); ?>">

			<div class="restohub-tracking-block__header">
				<span class="restohub-tracking-block__icon" aria-hidden="true">🛵</span>
				<h3 class="restohub-tracking-block__title">
					<?php esc_html_e( 'Tu Delivery con Uber Direct', 'restohub' ); ?>
				</h3>
			</div>

			<div class="restohub-tracking-block__status">
				<span class="restohub-status-badge restohub-status-badge--<?php echo esc_attr( $status_info['mod'] ); ?>">
					<?php echo esc_html( $status_info['label'] ); ?>
				</span>
			</div>

			<?php if ( $has_tracking ) : ?>
				<div class="restohub-tracking-block__cta">
					<a href="<?php echo esc_url( $tracking_url ); ?>"
					   class="restohub-tracking-btn"
					   target="_blank"
					   rel="noopener noreferrer">
						📍 <?php esc_html_e( 'Rastrear mi pedido en vivo', 'restohub' ); ?>
					</a>
				</div>
			<?php else : ?>
				<p class="restohub-tracking-block__notice">
					<?php esc_html_e( 'El enlace de seguimiento estará disponible en cuanto se asigne tu repartidor.', 'restohub' ); ?>
				</p>
			<?php endif; ?>

		</div>
		<?php
	}

	// =========================================================================
	// 3. Emails de WooCommerce
	// =========================================================================

	/**
	 * Inyecta el bloque de tracking en los emails de WooCommerce al cliente.
	 *
	 * Usa HTML con estilos en línea y layout basado en tablas para máxima
	 * compatibilidad con clientes de correo (Gmail, Outlook, Apple Mail, etc.).
	 *
	 * @param WC_Order $order          Pedido.
	 * @param bool     $sent_to_admin  True si el email va al administrador.
	 * @param bool     $plain_text     True si el email es texto plano.
	 * @param WC_Email $email          Objeto de email de WooCommerce.
	 */
	public function inject_email_tracking(
		WC_Order $order,
		bool $sent_to_admin,
		bool $plain_text,
		WC_Email $email
	): void {
		// Solo emails al cliente, con formato HTML, con pedido de Uber.
		if ( $sent_to_admin || $plain_text ) {
			return;
		}

		$tracking_url = $this->get_tracking_url( $order );

		if ( ! $tracking_url || ! $this->order_uses_uber_shipping( $order ) ) {
			return;
		}

		$status_info = $this->get_status_info( $order );
		?>
		<table width="100%" cellpadding="0" cellspacing="0" border="0"
		       style="margin-top:24px; margin-bottom:16px; border-collapse:collapse;">
			<tr>
				<td style="background-color:#1a1a1a; border:1px solid #333333; border-radius:8px; padding:24px; text-align:center;">

					<p style="margin:0 0 6px 0; font-size:28px; line-height:1;">🛵</p>

					<p style="margin:0 0 8px 0; font-size:16px; font-weight:700; color:#ffffff; font-family:sans-serif;">
						<?php esc_html_e( 'Tu Delivery con Uber Direct', 'restohub' ); ?>
					</p>

					<p style="margin:0 0 20px 0; display:inline-block; padding:6px 16px;
					          background-color:#2a2a2a; border-radius:20px;
					          font-size:13px; color:#cccccc; font-family:sans-serif;">
						<?php echo esc_html( $status_info['label'] ); ?>
					</p>

					<br>

					<!--[if mso]>
					<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml"
					             xmlns:w="urn:schemas-microsoft-com:office:word"
					             href="<?php echo esc_url( $tracking_url ); ?>"
					             style="height:48px; v-text-anchor:middle; width:260px;"
					             arcsize="8%" stroke="f" fillcolor="#ff9800">
					  <w:anchorlock/>
					  <center style="color:#000000; font-family:sans-serif; font-size:15px; font-weight:bold;">
					    📍 <?php esc_html_e( 'Rastrear mi pedido en vivo', 'restohub' ); ?>
					  </center>
					</v:roundrect>
					<![endif]-->
					<!--[if !mso]><!-->
					<a href="<?php echo esc_url( $tracking_url ); ?>"
					   target="_blank"
					   rel="noopener noreferrer"
					   style="display:inline-block; padding:14px 32px;
					          background-color:#ff9800; color:#000000;
					          text-decoration:none; border-radius:8px;
					          font-size:15px; font-weight:700; font-family:sans-serif;
					          letter-spacing:0.3px; mso-hide:all;">
						📍 <?php esc_html_e( 'Rastrear mi pedido en vivo', 'restohub' ); ?>
					</a>
					<!--<![endif]-->

					<p style="margin:16px 0 0 0; font-size:12px; color:#888888; font-family:sans-serif;">
						<?php esc_html_e( 'Se abrirá en el mapa de Uber. Guarda este email para consultar el estado de tu entrega.', 'restohub' ); ?>
					</p>

				</td>
			</tr>
		</table>
		<?php
	}

	// =========================================================================
	// 4. Assets
	// =========================================================================

	/**
	 * Encola el CSS en las páginas relevantes:
	 *  - Página de "Gracias" post-compra.
	 *  - Páginas de Mi Cuenta (endpoint rastrear-pedido + view order).
	 */
	public function enqueue_assets(): void {
		if ( ! is_wc_endpoint_url() && ! is_checkout() && ! is_account_page() ) {
			return;
		}

		wp_enqueue_style(
			'restohub-delivery-ui',
			RESTOHUB_PLUGIN_URL . 'assets/css/delivery-ui.css',
			array(),
			RESTOHUB_VERSION
		);
	}

	// =========================================================================
	// Helpers privados
	// =========================================================================

	/**
	 * Obtiene la URL de seguimiento del pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string URL o cadena vacía.
	 */
	private function get_tracking_url( WC_Order $order ): string {
		return esc_url_raw( (string) $order->get_meta( '_uber_tracking_url' ) );
	}

	/**
	 * Resuelve el estado legible del delivery para un pedido.
	 *
	 * Prioridad:
	 *  1. _uber_status (actualizado por webhooks de Uber en tiempo real).
	 *  2. _uber_delivery_status (estado interno del Motor Logístico).
	 *
	 * @param WC_Order $order Pedido.
	 * @return array{label: string, mod: string}
	 */
	private function get_status_info( WC_Order $order ): array {
		$uber_status     = (string) $order->get_meta( '_uber_status' );
		$logistics_status = (string) $order->get_meta( '_uber_delivery_status' );

		// Priorizar el estado del webhook (más reciente/real).
		$status = $uber_status ?: $logistics_status;

		return self::STATUS_MAP[ $status ] ?? array(
			'label' => ucfirst( $status ) ?: __( 'En proceso', 'restohub' ),
			'mod'   => 'default',
		);
	}

	/**
	 * Verifica si el pedido usa el método de envío Uber Direct.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private function order_uses_uber_shipping( WC_Order $order ): bool {
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 'uber_direct' === $method->get_method_id() ) {
				return true;
			}
		}
		return false;
	}
}
