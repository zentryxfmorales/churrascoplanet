<?php
/**
 * Template: Rastreo de pedido individual
 *
 * Muestra el estado en tiempo real del delivery de un pedido.
 *
 * Variables disponibles:
 * - $order: WC_Order
 * - $uber_delivery_id: string
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$uber_status = $order->get_meta('_uber_status') ?: 'pending';
$tracking = chp_order_tracking();
$status_info = $tracking->get_status_info($uber_status);
$timeline = $tracking->build_timeline($uber_status);

// Info del courier
$courier_name = $order->get_meta('_uber_courier_name');
$courier_phone = $order->get_meta('_uber_courier_phone');
$eta_minutes = $order->get_meta('_uber_eta_minutes');

// Info de la tienda
$store_name = $order->get_meta('_wcudc_store_name');
?>

<div class="chp-order-tracking" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
    <!-- Header con info del pedido -->
    <div class="chp-tracking-header">
        <div class="chp-order-summary">
            <h2>
                <?php printf(esc_html__('Pedido #%s', 'churrascoplanet-core'), esc_html($order->get_order_number())); ?>
            </h2>
            <p class="chp-order-date">
                <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format') . ' - ' . get_option('time_format'))); ?>
            </p>
            <?php if ($store_name): ?>
            <p class="chp-store-info">
                <i class="fas fa-store"></i>
                <?php echo esc_html($store_name); ?>
            </p>
            <?php endif; ?>
        </div>

        <div class="chp-current-status" style="background-color: <?php echo esc_attr($status_info['color']); ?>">
            <i class="fas <?php echo esc_attr($status_info['icon']); ?>"></i>
            <span class="chp-status-label"><?php echo esc_html($status_info['label']); ?></span>
        </div>
    </div>

    <?php if ($eta_minutes && $uber_status !== 'delivered' && $uber_status !== 'canceled'): ?>
    <!-- ETA -->
    <div class="chp-eta-banner">
        <i class="fas fa-clock"></i>
        <span>
            <?php printf(esc_html__('Tiempo estimado de llegada: %d minutos', 'churrascoplanet-core'), absint($eta_minutes)); ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <div class="chp-tracking-timeline">
        <h3><?php esc_html_e('Estado del pedido', 'churrascoplanet-core'); ?></h3>
        <div class="chp-timeline">
            <?php foreach ($timeline as $step): ?>
            <div class="chp-timeline-step <?php echo $step['completed'] ? 'completed' : ''; ?> <?php echo $step['active'] ? 'active' : ''; ?>">
                <div class="chp-timeline-marker" style="<?php echo ($step['completed'] || $step['active']) ? 'background-color: ' . esc_attr($step['color']) . ';' : ''; ?>">
                    <i class="fas <?php echo esc_attr($step['icon']); ?>"></i>
                </div>
                <div class="chp-timeline-content">
                    <span class="chp-timeline-label"><?php echo esc_html($step['label']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($courier_name && $uber_status !== 'delivered' && $uber_status !== 'canceled'): ?>
    <!-- Info del courier -->
    <div class="chp-courier-info">
        <h3><?php esc_html_e('Tu repartidor', 'churrascoplanet-core'); ?></h3>
        <div class="chp-courier-card">
            <div class="chp-courier-avatar">
                <i class="fas fa-user"></i>
            </div>
            <div class="chp-courier-details">
                <span class="chp-courier-name"><?php echo esc_html($courier_name); ?></span>
                <?php if ($courier_phone): ?>
                <a href="tel:<?php echo esc_attr($courier_phone); ?>" class="chp-courier-phone">
                    <i class="fas fa-phone"></i> <?php echo esc_html($courier_phone); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Productos del pedido -->
    <div class="chp-order-items">
        <h3><?php esc_html_e('Resumen del pedido', 'churrascoplanet-core'); ?></h3>
        <ul class="chp-items-list">
            <?php foreach ($order->get_items() as $item): ?>
            <li class="chp-order-item">
                <span class="chp-item-qty"><?php echo esc_html($item->get_quantity()); ?>x</span>
                <span class="chp-item-name"><?php echo esc_html($item->get_name()); ?></span>
                <span class="chp-item-total"><?php echo wc_price($item->get_total()); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="chp-order-total">
            <span><?php esc_html_e('Total', 'churrascoplanet-core'); ?></span>
            <strong><?php echo $order->get_formatted_order_total(); ?></strong>
        </div>
    </div>

    <!-- Dirección de entrega -->
    <div class="chp-delivery-address">
        <h3><?php esc_html_e('Dirección de entrega', 'churrascoplanet-core'); ?></h3>
        <address>
            <?php echo wp_kses_post($order->get_formatted_shipping_address()); ?>
        </address>
    </div>

    <!-- Botón de actualizar -->
    <div class="chp-tracking-actions">
        <button type="button" class="chp-refresh-btn" id="chp-refresh-tracking">
            <i class="fas fa-sync-alt"></i>
            <?php esc_html_e('Actualizar estado', 'churrascoplanet-core'); ?>
        </button>
        <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="chp-back-link">
            <i class="fas fa-arrow-left"></i>
            <?php esc_html_e('Volver a mis pedidos', 'churrascoplanet-core'); ?>
        </a>
    </div>

    <!-- Última actualización -->
    <p class="chp-last-update">
        <small>
            <?php
            $last_update = $order->get_meta('_uber_status_updated');
            if ($last_update) {
                printf(
                    esc_html__('Última actualización: %s', 'churrascoplanet-core'),
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_update))
                );
            }
            ?>
        </small>
    </p>
</div>
