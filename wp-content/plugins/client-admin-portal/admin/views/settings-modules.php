<?php
/**
 * Modules Settings Tab
 *
 * @package Client_Admin_Portal
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$modules = cap_modules();
$access  = cap_access();

// Get all modules.
$all_modules = $modules->get_all();

// Get available roles.
$roles = $access->get_available_roles( true );

// Get access matrix.
$access_matrix = $modules->get_access_matrix();

// Group modules by plugin source.
$grouped_modules = array();
foreach ( $all_modules as $module ) {
    $source = $module['plugin_source'];
    if ( ! isset( $grouped_modules[ $source ] ) ) {
        $grouped_modules[ $source ] = array();
    }
    $grouped_modules[ $source ][] = $module;
}

// Common capabilities for dropdown.
$capabilities = array(
    'read'               => __( 'Read (todos los usuarios)', 'client-admin-portal' ),
    'edit_posts'         => __( 'Edit Posts', 'client-admin-portal' ),
    'publish_posts'      => __( 'Publish Posts', 'client-admin-portal' ),
    'edit_pages'         => __( 'Edit Pages', 'client-admin-portal' ),
    'manage_categories'  => __( 'Manage Categories', 'client-admin-portal' ),
    'moderate_comments'  => __( 'Moderate Comments', 'client-admin-portal' ),
    'manage_woocommerce' => __( 'Manage WooCommerce', 'client-admin-portal' ),
    'view_woocommerce_reports' => __( 'View WooCommerce Reports', 'client-admin-portal' ),
    'manage_options'     => __( 'Manage Options (admin)', 'client-admin-portal' ),
);
?>

<div class="cap-modules-settings">
    <!-- Registered Modules -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-screenoptions"></span> <?php esc_html_e( 'Módulos Registrados', 'client-admin-portal' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Activa o desactiva módulos y configura qué roles pueden acceder a cada uno.', 'client-admin-portal' ); ?>
            </p>
        </div>

        <?php if ( empty( $all_modules ) ) : ?>
            <div class="cap-empty-state">
                <span class="dashicons dashicons-info"></span>
                <p><?php esc_html_e( 'No hay módulos registrados. Agrega uno manualmente usando el formulario de abajo.', 'client-admin-portal' ); ?></p>
            </div>
        <?php else : ?>
            <div class="cap-modules-list" id="cap-modules-sortable">
                <?php foreach ( $grouped_modules as $source => $source_modules ) : ?>
                    <div class="cap-module-group">
                        <h3 class="cap-module-group-title">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <?php echo esc_html( $source ); ?>
                            <span class="cap-module-count"><?php echo count( $source_modules ); ?></span>
                        </h3>

                        <?php foreach ( $source_modules as $module ) :
                            $is_active = (bool) $module['is_active'];
                            $is_core   = (bool) $module['is_core'];
                            $module_access = $access_matrix[ $module['module_slug'] ] ?? array();
                        ?>
                            <div class="cap-module-card <?php echo $is_active ? 'is-active' : 'is-inactive'; ?> <?php echo $is_core ? 'is-core' : ''; ?>"
                                 data-module="<?php echo esc_attr( $module['module_slug'] ); ?>">

                                <div class="cap-module-header">
                                    <div class="cap-module-drag-handle">
                                        <span class="dashicons dashicons-menu"></span>
                                    </div>

                                    <div class="cap-module-icon">
                                        <span class="dashicons <?php echo esc_attr( $module['icon'] ); ?>"></span>
                                    </div>

                                    <div class="cap-module-info">
                                        <h4 class="cap-module-name"><?php echo esc_html( $module['module_name'] ); ?></h4>
                                        <p class="cap-module-description"><?php echo esc_html( $module['module_description'] ); ?></p>
                                        <?php if ( ! empty( $module['menu_slug'] ) ) : ?>
                                            <code class="cap-module-slug"><?php echo esc_html( $module['menu_slug'] ); ?></code>
                                        <?php endif; ?>
                                    </div>

                                    <div class="cap-module-actions">
                                        <?php if ( ! $is_core ) : ?>
                                            <button type="button" class="button button-small cap-edit-module"
                                                    data-module="<?php echo esc_attr( $module['module_slug'] ); ?>"
                                                    title="<?php esc_attr_e( 'Editar', 'client-admin-portal' ); ?>">
                                                <span class="dashicons dashicons-edit"></span>
                                            </button>
                                            <button type="button" class="button button-small cap-delete-module"
                                                    data-module="<?php echo esc_attr( $module['module_slug'] ); ?>"
                                                    title="<?php esc_attr_e( 'Eliminar', 'client-admin-portal' ); ?>">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <div class="cap-module-toggle">
                                        <?php if ( $is_core ) : ?>
                                            <span class="cap-core-badge" title="<?php esc_attr_e( 'Módulo del sistema', 'client-admin-portal' ); ?>">
                                                <span class="dashicons dashicons-lock"></span>
                                                <?php esc_html_e( 'Core', 'client-admin-portal' ); ?>
                                            </span>
                                        <?php else : ?>
                                            <label class="cap-switch">
                                                <input type="checkbox" class="cap-module-active-toggle"
                                                       data-module="<?php echo esc_attr( $module['module_slug'] ); ?>"
                                                       <?php checked( $is_active ); ?>>
                                                <span class="cap-switch-slider"></span>
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="cap-module-access">
                                    <h5><?php esc_html_e( 'Acceso por Rol:', 'client-admin-portal' ); ?></h5>
                                    <div class="cap-role-checkboxes">
                                        <?php foreach ( $roles as $role_slug => $role_name ) :
                                            $has_access = $module_access[ $role_slug ] ?? false;
                                            $is_admin   = 'administrator' === $role_slug;
                                        ?>
                                            <label class="cap-role-checkbox <?php echo $is_admin ? 'is-admin' : ''; ?>">
                                                <input type="checkbox" class="cap-module-role-toggle"
                                                       data-module="<?php echo esc_attr( $module['module_slug'] ); ?>"
                                                       data-role="<?php echo esc_attr( $role_slug ); ?>"
                                                       <?php checked( $has_access || $is_admin ); ?>
                                                       <?php disabled( $is_admin ); ?>>
                                                <span class="cap-checkbox-label"><?php echo esc_html( $role_name ); ?></span>
                                                <?php if ( $is_admin ) : ?>
                                                    <span class="cap-always-on" title="<?php esc_attr_e( 'Los administradores siempre tienen acceso', 'client-admin-portal' ); ?>">
                                                        <span class="dashicons dashicons-lock"></span>
                                                    </span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Manual Module Registration -->
    <div class="cap-section">
        <div class="cap-section-header">
            <h2><span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'Agregar Módulo Manualmente', 'client-admin-portal' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Registra un módulo personalizado para cualquier página del admin.', 'client-admin-portal' ); ?>
            </p>
        </div>

        <form id="cap-manual-module-form" class="cap-form">
            <div class="cap-form-row">
                <div class="cap-form-field">
                    <label for="cap-module-name"><?php esc_html_e( 'Nombre *', 'client-admin-portal' ); ?></label>
                    <input type="text" id="cap-module-name" name="name" required
                           placeholder="<?php esc_attr_e( 'Ej: Mi Módulo', 'client-admin-portal' ); ?>">
                </div>

                <div class="cap-form-field">
                    <label for="cap-module-menu-slug"><?php esc_html_e( 'URL del Menú *', 'client-admin-portal' ); ?></label>
                    <input type="text" id="cap-module-menu-slug" name="menu_slug" required
                           placeholder="<?php esc_attr_e( 'Ej: admin.php?page=mi-pagina', 'client-admin-portal' ); ?>">
                    <p class="description"><?php esc_html_e( 'La URL relativa de la página en el admin.', 'client-admin-portal' ); ?></p>
                </div>
            </div>

            <div class="cap-form-row">
                <div class="cap-form-field">
                    <label for="cap-module-description"><?php esc_html_e( 'Descripción', 'client-admin-portal' ); ?></label>
                    <textarea id="cap-module-description" name="description" rows="2"
                              placeholder="<?php esc_attr_e( 'Descripción breve del módulo...', 'client-admin-portal' ); ?>"></textarea>
                </div>
            </div>

            <div class="cap-form-row">
                <div class="cap-form-field">
                    <label for="cap-module-source"><?php esc_html_e( 'Fuente / Plugin', 'client-admin-portal' ); ?></label>
                    <input type="text" id="cap-module-source" name="plugin_source"
                           placeholder="<?php esc_attr_e( 'Ej: Mi Plugin', 'client-admin-portal' ); ?>"
                           value="<?php esc_attr_e( 'Manual', 'client-admin-portal' ); ?>">
                </div>

                <div class="cap-form-field">
                    <label for="cap-module-capability"><?php esc_html_e( 'Capacidad Requerida', 'client-admin-portal' ); ?></label>
                    <select id="cap-module-capability" name="capability">
                        <?php foreach ( $capabilities as $cap => $label ) : ?>
                            <option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $cap, 'manage_options' ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="cap-form-row">
                <div class="cap-form-field">
                    <label for="cap-module-icon"><?php esc_html_e( 'Icono (Dashicon)', 'client-admin-portal' ); ?></label>
                    <div class="cap-icon-selector">
                        <input type="text" id="cap-module-icon" name="icon" value="dashicons-admin-generic"
                               placeholder="dashicons-admin-generic">
                        <span class="cap-icon-preview"><span class="dashicons dashicons-admin-generic"></span></span>
                        <button type="button" class="button cap-pick-icon"><?php esc_html_e( 'Elegir', 'client-admin-portal' ); ?></button>
                    </div>
                    <p class="description">
                        <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank">
                            <?php esc_html_e( 'Ver todos los Dashicons', 'client-admin-portal' ); ?>
                        </a>
                    </p>
                </div>

                <div class="cap-form-field">
                    <label for="cap-module-order"><?php esc_html_e( 'Orden', 'client-admin-portal' ); ?></label>
                    <input type="number" id="cap-module-order" name="sort_order" value="50" min="0" max="999">
                </div>
            </div>

            <div class="cap-form-actions">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-plus"></span>
                    <?php esc_html_e( 'Agregar Módulo', 'client-admin-portal' ); ?>
                </button>
                <span class="cap-form-status"></span>
            </div>
        </form>
    </div>
</div>

<!-- Edit Module Modal -->
<div id="cap-edit-module-modal" class="cap-modal" style="display: none;">
    <div class="cap-modal-content">
        <span class="cap-modal-close">&times;</span>
        <h2><?php esc_html_e( 'Editar Módulo', 'client-admin-portal' ); ?></h2>

        <form id="cap-edit-module-form" class="cap-form">
            <input type="hidden" id="cap-edit-module-slug" name="slug">

            <div class="cap-form-field">
                <label for="cap-edit-module-name"><?php esc_html_e( 'Nombre', 'client-admin-portal' ); ?></label>
                <input type="text" id="cap-edit-module-name" name="name" required>
            </div>

            <div class="cap-form-field">
                <label for="cap-edit-module-description"><?php esc_html_e( 'Descripción', 'client-admin-portal' ); ?></label>
                <textarea id="cap-edit-module-description" name="description" rows="2"></textarea>
            </div>

            <div class="cap-form-field">
                <label for="cap-edit-module-menu-slug"><?php esc_html_e( 'URL del Menú', 'client-admin-portal' ); ?></label>
                <input type="text" id="cap-edit-module-menu-slug" name="menu_slug" required>
            </div>

            <div class="cap-form-row">
                <div class="cap-form-field">
                    <label for="cap-edit-module-icon"><?php esc_html_e( 'Icono', 'client-admin-portal' ); ?></label>
                    <div class="cap-icon-selector">
                        <input type="text" id="cap-edit-module-icon" name="icon">
                        <span class="cap-icon-preview"><span class="dashicons dashicons-admin-generic"></span></span>
                    </div>
                </div>

                <div class="cap-form-field">
                    <label for="cap-edit-module-order"><?php esc_html_e( 'Orden', 'client-admin-portal' ); ?></label>
                    <input type="number" id="cap-edit-module-order" name="sort_order" min="0" max="999">
                </div>
            </div>

            <div class="cap-form-actions">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Guardar Cambios', 'client-admin-portal' ); ?>
                </button>
                <button type="button" class="button cap-modal-cancel">
                    <?php esc_html_e( 'Cancelar', 'client-admin-portal' ); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Icon Picker Modal -->
<div id="cap-icon-picker-modal" class="cap-modal" style="display: none;">
    <div class="cap-modal-content cap-modal-large">
        <span class="cap-modal-close">&times;</span>
        <h2><?php esc_html_e( 'Elegir Icono', 'client-admin-portal' ); ?></h2>

        <div class="cap-icon-search">
            <input type="text" id="cap-icon-search" placeholder="<?php esc_attr_e( 'Buscar icono...', 'client-admin-portal' ); ?>">
        </div>

        <div class="cap-icon-grid">
            <?php
            // Common dashicons.
            $icons = array(
                'menu', 'admin-site', 'dashboard', 'admin-post', 'admin-media', 'admin-links',
                'admin-page', 'admin-comments', 'admin-appearance', 'admin-plugins', 'admin-users',
                'admin-tools', 'admin-settings', 'admin-network', 'admin-home', 'admin-generic',
                'admin-collapse', 'filter', 'admin-customizer', 'admin-multisite', 'welcome-write-blog',
                'welcome-add-page', 'welcome-view-site', 'welcome-widgets-menus', 'welcome-comments',
                'welcome-learn-more', 'format-aside', 'format-image', 'format-gallery', 'format-video',
                'format-status', 'format-quote', 'format-chat', 'format-audio', 'camera', 'images-alt',
                'images-alt2', 'video-alt', 'video-alt2', 'video-alt3', 'media-archive', 'media-audio',
                'media-code', 'media-default', 'media-document', 'media-interactive', 'media-spreadsheet',
                'media-text', 'media-video', 'playlist-audio', 'playlist-video', 'controls-play',
                'controls-pause', 'controls-forward', 'controls-skipforward', 'controls-back',
                'controls-skipback', 'controls-repeat', 'controls-volumeon', 'controls-volumeoff',
                'image-crop', 'image-rotate', 'image-rotate-left', 'image-rotate-right', 'image-flip-vertical',
                'image-flip-horizontal', 'image-filter', 'undo', 'redo', 'editor-bold', 'editor-italic',
                'editor-ul', 'editor-ol', 'editor-quote', 'editor-alignleft', 'editor-aligncenter',
                'editor-alignright', 'editor-insertmore', 'editor-spellcheck', 'editor-expand',
                'editor-contract', 'editor-kitchensink', 'editor-underline', 'editor-justify',
                'editor-textcolor', 'editor-paste-word', 'editor-paste-text', 'editor-removeformatting',
                'editor-video', 'editor-customchar', 'editor-outdent', 'editor-indent', 'editor-help',
                'editor-strikethrough', 'editor-unlink', 'editor-rtl', 'editor-break', 'editor-code',
                'editor-paragraph', 'editor-table', 'align-left', 'align-right', 'align-center',
                'align-none', 'lock', 'unlock', 'calendar', 'calendar-alt', 'visibility', 'hidden',
                'post-status', 'edit', 'trash', 'sticky', 'external', 'arrow-up', 'arrow-down',
                'arrow-right', 'arrow-left', 'arrow-up-alt', 'arrow-down-alt', 'arrow-right-alt',
                'arrow-left-alt', 'arrow-up-alt2', 'arrow-down-alt2', 'arrow-right-alt2', 'arrow-left-alt2',
                'sort', 'leftright', 'randomize', 'list-view', 'exerpt-view', 'grid-view', 'move',
                'share', 'share-alt', 'share-alt2', 'twitter', 'rss', 'email', 'email-alt', 'email-alt2',
                'facebook', 'facebook-alt', 'googleplus', 'networking', 'hammer', 'art', 'migrate',
                'performance', 'universal-access', 'universal-access-alt', 'tickets', 'nametag',
                'clipboard', 'heart', 'megaphone', 'schedule', 'wordpress', 'wordpress-alt', 'pressthis',
                'update', 'screenoptions', 'info', 'cart', 'feedback', 'cloud', 'translation', 'tag',
                'category', 'archive', 'tagcloud', 'text', 'yes', 'no', 'no-alt', 'plus', 'plus-alt',
                'minus', 'dismiss', 'marker', 'star-filled', 'star-half', 'star-empty', 'flag',
                'warning', 'location', 'location-alt', 'vault', 'shield', 'shield-alt', 'sos',
                'search', 'slides', 'analytics', 'chart-pie', 'chart-bar', 'chart-line', 'chart-area',
                'groups', 'businessman', 'id', 'id-alt', 'products', 'awards', 'forms', 'testimonial',
                'portfolio', 'book', 'book-alt', 'download', 'upload', 'backup', 'clock', 'lightbulb',
                'microphone', 'desktop', 'laptop', 'tablet', 'smartphone', 'phone', 'index-card',
                'carrot', 'building', 'store', 'album', 'palmtree', 'tickets-alt', 'money', 'smiley',
                'thumbs-up', 'thumbs-down', 'layout', 'paperclip', 'car', 'food', 'coffee', 'drumstick',
            );

            foreach ( $icons as $icon ) :
            ?>
                <button type="button" class="cap-icon-option" data-icon="dashicons-<?php echo esc_attr( $icon ); ?>">
                    <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>
