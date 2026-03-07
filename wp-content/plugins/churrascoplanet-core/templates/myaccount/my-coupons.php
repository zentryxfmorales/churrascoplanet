<?php
/**
 * Template: Mis Cupones
 *
 * Muestra los cupones disponibles para el cliente.
 *
 * Variables disponibles:
 * - $customer: ChurrascoPlanet_Customer
 * - $coupons: array de WC_Coupon
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="chp-my-coupons">
    <div class="chp-coupons-header">
        <h2><?php esc_html_e('Mis Cupones', 'churrascoplanet-core'); ?></h2>
        <p><?php esc_html_e('Aquí puedes ver todos los cupones disponibles para usar en tus compras.', 'churrascoplanet-core'); ?></p>
    </div>

    <?php if (empty($coupons)): ?>
        <div class="chp-no-coupons">
            <div class="chp-empty-icon">
                <i class="fas fa-ticket-alt"></i>
            </div>
            <h3><?php esc_html_e('No tienes cupones disponibles', 'churrascoplanet-core'); ?></h3>
            <p><?php esc_html_e('Cuando tengas cupones activos, aparecerán aquí para que los puedas usar.', 'churrascoplanet-core'); ?></p>
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="button">
                <?php esc_html_e('Ir a la tienda', 'churrascoplanet-core'); ?>
            </a>
        </div>
    <?php else: ?>
        <div class="chp-coupons-grid">
            <?php foreach ($coupons as $coupon):
                $discount_type = $coupon->get_discount_type();
                $amount = $coupon->get_amount();
                $expiry = $coupon->get_date_expires();
                $min_spend = $coupon->get_minimum_amount();
                $description = $coupon->get_description();

                // Determinar texto de descuento
                if ($discount_type === 'percent') {
                    $discount_text = $amount . '%';
                    $discount_label = __('de descuento', 'churrascoplanet-core');
                } elseif ($discount_type === 'fixed_cart' || $discount_type === 'fixed_product') {
                    $discount_text = wc_price($amount);
                    $discount_label = __('de descuento', 'churrascoplanet-core');
                } else {
                    $discount_text = '';
                    $discount_label = __('Envío gratis', 'churrascoplanet-core');
                }
            ?>
            <div class="chp-coupon-card">
                <div class="chp-coupon-discount">
                    <?php if ($discount_text): ?>
                        <span class="chp-discount-amount"><?php echo $discount_text; ?></span>
                        <span class="chp-discount-label"><?php echo esc_html($discount_label); ?></span>
                    <?php else: ?>
                        <span class="chp-discount-label chp-free-shipping">
                            <i class="fas fa-truck"></i>
                            <?php echo esc_html($discount_label); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="chp-coupon-details">
                    <div class="chp-coupon-code">
                        <span class="chp-code-label"><?php esc_html_e('Código:', 'churrascoplanet-core'); ?></span>
                        <span class="chp-code-value"><?php echo esc_html(strtoupper($coupon->get_code())); ?></span>
                        <button type="button" class="chp-copy-code" data-code="<?php echo esc_attr($coupon->get_code()); ?>" title="<?php esc_attr_e('Copiar código', 'churrascoplanet-core'); ?>">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>

                    <?php if ($description): ?>
                    <p class="chp-coupon-description"><?php echo esc_html($description); ?></p>
                    <?php endif; ?>

                    <div class="chp-coupon-meta">
                        <?php if ($min_spend): ?>
                        <span class="chp-min-spend">
                            <i class="fas fa-shopping-cart"></i>
                            <?php printf(esc_html__('Compra mínima: %s', 'churrascoplanet-core'), wc_price($min_spend)); ?>
                        </span>
                        <?php endif; ?>

                        <?php if ($expiry): ?>
                        <span class="chp-expiry <?php echo $expiry->getTimestamp() < strtotime('+7 days') ? 'chp-expiring-soon' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <?php
                            $days_left = ceil(($expiry->getTimestamp() - time()) / DAY_IN_SECONDS);
                            if ($days_left <= 0) {
                                esc_html_e('Expira hoy', 'churrascoplanet-core');
                            } elseif ($days_left === 1) {
                                esc_html_e('Expira mañana', 'churrascoplanet-core');
                            } elseif ($days_left <= 7) {
                                printf(esc_html__('Expira en %d días', 'churrascoplanet-core'), $days_left);
                            } else {
                                printf(esc_html__('Válido hasta %s', 'churrascoplanet-core'), $expiry->date_i18n(get_option('date_format')));
                            }
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="chp-use-coupon-btn">
                    <?php esc_html_e('Usar cupón', 'churrascoplanet-core'); ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="chp-coupons-help">
            <p>
                <i class="fas fa-info-circle"></i>
                <?php esc_html_e('Para aplicar un cupón, copia el código y pégalo en el carrito o checkout.', 'churrascoplanet-core'); ?>
            </p>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Copiar código de cupón
    document.querySelectorAll('.chp-copy-code').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var code = this.dataset.code;
            navigator.clipboard.writeText(code).then(function() {
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(function() {
                    btn.innerHTML = '<i class="fas fa-copy"></i>';
                }, 2000);
            });
        });
    });
});
</script>
