<?php
/**
 * Frontend Storefront - Delivery App Style UI
 *
 * Transforma el frontend de WooCommerce en una interfaz
 * estilo app de delivery (tipo GetAgil/Rappi).
 *
 * @package WC_Uber_Direct_Connect
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase WCUDC_Frontend_Storefront
 */
class WCUDC_Frontend_Storefront {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define todos los hooks del frontend
	 */
	private function define_hooks(): void {
		// Scripts y estilos
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Header personalizado con ubicación
		add_action( 'wp_body_open', array( $this, 'render_delivery_header' ), 5 );

		// Navegación por categorías sticky
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_category_nav' ), 5 );

		// Modificar cards de productos
		remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5 );
		remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
		remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

		add_action( 'woocommerce_before_shop_loop_item', array( $this, 'product_card_open' ), 10 );
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'product_card_close' ), 20 );
		add_action( 'woocommerce_shop_loop_item_title', array( $this, 'product_card_content' ), 10 );

		// Carrito flotante
		add_action( 'wp_footer', array( $this, 'render_floating_cart' ) );

		// Status de tienda (abierto/cerrado)
		add_action( 'wp_footer', array( $this, 'render_store_status_badge' ) );

		// Filtrar clases del body
		add_filter( 'body_class', array( $this, 'add_body_classes' ) );

		// AJAX para mini cart
		add_action( 'wp_ajax_wcudc_get_mini_cart', array( $this, 'ajax_get_mini_cart' ) );
		add_action( 'wp_ajax_nopriv_wcudc_get_mini_cart', array( $this, 'ajax_get_mini_cart' ) );

		// Fragmentos del carrito
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragments' ) );
	}

	/**
	 * Encola scripts y estilos del frontend
	 */
	public function enqueue_scripts(): void {
		if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() && ! is_front_page() ) {
			return;
		}

		// CSS principal del storefront
		wp_enqueue_style(
			'wcudc-storefront',
			WCUDC_PLUGIN_URL . 'assets/css/storefront.css',
			array(),
			WCUDC_VERSION
		);

		// JS del storefront
		wp_enqueue_script(
			'wcudc-storefront',
			WCUDC_PLUGIN_URL . 'assets/js/storefront.js',
			array( 'jquery' ),
			WCUDC_VERSION,
			true
		);

		// Configuración para JS
		wp_localize_script( 'wcudc-storefront', 'wcudcStorefront', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'wcudc_storefront' ),
			'cartUrl'       => wc_get_cart_url(),
			'checkoutUrl'   => wc_get_checkout_url(),
			'storeStatus'   => $this->get_store_status(),
			'strings'       => array(
				'addToCart'    => __( 'Agregar', 'wc-uber-direct-connect' ),
				'viewCart'     => __( 'Ver carrito', 'wc-uber-direct-connect' ),
				'checkout'     => __( 'Ir a pagar', 'wc-uber-direct-connect' ),
				'emptyCart'    => __( 'Tu carrito está vacío', 'wc-uber-direct-connect' ),
				'storeClosed'  => __( 'Tienda cerrada', 'wc-uber-direct-connect' ),
				'storeOpen'    => __( 'Abierto ahora', 'wc-uber-direct-connect' ),
				'addedToCart'  => __( 'Agregado al carrito', 'wc-uber-direct-connect' ),
			),
		) );
	}

	/**
	 * Agrega clases al body para estilos condicionales
	 *
	 * @param array $classes Clases actuales.
	 * @return array
	 */
	public function add_body_classes( array $classes ): array {
		$classes[] = 'wcudc-delivery-app';

		if ( $this->is_store_open() ) {
			$classes[] = 'wcudc-store-open';
		} else {
			$classes[] = 'wcudc-store-closed';
		}

		if ( WCUDC_Location_Modal::has_location() ) {
			$classes[] = 'wcudc-has-location';
		}

		return $classes;
	}

	/**
	 * Renderiza el header de delivery con ubicación
	 */
	public function render_delivery_header(): void {
		if ( is_admin() ) {
			return;
		}

		$location      = WCUDC_Location_Modal::get_saved_location();
		$delivery_type = WCUDC_Location_Modal::get_delivery_type();
		$has_location  = ! empty( $location['lat'] );

		$display_text = __( 'Selecciona tu ubicación', 'wc-uber-direct-connect' );
		$icon         = '📍';

		if ( $has_location ) {
			if ( $delivery_type === 'pickup' && ! empty( $location['store_name'] ) ) {
				$display_text = sprintf( __( 'Retiro en %s', 'wc-uber-direct-connect' ), $location['store_name'] );
				$icon         = '🏪';
			} elseif ( ! empty( $location['short_address'] ) ) {
				$display_text = $location['short_address'];
			} elseif ( ! empty( $location['address'] ) ) {
				$display_text = wp_trim_words( $location['address'], 5, '...' );
			}
		}
		?>
		<div id="wcudc-delivery-header" class="wcudc-delivery-header <?php echo $has_location ? 'has-location' : 'no-location'; ?>">
			<div class="wcudc-delivery-header-inner">
				<!-- Logo/Brand -->
				<a href="<?php echo esc_url( home_url() ); ?>" class="wcudc-header-brand">
					<?php
					$custom_logo_id = get_theme_mod( 'custom_logo' );
					if ( $custom_logo_id ) {
						echo wp_get_attachment_image( $custom_logo_id, 'medium', false, array( 'class' => 'wcudc-header-logo' ) );
					} else {
						echo '<span class="wcudc-header-site-name">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
					}
					?>
				</a>

				<!-- Location Selector -->
				<button type="button" id="wcudc-open-location-modal" class="wcudc-header-location">
					<span class="wcudc-location-icon"><?php echo $icon; ?></span>
					<span class="wcudc-location-text">
						<span class="wcudc-location-label"><?php echo $delivery_type === 'pickup' ? esc_html__( 'Retiro', 'wc-uber-direct-connect' ) : esc_html__( 'Entregar en', 'wc-uber-direct-connect' ); ?></span>
						<span id="wcudc-location-display" class="wcudc-location-address"><?php echo esc_html( $display_text ); ?></span>
					</span>
					<span class="wcudc-location-arrow">▼</span>
				</button>

				<!-- Store Status -->
				<div class="wcudc-header-status">
					<?php if ( $this->is_store_open() ) : ?>
						<span class="wcudc-status-badge wcudc-status-open">
							<span class="wcudc-status-dot"></span>
							<?php esc_html_e( 'Abierto', 'wc-uber-direct-connect' ); ?>
						</span>
					<?php else : ?>
						<span class="wcudc-status-badge wcudc-status-closed">
							<?php esc_html_e( 'Cerrado', 'wc-uber-direct-connect' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza la navegación por categorías sticky
	 */
	public function render_category_nav(): void {
		$categories = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'parent'     => 0,
			'orderby'    => 'menu_order',
			'order'      => 'ASC',
		) );

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			return;
		}

		$current_cat = get_queried_object();
		$current_id  = ( $current_cat instanceof WP_Term ) ? $current_cat->term_id : 0;
		?>
		<nav id="wcudc-category-nav" class="wcudc-category-nav">
			<div class="wcudc-category-nav-inner">
				<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
				   class="wcudc-category-item <?php echo ! $current_id ? 'active' : ''; ?>">
					<?php esc_html_e( 'Todo', 'wc-uber-direct-connect' ); ?>
				</a>
				<?php foreach ( $categories as $category ) : ?>
					<a href="<?php echo esc_url( get_term_link( $category ) ); ?>"
					   class="wcudc-category-item <?php echo $current_id === $category->term_id ? 'active' : ''; ?>"
					   data-category-id="<?php echo esc_attr( $category->term_id ); ?>">
						<?php echo esc_html( $category->name ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</nav>
		<?php
	}

	/**
	 * Abre el contenedor del product card
	 */
	public function product_card_open(): void {
		global $product;
		$classes = array( 'wcudc-product-card' );

		if ( ! $product->is_in_stock() ) {
			$classes[] = 'out-of-stock';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<a href="' . esc_url( get_permalink() ) . '" class="wcudc-product-link">';
	}

	/**
	 * Contenido del product card
	 */
	public function product_card_content(): void {
		global $product;

		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src();
		?>
		<div class="wcudc-product-image-wrapper">
			<img src="<?php echo esc_url( $image_url ); ?>"
			     alt="<?php echo esc_attr( $product->get_name() ); ?>"
			     class="wcudc-product-image"
			     loading="lazy">
			<?php if ( ! $product->is_in_stock() ) : ?>
				<span class="wcudc-product-badge wcudc-badge-out-of-stock">
					<?php esc_html_e( 'Agotado', 'wc-uber-direct-connect' ); ?>
				</span>
			<?php elseif ( $product->is_on_sale() ) : ?>
				<span class="wcudc-product-badge wcudc-badge-sale">
					<?php esc_html_e( 'Oferta', 'wc-uber-direct-connect' ); ?>
				</span>
			<?php endif; ?>
		</div>

		<div class="wcudc-product-info">
			<h3 class="wcudc-product-title"><?php echo esc_html( $product->get_name() ); ?></h3>

			<?php if ( $product->get_short_description() ) : ?>
				<p class="wcudc-product-description">
					<?php echo esc_html( wp_trim_words( $product->get_short_description(), 10, '...' ) ); ?>
				</p>
			<?php endif; ?>

			<div class="wcudc-product-footer">
				<span class="wcudc-product-price">
					<?php echo $product->get_price_html(); ?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Cierra el contenedor del product card y agrega botón
	 */
	public function product_card_close(): void {
		global $product;

		echo '</a>'; // Cierra .wcudc-product-link

		// Botón de agregar (fuera del link)
		if ( $product->is_in_stock() && $product->is_purchasable() ) {
			if ( $product->is_type( 'simple' ) ) {
				echo '<button type="button" class="wcudc-add-to-cart-btn"
				        data-product-id="' . esc_attr( $product->get_id() ) . '"
				        data-product-name="' . esc_attr( $product->get_name() ) . '"
				        data-product-price="' . esc_attr( $product->get_price() ) . '">
				        <span class="wcudc-add-icon">+</span>
				      </button>';
			} else {
				echo '<a href="' . esc_url( get_permalink() ) . '" class="wcudc-add-to-cart-btn wcudc-view-options">
				        <span class="wcudc-add-icon">+</span>
				      </a>';
			}
		}

		echo '</div>'; // Cierra .wcudc-product-card
	}

	/**
	 * Renderiza el carrito flotante
	 */
	public function render_floating_cart(): void {
		if ( is_cart() || is_checkout() ) {
			return;
		}

		$cart_count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
		$cart_total = WC()->cart ? WC()->cart->get_cart_total() : wc_price( 0 );
		?>
		<div id="wcudc-floating-cart" class="wcudc-floating-cart <?php echo $cart_count > 0 ? 'has-items' : 'empty'; ?>">
			<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="wcudc-floating-cart-btn">
				<span class="wcudc-cart-icon">
					🛒
					<span id="wcudc-cart-count" class="wcudc-cart-count"><?php echo esc_html( $cart_count ); ?></span>
				</span>
				<span class="wcudc-cart-info">
					<span class="wcudc-cart-label"><?php esc_html_e( 'Ver carrito', 'wc-uber-direct-connect' ); ?></span>
					<span id="wcudc-cart-total" class="wcudc-cart-total"><?php echo $cart_total; ?></span>
				</span>
				<span class="wcudc-cart-arrow">→</span>
			</a>
		</div>

		<!-- Mini Cart Drawer -->
		<div id="wcudc-cart-drawer" class="wcudc-cart-drawer">
			<div class="wcudc-cart-drawer-overlay"></div>
			<div class="wcudc-cart-drawer-content">
				<div class="wcudc-cart-drawer-header">
					<h3><?php esc_html_e( 'Tu pedido', 'wc-uber-direct-connect' ); ?></h3>
					<button type="button" class="wcudc-cart-drawer-close">×</button>
				</div>
				<div id="wcudc-cart-drawer-items" class="wcudc-cart-drawer-items">
					<?php $this->render_mini_cart_items(); ?>
				</div>
				<div class="wcudc-cart-drawer-footer">
					<div class="wcudc-cart-drawer-subtotal">
						<span><?php esc_html_e( 'Subtotal', 'wc-uber-direct-connect' ); ?></span>
						<span id="wcudc-drawer-subtotal"><?php echo WC()->cart ? WC()->cart->get_cart_subtotal() : wc_price( 0 ); ?></span>
					</div>
					<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="wcudc-checkout-btn">
						<?php esc_html_e( 'Ir a pagar', 'wc-uber-direct-connect' ); ?>
						<span id="wcudc-drawer-total"><?php echo $cart_total; ?></span>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza los items del mini cart
	 */
	private function render_mini_cart_items(): void {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			echo '<div class="wcudc-cart-empty">';
			echo '<span class="wcudc-cart-empty-icon">🛒</span>';
			echo '<p>' . esc_html__( 'Tu carrito está vacío', 'wc-uber-direct-connect' ) . '</p>';
			echo '</div>';
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product   = $cart_item['data'];
			$quantity  = $cart_item['quantity'];
			$thumbnail = $product->get_image( array( 60, 60 ) );
			$price     = WC()->cart->get_product_subtotal( $product, $quantity );
			?>
			<div class="wcudc-cart-item" data-key="<?php echo esc_attr( $cart_item_key ); ?>">
				<div class="wcudc-cart-item-image"><?php echo $thumbnail; ?></div>
				<div class="wcudc-cart-item-info">
					<span class="wcudc-cart-item-name"><?php echo esc_html( $product->get_name() ); ?></span>
					<span class="wcudc-cart-item-price"><?php echo $price; ?></span>
				</div>
				<div class="wcudc-cart-item-qty">
					<button type="button" class="wcudc-qty-btn wcudc-qty-minus" data-key="<?php echo esc_attr( $cart_item_key ); ?>">−</button>
					<span class="wcudc-qty-value"><?php echo esc_html( $quantity ); ?></span>
					<button type="button" class="wcudc-qty-btn wcudc-qty-plus" data-key="<?php echo esc_attr( $cart_item_key ); ?>">+</button>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Renderiza badge de estado de tienda
	 */
	public function render_store_status_badge(): void {
		if ( ! $this->is_store_open() ) :
			?>
			<div id="wcudc-store-closed-banner" class="wcudc-store-closed-banner">
				<span class="wcudc-closed-icon">🕐</span>
				<span class="wcudc-closed-text">
					<?php
					$schedule = $this->get_store_schedule();
					if ( ! empty( $schedule['next_open'] ) ) {
						printf(
							esc_html__( 'Tienda cerrada. Abrimos %s', 'wc-uber-direct-connect' ),
							esc_html( $schedule['next_open'] )
						);
					} else {
						esc_html_e( 'Tienda cerrada', 'wc-uber-direct-connect' );
					}
					?>
				</span>
			</div>
			<?php
		endif;
	}

	/**
	 * Verifica si la tienda está abierta
	 *
	 * @return bool
	 */
	private function is_store_open(): bool {
		$settings = get_option( 'wcudc_settings', array() );

		// Si no hay horarios configurados, asumir siempre abierto
		if ( empty( $settings['store_hours'] ) ) {
			return true;
		}

		$hours = $settings['store_hours'];
		$now   = current_time( 'timestamp' );
		$day   = strtolower( gmdate( 'l', $now ) );
		$time  = gmdate( 'H:i', $now );

		// Mapeo de días en inglés a español para la config
		$day_map = array(
			'monday'    => 'lunes',
			'tuesday'   => 'martes',
			'wednesday' => 'miercoles',
			'thursday'  => 'jueves',
			'friday'    => 'viernes',
			'saturday'  => 'sabado',
			'sunday'    => 'domingo',
		);

		$day_key = $day_map[ $day ] ?? $day;

		if ( empty( $hours[ $day_key ] ) || empty( $hours[ $day_key ]['open'] ) ) {
			return false;
		}

		$open  = $hours[ $day_key ]['open'];
		$close = $hours[ $day_key ]['close'];

		return ( $time >= $open && $time <= $close );
	}

	/**
	 * Obtiene el estado de la tienda para JS
	 *
	 * @return array
	 */
	private function get_store_status(): array {
		return array(
			'is_open'  => $this->is_store_open(),
			'schedule' => $this->get_store_schedule(),
		);
	}

	/**
	 * Obtiene el horario de la tienda
	 *
	 * @return array
	 */
	private function get_store_schedule(): array {
		$settings = get_option( 'wcudc_settings', array() );
		$hours    = $settings['store_hours'] ?? array();

		$schedule = array(
			'hours'     => $hours,
			'next_open' => '',
		);

		// TODO: Calcular próxima apertura
		return $schedule;
	}

	/**
	 * AJAX: Obtiene el contenido del mini cart
	 */
	public function ajax_get_mini_cart(): void {
		check_ajax_referer( 'wcudc_storefront', 'nonce' );

		ob_start();
		$this->render_mini_cart_items();
		$items_html = ob_get_clean();

		wp_send_json_success( array(
			'items_html' => $items_html,
			'count'      => WC()->cart->get_cart_contents_count(),
			'total'      => WC()->cart->get_cart_total(),
			'subtotal'   => WC()->cart->get_cart_subtotal(),
		) );
	}

	/**
	 * Fragmentos del carrito para AJAX updates
	 *
	 * @param array $fragments Fragmentos actuales.
	 * @return array
	 */
	public function cart_fragments( array $fragments ): array {
		$count = WC()->cart->get_cart_contents_count();
		$total = WC()->cart->get_cart_total();

		$fragments['#wcudc-cart-count']       = '<span id="wcudc-cart-count" class="wcudc-cart-count">' . esc_html( $count ) . '</span>';
		$fragments['#wcudc-cart-total']       = '<span id="wcudc-cart-total" class="wcudc-cart-total">' . $total . '</span>';
		$fragments['#wcudc-drawer-total']     = '<span id="wcudc-drawer-total">' . $total . '</span>';
		$fragments['#wcudc-drawer-subtotal']  = '<span id="wcudc-drawer-subtotal">' . WC()->cart->get_cart_subtotal() . '</span>';

		// Mini cart items
		ob_start();
		$this->render_mini_cart_items();
		$fragments['#wcudc-cart-drawer-items'] = '<div id="wcudc-cart-drawer-items" class="wcudc-cart-drawer-items">' . ob_get_clean() . '</div>';

		// Estado del floating cart
		$fragments['#wcudc-floating-cart'] = $this->get_floating_cart_html();

		return $fragments;
	}

	/**
	 * Obtiene el HTML del floating cart para fragmentos
	 *
	 * @return string
	 */
	private function get_floating_cart_html(): string {
		$cart_count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
		$cart_total = WC()->cart ? WC()->cart->get_cart_total() : wc_price( 0 );

		ob_start();
		?>
		<div id="wcudc-floating-cart" class="wcudc-floating-cart <?php echo $cart_count > 0 ? 'has-items' : 'empty'; ?>">
			<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="wcudc-floating-cart-btn">
				<span class="wcudc-cart-icon">
					🛒
					<span id="wcudc-cart-count" class="wcudc-cart-count"><?php echo esc_html( $cart_count ); ?></span>
				</span>
				<span class="wcudc-cart-info">
					<span class="wcudc-cart-label"><?php esc_html_e( 'Ver carrito', 'wc-uber-direct-connect' ); ?></span>
					<span id="wcudc-cart-total" class="wcudc-cart-total"><?php echo $cart_total; ?></span>
				</span>
				<span class="wcudc-cart-arrow">→</span>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}
}
