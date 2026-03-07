/**
 * WC Uber Direct Connect - Checkout OpenStreetMap Integration
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

    // Configuración desde PHP
    const config = window.wcudcCheckout || {};
    const mapConfig = config.map || {};
    const nominatimConfig = config.nominatim || {};
    const strings = config.strings || {};

    /**
     * Inicialización cuando el DOM está listo
     */
    $(document).ready(function() {
        // Esperar a que el contenedor del mapa exista
        if ($('#wcudc-delivery-map').length === 0) {
            return;
        }

        initMap();
        initSearchBox();
        initGeolocation();
        initAddressFieldSync();
    });

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
        map = L.map('wcudc-delivery-map', {
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
            className: 'wcudc-marker-icon',
            html: `
                <div class="wcudc-marker-pin">
                    <div class="wcudc-marker-pin-inner"></div>
                </div>
                <div class="wcudc-marker-pulse"></div>
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
        const $searchInput = $('#wcudc-address-search');
        const $resultsContainer = $('#wcudc-search-results');

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
            if (!$(e.target).closest('.wcudc-search-container').length) {
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
        const $resultsContainer = $('#wcudc-search-results');
        const nominatimUrl = nominatimConfig.url || 'https://nominatim.openstreetmap.org';
        const countryCode = nominatimConfig.countryCode || '';

        // Mostrar loading
        $resultsContainer.html('<div class="wcudc-search-loading">' + (strings.locating || 'Buscando...') + '</div>').show();

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
                    '<div class="wcudc-search-error">' + (strings.searchError || 'Error en la búsqueda') + '</div>'
                );
            }
        });
    }

    /**
     * Muestra los resultados de búsqueda
     */
    function displaySearchResults(results) {
        const $resultsContainer = $('#wcudc-search-results');

        if (!results || results.length === 0) {
            $resultsContainer.html(
                '<div class="wcudc-search-no-results">' + (strings.searchError || 'No se encontraron resultados') + '</div>'
            );
            return;
        }

        let html = '';

        results.forEach(function(result) {
            const displayName = result.display_name || '';
            const shortName = displayName.split(',').slice(0, 3).join(',');

            html += `
                <div class="wcudc-search-result-item"
                     data-lat="${result.lat}"
                     data-lng="${result.lon}"
                     data-address="${displayName}">
                    <span class="wcudc-result-icon">📍</span>
                    <span class="wcudc-result-text">${shortName}</span>
                </div>
            `;
        });

        $resultsContainer.html(html).show();

        // Evento: Click en resultado
        $resultsContainer.find('.wcudc-search-result-item').on('click', function() {
            const lat = parseFloat($(this).data('lat'));
            const lng = parseFloat($(this).data('lng'));
            const address = $(this).data('address');

            selectSearchResult(lat, lng, address);
            $resultsContainer.hide();
            $('#wcudc-address-search').val(address.split(',').slice(0, 2).join(','));
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
        const $locateBtn = $('#wcudc-locate-me');

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
            $btn.addClass('wcudc-locating');

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    $btn.removeClass('wcudc-locating');

                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;

                    // Mover mapa y marcador
                    map.setView([lat, lng], 17);
                    marker.setLatLng([lat, lng]);

                    // Actualizar ubicación
                    onLocationSelected(lat, lng, true);
                },
                function(error) {
                    $btn.removeClass('wcudc-locating');
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
                $('#wcudc-address-search').val(address);

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
                action: 'wcudc_save_coordinates',
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
        const $statusContainer = $('#wcudc-coverage-status');

        if (!coverage) {
            $statusContainer.empty().hide();
            return;
        }

        let statusClass = coverage.has_coverage ? 'wcudc-coverage-ok' : 'wcudc-coverage-warning';
        let html = `<div class="${statusClass}">${coverage.message}</div>`;

        $statusContainer.html(html).show();
    }

    /**
     * Actualiza el display de ubicación seleccionada
     */
    function updateSelectedLocationDisplay(lat, lng) {
        const $container = $('#wcudc-selected-location');
        const $address = $('#wcudc-selected-address');

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
            $('#wcudc-address-search').val(shortAddress);
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
        const $address = $('#wcudc-selected-address');
        const shortName = displayName.split(',').slice(0, 3).join(',');
        $address.text(shortName);
    }

})(jQuery);
