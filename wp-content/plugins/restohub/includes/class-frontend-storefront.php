<?php
/**
 * Frontend Storefront - Delivery App Style UI
 *
 * Transforma el frontend de WooCommerce en una interfaz
 * estilo app de delivery (tipo GetAgil/Rappi).
 *
 * @package RestoHub
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase RestoHub_Frontend_Storefront
 */
class RestoHub_Frontend_Storefront {

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
		add_action( 'wp_ajax_restohub_get_mini_cart', array( $this, 'ajax_get_mini_cart' ) );
		add_action( 'wp_ajax_nopriv_restohub_get_mini_cart', array( $this, 'ajax_get_mini_cart' ) );

		// Fragmentos del carrito
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragments' ) );

		// AJAX add to cart para productos simples
		add_action( 'wp_ajax_woocommerce_ajax_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_woocommerce_ajax_add_to_cart', array( $this, 'ajax_add_to_cart' ) );

		// Soportar AJAX en WooCommerce
		add_filter( 'woocommerce_add_to_cart_redirect', '__return_false' );
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
			'restohub-storefront',
			RESTOHUB_PLUGIN_URL . 'assets/css/storefront.css',
			array(),
			RESTOHUB_VERSION
		);

		// JS del storefront
		wp_enqueue_script(
			'restohub-storefront',
			RESTOHUB_PLUGIN_URL . 'assets/js/storefront.js',
			array( 'jquery' ),
			RESTOHUB_VERSION,
			true
		);

		// Configuración para JS
		wp_localize_script( 'restohub-storefront', 'restoHubStorefront', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'restohub_storefront' ),
			'cartUrl'       => wc_get_cart_url(),
			'checkoutUrl'   => wc_get_checkout_url(),
			'storeStatus'   => $this->get_store_status(),
			'strings'       => array(
				'addToCart'    => __( 'Agregar', 'restohub' ),
				'viewCart'     => __( 'Ver carrito', 'restohub' ),
				'checkout'     => __( 'Ir a pagar', 'restohub' ),
				'emptyCart'    => __( 'Tu carrito está vacío', 'restohub' ),
				'storeClosed'  => __( 'Tienda cerrada', 'restohub' ),
				'storeOpen'    => __( 'Abierto ahora', 'restohub' ),
				'addedToCart'  => __( 'Agregado al carrito', 'restohub' ),
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
		$classes[] = 'restohub-delivery-app';

		if ( $this->is_store_open() ) {
			$classes[] = 'restohub-store-open';
		} else {
			$classes[] = 'restohub-store-closed';
		}

		if ( RestoHub_Location_Modal::has_location() ) {
			$classes[] = 'restohub-has-location';
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

		$location      = RestoHub_Location_Modal::get_location();
		$delivery_type = RestoHub_Location_Modal::get_delivery_type();
		$has_location  = ! empty( $location['lat'] );

		$display_text = __( 'Selecciona tu ubicación', 'restohub' );
		$icon         = '📍';

		if ( $has_location ) {
			if ( $delivery_type === 'pickup' && ! empty( $location['store_name'] ) ) {
				$display_text = sprintf( __( 'Retiro en %s', 'restohub' ), $location['store_name'] );
				$icon         = '🏪';
			} elseif ( ! empty( $location['short_address'] ) ) {
				$display_text = $location['short_address'];
			} elseif ( ! empty( $location['address'] ) ) {
				$display_text = wp_trim_words( $location['address'], 5, '...' );
			}
		}
		?>
		<div id="restohub-delivery-header" class="restohub-delivery-header <?php echo $has_location ? 'has-location' : 'no-location'; ?>">
			<div class="restohub-delivery-header-inner">
				<!-- Logo/Brand -->
				<a href="<?php echo esc_url( home_url() ); ?>" class="restohub-header-brand">
					<?php
					$custom_logo_id = get_theme_mod( 'custom_logo' );
					if ( $custom_logo_id ) {
						echo wp_get_attachment_image( $custom_logo_id, 'medium', false, array( 'class' => 'restohub-header-logo' ) );
					} else {
						echo '<span class="restohub-header-site-name">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
					}
					?>
				</a>

				<!-- Location Selector -->
				<button type="button" id="restohub-open-location-modal" class="restohub-header-location">
					<span class="restohub-location-icon"><?php echo $icon; ?></span>
					<span class="restohub-location-text">
						<span class="restohub-location-label"><?php echo $delivery_type === 'pickup' ? esc_html__( 'Retiro', 'restohub' ) : esc_html__( 'Entregar en', 'restohub' ); ?></span>
						<span id="restohub-location-display" class="restohub-location-address"><?php echo esc_html( $display_text ); ?></span>
					</span>
					<span class="restohub-location-arrow">▼</span>
				</button>

				<!-- Store Status -->
				<div class="restohub-header-status">
					<?php if ( $this->is_store_open() ) : ?>
						<span class="restohub-status-badge restohub-status-open">
							<span class="restohub-status-dot"></span>
							<?php esc_html_e( 'Abierto', 'restohub' ); ?>
						</span>
					<?php else : ?>
						<span class="restohub-status-badge restohub-status-closed">
							<?php esc_html_e( 'Cerrado', 'restohub' ); ?>
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
		<nav id="restohub-category-nav" class="restohub-category-nav">
			<div class="restohub-category-nav-inner">
				<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
				   class="restohub-category-item <?php echo ! $current_id ? 'active' : ''; ?>">
					<?php esc_html_e( 'Todo', 'restohub' ); ?>
				</a>
				<?php foreach ( $categories as $category ) : ?>
					<a href="<?php echo esc_url( get_term_link( $category ) ); ?>"
					   class="restohub-category-item <?php echo $current_id === $category->term_id ? 'active' : ''; ?>"
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
		$classes = array( 'restohub-product-card' );

		if ( ! $product->is_in_stock() ) {
			$classes[] = 'out-of-stock';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<a href="' . esc_url( get_permalink() ) . '" class="restohub-product-link">';
	}

	/**
	 * Contenido del product card
	 */
	public function product_card_content(): void {
		global $product;

		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src();
		?>
		<div class="restohub-product-image-wrapper">
			<img src="<?php echo esc_url( $image_url ); ?>"
			     alt="<?php echo esc_attr( $product->get_name() ); ?>"
			     class="restohub-product-image"
			     loading="lazy">
			<?php if ( ! $product->is_in_stock() ) : ?>
				<span class="restohub-product-badge restohub-badge-out-of-stock">
					<?php esc_html_e( 'Agotado', 'restohub' ); ?>
				</span>
			<?php elseif ( $product->is_on_sale() ) : ?>
				<span class="restohub-product-badge restohub-badge-sale">
					<?php esc_html_e( 'Oferta', 'restohub' ); ?>
				</span>
			<?php endif; ?>
		</div>

		<div class="restohub-product-info">
			<h3 class="restohub-product-title"><?php echo esc_html( $product->get_name() ); ?></h3>

			<?php if ( $product->get_short_description() ) : ?>
				<p class="restohub-product-description">
					<?php echo esc_html( wp_trim_words( $product->get_short_description(), 10, '...' ) ); ?>
				</p>
			<?php endif; ?>

			<div class="restohub-product-footer">
				<span class="restohub-product-price">
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

		echo '</a>'; // Cierra .restohub-product-link

		// Botón de agregar (fuera del link)
		if ( $product->is_in_stock() && $product->is_purchasable() ) {
			if ( $product->is_type( 'simple' ) ) {
				echo '<button type="button" class="restohub-add-to-cart-btn"
				        data-product-id="' . esc_attr( $product->get_id() ) . '"
				        data-product-name="' . esc_attr( $product->get_name() ) . '"
				        data-product-price="' . esc_attr( $product->get_price() ) . '">
				        <span class="restohub-add-icon">+</span>
				      </button>';
			} else {
				echo '<a href="' . esc_url( get_permalink() ) . '" class="restohub-add-to-cart-btn restohub-view-options">
				        <span class="restohub-add-icon">+</span>
				      </a>';
			}
		}

		echo '</div>'; // Cierra .restohub-product-card
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
		<div id="restohub-floating-cart" class="restohub-floating-cart <?php echo $cart_count > 0 ? 'has-items' : 'empty'; ?>">
			<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="restohub-floating-cart-btn">
				<span class="restohub-cart-icon">
					🛒
					<span id="restohub-cart-count" class="restohub-cart-count"><?php echo esc_html( $cart_count ); ?></span>
				</span>
				<span class="restohub-cart-info">
					<span class="restohub-cart-label"><?php esc_html_e( 'Ver carrito', 'restohub' ); ?></span>
					<span id="restohub-cart-total" class="restohub-cart-total"><?php echo $cart_total; ?></span>
				</span>
				<span class="restohub-cart-arrow">→</span>
			</a>
		</div>

		<!-- Mini Cart Drawer -->
		<div id="restohub-cart-drawer" class="restohub-cart-drawer">
			<div class="restohub-cart-drawer-overlay"></div>
			<div class="restohub-cart-drawer-content">
				<div class="restohub-cart-drawer-header">
					<h3><?php esc_html_e( 'Tu pedido', 'restohub' ); ?></h3>
					<button type="button" class="restohub-cart-drawer-close">×</button>
				</div>
				<div id="restohub-cart-drawer-items" class="restohub-cart-drawer-items">
					<?php $this->render_mini_cart_items(); ?>
				</div>
				<div class="restohub-cart-drawer-footer">
					<div class="restohub-cart-drawer-subtotal">
						<span><?php esc_html_e( 'Subtotal', 'restohub' ); ?></span>
						<span id="restohub-drawer-subtotal"><?php echo WC()->cart ? WC()->cart->get_cart_subtotal() : wc_price( 0 ); ?></span>
					</div>
					<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="restohub-checkout-btn">
						<?php esc_html_e( 'Ir a pagar', 'restohub' ); ?>
						<span id="restohub-drawer-total"><?php echo $cart_total; ?></span>
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
			echo '<div class="restohub-cart-empty">';
			echo '<span class="restohub-cart-empty-icon">🛒</span>';
			echo '<p>' . esc_html__( 'Tu carrito está vacío', 'restohub' ) . '</p>';
			echo '</div>';
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product   = $cart_item['data'];
			$quantity  = $cart_item['quantity'];
			$thumbnail = $product->get_image( array( 60, 60 ) );
			$price     = WC()->cart->get_product_subtotal( $product, $quantity );
			?>
			<div class="restohub-cart-item" data-key="<?php echo esc_attr( $cart_item_key ); ?>">
				<div class="restohub-cart-item-image"><?php echo $thumbnail; ?></div>
				<div class="restohub-cart-item-info">
					<span class="restohub-cart-item-name"><?php echo esc_html( $product->get_name() ); ?></span>
					<span class="restohub-cart-item-price"><?php echo $price; ?></span>
				</div>
				<div class="restohub-cart-item-qty">
					<button type="button" class="restohub-qty-btn restohub-qty-minus" data-key="<?php echo esc_attr( $cart_item_key ); ?>">−</button>
					<span class="restohub-qty-value"><?php echo esc_html( $quantity ); ?></span>
					<button type="button" class="restohub-qty-btn restohub-qty-plus" data-key="<?php echo esc_attr( $cart_item_key ); ?>">+</button>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Renderiza badge de estado de tienda
	 */
	public function render_store_status_badge(): void {
		$schedule     = $this->get_store_schedule();
		$today_hours  = $schedule['today_hours'] ?? array();
		$is_special   = ! empty( $today_hours['is_special'] );
		$special_name = $today_hours['name'] ?? '';

		if ( ! $this->is_store_open() ) :
			?>
			<div id="restohub-store-closed-banner" class="restohub-store-closed-banner <?php echo $is_special ? 'is-special-day' : ''; ?>">
				<span class="restohub-closed-icon"><?php echo $is_special ? '📅' : '🕐'; ?></span>
				<span class="restohub-closed-text">
					<?php
					if ( $is_special && $special_name ) {
						// Día especial con nombre (ej: "Navidad")
						if ( ! empty( $schedule['next_open'] ) ) {
							printf(
								/* translators: 1: special day name, 2: next opening time */
								esc_html__( 'Cerrado por %1$s. Abrimos %2$s', 'restohub' ),
								esc_html( $special_name ),
								esc_html( $schedule['next_open'] )
							);
						} else {
							printf(
								/* translators: %s: special day name */
								esc_html__( 'Cerrado por %s', 'restohub' ),
								esc_html( $special_name )
							);
						}
					} elseif ( ! empty( $schedule['next_open'] ) ) {
						printf(
							esc_html__( 'Tienda cerrada. Abrimos %s', 'restohub' ),
							esc_html( $schedule['next_open'] )
						);
					} else {
						esc_html_e( 'Tienda cerrada', 'restohub' );
					}
					?>
				</span>
			</div>
			<?php
		elseif ( $is_special && $special_name ) :
			// Abierto pero es día especial (horario modificado)
			?>
			<div id="restohub-store-special-banner" class="restohub-store-special-banner">
				<span class="restohub-special-icon">📅</span>
				<span class="restohub-special-text">
					<?php
					printf(
						/* translators: 1: special day name, 2: closing time */
						esc_html__( 'Horario especial por %1$s - Cerramos a las %2$s', 'restohub' ),
						esc_html( $special_name ),
						esc_html( $today_hours['close'] ?? '' )
					);
					?>
				</span>
			</div>
			<?php
		endif;
	}

	/**
	 * Verifica si la tienda está abierta
	 *
	 * Prioridad:
	 * 1. Horarios especiales (festivos, feriados)
	 * 2. Horarios regulares por día de la semana
	 *
	 * @return bool
	 */
	private function is_store_open(): bool {
		$settings = get_option( 'restohub_settings', array() );

		// Si no hay horarios configurados, asumir siempre abierto
		if ( empty( $settings['store_hours'] ) && empty( $settings['special_hours'] ) ) {
			return true;
		}

		$now        = current_time( 'timestamp' );
		$today_date = gmdate( 'Y-m-d', $now );
		$current_time = gmdate( 'H:i', $now );

		// 1. Verificar horarios especiales primero
		$special_hours = $this->get_special_hours_for_date( $today_date );

		if ( $special_hours !== null ) {
			// Tenemos un horario especial para hoy
			if ( ! empty( $special_hours['closed'] ) ) {
				return false; // Día especial cerrado
			}

			if ( ! empty( $special_hours['open'] ) && ! empty( $special_hours['close'] ) ) {
				return ( $current_time >= $special_hours['open'] && $current_time <= $special_hours['close'] );
			}

			// Si tiene horario especial pero sin horas definidas, está cerrado
			return false;
		}

		// 2. Horarios regulares
		$hours = $settings['store_hours'] ?? array();

		if ( empty( $hours ) ) {
			return true; // Sin horarios regulares = siempre abierto
		}

		$day = strtolower( gmdate( 'l', $now ) );

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

		// Día cerrado
		if ( ! empty( $hours[ $day_key ]['closed'] ) ) {
			return false;
		}

		if ( empty( $hours[ $day_key ] ) || empty( $hours[ $day_key ]['open'] ) ) {
			return false;
		}

		$open  = $hours[ $day_key ]['open'];
		$close = $hours[ $day_key ]['close'];

		return ( $current_time >= $open && $current_time <= $close );
	}

	/**
	 * Obtiene el horario especial para una fecha específica
	 *
	 * @param string $date Fecha en formato Y-m-d.
	 * @return array|null Horario especial o null si no hay.
	 */
	private function get_special_hours_for_date( string $date ): ?array {
		$settings      = get_option( 'restohub_settings', array() );
		$special_hours = $settings['special_hours'] ?? array();

		if ( empty( $special_hours ) ) {
			return null;
		}

		$date_obj   = DateTime::createFromFormat( 'Y-m-d', $date );
		$month_day  = $date_obj ? $date_obj->format( 'm-d' ) : '';

		foreach ( $special_hours as $special ) {
			if ( empty( $special['date'] ) ) {
				continue;
			}

			// Coincidencia exacta
			if ( $special['date'] === $date ) {
				return $special;
			}

			// Coincidencia anual (repetir cada año)
			if ( ! empty( $special['repeat_yearly'] ) ) {
				$special_month_day = substr( $special['date'], 5 ); // MM-DD
				if ( $special_month_day === $month_day ) {
					return $special;
				}
			}
		}

		return null;
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
		$settings      = get_option( 'restohub_settings', array() );
		$hours         = $settings['store_hours'] ?? array();
		$special_hours = $settings['special_hours'] ?? array();

		$now        = current_time( 'timestamp' );
		$today_date = gmdate( 'Y-m-d', $now );

		// Verificar si hoy es día especial
		$today_special = $this->get_special_hours_for_date( $today_date );

		$schedule = array(
			'hours'         => $hours,
			'special_hours' => $special_hours,
			'is_special'    => $today_special !== null,
			'special_name'  => $today_special['name'] ?? '',
			'next_open'     => $this->calculate_next_open_time(),
			'today_hours'   => $this->get_today_hours(),
		);

		return $schedule;
	}

	/**
	 * Obtiene los horarios de hoy (considerando especiales)
	 *
	 * @return array
	 */
	private function get_today_hours(): array {
		$settings   = get_option( 'restohub_settings', array() );
		$now        = current_time( 'timestamp' );
		$today_date = gmdate( 'Y-m-d', $now );

		// Verificar horario especial
		$special = $this->get_special_hours_for_date( $today_date );

		if ( $special !== null ) {
			return array(
				'is_special' => true,
				'name'       => $special['name'] ?? '',
				'closed'     => ! empty( $special['closed'] ),
				'open'       => $special['open'] ?? '',
				'close'      => $special['close'] ?? '',
			);
		}

		// Horario regular
		$hours   = $settings['store_hours'] ?? array();
		$day     = strtolower( gmdate( 'l', $now ) );
		$day_map = array(
			'monday'    => 'lunes',
			'tuesday'   => 'martes',
			'wednesday' => 'miercoles',
			'thursday'  => 'jueves',
			'friday'    => 'viernes',
			'saturday'  => 'sabado',
			'sunday'    => 'domingo',
		);

		$day_key    = $day_map[ $day ] ?? $day;
		$day_hours  = $hours[ $day_key ] ?? array();

		return array(
			'is_special' => false,
			'name'       => '',
			'closed'     => ! empty( $day_hours['closed'] ),
			'open'       => $day_hours['open'] ?? '',
			'close'      => $day_hours['close'] ?? '',
		);
	}

	/**
	 * Calcula la próxima hora de apertura
	 *
	 * @return string Texto legible de próxima apertura.
	 */
	private function calculate_next_open_time(): string {
		$settings = get_option( 'restohub_settings', array() );
		$hours    = $settings['store_hours'] ?? array();

		if ( empty( $hours ) ) {
			return '';
		}

		$now          = current_time( 'timestamp' );
		$current_time = gmdate( 'H:i', $now );
		$today_date   = gmdate( 'Y-m-d', $now );

		$day_map = array(
			'monday'    => 'lunes',
			'tuesday'   => 'martes',
			'wednesday' => 'miercoles',
			'thursday'  => 'jueves',
			'friday'    => 'viernes',
			'saturday'  => 'sabado',
			'sunday'    => 'domingo',
		);

		$day_names_es = array(
			'lunes'     => __( 'lunes', 'restohub' ),
			'martes'    => __( 'martes', 'restohub' ),
			'miercoles' => __( 'miércoles', 'restohub' ),
			'jueves'    => __( 'jueves', 'restohub' ),
			'viernes'   => __( 'viernes', 'restohub' ),
			'sabado'    => __( 'sábado', 'restohub' ),
			'domingo'   => __( 'domingo', 'restohub' ),
		);

		// Buscar en los próximos 7 días
		for ( $i = 0; $i <= 7; $i++ ) {
			$check_timestamp = strtotime( "+{$i} days", $now );
			$check_date      = gmdate( 'Y-m-d', $check_timestamp );
			$check_day       = strtolower( gmdate( 'l', $check_timestamp ) );
			$day_key         = $day_map[ $check_day ] ?? $check_day;

			// Verificar si es día especial
			$special = $this->get_special_hours_for_date( $check_date );

			if ( $special !== null ) {
				// Día especial
				if ( ! empty( $special['closed'] ) ) {
					continue; // Cerrado, buscar siguiente día
				}

				if ( ! empty( $special['open'] ) ) {
					$open_time = $special['open'];

					// Si es hoy, verificar que aún no haya pasado
					if ( $i === 0 && $current_time >= $open_time ) {
						continue;
					}

					if ( $i === 0 ) {
						return sprintf( __( 'hoy a las %s', 'restohub' ), $open_time );
					} elseif ( $i === 1 ) {
						return sprintf( __( 'mañana a las %s', 'restohub' ), $open_time );
					} else {
						return sprintf(
							__( 'el %s a las %s', 'restohub' ),
							$day_names_es[ $day_key ] ?? $day_key,
							$open_time
						);
					}
				}

				continue;
			}

			// Horario regular
			$day_hours = $hours[ $day_key ] ?? array();

			if ( ! empty( $day_hours['closed'] ) || empty( $day_hours['open'] ) ) {
				continue; // Cerrado, buscar siguiente día
			}

			$open_time = $day_hours['open'];

			// Si es hoy, verificar que aún no haya pasado
			if ( $i === 0 && $current_time >= $open_time ) {
				continue;
			}

			if ( $i === 0 ) {
				return sprintf( __( 'hoy a las %s', 'restohub' ), $open_time );
			} elseif ( $i === 1 ) {
				return sprintf( __( 'mañana a las %s', 'restohub' ), $open_time );
			} else {
				return sprintf(
					__( 'el %s a las %s', 'restohub' ),
					$day_names_es[ $day_key ] ?? $day_key,
					$open_time
				);
			}
		}

		return '';
	}

	/**
	 * AJAX: Obtiene el contenido del mini cart
	 */
	public function ajax_get_mini_cart(): void {
		check_ajax_referer( 'restohub_storefront', 'nonce' );

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
	 * AJAX: Agrega producto al carrito
	 */
	public function ajax_add_to_cart(): void {
		$product_id = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $_POST['product_id'] ?? 0 ) );
		$quantity   = empty( $_POST['quantity'] ) ? 1 : wc_stock_amount( absint( $_POST['quantity'] ) );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$variations = isset( $_POST['variations'] ) ? (array) $_POST['variations'] : array();

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Producto no encontrado', 'restohub' ) ) );
			return;
		}

		// Verificar si la tienda está cerrada
		if ( ! $this->is_store_open() ) {
			wp_send_json_error( array( 'message' => __( 'La tienda está cerrada', 'restohub' ) ) );
			return;
		}

		$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variations );

		if ( $passed_validation && false !== WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations ) ) {
			do_action( 'woocommerce_ajax_added_to_cart', $product_id );

			if ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
				wc_add_to_cart_message( array( $product_id => $quantity ), true );
			}

			WC_AJAX::get_refreshed_fragments();
		} else {
			$data = array(
				'error'       => true,
				'product_url' => apply_filters( 'woocommerce_cart_redirect_after_error', get_permalink( $product_id ), $product_id ),
			);

			wp_send_json( $data );
		}

		wp_die();
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

		$fragments['#restohub-cart-count']       = '<span id="restohub-cart-count" class="restohub-cart-count">' . esc_html( $count ) . '</span>';
		$fragments['#restohub-cart-total']       = '<span id="restohub-cart-total" class="restohub-cart-total">' . $total . '</span>';
		$fragments['#restohub-drawer-total']     = '<span id="restohub-drawer-total">' . $total . '</span>';
		$fragments['#restohub-drawer-subtotal']  = '<span id="restohub-drawer-subtotal">' . WC()->cart->get_cart_subtotal() . '</span>';

		// Mini cart items
		ob_start();
		$this->render_mini_cart_items();
		$fragments['#restohub-cart-drawer-items'] = '<div id="restohub-cart-drawer-items" class="restohub-cart-drawer-items">' . ob_get_clean() . '</div>';

		// Estado del floating cart
		$fragments['#restohub-floating-cart'] = $this->get_floating_cart_html();

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
		<div id="restohub-floating-cart" class="restohub-floating-cart <?php echo $cart_count > 0 ? 'has-items' : 'empty'; ?>">
			<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="restohub-floating-cart-btn">
				<span class="restohub-cart-icon">
					🛒
					<span id="restohub-cart-count" class="restohub-cart-count"><?php echo esc_html( $cart_count ); ?></span>
				</span>
				<span class="restohub-cart-info">
					<span class="restohub-cart-label"><?php esc_html_e( 'Ver carrito', 'restohub' ); ?></span>
					<span id="restohub-cart-total" class="restohub-cart-total"><?php echo $cart_total; ?></span>
				</span>
				<span class="restohub-cart-arrow">→</span>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}
}
