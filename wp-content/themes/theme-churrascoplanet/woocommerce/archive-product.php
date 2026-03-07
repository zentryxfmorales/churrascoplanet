<?php
/**
 * ChurrascoPlanet - Tienda / Archivo de Productos
 * Template para la tienda y páginas de categoría de productos
 *
 * @package ChurrascoPlanet
 */

defined('ABSPATH') || exit;

get_header();

// Detectar si estamos en una página de categoría específica
$is_category_page = is_product_category();
$current_category = null;

if ($is_category_page) {
    $current_category = get_queried_object();
}
?>

<section class="hero shop-hero">
    <div class="menu-section">
        <div class="container">
            <div class="section-header">
                <span class="section-badge">
                    <i class="fas fa-meteor"></i>
                    <?php
                    if ($is_category_page && $current_category) {
                        echo esc_html($current_category->name);
                    } else {
                        esc_html_e('Nuestro Menú', 'churrascoplanet');
                    }
                    ?>
                </span>
                <h2 class="section-title">
                    <?php if ($is_category_page && $current_category) : ?>
                        <?php echo esc_html($current_category->name); ?>
                    <?php else : ?>
                        <?php esc_html_e('Planeta de', 'churrascoplanet'); ?> <span class="highlight"><?php esc_html_e('Sabores', 'churrascoplanet'); ?></span>
                    <?php endif; ?>
                </h2>
                <p class="section-subtitle">
                    <?php
                    if ($is_category_page && $current_category && !empty($current_category->description)) {
                        echo esc_html($current_category->description);
                    } else {
                        esc_html_e('Explora nuestra galaxia de churrascos y más', 'churrascoplanet');
                    }
                    ?>
                </p>
            </div>
        </div>
    </div>
</section>

