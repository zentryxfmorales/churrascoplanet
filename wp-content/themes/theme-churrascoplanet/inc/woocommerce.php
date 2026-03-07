<?php
/**
 * ChurrascoPlanet WooCommerce Functions
 *
 * @package ChurrascoPlanet
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verificar si WooCommerce está activo
 */
if (!class_exists('WooCommerce')) {
    return;
}

/**
 * Desactivar templates de bloques de WooCommerce para usar templates PHP clásicos
 */
add_filter('woocommerce_has_block_template', '__return_false', 100);

/**
 * Forzar el uso del template archive-product.php del tema
 */
function churrascoplanet_wc_template_loader($template, $template_name, $template_path) {
    // Si es el template de archivo de productos, usar el del tema
    if ($template_name === 'archive-product.php') {
        $theme_template = get_template_directory() . '/woocommerce/archive-product.php';
        if (file_exists($theme_template)) {
            return $theme_template;
        }
    }
    return $template;
}
add_filter('wc_get_template', 'churrascoplanet_wc_template_loader', 100, 3);

/**
 * Desactivar el modo site editor de WooCommerce
 */
add_filter('woocommerce_coming_soon', '__return_false', 100);
add_filter('woocommerce_launch_your_store_enabled', '__return_false', 100);

/**
 * Remover estilos por defecto de WooCommerce
 */
add_filter('woocommerce_enqueue_styles', '__return_empty_array');

/**
 * Cambiar texto del botón "Añadir al carrito"
 */
function churrascoplanet_add_to_cart_text($text) {
    return __('Agregar', 'churrascoplanet');
}
add_filter('woocommerce_product_add_to_cart_text', 'churrascoplanet_add_to_cart_text');
add_filter('woocommerce_product_single_add_to_cart_text', 'churrascoplanet_add_to_cart_text');

/**
 * Personalizar fragmentos del carrito AJAX
 */
function churrascoplanet_cart_fragments($fragments) {
    $data = churrascoplanet_get_cart_fragments_data();
    $fragments['#cartItems']      = $data['cart_items_html'];
    $fragments['.cart-count']     = $data['count_html'];
    $fragments['.total-amount']   = $data['total_html'];
    $fragments['#cartCouponWrap'] = $data['coupon_wrap_html'];

    // Badge de cupones: cupones publicados menos los ya aplicados al carrito
    $total_coupons   = (int) ( wp_count_posts('shop_coupon')->publish ?? 0 );
    $applied_count   = count( WC()->cart->get_applied_coupons() );
    $available_count = max( 0, $total_coupons - $applied_count );
    $fragments['#couponCount'] = '<span class="coupon-count" id="couponCount"'
        . ( $available_count > 0 ? '' : ' style="display:none"' )
        . '>' . $available_count . '</span>';

    return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'churrascoplanet_cart_fragments');

/**
 * Remover elementos innecesarios de la tienda
 */
remove_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20);
remove_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30);
remove_action('woocommerce_single_product_summary', 'woocommerce_product_meta', 40);

/**
 * Agregar badge de descuento personalizado
 */
function churrascoplanet_sale_badge() {
    global $product;

    if (!$product->is_on_sale()) {
        return;
    }

    $regular_price = (float) $product->get_regular_price();
    $sale_price    = (float) $product->get_sale_price();

    if ($regular_price > 0) {
        $percentage = round((($regular_price - $sale_price) / $regular_price) * 100);
        echo '<div class="promo-badge">-' . $percentage . '%</div>';
    }
}
remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10);
add_action('woocommerce_before_shop_loop_item_title', 'churrascoplanet_sale_badge', 10);

/**
 * Personalizar breadcrumbs
 */
function churrascoplanet_breadcrumb_defaults($defaults) {
    $defaults['delimiter']   = ' <i class="fas fa-chevron-right"></i> ';
    $defaults['wrap_before'] = '<nav class="woocommerce-breadcrumb">';
    $defaults['wrap_after']  = '</nav>';
    return $defaults;
}
add_filter('woocommerce_breadcrumb_defaults', 'churrascoplanet_breadcrumb_defaults');

/**
 * Agregar wrapper personalizado a productos
 */
function churrascoplanet_before_shop_loop_item() {
    echo '<div class="product-card">';
}
add_action('woocommerce_before_shop_loop_item', 'churrascoplanet_before_shop_loop_item', 5);

function churrascoplanet_after_shop_loop_item() {
    echo '</div>';
}
add_action('woocommerce_after_shop_loop_item', 'churrascoplanet_after_shop_loop_item', 20);

/**
 * Mini cart para sidebar
 */
