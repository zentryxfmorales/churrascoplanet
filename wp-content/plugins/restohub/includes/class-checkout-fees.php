<?php
/**
 * Checkout Fees - Cuota de servicio y Propina en el checkout
 *
 * Agrega cargos automáticos (service fee) y opcionales (tip) al carrito
 * usando WC()->cart->add_fee(). Inyecta el selector de propina dentro
 * de la fila de fee que WC genera, para mostrar todo en una sola línea.
 *
 * @package RestoHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestoHub_Checkout_Fees {

	/**
	 * @var RestoHub_Checkout_Fees_Repository
	 */
	private RestoHub_Checkout_Fees_Repository $repo;

	/**
	 * Label usado para el fee de propina (para identificar la fila en el filtro)
	 */
	private const TIP_FEE_LABEL = 'Propina';

	public function __construct() {
		$this->repo = restohub_checkout_fees_repository();

		// Agregar cargos al carrito
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_cart_fees' ) );

		// Inyectar el <select> dentro de la fila de fee de Propina generada por WC
		add_filter( 'woocommerce_cart_totals_fee_html', array( $this, 'inject_tip_select_into_fee_row' ), 10, 2 );

		// Si propina es 0%, WC no genera fila de fee → renderizar fila custom con solo el select
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_tip_row_if_no_fee' ) );

		// AJAX: Seleccionar propina
		add_action( 'wp_ajax_restohub_set_tip', array( $this, 'ajax_set_tip' ) );
		add_action( 'wp_ajax_nopriv_restohub_set_tip', array( $this, 'ajax_set_tip' ) );

		// Guardar cargos en la tabla de orden al procesar el pedido
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_order_fees' ), 10, 3 );
	}

	/**
	 * Agrega cuota de servicio y propina como fees del carrito
	 *
	 * @param WC_Cart $cart
	 */
	public function add_cart_fees( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// --- Cuota de servicio ---
		$service_fee_config = $this->repo->get_service_fee( 0 );

		if ( $service_fee_config && ! empty( $service_fee_config['is_active'] ) && floatval( $service_fee_config['percentage'] ) > 0 ) {
			$subtotal   = $cart->get_subtotal();
			$shipping   = $cart->get_shipping_total();
			$base       = $subtotal + $shipping;
			$percentage = floatval( $service_fee_config['percentage'] );
			$fee_amount = round( $base * $percentage / 100, 2 );

			if ( $fee_amount > 0 ) {
				$label = $service_fee_config['label'] ?: __( 'Cuota de servicio', 'restohub' );
				$cart->add_fee( $label, $fee_amount, false );
			}
		}

		// --- Propina ---
		$tip_percentage = $this->get_session_tip();

		if ( $tip_percentage > 0 ) {
			$subtotal   = $cart->get_subtotal();
			$tip_amount = round( $subtotal * $tip_percentage / 100, 2 );

			if ( $tip_amount > 0 ) {
				$cart->add_fee( self::TIP_FEE_LABEL, $tip_amount, false );
			}
		}
	}

	/**
	 * Inyecta el <select> de propina dentro del HTML del fee "Propina" generado por WC.
	 * Resultado: una sola fila con [select] + $monto
	 *
	 * @param string $fee_html HTML del monto del fee.
	 * @param object $fee      Objeto fee de WC.
	 * @return string
	 */
	public function inject_tip_select_into_fee_row( string $fee_html, $fee ): string {
		if ( $fee->name !== self::TIP_FEE_LABEL ) {
			return $fee_html;
		}

		$select_html = $this->get_tip_select_html();

		return '<span class="restohub-tip-inline">' . $select_html . ' ' . $fee_html . '</span>';
	}

	/**
	 * Si la propina es 0%, WC no crea fila de fee.
	 * En ese caso, renderizamos una fila custom con solo el select.
	 */
	public function render_tip_row_if_no_fee(): void {
		$tip_options = $this->repo->get_tip_options( 0 );
		if ( empty( $tip_options ) ) {
			return;
		}

		// Si ya hay un fee de propina, WC lo renderiza y el filtro inject_tip_select_into_fee_row
		// ya agrega el select → no duplicar.
		$current_tip = $this->get_session_tip();
		if ( $current_tip > 0 ) {
			return;
		}

		$select_html = $this->get_tip_select_html();

		?>
		<tr class="fee restohub-tip-row">
			<th><?php echo esc_html( self::TIP_FEE_LABEL ); ?></th>
			<td><?php echo $select_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
		</tr>
		<?php
	}

	/**
	 * Genera el HTML del <select> de propina (reutilizado en ambos contextos)
	 *
	 * @return string
	 */
	private function get_tip_select_html(): string {
		$tip_options = $this->repo->get_tip_options( 0 );
		if ( empty( $tip_options ) ) {
			return '';
		}

		$current_tip = $this->get_session_tip();

		$percentages = array_unique( array_map( function( $opt ) {
			return floatval( $opt['percentage'] );
		}, $tip_options ) );
		sort( $percentages );

		$nonce = wp_create_nonce( 'restohub_tip_nonce' );

		$html = '<select class="restohub-tip-select" data-nonce="' . esc_attr( $nonce ) . '">';
		foreach ( $percentages as $pct ) {
			$label    = ( $pct == 0 ) ? __( 'Sin propina', 'restohub' ) : $pct . '%';
			$selected = ( abs( $pct - $current_tip ) < 0.01 ) ? ' selected' : '';
			$html    .= '<option value="' . esc_attr( $pct ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';

		return $html;
	}

	/**
	 * AJAX: Guarda el porcentaje de propina seleccionado en la sesión
	 */
	public function ajax_set_tip(): void {
		check_ajax_referer( 'restohub_tip_nonce', 'nonce' );

		$percentage = floatval( $_POST['percentage'] ?? 0 );

		if ( $percentage < 0 || $percentage > 100 ) {
			$percentage = 0;
		}

		WC()->session->set( 'restohub_tip_percentage', $percentage );

		wp_send_json_success( array( 'percentage' => $percentage ) );
	}

	/**
	 * Guarda los cargos aplicados en la tabla restohub_order_fees
	 *
	 * @param int      $order_id
	 * @param array    $posted_data
	 * @param WC_Order $order
	 */
	public function save_order_fees( $order_id, $posted_data, $order ): void {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		$service_fee_config = $this->repo->get_service_fee( 0 );
		$tip_percentage     = $this->get_session_tip();

		// Guardar cuota de servicio
		if ( $service_fee_config && ! empty( $service_fee_config['is_active'] ) && floatval( $service_fee_config['percentage'] ) > 0 ) {
			$subtotal   = $cart->get_subtotal();
			$shipping   = $cart->get_shipping_total();
			$base       = $subtotal + $shipping;
			$percentage = floatval( $service_fee_config['percentage'] );
			$fee_amount = round( $base * $percentage / 100, 2 );

			if ( $fee_amount > 0 ) {
				$this->repo->save_order_fee( array(
					'order_id'    => $order_id,
					'fee_type'    => 'service_fee',
					'label'       => $service_fee_config['label'] ?: 'Cuota de servicio',
					'percentage'  => $percentage,
					'base_amount' => $base,
					'fee_amount'  => $fee_amount,
				) );
			}
		}

		// Guardar propina
		if ( $tip_percentage > 0 ) {
			$subtotal   = $cart->get_subtotal();
			$tip_amount = round( $subtotal * $tip_percentage / 100, 2 );

			if ( $tip_amount > 0 ) {
				$this->repo->save_order_fee( array(
					'order_id'    => $order_id,
					'fee_type'    => 'tip',
					'label'       => self::TIP_FEE_LABEL,
					'percentage'  => $tip_percentage,
					'base_amount' => $subtotal,
					'fee_amount'  => $tip_amount,
				) );
			}
		}

		// Limpiar sesión de propina
		WC()->session->set( 'restohub_tip_percentage', null );
	}

	/**
	 * Obtiene el porcentaje de propina de la sesión
	 *
	 * @return float
	 */
	private function get_session_tip(): float {
		if ( ! WC()->session ) {
			return 0.0;
		}

		return floatval( WC()->session->get( 'restohub_tip_percentage', 0 ) );
	}
}
