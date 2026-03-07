/**
 * RestoHub - Location Modal (Estilo Rappi)
 *
 * Modal de ubicación con flujo Delivery/Retiro
 */

(function($) {
    'use strict';

    // =========================================================================
    // Estado del Modal
    // =========================================================================

    const STATE = {
        INITIAL: 'initial',
        DELIVERY_SEARCH: 'delivery-search',
        DELIVERY_CONFIRM: 'delivery-confirm',
        PICKUP_SELECT: 'pickup-select'
    };

    let currentState = STATE.INITIAL;
    let currentMode = 'delivery'; // 'delivery' o 'pickup'

    // Variables del módulo
    let confirmMap = null;
    let userMarker = null;
    let searchTimeout = null;
    let selectedLocation = {
        lat: null,
        lng: null,
        address: '',
        full_address: '',
        street: '',
        city: '',
        state: '',
        postcode: '',
        address_type: '',
        address_detail: '',
        delivery_type: 'delivery',
        store_id: '',
        store_name: '',
        has_coverage: false
    };

    // Configuración desde PHP
    const config = window.restoHubLocationModal || {};
    const mapConfig = config.map || {};
    const nominatimConfig = config.nominatim || {};
    const strings = config.strings || {};
    const stores = config.stores || [];

    // Key para localStorage
    const STORAGE_KEY = 'restohub_customer_location';

    // =========================================================================
    // Inicialización
    // =========================================================================

    $(document).ready(function() {
        initEventListeners();

        // Cargar ubicación guardada si existe
        if (config.savedLocation && config.savedLocation.lat) {
            loadSavedLocation();
        }

        // Auto-abrir modal si no hay ubicación guardada (ni en server ni en localStorage)
        if (!config.hasLocation && !loadFromLocalStorage()) {
            openModal();
        }
    });

    /**
     * Carga la ubicación guardada (prioridad: PHP config > localStorage)
     */
    function loadSavedLocation() {
        // Primero intentar desde config PHP (sesión/cookie)
        let saved = config.savedLocation;

        // Si no hay en PHP, intentar localStorage
        if (!saved || !saved.lat) {
            saved = loadFromLocalStorage();
        }

        if (saved && saved.lat) {
            selectedLocation = {
                lat: parseFloat(saved.lat),
                lng: parseFloat(saved.lng),
                address: saved.address || '',
                full_address: saved.full_address || saved.address || '',
                street: saved.street || '',
                city: saved.city || '',
                state: saved.state || '',
                postcode: saved.postcode || '',
                address_type: saved.address_type || '',
                address_detail: saved.address_detail || '',
                delivery_type: saved.delivery_type || 'delivery',
                store_id: saved.store_id || '',
                store_name: saved.store_name || '',
                has_coverage: saved.has_coverage || false
            };
            currentMode = selectedLocation.delivery_type;
        }
    }

    /**
     * Guarda la ubicación en localStorage
     */
    function saveToLocalStorage(data) {
        try {
            const locationData = {
                lat: data.lat,
                lng: data.lng,
                address: data.address,
                full_address: data.full_address,
                street: data.street || '',
                city: data.city || '',
                state: data.state || '',
                postcode: data.postcode || '',
                address_type: data.address_type,
                address_detail: data.address_detail,
                delivery_type: data.delivery_type,
                store_id: data.store_id,
                store_name: data.store_name,
                timestamp: Date.now()
            };
            localStorage.setItem(STORAGE_KEY, JSON.stringify(locationData));
        } catch (e) {
            console.warn('WCUDC: No se pudo guardar en localStorage', e);
        }
    }

    /**
     * Carga la ubicación desde localStorage
     */
    function loadFromLocalStorage() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) {
                const data = JSON.parse(stored);
                // Verificar que no sea muy viejo (7 días)
                if (data.timestamp && (Date.now() - data.timestamp) < 7 * 24 * 60 * 60 * 1000) {
                    return data;
                }
            }
        } catch (e) {
            console.warn('WCUDC: No se pudo leer localStorage', e);
        }
        return null;
    }

    // =========================================================================
    // Event Listeners
    // =========================================================================

    function initEventListeners() {
        // Abrir modal (múltiples selectores para compatibilidad con tema)
        $(document).on('click', '#restohub-open-location-modal, .restohub-open-modal-btn, #restohub-header-mode-btn, .btn-delivery', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openModal();
        });

        // Cerrar modal
        $(document).on('click', '#restohub-modal-close, #restohub-cancel-btn', handleCloseModal);
        $(document).on('click', '.restohub-modal-overlay', function(e) {
            if (e.target === this) {
                handleCloseModal();
            }
        });

        // Tecla Escape
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && isModalVisible()) {
                handleCloseModal();
            }
        });

        // Toggle Delivery / Retiro
        $(document).on('click', '.restohub-mode-toggle-btn', function() {
            const mode = $(this).data('mode');
            switchMode(mode);
        });

        // === DELIVERY: Búsqueda de dirección (ambos inputs) ===
        $(document).on('input', '#restohub-address-input, #restohub-address-display', function() {
            const query = $(this).val().trim();
            const $input = $(this);
            clearTimeout(searchTimeout);

            if (query.length < 3) {
                hideAddressResults($input);
                return;
            }

            searchTimeout = setTimeout(function() {
                searchAddress(query, $input);
            }, 500);
        });

        // Focus en el input de confirmación también permite editar
        $(document).on('focus', '#restohub-address-display', function() {
            $(this).select(); // Seleccionar todo el texto para facilitar edición
        });

        // Click en resultado de búsqueda (ambos contenedores)
        $(document).on('click', '.restohub-address-result-item', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const lat = parseFloat($(this).data('lat'));
            const lng = parseFloat($(this).data('lng'));
            const address = $(this).data('address');
            const shortAddress = $(this).data('short') || $(this).find('.restohub-result-main').text();

            // Datos estructurados de Nominatim
            const addressParts = {
                street: $(this).data('street') || '',
                city: $(this).data('city') || '',
                state: $(this).data('state') || '',
                postcode: $(this).data('postcode') || ''
            };

            // Ocultar resultados
            hideAddressResults();

            // Seleccionar la dirección con datos estructurados
            selectDeliveryAddress(lat, lng, shortAddress, address, addressParts);
        });

        // Geolocalización
        $(document).on('click', '#restohub-geolocate-btn', geolocateUser);

        // Botón "Ajústalo" - volver a búsqueda
        $(document).on('click', '#restohub-adjust-address-btn', function() {
            switchToDeliverySearch();
        });

        // === Tags de indicaciones ===
        $(document).on('click', '.restohub-address-tag', function() {
            const type = $(this).data('type');
            selectAddressTag(type);
        });

        // Input detalle de dirección
        $(document).on('input', '#restohub-address-detail', function() {
            selectedLocation.address_detail = $(this).val();
        });

        // === RETIRO: Búsqueda de tiendas ===
        $(document).on('input', '#restohub-store-search', function() {
            const query = $(this).val().trim().toLowerCase();
            filterStores(query);
        });

        // Click en store card
        $(document).on('click', '.restohub-store-card', function() {
            selectStore($(this));
        });

        // === Confirmar ubicación ===
        $(document).on('click', '#restohub-confirm-btn', confirmLocation);

        // Cerrar resultados al hacer click fuera
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.restohub-address-input-wrapper').length) {
                hideAddressResults();
            }
        });

        // Click en icono de edición
        $(document).on('click', '.restohub-input-edit-icon', function() {
            $(this).siblings('.restohub-address-input').focus().select();
        });
    }

    // =========================================================================
    // Modal Control
    // =========================================================================

    function openModal() {
        const $modal = $('#restohub-location-modal');

        // Remover el style inline de display:none y agregar clase visible
        $modal.removeAttr('style').addClass('restohub-modal-visible');
        $('body').addClass('restohub-modal-open');

        // Establecer estado inicial según modo actual
        if (currentMode === 'pickup') {
            switchMode('pickup');
        } else {
            switchMode('delivery');
        }

        // Si ya hay dirección seleccionada en delivery, mostrar confirmación
        if (currentMode === 'delivery' && selectedLocation.lat) {
            switchToDeliveryConfirm();
        }
    }

    function handleCloseModal() {
        closeModal();
    }

    function closeModal() {
        const $modal = $('#restohub-location-modal');
        $modal.removeClass('restohub-modal-visible');

        // Esperar a que termine la transición y ocultar
        setTimeout(function() {
            $modal.css('display', 'none');
        }, 300);

        $('body').removeClass('restohub-modal-open');
    }

    function isModalVisible() {
        return $('#restohub-location-modal').is(':visible');
    }

    // =========================================================================
    // Mode Toggle (Delivery / Pickup)
    // =========================================================================

    function switchMode(mode) {
        currentMode = mode;

        // Actualizar botones del toggle
        $('.restohub-mode-toggle-btn').removeClass('active');
        $(`.restohub-mode-toggle-btn[data-mode="${mode}"]`).addClass('active');

        // Actualizar vistas
        $('.restohub-view').removeClass('active');

        if (mode === 'delivery') {
            $('#restohub-delivery-view').addClass('active');
            updateModalTitle(strings.modalTitle || 'Hola! ¿Cómo quieres tu pedido?');

            // Mostrar estado de búsqueda o confirmación
            if (selectedLocation.lat && selectedLocation.delivery_type === 'delivery') {
                switchToDeliveryConfirm();
            } else {
                switchToDeliverySearch();
            }

            // Actualizar texto del botón
            $('#restohub-confirm-btn').text(strings.saveAddress || 'Guardar Dirección');
        } else {
            $('#restohub-pickup-view').addClass('active');
            updateModalTitle(strings.modalTitle || 'Hola! ¿Cómo quieres tu pedido?');
            $('#restohub-confirm-btn').text(strings.confirmStore || 'Confirmar Tienda');

            // Verificar si hay tienda seleccionada
            updateConfirmButton();
        }
    }

    function updateModalTitle(title) {
        $('#restohub-modal-title').text(title);
    }

    // =========================================================================
    // Delivery Flow
    // =========================================================================

    function switchToDeliverySearch() {
        currentState = STATE.DELIVERY_SEARCH;
        $('.restohub-delivery-state').removeClass('active');
        $('#restohub-delivery-search').addClass('active');

        // Limpiar input y enfocar
        $('#restohub-address-input').val(selectedLocation.address || '').focus();
        hideAddressResults();

        // Deshabilitar botón confirmar
        $('#restohub-confirm-btn').prop('disabled', true);
    }

    function switchToDeliveryConfirm() {
        currentState = STATE.DELIVERY_CONFIRM;
        $('.restohub-delivery-state').removeClass('active');
        $('#restohub-delivery-confirm').addClass('active');

        // Actualizar display de dirección
        $('#restohub-address-display').val(selectedLocation.address);
        $('#restohub-full-address').text(selectedLocation.full_address);

        // Inicializar mapa de confirmación
        setTimeout(function() {
            initConfirmMap();
        }, 100);

        // Verificar cobertura y actualizar UI
        checkAndDisplayCoverage();

        // Habilitar botón confirmar
        updateConfirmButton();
    }

    function selectDeliveryAddress(lat, lng, shortAddress, fullAddress, addressParts) {
        selectedLocation.lat = lat;
        selectedLocation.lng = lng;
        selectedLocation.address = shortAddress;
        selectedLocation.full_address = fullAddress;
        selectedLocation.delivery_type = 'delivery';

        // Guardar datos estructurados de Nominatim
        if (addressParts) {
            selectedLocation.street = addressParts.street || '';
            selectedLocation.city = addressParts.city || '';
            selectedLocation.state = addressParts.state || '';
            selectedLocation.postcode = addressParts.postcode || '';
        }

        // Verificar cobertura
        const coverage = checkLocalCoverage(lat, lng);
        selectedLocation.has_coverage = coverage.has_coverage;
        selectedLocation.store_id = coverage.store_id;
        selectedLocation.store_name = coverage.store_name;

        // Cambiar al estado de confirmación
        switchToDeliveryConfirm();
    }

    function searchAddress(query, $input) {
        // Determinar qué contenedor de resultados usar
        const isConfirmInput = $input && $input.attr('id') === 'restohub-address-display';
        const $results = isConfirmInput ? $('#restohub-address-results-confirm') : $('#restohub-address-results');

        const nominatimUrl = nominatimConfig.url || 'https://nominatim.openstreetmap.org';
        const countryCode = nominatimConfig.countryCode || '';

        $results.html('<div class="restohub-address-results-loading"><span class="restohub-loading-spinner"></span> Buscando...</div>').addClass('show');

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
                    $results.html('<div class="restohub-address-results-empty">No se encontraron resultados</div>');
                    return;
                }

                let html = '';
                results.forEach(function(r) {
                    // Crear nombre corto más legible
                    const parts = r.display_name.split(',');
                    const mainPart = parts.slice(0, 2).join(',').trim();
                    const secondaryPart = parts.slice(2, 4).join(',').trim();

                    // Extraer datos estructurados de Nominatim
                    const addr = r.address || {};
                    const street = [addr.road || '', addr.house_number || ''].filter(Boolean).join(' ');
                    const city = addr.city || addr.town || addr.village || addr.municipality || '';
                    const state = addr.state || addr.region || '';
                    const postcode = addr.postcode || '';

                    html += `
                        <div class="restohub-address-result-item"
                             data-lat="${r.lat}"
                             data-lng="${r.lon}"
                             data-address="${escapeHtml(r.display_name)}"
                             data-short="${escapeHtml(mainPart)}"
                             data-street="${escapeHtml(street)}"
                             data-city="${escapeHtml(city)}"
                             data-state="${escapeHtml(state)}"
                             data-postcode="${escapeHtml(postcode)}">
                            <span class="restohub-result-icon">📍</span>
                            <div class="restohub-result-content">
                                <span class="restohub-result-main">${escapeHtml(mainPart)}</span>
                                <span class="restohub-result-secondary">${escapeHtml(secondaryPart)}</span>
                            </div>
                        </div>
                    `;
                });

                $results.html(html);
            },
            error: function() {
                $results.html('<div class="restohub-address-results-empty">Error en la búsqueda</div>');
            }
        });
    }

    /**
     * Escapa HTML para prevenir XSS
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function hideAddressResults($input) {
        // Ocultar ambos contenedores de resultados
        $('#restohub-address-results, #restohub-address-results-confirm').removeClass('show').empty();
    }

    function geolocateUser() {
        if (!navigator.geolocation) {
            showToast('Geolocalización no disponible');
            return;
        }

        const $btn = $('#restohub-geolocate-btn');
        $btn.addClass('locating').prop('disabled', true);

        navigator.geolocation.getCurrentPosition(
            function(position) {
                $btn.removeClass('locating').prop('disabled', false);
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;

                // Reverse geocoding para obtener dirección
                reverseGeocode(lat, lng, function(address, fullAddress, addressParts) {
                    selectDeliveryAddress(lat, lng, address, fullAddress, addressParts);
                });
            },
            function(error) {
                $btn.removeClass('locating').prop('disabled', false);
                showToast('No pudimos obtener tu ubicación');
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }

    function reverseGeocode(lat, lng, callback) {
        const nominatimUrl = nominatimConfig.url || 'https://nominatim.openstreetmap.org';

        $.ajax({
            url: `${nominatimUrl}/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`,
            type: 'GET',
            dataType: 'json',
            headers: { 'Accept-Language': 'es' },
            success: function(result) {
                if (result && result.display_name) {
                    const shortAddress = result.display_name.split(',').slice(0, 3).join(', ');

                    // Extraer datos estructurados
                    const addr = result.address || {};
                    const addressParts = {
                        street: [addr.road || '', addr.house_number || ''].filter(Boolean).join(' '),
                        city: addr.city || addr.town || addr.village || addr.municipality || '',
                        state: addr.state || addr.region || '',
                        postcode: addr.postcode || ''
                    };

                    callback(shortAddress, result.display_name, addressParts);
                } else {
                    callback('Ubicación seleccionada', 'Ubicación seleccionada', null);
                }
            },
            error: function() {
                callback('Ubicación seleccionada', 'Ubicación seleccionada', null);
            }
        });
    }

    // =========================================================================
    // Confirm Map
    // =========================================================================

    function initConfirmMap() {
        const $mapContainer = $('#restohub-confirm-map');
        if ($mapContainer.length === 0) return;

        const lat = selectedLocation.lat || mapConfig.defaultLat || -12.0464;
        const lng = selectedLocation.lng || mapConfig.defaultLng || -77.0428;

        // Si el mapa ya existe, solo actualizar vista
        if (confirmMap) {
            confirmMap.setView([lat, lng], 16);
            if (userMarker) {
                userMarker.setLatLng([lat, lng]);
            }
            confirmMap.invalidateSize();
            return;
        }

        // Crear mapa
        confirmMap = L.map('restohub-confirm-map', {
            center: [lat, lng],
            zoom: 16,
            zoomControl: true,
            dragging: true,
            scrollWheelZoom: false
        });

        // Tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap',
            maxZoom: 19
        }).addTo(confirmMap);

        // Marcador del usuario (draggable)
        userMarker = L.marker([lat, lng], {
            draggable: true,
            icon: createUserMarkerIcon()
        }).addTo(confirmMap);

        // Evento: Marcador arrastrado
        userMarker.on('dragend', function() {
            const pos = userMarker.getLatLng();
            onMapMarkerMoved(pos.lat, pos.lng);
        });

        // Evento: Click en el mapa
        confirmMap.on('click', function(e) {
            userMarker.setLatLng(e.latlng);
            onMapMarkerMoved(e.latlng.lat, e.latlng.lng);
        });

        // Invalidar tamaño
        setTimeout(function() {
            confirmMap.invalidateSize();
        }, 100);
    }

    function createUserMarkerIcon() {
        return L.divIcon({
            className: 'restohub-user-marker',
            html: `
                <div class="restohub-user-marker-pin">
                    <div class="restohub-user-marker-inner"></div>
                </div>
            `,
            iconSize: [32, 42],
            iconAnchor: [16, 42]
        });
    }

    function onMapMarkerMoved(lat, lng) {
        selectedLocation.lat = lat;
        selectedLocation.lng = lng;

        // Reverse geocoding
        reverseGeocode(lat, lng, function(address, fullAddress, addressParts) {
            selectedLocation.address = address;
            selectedLocation.full_address = fullAddress;

            // Guardar datos estructurados
            if (addressParts) {
                selectedLocation.street = addressParts.street || '';
                selectedLocation.city = addressParts.city || '';
                selectedLocation.state = addressParts.state || '';
                selectedLocation.postcode = addressParts.postcode || '';
            }

            $('#restohub-address-display').val(address);
            $('#restohub-full-address').text(fullAddress);
        });

        // Verificar cobertura
        checkAndDisplayCoverage();
    }

    function checkAndDisplayCoverage() {
        const coverage = checkLocalCoverage(selectedLocation.lat, selectedLocation.lng);
        selectedLocation.has_coverage = coverage.has_coverage;
        selectedLocation.store_id = coverage.store_id;
        selectedLocation.store_name = coverage.store_name;

        const $status = $('#restohub-coverage-status');

        if (coverage.has_coverage) {
            $status.removeClass('coverage-no').addClass('coverage-ok');
            $status.find('.restohub-coverage-icon').text('✅');
            $status.find('.restohub-coverage-text').text(`¡Llegamos a tu zona! Envío desde ${coverage.store_name}`);
        } else {
            $status.removeClass('coverage-ok').addClass('coverage-no');
            $status.find('.restohub-coverage-icon').text('⚠️');
            $status.find('.restohub-coverage-text').text('No llegamos a tu zona con delivery. Puedes elegir retiro en tienda.');
        }

        $status.show();
        updateConfirmButton();
    }

    // =========================================================================
    // Coverage Check (Local)
    // =========================================================================

    function checkLocalCoverage(lat, lng) {
        for (let i = 0; i < stores.length; i++) {
            const store = stores[i];
            if (store.polygon && store.polygon.length >= 3) {
                if (isPointInPolygon({ lat: lat, lng: lng }, store.polygon)) {
                    return {
                        has_coverage: true,
                        store_id: store.id,
                        store_name: store.name
                    };
                }
            }
        }

        return {
            has_coverage: false,
            store_id: '',
            store_name: ''
        };
    }

    function isPointInPolygon(point, polygon) {
        const x = point.lng;
        const y = point.lat;
        let inside = false;

        for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
            const xi = polygon[i].lng, yi = polygon[i].lat;
            const xj = polygon[j].lng, yj = polygon[j].lat;

            if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) {
                inside = !inside;
            }
        }

        return inside;
    }

    // =========================================================================
    // Address Tags
    // =========================================================================

    function selectAddressTag(type) {
        // Deseleccionar todos y seleccionar el clickeado
        $('.restohub-address-tag').removeClass('selected');
        $(`.restohub-address-tag[data-type="${type}"]`).addClass('selected');

        selectedLocation.address_type = type;
        $('#restohub-selected-address-type').val(type);
    }

    // =========================================================================
    // Pickup Flow (Store Selection)
    // =========================================================================

    function filterStores(query) {
        $('.restohub-store-card').each(function() {
            const name = $(this).data('store-name').toLowerCase();
            const address = $(this).data('store-address').toLowerCase();

            if (name.includes(query) || address.includes(query) || query === '') {
                $(this).removeClass('hidden');
            } else {
                $(this).addClass('hidden');
            }
        });
    }

    function selectStore($card) {
        // Deseleccionar todas
        $('.restohub-store-card').removeClass('selected');
        $('.restohub-radio-circle').removeClass('checked').empty();

        // Seleccionar la clickeada
        $card.addClass('selected');
        $card.find('.restohub-radio-circle').addClass('checked').html('<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>');

        // Guardar datos
        selectedLocation.store_id = $card.data('store-id');
        selectedLocation.store_name = $card.data('store-name');
        selectedLocation.store_address = $card.data('store-address');
        selectedLocation.lat = parseFloat($card.data('store-lat'));
        selectedLocation.lng = parseFloat($card.data('store-lng'));
        selectedLocation.delivery_type = 'pickup';
        selectedLocation.has_coverage = true; // Siempre true para retiro

        // Actualizar hidden inputs
        $('#restohub-selected-store-id').val(selectedLocation.store_id);
        $('#restohub-selected-delivery-type').val('pickup');

        updateConfirmButton();
    }

    // =========================================================================
    // Confirm Button
    // =========================================================================

    function updateConfirmButton() {
        const $btn = $('#restohub-confirm-btn');
        let isValid = false;

        if (currentMode === 'delivery') {
            // Para delivery: necesita lat/lng y (cobertura O permitir sin cobertura)
            isValid = selectedLocation.lat && selectedLocation.lng;
        } else {
            // Para retiro: necesita tienda seleccionada
            isValid = selectedLocation.store_id && selectedLocation.store_id !== '';
        }

        $btn.prop('disabled', !isValid);
    }

    // =========================================================================
    // Confirm Location
    // =========================================================================

    function confirmLocation() {
        if (currentMode === 'delivery' && !selectedLocation.lat) {
            showToast('Por favor selecciona una dirección');
            return;
        }

        if (currentMode === 'pickup' && !selectedLocation.store_id) {
            showToast('Por favor selecciona una tienda');
            return;
        }

        const $btn = $('#restohub-confirm-btn');
        $btn.prop('disabled', true).html('<span class="restohub-loading-spinner"></span> Guardando...');

        // Preparar datos
        const data = {
            action: 'restohub_save_location',
            nonce: config.nonce,
            lat: selectedLocation.lat,
            lng: selectedLocation.lng,
            address: selectedLocation.address,
            full_address: selectedLocation.full_address,
            short_address: selectedLocation.address,
            street: selectedLocation.street,
            city: selectedLocation.city,
            state: selectedLocation.state,
            postcode: selectedLocation.postcode,
            address_type: selectedLocation.address_type,
            address_detail: $('#restohub-address-detail').val() || '',
            delivery_type: currentMode,
            store_id: selectedLocation.store_id,
            store_name: selectedLocation.store_name
        };

        // Guardar via AJAX
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    // Guardar también en localStorage para persistencia cross-page
                    saveToLocalStorage({
                        lat: data.lat,
                        lng: data.lng,
                        address: data.address,
                        full_address: data.full_address,
                        street: data.street,
                        city: data.city,
                        state: data.state,
                        postcode: data.postcode,
                        address_type: data.address_type,
                        address_detail: data.address_detail,
                        delivery_type: data.delivery_type,
                        store_id: data.store_id,
                        store_name: data.store_name
                    });

                    // Actualizar header bar
                    updateHeaderBar();

                    // Cerrar modal
                    config.hasLocation = true;
                    closeModal();

                    // Toast de éxito
                    showToast('✅ Ubicación guardada');

                    // Recargar para actualizar precios
                    setTimeout(function() {
                        window.location.reload();
                    }, 800);
                } else {
                    showToast('❌ ' + (response.data?.message || 'Error al guardar'));
                    resetConfirmButton();
                }
            },
            error: function() {
                showToast('❌ Error de conexión');
                resetConfirmButton();
            }
        });
    }

    function resetConfirmButton() {
        const $btn = $('#restohub-confirm-btn');
        $btn.prop('disabled', false);

        if (currentMode === 'pickup') {
            $btn.text(strings.confirmStore || 'Confirmar Tienda');
        } else {
            $btn.text(strings.saveAddress || 'Guardar Dirección');
        }
    }

    // =========================================================================
    // Header Bar
    // =========================================================================

    function updateHeaderBar() {
        const $bar = $('#restohub-header-bar');
        const $modeBtn = $('#restohub-header-mode-btn');
        const $locationText = $('#restohub-location-display');

        // Actualizar clase
        $bar.removeClass('no-location').addClass('has-location');

        // Actualizar modo
        if (currentMode === 'pickup') {
            $modeBtn.find('.restohub-header-mode-icon').text('🏪');
            $modeBtn.find('.restohub-header-mode-text').text('Retiro');
            $locationText.text('Retiro en ' + selectedLocation.store_name);
        } else {
            $modeBtn.find('.restohub-header-mode-icon').text('🛵');
            $modeBtn.find('.restohub-header-mode-text').text('Delivery');
            $locationText.text(selectedLocation.address);
        }
    }

    // =========================================================================
    // Toast Notifications
    // =========================================================================

    function showToast(message) {
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
        }, 3000);
    }

})(jQuery);