function churrascoplanet_mini_cart() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    ?>
    <div class="cart-sidebar" id="cartSidebar">
        <div class="cart-header">
            <h3><i class="fas fa-shopping-cart"></i> <?php _e('Tu Pedido', 'churrascoplanet'); ?></h3>
            <button class="close-cart" id="closeCart">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="cart-items" id="cartItems">
            <?php if (WC()->cart->is_empty()) : ?>
            <div class="empty-cart">
                <i class="fas fa-rocket"></i>
                <p><?php _e('Tu carrito esta vacio', 'churrascoplanet'); ?></p>
                <span><?php _e('Agrega productos para comenzar tu viaje', 'churrascoplanet'); ?></span>
            </div>
            <?php else : ?>
                <?php foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) :
                    $product = $cart_item['data'];
                    $product_id = $cart_item['product_id'];
                    $quantity = $cart_item['quantity'];
                    $price = WC()->cart->get_product_price($product);
                    $subtotal = WC()->cart->get_product_subtotal($product, $quantity);
                ?>
                <div class="cart-item" data-key="<?php echo esc_attr($cart_item_key); ?>">
                    <div class="cart-item-image">
                        <?php echo $product->get_image('thumbnail'); ?>
                    </div>
                    <div class="cart-item-info">
                        <h4 class="cart-item-name"><?php echo esc_html($product->get_name()); ?></h4>
                        <?php if (!empty($cart_item['churrascoplanet_extras'])) : ?>
                        <div class="cart-item-extras">
                            <?php foreach ($cart_item['churrascoplanet_extras'] as $extra_group) : ?>
                                <?php foreach ($extra_group['items'] as $extra_item) : ?>
                                    <span class="cart-extra-tag">
                                        <?php echo esc_html($extra_item['nombre']); ?>
                                        <?php if ($extra_item['precio'] > 0) : ?>
                                            <small>(+<?php echo wc_price($extra_item['precio']); ?>)</small>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <p class="cart-item-price"><?php echo $subtotal; ?></p>
                        <div class="cart-item-quantity">
                            <button type="button" class="qty-btn minus" data-key="<?php echo esc_attr($cart_item_key); ?>">-</button>
                            <span class="qty-value"><?php echo $quantity; ?></span>
                            <button type="button" class="qty-btn plus" data-key="<?php echo esc_attr($cart_item_key); ?>">+</button>
                        </div>
                    </div>
                    <button type="button" class="remove-item" data-key="<?php echo esc_attr($cart_item_key); ?>" title="<?php esc_attr_e('Eliminar', 'churrascoplanet'); ?>">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="cart-footer">
            <!-- Campo de cupón en sidebar — siempre en DOM para poder actualizarlo via AJAX -->
            <?php echo churrascoplanet_coupon_sidebar_html(); ?>
            <div class="cart-total">
                <span><?php _e('Total:', 'churrascoplanet'); ?></span>
                <span class="total-amount" id="cartTotalAmount"><?php echo WC()->cart->get_cart_total(); ?></span>
            </div>
            <a href="<?php echo wc_get_checkout_url(); ?>" class="btn btn-checkout">
                <i class="fas fa-credit-card"></i>
                <?php _e('Pagar Pedido', 'churrascoplanet'); ?>
            </a>
        </div>
    </div>
    <div class="cart-overlay" id="cartOverlay"></div>

    <!-- Modal de Cupones -->
    <div class="coupon-modal-overlay" id="couponModalOverlay"></div>
    <div class="coupon-modal" id="couponModal">
        <div class="coupon-modal-header">
            <h3><i class="fas fa-ticket-alt"></i> <?php _e('Cupones Disponibles', 'churrascoplanet'); ?></h3>
            <button class="coupon-modal-close" id="closeCouponModal"><i class="fas fa-times"></i></button>
        </div>
        <div class="coupon-modal-body" id="couponModalBody">
            <?php
            if (wc_coupons_enabled()) {
                $chp_modal_init = churrascoplanet_coupon_modal_data();
                echo $chp_modal_init['modal_body'];
            }
            ?>
        </div>
        <div class="coupon-modal-footer">
            <div class="coupon-manual-wrap">
                <input type="text" id="couponManualInput" class="coupon-manual-input" placeholder="<?php esc_attr_e('Ingresa tu código de cupón', 'churrascoplanet'); ?>">
                <button type="button" id="couponManualApply" class="btn btn-primary">
                    <i class="fas fa-check"></i> <?php _e('Aplicar', 'churrascoplanet'); ?>
                </button>
            </div>
            <div class="coupon-modal-msg" id="couponModalMsg"></div>
        </div>
    </div>

    <!-- Product Modal -->
    <div class="product-modal-overlay" id="productModalOverlay"></div>
    <div class="product-modal" id="productModal">
        <button class="product-modal-close" id="closeProductModal">
            <i class="fas fa-times"></i>
        </button>
        <div class="product-modal-loading" id="productModalLoading">
            <i class="fas fa-spinner fa-spin"></i>
        </div>
        <div class="product-modal-content" id="productModalContent">
            <div class="product-modal-image">
                <img id="modalProductImage" src="" alt="">
                <span class="product-modal-category" id="modalProductCategory"></span>
            </div>
            <div class="product-modal-info">
                <h2 class="product-modal-name" id="modalProductName"></h2>
                <p class="product-modal-description" id="modalProductDescription"></p>
                <div class="product-modal-price" id="modalProductPrice"></div>

                <div class="product-modal-extras" id="modalProductExtras">
                    <!-- Extras groups rendered here by JS -->
                </div>

                <div class="product-modal-footer">
                    <div class="product-modal-qty">
                        <button type="button" class="qty-btn minus" id="modalQtyMinus">-</button>
                        <span class="qty-value" id="modalQtyValue">1</span>
                        <button type="button" class="qty-btn plus" id="modalQtyPlus">+</button>
                    </div>
                    <div class="product-modal-total">
                        <span class="product-modal-total-label"><?php _e('Total:', 'churrascoplanet'); ?></span>
                        <span class="product-modal-total-price" id="modalTotalPrice">$0</span>
                    </div>
                    <button type="button" class="btn btn-primary product-modal-add" id="modalAddToCart">
                        <i class="fas fa-cart-plus"></i>
                        <?php _e('Agregar al Carrito', 'churrascoplanet'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
}
add_action('wp_footer', 'churrascoplanet_mini_cart');

/**
 * Genera los fragmentos del carrito como array para wp_send_json_success.
 * Centraliza la lógica de fragmentos para update_quantity y remove_item.
 */
function churrascoplanet_get_cart_fragments_data() {
    WC()->cart->calculate_totals();

    // Fragmento contador
    ob_start();
    echo WC()->cart->get_cart_contents_count();
    $count_html = '<span class="cart-count">' . ob_get_clean() . '</span>';

    // Fragmento total
    $total_html = '<span class="total-amount" id="cartTotalAmount">' . WC()->cart->get_cart_total() . '</span>';

    // Fragmento #cartItems completo
    ob_start();
    ?>
    <div class="cart-items" id="cartItems">
        <?php if (WC()->cart->is_empty()) : ?>
        <div class="empty-cart">
            <i class="fas fa-rocket"></i>
            <p><?php _e('Tu carrito esta vacio', 'churrascoplanet'); ?></p>
            <span><?php _e('Agrega productos para comenzar tu viaje', 'churrascoplanet'); ?></span>
        </div>
        <?php else : ?>
            <?php foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) :
                $product  = $cart_item['data'];
                $quantity = $cart_item['quantity'];
                $subtotal = WC()->cart->get_product_subtotal($product, $quantity);
            ?>
            <div class="cart-item" data-key="<?php echo esc_attr($cart_item_key); ?>">
                <div class="cart-item-image">
                    <?php echo $product->get_image('thumbnail'); ?>
                </div>
                <div class="cart-item-info">
                    <h4 class="cart-item-name"><?php echo esc_html($product->get_name()); ?></h4>
                    <?php if (!empty($cart_item['churrascoplanet_extras'])) : ?>
                    <div class="cart-item-extras">
                        <?php foreach ($cart_item['churrascoplanet_extras'] as $extra_group) : ?>
                            <?php foreach ($extra_group['items'] as $extra_item) : ?>
                                <span class="cart-extra-tag">
                                    <?php echo esc_html($extra_item['nombre']); ?>
                                    <?php if ($extra_item['precio'] > 0) : ?>
                                        <small>(+<?php echo wc_price($extra_item['precio']); ?>)</small>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <p class="cart-item-price"><?php echo $subtotal; ?></p>
                    <div class="cart-item-quantity">
                        <button type="button" class="qty-btn minus" data-key="<?php echo esc_attr($cart_item_key); ?>">-</button>
                        <span class="qty-value"><?php echo $quantity; ?></span>
                        <button type="button" class="qty-btn plus" data-key="<?php echo esc_attr($cart_item_key); ?>">+</button>
                    </div>
                </div>
                <button type="button" class="remove-item" data-key="<?php echo esc_attr($cart_item_key); ?>" title="<?php esc_attr_e('Eliminar', 'churrascoplanet'); ?>">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    $cart_items_html = ob_get_clean();

    // Fragmento sección cupón del sidebar
    $coupon_wrap_html = churrascoplanet_coupon_sidebar_html();

    return array(
        'cart_items_html'  => $cart_items_html,
        'count_html'       => $count_html,
        'total_html'       => $total_html,
        'coupon_wrap_html' => $coupon_wrap_html,
        'cart_total'       => WC()->cart->get_cart_total(),
        'cart_count'       => WC()->cart->get_cart_contents_count(),
        'is_empty'         => WC()->cart->is_empty(),
    );
}

