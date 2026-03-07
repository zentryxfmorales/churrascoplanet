<?php
/**
 * Branding Settings Tab
 *
 * @package Client_Admin_Portal
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$branding = cap_branding();
$settings = cap_settings();
$access   = cap_access();

// Get global branding config.
$global_config = $settings->get_group( 'branding', null );
$global_config = wp_parse_args( $global_config, $branding->get_defaults() );

// Get available presets.
$presets = $branding->get_presets();

// Get roles for per-role branding.
$roles = $access->get_available_roles( false );

// Get roles that have custom branding.
$roles_with_branding = $settings->get_roles_with_settings( 'branding' );
?>

<div class="cap-branding-settings">
    <!-- Role Selector -->
    <div class="cap-section cap-role-selector">
        <h2><?php esc_html_e( 'Configurar Branding para:', 'client-admin-portal' ); ?></h2>
        <div class="cap-role-tabs">
            <button type="button" class="cap-role-tab active" data-role="">
                <span class="dashicons dashicons-admin-site"></span>
                <?php esc_html_e( 'Global (Predeterminado)', 'client-admin-portal' ); ?>
            </button>
            <?php foreach ( $roles as $role_slug => $role_name ) : ?>
                <button type="button" class="cap-role-tab" data-role="<?php echo esc_attr( $role_slug ); ?>">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php echo esc_html( $role_name ); ?>
                    <?php if ( in_array( $role_slug, $roles_with_branding, true ) ) : ?>
                        <span class="cap-custom-badge" title="<?php esc_attr_e( 'Tiene configuración personalizada', 'client-admin-portal' ); ?>">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Branding Form -->
    <form id="cap-branding-form" class="cap-form">
        <input type="hidden" name="role_slug" id="cap-branding-role" value="">

        <!-- Theme Preset -->
        <div class="cap-section">
            <div class="cap-section-header">
                <h2><span class="dashicons dashicons-art"></span> <?php esc_html_e( 'Tema Base', 'client-admin-portal' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Selecciona un tema predefinido o personaliza los colores.', 'client-admin-portal' ); ?></p>
            </div>

            <div class="cap-preset-grid">
                <?php foreach ( $presets as $preset_id => $preset ) : ?>
                    <label class="cap-preset-card <?php echo $global_config['theme_preset'] === $preset_id ? 'selected' : ''; ?>">
                        <input type="radio" name="settings[theme_preset]" value="<?php echo esc_attr( $preset_id ); ?>"
                               <?php checked( $global_config['theme_preset'], $preset_id ); ?>>
                        <div class="cap-preset-preview" data-preset="<?php echo esc_attr( $preset_id ); ?>">
                            <?php if ( 'custom' !== $preset_id ) : ?>
                                <div class="cap-preset-colors">
                                    <span style="background-color: <?php echo esc_attr( $preset['bg_main'] ); ?>"></span>
                                    <span style="background-color: <?php echo esc_attr( $preset['bg_card'] ); ?>"></span>
                                    <span style="background-color: <?php echo esc_attr( $preset['accent'] ); ?>"></span>
                                    <span style="background-color: <?php echo esc_attr( $preset['text_main'] ); ?>"></span>
                                </div>
                            <?php else : ?>
                                <div class="cap-preset-colors cap-custom-colors">
                                    <span class="dashicons dashicons-admin-customizer"></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <span class="cap-preset-name"><?php echo esc_html( $preset['name'] ); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Custom Colors (shown when custom preset is selected) -->
        <div class="cap-section cap-custom-colors-section" style="display: <?php echo $global_config['theme_preset'] === 'custom' ? 'block' : 'none'; ?>;">
            <div class="cap-section-header">
                <h2><span class="dashicons dashicons-color-picker"></span> <?php esc_html_e( 'Colores Personalizados', 'client-admin-portal' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Personaliza cada color del tema.', 'client-admin-portal' ); ?></p>
            </div>

            <div class="cap-color-grid">
                <!-- Background Colors -->
                <div class="cap-color-group">
                    <h3><?php esc_html_e( 'Fondos', 'client-admin-portal' ); ?></h3>

                    <div class="cap-color-field">
                        <label for="cap-bg-main"><?php esc_html_e( 'Fondo Principal', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-bg-main" name="settings[bg_main]"
                               value="<?php echo esc_attr( $global_config['bg_main'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-bg-card"><?php esc_html_e( 'Fondo de Cards', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-bg-card" name="settings[bg_card]"
                               value="<?php echo esc_attr( $global_config['bg_card'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-bg-input"><?php esc_html_e( 'Fondo de Inputs', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-bg-input" name="settings[bg_input]"
                               value="<?php echo esc_attr( $global_config['bg_input'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-bg-hover"><?php esc_html_e( 'Fondo Hover', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-bg-hover" name="settings[bg_hover]"
                               value="<?php echo esc_attr( $global_config['bg_hover'] ); ?>" class="cap-color-picker">
                    </div>
                </div>

                <!-- Text Colors -->
                <div class="cap-color-group">
                    <h3><?php esc_html_e( 'Texto', 'client-admin-portal' ); ?></h3>

                    <div class="cap-color-field">
                        <label for="cap-text-main"><?php esc_html_e( 'Texto Principal', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-text-main" name="settings[text_main]"
                               value="<?php echo esc_attr( $global_config['text_main'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-text-muted"><?php esc_html_e( 'Texto Secundario', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-text-muted" name="settings[text_muted]"
                               value="<?php echo esc_attr( $global_config['text_muted'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-text-faint"><?php esc_html_e( 'Texto Terciario', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-text-faint" name="settings[text_faint]"
                               value="<?php echo esc_attr( $global_config['text_faint'] ); ?>" class="cap-color-picker">
                    </div>
                </div>

                <!-- Accent & Borders -->
                <div class="cap-color-group">
                    <h3><?php esc_html_e( 'Acento y Bordes', 'client-admin-portal' ); ?></h3>

                    <div class="cap-color-field">
                        <label for="cap-accent"><?php esc_html_e( 'Color de Acento', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-accent" name="settings[accent]"
                               value="<?php echo esc_attr( $global_config['accent'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-accent-hover"><?php esc_html_e( 'Acento Hover', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-accent-hover" name="settings[accent_hover]"
                               value="<?php echo esc_attr( $global_config['accent_hover'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-border"><?php esc_html_e( 'Bordes', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-border" name="settings[border]"
                               value="<?php echo esc_attr( $global_config['border'] ); ?>" class="cap-color-picker">
                    </div>
                </div>

                <!-- Status Colors -->
                <div class="cap-color-group">
                    <h3><?php esc_html_e( 'Estados', 'client-admin-portal' ); ?></h3>

                    <div class="cap-color-field">
                        <label for="cap-success"><?php esc_html_e( 'Éxito', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-success" name="settings[success]"
                               value="<?php echo esc_attr( $global_config['success'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-warning"><?php esc_html_e( 'Advertencia', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-warning" name="settings[warning]"
                               value="<?php echo esc_attr( $global_config['warning'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-error"><?php esc_html_e( 'Error', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-error" name="settings[error]"
                               value="<?php echo esc_attr( $global_config['error'] ); ?>" class="cap-color-picker">
                    </div>

                    <div class="cap-color-field">
                        <label for="cap-info"><?php esc_html_e( 'Información', 'client-admin-portal' ); ?></label>
                        <input type="text" id="cap-info" name="settings[info]"
                               value="<?php echo esc_attr( $global_config['info'] ); ?>" class="cap-color-picker">
                    </div>
                </div>
            </div>
        </div>

        <!-- Logo & Brand -->
        <div class="cap-section">
            <div class="cap-section-header">
                <h2><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Logo y Marca', 'client-admin-portal' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Personaliza el logo y nombre de tu portal.', 'client-admin-portal' ); ?></p>
            </div>

            <div class="cap-form-grid">
                <div class="cap-form-field cap-logo-field">
                    <label for="cap-logo-url"><?php esc_html_e( 'Logo', 'client-admin-portal' ); ?></label>
                    <div class="cap-logo-preview">
                        <?php if ( ! empty( $global_config['logo_url'] ) ) : ?>
                            <img src="<?php echo esc_url( $global_config['logo_url'] ); ?>" alt="Logo">
                        <?php else : ?>
                            <span class="cap-no-logo"><?php esc_html_e( 'Sin logo', 'client-admin-portal' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" id="cap-logo-url" name="settings[logo_url]"
                           value="<?php echo esc_url( $global_config['logo_url'] ); ?>">
                    <button type="button" class="button cap-upload-logo">
                        <span class="dashicons dashicons-upload"></span>
                        <?php esc_html_e( 'Subir Logo', 'client-admin-portal' ); ?>
                    </button>
                    <?php if ( ! empty( $global_config['logo_url'] ) ) : ?>
                        <button type="button" class="button cap-remove-logo">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="cap-form-field">
                    <label for="cap-logo-height"><?php esc_html_e( 'Altura del Logo (px)', 'client-admin-portal' ); ?></label>
                    <input type="number" id="cap-logo-height" name="settings[logo_height]"
                           value="<?php echo esc_attr( $global_config['logo_height'] ); ?>" min="20" max="200">
                </div>

                <div class="cap-form-field">
                    <label for="cap-brand-name"><?php esc_html_e( 'Nombre de Marca', 'client-admin-portal' ); ?></label>
                    <input type="text" id="cap-brand-name" name="settings[brand_name]"
                           value="<?php echo esc_attr( $global_config['brand_name'] ); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e( 'Aparece en el pie de página del admin.', 'client-admin-portal' ); ?></p>
                </div>

                <div class="cap-form-field cap-full-width">
                    <label for="cap-admin-footer"><?php esc_html_e( 'Texto de Pie de Página', 'client-admin-portal' ); ?></label>
                    <input type="text" id="cap-admin-footer" name="settings[admin_footer]"
                           value="<?php echo esc_attr( $global_config['admin_footer'] ); ?>" class="large-text">
                    <p class="description"><?php esc_html_e( 'Deja vacío para usar el nombre de marca con el año actual.', 'client-admin-portal' ); ?></p>
                </div>
            </div>
        </div>

        <!-- Custom CSS -->
        <div class="cap-section">
            <div class="cap-section-header">
                <h2><span class="dashicons dashicons-editor-code"></span> <?php esc_html_e( 'CSS Personalizado', 'client-admin-portal' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Agrega CSS adicional para ajustes finos.', 'client-admin-portal' ); ?></p>
            </div>

            <div class="cap-form-field">
                <textarea id="cap-custom-css" name="settings[custom_css]" rows="10"
                          class="large-text code"><?php echo esc_textarea( $global_config['custom_css'] ); ?></textarea>
            </div>
        </div>

        <!-- Submit -->
        <div class="cap-form-actions">
            <button type="submit" class="button button-primary button-hero">
                <span class="dashicons dashicons-yes"></span>
                <?php esc_html_e( 'Guardar Cambios', 'client-admin-portal' ); ?>
            </button>
            <span class="cap-save-status"></span>
        </div>
    </form>
</div>
