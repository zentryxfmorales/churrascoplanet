<?php
/**
 * Vista principal del panel de administración
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

// Usar la variable $admin que viene del archivo que incluye esta vista
// Si no existe, crear una instancia helper
if (!isset($admin)) {
    if (class_exists('ChurrascoPlanet_Admin_Options')) {
        $admin = ChurrascoPlanet_Admin_Options::get_instance();
    } elseif (class_exists('ChurrascoPlanet_Admin_Options_Helper')) {
        $admin = new ChurrascoPlanet_Admin_Options_Helper();
    }
}

$option_name = isset($admin) ? $admin->get_option_name() : 'churrascoplanet_options';
?>
<div class="wrap churrascoplanet-admin-wrap">
    <div class="churrascoplanet-admin-header">
        <h1>
            <i class="fas fa-rocket"></i>
            <?php _e('Aspecto PlanetaChurrascos', 'churrascoplanet-core'); ?>
        </h1>
        <p class="description"><?php _e('Personaliza todos los elementos visuales de tu sitio', 'churrascoplanet-core'); ?></p>
    </div>

    <form id="churrascoplanet-options-form" method="post" action="options.php">
        <?php settings_fields('churrascoplanet_options_group'); ?>

        <div class="churrascoplanet-admin-tabs">
            <nav class="nav-tab-wrapper">
                <a href="#tab-inicio" class="nav-tab nav-tab-active" data-tab="inicio">
                    <i class="fas fa-home"></i> <span><?php _e('Inicio', 'churrascoplanet-core'); ?></span>
                </a>
                <a href="#tab-navegacion" class="nav-tab" data-tab="navegacion">
                    <i class="fas fa-bars"></i> <span><?php _e('Navegación', 'churrascoplanet-core'); ?></span>
                </a>
                <a href="#tab-menu" class="nav-tab" data-tab="menu">
                    <i class="fas fa-utensils"></i> <span><?php _e('Menú', 'churrascoplanet-core'); ?></span>
                </a>
                <a href="#tab-promociones" class="nav-tab" data-tab="promociones">
                    <i class="fas fa-tags"></i> <span><?php _e('Promociones', 'churrascoplanet-core'); ?></span>
                </a>
                <a href="#tab-locales" class="nav-tab" data-tab="locales">
                    <i class="fas fa-map-marker-alt"></i> <span><?php _e('Locales', 'churrascoplanet-core'); ?></span>
                </a>
                <a href="#tab-extras" class="nav-tab" data-tab="extras">
                    <i class="fas fa-puzzle-piece"></i> <span><?php _e('Extras', 'churrascoplanet-core'); ?></span>
                </a>
                <a href="#tab-colores" class="nav-tab" data-tab="colores">
                    <i class="fas fa-palette"></i> <span><?php _e('Colores', 'churrascoplanet-core'); ?></span>
                </a>
                <a href="#tab-clientes" class="nav-tab" data-tab="clientes">
                    <i class="fas fa-users"></i> <span><?php _e('Clientes', 'churrascoplanet-core'); ?></span>
                </a>
                <a href="#tab-cupones" class="nav-tab" data-tab="cupones">
                    <i class="fas fa-ticket-alt"></i> <span><?php _e('Cupones', 'churrascoplanet-core'); ?></span>
                </a>
            </nav>

            <!-- TAB: INICIO -->
            <div id="tab-inicio" class="tab-content active">
                <?php include CHP_CORE_PATH . 'admin/views/tabs/tab-inicio.php'; ?>
            </div>

            <!-- TAB: NAVEGACIÓN -->
            <div id="tab-navegacion" class="tab-content">
                <?php include CHP_CORE_PATH . 'admin/views/tabs/tab-navegacion.php'; ?>
            </div>

            <!-- TAB: MENÚ -->
            <div id="tab-menu" class="tab-content">
                <?php include CHP_CORE_PATH . 'admin/views/tabs/tab-menu.php'; ?>
            </div>

            <!-- TAB: PROMOCIONES -->
            <div id="tab-promociones" class="tab-content">
                <?php include CHP_CORE_PATH . 'admin/views/tabs/tab-promociones.php'; ?>
            </div>

            <!-- TAB: LOCALES -->
            <div id="tab-locales" class="tab-content">
                <?php include CHP_CORE_PATH . 'admin/views/tabs/tab-locales.php'; ?>
            </div>

            <!-- TAB: EXTRAS DE PRODUCTOS -->
            <div id="tab-extras" class="tab-content">
                <?php include CHP_CORE_PATH . 'admin/views/tabs/tab-extras.php'; ?>
            </div>

            <!-- TAB: COLORES -->
            <div id="tab-colores" class="tab-content">
                <?php include CHP_CORE_PATH . 'admin/views/tabs/tab-colores.php'; ?>
            </div>

            <!-- TAB: CUPONES (dentro del form para guardar opciones de configuración) -->
            <div id="tab-cupones" class="tab-content">
                <?php include CHP_CORE_PATH . 'admin/views/tabs/tab-cupones.php'; ?>
            </div>

        </div>

        <div class="churrascoplanet-admin-footer">
            <button type="submit" class="button button-primary button-hero" id="save-options">
                <i class="fas fa-save"></i>
                <?php _e('Guardar Cambios', 'churrascoplanet-core'); ?>
            </button>
            <span class="save-status"></span>
        </div>
    </form>

    <!-- TAB: CLIENTES — fuera del form principal para evitar forms anidados -->
    <div id="tab-clientes" class="tab-content churrascoplanet-admin-tabs-clientes">
        <?php include CHP_CORE_PATH . 'admin/views/tabs/tab-clientes.php'; ?>
    </div>
</div>