/**
 * AJAX handler para actualizar cantidad del carrito
 */
function churrascoplanet_update_cart_quantity() {
    if ( function_exists('wc_load_cart') ) {
        wc_load_cart();
    }

    $cart_item_key = sanitize_text_field($_POST['cart_item_key'] ?? '');
    $quantity      = intval($_POST['quantity'] ?? 0);

    if ( empty($cart_item_key) ) {
        wp_send_json_error( array('message' => 'Cart item key missing') );
    }

    if ($quantity > 0) {
        WC()->cart->set_quantity($cart_item_key, $quantity, true);
    } else {
        WC()->cart->remove_cart_item($cart_item_key);
    }

    wp_send_json_success( churrascoplanet_get_cart_fragments_data() );
}
add_action('wp_ajax_churrascoplanet_update_quantity', 'churrascoplanet_update_cart_quantity');
add_action('wp_ajax_nopriv_churrascoplanet_update_quantity', 'churrascoplanet_update_cart_quantity');
add_action('wc_ajax_chp_update_qty', 'churrascoplanet_update_cart_quantity');

/**
 * AJAX handler para eliminar item del carrito
 */
function churrascoplanet_remove_cart_item() {
    if ( function_exists('wc_load_cart') ) {
        wc_load_cart();
    }

    $cart_item_key = sanitize_text_field($_POST['cart_item_key'] ?? '');

    if ( empty($cart_item_key) ) {
        wp_send_json_error( array('message' => 'Cart item key missing') );
    }

    WC()->cart->remove_cart_item($cart_item_key);

    wp_send_json_success( churrascoplanet_get_cart_fragments_data() );
}
add_action('wp_ajax_churrascoplanet_remove_item', 'churrascoplanet_remove_cart_item');
add_action('wp_ajax_nopriv_churrascoplanet_remove_item', 'churrascoplanet_remove_cart_item');
add_action('wc_ajax_chp_remove_item', 'churrascoplanet_remove_cart_item');

/**
 * Genera el HTML del body del modal de cupones con estado actualizado.
 * Marca con clase 'is-applied' los cupones ya aplicados al carrito.
 * Retorna también el conteo de cupones disponibles (no aplicados).
 */
