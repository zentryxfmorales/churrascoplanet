<?php
/**
 * Tab: Cupones — CRUD completo sincronizado con WooCommerce.
 *
 * @package ChurrascoPlanet_Core
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$option_name = $admin->get_option_name();

// ── Leer cupones directamente de WooCommerce ──────────────────────────────
global $wpdb;
$coupons_raw = $wpdb->get_results(
    "SELECT p.ID, p.post_title, p.post_status,
            MAX(CASE WHEN pm.meta_key='discount_type'   THEN pm.meta_value END) AS discount_type,
            MAX(CASE WHEN pm.meta_key='coupon_amount'   THEN pm.meta_value END) AS coupon_amount,
            MAX(CASE WHEN pm.meta_key='usage_count'     THEN pm.meta_value END) AS usage_count,
            MAX(CASE WHEN pm.meta_key='usage_limit'     THEN pm.meta_value END) AS usage_limit,
            MAX(CASE WHEN pm.meta_key='date_expires'    THEN pm.meta_value END) AS date_expires,
            MAX(CASE WHEN pm.meta_key='minimum_amount'  THEN pm.meta_value END) AS minimum_amount,
            MAX(CASE WHEN pm.meta_key='free_shipping'   THEN pm.meta_value END) AS free_shipping,
            MAX(CASE WHEN pm.meta_key='individual_use'  THEN pm.meta_value END) AS individual_use
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
     WHERE p.post_type = 'shop_coupon'
     GROUP BY p.ID
     ORDER BY p.post_date DESC"
);

$discount_labels = [
    'percent'       => __( '% Porcentaje',      'churrascoplanet-core' ),
    'fixed_cart'    => __( '$ Fijo en carrito',  'churrascoplanet-core' ),
    'fixed_product' => __( '$ Fijo en producto', 'churrascoplanet-core' ),
];

$now       = current_time( 'timestamp' );
$total     = count( $coupons_raw );
$activos   = $expirados = $borradores = $uso_total = 0;

foreach ( $coupons_raw as $c ) {
    $uso_total += (int) $c->usage_count;
    if ( $c->post_status !== 'publish' ) { $borradores++; }
    elseif ( ! empty( $c->date_expires ) && (int) $c->date_expires < $now ) { $expirados++; }
    else { $activos++; }
}
?>

<!-- ══════════════════════════════════════════════════════
     MODAL: Crear / Editar cupón
     ══════════════════════════════════════════════════════ -->
<div id="chp-coupon-modal" class="chp-modal" style="display:none;" role="dialog" aria-modal="true">
    <div class="chp-modal-overlay"></div>
    <div class="chp-modal-box">
        <div class="chp-modal-header">
            <h2 id="chp-modal-title"><i class="fas fa-ticket-alt"></i> <?php _e( 'Nuevo Cupón', 'churrascoplanet-core' ); ?></h2>
            <button type="button" class="chp-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'churrascoplanet-core' ); ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="chp-modal-body">
            <input type="hidden" id="chp-coupon-id" value="">

            <div class="chp-form-grid">
                <!-- Código -->
                <div class="chp-form-field chp-field-full">
                    <label for="chp-f-code"><?php _e( 'Código del cupón', 'churrascoplanet-core' ); ?> <span class="chp-required">*</span></label>
                    <div class="chp-code-input-wrap">
                        <input type="text" id="chp-f-code" class="regular-text" placeholder="DESCUENTO20" autocomplete="off" style="text-transform:uppercase;">
                        <button type="button" class="button" id="chp-gen-code" title="<?php esc_attr_e( 'Generar código aleatorio', 'churrascoplanet-core' ); ?>">
                            <i class="fas fa-random"></i>
                        </button>
                    </div>
                </div>

                <!-- Descripción -->
                <div class="chp-form-field chp-field-full">
                    <label for="chp-f-desc"><?php _e( 'Descripción (interna)', 'churrascoplanet-core' ); ?></label>
                    <input type="text" id="chp-f-desc" class="regular-text" placeholder="<?php esc_attr_e( 'Uso interno, no se muestra al cliente', 'churrascoplanet-core' ); ?>">
                </div>

                <!-- Tipo de descuento -->
                <div class="chp-form-field">
                    <label for="chp-f-type"><?php _e( 'Tipo de descuento', 'churrascoplanet-core' ); ?></label>
                    <select id="chp-f-type">
                        <option value="percent"><?php _e( '% Porcentaje', 'churrascoplanet-core' ); ?></option>
                        <option value="fixed_cart"><?php _e( '$ Fijo en carrito', 'churrascoplanet-core' ); ?></option>
                        <option value="fixed_product"><?php _e( '$ Fijo en producto', 'churrascoplanet-core' ); ?></option>
                    </select>
                </div>

                <!-- Monto -->
                <div class="chp-form-field">
                    <label for="chp-f-amount"><span id="chp-amount-label"><?php _e( 'Descuento (%)', 'churrascoplanet-core' ); ?></span></label>
                    <input type="number" id="chp-f-amount" class="small-text" min="0" step="1" value="0">
                </div>

                <!-- Mínimo pedido -->
                <div class="chp-form-field">
                    <label for="chp-f-min"><?php _e( 'Mínimo de pedido ($)', 'churrascoplanet-core' ); ?></label>
                    <input type="number" id="chp-f-min" class="small-text" min="0" step="1" value="" placeholder="0">
                    <p class="description"><?php _e( '0 = sin mínimo', 'churrascoplanet-core' ); ?></p>
                </div>

                <!-- Máximo pedido -->
                <div class="chp-form-field">
                    <label for="chp-f-max"><?php _e( 'Máximo de pedido ($)', 'churrascoplanet-core' ); ?></label>
                    <input type="number" id="chp-f-max" class="small-text" min="0" step="1" value="" placeholder="0">
                    <p class="description"><?php _e( '0 = sin máximo', 'churrascoplanet-core' ); ?></p>
                </div>

                <!-- Fecha de expiración -->
                <div class="chp-form-field">
                    <label for="chp-f-expires"><?php _e( 'Fecha de expiración', 'churrascoplanet-core' ); ?></label>
                    <input type="date" id="chp-f-expires">
                    <p class="description"><?php _e( 'Dejar vacío = sin expiración', 'churrascoplanet-core' ); ?></p>
                </div>

                <!-- Límite de usos -->
                <div class="chp-form-field">
                    <label for="chp-f-limit"><?php _e( 'Límite de usos totales', 'churrascoplanet-core' ); ?></label>
                    <input type="number" id="chp-f-limit" class="small-text" min="0" step="1" value="" placeholder="∞">
                    <p class="description"><?php _e( 'Vacío = ilimitado', 'churrascoplanet-core' ); ?></p>
                </div>

                <!-- Límite por usuario -->
                <div class="chp-form-field">
                    <label for="chp-f-limit-user"><?php _e( 'Límite de usos por usuario', 'churrascoplanet-core' ); ?></label>
                    <input type="number" id="chp-f-limit-user" class="small-text" min="0" step="1" value="" placeholder="∞">
                </div>

                <!-- Estado -->
                <div class="chp-form-field">
                    <label for="chp-f-status"><?php _e( 'Estado', 'churrascoplanet-core' ); ?></label>
                    <select id="chp-f-status">
                        <option value="publish"><?php _e( 'Activo', 'churrascoplanet-core' ); ?></option>
                        <option value="draft"><?php _e( 'Borrador', 'churrascoplanet-core' ); ?></option>
                    </select>
                </div>

                <!-- Opciones extra -->
                <div class="chp-form-field chp-field-full">
                    <label><?php _e( 'Opciones adicionales', 'churrascoplanet-core' ); ?></label>
                    <div class="chp-checkboxes">
                        <label class="chp-checkbox-label">
                            <input type="checkbox" id="chp-f-individual">
                            <?php _e( 'Uso individual (no se combina con otros cupones)', 'churrascoplanet-core' ); ?>
                        </label>
                        <label class="chp-checkbox-label">
                            <input type="checkbox" id="chp-f-shipping">
                            <?php _e( 'Otorgar envío gratis', 'churrascoplanet-core' ); ?>
                        </label>
                    </div>
                </div>
            </div>

            <div id="chp-modal-notice" class="chp-modal-notice" style="display:none;"></div>
        </div>

        <div class="chp-modal-footer">
            <button type="button" class="button button-large chp-modal-close-btn">
                <?php _e( 'Cancelar', 'churrascoplanet-core' ); ?>
            </button>
            <button type="button" class="button button-primary button-large" id="chp-modal-save">
                <i class="fas fa-save"></i> <span id="chp-modal-save-label"><?php _e( 'Crear Cupón', 'churrascoplanet-core' ); ?></span>
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     CABECERA
     ══════════════════════════════════════════════════════ -->
<div class="options-section chp-cupones-header">
    <div>
        <h2 class="section-title" style="margin-bottom:4px;">
            <i class="fas fa-ticket-alt"></i>
            <?php _e( 'Cupones de Descuento', 'churrascoplanet-core' ); ?>
        </h2>
        <p style="margin:0; color:#646970; font-size:13px;">
            <?php _e( 'Gestión completa sincronizada con WooCommerce.', 'churrascoplanet-core' ); ?>
        </p>
    </div>
    <button type="button" class="button button-primary button-hero" id="chp-btn-new-coupon">
        <i class="fas fa-plus"></i> <?php _e( 'Nuevo Cupón', 'churrascoplanet-core' ); ?>
    </button>
</div>

<!-- ══════════════════════════════════════════════════════
     ESTADÍSTICAS
     ══════════════════════════════════════════════════════ -->
<div class="options-section">
    <div class="chp-cupones-stats">
        <div class="chp-stat-card">
            <div class="chp-stat-icon chp-icon-total"><i class="fas fa-ticket-alt"></i></div>
            <div class="chp-stat-info">
                <span class="chp-stat-number" id="chp-stat-total"><?php echo $total; ?></span>
                <span class="chp-stat-label"><?php _e( 'Total', 'churrascoplanet-core' ); ?></span>
            </div>
        </div>
        <div class="chp-stat-card">
            <div class="chp-stat-icon chp-icon-active"><i class="fas fa-check-circle"></i></div>
            <div class="chp-stat-info">
                <span class="chp-stat-number chp-green" id="chp-stat-active"><?php echo $activos; ?></span>
                <span class="chp-stat-label"><?php _e( 'Activos', 'churrascoplanet-core' ); ?></span>
            </div>
        </div>
        <div class="chp-stat-card">
            <div class="chp-stat-icon chp-icon-expired"><i class="fas fa-clock"></i></div>
            <div class="chp-stat-info">
                <span class="chp-stat-number chp-red" id="chp-stat-expired"><?php echo $expirados; ?></span>
                <span class="chp-stat-label"><?php _e( 'Expirados', 'churrascoplanet-core' ); ?></span>
            </div>
        </div>
        <div class="chp-stat-card">
            <div class="chp-stat-icon chp-icon-uso"><i class="fas fa-chart-bar"></i></div>
            <div class="chp-stat-info">
                <span class="chp-stat-number chp-blue" id="chp-stat-uses"><?php echo $uso_total; ?></span>
                <span class="chp-stat-label"><?php _e( 'Usos totales', 'churrascoplanet-core' ); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     TABLA DE CUPONES
     ══════════════════════════════════════════════════════ -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-list-alt"></i>
        <?php _e( 'Lista de Cupones', 'churrascoplanet-core' ); ?>
    </h2>

    <div id="chp-coupon-list-wrap">
        <?php if ( empty( $coupons_raw ) ) : ?>
            <div class="chp-empty-state" id="chp-empty-state">
                <i class="fas fa-ticket-alt"></i>
                <p><?php _e( 'Aún no hay cupones. Crea el primero.', 'churrascoplanet-core' ); ?></p>
                <button type="button" class="button button-primary" id="chp-btn-new-coupon-2">
                    <i class="fas fa-plus"></i> <?php _e( 'Crear Cupón', 'churrascoplanet-core' ); ?>
                </button>
            </div>
        <?php else : ?>
            <div class="chp-empty-state" id="chp-empty-state" style="display:none;">
                <i class="fas fa-ticket-alt"></i>
                <p><?php _e( 'Aún no hay cupones. Crea el primero.', 'churrascoplanet-core' ); ?></p>
            </div>
        <?php endif; ?>

        <div class="chp-table-wrap" <?php echo empty( $coupons_raw ) ? 'style="display:none;"' : ''; ?> id="chp-table-wrap">
            <table class="chp-coupons-table" id="chp-coupons-table">
                <thead>
                    <tr>
                        <th><?php _e( 'Código', 'churrascoplanet-core' ); ?></th>
                        <th><?php _e( 'Tipo', 'churrascoplanet-core' ); ?></th>
                        <th><?php _e( 'Descuento', 'churrascoplanet-core' ); ?></th>
                        <th><?php _e( 'Mínimo', 'churrascoplanet-core' ); ?></th>
                        <th><?php _e( 'Usos', 'churrascoplanet-core' ); ?></th>
                        <th><?php _e( 'Expira', 'churrascoplanet-core' ); ?></th>
                        <th><?php _e( 'Estado', 'churrascoplanet-core' ); ?></th>
                        <th><?php _e( 'Acciones', 'churrascoplanet-core' ); ?></th>
                    </tr>
                </thead>
                <tbody id="chp-coupons-tbody">
                    <?php foreach ( $coupons_raw as $coupon ) :
                        $is_expired = $coupon->post_status === 'publish' && ! empty( $coupon->date_expires ) && (int) $coupon->date_expires < $now;
                        $is_active  = $coupon->post_status === 'publish' && ! $is_expired;

                        $amount_fmt = $coupon->discount_type === 'percent'
                            ? number_format( (float) $coupon->coupon_amount, 0 ) . '%'
                            : '$' . number_format( (float) $coupon->coupon_amount, 0, ',', '.' );

                        $usos     = (int) $coupon->usage_count;
                        $limit    = ! empty( $coupon->usage_limit ) ? (int) $coupon->usage_limit : null;
                        $usos_str = $usos . ' / ' . ( $limit ?: '∞' );
                        $uso_pct  = $limit ? min( 100, round( $usos / $limit * 100 ) ) : 0;
                        $uso_color = $uso_pct >= 90 ? '#dc3232' : ( $uso_pct >= 60 ? '#ffb268' : '#00a32a' );

                        $expiry_str   = '—';
                        $expiry_class = '';
                        if ( ! empty( $coupon->date_expires ) ) {
                            $ts           = (int) $coupon->date_expires;
                            $expiry_str   = date_i18n( 'd/m/Y', $ts );
                            $days_left    = ( $ts - $now ) / DAY_IN_SECONDS;
                            $expiry_class = $days_left < 0 ? 'chp-expiry-past' : ( $days_left <= 7 ? 'chp-expiry-soon' : '' );
                        }

                        $min_str = ! empty( $coupon->minimum_amount ) && (float) $coupon->minimum_amount > 0
                            ? '$' . number_format( (float) $coupon->minimum_amount, 0, ',', '.' )
                            : '—';

                        $row_class = $is_expired ? 'chp-row-expired' : ( $coupon->post_status !== 'publish' ? 'chp-row-draft' : '' );
                    ?>
                    <tr class="<?php echo esc_attr( $row_class ); ?>" data-id="<?php echo $coupon->ID; ?>">
                        <td>
                            <div class="chp-code-wrap">
                                <code class="chp-coupon-code" title="<?php esc_attr_e( 'Copiar', 'churrascoplanet-core' ); ?>" onclick="chpCopyCode(this)">
                                    <?php echo esc_html( strtoupper( $coupon->post_title ) ); ?>
                                </code>
                                <i class="fas fa-copy chp-copy-hint"></i>
                            </div>
                            <?php if ( $coupon->free_shipping === 'yes' ) : ?>
                                <span class="chp-tag chp-tag-shipping"><i class="fas fa-shipping-fast"></i> <?php _e( 'Envío gratis', 'churrascoplanet-core' ); ?></span>
                            <?php endif; ?>
                            <?php if ( $coupon->individual_use === 'yes' ) : ?>
                                <span class="chp-tag chp-tag-individual"><i class="fas fa-user"></i> <?php _e( 'Uso individual', 'churrascoplanet-core' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $discount_labels[ $coupon->discount_type ] ?? $coupon->discount_type ); ?></td>
                        <td><strong><?php echo esc_html( $amount_fmt ); ?></strong></td>
                        <td><?php echo esc_html( $min_str ); ?></td>
                        <td>
                            <?php echo esc_html( $usos_str ); ?>
                            <?php if ( $limit ) : ?>
                                <div class="chp-usage-bar"><div class="chp-usage-fill" style="width:<?php echo $uso_pct; ?>%;background:<?php echo $uso_color; ?>;"></div></div>
                            <?php endif; ?>
                        </td>
                        <td class="<?php echo esc_attr( $expiry_class ); ?>"><?php echo esc_html( $expiry_str ); ?></td>
                        <td>
                            <?php if ( $is_active ) : ?>
                                <span class="chp-badge chp-badge-active"><i class="fas fa-circle"></i> <?php _e( 'Activo', 'churrascoplanet-core' ); ?></span>
                            <?php elseif ( $is_expired ) : ?>
                                <span class="chp-badge chp-badge-expired"><i class="fas fa-times-circle"></i> <?php _e( 'Expirado', 'churrascoplanet-core' ); ?></span>
                            <?php else : ?>
                                <span class="chp-badge chp-badge-draft"><i class="fas fa-pause-circle"></i> <?php _e( 'Borrador', 'churrascoplanet-core' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="chp-actions-cell">
                            <button type="button" class="button button-small chp-btn-edit" data-id="<?php echo $coupon->ID; ?>" title="<?php esc_attr_e( 'Editar', 'churrascoplanet-core' ); ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="button button-small chp-btn-delete" data-id="<?php echo $coupon->ID; ?>" data-code="<?php echo esc_attr( strtoupper( $coupon->post_title ) ); ?>" title="<?php esc_attr_e( 'Eliminar', 'churrascoplanet-core' ); ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     CONFIGURACIÓN DE APARIENCIA
     ══════════════════════════════════════════════════════ -->
<div class="options-section">
    <h2 class="section-title">
        <i class="fas fa-paint-brush"></i>
        <?php _e( 'Configuración de Cupones en el Sitio', 'churrascoplanet-core' ); ?>
    </h2>
    <div class="options-grid">
        <div class="option-field">
            <label><?php _e( 'Mostrar campo de cupón en el carrito', 'churrascoplanet-core' ); ?></label>
            <select name="<?php echo $option_name; ?>[cupones_mostrar_carrito]">
                <option value="1" <?php selected( $admin->get_option( 'cupones_mostrar_carrito', '1' ), '1' ); ?>><?php _e( 'Sí — Mostrar', 'churrascoplanet-core' ); ?></option>
                <option value="0" <?php selected( $admin->get_option( 'cupones_mostrar_carrito', '1' ), '0' ); ?>><?php _e( 'No — Ocultar', 'churrascoplanet-core' ); ?></option>
            </select>
        </div>
        <div class="option-field">
            <label><?php _e( 'Mostrar campo de cupón en el checkout', 'churrascoplanet-core' ); ?></label>
            <select name="<?php echo $option_name; ?>[cupones_mostrar_checkout]">
                <option value="1" <?php selected( $admin->get_option( 'cupones_mostrar_checkout', '1' ), '1' ); ?>><?php _e( 'Sí — Mostrar', 'churrascoplanet-core' ); ?></option>
                <option value="0" <?php selected( $admin->get_option( 'cupones_mostrar_checkout', '1' ), '0' ); ?>><?php _e( 'No — Ocultar', 'churrascoplanet-core' ); ?></option>
            </select>
        </div>
        <div class="option-field">
            <label><?php _e( 'Placeholder del campo', 'churrascoplanet-core' ); ?></label>
            <input type="text" name="<?php echo $option_name; ?>[cupones_placeholder]"
                   value="<?php echo esc_attr( $admin->get_option( 'cupones_placeholder', 'Código de cupón' ) ); ?>"
                   class="regular-text">
        </div>
        <div class="option-field">
            <label><?php _e( 'Texto del botón Aplicar', 'churrascoplanet-core' ); ?></label>
            <input type="text" name="<?php echo $option_name; ?>[cupones_btn_texto]"
                   value="<?php echo esc_attr( $admin->get_option( 'cupones_btn_texto', 'Aplicar cupón' ) ); ?>"
                   class="regular-text">
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════════ -->
<script>
(function($) {
    // Esperar a que jQuery y churrascoplanetAdmin estén listos
    $(document).ready(function() {

    var ajax   = churrascoplanetAdmin.ajaxUrl;
    var nonce  = churrascoplanetAdmin.nonce;
    var $modal = $('#chp-coupon-modal');

    // ── Abrir modal nuevo ────────────────────────────────
    $(document).on('click', '#chp-btn-new-coupon, #chp-btn-new-coupon-2', function(e) {
        e.preventDefault();
        chpOpenModal();
    });

    // ── Cerrar modal ─────────────────────────────────────
    $(document).on('click', '.chp-modal-close, .chp-modal-close-btn, .chp-modal-overlay', function() {
        chpCloseModal();
    });

    // ── Editar ───────────────────────────────────────────
    $(document).on('click', '.chp-btn-edit', function() {
        var id = $(this).data('id');
        chpLoadCoupon(id);
    });

    // ── Eliminar ─────────────────────────────────────────
    $(document).on('click', '.chp-btn-delete', function() {
        var id   = $(this).data('id');
        var code = $(this).data('code');
        if (!confirm('¿Eliminar el cupón ' + code + '? Esta acción no se puede deshacer.')) return;
        chpDeleteCoupon(id, $(this).closest('tr'));
    });

    // ── Guardar (crear o actualizar) ─────────────────────
    $('#chp-modal-save').on('click', function() {
        chpSaveCoupon();
    });

    // ── Generar código aleatorio ─────────────────────────
    $('#chp-gen-code').on('click', function() {
        var chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        var result = '';
        for (var i = 0; i < 8; i++) result += chars.charAt(Math.floor(Math.random() * chars.length));
        $('#chp-f-code').val(result);
    });

    // ── Actualizar label del monto según tipo ────────────
    $('#chp-f-type').on('change', function() {
        var labels = { percent: 'Descuento (%)', fixed_cart: 'Descuento ($)', fixed_product: 'Descuento ($)' };
        $('#chp-amount-label').text(labels[$(this).val()] || 'Descuento');
    });

    // Mover el modal al <body> para que position:fixed funcione
    // aunque el tab padre esté con display:none
    $('body').append($modal);

    // ─────────────────────────────────────────────────────
    function chpOpenModal(data) {
        $('#chp-modal-title').html('<i class="fas fa-ticket-alt"></i> ' + (data ? 'Editar Cupón' : 'Nuevo Cupón'));
        $('#chp-modal-save-label').text(data ? 'Guardar cambios' : 'Crear Cupón');
        $('#chp-modal-notice').hide().removeClass('chp-notice-error chp-notice-success').text('');

        $('#chp-coupon-id').val(data ? data.id : '');
        $('#chp-f-code').val(data ? data.code.toUpperCase() : '');
        $('#chp-f-desc').val(data ? data.description : '');
        $('#chp-f-type').val(data ? data.discount_type : 'percent').trigger('change');
        $('#chp-f-amount').val(data ? data.coupon_amount : '0');
        $('#chp-f-min').val(data ? data.minimum_amount : '');
        $('#chp-f-max').val(data ? data.maximum_amount : '');
        $('#chp-f-expires').val(data ? data.date_expires : '');
        $('#chp-f-limit').val(data ? data.usage_limit : '');
        $('#chp-f-limit-user').val(data ? data.usage_limit_per_user : '');
        $('#chp-f-individual').prop('checked', data ? data.individual_use === '1' : false);
        $('#chp-f-shipping').prop('checked', data ? data.free_shipping === '1' : false);
        $('#chp-f-status').val(data ? data.status : 'publish');

        $modal.fadeIn(200);
        setTimeout(function(){ $('#chp-f-code').focus(); }, 250);
    }

    function chpCloseModal() {
        $modal.fadeOut(200);
    }

    function chpLoadCoupon(id) {
        $.post(ajax, { action: 'chp_get_coupon', nonce: nonce, coupon_id: id }, function(res) {
            if (res.success) {
                chpOpenModal(res.data);
            } else {
                alert(res.data.message || 'Error al cargar el cupón.');
            }
        });
    }

    function chpSaveCoupon() {
        var id      = $('#chp-coupon-id').val();
        var action  = id ? 'chp_update_coupon' : 'chp_create_coupon';
        var $btn    = $('#chp-modal-save');
        var $notice = $('#chp-modal-notice');

        $btn.prop('disabled', true).find('i').removeClass('fa-save').addClass('fa-spinner fa-spin');
        $notice.hide();

        var payload = {
            action             : action,
            nonce              : nonce,
            coupon_id          : id,
            code               : $('#chp-f-code').val().trim(),
            description        : $('#chp-f-desc').val(),
            discount_type      : $('#chp-f-type').val(),
            coupon_amount      : $('#chp-f-amount').val(),
            minimum_amount     : $('#chp-f-min').val(),
            maximum_amount     : $('#chp-f-max').val(),
            date_expires       : $('#chp-f-expires').val(),
            usage_limit        : $('#chp-f-limit').val(),
            usage_limit_per_user: $('#chp-f-limit-user').val(),
            individual_use     : $('#chp-f-individual').is(':checked') ? '1' : '',
            free_shipping      : $('#chp-f-shipping').is(':checked') ? '1' : '',
            status             : $('#chp-f-status').val(),
        };

        $.post(ajax, payload, function(res) {
            $btn.prop('disabled', false).find('i').removeClass('fa-spinner fa-spin').addClass('fa-save');
            if (res.success) {
                chpCloseModal();
                chpApplyTableUpdate(res.data);
            } else {
                $notice.text(res.data.message || 'Error al guardar.').addClass('chp-notice-error').show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).find('i').removeClass('fa-spinner fa-spin').addClass('fa-save');
            $notice.text('Error de conexión. Intenta de nuevo.').addClass('chp-notice-error').show();
        });
    }

    function chpDeleteCoupon(id, $row) {
        $.post(ajax, { action: 'chp_delete_coupon', nonce: nonce, coupon_id: id }, function(res) {
            if (res.success) {
                chpApplyTableUpdate(res.data);
            } else {
                alert(res.data.message || 'Error al eliminar.');
            }
        });
    }

    // Aplica tbody y stats que vienen en la respuesta AJAX de cualquier operación
    function chpApplyTableUpdate(data) {
        if (!data || !data.tbody) return;
        $('#chp-coupons-tbody').html(data.tbody);
        if (data.stats) {
            $('#chp-stat-total').text(data.stats.total);
            $('#chp-stat-active').text(data.stats.activos);
            $('#chp-stat-expired').text(data.stats.expirados);
            $('#chp-stat-uses').text(data.stats.uso_total);
        }
        if ($('#chp-coupons-tbody tr').length > 0) {
            $('#chp-table-wrap').show();
            $('#chp-empty-state').hide();
        } else {
            $('#chp-table-wrap').hide();
            $('#chp-empty-state').show();
        }
    }

    // Copiar código al portapapeles
    window.chpCopyCode = function(el) {
        var code = el.textContent.trim();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(code).then(function() {
                el.style.background = '#d1fae5'; el.style.color = '#065f46';
                setTimeout(function(){ el.style.background = ''; el.style.color = ''; }, 1500);
            });
        }
    };

    }); // end document.ready
})(jQuery);
</script>

<!-- ══════════════════════════════════════════════════════
     ESTILOS
     ══════════════════════════════════════════════════════ -->
<style>
/* ── Stats ── */
.chp-cupones-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:15px; }
.chp-cupones-stats  { display:grid; grid-template-columns:repeat(4,1fr); gap:15px; }

