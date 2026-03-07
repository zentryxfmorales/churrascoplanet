/**
 * WC Uber Direct Connect - Location Modal
 *
 * Modal de ubicación que aparece al ingresar a la tienda
 * para verificar cobertura y asignar tienda.
 */

(function($) {
    'use strict';

    // Variables del módulo
    let map = null;
    let marker = null;
    let storeMarkers = [];
    let polygonLayers = [];
    let searchTimeout = null;
    let selectedLocation = null;

    // Configuración desde PHP
    const config = window.wcudcLocationModal || {};
    const mapConfig = config.map || {};
    const nominatimConfig = config.nominatim || {};
    const strings = config.strings || {};
    const stores = config.stores || [];

    /**
     * Inicialización
     */
    $(document).ready(function() {
        initEventListeners();

        // Si no tiene ubicación guardada, mostrar modal automáticamente
        if (!config.hasLocation) {
            setTimeout(function() {
                openModal();
            }, 500);
        }
    });

    /**
     * Inicializa los event listeners
     */
    function initEventListeners() {
        // Abrir modal
        $(document).on('click', '#wcudc-open-location-modal', function(e) {
            e.preventDefault();
            openModal();
        });

        // Cerrar modal
        $(document).on('click', '#wcudc-modal-close', closeModal);
        $(document).on('click', '.wcudc-modal-overlay', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Tecla Escape para cerrar
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#wcudc-location-modal').is(':visible')) {
                closeModal();
            }
        });

        // Búsqueda de dirección
        $(document).on('input', '#wcudc-modal-search', function() {
            const query = $(this).val().trim();
            clearTimeout(searchTimeout);

            if (query.length < 3) {
                $('#wcudc-modal-search-results').empty().hide();
                return;
            }

            searchTimeout = setTimeout(function() {
                searchAddress(query);
            }, 500);
        });

        // Geolocalización
        $(document).on('click', '#wcudc-modal-geolocate', geolocateUser);

        // Cambio de tipo de entrega
        $(document).on('change', 'input[name="wcudc_delivery_type"]', function() {
            const type = $(this).val();
            handleDeliveryTypeChange(type);
        });

        // Cambio de tienda para retiro
        $(document).on('change', '#wcudc-pickup-store-select', function() {
            const $selected = $(this).find(':selected');
            selectedLocation.store_id = $selected.val();
            selectedLocation.store_name = $selected.text().split(' - ')[0];
            selectedLocation.store_address = $selected.data('address');

            // Mover mapa a la tienda
            const lat = parseFloat($selected.data('lat'));
            const lng = parseFloat($selected.data('lng'));
            if (lat && lng) {
                map.setView([lat, lng], 15);
            }

            updateConfirmButton();
        });

        // Confirmar ubicación
        $(document).on('click', '#wcudc-confirm-location', confirmLocation);

        // Click en resultados de búsqueda
        $(document).on('click', '.wcudc-search-result-item', function() {
            const lat = parseFloat($(this).data('lat'));
            const lng = parseFloat($(this).data('lng'));
            const address = $(this).data('address');

            selectLocation(lat, lng, address);
            $('#wcudc-modal-search').val($(this).find('.wcudc-result-text').text());
            $('#wcudc-modal-search-results').hide();
        });
    }

    /**
     * Abre el modal
     */
    function openModal() {
        const $modal = $('#wcudc-location-modal');
        $modal.fadeIn(200);
        $('body').addClass('wcudc-modal-open');

        // Inicializar mapa si no existe
        setTimeout(function() {
            if (!map) {
                initMap();
            } else {
                map.invalidateSize();
            }
        }, 300);
    }

    /**
     * Cierra el modal
     */
    function closeModal() {
        $('#wcudc-location-modal').fadeOut(200);
        $('body').removeClass('wcudc-modal-open');
    }

    /**
     * Inicializa el mapa
     */
    function initMap() {
        const $mapContainer = $('#wcudc-modal-map');
        if ($mapContainer.length === 0) return;

        // Coordenadas iniciales
        let initialLat = mapConfig.defaultLat || -12.0464;
        let initialLng = mapConfig.defaultLng || -77.0428;
        let initialZoom = mapConfig.defaultZoom || 13;

        // Si hay ubicación guardada, usarla
        if (config.savedLocation && config.savedLocation.lat) {
            initialLat = parseFloat(config.savedLocation.lat);
            initialLng = parseFloat(config.savedLocation.lng);
            initialZoom = 15;

            selectedLocation = {
                lat: initialLat,
                lng: initialLng,
                address: config.savedLocation.address || '',
                short_address: config.savedLocation.short_address || '',
                delivery_type: config.savedLocation.delivery_type || 'delivery',
                store_id: config.savedLocation.store_id || '',
                store_name: config.savedLocation.store_name || '',
                has_coverage: config.savedLocation.has_coverage || false
            };
        }

        // Crear mapa
        map = L.map('wcudc-modal-map', {
            center: [initialLat, initialLng],
            zoom: initialZoom,
            zoomControl: true
        });

        // Capa de tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap',
            maxZoom: 19
        }).addTo(map);

        // Dibujar polígonos de cobertura de las tiendas
        drawStorePolygons();

        // Agregar marcadores de tiendas
        addStoreMarkers();

        // Marcador del usuario (draggable)
        marker = L.marker([initialLat, initialLng], {
            draggable: true,
            icon: createUserMarkerIcon()
        }).addTo(map);

        // Evento: Marcador arrastrado
        marker.on('dragend', function() {
            const pos = marker.getLatLng();
            onMarkerMoved(pos.lat, pos.lng);
        });

        // Evento: Click en el mapa
        map.on('click', function(e) {
            marker.setLatLng(e.latlng);
            onMarkerMoved(e.latlng.lat, e.latlng.lng);
        });

        // Si hay ubicación previa, mostrar estado
        if (selectedLocation) {
            updateCoverageDisplay(selectedLocation.has_coverage, selectedLocation.store_name);
            showDeliveryOptions(selectedLocation.has_coverage);
            updateConfirmButton();

            if (selectedLocation.delivery_type === 'pickup') {
                $('input[name="wcudc_delivery_type"][value="pickup"]').prop('checked', true);
                handleDeliveryTypeChange('pickup');
            }
        }

        // Forzar recálculo del tamaño
        setTimeout(function() {
            map.invalidateSize();
        }, 100);
    }

    /**
     * Crea el icono del marcador del usuario
     */
    function createUserMarkerIcon() {
        return L.divIcon({
            className: 'wcudc-user-marker',
            html: `
                <div class="wcudc-user-marker-pin">
                    <div class="wcudc-user-marker-inner"></div>
                </div>
                <div class="wcudc-user-marker-pulse"></div>
            `,
            iconSize: [36, 48],
            iconAnchor: [18, 48]
        });
    }

    /**
     * Dibuja los polígonos de cobertura de las tiendas
     */
    function drawStorePolygons() {
        stores.forEach(function(store) {
            if (store.polygon && store.polygon.length >= 3) {
                const latlngs = store.polygon.map(p => [p.lat, p.lng]);
                const polygon = L.polygon(latlngs, {
                    color: '#0073aa',
                    fillColor: '#0073aa',
                    fillOpacity: 0.1,
                    weight: 2
                }).addTo(map);

                polygon.bindTooltip(store.name, {
                    permanent: false,
                    direction: 'center'
                });

                polygonLayers.push(polygon);
            }
        });
    }

    /**
     * Agrega marcadores de las tiendas
     */
    function addStoreMarkers() {
        stores.forEach(function(store) {
            const storeIcon = L.divIcon({
                className: 'wcudc-store-marker',
                html: '<div class="wcudc-store-marker-icon">🏪</div>',
                iconSize: [32, 32],
                iconAnchor: [16, 16]
            });

            const storeMarker = L.marker([store.lat, store.lng], {
                icon: storeIcon
            }).addTo(map);

            storeMarker.bindPopup(`<strong>${store.name}</strong><br>${store.address}`);
            storeMarkers.push(storeMarker);
        });
    }

    /**
     * Busca una dirección con Nominatim
     */
    function searchAddress(query) {
        const $results = $('#wcudc-modal-search-results');
        const nominatimUrl = nominatimConfig.url || 'https://nominatim.openstreetmap.org';
        const countryCode = nominatimConfig.countryCode || '';

        $results.html('<div class="wcudc-search-loading">🔍 ' + (strings.locating || 'Buscando...') + '</div>').show();

        let url = `${nominatimUrl}/search?format=json&q=${encodeURIComponent(query)}&limit=5`;
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
                    $results.html('<div class="wcudc-search-no-results">' + (strings.searchError || 'Sin resultados') + '</div>');
                    return;
                }

                let html = '';
                results.forEach(function(r) {
                    const shortName = r.display_name.split(',').slice(0, 3).join(',');
                    html += `
                        <div class="wcudc-search-result-item" data-lat="${r.lat}" data-lng="${r.lon}" data-address="${r.display_name}">
                            <span class="wcudc-result-icon">📍</span>
                            <span class="wcudc-result-text">${shortName}</span>
                        </div>
                    `;
                });

                $results.html(html).show();
            },
            error: function() {
                $results.html('<div class="wcudc-search-error">' + (strings.searchError || 'Error en búsqueda') + '</div>');
            }
        });
    }

    /**
     * Geolocaliza al usuario
     */
    function geolocateUser() {
        if (!navigator.geolocation) {
            showToast(strings.locationError || 'Geolocalización no disponible');
            return;
        }

        const $btn = $('#wcudc-modal-geolocate');
        $btn.addClass('locating');

        navigator.geolocation.getCurrentPosition(
            function(position) {
                $btn.removeClass('locating');
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;

                map.setView([lat, lng], 16);
                marker.setLatLng([lat, lng]);
                onMarkerMoved(lat, lng);
            },
            function(error) {
                $btn.removeClass('locating');
                showToast(strings.locationError || 'No pudimos obtener tu ubicación');
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }

    /**
     * Cuando el marcador se mueve
     */
    function onMarkerMoved(lat, lng) {
        // Reverse geocoding
        reverseGeocode(lat, lng, function(address) {
            selectLocation(lat, lng, address);
        });
    }

    /**
     * Reverse geocoding
     */
    function reverseGeocode(lat, lng, callback) {
        const nominatimUrl = nominatimConfig.url || 'https://nominatim.openstreetmap.org';

        $.ajax({
            url: `${nominatimUrl}/reverse?format=json&lat=${lat}&lon=${lng}`,
            type: 'GET',
            dataType: 'json',
            headers: { 'Accept-Language': 'es' },
            success: function(result) {
                if (result && result.display_name) {
                    callback(result.display_name);
                } else {
                    callback('');
                }
            },
            error: function() {
                callback('');
            }
        });
    }

    /**
     * Selecciona una ubicación
     */
    function selectLocation(lat, lng, address) {
        // Verificar cobertura
        const coverage = checkLocalCoverage(lat, lng);

        selectedLocation = {
            lat: lat,
            lng: lng,
            address: address,
            short_address: address ? address.split(',').slice(0, 2).join(',') : '',
            delivery_type: coverage.has_coverage ? 'delivery' : 'pickup',
            store_id: coverage.store_id || '',
            store_name: coverage.store_name || '',
            has_coverage: coverage.has_coverage
        };

        // Actualizar UI
        $('#wcudc-modal-search').val(selectedLocation.short_address);
        updateCoverageDisplay(coverage.has_coverage, coverage.store_name);
        showDeliveryOptions(coverage.has_coverage);
        updateConfirmButton();

        // Si no hay cobertura, preseleccionar retiro
        if (!coverage.has_coverage) {
            $('input[name="wcudc_delivery_type"][value="pickup"]').prop('checked', true);
            handleDeliveryTypeChange('pickup');
        } else {
            $('input[name="wcudc_delivery_type"][value="delivery"]').prop('checked', true);
            handleDeliveryTypeChange('delivery');
        }
    }

    /**
     * Verifica cobertura localmente (sin AJAX)
     */
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

    /**
     * Algoritmo Ray Casting para verificar punto en polígono
     */
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

    /**
     * Actualiza el display de cobertura
     */
    function updateCoverageDisplay(hasCoverage, storeName) {
        const $result = $('#wcudc-coverage-result');

        if (hasCoverage) {
            $result.removeClass('no-coverage').addClass('has-coverage');
            $result.find('.wcudc-coverage-icon').html('✅');
            $result.find('.wcudc-coverage-message').html(
                (strings.coverageOk || '¡Genial! Llegamos a tu zona') +
                (storeName ? ` <strong>(${storeName})</strong>` : '')
            );
        } else {
            $result.removeClass('has-coverage').addClass('no-coverage');
            $result.find('.wcudc-coverage-icon').html('⚠️');
            $result.find('.wcudc-coverage-message').html(strings.noCoverage || 'No llegamos a tu zona, pero puedes retirar en tienda');
        }

        $result.show();
    }

    /**
     * Muestra las opciones de entrega
     */
    function showDeliveryOptions(hasCoverage) {
        const $options = $('#wcudc-delivery-options');
        const $deliveryOption = $('#wcudc-option-delivery');
        const $pickupStores = $('#wcudc-pickup-stores');

        // Si no hay cobertura, deshabilitar opción de delivery
        if (!hasCoverage) {
            $deliveryOption.addClass('disabled');
            $deliveryOption.find('input').prop('disabled', true);
            $('#wcudc-delivery-store').text(strings.noCoverage || 'Sin cobertura');
        } else {
            $deliveryOption.removeClass('disabled');
            $deliveryOption.find('input').prop('disabled', false);
            $('#wcudc-delivery-store').text(selectedLocation.store_name || '');
        }

        $options.show();
    }

    /**
     * Maneja el cambio de tipo de entrega
     */
    function handleDeliveryTypeChange(type) {
        const $pickupStores = $('#wcudc-pickup-stores');

        if (type === 'pickup') {
            $pickupStores.slideDown(200);
            selectedLocation.delivery_type = 'pickup';

            // Seleccionar primera tienda si no hay una seleccionada
            const $select = $('#wcudc-pickup-store-select');
            const $selected = $select.find(':selected');
            selectedLocation.store_id = $selected.val();
            selectedLocation.store_name = $selected.text().split(' - ')[0];
            selectedLocation.store_address = $selected.data('address');
        } else {
            $pickupStores.slideUp(200);
            selectedLocation.delivery_type = 'delivery';
        }

        updateConfirmButton();
    }

    /**
     * Actualiza el estado del botón confirmar
     */
    function updateConfirmButton() {
        const $btn = $('#wcudc-confirm-location');
        const isValid = selectedLocation &&
                        selectedLocation.lat &&
                        (selectedLocation.delivery_type === 'delivery' ? selectedLocation.has_coverage : selectedLocation.store_id);

        $btn.prop('disabled', !isValid);
    }

    /**
     * Confirma la ubicación seleccionada
     */
    function confirmLocation() {
        if (!selectedLocation) return;

        const $btn = $('#wcudc-confirm-location');
        $btn.prop('disabled', true).text(strings.locating || 'Guardando...');

        // Guardar via AJAX
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wcudc_save_location',
                nonce: config.nonce,
                lat: selectedLocation.lat,
                lng: selectedLocation.lng,
                address: selectedLocation.address,
                short_address: selectedLocation.short_address,
                delivery_type: selectedLocation.delivery_type,
                store_id: selectedLocation.store_id,
                store_name: selectedLocation.store_name
            },
            success: function(response) {
                if (response.success) {
                    // Actualizar UI del header
                    updateLocationBar();

                    // Cerrar modal
                    config.hasLocation = true;
                    $('#wcudc-location-modal').fadeOut(200);
                    $('body').removeClass('wcudc-modal-open');

                    // Mostrar toast de éxito
                    showToast('✅ ' + (strings.confirmLocation || 'Ubicación confirmada'));

                    // Recargar página para actualizar precios de envío
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast('❌ ' + (response.data.message || 'Error al guardar'));
                    $btn.prop('disabled', false).text(strings.confirmLocation || 'Confirmar ubicación');
                }
            },
            error: function() {
                showToast('❌ Error de conexión');
                $btn.prop('disabled', false).text(strings.confirmLocation || 'Confirmar ubicación');
            }
        });
    }

    /**
     * Actualiza la barra de ubicación en el header
     */
    function updateLocationBar() {
        const $bar = $('#wcudc-location-bar');
        const $text = $('#wcudc-location-display');
        const icon = selectedLocation.delivery_type === 'pickup' ? '🏪' : '📍';

        let displayText = selectedLocation.short_address;
        if (selectedLocation.delivery_type === 'pickup') {
            displayText = 'Retiro en ' + selectedLocation.store_name;
        }

        $bar.removeClass('no-location').addClass('has-location');
        $bar.find('.wcudc-location-icon').text(icon);
        $text.text(displayText);
    }

    /**
     * Muestra un toast/notificación
     */
    function showToast(message) {
        // Remover toast anterior si existe
        $('.wcudc-toast').remove();

        const $toast = $('<div class="wcudc-toast">' + message + '</div>');
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