function churrascoplanet_coupon_modal_data() {
    $applied_coupons = array_map('strtolower', WC()->cart->get_applied_coupons());

    $coupons_query = new WP_Query(array(
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ));

    $count_available = 0;
    ob_start();

    if ($coupons_query->have_posts()) :
        while ($coupons_query->have_posts()) : $coupons_query->the_post();
            $coupon_obj  = new WC_Coupon(get_the_title());
            $code        = strtolower(get_the_title());
            $amount      = $coupon_obj->get_amount();
            $type        = $coupon_obj->get_discount_type();
            $minimum     = $coupon_obj->get_minimum_amount();
            $expiry      = $coupon_obj->get_date_expires();
            $description = get_post_meta(get_the_ID(), 'coupon_description', true) ?: get_the_excerpt();
            $usage_limit = $coupon_obj->get_usage_limit();
            $usage_count = $coupon_obj->get_usage_count();
            $uses_left   = $usage_limit ? $usage_limit - $usage_count : null;
            $is_applied  = in_array($code, $applied_coupons, true);

            if (!$is_applied) $count_available++;

            $discount_label = $type === 'percent'
                ? esc_html($amount) . '%'
                : wc_price($amount);
            ?>
            <div class="coupon-ticket<?php echo $is_applied ? ' is-applied' : ''; ?>" data-code="<?php echo esc_attr($code); ?>">
                <div class="coupon-ticket-left">
                    <span class="coupon-ticket-amount"><?php echo $discount_label; ?></span>
                    <span class="coupon-ticket-type"><?php echo $type === 'percent' ? __('descuento', 'churrascoplanet') : __('de descuento', 'churrascoplanet'); ?></span>
                </div>
                <div class="coupon-ticket-divider"></div>
                <div class="coupon-ticket-right">
                    <div class="coupon-ticket-code"><?php echo esc_html(strtoupper($code)); ?></div>
                    <?php if ($description) : ?>
                    <div class="coupon-ticket-desc"><?php echo esc_html(wp_trim_words($description, 10)); ?></div>
                    <?php endif; ?>
                    <div class="coupon-ticket-meta">
                        <?php if ($minimum) : ?>
                        <span><i class="fas fa-shopping-cart"></i> <?php printf(__('Mín. %s', 'churrascoplanet'), wc_price($minimum)); ?></span>
                        <?php endif; ?>
                        <?php if ($expiry) : ?>
                        <span><i class="fas fa-clock"></i> <?php printf(__('Vence %s', 'churrascoplanet'), $expiry->date_i18n('d/m/Y')); ?></span>
                        <?php endif; ?>
                        <?php if ($uses_left !== null) : ?>
                        <span><i class="fas fa-users"></i> <?php printf(__('%d usos restantes', 'churrascoplanet'), $uses_left); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($is_applied) : ?>
                    <div class="coupon-ticket-applied-label">
                        <i class="fas fa-check-circle"></i> <?php _e('Aplicado', 'churrascoplanet'); ?>
                    </div>
                    <?php else : ?>
                    <button type="button" class="coupon-ticket-apply" data-coupon="<?php echo esc_attr($code); ?>">
                        <i class="fas fa-tag"></i> <?php _e('Aplicar cupón', 'churrascoplanet'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        endwhile;
        wp_reset_postdata();
    else :
        ?>
        <div class="coupon-modal-empty">
            <i class="fas fa-ticket-alt"></i>
            <p><?php _e('No hay cupones disponibles en este momento.', 'churrascoplanet'); ?></p>
        </div>
        <?php
    endif;

    return array(
        'modal_body'      => ob_get_clean(),
        'coupon_count'    => $count_available,
    );
}

/**
 * Genera el HTML del wrap de cupón en el sidebar según el estado actual del carrito.
 */
function churrascoplanet_coupon_sidebar_html() {
    WC()->cart->calculate_totals();
    $applied = WC()->cart->get_applied_coupons();
    ob_start();
    ?>
    <div class="cart-coupon-wrap" id="cartCouponWrap">
    <?php if (!empty($applied)) :
        $code     = $applied[0];
        // Usar discount_total del carrito para asegurar que siempre refleja el total real
        $discount = WC()->cart->get_discount_total();
        ?>
        <div class="cart-coupon-applied">
            <span class="cart-coupon-badge">
                <i class="fas fa-tag"></i>
                <strong><?php echo esc_html(strtoupper($code)); ?></strong>
                <span class="cart-coupon-discount">-<?php echo wc_price($discount); ?></span>
            </span>
            <button type="button" class="cart-coupon-remove" data-coupon="<?php echo esc_attr($code); ?>" title="<?php esc_attr_e('Quitar cupón', 'churrascoplanet'); ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php elseif (!WC()->cart->is_empty()) : ?>
        <div class="cart-coupon-input-row">
            <input type="text" id="cartCouponInput" class="cart-coupon-input" placeholder="<?php esc_attr_e('Código de descuento', 'churrascoplanet'); ?>">
            <button type="button" id="cartCouponApply" class="cart-coupon-apply-btn"><?php _e('Aplicar', 'churrascoplanet'); ?></button>
        </div>
        <div class="cart-coupon-msg" id="cartCouponMsg"></div>
    <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * AJAX: Aplicar cupón desde sidebar/modal
 */
function churrascoplanet_ajax_apply_coupon() {
    if ( function_exists('wc_load_cart') ) {
        wc_load_cart();
    }

    $coupon_code = sanitize_text_field($_POST['coupon_code'] ?? '');

    if (empty($coupon_code)) {
        wp_send_json_error(array('message' => __('Ingresa un código de cupón.', 'churrascoplanet')));
    }

    $result = WC()->cart->apply_coupon($coupon_code);

    $notices = wc_get_notices('error');
    $msg_error = !empty($notices) ? wp_strip_all_tags($notices[0]['notice']) : '';
    wc_clear_notices();

    // Recalcular siempre DESPUÉS de apply_coupon para reflejar descuentos reales
    WC()->cart->calculate_totals();
    $modal     = churrascoplanet_coupon_modal_data();
    $cart_data = churrascoplanet_get_cart_fragments_data();

    if ($result) {
        wp_send_json_success(array_merge($cart_data, array(
            'message'      => __('Cupón aplicado correctamente.', 'churrascoplanet'),
            'sidebar_html' => $cart_data['coupon_wrap_html'],
            'modal_body'   => $modal['modal_body'],
            'coupon_count' => $modal['coupon_count'],
        )));
    } else {
        // Incluir datos del carrito en el error para que el JS sincronice el estado real
        wp_send_json_error(array_merge($cart_data, array(
            'message'      => $msg_error ?: __('Cupón no válido.', 'churrascoplanet'),
            'sidebar_html' => $cart_data['coupon_wrap_html'],
            'modal_body'   => $modal['modal_body'],
            'coupon_count' => $modal['coupon_count'],
        )));
    }
}
add_action('wp_ajax_churrascoplanet_apply_coupon', 'churrascoplanet_ajax_apply_coupon');
add_action('wp_ajax_nopriv_churrascoplanet_apply_coupon', 'churrascoplanet_ajax_apply_coupon');
add_action('wc_ajax_chp_apply_coupon', 'churrascoplanet_ajax_apply_coupon');

/**
 * AJAX: Quitar cupón desde sidebar
 */
function churrascoplanet_ajax_remove_coupon() {
    if ( function_exists('wc_load_cart') ) {
        wc_load_cart();
    }

    $coupon_code = sanitize_text_field($_POST['coupon_code'] ?? '');

    if (empty($coupon_code)) {
        wp_send_json_error(array('message' => __('Código inválido.', 'churrascoplanet')));
    }

    WC()->cart->remove_coupon($coupon_code);
    WC()->cart->calculate_totals();

    $modal     = churrascoplanet_coupon_modal_data();
    $cart_data = churrascoplanet_get_cart_fragments_data();
    wp_send_json_success(array_merge($cart_data, array(
        'message'      => __('Cupón eliminado.', 'churrascoplanet'),
        'sidebar_html' => $cart_data['coupon_wrap_html'],
        'modal_body'   => $modal['modal_body'],
        'coupon_count' => $modal['coupon_count'],
    )));
}
add_action('wp_ajax_churrascoplanet_remove_coupon', 'churrascoplanet_ajax_remove_coupon');
add_action('wp_ajax_nopriv_churrascoplanet_remove_coupon', 'churrascoplanet_ajax_remove_coupon');
add_action('wc_ajax_chp_remove_coupon', 'churrascoplanet_ajax_remove_coupon');

/**
 * AJAX: Retornar estado actual del carrito como fragments (sin cache de cart_hash)
 * Usado por refreshCartFromServer() para sincronizar el sidebar tras cambios nativos de WC.
 */
function churrascoplanet_ajax_get_cart_state() {
    if ( function_exists('wc_load_cart') ) wc_load_cart();
    WC()->cart->calculate_totals();

    $data            = churrascoplanet_get_cart_fragments_data();
    $modal           = churrascoplanet_coupon_modal_data();
    $available_count = $modal['coupon_count'];

    $fragments = array(
        '#cartItems'      => $data['cart_items_html'],
        '.cart-count'     => $data['count_html'],
        '.total-amount'   => $data['total_html'],
        '#cartCouponWrap' => $data['coupon_wrap_html'],
        '#couponCount'    => '<span class="coupon-count" id="couponCount"'
            . ( $available_count > 0 ? '' : ' style="display:none"' )
            . '>' . $available_count . '</span>',
    );

    wp_send_json_success( array(
        'fragments'    => $fragments,
        'modal_body'   => $modal['modal_body'],
        'coupon_count' => $available_count,
    ) );
}
add_action('wp_ajax_churrascoplanet_get_cart_state', 'churrascoplanet_ajax_get_cart_state');
add_action('wp_ajax_nopriv_churrascoplanet_get_cart_state', 'churrascoplanet_ajax_get_cart_state');
add_action('wc_ajax_chp_get_cart_state', 'churrascoplanet_ajax_get_cart_state');

/**
 * Forzar carga de scripts AJAX de WooCommerce en todas las páginas del frontend
 * Necesario porque el tema usa botones personalizados que no disparan la carga automática
 */
function churrascoplanet_force_wc_scripts() {
    if (is_admin()) {
        return;
    }
    // Solo asegurarse de que el script está encolado.
    // WooCommerce se encarga de localizar wc_add_to_cart_params automáticamente.
    wp_enqueue_script('wc-add-to-cart');
}
add_action('wp_enqueue_scripts', 'churrascoplanet_force_wc_scripts', 20);

/* ============================================
   CART PAGE — CABECERA VISUAL
   ============================================ */

/**
 * Agrega cabecera de página (badge + título) antes del contenido del carrito.
 * Usa el mismo patrón visual que la plantilla cart-empty.php.
 */
function churrascoplanet_cart_page_header(): void {
    if ( ! is_cart() || WC()->cart->is_empty() ) {
        return;
    }
    ?>
    <div class="cart-page-title">
        <span class="section-badge">
            <i class="fas fa-shopping-cart" aria-hidden="true"></i>
            <?php esc_html_e( 'Mi Pedido', 'churrascoplanet' ); ?>
        </span>
        <h1 class="section-title">
            <?php esc_html_e( 'Carrito de', 'churrascoplanet' ); ?>
            <span class="highlight"><?php esc_html_e( 'Compras', 'churrascoplanet' ); ?></span>
        </h1>
    </div>
    <?php
}
add_action( 'woocommerce_before_cart', 'churrascoplanet_cart_page_header', 5 );

/* ============================================
   MY ACCOUNT CUSTOMIZATIONS
   ============================================ */

/**
 * Quitar la página "Mi cuenta" del menú de navegación principal.
 * El acceso ahora se hace vía el botón "Ingresar / Mi cuenta" del header.
 */
add_filter( 'wp_nav_menu_objects', function ( array $items, object $args ): array {
    if ( 'primary' !== ( $args->theme_location ?? '' ) ) {
        return $items;
    }
    if ( ! function_exists( 'wc_get_page_permalink' ) ) {
        return $items;
    }
    $account_url = rtrim( wc_get_page_permalink( 'myaccount' ), '/' );
    return array_values( array_filter( $items, function ( $item ) use ( $account_url ) {
        return rtrim( $item->url, '/' ) !== $account_url;
    } ) );
}, 10, 2 );

/**
 * Ocultar endpoint "Descargas" del menú Mi Cuenta
 * Para modificar en el futuro: agregar/quitar slugs de este array
 */
function churrascoplanet_remove_my_account_links($items) {
    // Endpoints a ocultar (puedes agregar más aquí)
    $endpoints_to_hide = array(
        'downloads', // Descargas
        // 'edit-address', // Direcciones (descomenta para ocultar)
        // 'payment-methods', // Métodos de pago
    );

    foreach ($endpoints_to_hide as $endpoint) {
        if (isset($items[$endpoint])) {
            unset($items[$endpoint]);
        }
    }

    return $items;
}
add_filter('woocommerce_account_menu_items', 'churrascoplanet_remove_my_account_links');

/**
 * Habilitar registro de clientes en Mi Cuenta
 * También se puede configurar en: WooCommerce > Ajustes > Cuentas y privacidad
 */
function churrascoplanet_enable_registration() {
    // Habilitar registro en página Mi Cuenta (solo si la opción no está ya en 'yes')
    if (get_option('woocommerce_enable_myaccount_registration') !== 'yes') {
        update_option('woocommerce_enable_myaccount_registration', 'yes');
    }

    // Permitir generar nombre de usuario automáticamente desde el email
    if (get_option('woocommerce_registration_generate_username') !== 'yes') {
        update_option('woocommerce_registration_generate_username', 'yes');
    }

    // Permitir generar contraseña automáticamente (el usuario puede cambiarla después)
    // Descomenta si prefieres que WooCommerce genere contraseña automática:
    // update_option('woocommerce_registration_generate_password', 'yes');
}
add_action('init', 'churrascoplanet_enable_registration');

// Filtro para asegurar que el registro esté habilitado
add_filter('woocommerce_enable_myaccount_registration', '__return_true');

/**
 * Agregar campos personalizados al formulario de registro
 * Campos para tienda gastronómica: Nombre, Apellido, Teléfono
 */
function churrascoplanet_register_form_fields() {
    ?>
    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
        <label for="reg_billing_first_name"><?php esc_html_e('Nombre', 'churrascoplanet'); ?> <span class="required">*</span></label>
        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="billing_first_name" id="reg_billing_first_name" autocomplete="given-name" value="<?php echo (!empty($_POST['billing_first_name'])) ? esc_attr(wp_unslash($_POST['billing_first_name'])) : ''; ?>" required />
    </p>

    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
        <label for="reg_billing_last_name"><?php esc_html_e('Apellido', 'churrascoplanet'); ?> <span class="required">*</span></label>
        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="billing_last_name" id="reg_billing_last_name" autocomplete="family-name" value="<?php echo (!empty($_POST['billing_last_name'])) ? esc_attr(wp_unslash($_POST['billing_last_name'])) : ''; ?>" required />
    </p>

    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
        <label for="reg_billing_phone"><?php esc_html_e('Teléfono', 'churrascoplanet'); ?> <span class="required">*</span></label>
        <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="billing_phone" id="reg_billing_phone" autocomplete="tel" placeholder="+56 9 1234 5678" value="<?php echo (!empty($_POST['billing_phone'])) ? esc_attr(wp_unslash($_POST['billing_phone'])) : ''; ?>" required />
    </p>
    <?php
}
add_action('woocommerce_register_form_start', 'churrascoplanet_register_form_fields');

/**
 * Validar campos personalizados del registro
 */
function churrascoplanet_validate_register_fields($errors, $username, $email) {
    if (empty($_POST['billing_first_name'])) {
        $errors->add('billing_first_name_error', __('Por favor ingresa tu nombre.', 'churrascoplanet'));
    }

    if (empty($_POST['billing_last_name'])) {
        $errors->add('billing_last_name_error', __('Por favor ingresa tu apellido.', 'churrascoplanet'));
    }

    if (empty($_POST['billing_phone'])) {
        $errors->add('billing_phone_error', __('Por favor ingresa tu número de teléfono.', 'churrascoplanet'));
    }

    return $errors;
}
add_filter('woocommerce_registration_errors', 'churrascoplanet_validate_register_fields', 10, 3);

/**
 * Guardar campos personalizados al crear usuario
 */
function churrascoplanet_save_register_fields($customer_id) {
    if (isset($_POST['billing_first_name'])) {
        update_user_meta($customer_id, 'billing_first_name', sanitize_text_field($_POST['billing_first_name']));
        update_user_meta($customer_id, 'first_name', sanitize_text_field($_POST['billing_first_name']));
    }

    if (isset($_POST['billing_last_name'])) {
        update_user_meta($customer_id, 'billing_last_name', sanitize_text_field($_POST['billing_last_name']));
        update_user_meta($customer_id, 'last_name', sanitize_text_field($_POST['billing_last_name']));
    }

    if (isset($_POST['billing_phone'])) {
        update_user_meta($customer_id, 'billing_phone', sanitize_text_field($_POST['billing_phone']));
    }
}
add_action('woocommerce_created_customer', 'churrascoplanet_save_register_fields');

/**
 * Personalizar textos del menú Mi Cuenta (traducción al español)
 */
function churrascoplanet_my_account_menu_items($items) {
    $items['dashboard']       = __('Inicio', 'churrascoplanet');
    $items['orders']          = __('Mis Pedidos', 'churrascoplanet');
    $items['edit-address']    = __('Mi Dirección', 'churrascoplanet');
    $items['edit-account']    = __('Mis Datos', 'churrascoplanet');
    $items['customer-logout'] = __('Cerrar Sesión', 'churrascoplanet');

    return $items;
}
add_filter('woocommerce_account_menu_items', 'churrascoplanet_my_account_menu_items', 20);

/**
 * Agregar toggle button y script para menú móvil en Mi Cuenta
 */
function churrascoplanet_my_account_mobile_toggle() {
    if (!is_account_page() || !is_user_logged_in()) {
        return;
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var navigation = document.querySelector('.woocommerce-MyAccount-navigation');
        if (!navigation) return;

        var ul = navigation.querySelector('ul');
        if (!ul) return;

        // Crear botón toggle
        var toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'woocommerce-MyAccount-navigation-toggle';
        toggleBtn.innerHTML = '<i class="fas fa-bars"></i> Menú de Mi Cuenta';

        // Insertar antes del ul
        navigation.insertBefore(toggleBtn, ul);

        // Toggle functionality
        toggleBtn.addEventListener('click', function() {
            ul.classList.toggle('show');
            toggleBtn.classList.toggle('active');
        });

        // Cerrar al hacer click en un link
        ul.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    ul.classList.remove('show');
                    toggleBtn.classList.remove('active');
                }
            });
        });
    });
    </script>
    <?php
}
add_action('wp_footer', 'churrascoplanet_my_account_mobile_toggle');

