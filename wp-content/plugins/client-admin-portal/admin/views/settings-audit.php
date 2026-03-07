<?php
/**
 * Audit Log Settings Tab
 *
 * @package Client_Admin_Portal
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$audit    = cap_audit();
$settings = cap_settings();

// Get action types for filter.
$action_types = $audit->get_action_types();

// Get retention setting.
$retention_days = $settings->get( 'general', 'audit_retention_days', 90 );

// Get initial logs.
$initial_logs = $audit->query( array( 'limit' => 50 ) );
$total_logs   = $audit->count();
?>

<div class="cap-audit-settings">
    <!-- Filters -->
    <div class="cap-section cap-audit-filters">
        <div class="cap-filter-row">
            <div class="cap-filter-field">
                <label for="cap-audit-search"><?php esc_html_e( 'Buscar:', 'client-admin-portal' ); ?></label>
                <input type="text" id="cap-audit-search" placeholder="<?php esc_attr_e( 'Usuario, target...', 'client-admin-portal' ); ?>">
            </div>

            <div class="cap-filter-field">
                <label for="cap-audit-action"><?php esc_html_e( 'Acción:', 'client-admin-portal' ); ?></label>
                <select id="cap-audit-action">
                    <option value=""><?php esc_html_e( 'Todas', 'client-admin-portal' ); ?></option>
                    <?php foreach ( $action_types as $action_id => $action_label ) : ?>
                        <option value="<?php echo esc_attr( $action_id ); ?>">
                            <?php echo esc_html( $action_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cap-filter-field">
                <label for="cap-audit-date-from"><?php esc_html_e( 'Desde:', 'client-admin-portal' ); ?></label>
                <input type="date" id="cap-audit-date-from">
            </div>

            <div class="cap-filter-field">
                <label for="cap-audit-date-to"><?php esc_html_e( 'Hasta:', 'client-admin-portal' ); ?></label>
                <input type="date" id="cap-audit-date-to">
            </div>

            <div class="cap-filter-actions">
                <button type="button" class="button" id="cap-audit-filter">
                    <span class="dashicons dashicons-filter"></span>
                    <?php esc_html_e( 'Filtrar', 'client-admin-portal' ); ?>
                </button>
                <button type="button" class="button" id="cap-audit-reset">
                    <span class="dashicons dashicons-dismiss"></span>
                    <?php esc_html_e( 'Limpiar', 'client-admin-portal' ); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Audit Log Table -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2>
                <span class="dashicons dashicons-list-view"></span>
                <?php esc_html_e( 'Registro de Actividad', 'client-admin-portal' ); ?>
                <span class="cap-log-count" id="cap-audit-total">(<?php echo number_format_i18n( $total_logs ); ?>)</span>
            </h2>
            <div class="cap-section-actions">
                <button type="button" class="button" id="cap-export-audit">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e( 'Exportar CSV', 'client-admin-portal' ); ?>
                </button>
            </div>
        </div>

        <div class="cap-audit-table-wrapper">
            <table class="wp-list-table widefat fixed striped" id="cap-audit-table">
                <thead>
                    <tr>
                        <th class="column-date"><?php esc_html_e( 'Fecha', 'client-admin-portal' ); ?></th>
                        <th class="column-user"><?php esc_html_e( 'Usuario', 'client-admin-portal' ); ?></th>
                        <th class="column-action"><?php esc_html_e( 'Acción', 'client-admin-portal' ); ?></th>
                        <th class="column-target"><?php esc_html_e( 'Objetivo', 'client-admin-portal' ); ?></th>
                        <th class="column-changes"><?php esc_html_e( 'Cambios', 'client-admin-portal' ); ?></th>
                        <th class="column-ip"><?php esc_html_e( 'IP', 'client-admin-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody id="cap-audit-body">
                    <?php if ( empty( $initial_logs ) ) : ?>
                        <tr class="no-items">
                            <td colspan="6"><?php esc_html_e( 'No hay registros de actividad.', 'client-admin-portal' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $initial_logs as $log ) : ?>
                            <tr>
                                <td class="column-date">
                                    <span class="cap-log-date">
                                        <?php echo esc_html( wp_date( 'd/m/Y', strtotime( $log['created_at'] ) ) ); ?>
                                    </span>
                                    <span class="cap-log-time">
                                        <?php echo esc_html( wp_date( 'H:i:s', strtotime( $log['created_at'] ) ) ); ?>
                                    </span>
                                </td>
                                <td class="column-user">
                                    <?php if ( $log['user_id'] > 0 ) : ?>
                                        <a href="<?php echo esc_url( get_edit_user_link( $log['user_id'] ) ); ?>">
                                            <?php echo esc_html( $log['user_login'] ); ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="cap-guest"><?php echo esc_html( $log['user_login'] ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-action">
                                    <span class="cap-action-badge cap-action-<?php echo esc_attr( $log['action'] ); ?>">
                                        <?php echo esc_html( $action_types[ $log['action'] ] ?? $log['action'] ); ?>
                                    </span>
                                </td>
                                <td class="column-target">
                                    <?php if ( ! empty( $log['target'] ) ) : ?>
                                        <code><?php echo esc_html( $log['target'] ); ?></code>
                                    <?php else : ?>
                                        <span class="cap-na">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-changes">
                                    <?php if ( ! empty( $log['old_value'] ) || ! empty( $log['new_value'] ) ) : ?>
                                        <button type="button" class="button button-small cap-view-changes"
                                                data-old="<?php echo esc_attr( $log['old_value'] ); ?>"
                                                data-new="<?php echo esc_attr( $log['new_value'] ); ?>">
                                            <span class="dashicons dashicons-visibility"></span>
                                        </button>
                                    <?php else : ?>
                                        <span class="cap-na">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-ip">
                                    <span class="cap-ip" title="<?php echo esc_attr( $log['user_agent'] ); ?>">
                                        <?php echo esc_html( $log['ip_address'] ); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="cap-audit-pagination">
            <button type="button" class="button" id="cap-audit-load-more"
                    data-offset="50"
                    <?php disabled( count( $initial_logs ) < 50 ); ?>>
                <?php esc_html_e( 'Cargar más', 'client-admin-portal' ); ?>
            </button>
        </div>
    </div>

    <!-- Retention Settings -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Configuración de Retención', 'client-admin-portal' ); ?></h2>
        </div>

        <form id="cap-audit-settings-form" class="cap-form">
            <div class="cap-form-field">
                <label for="cap-audit-retention"><?php esc_html_e( 'Retención de registros:', 'client-admin-portal' ); ?></label>
                <select id="cap-audit-retention" name="audit_retention_days">
                    <option value="30" <?php selected( $retention_days, 30 ); ?>><?php esc_html_e( '30 días', 'client-admin-portal' ); ?></option>
                    <option value="60" <?php selected( $retention_days, 60 ); ?>><?php esc_html_e( '60 días', 'client-admin-portal' ); ?></option>
                    <option value="90" <?php selected( $retention_days, 90 ); ?>><?php esc_html_e( '90 días', 'client-admin-portal' ); ?></option>
                    <option value="180" <?php selected( $retention_days, 180 ); ?>><?php esc_html_e( '180 días', 'client-admin-portal' ); ?></option>
                    <option value="365" <?php selected( $retention_days, 365 ); ?>><?php esc_html_e( '1 año', 'client-admin-portal' ); ?></option>
                </select>
                <p class="description">
                    <?php esc_html_e( 'Los registros más antiguos se eliminan automáticamente cada día.', 'client-admin-portal' ); ?>
                </p>
            </div>

            <div class="cap-form-actions">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Guardar', 'client-admin-portal' ); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Changes Modal -->
<div id="cap-changes-modal" class="cap-modal" style="display: none;">
    <div class="cap-modal-content">
        <span class="cap-modal-close">&times;</span>
        <h2><?php esc_html_e( 'Detalles del Cambio', 'client-admin-portal' ); ?></h2>
        <div class="cap-changes-comparison">
            <div class="cap-change-old">
                <h4><?php esc_html_e( 'Valor Anterior', 'client-admin-portal' ); ?></h4>
                <pre id="cap-change-old-value"></pre>
            </div>
            <div class="cap-change-arrow">
                <span class="dashicons dashicons-arrow-right-alt"></span>
            </div>
            <div class="cap-change-new">
                <h4><?php esc_html_e( 'Valor Nuevo', 'client-admin-portal' ); ?></h4>
                <pre id="cap-change-new-value"></pre>
            </div>
        </div>
    </div>
</div>
