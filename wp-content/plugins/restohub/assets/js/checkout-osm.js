/**
 * RestoHub - Checkout OpenStreetMap Integration
 *
 * Maneja el mapa de Leaflet en el checkout para selección de ubicación
 * con búsqueda via Nominatim y marcador draggable.
 */

(function($) {
    'use strict';

    // Variables del módulo
    let map = null;
    let marker = null;
    let searchTimeout = null;
    let isUpdatingFromMap = false;
    let mapInitialized = false;

    // Configuración desde PHP
    const config = window.restoHubCheckout || {};
    const mapConfig = config.map || {};
    const nominatimConfig = config.nominatim || {};
    const strings = config.strings || {};

    // Key para localStorage (misma que location-modal.js)
    const STORAGE_KEY = 'restohub_customer_location';

    // Estado de la ubicación
    let locationData = null;
    let hasValidLocation = false;

    /**
     * Inicialización cuando el DOM está listo
     */
    $(document).ready(function() {
        // Cargar datos desde localStorage si existen
        loadFromLocalStorage();

        // Inicializar toggle Delivery/Retiro
        initDeliveryToggle();

        // Inicializar selector de horarios
        initScheduleSelector();

        // Inicializar campo único de dirección con autocompletado
        initUnifiedAddressField();

        // Inicializar selector de direcciones guardadas
        initAddressSelector();

        // Inicializar control de métodos de envío
        initShippingMethodsControl();

        // Inicializar control del botón de pago
        initPaymentButtonControl();

        // Inicializar toggle colapsable del mapa (init lazy al abrir)
        initMapToggle();

        // Inicializar selector de propina
        initTipSelector();

        // Escuchar cambios en el checkout
        $(document.body).on('updated_checkout', function() {
            initShippingMethodsControl();
            updatePaymentButtonState();
            // Re-bind tip selector events (order review re-renders on AJAX)
            initTipSelector();
            // Refrescar slots si el picker está visible
            reinitScheduleSelector();
        });
    });

    /**
     * Inicializa el toggle Delivery / Retiro en tienda
     */
    function initDeliveryToggle() {
        const $toggle = $('#restohub-checkout-delivery-toggle');
        if ($toggle.length === 0) return;

        // Click en botones de modo
        $toggle.on('click', '.restohub-checkout-mode-btn', function() {
            const mode = $(this).data('mode');

            // Actualizar botones activos
            $toggle.find('.restohub-checkout-mode-btn').removeClass('active');
            $(this).addClass('active');

            // Actualizar input oculto
            $('#restohub-checkout-delivery-type').val(mode);

            // Mostrar/ocultar secciones según modo
            if (mode === 'pickup') {
                $('#restohub-checkout-delivery-info').hide();
                $('#restohub-checkout-pickup-info').show();
                $('form.checkout').addClass('restohub-pickup-mode');
            } else {
                $('#restohub-checkout-delivery-info').show();
                $('#restohub-checkout-pickup-info').hide();
                $('form.checkout').removeClass('restohub-pickup-mode');
            }

            // Sincronizar modo con el servidor
            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'restohub_switch_delivery_mode',
                    nonce: config.nonce,
                    delivery_type: mode,
                    store_id: mode === 'pickup' ? $('#restohub-checkout-store-selector').val() : ''
                },
                success: function(response) {
                    if (response.success) {
                        // Recalcular checkout (métodos de envío, etc.)
                        $(document.body).trigger('update_checkout');
                    }
                }
            });

            // Actualizar controles
            initShippingMethodsControl();
            updatePaymentButtonState();
            // Actualizar texto de schedule según modo
            updateScheduleInfoText(mode);
        });

        // Cambio de tienda para retiro
        $toggle.on('change', '#restohub-checkout-store-selector', function() {
            const storeId = $(this).val();

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'restohub_switch_delivery_mode',
                    nonce: config.nonce,
                    delivery_type: 'pickup',
                    store_id: storeId
                },
                success: function(response) {
                    if (response.success) {
                        $(document.body).trigger('update_checkout');
                    }
                }
            });
        });

        // Aplicar estado inicial
        const currentMode = $('#restohub-checkout-delivery-type').val() || 'delivery';
        if (currentMode === 'pickup') {
            $('form.checkout').addClass('restohub-pickup-mode');
        }
    }

    /**
     * Carga ubicación desde PHP config o localStorage
     */
    function loadFromLocalStorage() {
        // Primero intentar desde PHP config (sesión del servidor)
        if (config.hasLocation && config.locationData && config.locationData.lat) {
            locationData = config.locationData;
            hasValidLocation = true;

            // Pre-rellenar campos
            setTimeout(function() {
                prefillWooCommerceFields(locationData);
                updatePaymentButtonState();
            }, 500);

            return;
        }

        // Luego intentar desde localStorage
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (!stored) {
                hasValidLocation = false;
                return;
            }

            const data = JSON.parse(stored);

            // Verificar que no sea muy viejo (7 días)
            if (data.timestamp && (Date.now() - data.timestamp) > 7 * 24 * 60 * 60 * 1000) {
                localStorage.removeItem(STORAGE_KEY);
                hasValidLocation = false;
                return;
            }

            // Guardar datos globalmente
            locationData = data;
            hasValidLocation = !!(data.lat && data.lng);

            // Guardar en config para que initMap use estas coordenadas
            if (data.lat && data.lng) {
                mapConfig.savedLat = data.lat;
                mapConfig.savedLng = data.lng;

                // *** IMPORTANTE: Sincronizar con el servidor inmediatamente ***
                syncLocationToServer(data);
            }

            // Pre-rellenar campos de WooCommerce después de que se cargue la página
            setTimeout(function() {
                prefillWooCommerceFields(data);
                updatePaymentButtonState();
            }, 500);

        } catch (e) {
            console.warn('WCUDC Checkout: Error leyendo localStorage', e);
            hasValidLocation = false;
        }
    }

    /**
     * Sincroniza la ubicación del localStorage con el servidor
     * Esto es CRÍTICO para que el shipping se calcule correctamente
     */
    function syncLocationToServer(data) {
        if (!data || !data.lat || !data.lng) {
            return;
        }

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'restohub_sync_location',
                nonce: config.nonce,
                latitude: data.lat,
                longitude: data.lng,
                address: data.address || '',
                full_address: data.full_address || '',
                street: data.street || '',
                city: data.city || '',
                state: data.state || '',
                postcode: data.postcode || '',
                delivery_type: data.delivery_type || 'delivery',
                store_id: data.store_id || ''
            },
            success: function(response) {
                if (response.success) {
                    // Forzar recálculo del checkout para que use las nuevas coordenadas
                    setTimeout(function() {
                        $(document.body).trigger('update_checkout');
                    }, 300);
                } else {
                    console.warn('WCUDC Checkout: Error sincronizando ubicación', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('WCUDC Checkout: Error AJAX sincronizando ubicación', error);
            }
        });
    }

    /**
     * Pre-rellena los campos de WooCommerce con los datos del modal de delivery.
     *
     * Los campos originales de WC (address_1, city, state) están ocultos.
     * Se rellenan internamente desde los datos estructurados de Nominatim
     * para que WooCommerce los procese correctamente al hacer checkout.
     */
    function prefillWooCommerceFields(data) {
        if (!data) return;

        // Rellenar tanto billing como shipping (campos ocultos)
        const prefixes = ['billing', 'shipping'];

        prefixes.forEach(function(prefix) {
            // Dirección línea 1: usar street si existe, sino primer parte de address
            if (data.street) {
                $(`#${prefix}_address_1`).val(data.street).trigger('change');
            } else if (data.address) {
                const addressLine = data.address.split(',')[0].trim();
                $(`#${prefix}_address_1`).val(addressLine).trigger('change');
            }

            // Ciudad (dato estructurado de Nominatim)
            if (data.city) {
                $(`#${prefix}_city`).val(data.city).trigger('change');
            }

            // Estado/Región (dato estructurado de Nominatim)
            if (data.state) {
                const $state = $(`#${prefix}_state`);
                if ($state.length) {
                    if ($state.is('select')) {
                        const stateLower = data.state.toLowerCase();
                        const $option = $state.find('option').filter(function() {
                            return $(this).text().toLowerCase().includes(stateLower);
                        });
                        if ($option.length) {
                            $state.val($option.val()).trigger('change');
                        }
                    } else {
                        $state.val(data.state).trigger('change');
                    }
                }
            }

            // Detalle de dirección (número de depto, etc.)
            if (data.address_detail) {
                $(`#${prefix}_address_2`).val(data.address_detail).trigger('change');
            }

            // Código postal
            if (data.postcode) {
                $(`#${prefix}_postcode`).val(data.postcode).trigger('change');
            }
        });

        // Campos ocultos de coordenadas
        if (data.lat && data.lng) {
            $('#billing_latitude').val(data.lat);
            $('#billing_longitude').val(data.lng);
            $('#shipping_latitude').val(data.lat);
            $('#shipping_longitude').val(data.lng);

            // Crear inputs si no existen
            ensureHiddenField('billing_latitude', data.lat);
            ensureHiddenField('billing_longitude', data.lng);
            ensureHiddenField('shipping_latitude', data.lat);
            ensureHiddenField('shipping_longitude', data.lng);
        }

        // Actualizar campo único de dirección
        const displayAddress = data.full_address || data.address || '';
        if (displayAddress) {
            $('#restohub-unified-address-input').val(displayAddress);
        }

        // Actualizar campo de búsqueda del mapa
        if (data.address) {
            $('#restohub-address-search').val(data.address);
        }

    }

    /**
     * Asegura que exista un campo oculto
     */
    function ensureHiddenField(name, value) {
        if ($(`#${name}`).length === 0) {
            $('<input>').attr({
                type: 'hidden',
                id: name,
                name: name,
                value: value
            }).appendTo('form.checkout');
        }
    }

    /**
     * Inicializa el campo único de dirección con autocompletado Nominatim.
     *
     * Este campo reemplaza los 3 campos separados de WooCommerce
     * (address_1, city, state) con un solo input con autocompletado.
     */
    function initUnifiedAddressField() {
        const $input = $('#restohub-unified-address-input');
        const $results = $('#restohub-unified-address-results');
        if ($input.length === 0) return;

        let unifiedSearchTimeout = null;

        // Búsqueda con debounce
        $input.on('input', function() {
            const query = $(this).val().trim();
            clearTimeout(unifiedSearchTimeout);

            if (query.length < 3) {
                $results.removeClass('show').empty();
                return;
            }

            unifiedSearchTimeout = setTimeout(function() {
                searchUnifiedAddress(query);
            }, 500);
        });

        // Focus: seleccionar todo para facilitar edición
        $input.on('focus', function() {
            $(this).select();
            if ($results.children().length > 0) {
                $results.addClass('show');
            }
        });

        // Click fuera cierra resultados
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.restohub-unified-address-wrapper').length) {
                $results.removeClass('show');
            }
        });

        // Enter previene envío del formulario
        $input.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
            }
        });
    }

    /**
     * Busca dirección para el campo unificado usando Nominatim
     */
    function searchUnifiedAddress(query) {
        const $results = $('#restohub-unified-address-results');
        const nominatimUrl = nominatimConfig.url || 'https://nominatim.openstreetmap.org';
        const countryCode = nominatimConfig.countryCode || '';

        $results.html('<div class="restohub-unified-loading">Buscando...</div>').addClass('show');

        let url = `${nominatimUrl}/search?format=json&q=${encodeURIComponent(query)}&limit=5&addressdetails=1`;
        if (countryCode) {
            url += `&countrycodes=${countryCode}`;
        }

        $.ajax({
            url: url,
            type: 'GET',
            dataType: 'json',
            headers: { 'Accept-Language': 'es' },
            success: function(results) {
                if (!results || results.length === 0) {
                    $results.html('<div class="restohub-unified-no-results">No se encontraron resultados</div>');
                    return;
                }

                let html = '';
                results.forEach(function(r) {
                    const parts = r.display_name.split(',');
                    const mainPart = parts.slice(0, 2).join(',').trim();
                    const secondaryPart = parts.slice(2, 4).join(',').trim();

                    // Datos estructurados de Nominatim
                    const addr = r.address || {};
                    const street = [addr.road || '', addr.house_number || ''].filter(Boolean).join(' ');
                    const city = addr.city || addr.town || addr.village || addr.municipality || '';
                    const state = addr.state || addr.region || '';
                    const postcode = addr.postcode || '';

                    html += `
                        <div class="restohub-unified-result-item"
                             data-lat="${r.lat}"
                             data-lng="${r.lon}"
                             data-display="${escapeHtmlAttr(r.display_name)}"
                             data-street="${escapeHtmlAttr(street)}"
                             data-city="${escapeHtmlAttr(city)}"
                             data-state="${escapeHtmlAttr(state)}"
                             data-postcode="${escapeHtmlAttr(postcode)}">
                            <span class="restohub-unified-result-icon">📍</span>
                            <div class="restohub-unified-result-content">
                                <span class="restohub-unified-result-main">${escapeHtmlContent(mainPart)}</span>
                                <span class="restohub-unified-result-secondary">${escapeHtmlContent(secondaryPart)}</span>
                            </div>
                        </div>
                    `;
                });

                $results.html(html);

                // Click en resultado
                $results.find('.restohub-unified-result-item').on('click', function() {
                    const lat = parseFloat($(this).data('lat'));
                    const lng = parseFloat($(this).data('lng'));
                    const display = $(this).data('display');
                    const street = $(this).data('street');
                    const city = $(this).data('city');
                    const state = $(this).data('state');
                    const postcode = $(this).data('postcode');

                    // Actualizar campo visible
                    $('#restohub-unified-address-input').val(display);
                    $results.removeClass('show');

                    // Rellenar campos ocultos de WC
                    const structuredData = {
                        street: street,
                        city: city,
                        state: state,
                        postcode: postcode,
                        address: display,
                        full_address: display,
                        lat: lat,
                        lng: lng
                    };
                    prefillWooCommerceFields(structuredData);

                    // Actualizar mapa si existe
                    if (map && marker) {
                        map.setView([lat, lng], 17);
                        marker.setLatLng([lat, lng]);
                        onLocationSelected(lat, lng, false);
                    }

                    // Trigger recálculo
                    $(document.body).trigger('update_checkout');
                });
            },
            error: function() {
                $results.html('<div class="restohub-unified-no-results">Error en la búsqueda</div>');
            }
        });
    }

    /**
     * Escapa texto para atributos HTML
     */
    function escapeHtmlAttr(text) {
        if (!text) return '';
        return text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * Escapa texto para contenido HTML
     */
    function escapeHtmlContent(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Inicializa el selector de direcciones guardadas.
     *
     * Permite al usuario elegir entre la dirección del modal de delivery
     * y las direcciones guardadas en su perfil de WooCommerce.
     */
    function initAddressSelector() {
        const $selector = $('#restohub-address-selector');
        if ($selector.length === 0) return;

        $selector.on('change', 'input[name="restohub_address_source"]', function() {
            const value = $(this).val();

            // Actualizar estilos de la opción activa
            $selector.find('.restohub-address-option').removeClass('restohub-address-option-active');
            $(this).closest('.restohub-address-option').addClass('restohub-address-option-active');

            if (value === 'modal') {
                // Rellenar con datos del modal de delivery
                if (locationData) {
                    prefillWooCommerceFields(locationData);
                }
            } else {
                // Rellenar con datos del perfil guardado
                const $option = $(this).closest('.restohub-address-option');
                const profileData = {
                    street:    $option.data('address-1') || '',
                    address:   $option.data('address-1') || '',
                    address_detail: $option.data('address-2') || '',
                    city:      $option.data('city') || '',
                    state:     $option.data('state') || '',
                    postcode:  $option.data('postcode') || ''
                };

                // Construir dirección completa para el campo visible
                const displayParts = [
                    profileData.street,
                    profileData.city,
                    profileData.state
                ].filter(Boolean);
                profileData.full_address = displayParts.join(', ');

                prefillWooCommerceFields(profileData);
            }
        });
    }

    /**
     * Controla la visibilidad de los métodos de envío
     */
    function initShippingMethodsControl() {
        // Determinar el modo: desde toggle del checkout > config PHP > locationData
        const toggleMode = $('#restohub-checkout-delivery-type').val();
        const deliveryType = toggleMode || config.deliveryType || (locationData ? locationData.delivery_type : 'delivery');
        const isPickupMode = deliveryType === 'pickup';

        // Selector para el método de retiro en tienda
        const $pickupMethod = $('input[value*="local_pickup"], input[value*="pickup"]').closest('li');
        const $uberMethod = $('input[value*="uber_direct"]').closest('li');

        if (isPickupMode) {
            // Modo retiro: mostrar solo retiro, ocultar delivery
            $uberMethod.hide();
            $pickupMethod.show();

            // Seleccionar automáticamente retiro
            $pickupMethod.find('input[type="radio"]').prop('checked', true).trigger('change');
        } else {
            // Modo delivery: ocultar retiro, mostrar delivery
            $pickupMethod.hide();
            $uberMethod.show();

            // Seleccionar automáticamente delivery si hay ubicación válida
            if (hasValidLocation) {
                $uberMethod.find('input[type="radio"]').prop('checked', true).trigger('change');
            }
        }
    }

    /**
     * Controla el estado del botón de pago
     */
    function initPaymentButtonControl() {
        updatePaymentButtonState();

        // Escuchar cambios en métodos de envío
        $(document).on('change', 'input[name="shipping_method[0]"]', function() {
            updatePaymentButtonState();
        });

        // Escuchar cambios en campos de dirección
        $(document).on('change', '#billing_address_1, #shipping_address_1', function() {
            const hasAddress = $(this).val().length >= 5;
            if (hasAddress && locationData && locationData.lat) {
                hasValidLocation = true;
            }
            updatePaymentButtonState();
        });

        // Escuchar cambios en campos de contacto requeridos (nombre, apellido, teléfono, email)
        $(document).on('input change', '#billing_first_name, #billing_last_name, #billing_phone, #billing_email', function() {
            updatePaymentButtonState();
        });
    }

    /**
     * Actualiza el estado del botón de pago.
     *
     * Condiciones que bloquean el botón (por prioridad):
     *  1. Delivery mode sin dirección válida → aviso de dirección
     *  2. Campos de contacto obligatorios vacíos → aviso de campos
     *  3. Tienda cerrada sin horario programado → aviso de schedule
     */
    function updatePaymentButtonState() {
        const $placeOrderBtn  = $('#place_order');
        const $paymentSection = $('.woocommerce-checkout-payment');

        // ── Modo de entrega ────────────────────────────────────────────────
        const toggleMode      = $('#restohub-checkout-delivery-type').val();
        const deliveryType    = toggleMode || config.deliveryType || (locationData ? locationData.delivery_type : 'delivery');
        const isPickupMode    = deliveryType === 'pickup';
        const selectedShipping = $('input[name="shipping_method[0]"]:checked').val() || '';
        const isPickupShipping = selectedShipping.includes('pickup') || selectedShipping.includes('local_pickup');
        const hasServerLocation = config.hasLocation === true;

        // ── Campos de contacto obligatorios ───────────────────────────────
        const firstName = $.trim($('#billing_first_name').val());
        const lastName  = $.trim($('#billing_last_name').val());
        const phone     = $.trim($('#billing_phone').val());
        const email     = $.trim($('#billing_email').val());
        const hasRequiredFields = firstName && lastName && phone && email;

        // ── Evaluar condiciones ────────────────────────────────────────────
        let canPay      = false;
        let blockReason = '';

        // 1. Dirección de delivery
        if (isPickupMode || isPickupShipping) {
            canPay = true;
        } else if (hasValidLocation || hasServerLocation) {
            canPay = true;
        } else {
            blockReason = 'address';
        }

        // 2. Campos requeridos (siempre se verifica, independiente del modo)
        if (!hasRequiredFields) {
            canPay = false;
            if (!blockReason) blockReason = 'fields';
        }

        // 3. Scheduling: tienda cerrada sin horario programado
        const scheduling = config.scheduling || {};
        if (scheduling.enabled && !scheduling.isStoreOpen) {
            const scheduleType = $('#restohub_schedule_type').val();
            const scheduleTime = $('#restohub_schedule_time').val();
            if (scheduleType !== 'scheduled' || !scheduleTime) {
                canPay = false;
                if (!blockReason) blockReason = 'schedule';
            }
        }

        // ── Aplicar estado ─────────────────────────────────────────────────
        if (canPay) {
            $placeOrderBtn.prop('disabled', false).removeClass('restohub-disabled');
            $paymentSection.removeClass('restohub-payment-disabled');
            $('#restohub-payment-notice').remove();
        } else {
            $placeOrderBtn.prop('disabled', true).addClass('restohub-disabled');
            $paymentSection.addClass('restohub-payment-disabled');

            // Construir mensaje según la razón del bloqueo
            let noticeIcon = '📍';
            let noticeBody = '';

            if (blockReason === 'address') {
                noticeBody = 'Para continuar, por favor <a href="#" class="restohub-open-location-modal">selecciona tu dirección de entrega</a> o elige <strong>Retiro en Tienda</strong>.';
            } else if (blockReason === 'fields') {
                noticeIcon = '✏️';
                noticeBody = 'Completa tu <strong>nombre</strong>, <strong>apellido</strong>, <strong>teléfono</strong> y <strong>correo</strong> para continuar.';
            }

            // Actualizar o crear el aviso (se regenera siempre para reflejar cambio de razón)
            $('#restohub-payment-notice').remove();

            if (noticeBody) {
                const noticeHtml = `
                    <div id="restohub-payment-notice" class="restohub-location-notice">
                        <span class="restohub-notice-icon">${noticeIcon}</span>
                        <span class="restohub-notice-text">${noticeBody}</span>
                    </div>
                `;
                $paymentSection.before(noticeHtml);

                // Delegación de evento para abrir modal de dirección
                $(document).off('click.payNotice').on('click.payNotice', '.restohub-open-location-modal', function(e) {
                    e.preventDefault();
                    const $modal = jQuery('#restohub-location-modal');
                    if ($modal.length) {
                        $modal.removeAttr('style').addClass('restohub-modal-visible');
                        jQuery('body').addClass('restohub-modal-open');
                    }
                });
            }
        }
    }

    /**
     * Inicializa el toggle colapsable del mapa con lazy-init de Leaflet
     */
    function initMapToggle() {
        const $toggle = $('#restohub-map-toggle');
        const $collapsible = $('#restohub-map-collapsible');

        if ($toggle.length === 0 || $collapsible.length === 0) {
            // Fallback: si no hay toggle, inicializar el mapa directamente
            if ($('#restohub-delivery-map').length > 0) {
                initMap();
                initSearchBox();
                initGeolocation();
                initAddressFieldSync();
                mapInitialized = true;
            }
            return;
        }

        $toggle.on('click', function() {
            const isOpen = $toggle.attr('aria-expanded') === 'true';

            if (isOpen) {
                // Cerrar
                $toggle.attr('aria-expanded', 'false');
                $collapsible.attr('aria-hidden', 'true').removeClass('open');
            } else {
                // Abrir
                $toggle.attr('aria-expanded', 'true');
                $collapsible.attr('aria-hidden', 'false').addClass('open');

                if (!mapInitialized) {
                    // Primera apertura: inicializar mapa ahora que el contenedor es visible
                    initMap();
                    initSearchBox();
                    initGeolocation();
                    initAddressFieldSync();
                    mapInitialized = true;
                } else {
                    // Mapa ya inicializado: forzar recálculo de tamaño
                    setTimeout(function() {
                        if (map) map.invalidateSize();
                    }, 100);
                }
            }
        });

        // Auto-abrir si NO hay ubicación válida guardada (fuerza al usuario a confirmar)
        const hasLocation = config.hasLocation || (locationData && locationData.lat);
        if (!hasLocation && $('#restohub-delivery-map').length > 0) {
            // No abrir automáticamente: dejar colapsado para checkout limpio
            // El usuario puede abrir cuando quiera ajustar
        }
    }

    /**
     * Inicializa el mapa Leaflet
     */
    function initMap() {
        // Determinar coordenadas iniciales
        let initialLat = parseFloat(mapConfig.savedLat) || mapConfig.defaultLat || -12.0464;
        let initialLng = parseFloat(mapConfig.savedLng) || mapConfig.defaultLng || -77.0428;
        let initialZoom = mapConfig.defaultZoom || 13;

        // Si hay coordenadas guardadas, usar zoom más cercano
        if (mapConfig.savedLat && mapConfig.savedLng) {
            initialZoom = 16;
        }

        // Crear mapa
        map = L.map('restohub-delivery-map', {
            center: [initialLat, initialLng],
            zoom: initialZoom,
            zoomControl: true,
            scrollWheelZoom: true
        });

        // Agregar capa de tiles (OpenStreetMap)
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19
        }).addTo(map);

        // Crear marcador draggable
        marker = L.marker([initialLat, initialLng], {
            draggable: true,
            autoPan: true,
            icon: createCustomIcon()
        }).addTo(map);

        // Agregar tooltip al marcador
        marker.bindTooltip(strings.dragMarkerHint || 'Arrastra el marcador', {
            permanent: false,
            direction: 'top',
            offset: [0, -40]
        });

        // Evento: Marcador arrastrado
        marker.on('dragend', function(e) {
            const position = marker.getLatLng();
            onLocationSelected(position.lat, position.lng, true);
        });

        // Evento: Click en el mapa
        map.on('click', function(e) {
            marker.setLatLng(e.latlng);
            onLocationSelected(e.latlng.lat, e.latlng.lng, true);
        });

        // Si hay coordenadas guardadas, actualizar campos
        if (mapConfig.savedLat && mapConfig.savedLng) {
            updateHiddenFields(initialLat, initialLng);
            updateSelectedLocationDisplay(initialLat, initialLng);
        }

        // Forzar recálculo del tamaño del mapa
        setTimeout(function() {
            map.invalidateSize();
        }, 250);
    }

    /**
     * Crea un icono personalizado para el marcador
     */
    function createCustomIcon() {
        return L.divIcon({
            className: 'restohub-marker-icon',
            html: `
                <div class="restohub-marker-pin">
                    <div class="restohub-marker-pin-inner"></div>
                </div>
                <div class="restohub-marker-pulse"></div>
            `,
            iconSize: [30, 42],
            iconAnchor: [15, 42],
            popupAnchor: [0, -42]
        });
    }

    /**
     * Inicializa el buscador de direcciones
     */
    function initSearchBox() {
        const $searchInput = $('#restohub-address-search');
        const $resultsContainer = $('#restohub-search-results');

        if ($searchInput.length === 0) {
            return;
        }

        // Evento: Escribir en el buscador
        $searchInput.on('input', function() {
            const query = $(this).val().trim();

            // Limpiar timeout anterior
            clearTimeout(searchTimeout);

            if (query.length < 3) {
                $resultsContainer.empty().hide();
                return;
            }

            // Debounce de 500ms
            searchTimeout = setTimeout(function() {
                searchAddress(query);
            }, 500);
        });

        // Evento: Focus en el buscador
        $searchInput.on('focus', function() {
            if ($resultsContainer.children().length > 0) {
                $resultsContainer.show();
            }
        });

        // Evento: Click fuera para cerrar resultados
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.restohub-search-container').length) {
                $resultsContainer.hide();
            }
        });

        // Evento: Tecla Enter
        $searchInput.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                const query = $(this).val().trim();
                if (query.length >= 3) {
                    searchAddress(query);
                }
            }
        });
    }

    /**
     * Busca una dirección usando Nominatim
     */
    function searchAddress(query) {
        const $resultsContainer = $('#restohub-search-results');
        const nominatimUrl = nominatimConfig.url || 'https://nominatim.openstreetmap.org';
        const countryCode = nominatimConfig.countryCode || '';

        // Mostrar loading
        $resultsContainer.html('<div class="restohub-search-loading">' + (strings.locating || 'Buscando...') + '</div>').show();

        // Construir URL de búsqueda
        let searchUrl = `${nominatimUrl}/search?format=json&q=${encodeURIComponent(query)}&limit=5`;

        if (countryCode) {
            searchUrl += `&countrycodes=${countryCode}`;
        }

        // Realizar búsqueda
        $.ajax({
            url: searchUrl,
            type: 'GET',
            dataType: 'json',
            headers: {
                'Accept-Language': 'es'
            },
            success: function(results) {
                displaySearchResults(results);
            },
            error: function() {
                $resultsContainer.html(
                    '<div class="restohub-search-error">' + (strings.searchError || 'Error en la búsqueda') + '</div>'
                );
            }
        });
    }

    /**
     * Muestra los resultados de búsqueda
     */
    function displaySearchResults(results) {
        const $resultsContainer = $('#restohub-search-results');

        if (!results || results.length === 0) {
            $resultsContainer.html(
                '<div class="restohub-search-no-results">' + (strings.searchError || 'No se encontraron resultados') + '</div>'
            );
            return;
        }

        let html = '';

        results.forEach(function(result) {
            const displayName = result.display_name || '';
            const shortName = displayName.split(',').slice(0, 3).join(',');

            html += `
                <div class="restohub-search-result-item"
                     data-lat="${result.lat}"
                     data-lng="${result.lon}"
                     data-address="${displayName}">
                    <span class="restohub-result-icon">📍</span>
                    <span class="restohub-result-text">${shortName}</span>
                </div>
            `;
        });

        $resultsContainer.html(html).show();

        // Evento: Click en resultado
        $resultsContainer.find('.restohub-search-result-item').on('click', function() {
            const lat = parseFloat($(this).data('lat'));
            const lng = parseFloat($(this).data('lng'));
            const address = $(this).data('address');

            selectSearchResult(lat, lng, address);
            $resultsContainer.hide();
            $('#restohub-address-search').val(address.split(',').slice(0, 2).join(','));
        });
    }

    /**
     * Selecciona un resultado de búsqueda
     */
    function selectSearchResult(lat, lng, address) {
        // Mover mapa y marcador
        map.setView([lat, lng], 17);
        marker.setLatLng([lat, lng]);

        // Actualizar ubicación
        onLocationSelected(lat, lng, false);
    }

    /**
     * Inicializa el botón de geolocalización
     */
    function initGeolocation() {
        const $locateBtn = $('#restohub-locate-me');

        if ($locateBtn.length === 0) {
            return;
        }

        // Verificar si el navegador soporta geolocalización
        if (!navigator.geolocation) {
            $locateBtn.hide();
            return;
        }

        $locateBtn.on('click', function() {
            const $btn = $(this);
            $btn.addClass('restohub-locating');

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    $btn.removeClass('restohub-locating');

                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;

                    // Mover mapa y marcador
                    map.setView([lat, lng], 17);
                    marker.setLatLng([lat, lng]);

                    // Actualizar ubicación
                    onLocationSelected(lat, lng, true);
                },
                function(error) {
                    $btn.removeClass('restohub-locating');
                    alert(strings.locationError || 'No se pudo obtener tu ubicación');
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        });
    }

    /**
     * Sincroniza con los campos de dirección de WooCommerce
     */
    function initAddressFieldSync() {
        // Campos de dirección de WooCommerce
        const addressFields = [
            '#billing_address_1',
            '#billing_city',
            '#billing_state',
            '#shipping_address_1',
            '#shipping_city',
            '#shipping_state'
        ];

        // Cuando cambian los campos de dirección, buscar en el mapa
        $(addressFields.join(', ')).on('change', function() {
            if (isUpdatingFromMap) {
                return;
            }

            // Construir dirección completa
            const address = buildAddressFromFields();

            if (address.length > 10) {
                // Actualizar campo de búsqueda
                $('#restohub-address-search').val(address);

                // Buscar automáticamente (con debounce)
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    searchAndSelectFirst(address);
                }, 1000);
            }
        });
    }

    /**
     * Construye una dirección desde los campos de WooCommerce
     */
    function buildAddressFromFields() {
        const prefix = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';

        const address1 = $(`#${prefix}_address_1`).val() || '';
        const city = $(`#${prefix}_city`).val() || '';
        const state = $(`#${prefix}_state`).val() || '';

        return [address1, city, state].filter(Boolean).join(', ');
    }

    /**
     * Busca una dirección y selecciona el primer resultado
     */
    function searchAndSelectFirst(query) {
        const nominatimUrl = nominatimConfig.url || 'https://nominatim.openstreetmap.org';
        const countryCode = nominatimConfig.countryCode || '';

        let searchUrl = `${nominatimUrl}/search?format=json&q=${encodeURIComponent(query)}&limit=1`;

        if (countryCode) {
            searchUrl += `&countrycodes=${countryCode}`;
        }

        $.ajax({
            url: searchUrl,
            type: 'GET',
            dataType: 'json',
            success: function(results) {
                if (results && results.length > 0) {
                    const lat = parseFloat(results[0].lat);
                    const lng = parseFloat(results[0].lon);

                    map.setView([lat, lng], 16);
                    marker.setLatLng([lat, lng]);
                    onLocationSelected(lat, lng, false);
                }
            }
        });
    }

    /**
     * Callback cuando se selecciona una ubicación
     */
    function onLocationSelected(lat, lng, doReverseGeocode) {
        // Actualizar campos ocultos
        updateHiddenFields(lat, lng);

        // Guardar en sesión via AJAX
        saveCoordinatesToSession(lat, lng);

        // Actualizar display de ubicación seleccionada
        updateSelectedLocationDisplay(lat, lng);

        // Reverse geocoding si es necesario
        if (doReverseGeocode) {
            reverseGeocode(lat, lng);
        }

        // Trigger para que WooCommerce recalcule el shipping
        $(document.body).trigger('update_checkout');
    }

    /**
     * Actualiza los campos ocultos de coordenadas
     */
    function updateHiddenFields(lat, lng) {
        const latValue = lat.toFixed(7);
        const lngValue = lng.toFixed(7);

        // Actualizar campos de billing
        $('#billing_latitude').val(latValue);
        $('#billing_longitude').val(lngValue);

        // Actualizar campos de shipping
        $('#shipping_latitude').val(latValue);
        $('#shipping_longitude').val(lngValue);

        // También crear los inputs si no existen
        if ($('#billing_latitude').length === 0) {
            $('<input>').attr({
                type: 'hidden',
                id: 'billing_latitude',
                name: 'billing_latitude',
                value: latValue
            }).appendTo('form.checkout');
        }

        if ($('#billing_longitude').length === 0) {
            $('<input>').attr({
                type: 'hidden',
                id: 'billing_longitude',
                name: 'billing_longitude',
                value: lngValue
            }).appendTo('form.checkout');
        }
    }

    /**
     * Guarda las coordenadas en la sesión via AJAX
     */
    function saveCoordinatesToSession(lat, lng) {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'restohub_save_coordinates',
                nonce: config.nonce,
                latitude: lat,
                longitude: lng
            },
            success: function(response) {
                if (response.success && response.data.coverage) {
                    updateCoverageStatus(response.data.coverage);
                }
            }
        });
    }

    /**
     * Actualiza el indicador de cobertura
     */
    function updateCoverageStatus(coverage) {
        const $statusContainer = $('#restohub-coverage-status');

        if (!coverage) {
            $statusContainer.empty().hide();
            return;
        }

        let statusClass = coverage.has_coverage ? 'restohub-coverage-ok' : 'restohub-coverage-warning';
        let html = `<div class="${statusClass}">${coverage.message}</div>`;

        $statusContainer.html(html).show();
    }

    /**
     * Actualiza el display de ubicación seleccionada
     */
    function updateSelectedLocationDisplay(lat, lng) {
        const $container = $('#restohub-selected-location');
        const $address = $('#restohub-selected-address');

        $address.text(`${lat.toFixed(6)}, ${lng.toFixed(6)}`);
        $container.show();
    }

    /**
     * Realiza reverse geocoding para obtener la dirección
     */
    function reverseGeocode(lat, lng) {
        const nominatimUrl = nominatimConfig.url || 'https://nominatim.openstreetmap.org';

        $.ajax({
            url: `${nominatimUrl}/reverse?format=json&lat=${lat}&lon=${lng}`,
            type: 'GET',
            dataType: 'json',
            headers: {
                'Accept-Language': 'es'
            },
            success: function(result) {
                if (result && result.address) {
                    updateAddressFieldsFromGeocode(result);
                    updateSelectedAddressDisplay(result.display_name);
                }
            }
        });
    }

    /**
     * Actualiza los campos de dirección desde el resultado de geocoding
     */
    function updateAddressFieldsFromGeocode(result) {
        if (!result || !result.address) {
            return;
        }

        isUpdatingFromMap = true;

        const address = result.address;
        const prefix = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';

        // Construir dirección de línea 1
        let address1Parts = [];
        if (address.road) address1Parts.push(address.road);
        if (address.house_number) address1Parts.push(address.house_number);

        const address1 = address1Parts.join(' ');

        // Solo actualizar si el campo está vacío o tiene valor genérico
        const $address1Field = $(`#${prefix}_address_1`);
        if (!$address1Field.val() || $address1Field.val().length < 5) {
            $address1Field.val(address1);
        }

        // Ciudad
        const city = address.city || address.town || address.village || address.municipality || '';
        const $cityField = $(`#${prefix}_city`);
        if (!$cityField.val() && city) {
            $cityField.val(city);
        }

        // Código postal
        if (address.postcode) {
            const $postcodeField = $(`#${prefix}_postcode`);
            if (!$postcodeField.val()) {
                $postcodeField.val(address.postcode);
            }
        }

        // Actualizar campo de búsqueda
        const shortAddress = [address1, city].filter(Boolean).join(', ');
        if (shortAddress) {
            $('#restohub-address-search').val(shortAddress);
        }

        // Restaurar flag después de un momento
        setTimeout(function() {
            isUpdatingFromMap = false;
        }, 100);
    }

    /**
     * Actualiza el display de dirección seleccionada
     */
    function updateSelectedAddressDisplay(displayName) {
        const $address = $('#restohub-selected-address');
        const shortName = displayName.split(',').slice(0, 3).join(',');
        $address.text(shortName);

        // Actualizar subtitle del trigger con la dirección confirmada
        const $subtitle = $('#restohub-map-trigger-subtitle');
        if ($subtitle.length) {
            $subtitle.text('✓ ' + shortName);
            $subtitle.addClass('restohub-map-trigger-subtitle--confirmed');
        }
    }

    // =========================================================================
    // SCHEDULING: Selector de horarios
    // =========================================================================

    /**
     * Inicializa el selector de horarios (ASAP/Programar)
     */
    function initScheduleSelector() {
        const $container = $('#restohub-checkout-schedule');
        if ($container.length === 0) return;

        const scheduling = config.scheduling || {};
        if (!scheduling.enabled) return;

        const mode = scheduling.mode; // 'both' | 'schedule_only'
        const dates = scheduling.dates || [];
        const savedSchedule = scheduling.currentSchedule || {};

        // Si modo schedule_only: forzar programar, deshabilitar ASAP
        if (mode === 'schedule_only') {
            $container.find('.restohub-schedule-toggle-btn[data-schedule="asap"]').prop('disabled', true).removeClass('active');
            $container.find('.restohub-schedule-toggle-btn[data-schedule="scheduled"]').addClass('active');
            $('#restohub_schedule_type').val('scheduled');
            $('#restohub-schedule-asap-info').hide();
            $('#restohub-schedule-picker').show();
        }

        // Renderizar tabs de fecha
        renderDateTabs(dates, savedSchedule);

        // Event: click en toggle ASAP/Programar
        $container.off('click.schedule-toggle').on('click.schedule-toggle', '.restohub-schedule-toggle-btn:not(:disabled)', function() {
            const scheduleType = $(this).data('schedule');

            // Actualizar botones
            $container.find('.restohub-schedule-toggle-btn').removeClass('active');
            $(this).addClass('active');

            // Actualizar hidden input
            $('#restohub_schedule_type').val(scheduleType);

            if (scheduleType === 'asap') {
                $('#restohub-schedule-asap-info').show();
                $('#restohub-schedule-picker').hide();
                // Limpiar date/time
                $('#restohub_schedule_date').val('');
                $('#restohub_schedule_time').val('');
            } else {
                $('#restohub-schedule-asap-info').hide();
                $('#restohub-schedule-picker').show();
            }

            // Guardar en sesión
            saveScheduleToSession(scheduleType, '', '');
            updatePaymentButtonState();
        });

        // Event: click en tab de fecha
        $container.off('click.schedule-date').on('click.schedule-date', '.restohub-schedule-date-tab:not(.disabled)', function() {
            const date = $(this).data('date');
            const index = $(this).data('index');

            // Actualizar tabs activos
            $container.find('.restohub-schedule-date-tab').removeClass('active');
            $(this).addClass('active');

            // Renderizar slots
            renderSlots(dates[index] || {}, date);

            // Actualizar hidden
            $('#restohub_schedule_date').val(date);
            $('#restohub_schedule_time').val(''); // Limpiar time al cambiar fecha
        });

        // Event: click en slot
        $container.off('click.schedule-slot').on('click.schedule-slot', '.restohub-schedule-slot', function() {
            const time = $(this).data('time');
            const date = $('#restohub_schedule_date').val();

            // Marcar seleccionado
            $container.find('.restohub-schedule-slot').removeClass('selected');
            $(this).addClass('selected');

            // Actualizar hidden
            $('#restohub_schedule_time').val(time);

            // Guardar en sesión
            saveScheduleToSession('scheduled', date, time);
            updatePaymentButtonState();

            // Trigger recálculo
            $(document.body).trigger('update_checkout');
        });

        // Restaurar selección guardada
        if (savedSchedule.type === 'scheduled' && savedSchedule.date && savedSchedule.time) {
            // Activar la tab de la fecha guardada
            const $savedTab = $container.find('.restohub-schedule-date-tab[data-date="' + savedSchedule.date + '"]');
            if ($savedTab.length) {
                $savedTab.trigger('click');
                // Timeout para que los slots se rendericen antes de seleccionar
                setTimeout(function() {
                    $container.find('.restohub-schedule-slot[data-time="' + savedSchedule.time + '"]').addClass('selected');
                }, 50);
            }
        } else if (dates.length > 0) {
            // Seleccionar primera fecha con slots por defecto
            for (let i = 0; i < dates.length; i++) {
                if (dates[i].slots && dates[i].slots.length > 0) {
                    $container.find('.restohub-schedule-date-tab[data-index="' + i + '"]').trigger('click');
                    break;
                }
            }
        }
    }

    /**
     * Renderiza los tabs de fecha
     */
    function renderDateTabs(dates, savedSchedule) {
        const $tabs = $('#restohub-schedule-date-tabs');
        if ($tabs.length === 0 || !dates || dates.length === 0) return;

        let html = '';
        dates.forEach(function(day, index) {
            const isDisabled = day.closed ? ' disabled' : '';
            const isActive = '';

            html += '<button type="button" class="restohub-schedule-date-tab' + isActive + isDisabled + '"'
                + ' data-date="' + escapeHtmlAttr(day.date) + '"'
                + ' data-index="' + index + '">'
                + '<span class="restohub-schedule-date-tab-label">' + escapeHtmlContent(day.label) + '</span>'
                + '<span class="restohub-schedule-date-tab-day">' + escapeHtmlContent(day.day_name) + '</span>';

            if (day.closed) {
                html += '<span class="restohub-schedule-date-tab-day" style="color:#ff5722;">Cerrado</span>';
            } else if (day.special) {
                html += '<span class="restohub-schedule-date-tab-day" style="color:#ff9800;">' + escapeHtmlContent(day.special) + '</span>';
            }

            html += '</button>';
        });

        $tabs.html(html);
    }

    /**
     * Renderiza los slots de horario para un día
     */
    function renderSlots(dayData, date) {
        const $slotsContainer = $('#restohub-schedule-slots');
        if ($slotsContainer.length === 0) return;

        const slots = dayData.slots || [];
        const selectedTime = $('#restohub_schedule_time').val();

        if (slots.length === 0) {
            $slotsContainer.html(
                '<div class="restohub-schedule-slots-empty">'
                + (dayData.closed ? 'Cerrado este d\u00eda' : 'No hay horarios disponibles')
                + '</div>'
            );
            return;
        }

        let html = '<div class="restohub-schedule-slots-grid">';
        slots.forEach(function(slot) {
            const isSelected = (slot.time === selectedTime) ? ' selected' : '';
            html += '<button type="button" class="restohub-schedule-slot' + isSelected + '"'
                + ' data-time="' + escapeHtmlAttr(slot.time) + '">'
                + escapeHtmlContent(slot.label)
                + '</button>';
        });
        html += '</div>';

        $slotsContainer.html(html);
    }

    /**
     * Guarda la selección de schedule en la sesión del servidor
     */
    function saveScheduleToSession(type, date, time) {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'restohub_save_schedule',
                nonce: config.nonce,
                schedule_type: type,
                schedule_date: date,
                schedule_time: time
            }
        });
    }

    /**
     * Refresca los slots del schedule (llamado desde updated_checkout)
     */
    function reinitScheduleSelector() {
        const $picker = $('#restohub-schedule-picker');
        // Solo refrescar si el picker está visible
        if ($picker.length === 0 || !$picker.is(':visible')) return;

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'restohub_get_schedule_slots',
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Actualizar datos en config
                    config.scheduling = config.scheduling || {};
                    config.scheduling.mode = response.data.mode;
                    config.scheduling.isStoreOpen = response.data.isStoreOpen;
                    config.scheduling.dates = response.data.dates;

                    // Re-renderizar tabs
                    renderDateTabs(response.data.dates, {});

                    // Re-seleccionar la fecha activa actual
                    const currentDate = $('#restohub_schedule_date').val();
                    if (currentDate) {
                        const $tab = $('#restohub-schedule-date-tabs').find('[data-date="' + currentDate + '"]');
                        if ($tab.length) {
                            $tab.addClass('active');
                            const index = $tab.data('index');
                            const dates = response.data.dates || [];
                            if (dates[index]) {
                                renderSlots(dates[index], currentDate);
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Actualiza el texto informativo del schedule según el modo de entrega
     */
    function updateScheduleInfoText(mode) {
        const $asapText = $('.restohub-schedule-asap-text');
        if ($asapText.length === 0) return;

        if (mode === 'pickup') {
            $asapText.text('Retiro estimado: 15-25 min');
        } else {
            $asapText.text('Entrega estimada: 30-45 min');
        }
    }

    /**
     * Inicializa el selector de propina en el order review
     */
    function initTipSelector() {
        const $select = $('.restohub-tip-select');
        if ($select.length === 0) return;

        $select.off('change.restoHubTip').on('change.restoHubTip', function() {
            const percentage = parseFloat($(this).val()) || 0;
            const nonce = $(this).data('nonce');

            $.ajax({
                url: config.ajaxUrl || wc_checkout_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'restohub_set_tip',
                    nonce: nonce,
                    percentage: percentage
                },
                success: function(response) {
                    if (response.success) {
                        $(document.body).trigger('update_checkout');
                    }
                }
            });
        });
    }

})(jQuery);