.chp-stat-card  { display:flex; align-items:center; gap:14px; background:#fff; border:1px solid #e0e0e0; border-radius:10px; padding:16px 20px; }
.chp-stat-icon  { width:46px; height:46px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.chp-stat-info  { display:flex; flex-direction:column; }
.chp-stat-number{ font-size:26px; font-weight:700; color:#1d2327; line-height:1; }
.chp-stat-label { font-size:12px; color:#646970; margin-top:5px; }

.chp-icon-total  { background:linear-gradient(135deg,#1a1a1a,#0a0a0a); border:1px solid rgba(255,178,104,.3); color:#ffb268; }
.chp-icon-active { background:linear-gradient(135deg,#d1fae5,#a7f3d0); color:#065f46; }
.chp-icon-expired{ background:linear-gradient(135deg,#fee2e2,#fecaca); color:#991b1b; }
.chp-icon-uso    { background:linear-gradient(135deg,#dbeafe,#bfdbfe); color:#1e40af; }

.chp-green { color:#065f46 !important; }
.chp-red   { color:#991b1b !important; }
.chp-blue  { color:#1e40af !important; }

/* ── Tabla ── */
.chp-table-wrap { overflow-x:auto; border-radius:8px; border:1px solid #e0e0e0; }
.chp-coupons-table { width:100%; border-collapse:collapse; font-size:13px; min-width:700px; }
.chp-coupons-table thead { background:#f0f0f1; }
.chp-coupons-table th { padding:10px 14px; text-align:left; font-weight:600; color:#1d2327; border-bottom:2px solid #c3c4c7; white-space:nowrap; }
.chp-coupons-table td { padding:10px 14px; border-bottom:1px solid #f0f0f1; color:#1d2327; vertical-align:middle; }
.chp-coupons-table tbody tr:last-child td { border-bottom:none; }
.chp-coupons-table tbody tr:hover td { background:#fafafa; }
.chp-row-expired td { opacity:.6; }
.chp-row-draft td   { opacity:.75; }

.chp-code-wrap { display:flex; align-items:center; gap:6px; }
.chp-coupon-code { font-family:'Courier New',monospace; font-size:13px; font-weight:700; background:#f0f0f1; padding:3px 10px; border-radius:4px; letter-spacing:1px; cursor:pointer; transition:background .2s; }
.chp-coupon-code:hover { background:#e0e0e0; }
.chp-copy-hint { color:#bbb; font-size:11px; }

.chp-tag { display:inline-block; font-size:10px; padding:2px 7px; border-radius:3px; margin-top:4px; font-weight:600; }
.chp-tag-shipping  { background:#dbeafe; color:#1e40af; }
.chp-tag-individual{ background:#fef3c7; color:#92400e; }

.chp-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; padding:4px 10px; border-radius:20px; white-space:nowrap; }
.chp-badge-active  { background:#d1fae5; color:#065f46; }
.chp-badge-expired { background:#fee2e2; color:#991b1b; }
.chp-badge-draft   { background:#f3f4f6; color:#374151; }

.chp-usage-bar  { height:4px; background:#e0e0e0; border-radius:2px; margin-top:5px; width:80px; }
.chp-usage-fill { height:100%; border-radius:2px; }

.chp-expiry-past { color:#991b1b; font-weight:600; }
.chp-expiry-soon { color:#b45309; font-weight:600; }

.chp-actions-cell { white-space:nowrap; }
.chp-actions-cell .button { margin-right:4px; }
.chp-btn-delete { color:#dc3232 !important; border-color:#dc3232 !important; }
.chp-btn-delete:hover { background:#dc3232 !important; color:#fff !important; }

/* ── Empty state ── */
.chp-empty-state { text-align:center; padding:50px 20px; color:#646970; }
.chp-empty-state i { font-size:48px; color:#ddd; display:block; margin-bottom:15px; }

/* ── Modal ── */
.chp-modal { position:fixed; inset:0; z-index:100000; display:flex; align-items:center; justify-content:center; }
.chp-modal-overlay { position:absolute; inset:0; background:rgba(0,0,0,.55); }
.chp-modal-box { position:relative; background:#fff; border-radius:12px; width:100%; max-width:680px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.3); margin:20px; overflow:hidden; }

.chp-modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 25px; border-bottom:1px solid #e0e0e0; background:linear-gradient(135deg,#1a1a1a,#0a0a0a); }
.chp-modal-header h2 { margin:0; font-size:18px; color:#fff; display:flex; align-items:center; gap:10px; }
.chp-modal-header h2 i { color:#ffb268; }
.chp-modal-close { background:none; border:none; color:rgba(255,255,255,.6); font-size:20px; cursor:pointer; padding:4px 8px; line-height:1; transition:color .2s; }
.chp-modal-close:hover { color:#fff; }

.chp-modal-body { padding:25px; overflow-y:auto; flex:1; }
.chp-modal-footer { padding:18px 25px; border-top:1px solid #e0e0e0; display:flex; justify-content:flex-end; gap:10px; background:#f9f9f9; }

/* ── Formulario del modal ── */
.chp-form-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:18px; }
.chp-field-full { grid-column:1/-1; }
.chp-form-field { display:flex; flex-direction:column; gap:6px; }
.chp-form-field label { font-weight:600; font-size:13px; color:#1d2327; }
.chp-form-field input, .chp-form-field select { width:100%; max-width:100%; }
.chp-form-field .description { font-size:11px; color:#646970; margin:0; font-style:italic; }
.chp-required { color:#dc3232; }

.chp-code-input-wrap { display:flex; gap:8px; }
.chp-code-input-wrap input { flex:1; text-transform:uppercase; font-family:'Courier New',monospace; font-size:15px; font-weight:700; letter-spacing:2px; }

.chp-checkboxes { display:flex; flex-direction:column; gap:8px; }
.chp-checkbox-label { display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer; }
.chp-checkbox-label input { margin:0; width:auto; }

.chp-modal-notice { padding:10px 14px; border-radius:6px; font-size:13px; margin-top:15px; }
.chp-notice-error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.chp-notice-success { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }

@media (max-width:1100px) { .chp-cupones-stats { grid-template-columns:repeat(2,1fr); } }
@media (max-width:700px) {
    .chp-cupones-stats { grid-template-columns:1fr; }
    .chp-form-grid { grid-template-columns:1fr; }
    .chp-field-full { grid-column:1; }
}
</style>