/**
 * Eliminar el dashboard por defecto de WooCommerce.
 * El plugin churrascoplanet-core inyecta su propio dashboard completo
 * (bienvenida + estadísticas + acciones rápidas) via custom_dashboard_content().
 */
remove_action( 'woocommerce_account_dashboard', 'woocommerce_account_dashboard' );

/* ============================================
   MY ACCOUNT — CAMPOS DE DIRECCIÓN DE ENVÍO
   ============================================ */

/**
 * Personalizar campos de dirección de envío para coherencia con el checkout.
 *
 * - Labels en español consistentes con los del checkout
 * - Nombre + Apellidos en la misma fila
 * - País no requerido (lo fijamos en CL vía el template)
 * - Código postal no requerido (lo rellena el autocomplete)
 */
add_filter( 'woocommerce_shipping_fields', 'churrascoplanet_customize_shipping_fields', 20 );
function churrascoplanet_customize_shipping_fields( array $fields ): array {
    // Nombre — primera columna
    if ( isset( $fields['shipping_first_name'] ) ) {
        $fields['shipping_first_name']['label']       = __( 'Nombre', 'churrascoplanet' );
        $fields['shipping_first_name']['placeholder'] = __( 'Tu nombre', 'churrascoplanet' );
        $fields['shipping_first_name']['class']       = array( 'form-row-first' );
    }

    // Apellidos — segunda columna
    if ( isset( $fields['shipping_last_name'] ) ) {
        $fields['shipping_last_name']['label']       = __( 'Apellidos', 'churrascoplanet' );
        $fields['shipping_last_name']['placeholder'] = __( 'Tus apellidos', 'churrascoplanet' );
        $fields['shipping_last_name']['class']       = array( 'form-row-last' );
    }

    // Empresa — ocultar (no aplica para delivery)
    if ( isset( $fields['shipping_company'] ) ) {
        $fields['shipping_company']['class'] = array( 'hidden' );
        $fields['shipping_company']['required'] = false;
    }

    // Dirección línea 2 (Depto, piso...)
    if ( isset( $fields['shipping_address_2'] ) ) {
        $fields['shipping_address_2']['label']       = __( 'Departamento / Piso', 'churrascoplanet' );
        $fields['shipping_address_2']['placeholder'] = __( 'Depto., piso, oficina (opcional)', 'churrascoplanet' );
    }

    // Ciudad / Comuna
    if ( isset( $fields['shipping_city'] ) ) {
        $fields['shipping_city']['label']       = __( 'Comuna / Ciudad', 'churrascoplanet' );
        $fields['shipping_city']['placeholder'] = __( 'Ej: Maipú', 'churrascoplanet' );
    }

    // Región
    if ( isset( $fields['shipping_state'] ) ) {
        $fields['shipping_state']['label'] = __( 'Región', 'churrascoplanet' );
    }

    // País — no requerido; se fija a CL en el template
    if ( isset( $fields['shipping_country'] ) ) {
        $fields['shipping_country']['required'] = false;
    }

    // Código postal — no requerido; se llena automáticamente
    if ( isset( $fields['shipping_postcode'] ) ) {
        $fields['shipping_postcode']['required'] = false;
    }

    return $fields;
}

