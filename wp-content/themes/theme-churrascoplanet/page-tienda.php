<?php
/**
 * Template Name: Tienda ChurrascoPlanet
 * ChurrascoPlanet - Página de Tienda
 * Template para la página de tienda con productos agrupados por categorías
 *
 * @package ChurrascoPlanet
 */

defined('ABSPATH') || exit;

get_header();

// Obtener todos los productos y organizarlos por categoría
$all_products_args = array(
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
);
$all_products = new WP_Query($all_products_args);

// Recolectar productos por categoría
$products_by_category = array();
if ($all_products->have_posts()) :
    while ($all_products->have_posts()) : $all_products->the_post();
        $product_id = get_the_ID();
        $terms = get_the_terms($product_id, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if ($term->slug !== 'uncategorized') {
                    if (!isset($products_by_category[$term->term_id])) {
                        $products_by_category[$term->term_id] = array(
                            'term' => $term,
                            'products' => array()
                        );
                    }
                    $products_by_category[$term->term_id]['products'][] = $product_id;
                }
            }
        }
    endwhile;
    wp_reset_postdata();
endif;

// Ordenar categorías en orden definido
$cat_order_slugs = array(
    'promociones-de-otro-planeta',
    'planeta-sabores',
    'misiles-planetarios',
    'planeta-frito',
    'hidratacion-planetaria',
    'aderezo-interestelar',
);
$ordered_products = array();
foreach ($cat_order_slugs as $slug) {
    foreach ($products_by_category as $cat_id => $data) {
        if ($data['term']->slug === $slug) {
            $ordered_products[$cat_id] = $data;
            break;
        }
    }
}
// Agregar categorías que no estén en el orden definido
foreach ($products_by_category as $cat_id => $data) {
    if (!isset($ordered_products[$cat_id])) {
        $ordered_products[$cat_id] = $data;
    }
}
$products_by_category = $ordered_products;
?>

<section class="hero shop-hero">
    <div class="menu-section">
        <div class="container">
            <div class="section-header">
                <span class="section-badge">
                    <i class="fas fa-meteor"></i>
                    <?php esc_html_e('Nuestro Menú', 'churrascoplanet'); ?>
                </span>
                <h2 class="section-title"><?php esc_html_e('Planeta de', 'churrascoplanet'); ?> <span class="highlight"><?php esc_html_e('Sabores', 'churrascoplanet'); ?></span></h2>
                <p class="section-subtitle"><?php esc_html_e('Explora nuestra galaxia de churrascos y más', 'churrascoplanet'); ?></p>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($products_by_category)) : ?>
<!-- Navegación sticky de categorías -->
<nav class="category-nav-sticky" id="categoryNavSticky">
    <div class="container">
        <div class="category-nav">
            <button class="category-nav-btn active" data-target="all">
                <i class="fas fa-th"></i>
                <span><?php esc_html_e('Todos', 'churrascoplanet'); ?></span>
            </button>
            <?php foreach ($products_by_category as $cat_data) : ?>
            <button class="category-nav-btn" data-target="categoria-<?php echo esc_attr($cat_data['term']->slug); ?>">
                <i class="fas fa-utensils"></i>
                <span><?php echo esc_html($cat_data['term']->name); ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</nav>
<?php endif; ?>

<section class="shop-products">
    <div class="container">
        <?php if (!empty($products_by_category)) : ?>
            <?php foreach ($products_by_category as $cat_data) :
                $category = $cat_data['term'];
                $product_ids = $cat_data['products'];
            ?>
                <div class="category-section" id="categoria-<?php echo esc_attr($category->slug); ?>">
                    <h3 class="section-title section-title-sm"><?php echo esc_html($category->name); ?></h3>
                    <?php if (!empty($category->description)) : ?>
                        <p class="category-description"><?php echo esc_html($category->description); ?></p>
                    <?php endif; ?>

                    <div class="products-grid">
                        <?php foreach ($product_ids as $product_id) :
                            $product = wc_get_product($product_id);
                            if (!$product) continue;

                            $post = get_post($product_id);
                            setup_postdata($post);
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
                        <?php
                        endforeach;
                        wp_reset_postdata();
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="no-products">
                <i class="fas fa-rocket" style="font-size: 60px; color: var(--color-orange); margin-bottom: 20px; display: block;"></i>
                <p><?php esc_html_e('No se encontraron productos.', 'churrascoplanet'); ?></p>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php get_footer(); ?>