<section class="shop-products">
    <div class="container">
        <?php
        if ($is_category_page && $current_category) :
            // Página de categoría individual - mostrar productos de esta categoría
            $products = wc_get_products(array(
                'status'   => 'publish',
                'limit'    => -1,
                'orderby'  => 'title',
                'order'    => 'ASC',
                'category' => array($current_category->slug),
            ));

            if (!empty($products)) :
        ?>
                <div class="category-section" id="categoria-<?php echo esc_attr($current_category->slug); ?>">
                    <div class="products-grid">
                        <?php foreach ($products as $product) :
                            $product_id = $product->get_id();
                        ?>
                            <div class="product-card" data-category="<?php echo esc_attr($current_category->slug); ?>" data-product-id="<?php echo esc_attr($product_id); ?>">
                                <div class="product-image">
                                    <?php
                                    $image_id = $product->get_image_id();
                                    if ($image_id) {
                                        echo wp_get_attachment_image($image_id, 'woocommerce_thumbnail');
                                    } else {
                                        echo '<img src="' . esc_url(wc_placeholder_img_src('woocommerce_thumbnail')) . '" alt="' . esc_attr($product->get_name()) . '">';
                                    }
                                    ?>

                                    <?php if ($product->is_on_sale()) :
                                        $regular_price = (float) $product->get_regular_price();
                                        $sale_price = (float) $product->get_sale_price();
                                        $discount = ($regular_price > 0 && $sale_price) ? round((($regular_price - $sale_price) / $regular_price) * 100) : 0;
                                    ?>
                                        <span class="promo-badge"><?php echo $discount > 0 ? '-' . $discount . '%' : esc_html__('Oferta', 'churrascoplanet'); ?></span>
                                    <?php endif; ?>

                                    <div class="product-overlay">
                                        <button type="button" class="btn-quick-view btn-open-modal" data-product-id="<?php echo esc_attr($product_id); ?>" title="<?php esc_attr_e('Ver producto', 'churrascoplanet'); ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="product-content">
                                    <span class="product-category"><?php echo esc_html($current_category->name); ?></span>
                                    <h4 class="product-name"><?php echo esc_html($product->get_name()); ?></h4>
                                    <?php
                                    $short_desc = $product->get_short_description();
                                    if (!empty($short_desc)) :
                                    ?>
                                        <p class="product-description"><?php echo wp_trim_words(wp_strip_all_tags($short_desc), 10); ?></p>
                                    <?php endif; ?>

                                    <div class="product-footer">
                                        <div class="product-price">
                                            <?php echo $product->get_price_html(); ?>
                                        </div>

                                        <?php if ($product->is_purchasable() && $product->is_in_stock()) : ?>
                                            <button type="button"
                                               class="btn-add-cart btn-open-modal"
                                               data-product-id="<?php echo esc_attr($product->get_id()); ?>"
                                               aria-label="<?php echo esc_attr(sprintf(__('Añadir "%s" al carrito', 'churrascoplanet'), $product->get_name())); ?>">
                                                <i class="fas fa-shopping-cart"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
        <?php
            else :
        ?>
                <div class="no-products">
                    <i class="fas fa-rocket" style="font-size: 60px; color: var(--color-orange); margin-bottom: 20px; display: block;"></i>
                    <p><?php esc_html_e('No se encontraron productos en esta categoría.', 'churrascoplanet'); ?></p>
                </div>
        <?php
            endif;
        else :
            // Página principal de tienda - mostrar todas las categorías con productos
            $product_categories = get_terms(array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'exclude'    => array(get_option('default_product_cat')), // Excluir "Sin categoría"
            ));

            $has_products = false;

            if (!empty($product_categories) && !is_wp_error($product_categories)) :
                foreach ($product_categories as $category) :
                    // Saltar la categoría "uncategorized"
                    if ($category->slug === 'uncategorized') continue;

                    $products = wc_get_products(array(
                        'status'   => 'publish',
                        'limit'    => -1,
                        'orderby'  => 'title',
                        'order'    => 'ASC',
                        'category' => array($category->slug),
                    ));

                    if (!empty($products)) :
                        $has_products = true;
        ?>
                        <div class="category-section" id="categoria-<?php echo esc_attr($category->slug); ?>">
                            <h3 class="section-title section-title-sm"><?php echo esc_html($category->name); ?></h3>
                            <?php if (!empty($category->description)) : ?>
                                <p class="category-description"><?php echo esc_html($category->description); ?></p>
                            <?php endif; ?>

                            <div class="products-grid">
                                <?php foreach ($products as $product) :
                                    $product_id = $product->get_id();
                                ?>
                                    <div class="product-card" data-category="<?php echo esc_attr($category->slug); ?>" data-product-id="<?php echo esc_attr($product_id); ?>">
                                        <div class="product-image">
                                            <?php
                                            $image_id = $product->get_image_id();
                                            if ($image_id) {
                                                echo wp_get_attachment_image($image_id, 'woocommerce_thumbnail');
                                            } else {
                                                echo '<img src="' . esc_url(wc_placeholder_img_src('woocommerce_thumbnail')) . '" alt="' . esc_attr($product->get_name()) . '">';
                                            }
                                            ?>

                                            <?php if ($product->is_on_sale()) :
                                                $regular_price = (float) $product->get_regular_price();
                                                $sale_price = (float) $product->get_sale_price();
                                                $discount = ($regular_price > 0 && $sale_price) ? round((($regular_price - $sale_price) / $regular_price) * 100) : 0;
                                            ?>
                                                <span class="promo-badge"><?php echo $discount > 0 ? '-' . $discount . '%' : esc_html__('Oferta', 'churrascoplanet'); ?></span>
                                            <?php endif; ?>

                                            <div class="product-overlay">
                                                <button type="button" class="btn-quick-view btn-open-modal" data-product-id="<?php echo esc_attr($product_id); ?>" title="<?php esc_attr_e('Ver producto', 'churrascoplanet'); ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="product-content">
                                            <span class="product-category"><?php echo esc_html($category->name); ?></span>
                                            <h4 class="product-name"><?php echo esc_html($product->get_name()); ?></h4>
                                            <?php
                                            $short_desc = $product->get_short_description();
                                            if (!empty($short_desc)) :
                                            ?>
                                                <p class="product-description"><?php echo wp_trim_words(wp_strip_all_tags($short_desc), 10); ?></p>
                                            <?php endif; ?>

                                            <div class="product-footer">
                                                <div class="product-price">
                                                    <?php echo $product->get_price_html(); ?>
                                                </div>

                                                <?php if ($product->is_purchasable() && $product->is_in_stock()) : ?>
                                                    <button type="button"
                                                       class="btn-add-cart btn-open-modal"
                                                       data-product-id="<?php echo esc_attr($product->get_id()); ?>"
                                                       aria-label="<?php echo esc_attr(sprintf(__('Añadir "%s" al carrito', 'churrascoplanet'), $product->get_name())); ?>">
                                                        <i class="fas fa-shopping-cart"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
        <?php
                    endif;
                endforeach;
            endif;

            if (!$has_products) :
        ?>
                <div class="no-products">
                    <i class="fas fa-rocket" style="font-size: 60px; color: var(--color-orange); margin-bottom: 20px; display: block;"></i>
                    <p><?php esc_html_e('No se encontraron productos.', 'churrascoplanet'); ?></p>
                </div>
        <?php endif;
        endif; ?>
    </div>
</section>

<?php get_footer(); ?>