/**
 * Encolar el JS de autocompletado de dirección solo en la página de edición
 * de la dirección de envío en Mi Cuenta.
 */
add_action( 'wp_enqueue_scripts', 'churrascoplanet_enqueue_address_autocomplete', 20 );
function churrascoplanet_enqueue_address_autocomplete(): void {
    if ( ! is_account_page() || ! is_user_logged_in() ) {
        return;
    }

    global $wp_query;

    // Solo en /mi-cuenta/edit-address/shipping/ (billing ya está bloqueado)
    $address_type = $wp_query->query_vars['edit-address'] ?? '';
    if ( empty( $address_type ) || 'billing' === $address_type ) {
        return;
    }

    wp_enqueue_script(
        'chp-address-autocomplete',
        get_template_directory_uri() . '/js/chp-address-autocomplete.js',
        array( 'jquery' ),
        CHURRASCOPLANET_VERSION,
        true
    );

    wp_localize_script( 'chp-address-autocomplete', 'chpAddressEdit', array(
        'nominatimUrl' => 'https://nominatim.openstreetmap.org',
        'countryCode'  => 'cl',
        'strings'      => array(
            'searching' => __( 'Buscando...', 'churrascoplanet' ),
            'noResults' => __( 'No se encontraron resultados.', 'churrascoplanet' ),
            'connError' => __( 'Error de conexión. Intenta nuevamente.', 'churrascoplanet' ),
        ),
    ) );
}

/* ============================================
   MY ACCOUNT — SEGURIDAD Y REDIRECCIONES
   ============================================ */

