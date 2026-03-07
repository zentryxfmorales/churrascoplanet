<?php
/**
 * Template: Preferencias del cliente
 *
 * Permite al cliente configurar su local favorito y preferencias de notificaciones.
 *
 * Variables disponibles:
 * - $customer: ChurrascoPlanet_Customer
 * - $stores: array de locales
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$preferred_store_id = $customer->get_preferred_store_id();
$email_notifications = $customer->wants_email_notifications();
$whatsapp_notifications = $customer->wants_whatsapp_notifications();
$whatsapp_number = $customer->get_whatsapp_number();
?>

<div class="chp-preferences">
    <div class="chp-preferences-header">
        <h2><?php esc_html_e('Preferencias', 'churrascoplanet-core'); ?></h2>
        <p><?php esc_html_e('Personaliza tu experiencia en ChurrascoPlanet.', 'churrascoplanet-core'); ?></p>
    </div>

    <form method="post" class="chp-preferences-form" id="chp-preferences-form">
        <?php wp_nonce_field('chp_save_preferences', 'chp_preferences_nonce'); ?>

        <!-- Local Favorito -->
        <div class="chp-preference-section">
            <h3>
                <i class="fas fa-store"></i>
                <?php esc_html_e('Local Favorito', 'churrascoplanet-core'); ?>
            </h3>
            <p class="chp-section-description">
                <?php esc_html_e('Selecciona tu local preferido para que tus pedidos se preparen ahí cuando sea posible.', 'churrascoplanet-core'); ?>
            </p>

            <?php if (!empty($stores)): ?>
            <div class="chp-store-selector">
                <div class="chp-store-option <?php echo empty($preferred_store_id) ? 'selected' : ''; ?>">
                    <input type="radio" name="preferred_store_id" id="store_auto" value="0" <?php checked(empty($preferred_store_id)); ?>>
                    <label for="store_auto">
                        <span class="chp-store-icon"><i class="fas fa-magic"></i></span>
                        <span class="chp-store-name"><?php esc_html_e('Automático', 'churrascoplanet-core'); ?></span>
                        <span class="chp-store-desc"><?php esc_html_e('El más cercano a tu dirección', 'churrascoplanet-core'); ?></span>
                    </label>
                </div>

                <?php foreach ($stores as $store):
                    $store_id = $store['id'] ?? $store['local_id'] ?? 0;
                    $store_name = $store['nombre'] ?? $store['name'] ?? '';
                    $store_address = $store['direccion'] ?? $store['address'] ?? '';
                ?>
                <div class="chp-store-option <?php echo $preferred_store_id == $store_id ? 'selected' : ''; ?>">
                    <input type="radio" name="preferred_store_id" id="store_<?php echo esc_attr($store_id); ?>" value="<?php echo esc_attr($store_id); ?>" <?php checked($preferred_store_id, $store_id); ?>>
                    <label for="store_<?php echo esc_attr($store_id); ?>">
                        <span class="chp-store-icon"><i class="fas fa-map-marker-alt"></i></span>
                        <span class="chp-store-name"><?php echo esc_html($store_name); ?></span>
                        <?php if ($store_address): ?>
                        <span class="chp-store-address"><?php echo esc_html($store_address); ?></span>
                        <?php endif; ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="chp-no-stores"><?php esc_html_e('No hay locales disponibles en este momento.', 'churrascoplanet-core'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Notificaciones -->
        <div class="chp-preference-section">
            <h3>
                <i class="fas fa-bell"></i>
                <?php esc_html_e('Notificaciones', 'churrascoplanet-core'); ?>
            </h3>
            <p class="chp-section-description">
                <?php esc_html_e('Elige cómo quieres recibir actualizaciones sobre tus pedidos.', 'churrascoplanet-core'); ?>
            </p>

            <div class="chp-notifications-options">
                <!-- Email -->
                <div class="chp-notification-toggle">
                    <label class="chp-toggle-switch">
                        <input type="checkbox" name="notification_email" value="1" <?php checked($email_notifications); ?>>
                        <span class="chp-toggle-slider"></span>
                    </label>
                    <div class="chp-toggle-content">
                        <span class="chp-toggle-label">
                            <i class="fas fa-envelope"></i>
                            <?php esc_html_e('Notificaciones por email', 'churrascoplanet-core'); ?>
                        </span>
                        <span class="chp-toggle-desc"><?php esc_html_e('Recibe actualizaciones del estado de tu pedido por correo.', 'churrascoplanet-core'); ?></span>
                    </div>
                </div>

                <!-- WhatsApp -->
                <div class="chp-notification-toggle">
                    <label class="chp-toggle-switch">
                        <input type="checkbox" name="notification_whatsapp" id="notification_whatsapp" value="1" <?php checked($whatsapp_notifications); ?>>
                        <span class="chp-toggle-slider"></span>
                    </label>
                    <div class="chp-toggle-content">
                        <span class="chp-toggle-label">
                            <i class="fab fa-whatsapp"></i>
                            <?php esc_html_e('Notificaciones por WhatsApp', 'churrascoplanet-core'); ?>
                        </span>
                        <span class="chp-toggle-desc"><?php esc_html_e('Recibe mensajes instantáneos sobre tu pedido.', 'churrascoplanet-core'); ?></span>
                    </div>
                </div>

                <!-- Número WhatsApp -->
                <div class="chp-whatsapp-number-field" id="whatsapp-number-field" style="<?php echo $whatsapp_notifications ? '' : 'display: none;'; ?>">
                    <label for="whatsapp_number"><?php esc_html_e('Número de WhatsApp', 'churrascoplanet-core'); ?></label>
                    <div class="chp-phone-input">
                        <span class="chp-phone-prefix">+56</span>
                        <input type="tel" name="whatsapp_number" id="whatsapp_number"
                               value="<?php echo esc_attr(ltrim($whatsapp_number, '+56')); ?>"
                               placeholder="9 1234 5678"
                               pattern="[0-9]{9}"
                               maxlength="9">
                    </div>
                    <span class="chp-field-hint"><?php esc_html_e('Ingresa tu número sin el código de país', 'churrascoplanet-core'); ?></span>
                </div>
            </div>
        </div>

        <!-- Información de cuenta -->
        <div class="chp-preference-section chp-account-info">
            <h3>
                <i class="fas fa-user-circle"></i>
                <?php esc_html_e('Información de la cuenta', 'churrascoplanet-core'); ?>
            </h3>

            <div class="chp-info-grid">
                <div class="chp-info-item">
                    <span class="chp-info-label"><?php esc_html_e('Tipo de cuenta', 'churrascoplanet-core'); ?></span>
                    <span class="chp-info-value">
                        <?php
                        $source = $customer->get_registration_source();
                        $sources = [
                            'normal'          => __('Cuenta normal', 'churrascoplanet-core'),
                            'social_facebook' => __('Facebook', 'churrascoplanet-core'),
                            'social_google'   => __('Google', 'churrascoplanet-core'),
                            'guest'           => __('Invitado', 'churrascoplanet-core'),
                        ];
                        echo esc_html($sources[$source] ?? $source);
                        ?>
                    </span>
                </div>

                <div class="chp-info-item">
                    <span class="chp-info-label"><?php esc_html_e('Total de pedidos', 'churrascoplanet-core'); ?></span>
                    <span class="chp-info-value"><?php echo esc_html($customer->get_total_orders()); ?></span>
                </div>

                <div class="chp-info-item">
                    <span class="chp-info-label"><?php esc_html_e('Total gastado', 'churrascoplanet-core'); ?></span>
                    <span class="chp-info-value"><?php echo wc_price($customer->get_total_spent()); ?></span>
                </div>
            </div>

            <p class="chp-edit-account-link">
                <a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>">
                    <?php esc_html_e('Editar datos de la cuenta', 'churrascoplanet-core'); ?>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </p>
        </div>

        <!-- Botón guardar -->
        <div class="chp-preferences-actions">
            <button type="submit" name="chp_save_preferences" class="button chp-save-btn">
                <i class="fas fa-save"></i>
                <?php esc_html_e('Guardar preferencias', 'churrascoplanet-core'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle WhatsApp number field
    var whatsappCheckbox = document.getElementById('notification_whatsapp');
    var numberField = document.getElementById('whatsapp-number-field');

    if (whatsappCheckbox && numberField) {
        whatsappCheckbox.addEventListener('change', function() {
            numberField.style.display = this.checked ? 'block' : 'none';
        });
    }

    // Store selection visual feedback
    document.querySelectorAll('.chp-store-option input').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.chp-store-option').forEach(function(opt) {
                opt.classList.remove('selected');
            });
            this.closest('.chp-store-option').classList.add('selected');
        });
    });
});
</script>
