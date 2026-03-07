<?php
/**
 * My Account Dashboard - Override
 *
 * Reemplaza el dashboard default de WooCommerce (Hello %s / From your account...)
 * para que solo se renderice el dashboard custom del plugin ChurrascoPlanet Core.
 *
 * @package theme-churrascoplanet
 * @version 1.1.3
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Solo ejecutar el hook donde el plugin inyecta el dashboard custom.
 * Si el plugin no está activo, mostramos un fallback básico en español.
 */
do_action('woocommerce_account_dashboard');

if (!defined('CHP_CORE_VERSION')) :
    // Fallback si el plugin churrascoplanet-core no está activo
    $current_user = wp_get_current_user();
    $first_name   = $current_user->first_name ?: $current_user->display_name;
?>
<div class="chp-dashboard-fallback">
    <p class="myaccount-welcome">
        <?php printf( 'Hola, <strong>%s</strong>', esc_html( $first_name ) ); ?>
        <span>(<a href="<?php echo esc_url( wc_logout_url() ); ?>"><?php esc_html_e( 'Cerrar sesión', 'churrascoplanet' ); ?></a>)</span>
    </p>
    <p>
        <?php
        printf(
            'Desde tu cuenta puedes ver tus <a href="%1$s">pedidos recientes</a>, gestionar tu <a href="%2$s">dirección de entrega</a> y <a href="%3$s">editar tus datos</a>.',
            esc_url( wc_get_endpoint_url( 'orders' ) ),
            esc_url( wc_get_endpoint_url( 'edit-address' ) ),
            esc_url( wc_get_endpoint_url( 'edit-account' ) )
        );
        ?>
    </p>
</div>
<?php endif; ?>