/**
 * Proteger /mi-cuenta/ para usuarios no logueados.
 *
 * Si alguien escribe la URL directamente sin estar logueado,
 * es redirigido al inicio de la tienda (no al formulario de login de WC).
 * El login se realiza exclusivamente mediante el modal del header.
 */
add_action( 'template_redirect', 'churrascoplanet_redirect_myaccount_guests', 1 );
function churrascoplanet_redirect_myaccount_guests(): void {
    if ( is_account_page() && ! is_user_logged_in() ) {
        wp_safe_redirect( home_url( '/' ), 302 );
        exit;
    }
}

/**
 * Bloquear acceso directo a /mi-cuenta/edit-address/billing/
 *
 * La dirección de facturación no es relevante para delivery.
 * Solo se expone la dirección de envío. Si alguien accede
 * por URL directa al formulario de billing, se redirige a shipping.
 */
add_action( 'template_redirect', 'churrascoplanet_block_billing_address_form', 10 );
function churrascoplanet_block_billing_address_form(): void {
    if ( ! is_account_page() || ! is_user_logged_in() ) {
        return;
    }

    global $wp_query;
    $address_type = $wp_query->query_vars['edit-address'] ?? '';

    if ( 'billing' === $address_type ) {
        wp_safe_redirect( wc_get_endpoint_url( 'edit-address', 'shipping', wc_get_page_permalink( 'myaccount' ) ), 302 );
        exit;
    }
}

/**
 * Redirigir al inicio de la tienda al cerrar sesión.
 *
 * Cubre tanto el logout de WooCommerce como el logout estándar de WordPress.
 */
add_filter( 'woocommerce_logout_redirect', 'churrascoplanet_logout_redirect' );
add_filter( 'logout_redirect', 'churrascoplanet_logout_redirect' );
function churrascoplanet_logout_redirect(): string {
    return home_url( '/' );
}

/* ============================================
   CHECKOUT CUSTOMIZATIONS
   ============================================ */

/**
 * Renombrar "Detalles de facturación" → "Datos de entrega"
 * y otros textos del checkout para contexto de delivery de comida
 */
function churrascoplanet_checkout_texts($translated_text, $text, $domain) {
    if ($domain !== 'woocommerce') {
        return $translated_text;
    }

    switch ($text) {
        case 'Billing details':
            return __('Datos de entrega', 'churrascoplanet');
        case 'Ship to a different address?':
            return '';
        case 'Additional information':
            return __('Notas del pedido', 'churrascoplanet');
    }

    return $translated_text;
}
add_filter('gettext', 'churrascoplanet_checkout_texts', 10, 3);

/**
 * Ocultar sección "Enviar a una dirección diferente"
 * En delivery de comida, la dirección de entrega viene del modal
 */
add_filter('woocommerce_ship_to_different_address_checked', '__return_false');

/**
 * Acortar el nombre del método de envío en el checkout
 * "Delivery Express (Uber) (Churrasco Planet Maipu)" → "Gasto de envío"
 */
function churrascoplanet_short_shipping_label($label, $method) {
    $label = preg_replace('/:.+$/', '', $label);
    return 'Gasto de envío: ' . wc_price($method->cost);
}
add_filter('woocommerce_cart_shipping_method_full_label', 'churrascoplanet_short_shipping_label', 10, 2);

/**
 * Cambiar placeholder de los campos del checkout
 */
function churrascoplanet_checkout_field_placeholders($fields) {
    // Billing fields
    if (isset($fields['billing']['billing_first_name'])) {
        $fields['billing']['billing_first_name']['placeholder'] = __('Tu nombre', 'churrascoplanet');
    }
    if (isset($fields['billing']['billing_last_name'])) {
        $fields['billing']['billing_last_name']['placeholder'] = __('Tu apellido', 'churrascoplanet');
    }
    if (isset($fields['billing']['billing_phone'])) {
        $fields['billing']['billing_phone']['placeholder'] = __('+56 9 1234 5678', 'churrascoplanet');
    }
    if (isset($fields['billing']['billing_email'])) {
        $fields['billing']['billing_email']['placeholder'] = __('correo@ejemplo.com', 'churrascoplanet');
    }
    if (isset($fields['billing']['billing_address_1'])) {
        $fields['billing']['billing_address_1']['placeholder'] = __('Calle y número', 'churrascoplanet');
    }
    if (isset($fields['billing']['billing_address_2'])) {
        $fields['billing']['billing_address_2']['placeholder'] = __('Depto, oficina, etc. (opcional)', 'churrascoplanet');
    }
    if (isset($fields['billing']['billing_city'])) {
        $fields['billing']['billing_city']['placeholder'] = __('Ciudad', 'churrascoplanet');
    }
    if (isset($fields['billing']['billing_postcode'])) {
        $fields['billing']['billing_postcode']['placeholder'] = __('Código postal', 'churrascoplanet');
    }

    // Order notes
    if (isset($fields['order']['order_comments'])) {
        $fields['order']['order_comments']['placeholder'] = __('Instrucciones especiales para tu pedido (alergias, preferencias de cocción, etc.)', 'churrascoplanet');
    }

    return $fields;
}
add_filter('woocommerce_checkout_fields', 'churrascoplanet_checkout_field_placeholders');

/**
 * Cambiar textos de secciones del checkout
 */
function churrascoplanet_checkout_billing_title($title) {
    return __('Datos de Contacto', 'churrascoplanet');
}
add_filter('woocommerce_checkout_show_terms', '__return_true');

/**
 * Personalizar texto del botón de finalizar compra
 */
function churrascoplanet_checkout_button_text($text) {
    return __('Confirmar Pedido', 'churrascoplanet');
}
add_filter('woocommerce_order_button_text', 'churrascoplanet_checkout_button_text');

/**
 * Mover cupón al resumen del pedido (quitar del tope del checkout)
 */
function churrascoplanet_remove_default_coupon() {
    remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10);
}
add_action('init', 'churrascoplanet_remove_default_coupon');

/**
 * Renderizar cupón dentro del Order Review (como fila válida de la tabla)
 */
function churrascoplanet_coupon_in_order_review() {
    if (!wc_coupons_enabled()) {
        return;
    }
    ?>
    <tr class="wccp-coupon-row">
        <td colspan="2">
            <div class="wccp-coupon-review">
                <button type="button" class="wccp-coupon-toggle">
                    <span><i class="fas fa-tag"></i> <?php esc_html_e('¿Tienes un cupón?', 'churrascoplanet'); ?></span>
                    <i class="fas fa-chevron-down wccp-coupon-arrow"></i>
                </button>
                <div class="wccp-coupon-form">
                    <div class="wccp-coupon-input-wrap">
                        <input type="text" class="wccp-coupon-code" placeholder="<?php esc_attr_e('Código de descuento', 'churrascoplanet'); ?>" />
                        <button type="button" class="wccp-coupon-apply"><?php esc_html_e('Aplicar', 'churrascoplanet'); ?></button>
                    </div>
                    <div class="wccp-coupon-msg"></div>
                </div>
            </div>
        </td>
    </tr>
    <?php
}
add_action('woocommerce_review_order_after_order_total', 'churrascoplanet_coupon_in_order_review');

