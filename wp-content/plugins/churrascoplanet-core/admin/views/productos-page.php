<?php
/**
 * Productos - Página principal (wrapper + sub-navegación)
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

$section    = isset($section) ? $section : 'list';
$product_id = isset($product_id) ? $product_id : 0;
$base_url   = admin_url('admin.php?page=churrascoplanet-productos');

// Determinar sección activa para nav
$nav_active = $section;
if ($section === 'edit') $nav_active = 'list';
?>
<div class="wrap chp-productos-wrap">
    <div class="chp-productos-header">
        <h1>
            <i class="fas fa-box-open"></i>
            <?php _e('Productos', 'churrascoplanet-core'); ?>
        </h1>
        <p class="description"><?php _e('Gestiona los productos de tu tienda WooCommerce', 'churrascoplanet-core'); ?></p>
    </div>

    <nav class="chp-productos-nav">
        <a href="<?php echo esc_url($base_url); ?>"
           class="chp-nav-tab <?php echo $nav_active === 'list' ? 'active' : ''; ?>">
            <i class="fas fa-list"></i>
            <span><?php _e('Todos los Productos', 'churrascoplanet-core'); ?></span>
        </a>
        <a href="<?php echo esc_url($base_url . '&section=add'); ?>"
           class="chp-nav-tab <?php echo $section === 'add' ? 'active' : ''; ?>">
            <i class="fas fa-plus"></i>
            <span><?php _e('Agregar Nuevo', 'churrascoplanet-core'); ?></span>
        </a>
        <a href="<?php echo esc_url($base_url . '&section=categories'); ?>"
           class="chp-nav-tab <?php echo $section === 'categories' ? 'active' : ''; ?>">
            <i class="fas fa-folder"></i>
            <span><?php _e('Categorías', 'churrascoplanet-core'); ?></span>
        </a>
        <a href="<?php echo esc_url($base_url . '&section=tags'); ?>"
           class="chp-nav-tab <?php echo $section === 'tags' ? 'active' : ''; ?>">
            <i class="fas fa-tags"></i>
            <span><?php _e('Etiquetas', 'churrascoplanet-core'); ?></span>
        </a>
    </nav>

    <div class="chp-productos-content">
        <?php
        switch ($section) {
            case 'add':
                include CHP_CORE_PATH . 'admin/views/productos/form.php';
                break;
            case 'edit':
                include CHP_CORE_PATH . 'admin/views/productos/form.php';
                break;
            case 'categories':
                include CHP_CORE_PATH . 'admin/views/productos/categories.php';
                break;
            case 'tags':
                include CHP_CORE_PATH . 'admin/views/productos/tags.php';
                break;
            default:
                include CHP_CORE_PATH . 'admin/views/productos/list.php';
                break;
        }
        ?>
    </div>
</div>
