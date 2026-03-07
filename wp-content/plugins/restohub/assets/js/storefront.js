/**
 * RestoHub - Storefront JS
 *
 * Funcionalidades del frontend estilo app de delivery
 */

(function($) {
    'use strict';

    // Configuración desde PHP
    const config = window.restoHubStorefront || {};
    const strings = config.strings || {};

    /**
     * Inicialización
     */
    $(document).ready(function() {
        initAddToCart();
        initFloatingCart();
        initCartDrawer();
        initCategoryNav();
        initQuantityButtons();
    });

    /**
     * Add to Cart con AJAX
     */
    function initAddToCart() {
        $(document).on('click', '.restohub-add-to-cart-btn:not(.restohub-view-options)', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const $btn = $(this);
            const productId = $btn.data('product-id');
            const productName = $btn.data('product-name');

            if ($btn.hasClass('loading') || $btn.hasClass('added')) {
                return;
            }

            $btn.addClass('loading');

            $.ajax({
                url: wc_add_to_cart_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'woocommerce_ajax_add_to_cart',
                    product_id: productId,
                    quantity: 1
                },
                success: function(response) {
                    if (response.error && response.product_url) {
                        window.location = response.product_url;
                        return;
                    }

                    $btn.removeClass('loading').addClass('added');
                    $btn.find('.restohub-add-icon').text('✓');

                    // Mostrar toast
                    showToast('✓ ' + (strings.addedToCart || 'Agregado al carrito'));

                    // Actualizar fragmentos del carrito
                    $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);

                    // Resetear botón después de 2 segundos
                    setTimeout(function() {
                        $btn.removeClass('added');
                        $btn.find('.restohub-add-icon').text('+');
                    }, 2000);
                },
                error: function() {
                    $btn.removeClass('loading');
                    showToast('❌ Error al agregar');
                }
            });
        });

        // Actualizar UI cuando se agregan productos
        $(document.body).on('added_to_cart', function(event, fragments) {
            updateCartFragments(fragments);
        });
    }

    /**
     * Floating Cart
     */
    function initFloatingCart() {
        const $floatingCart = $('#restohub-floating-cart');

        // Actualizar visibilidad basado en contenido del carrito
        $(document.body).on('added_to_cart removed_from_cart', function() {
            updateFloatingCartVisibility();
        });

        // Click en el floating cart puede abrir drawer
        $floatingCart.on('click', '.restohub-floating-cart-btn', function(e) {
            // Si está en móvil, abrir drawer en lugar de ir al carrito
            if (window.innerWidth <= 768) {
                e.preventDefault();
                openCartDrawer();
            }
        });
    }

    /**
     * Actualiza la visibilidad del floating cart
     */
    function updateFloatingCartVisibility() {
        const $floatingCart = $('#restohub-floating-cart');
        const count = parseInt($('#restohub-cart-count').text()) || 0;

        if (count > 0) {
            $floatingCart.addClass('has-items').removeClass('empty');
        } else {
            $floatingCart.removeClass('has-items').addClass('empty');
        }
    }

    /**
     * Cart Drawer
     */
    function initCartDrawer() {
        const $drawer = $('#restohub-cart-drawer');

        // Cerrar drawer
        $(document).on('click', '.restohub-cart-drawer-close, .restohub-cart-drawer-overlay', function() {
            closeCartDrawer();
        });

        // Cerrar con Escape
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $drawer.hasClass('open')) {
                closeCartDrawer();
            }
        });

        // Actualizar drawer cuando cambia el carrito
        $(document.body).on('added_to_cart removed_from_cart updated_cart_totals', function() {
            refreshCartDrawer();
        });
    }

    /**
     * Abre el drawer del carrito
     */
    function openCartDrawer() {
        $('#restohub-cart-drawer').addClass('open');
        $('body').addClass('restohub-drawer-open');
    }

    /**
     * Cierra el drawer del carrito
     */
    function closeCartDrawer() {
        $('#restohub-cart-drawer').removeClass('open');
        $('body').removeClass('restohub-drawer-open');
    }

    /**
     * Refresca el contenido del drawer
     */
    function refreshCartDrawer() {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'restohub_get_mini_cart',
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#restohub-cart-drawer-items').html(response.data.items_html);
                    $('#restohub-drawer-subtotal').html(response.data.subtotal);
                    $('#restohub-drawer-total').html(response.data.total);
                }
            }
        });
    }

    /**
     * Navegación por categorías sticky
     */
    function initCategoryNav() {
        const $nav = $('#restohub-category-nav');
        const $navInner = $nav.find('.restohub-category-nav-inner');

        // Scroll horizontal con rueda del mouse
        $navInner.on('wheel', function(e) {
            if (e.originalEvent.deltaY !== 0) {
                e.preventDefault();
                this.scrollLeft += e.originalEvent.deltaY;
            }
        });

        // Scroll suave a la categoría activa
        const $activeItem = $nav.find('.restohub-category-item.active');
        if ($activeItem.length) {
            const navWidth = $navInner.width();
            const itemLeft = $activeItem.position().left;
            const itemWidth = $activeItem.outerWidth();

            if (itemLeft + itemWidth > navWidth || itemLeft < 0) {
                $navInner.scrollLeft(itemLeft - (navWidth / 2) + (itemWidth / 2));
            }
        }

        // Scroll to section cuando se hace click (si estamos en página de tienda)
        if ($('body').hasClass('post-type-archive-product') || $('body').hasClass('tax-product_cat')) {
            // Opcional: implementar scroll suave a secciones de categorías
        }
    }

    /**
     * Botones de cantidad e íconos de eliminar en el drawer.
     *
     * Usa event delegation sobre document para que los listeners sobrevivan
     * los re-renders AJAX del contenido del drawer.
     */
    function initQuantityButtons() {
        // Incrementar cantidad
        $(document).on('click', '.restohub-qty-plus', function() {
            const $item = $(this).closest('.restohub-cart-item');
            const key = $(this).data('key');
            updateCartItemQuantity(key, 'increase', $item);
        });

        // Decrementar cantidad (elimina si llega a 0)
        $(document).on('click', '.restohub-qty-minus', function() {
            const $item = $(this).closest('.restohub-cart-item');
            const key = $(this).data('key');
            const currentQty = parseInt($item.find('.restohub-qty-value').text());

            if (currentQty <= 1) {
                removeCartItem(key, $item);
            } else {
                updateCartItemQuantity(key, 'decrease', $item);
            }
        });

        // Botón eliminar (ícono basurero) — delegado para sobrevivir re-renders AJAX
        $(document).on('click', '.restohub-remove-item', function(e) {
            e.preventDefault();
            const $item = $(this).closest('.restohub-cart-item');
            const key = $(this).data('key');
            if (key) {
                removeCartItem(key, $item);
            }
        });
    }

    /**
     * Actualiza la cantidad de un item en el carrito
     */
    function updateCartItemQuantity(cartItemKey, action, $item) {
        const currentQty = parseInt($item.find('.restohub-qty-value').text());
        const newQty = action === 'increase' ? currentQty + 1 : currentQty - 1;

        $item.addClass('updating');

        $.ajax({
            url: wc_cart_params.ajax_url,
            type: 'POST',
            data: {
                action: 'woocommerce_update_cart',
                cart_item_key: cartItemKey,
                quantity: newQty,
                security: wc_cart_params.update_cart_nonce || ''
            },
            success: function() {
                // Actualizar cantidad visual
                $item.find('.restohub-qty-value').text(newQty);
                $item.removeClass('updating');

                // Refrescar totales
                $(document.body).trigger('updated_cart_totals');
                refreshCartDrawer();
            },
            error: function() {
                $item.removeClass('updating');
                showToast('❌ Error al actualizar');
            }
        });
    }

    /**
     * Elimina un item del carrito via el endpoint nativo de WooCommerce.
     *
     * Usa ?wc-ajax=remove_from_cart (GET) que WooCommerce registra en
     * WC_AJAX y no requiere nonce adicional. Después de la eliminación
     * dispara wc_fragment_refresh para que todos los fragmentos del carrito
     * (badge, totales, drawer) se actualicen en un solo ciclo.
     *
     * @param {string} cartItemKey  Cart item key de WooCommerce.
     * @param {jQuery} $item        Elemento DOM del ítem a animar/remover.
     */
    function removeCartItem(cartItemKey, $item) {
        $item.addClass('removing');

        // Construir la URL del endpoint nativo de WC. wc_cart_params.wc_ajax_url
        // tiene el formato '/?wc-ajax=%%endpoint%%'. Si por alguna razón no está
        // disponible (página sin scripts de WC), usamos el fallback genérico.
        var wc_ajax_base = (typeof wc_cart_params !== 'undefined' && wc_cart_params.wc_ajax_url)
            ? wc_cart_params.wc_ajax_url
            : '/?wc-ajax=%%endpoint%%';

        var removeUrl = wc_ajax_base.replace('%%endpoint%%', 'remove_from_cart');

        $.get(removeUrl, { cart_item_key: cartItemKey })
            .done(function() {
                $item.slideUp(200, function() {
                    $(this).remove();
                });

                // wc_fragment_refresh actualiza todos los fragmentos del carrito
                // registrados en woocommerce_add_to_cart_fragments (badge,
                // totales, items del drawer) en un solo request.
                $(document.body).trigger('wc_fragment_refresh');
            })
            .fail(function() {
                $item.removeClass('removing');
                showToast('❌ Error al eliminar');
            });
    }

    /**
     * Actualiza los fragmentos del carrito
     */
    function updateCartFragments(fragments) {
        if (!fragments) return;

        $.each(fragments, function(selector, content) {
            $(selector).replaceWith(content);
        });

        updateFloatingCartVisibility();
    }

    /**
     * Muestra un toast/notificación
     */
    function showToast(message) {
        // Remover toast anterior
        $('.restohub-toast').remove();

        const $toast = $('<div class="restohub-toast">' + message + '</div>');
        $('body').append($toast);

        setTimeout(function() {
            $toast.addClass('show');
        }, 10);

        setTimeout(function() {
            $toast.removeClass('show');
            setTimeout(function() {
                $toast.remove();
            }, 300);
        }, 2500);
    }

    /**
     * Exponer funciones globalmente si es necesario
     */
    window.restoHubStorefront = window.restoHubStorefront || {};
    window.restoHubStorefront.showToast = showToast;
    window.restoHubStorefront.openCartDrawer = openCartDrawer;
    window.restoHubStorefront.closeCartDrawer = closeCartDrawer;

})(jQuery);
