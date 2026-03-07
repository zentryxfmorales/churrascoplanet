/**
 * RestoHub SumUp Checkout — Frontend Widget Handler
 *
 * Flujo de pago:
 *  1. Intercepta el evento WC `checkout_place_order_restohub_sumup` → retorna false.
 *  2. Hace su propio AJAX POST al endpoint de WooCommerce checkout.
 *  3. Recibe { result:'success', checkoutId, redirectUrl } y monta SumUpCard.
 *  4. onResponse('success', {status:'PAID'}) → redirige al cliente a thank-you.
 *  5. onResponse('success', {status:'PENDING'}) → redirige; el webhook confirma después.
 *  6. Para 3DS con redirect: el PHP check_redirect_flow() maneja el retorno.
 *
 * Estados internos: 'idle' | 'loading' | 'widget_active' | 'redirecting'
 *
 * @package RestoHub
 */
(function ($) {
    'use strict';

    // ── Configuración desde wp_localize_script ────────────────────────────────
    var cfg       = window.restoHubSumUp || {};
    var METHOD_ID = cfg.methodId || 'restohub_sumup';
    var i18n      = cfg.i18n    || {};

    // ── Estado del módulo ─────────────────────────────────────────────────────
    var state             = 'idle'; // 'idle'|'loading'|'widget_active'|'redirecting'
    var activeCheckoutId  = null;
    var activeRedirectUrl = null;

    // ── Selectores DOM (re-evaluados en cada llamada porque WC puede re-renderizar) ──
    function $wrap()       { return $('#restohub-sumup-widget-wrap'); }
    function $cardDiv()    { return $('#restohub-sumup-card'); }
    function $loadingEl()  { return $('#restohub-sumup-loading'); }
    function $errorEl()    { return $('#restohub-sumup-widget-error'); }
    function $placeOrder() { return $('#place_order'); }
    function $cancelBtn()  { return $('#restohub-sumup-cancel-btn'); }

    // Helpers de loading (display:flex, no block)
    function showLoading() { $loadingEl().css('display', 'flex'); }
    function hideLoading() { $loadingEl().hide(); }

    // =========================================================================
    // Inicialización
    // =========================================================================

    $(document).ready(function () {
        // Solo operar en el checkout con los parámetros de WC disponibles
        if (typeof wc_checkout_params === 'undefined') { return; }
        bindCheckoutEvent();
    });

    // ── Re-enlace de eventos tras re-renderizado de WC ────────────────────────
    $(document.body).on('updated_checkout', function () {
        onUpdatedCheckout();
        bindCheckoutEvent();
    });

    // ── Cambio de método de pago ──────────────────────────────────────────────
    $(document.body).on('payment_method_selected', function () {
        var selected = $('input[name="payment_method"]:checked').val();
        if (selected !== METHOD_ID && state !== 'idle' && state !== 'redirecting') {
            resetToIdle();
        }
    });

    // ── Botón cancelar (delegado para sobrevivir re-renders) ──────────────────
    $(document).on('click', '#restohub-sumup-cancel-btn', function (e) {
        e.preventDefault();
        resetToIdle();
    });

    // =========================================================================
    // Enlace del evento de WooCommerce
    // =========================================================================

    /**
     * Enlaza el interceptor al evento específico del método de pago.
     * WC dispara checkout_place_order_{method_id} antes de su propio AJAX;
     * retornar false desde aquí detiene el flujo nativo.
     */
    function bindCheckoutEvent() {
        $('form.checkout')
            .off('checkout_place_order_' + METHOD_ID)
            .on('checkout_place_order_' + METHOD_ID, onPlaceOrder);
    }

    // =========================================================================
    // Handler principal: clic en "Pagar"
    // =========================================================================

    function onPlaceOrder() {
        // Si ya tenemos un proceso activo, no hacer nada (protege contra doble clic)
        if (state !== 'idle') {
            return false;
        }

        // Seguridad: evitar doble submit si WooCommerce ya está procesando
        var $checkoutForm = $('form.checkout');
        if ($checkoutForm.hasClass('processing')) {
            return false;
        }

        // Seguridad: validación nativa HTML5 — defiende contra manipulación de DOM.
        // Si el usuario habilitó el botón manualmente, el navegador mostrará los
        // errores nativos de los campos requeridos y abortamos sin llamar a SumUp.
        var nativeForm = $checkoutForm[0];
        if (nativeForm && typeof nativeForm.reportValidity === 'function' && !nativeForm.reportValidity()) {
            return false;
        }

        // Verificar que el SDK de SumUp esté cargado
        if (typeof SumUpCard === 'undefined') {
            displayError(i18n.generalError || 'Error al cargar la pasarela de pago. Recarga la página.');
            return false;
        }

        enterLoadingState();
        doCheckoutAjax();

        return false; // Siempre bloquear el flujo nativo de WC
    }

    // =========================================================================
    // AJAX al endpoint de checkout de WooCommerce
    // =========================================================================

    function doCheckoutAjax() {
        $.ajax({
            type:     'POST',
            url:      wc_checkout_params.checkout_url,
            data:     $('form.checkout').serialize(),
            dataType: 'json',
            success:  handleCheckoutResponse,
            error:    handleCheckoutAjaxError,
        });
    }

    /**
     * Maneja la respuesta del servidor al POST de checkout.
     *
     * WC puede devolver:
     *   • { result:'failure', messages:'<ul>...', reload:bool, refresh:bool }
     *     → errores de validación (dirección, stock, etc.)
     *   • { result:'success', redirect:'#', checkoutId, redirectUrl, orderId, orderKey }
     *     → process_payment() exitoso (nuestro formato personalizado)
     */
    function handleCheckoutResponse(response) {
        if (!response || typeof response !== 'object') {
            displayError(i18n.generalError || 'Respuesta inesperada del servidor.');
            exitLoadingState();
            return;
        }

        // ── WC retorna instrucción de recargar página ─────────────────────────
        if (response.reload === true) {
            window.location.reload();
            return;
        }

        // ── WC retorna instrucción de actualizar checkout ─────────────────────
        if (response.refresh === true) {
            $(document.body).trigger('update_checkout');
        }

        // ── Fallo: errores de validación u otros ──────────────────────────────
        if (response.result === 'failure') {
            if (response.messages) {
                showWcNotices(response.messages);
            }
            exitLoadingState();
            return;
        }

        // ── Resultado inesperado ──────────────────────────────────────────────
        if (response.result !== 'success') {
            displayError(i18n.generalError || 'Error inesperado. Por favor intenta nuevamente.');
            exitLoadingState();
            return;
        }

        // ── Éxito pero falta checkoutId (no debería ocurrir) ──────────────────
        if (!response.checkoutId) {
            displayError(i18n.generalError || 'No se recibió el ID de pago. Por favor intenta nuevamente.');
            exitLoadingState();
            return;
        }

        // ── Todo bien: guardar datos y montar el widget ───────────────────────
        activeCheckoutId  = response.checkoutId;
        activeRedirectUrl = response.redirectUrl || wc_checkout_params.order_received_url || '/';

        mountSumUpWidget();
    }

    function handleCheckoutAjaxError(jqXHR, textStatus) {
        console.error('[RestoHub SumUp] AJAX error:', textStatus, jqXHR.status);
        displayError(i18n.generalError || 'Error de conexión. Verifica tu red e intenta nuevamente.');
        exitLoadingState();
    }

    // =========================================================================
    // Montado del widget SumUpCard
    // =========================================================================

    function mountSumUpWidget() {
        // Limpiar un montado anterior si existe
        $cardDiv().empty();
        clearError();

        // Transición visual: ocultar loading, mostrar widget
        hideLoading();
        $wrap().show();
        $placeOrder().hide();
        showCancelButton();

        state = 'widget_active';

        SumUpCard.mount({
            id:          'restohub-sumup-card',
            checkoutId:  activeCheckoutId,
            locale:      cfg.locale  || 'es-CL', // IETF BCP 47: 'es-CL', 'pt-BR', etc.
            country:     cfg.country || 'CL',     // ISO 3166-1 alpha-2
            showZipCode: false,
            showFooter:  true,
            onResponse:  handleSumUpResponse,
        });
    }

    // =========================================================================
    // Manejador de respuesta de SumUpCard
    // =========================================================================

    /**
     * @param {string} type  'sent'|'auth-screen'|'success'|'error'|'invalid'
     * @param {object} body  Varía según el tipo
     */
    function handleSumUpResponse(type, body) {
        switch (type) {

            case 'sent':
            case 'auth-screen':
                // Estados intermedios del flujo 3DS — esperar
                clearError();
                break;

            case 'success': {
                var status = (body && body.status) ? body.status.toUpperCase() : 'UNKNOWN';

                if (status === 'PAID' || status === 'PENDING') {
                    // Mostrar spinner de redirección
                    state = 'redirecting';
                    $wrap().hide();
                    $cancelBtn().hide();
                    showLoading();

                    var redirectMsg = status === 'PAID'
                        ? (i18n.loading       || 'Verificando pago...')
                        : (i18n.paymentPending || 'Verificando estado del pago...');
                    $loadingEl().find('span').last().text(redirectMsg);

                    // Pequeño delay para que el spinner sea visible antes de navegar
                    setTimeout(function () {
                        window.location.href = activeRedirectUrl;
                    }, 400);

                } else {
                    // status FAILED devuelto dentro de type 'success' (edge case de SumUp)
                    displayError(i18n.paymentFailed || 'Pago rechazado. Por favor intenta con otra tarjeta.');
                    state = 'widget_active';
                }
                break;
            }

            case 'error':
                displayError(
                    (body && body.message)
                        ? body.message
                        : (i18n.generalError || 'Error al procesar el pago. Por favor intenta nuevamente.')
                );
                state = 'widget_active';
                break;

            case 'invalid':
                displayError(i18n.cardError || 'Datos de tarjeta inválidos. Verifica e intenta nuevamente.');
                state = 'widget_active';
                break;

            default:
                // Tipo no reconocido — ignorar silenciosamente
                break;
        }
    }

    // =========================================================================
    // Transiciones de estado (UI)
    // =========================================================================

    /** Inicia el estado de carga: spinner + botón deshabilitado + botón cancelar */
    function enterLoadingState() {
        state = 'loading';
        clearError();
        $wrap().hide();
        showLoading();
        $placeOrder().prop('disabled', true).addClass('restohub-sumup-btn-loading');
        showCancelButton();
    }

    /** Vuelve a idle sin widget (error o validación fallida) */
    function exitLoadingState() {
        state = 'idle';
        hideLoading();
        $placeOrder()
            .prop('disabled', false)
            .removeClass('restohub-sumup-btn-loading')
            .show();
        $cancelBtn().remove();
    }

    /** Reset completo — devuelve todo a estado inicial */
    function resetToIdle() {
        state             = 'idle';
        activeCheckoutId  = null;
        activeRedirectUrl = null;

        $wrap().hide();
        $cardDiv().empty();
        hideLoading();
        $cancelBtn().remove();
        clearError();

        $placeOrder()
            .prop('disabled', false)
            .removeClass('restohub-sumup-btn-loading')
            .show();
    }

    /** Maneja el evento updated_checkout de WC */
    function onUpdatedCheckout() {
        // WC re-renderizó el área de pago → el DOM del widget fue destruido.
        // Solo resetear el estado interno (no tocar DOM — ya fue reemplazado).
        if (state !== 'idle' && state !== 'redirecting') {
            state             = 'idle';
            activeCheckoutId  = null;
            activeRedirectUrl = null;
        }
    }

    // =========================================================================
    // Helpers de UI
    // =========================================================================

    function displayError(msg) {
        var $e = $errorEl();
        $e.text(msg).show();
        // Scroll al error si está fuera del viewport
        if ($e.length && $e.offset()) {
            $('html, body').animate({ scrollTop: $e.offset().top - 120 }, 300);
        }
    }

    function clearError() {
        $errorEl().hide().text('');
    }

    /** Inserta el botón cancelar junto a #place_order (solo si no existe ya) */
    function showCancelButton() {
        if ($cancelBtn().length > 0) { return; }

        $('<button>', {
            type:  'button',
            id:    'restohub-sumup-cancel-btn',
            class: 'restohub-sumup-cancel',
            text:  '← Volver',
        }).insertAfter($placeOrder());
    }

    /**
     * Muestra los mensajes de WC (HTML) en el área de avisos estándar.
     * Si no existe un wrapper, lo crea al inicio del formulario.
     *
     * @param {string} messagesHtml HTML de la lista de errores/avisos de WC.
     */
    function showWcNotices(messagesHtml) {
        var $wrapper = $('.woocommerce-notices-wrapper').first();

        if ($wrapper.length) {
            $wrapper.html(messagesHtml);
        } else {
            $('form.checkout').prepend(
                $('<div>', { class: 'woocommerce-notices-wrapper' }).html(messagesHtml)
            );
        }

        var $firstNotice = $('.woocommerce-notices-wrapper').first();
        if ($firstNotice.length && $firstNotice.offset()) {
            $('html, body').animate(
                { scrollTop: $firstNotice.offset().top - 80 },
                350
            );
        }
    }

})(jQuery);
