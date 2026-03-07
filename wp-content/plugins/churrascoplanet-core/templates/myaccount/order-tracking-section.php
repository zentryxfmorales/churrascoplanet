<?php
/**
 * Template: Sección de rastreo en vista de pedido
 *
 * Se muestra en la página de ver pedido individual de WooCommerce.
 *
 * Variables disponibles:
 * - $order: WC_Order
 * - $uber_delivery_id: string
 * - $status: string
 * - $status_info: array
 * - $timeline: array
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$eta_minutes = $order->get_meta('_uber_eta_minutes');
$courier_name = $order->get_meta('_uber_courier_name');
?>

<section class="chp-tracking-section woocommerce-order-tracking">
    <h2><?php esc_html_e('Estado del Delivery', 'churrascoplanet-core'); ?></h2>

    <div class="chp-tracking-mini">
        <!-- Estado actual -->
        <div class="chp-mini-status" style="border-left-color: <?php echo esc_attr($status_info['color']); ?>">
            <div class="chp-mini-status-icon" style="background-color: <?php echo esc_attr($status_info['color']); ?>">
                <i class="fas <?php echo esc_attr($status_info['icon']); ?>"></i>
            </div>
            <div class="chp-mini-status-info">
                <span class="chp-mini-status-label"><?php echo esc_html($status_info['label']); ?></span>
                <?php if ($eta_minutes && $status !== 'delivered' && $status !== 'canceled'): ?>
                <span class="chp-mini-eta">
                    <?php printf(esc_html__('Llegada estimada: ~%d min', 'churrascoplanet-core'), absint($eta_minutes)); ?>
                </span>
                <?php endif; ?>
                <?php if ($courier_name && $status !== 'delivered' && $status !== 'canceled'): ?>
                <span class="chp-mini-courier">
                    <?php printf(esc_html__('Repartidor: %s', 'churrascoplanet-core'), esc_html($courier_name)); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mini timeline -->
        <div class="chp-mini-timeline">
            <?php foreach ($timeline as $step): ?>
            <div class="chp-mini-step <?php echo $step['completed'] ? 'completed' : ''; ?> <?php echo $step['active'] ? 'active' : ''; ?>"
                 title="<?php echo esc_attr($step['label']); ?>">
                <span class="chp-mini-dot" style="<?php echo ($step['completed'] || $step['active']) ? 'background-color: ' . esc_attr($step['color']) . ';' : ''; ?>"></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Link a rastreo completo -->
        <a href="<?php echo esc_url(wc_get_account_endpoint_url('order-tracking') . $order->get_id()); ?>" class="chp-view-tracking-link">
            <?php esc_html_e('Ver rastreo completo', 'churrascoplanet-core'); ?>
            <i class="fas fa-external-link-alt"></i>
        </a>
    </div>
</section>

<style>
.chp-tracking-section {
    margin: 2em 0;
    padding: 1.5em;
    background: #f8f9fa;
    border-radius: 8px;
}

.chp-tracking-section h2 {
    margin-top: 0;
    margin-bottom: 1em;
    font-size: 1.2em;
}

.chp-mini-status {
    display: flex;
    align-items: center;
    gap: 1em;
    padding: 1em;
    background: #fff;
    border-radius: 6px;
    border-left: 4px solid;
    margin-bottom: 1em;
}

.chp-mini-status-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    flex-shrink: 0;
}

.chp-mini-status-info {
    display: flex;
    flex-direction: column;
    gap: 0.25em;
}

.chp-mini-status-label {
    font-weight: 600;
    font-size: 1.1em;
}

.chp-mini-eta,
.chp-mini-courier {
    font-size: 0.9em;
    color: #666;
}

.chp-mini-timeline {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 1em 0;
    padding: 0 1em;
    position: relative;
}

.chp-mini-timeline::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 1em;
    right: 1em;
    height: 2px;
    background: #ddd;
    z-index: 0;
}

.chp-mini-step {
    position: relative;
    z-index: 1;
}

.chp-mini-dot {
    display: block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #ddd;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #ddd;
    transition: all 0.3s;
}

.chp-mini-step.completed .chp-mini-dot,
.chp-mini-step.active .chp-mini-dot {
    box-shadow: 0 0 0 2px currentColor;
}

.chp-mini-step.active .chp-mini-dot {
    transform: scale(1.3);
}

.chp-view-tracking-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5em;
    color: #C41E3A;
    text-decoration: none;
    font-weight: 500;
}

.chp-view-tracking-link:hover {
    text-decoration: underline;
}
</style>
