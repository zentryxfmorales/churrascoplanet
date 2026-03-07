<?php
/**
 * My Account - Edit Shipping Address Form (ChurrascoPlanet Override)
 *
 * Personaliza el formulario de edición de dirección de envío:
 * - Campo de dirección con autocompletado Nominatim (coherente con checkout)
 * - País siempre CL (oculto)
 * - Código postal oculto (se llena automáticamente)
 * - Disposición: Nombre + Apellidos en una fila
 *
 * Override de: woocommerce/templates/myaccount/form-edit-address.php
 *
 * @package theme-churrascoplanet
 * @version 9.3.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_edit_account_address_form' );

if ( ! $load_address ) :
	wc_get_template( 'myaccount/my-address.php' );
else :

	// Valores actuales guardados del usuario
	$addr1_value     = isset( $address['shipping_address_1']['value'] ) ? $address['shipping_address_1']['value'] : '';
	$postcode_value  = isset( $address['shipping_postcode']['value'] )  ? $address['shipping_postcode']['value']  : '';
	?>

	<form method="post" novalidate>

		<h2><?php esc_html_e( 'Dirección de entrega', 'churrascoplanet' ); ?></h2>

		<div class="woocommerce-address-fields">
			<?php do_action( 'woocommerce_before_edit_address_form_shipping' ); ?>

			<div class="woocommerce-address-fields__field-wrapper">

				<?php
				// ── Nombre + Apellidos ─────────────────────────────────────────
				if ( isset( $address['shipping_first_name'] ) ) {
					woocommerce_form_field(
						'shipping_first_name',
						$address['shipping_first_name'],
						wc_get_post_data_by_key( 'shipping_first_name', $address['shipping_first_name']['value'] )
					);
				}
				if ( isset( $address['shipping_last_name'] ) ) {
					woocommerce_form_field(
						'shipping_last_name',
						$address['shipping_last_name'],
						wc_get_post_data_by_key( 'shipping_last_name', $address['shipping_last_name']['value'] )
					);
				}
				?>

				<?php // ── País: siempre CL, oculto ──────────────────────────── ?>
				<input type="hidden" name="shipping_country" value="CL" />

				<?php
				// ── Campo de dirección con autocompletado ──────────────────────
				// El input visible (#chp-shipping-search) es solo para búsqueda/display.
				// El campo real (shipping_address_1) permanece oculto y es
				// poblado por chp-address-autocomplete.js al seleccionar un resultado.
				?>
				<p class="form-row form-row-wide chp-address-search-field">
					<label for="chp-shipping-search">
						<?php esc_html_e( 'Dirección de entrega', 'churrascoplanet' ); ?>
						<abbr class="required" title="<?php esc_attr_e( 'requerido', 'churrascoplanet' ); ?>">*</abbr>
					</label>
					<span id="chp-shipping-search-wrapper" class="chp-address-search-wrapper">
						<i class="fas fa-search-location chp-addr-search-icon" aria-hidden="true"></i>
						<input
							type="text"
							id="chp-shipping-search"
							class="input-text chp-address-search-input"
							placeholder="<?php esc_attr_e( 'Busca tu calle y número...', 'churrascoplanet' ); ?>"
							autocomplete="off"
							value="<?php echo esc_attr( $addr1_value ); ?>"
						/>
					</span>
					<span id="chp-shipping-results" class="chp-address-results" style="display:none;" role="listbox" aria-label="<?php esc_attr_e( 'Resultados de búsqueda', 'churrascoplanet' ); ?>"></span>

					<?php // Campo real WC — oculto, se rellena con JS ?>
					<input
						type="hidden"
						id="shipping_address_1"
						name="shipping_address_1"
						value="<?php echo esc_attr( $addr1_value ); ?>"
					/>
				</p>

				<?php
				// ── Depto / Piso (opcional) ────────────────────────────────────
				if ( isset( $address['shipping_address_2'] ) ) {
					woocommerce_form_field(
						'shipping_address_2',
						$address['shipping_address_2'],
						wc_get_post_data_by_key( 'shipping_address_2', $address['shipping_address_2']['value'] )
					);
				}

				// ── Comuna / Ciudad ────────────────────────────────────────────
				if ( isset( $address['shipping_city'] ) ) {
					woocommerce_form_field(
						'shipping_city',
						$address['shipping_city'],
						wc_get_post_data_by_key( 'shipping_city', $address['shipping_city']['value'] )
					);
				}

				// ── Región (select — autoseleccionado por JS) ──────────────────
				if ( isset( $address['shipping_state'] ) ) {
					woocommerce_form_field(
						'shipping_state',
						$address['shipping_state'],
						wc_get_post_data_by_key( 'shipping_state', $address['shipping_state']['value'] )
					);
				}
				?>

				<?php // ── Código postal: oculto, se rellena con JS ──────────── ?>
				<input
					type="hidden"
					id="shipping_postcode"
					name="shipping_postcode"
					value="<?php echo esc_attr( $postcode_value ); ?>"
				/>

			</div>

			<?php do_action( 'woocommerce_after_edit_address_form_shipping' ); ?>

			<p class="chp-address-save-row">
				<button
					type="submit"
					class="button woocommerce-Button"
					name="save_address"
					value="<?php esc_attr_e( 'Save address', 'woocommerce' ); ?>"
				>
					<?php esc_html_e( 'Guardar dirección', 'churrascoplanet' ); ?>
				</button>
				<?php wp_nonce_field( 'woocommerce-edit_address', 'woocommerce-edit-address-nonce' ); ?>
				<input type="hidden" name="action" value="edit_address" />
			</p>

		</div>

	</form>

<?php endif; ?>

<?php do_action( 'woocommerce_after_edit_account_address_form' ); ?>
