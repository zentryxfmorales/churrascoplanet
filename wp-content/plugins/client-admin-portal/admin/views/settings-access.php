<?php
/**
 * Access Control Settings Tab
 *
 * @package Client_Admin_Portal
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = cap_settings();
$access   = cap_access();
$modules  = cap_modules();

// Get available roles.
$roles = $access->get_available_roles( false );

// Get all active modules.
$all_modules = $modules->get_all( true );

// Get access matrix.
$access_matrix = $modules->get_access_matrix();

// HPOS-aware orders slug (computed once, same for all roles).
$orders_slug = cap_wc_orders_menu_slug();

// Preset slug list — used to detect custom (non-preset) saved redirects.
$preset_redirect_slugs = array(
    '',
    'index.php',
    'edit.php?post_type=shop_order',
    'admin.php?page=wc-orders',
    'edit.php?post_type=product',
    'edit.php',
    'profile.php',
);
?>

<div class="cap-access-settings">
    <!-- Role Configuration -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'Configuración por Rol', 'client-admin-portal' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Configura la redirección después del login y otras opciones para cada rol.', 'client-admin-portal' ); ?>
            </p>
        </div>

        <div class="cap-roles-list">
            <?php foreach ( $roles as $role_slug => $role_name ) :
                $login_redirect = $settings->get( 'access', 'login_redirect', '', $role_slug );
                $role_modules   = $modules->get_for_role( $role_slug );

                // Detect whether the saved value is a custom (non-preset) URL.
                $is_custom    = ! empty( $login_redirect ) && ! in_array( $login_redirect, $preset_redirect_slugs, true );
                $select_value = $is_custom ? 'custom' : $login_redirect;
                $custom_value = $is_custom ? $login_redirect : '';

                // Orders option: mark selected for both HPOS and legacy slugs.
                $orders_selected = ! $is_custom && in_array( $login_redirect, array( 'edit.php?post_type=shop_order', 'admin.php?page=wc-orders' ), true );
            ?>
                <div class="cap-role-card" data-role="<?php echo esc_attr( $role_slug ); ?>">
                    <div class="cap-role-header">
                        <span class="dashicons dashicons-admin-users"></span>
                        <h3><?php echo esc_html( $role_name ); ?></h3>
                        <span class="cap-role-slug"><?php echo esc_html( $role_slug ); ?></span>
                    </div>

                    <div class="cap-role-settings">
                        <div class="cap-form-field">
                            <label for="cap-redirect-<?php echo esc_attr( $role_slug ); ?>">
                                <?php esc_html_e( 'Redirección post-login:', 'client-admin-portal' ); ?>
                            </label>
                            <select id="cap-redirect-<?php echo esc_attr( $role_slug ); ?>"
                                    class="cap-login-redirect"
                                    data-role="<?php echo esc_attr( $role_slug ); ?>">
                                <option value="" <?php selected( $select_value, '' ); ?>><?php esc_html_e( '— Predeterminado —', 'client-admin-portal' ); ?></option>
                                <option value="index.php" <?php selected( $select_value, 'index.php' ); ?>>
                                    <?php esc_html_e( 'Dashboard', 'client-admin-portal' ); ?>
                                </option>
                                <option value="<?php echo esc_attr( $orders_slug ); ?>" <?php echo $orders_selected ? 'selected' : ''; ?>>
                                    <?php esc_html_e( 'Pedidos (WooCommerce)', 'client-admin-portal' ); ?>
                                </option>
                                <option value="edit.php?post_type=product" <?php selected( $select_value, 'edit.php?post_type=product' ); ?>>
                                    <?php esc_html_e( 'Productos (WooCommerce)', 'client-admin-portal' ); ?>
                                </option>
                                <option value="edit.php" <?php selected( $select_value, 'edit.php' ); ?>>
                                    <?php esc_html_e( 'Entradas', 'client-admin-portal' ); ?>
                                </option>
                                <option value="profile.php" <?php selected( $select_value, 'profile.php' ); ?>>
                                    <?php esc_html_e( 'Perfil', 'client-admin-portal' ); ?>
                                </option>
                                <option value="custom" <?php selected( $select_value, 'custom' ); ?>>
                                    <?php esc_html_e( 'URL personalizada…', 'client-admin-portal' ); ?>
                                </option>
                            </select>
                            <span class="cap-redirect-status"></span>

                            <div class="cap-custom-redirect-wrap" <?php echo $is_custom ? '' : 'style="display:none"'; ?>>
                                <input type="text"
                                       class="cap-custom-redirect-input regular-text"
                                       data-role="<?php echo esc_attr( $role_slug ); ?>"
                                       placeholder="admin.php?page=mi-plugin"
                                       value="<?php echo esc_attr( $custom_value ); ?>">
                                <p class="description">
                                    <?php esc_html_e( 'Ruta relativa al admin de WordPress (ej: admin.php?page=wc-orders)', 'client-admin-portal' ); ?>
                                </p>
                            </div>
                        </div>

                        <div class="cap-role-modules-summary">
                            <strong><?php esc_html_e( 'Módulos accesibles:', 'client-admin-portal' ); ?></strong>
                            <?php if ( empty( $role_modules ) ) : ?>
                                <span class="cap-no-modules"><?php esc_html_e( 'Ninguno', 'client-admin-portal' ); ?></span>
                            <?php else : ?>
                                <span class="cap-modules-count">
                                    <?php echo count( $role_modules ); ?>
                                    <?php esc_html_e( 'módulos', 'client-admin-portal' ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Access Matrix -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Matriz de Acceso', 'client-admin-portal' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Vista rápida de qué roles tienen acceso a qué módulos.', 'client-admin-portal' ); ?>
            </p>
        </div>

        <?php if ( empty( $all_modules ) ) : ?>
            <div class="cap-empty-state">
                <span class="dashicons dashicons-info"></span>
                <p><?php esc_html_e( 'No hay módulos activos para mostrar.', 'client-admin-portal' ); ?></p>
            </div>
        <?php else : ?>
            <div class="cap-matrix-wrapper">
                <table class="cap-access-matrix">
                    <thead>
                        <tr>
                            <th class="cap-matrix-module-col"><?php esc_html_e( 'Módulo', 'client-admin-portal' ); ?></th>
                            <?php foreach ( $roles as $role_slug => $role_name ) : ?>
                                <th class="cap-matrix-role-col">
                                    <span class="cap-role-name"><?php echo esc_html( $role_name ); ?></span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $all_modules as $module ) :
                            $module_access = $access_matrix[ $module['module_slug'] ] ?? array();
                        ?>
                            <tr>
                                <td class="cap-matrix-module-name">
                                    <span class="dashicons <?php echo esc_attr( $module['icon'] ); ?>"></span>
                                    <?php echo esc_html( $module['module_name'] ); ?>
                                </td>
                                <?php foreach ( $roles as $role_slug => $role_name ) :
                                    $has_access = $module_access[ $role_slug ] ?? false;
                                ?>
                                    <td class="cap-matrix-cell <?php echo $has_access ? 'has-access' : 'no-access'; ?>">
                                        <?php if ( $has_access ) : ?>
                                            <span class="dashicons dashicons-yes-alt" title="<?php esc_attr_e( 'Tiene acceso', 'client-admin-portal' ); ?>"></span>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-minus" title="<?php esc_attr_e( 'Sin acceso', 'client-admin-portal' ); ?>"></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- General Access Settings -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Opciones Generales', 'client-admin-portal' ); ?></h2>
        </div>

        <form id="cap-access-settings-form" class="cap-form">
            <div class="cap-form-field">
                <label>
                    <input type="checkbox" name="apply_branding_to_admin" id="cap-apply-branding-admin"
                           <?php checked( $settings->get( 'general', 'apply_branding_to_admin', false ) ); ?>>
                    <?php esc_html_e( 'Aplicar branding también a administradores', 'client-admin-portal' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Por defecto, el tema personalizado solo se aplica a usuarios no administradores.', 'client-admin-portal' ); ?>
                </p>
            </div>

            <div class="cap-form-actions">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Guardar Opciones', 'client-admin-portal' ); ?>
                </button>
            </div>
        </form>
    </div>
</div>