/**
 * JS para cupón en order review + lista desplegable de productos (>5 items)
 */
function churrascoplanet_checkout_order_review_scripts() {
    if (!is_checkout()) {
        return;
    }
    ?>
    <script>
    jQuery(function($) {
        /* --- Cupón Toggle --- */
        $(document).on('click', '.wccp-coupon-toggle', function() {
            $(this).next('.wccp-coupon-form').slideToggle(200);
            $(this).find('.wccp-coupon-arrow').toggleClass('rotated');
        });

        /* --- Aplicar Cupón via AJAX --- */
        $(document).on('click', '.wccp-coupon-apply', function() {
            var $btn = $(this);
            var $wrap = $btn.closest('.wccp-coupon-review');
            var code = $wrap.find('.wccp-coupon-code').val().trim();
            var $msg = $wrap.find('.wccp-coupon-msg');

            if (!code) {
                $msg.html('<span class="wccp-error">Ingresa un código</span>');
                return;
            }

            $btn.prop('disabled', true).text('...');

            $.ajax({
                url: wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'),
                type: 'POST',
                data: {
                    coupon_code: code,
                    security: wc_checkout_params.apply_coupon_nonce
                },
                success: function(response) {
                    $msg.html(response);
                    $wrap.find('.wccp-coupon-code').val('');
                    $('body').trigger('update_checkout');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Aplicar');
                }
            });
        });

        /* Enter key en input cupón */
        $(document).on('keypress', '.wccp-coupon-code', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $(this).closest('.wccp-coupon-input-wrap').find('.wccp-coupon-apply').trigger('click');
            }
        });

        /* --- Lista desplegable de productos (>5 ítems) --- */
        var maxVisible = 5;

        function initCollapsibleProducts() {
            var $table = $('.woocommerce-checkout-review-order-table');
            var $tbody = $table.find('tbody');
            var $rows = $tbody.find('tr:not(.wccp-products-toggle)');

            /* Limpiar toggle anterior */
            $table.find('.wccp-products-toggle').remove();
            $rows.show();

            if ($rows.length > maxVisible) {
                $rows.slice(maxVisible).hide();

                var $toggleRow = $(
                    '<tr class="wccp-products-toggle"><td colspan="2">' +
                    '<button type="button" class="wccp-show-all">' +
                    'Ver todos (' + $rows.length + ' productos) <i class="fas fa-chevron-down"></i>' +
                    '</button></td></tr>'
                );

                $tbody.append($toggleRow);

                $toggleRow.find('.wccp-show-all').on('click', function() {
                    var $btn = $(this);
                    if ($btn.hasClass('expanded')) {
                        $rows.slice(maxVisible).slideUp(200);
                        $btn.removeClass('expanded')
                            .html('Ver todos (' + $rows.length + ' productos) <i class="fas fa-chevron-down"></i>');
                    } else {
                        $rows.slice(maxVisible).slideDown(200);
                        $btn.addClass('expanded')
                            .html('Ver menos <i class="fas fa-chevron-up"></i>');
                    }
                });
            }
        }

        /* Inicializar al cargar y después de cada actualización del checkout */
        initCollapsibleProducts();
        $(document.body).on('updated_checkout', initCollapsibleProducts);
    });
    </script>
    <?php
}
add_action('wp_footer', 'churrascoplanet_checkout_order_review_scripts');

/**
 * Remover campos innecesarios del checkout (opcional)
 * Descomenta los campos que quieras remover
 */
function churrascoplanet_remove_checkout_fields($fields) {
    // Remover campos que no son necesarios para delivery de comida
    // unset($fields['billing']['billing_company']); // Empresa
    // unset($fields['billing']['billing_postcode']); // Código postal
    // unset($fields['billing']['billing_state']); // Región

    // Hacer opcional el código postal para Chile
    if (isset($fields['billing']['billing_postcode'])) {
        $fields['billing']['billing_postcode']['required'] = false;
    }

    return $fields;
}
add_filter('woocommerce_checkout_fields', 'churrascoplanet_remove_checkout_fields', 20);

/**
 * Ordenar campos del checkout
 */
function churrascoplanet_checkout_field_order($fields) {
    // Orden de campos de facturación
    if (isset($fields['billing'])) {
        $fields['billing']['billing_first_name']['priority'] = 10;
        $fields['billing']['billing_last_name']['priority'] = 20;
        $fields['billing']['billing_phone']['priority'] = 30;
        $fields['billing']['billing_email']['priority'] = 40;
        $fields['billing']['billing_country']['priority'] = 50;
        $fields['billing']['billing_address_1']['priority'] = 60;
        $fields['billing']['billing_address_2']['priority'] = 70;
        $fields['billing']['billing_city']['priority'] = 80;
        $fields['billing']['billing_state']['priority'] = 90;
        $fields['billing']['billing_postcode']['priority'] = 100;
    }

    return $fields;
}
add_filter('woocommerce_checkout_fields', 'churrascoplanet_checkout_field_order', 30);

/**
 * Agregar clases CSS personalizadas a campos del checkout
 */
function churrascoplanet_checkout_field_classes($fields) {
    // Hacer que nombre y apellido estén en la misma fila
    if (isset($fields['billing']['billing_first_name'])) {
        $fields['billing']['billing_first_name']['class'] = array('form-row-first');
    }
    if (isset($fields['billing']['billing_last_name'])) {
        $fields['billing']['billing_last_name']['class'] = array('form-row-last');
    }

    // Teléfono y email en la misma fila
    if (isset($fields['billing']['billing_phone'])) {
        $fields['billing']['billing_phone']['class'] = array('form-row-first');
    }
    if (isset($fields['billing']['billing_email'])) {
        $fields['billing']['billing_email']['class'] = array('form-row-last');
    }

    return $fields;
}
add_filter('woocommerce_checkout_fields', 'churrascoplanet_checkout_field_classes', 40);

/**
 * Personalizar mensaje de pedido recibido (Thank You page)
 */
function churrascoplanet_thankyou_message($message, $order) {
    if ($order) {
        $first_name = $order->get_billing_first_name();
        return sprintf(
            __('¡Gracias %s! Tu pedido ha sido recibido y está siendo preparado. Te notificaremos cuando esté en camino.', 'churrascoplanet'),
            '<strong>' . esc_html($first_name) . '</strong>'
        );
    }
    return $message;
}
add_filter('woocommerce_thankyou_order_received_text', 'churrascoplanet_thankyou_message', 10, 2);
