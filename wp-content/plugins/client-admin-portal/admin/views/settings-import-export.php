<?php
/**
 * Import/Export Settings Tab
 *
 * @package Client_Admin_Portal
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$db = new CAP_Database();
$stats = $db->get_stats();
?>

<div class="cap-import-export-settings">
    <!-- Export Section -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Exportar Configuración', 'client-admin-portal' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Exporta toda la configuración del portal a un archivo JSON para backup o migración.', 'client-admin-portal' ); ?>
            </p>
        </div>

        <div class="cap-export-info">
            <h3><?php esc_html_e( 'La exportación incluye:', 'client-admin-portal' ); ?></h3>
            <ul>
                <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Configuración de branding (global y por rol)', 'client-admin-portal' ); ?></li>
                <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Módulos registrados', 'client-admin-portal' ); ?></li>
                <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Matriz de acceso por rol', 'client-admin-portal' ); ?></li>
                <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Configuración general', 'client-admin-portal' ); ?></li>
            </ul>

            <p class="cap-export-note">
                <span class="dashicons dashicons-info"></span>
                <?php esc_html_e( 'El registro de auditoría NO se incluye en la exportación.', 'client-admin-portal' ); ?>
            </p>
        </div>

        <div class="cap-export-actions">
            <button type="button" class="button button-primary button-hero" id="cap-export-settings">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e( 'Exportar Configuración', 'client-admin-portal' ); ?>
            </button>
        </div>
    </div>

    <!-- Import Section -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Importar Configuración', 'client-admin-portal' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Importa configuración desde un archivo JSON previamente exportado.', 'client-admin-portal' ); ?>
            </p>
        </div>

        <?php settings_errors( 'cap_import' ); ?>

        <form method="post" enctype="multipart/form-data" class="cap-import-form">
            <?php wp_nonce_field( 'cap_import_settings', 'cap_import_nonce' ); ?>

            <div class="cap-form-field">
                <label for="cap-import-file"><?php esc_html_e( 'Archivo de configuración:', 'client-admin-portal' ); ?></label>
                <input type="file" id="cap-import-file" name="cap_import_file" accept=".json" required>
                <p class="description"><?php esc_html_e( 'Selecciona un archivo .json exportado anteriormente.', 'client-admin-portal' ); ?></p>
            </div>

            <div class="cap-form-field">
                <label class="cap-checkbox-label">
                    <input type="checkbox" name="cap_import_replace" value="1">
                    <?php esc_html_e( 'Reemplazar configuración existente', 'client-admin-portal' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Si está marcado, la configuración importada reemplazará los valores existentes. Si no, solo se importarán valores que no existan.', 'client-admin-portal' ); ?>
                </p>
            </div>

            <div class="cap-import-warning">
                <span class="dashicons dashicons-warning"></span>
                <p>
                    <strong><?php esc_html_e( 'Advertencia:', 'client-admin-portal' ); ?></strong>
                    <?php esc_html_e( 'Importar con la opción "Reemplazar" sobrescribirá toda tu configuración actual. Se recomienda hacer una exportación de backup antes de importar.', 'client-admin-portal' ); ?>
                </p>
            </div>

            <div class="cap-form-actions">
                <button type="submit" name="cap_import_settings" class="button button-primary">
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e( 'Importar Configuración', 'client-admin-portal' ); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Database Stats -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e( 'Información del Sistema', 'client-admin-portal' ); ?></h2>
        </div>

        <table class="cap-stats-table">
            <tbody>
                <tr>
                    <th><?php esc_html_e( 'Versión del Plugin:', 'client-admin-portal' ); ?></th>
                    <td><?php echo esc_html( CAP_VERSION ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Versión del Schema:', 'client-admin-portal' ); ?></th>
                    <td><?php echo esc_html( $stats['schema_version'] ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Configuraciones guardadas:', 'client-admin-portal' ); ?></th>
                    <td><?php echo number_format_i18n( $stats['settings_count'] ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Módulos registrados:', 'client-admin-portal' ); ?></th>
                    <td><?php echo number_format_i18n( $stats['modules_count'] ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Registros de auditoría:', 'client-admin-portal' ); ?></th>
                    <td><?php echo number_format_i18n( $stats['audit_log_count'] ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'URL del sitio:', 'client-admin-portal' ); ?></th>
                    <td><code><?php echo esc_html( get_site_url() ); ?></code></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Reset Section -->
    <div class="cap-section cap-danger-zone">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Zona de Peligro', 'client-admin-portal' ); ?></h2>
        </div>

        <div class="cap-danger-content">
            <p><?php esc_html_e( 'Las siguientes acciones son irreversibles. Procede con precaución.', 'client-admin-portal' ); ?></p>

            <div class="cap-danger-actions">
                <button type="button" class="button" id="cap-reset-branding">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php esc_html_e( 'Restablecer Branding', 'client-admin-portal' ); ?>
                </button>
                <span class="cap-action-description">
                    <?php esc_html_e( 'Vuelve a los colores predeterminados del tema.', 'client-admin-portal' ); ?>
                </span>
            </div>

            <div class="cap-danger-actions">
                <button type="button" class="button" id="cap-clear-audit-log">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e( 'Limpiar Registro de Auditoría', 'client-admin-portal' ); ?>
                </button>
                <span class="cap-action-description">
                    <?php esc_html_e( 'Elimina todos los registros de auditoría.', 'client-admin-portal' ); ?>
                </span>
            </div>
        </div>
    </div>
</div>
