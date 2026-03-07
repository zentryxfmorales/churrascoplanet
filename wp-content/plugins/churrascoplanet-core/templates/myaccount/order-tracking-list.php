<?php
/**
 * Template: Lista de pedidos rastreables
 *
 * Muestra los pedidos que tienen delivery activo y pueden ser rastreados.
 *
 * Variables disponibles:
 * - $orders: array de WC_Order
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$tracking = chp_order_tracking();
?>

<div class="chp-tracking-list">
    <div class="chp-tracking-list-header">
        <h2><?php esc_html_e('Rastrear Pedido', 'churrascoplanet-core'); ?></h2>
        <p><?php esc_html_e('Selecciona un pedido para ver su estado de entrega en tiempo real.', 'churrascoplanet-core'); ?></p>
    </div>

    <?php if (empty($orders)): ?>
        <div class="chp-no-tracking-orders">
            <div class="chp-empty-icon">
                <i class="fas fa-truck"></i>
            </div>
            <h3><?php esc_html_e('No hay pedidos en camino', 'churrascoplanet-core'); ?></h3>
            <p><?php esc_html_e('Cuando tengas un pedido con delivery activo, podrás rastrearlo desde aquí.', 'churrascoplanet-core'); ?></p>
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="button">
                <?php esc_html_e('Ir a la tienda', 'churrascoplanet-core'); ?>
            </a>
        </div>
    <?php else: ?>
        <div class="chp-tracking-orders-list">
            <?php foreach ($orders as $order):
                $uber_status = $order->get_meta('_uber_status') ?: 'pending';
                $status_info = $tracking->get_status_info($uber_status);
                $eta_minutes = $order->get_meta('_uber_eta_minutes');
                $store_name = $order->get_meta('_wcudc_store_name');
            ?>
            <div class="chp-tracking-order-card">
                <div class="chp-order-card-header">
                    <div class="chp-order-number">
                        <span class="chp-label"><?php esc_html_e('Pedido', 'churrascoplanet-core'); ?></span>
                        <span class="chp-value">#<?php echo esc_html($order->get_order_number()); ?></span>
                    </div>
                    <div class="chp-order-status-badge" style="background-color: <?php echo esc_attr($status_info['color']); ?>">
                        <i class="fas <?php echo esc_attr($status_info['icon']); ?>"></i>
                        <?php echo esc_html($status_info['label']); ?>
                    </div>
                </div>

                <div class="chp-order-card-body">
                    <div class="chp-order-meta">
                        <span class="chp-order-date">
                            <i class="far fa-calendar"></i>
                            <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?>
                        </span>
                        <?php if ($store_name): ?>
                        <span class="chp-order-store">
                            <i class="fas fa-store"></i>
                            <?php echo esc_html($store_name); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($eta_minutes && $uber_status !== 'delivered'): ?>
                    <div class="chp-order-eta">
                        <i class="fas fa-clock"></i>
                        <?php printf(esc_html__('Llega en ~%d min', 'churrascoplanet-core'), absint($eta_minutes)); ?>
                    </div>
                    <?php endif; ?>

                    <div class="chp-order-items-preview">
                        <?php
                        $items = $order->get_items();
                        $items_count = count($items);
                        $preview_items = array_slice($items, 0, 3);
                        ?>
                        <?php foreach ($preview_items as $item): ?>
                            <span class="chp-item-preview"><?php echo esc_html($item->get_quantity()); ?>x <?php echo esc_html($item->get_name()); ?></span>
                        <?php endforeach; ?>
                        <?php if ($items_count > 3): ?>
                            <span class="chp-more-items">+<?php echo ($items_count - 3); ?> <?php esc_html_e('más', 'churrascoplanet-core'); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="chp-order-total">
                        <span><?php esc_html_e('Total:', 'churrascoplanet-core'); ?></span>
                        <strong><?php echo $order->get_formatted_order_total(); ?></strong>
                    </div>
                </div>

                <div class="chp-order-card-footer">
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('order-tracking') . $order->get_id()); ?>" class="chp-track-button">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php esc_html_e('Ver rastreo', 'churrascoplanet-core'); ?>
                    </a>
                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="chp-view-order-link">
                        <?php esc_html_e('Ver detalles', 'churrascoplanet-core'); ?>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="chp-tracking-help">
        <p>
            <i class="fas fa-info-circle"></i>
            <?php esc_html_e('Los pedidos con delivery Uber Direct se actualizan automáticamente cada 30 segundos.', 'churrascoplanet-core'); ?>
        </p>
    </div>
</div>
