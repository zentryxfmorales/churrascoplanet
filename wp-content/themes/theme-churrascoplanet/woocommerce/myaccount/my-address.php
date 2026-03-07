<?php
/**
 * My Addresses - ChurrascoPlanet Override
 *
 * Solo muestra dirección de entrega (shipping).
 * Override de: woocommerce/templates/myaccount/my-address.php
 *
 * @package ChurrascoPlanet
 * @version 9.3.0
 */

defined( 'ABSPATH' ) || exit;

$customer_id = get_current_user_id();
$address     = wc_get_account_formatted_address( 'shipping' );
$edit_url    = wc_get_endpoint_url( 'edit-address', 'shipping' );
?>

<p class="myaccount-address-description">
	<?php esc_html_e( 'Tu dirección de entrega para pedidos de delivery.', 'churrascoplanet' ); ?>
</p>

<div class="woocommerce-Addresses">
	<div class="woocommerce-Address">
		<header class="woocommerce-Address-title">
			<h3><?php esc_html_e( 'Dirección de entrega', 'churrascoplanet' ); ?></h3>
			<a href="<?php echo esc_url( $edit_url ); ?>" class="edit">
				<?php echo $address ? esc_html__( 'Editar', 'churrascoplanet' ) : esc_html__( 'Agregar', 'churrascoplanet' ); ?>
			</a>
		</header>
		<address>
			<?php
			if ( $address ) {
				echo wp_kses_post( $address );
			} else {
				esc_html_e( 'Aún no has configurado tu dirección de entrega.', 'churrascoplanet' );
			}

			do_action( 'woocommerce_my_account_after_my_address', 'shipping' );
			?>
		</address>
	</div>
</div>
