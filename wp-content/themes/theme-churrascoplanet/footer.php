<?php
/**
 * ChurrascoPlanet - Footer Template
 *
 * @package ChurrascoPlanet
 */
?>

<!-- Footer -->
<footer id="contacto" class="footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <?php if (has_custom_logo()) : ?>
                    <?php the_custom_logo(); ?>
                <?php else : ?>
                    <img src="<?php echo CHURRASCOPLANET_URI; ?>/img/logo.png" alt="<?php bloginfo('name'); ?>" class="footer-logo">
                <?php endif; ?>
                <p><?php bloginfo('description'); ?></p>
                <div class="social-links">
                    <?php if (get_theme_mod('churrascoplanet_facebook')) : ?>
                        <a href="<?php echo esc_url(get_theme_mod('churrascoplanet_facebook')); ?>" target="_blank"><i class="fab fa-facebook-f"></i></a>
                    <?php endif; ?>
                    <?php if (get_theme_mod('churrascoplanet_instagram')) : ?>
                        <a href="<?php echo esc_url(get_theme_mod('churrascoplanet_instagram')); ?>" target="_blank"><i class="fab fa-instagram"></i></a>
                    <?php endif; ?>
                    <?php if (get_theme_mod('churrascoplanet_tiktok')) : ?>
                        <a href="<?php echo esc_url(get_theme_mod('churrascoplanet_tiktok')); ?>" target="_blank"><i class="fab fa-tiktok"></i></a>
                    <?php endif; ?>
                    <?php if (get_theme_mod('churrascoplanet_whatsapp')) : ?>
                        <a href="https://wa.me/<?php echo esc_attr(get_theme_mod('churrascoplanet_whatsapp')); ?>" target="_blank"><i class="fab fa-whatsapp"></i></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="footer-links">
                <h4><?php _e('Menu', 'churrascoplanet'); ?></h4>
                <?php
                wp_nav_menu(array(
                    'theme_location' => 'footer',
                    'container'      => false,
                    'fallback_cb'    => false,
                ));
                ?>
            </div>

            <div class="footer-links">
                <h4><?php _e('Categorias', 'churrascoplanet'); ?></h4>
                <?php if (class_exists('WooCommerce')) : ?>
                <ul>
                    <?php
                    $categories = get_terms(array(
                        'taxonomy'   => 'product_cat',
                        'hide_empty' => true,
                        'number'     => 5,
                    ));
                    foreach ($categories as $cat) :
                    ?>
                    <li><a href="<?php echo get_term_link($cat); ?>"><?php echo esc_html($cat->name); ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="footer-contact">
                <h4><?php _e('Contacto', 'churrascoplanet'); ?></h4>
                <?php if (get_theme_mod('churrascoplanet_hours')) : ?>
                    <p><i class="fas fa-clock"></i> <?php echo esc_html(get_theme_mod('churrascoplanet_hours')); ?></p>
                <?php endif; ?>
                <p><i class="fas fa-map-marker-alt"></i> Santiago, Chile</p>
                <?php if (get_theme_mod('churrascoplanet_whatsapp')) : ?>
                    <p><i class="fab fa-whatsapp"></i> WhatsApp por local</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. <?php _e('Todos los derechos reservados.', 'churrascoplanet'); ?></p>
            <p><?php _e('Desarrollado por', 'churrascoplanet'); ?> <a href="https://www.zentryx.cl" target="_blank">Zentryx SPA</a> <i class="fas fa-rocket"></i></p>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
