<?php
/**
 * Template: Dashboard personalizado de Mi Cuenta
 *
 * Muestra un resumen del cliente con estadísticas y accesos rápidos.
 *
 * Variables disponibles:
 * - $customer: ChurrascoPlanet_Customer
 * - $wc_customer: WC_Customer
 * - $total_orders: int
 * - $total_spent: float
 * - $last_order_date: string|null
 * - $available_coupons: int
 * - $tracking_order: WC_Order|null
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="chp-dashboard-custom">
    <!-- Bienvenida -->
    <div class="chp-welcome-section">
        <h2><?php printf(esc_html__('Hola, %s', 'churrascoplanet-core'), esc_html($wc_customer->get_first_name() ?: $wc_customer->get_display_name())); ?></h2>
        <p class="chp-welcome-text">
            <?php esc_html_e('Desde tu cuenta puedes ver tus pedidos recientes, rastrear entregas y gestionar tus preferencias.', 'churrascoplanet-core'); ?>
        </p>
    </div>

    <!-- Tarjetas de estadísticas -->
    <div class="chp-stats-grid">
        <div class="chp-stat-card">
            <div class="chp-stat-icon">
                <i class="fas fa-shopping-bag"></i>
            </div>
            <div class="chp-stat-content">
                <span class="chp-stat-number"><?php echo esc_html($total_orders); ?></span>
                <span class="chp-stat-label"><?php esc_html_e('Pedidos', 'churrascoplanet-core'); ?></span>
            </div>
        </div>

        <div class="chp-stat-card">
            <div class="chp-stat-icon">
                <i class="fas fa-wallet"></i>
            </div>
            <div class="chp-stat-content">
                <span class="chp-stat-number"><?php echo wc_price($total_spent); ?></span>
                <span class="chp-stat-label"><?php esc_html_e('Total gastado', 'churrascoplanet-core'); ?></span>
            </div>
        </div>

        <div class="chp-stat-card chp-stat-coupons">
            <div class="chp-stat-icon">
                <i class="fas fa-ticket-alt"></i>
            </div>
            <div class="chp-stat-content">
                <span class="chp-stat-number"><?php echo esc_html($available_coupons); ?></span>
                <span class="chp-stat-label"><?php esc_html_e('Cupones disponibles', 'churrascoplanet-core'); ?></span>
            </div>
            <?php if ($available_coupons > 0): ?>
                <a href="<?php echo esc_url(wc_get_account_endpoint_url('my-coupons')); ?>" class="chp-stat-link">
                    <?php esc_html_e('Ver cupones', 'churrascoplanet-core'); ?> <i class="fas fa-arrow-right"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($tracking_order): ?>
    <!-- Pedido en curso -->
    <div class="chp-active-order">
        <h3><i class="fas fa-truck"></i> <?php esc_html_e('Pedido en camino', 'churrascoplanet-core'); ?></h3>
        <div class="chp-active-order-content">
            <div class="chp-order-info">
                <span class="chp-order-number">
                    <?php printf(esc_html__('Pedido #%s', 'churrascoplanet-core'), $tracking_order->get_order_number()); ?>
                </span>
                <span class="chp-order-date">
                    <?php echo esc_html($tracking_order->get_date_created()->date_i18n(get_option('date_format'))); ?>
                </span>
            </div>
            <div class="chp-order-status">
                <?php
                $uber_status = $tracking_order->get_meta('_uber_status') ?: 'pending';
                $tracking = chp_order_tracking();
                $status_info = $tracking->get_status_info($uber_status);
                ?>
                <span class="chp-status-badge" style="background-color: <?php echo esc_attr($status_info['color']); ?>">
                    <i class="fas <?php echo esc_attr($status_info['icon']); ?>"></i>
                    <?php echo esc_html($status_info['label']); ?>
                </span>
            </div>
            <a href="<?php echo esc_url(wc_get_account_endpoint_url('order-tracking') . $tracking_order->get_id()); ?>" class="chp-track-btn">
                <?php esc_html_e('Rastrear pedido', 'churrascoplanet-core'); ?>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Acciones rápidas -->
    <div class="chp-quick-actions">
        <h3><?php esc_html_e('Acciones rápidas', 'churrascoplanet-core'); ?></h3>
        <div class="chp-actions-grid">
            <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="chp-action-card">
                <i class="fas fa-receipt"></i>
                <span><?php esc_html_e('Ver pedidos', 'churrascoplanet-core'); ?></span>
            </a>

            <a href="<?php echo esc_url(wc_get_account_endpoint_url('order-tracking')); ?>" class="chp-action-card">
                <i class="fas fa-map-marker-alt"></i>
                <span><?php esc_html_e('Rastrear pedido', 'churrascoplanet-core'); ?></span>
            </a>

            <a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-address')); ?>" class="chp-action-card">
                <i class="fas fa-home"></i>
                <span><?php esc_html_e('Mis direcciones', 'churrascoplanet-core'); ?></span>
            </a>

            <a href="<?php echo esc_url(wc_get_account_endpoint_url('preferences')); ?>" class="chp-action-card">
                <i class="fas fa-cog"></i>
                <span><?php esc_html_e('Preferencias', 'churrascoplanet-core'); ?></span>
            </a>
        </div>
    </div>

    <?php if ($last_order_date): ?>
    <!-- Último pedido -->
    <p class="chp-last-order-info">
        <i class="fas fa-history"></i>
        <?php
        printf(
            esc_html__('Tu último pedido fue el %s', 'churrascoplanet-core'),
            date_i18n(get_option('date_format'), strtotime($last_order_date))
        );
        ?>
    </p>
    <?php endif; ?>
</div>
