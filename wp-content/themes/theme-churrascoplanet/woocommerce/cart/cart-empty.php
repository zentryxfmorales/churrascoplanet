<?php
/**
 * Empty cart page
 *
 * ChurrascoPlanet - Custom empty cart template.
 * Displayed when the cart is empty (e.g. after redirecting from checkout).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/cart-empty.php.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package ChurrascoPlanet
 * @version 7.0.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * @hooked wc_print_notices - 10
 */
do_action( 'woocommerce_before_cart' );
?>

<div class="cart-empty-wrapper">

    <!-- Page Header -->
    <div class="cart-page-title">
        <span class="section-badge">
            <i class="fas fa-shopping-cart"></i>
            <?php esc_html_e( 'Mi Pedido', 'churrascoplanet' ); ?>
        </span>
        <h1 class="section-title">
            <?php esc_html_e( 'Carrito de', 'churrascoplanet' ); ?>
            <span class="highlight"><?php esc_html_e( 'Compras', 'churrascoplanet' ); ?></span>
        </h1>
    </div>

    <!-- Empty State Card -->
    <div class="cart-empty-card">

        <div class="cart-empty-icon">
            <i class="fas fa-shopping-bag"></i>
        </div>

        <h2 class="cart-empty-title">
            <?php esc_html_e( 'Tu carrito está vacío', 'churrascoplanet' ); ?>
        </h2>

        <p class="cart-empty-text">
            <?php esc_html_e( 'Aún no has agregado productos a tu pedido. ¡Explora nuestro menú y encuentra algo delicioso!', 'churrascoplanet' ); ?>
        </p>

        <?php if ( wc_get_page_id( 'shop' ) > 0 ) : ?>
        <a href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>"
           class="cart-btn-return">
            <i class="fas fa-utensils"></i>
            <?php echo esc_html( apply_filters( 'woocommerce_return_to_shop_text', __( 'Explorar el Menú', 'churrascoplanet' ) ) ); ?>
        </a>
        <?php endif; ?>

    </div><!-- .cart-empty-card -->

</div><!-- .cart-empty-wrapper -->

<?php do_action( 'woocommerce_after_cart' ); ?>
