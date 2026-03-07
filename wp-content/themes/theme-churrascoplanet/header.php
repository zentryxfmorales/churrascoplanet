<?php
/**
 * ChurrascoPlanet - Header Template
 *
 * @package ChurrascoPlanet
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<!-- Stars Background -->
<div class="stars-container">
    <div id="stars"></div>
    <div id="stars2"></div>
    <div id="stars3"></div>
</div>

<!-- Header -->
<header class="header">
    <div class="container">
        <nav class="navbar">
            <div class="logo">
                <?php if (has_custom_logo()) : ?>
                    <?php the_custom_logo(); ?>
                <?php else : ?>
                    <a href="<?php echo esc_url(home_url('/')); ?>">
                        <img src="<?php echo CHURRASCOPLANET_URI; ?>/img/logo.png" alt="<?php bloginfo('name'); ?>">
                    </a>
                <?php endif; ?>
            </div>

            <?php
            if (has_nav_menu('primary')) {
                wp_nav_menu(array(
                    'theme_location' => 'primary',
                    'container'      => false,
                    'menu_class'     => 'nav-menu',
                    'fallback_cb'    => false,
                    'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
                    'link_before'    => '<span class="nav-link">',
                    'link_after'     => '</span>',
                ));
            } else {
                // Menú desde el panel "Aspecto PlanetaChurrascos"
                $home_url = home_url('/');
                $is_front_page = is_front_page();
                $nav_items = churrascoplanet_get_option('nav_menu_items', array(
                    array('texto' => 'Inicio', 'url' => '#inicio', 'icon' => '', 'target' => '', 'visible' => '1'),
                    array('texto' => 'Menú', 'url' => '#menu', 'icon' => '', 'target' => '', 'visible' => '1'),
                    array('texto' => 'Promociones', 'url' => '#promociones', 'icon' => '', 'target' => '', 'visible' => '1'),
                    array('texto' => 'Locales', 'url' => '#locales', 'icon' => '', 'target' => '', 'visible' => '1'),
                    array('texto' => 'Tienda', 'url' => '/tienda', 'icon' => '', 'target' => '', 'visible' => '1'),
                ));
            ?>
            <ul class="nav-menu">
                <?php foreach ($nav_items as $nav_item) :
                    if (empty($nav_item['visible']) || $nav_item['visible'] === '0') continue;

                    $item_url = $nav_item['url'] ?? '#';
                    $item_text = $nav_item['texto'] ?? '';
                    $item_icon = $nav_item['icon'] ?? '';
                    $item_target = $nav_item['target'] ?? '';
                    $is_anchor = strpos($item_url, '#') === 0;

                    // Construir URL correcta
                    if ($is_anchor && !$is_front_page) {
                        $item_url = $home_url . $item_url;
                    } elseif (!$is_anchor && strpos($item_url, 'http') !== 0 && strpos($item_url, '/') === 0) {
                        $item_url = home_url($item_url);
                    }

                    // Determinar si está activo
                    $is_active = false;
                    if ($is_anchor && $is_front_page && $nav_item['url'] === '#inicio') {
                        $is_active = true;
                    } elseif (!$is_anchor) {
                        $current_url = rtrim($_SERVER['REQUEST_URI'], '/');
                        $nav_path = rtrim(parse_url($item_url, PHP_URL_PATH) ?: '', '/');
                        if ($current_url === $nav_path) {
                            $is_active = true;
                        }
                    }
                ?>
                <li>
                    <a href="<?php echo esc_url($item_url); ?>"
                       class="nav-link<?php echo $is_active ? ' active' : ''; ?>"
                       <?php echo $item_target ? 'target="' . esc_attr($item_target) . '"' : ''; ?>>
                        <?php if ($item_icon) : ?><i class="<?php echo esc_attr($item_icon); ?>"></i> <?php endif; ?>
                        <?php echo esc_html($item_text); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php
            }
            ?>

            <div class="nav-actions">
                <?php
                // Obtener ubicación guardada del plugin WCUDC
                $delivery_text = __('Delivery', 'churrascoplanet');
                $delivery_icon = 'fa-motorcycle';
                $has_location = false;

                if (class_exists('WCUDC_Location_Modal')) {
                    $location = WCUDC_Location_Modal::get_location();
                    if (!empty($location['lat'])) {
                        $has_location = true;
                        $delivery_type = $location['delivery_type'] ?? 'delivery';
                        if ($delivery_type === 'pickup' && !empty($location['store_name'])) {
                            $delivery_text = sprintf(__('Retiro: %s', 'churrascoplanet'), $location['store_name']);
                            $delivery_icon = 'fa-store';
                        } elseif (!empty($location['address'])) {
                            // Limitar a 25 caracteres
                            $address = $location['address'];
                            if (strlen($address) > 25) {
                                $address = substr($address, 0, 22) . '...';
                            }
                            $delivery_text = $address;
                            $delivery_icon = 'fa-map-marker-alt';
                        }
                    }
                }
                ?>
                <?php $is_checkout_page = function_exists( 'is_checkout' ) && is_checkout(); ?>
                <button class="btn-delivery <?php echo $has_location ? 'has-location' : ''; ?> <?php echo $is_checkout_page ? 'btn-delivery-disabled' : ''; ?>"
                        id="wcudc-open-location-modal"
                        <?php echo $is_checkout_page ? 'disabled' : ''; ?>>
                    <i class="fas <?php echo esc_attr($delivery_icon); ?>"></i>
                    <span><?php echo esc_html($delivery_text); ?></span>
                </button>

                <?php if ( is_user_logged_in() ) :
                    $current_user    = wp_get_current_user();
                    $display_name    = $current_user->first_name ?: $current_user->display_name;
                    $display_name    = $display_name ?: __( 'Mi cuenta', 'churrascoplanet' );
                    // Limitar a 15 caracteres para que no desborde
                    if ( mb_strlen( $display_name ) > 15 ) {
                        $display_name = mb_substr( $display_name, 0, 13 ) . '…';
                    }
                ?>
                <a href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url() ); ?>"
                   class="btn-account btn-account--in"
                   aria-label="<?php esc_attr_e( 'Mi cuenta', 'churrascoplanet' ); ?>">
                    <i class="fas fa-user"></i>
                    <span><?php echo esc_html( $display_name ); ?></span>
                </a>
                <?php else : ?>
                <button class="btn-account btn-account--out"
                        id="btn-open-auth-modal"
                        aria-label="<?php esc_attr_e( 'Ingresar', 'churrascoplanet' ); ?>">
                    <i class="fas fa-user"></i>
                    <span><?php esc_html_e( 'Ingresar', 'churrascoplanet' ); ?></span>
                </button>
                <?php endif; ?>

                <?php if (class_exists('WooCommerce') && wc_coupons_enabled()) :
                    $chp_total_coupons   = (int) ( wp_count_posts('shop_coupon')->publish ?? 0 );
                    $chp_applied_coupons = class_exists('WooCommerce') ? count( WC()->cart->get_applied_coupons() ) : 0;
                    $chp_coupon_count    = max( 0, $chp_total_coupons - $chp_applied_coupons );
                ?>
                <button class="btn-coupons" id="openCouponModal" title="<?php esc_attr_e('Ver cupones', 'churrascoplanet'); ?>">
                    <i class="fas fa-ticket-alt"></i>
                    <span class="btn-coupons-label"><?php _e('Cupones', 'churrascoplanet'); ?></span>
                    <span class="coupon-count" id="couponCount"<?php echo $chp_coupon_count > 0 ? '' : ' style="display:none"'; ?>><?php echo $chp_coupon_count; ?></span>
                </button>
                <?php endif; ?>

                <?php if (class_exists('WooCommerce') && !is_cart() && !is_checkout()) : ?>
                <button class="btn-cart" id="openCart">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="cart-count"><?php echo WC()->cart->get_cart_contents_count(); ?></span>
                </button>
                <?php endif; ?>

                <button class="hamburger" id="hamburger">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </nav>
    </div>
</header>
