/**
 * RestoHub - Admin JavaScript
 *
 * Maneja el CRUD de tiendas y la integración con Leaflet para dibujar polígonos
 * Incluye autocompletado de direcciones con Nominatim API
 */

(function($) {
    'use strict';

    // Variables globales del módulo
    let map = null;
    let drawnItems = null;
    let drawControl = null;
    let currentPolygon = null;
    let storeMarker = null;

    // Variables para autocompletado
    let searchTimeout = null;
    const DEBOUNCE_DELAY = 500; // ms
    const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    // Configuración por defecto del mapa (Santiago/Maipú, Chile)
    const DEFAULT_CENTER = [-33.5117, -70.7578];
    const DEFAULT_ZOOM = 13;

    /**
     * Inicialización cuando el DOM está listo
     */
    $(document).ready(function() {
        initEventListeners();
    });

    /**
     * Inicializa todos los event listeners
     */
    function initEventListeners() {
        // Botón agregar tienda
        $('#restohub-add-store').on('click', openNewStoreModal);

        // Botón guardar cargos del checkout
        $('#restohub-save-checkout-fees').on('click', saveCheckoutFees);

        // Botones editar tienda
        $(document).on('click', '.restohub-edit-store', function() {
            const storeId = $(this).data('id');
            openEditStoreModal(storeId);
        });

        // Botones eliminar tienda
        $(document).on('click', '.restohub-delete-store', function() {
            const storeId = $(this).data('id');
            deleteStore(storeId);
        });

        // Cerrar modal
        $('.restohub-modal-close, .restohub-modal-cancel').on('click', closeModal);

        // Click fuera del modal para cerrar
        $('#restohub-store-modal').on('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Envío del formulario
        $('#restohub-store-form').on('submit', function(e) {
            e.preventDefault();
            saveStore();
        });

        // Actualizar marcador cuando cambian las coordenadas manualmente
        $('#restohub-store-lat, #restohub-store-lng').on('change', updateStoreMarker);

        // Autocompletado de direcciones
        $('#restohub-store-address').on('input', handleAddressInput);

        // Cerrar resultados al hacer click fuera
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.restohub-address-autocomplete-wrapper').length) {
                $('#restohub-address-results').hide();
            }
        });

        // Seleccionar resultado de autocompletado
        $(document).on('click', '.restohub-address-result-item', function() {
            selectAddressResult($(this));
        });
    }

    /**
     * Maneja el input de dirección con debounce
     */
    function handleAddressInput() {
        const query = $(this).val().trim();
        const $results = $('#restohub-address-results');

        // Limpiar timeout anterior
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }

        // Si la consulta es muy corta, ocultar resultados
        if (query.length < 3) {
            $results.hide();
            return;
        }

        // Mostrar loading
        $results.html('<div class="restohub-address-loading">Buscando...</div>').show();

        // Debounce: esperar antes de buscar
        searchTimeout = setTimeout(function() {
            searchAddress(query);
        }, DEBOUNCE_DELAY);
    }

    /**
     * Busca direcciones usando Nominatim API
     */
    function searchAddress(query) {
        const $results = $('#restohub-address-results');

        $.ajax({
            url: NOMINATIM_URL,
            type: 'GET',
            data: {
                q: query,
                format: 'json',
                addressdetails: 1,
                limit: 5,
                countrycodes: 'cl', // Chile
                'accept-language': 'es'
            },
            success: function(data) {
                if (data && data.length > 0) {
                    renderAddressResults(data);
                } else {
                    $results.html('<div class="restohub-address-no-results">No se encontraron resultados</div>');
                }
            },
            error: function() {
                $results.html('<div class="restohub-address-error">Error al buscar. Intenta de nuevo.</div>');
            }
        });
    }

    /**
     * Renderiza los resultados de búsqueda de direcciones
     */
    function renderAddressResults(results) {
        const $results = $('#restohub-address-results');
        let html = '';

        results.forEach(function(result) {
            html += `
                <div class="restohub-address-result-item"
                     data-lat="${result.lat}"
                     data-lng="${result.lon}"
                     data-address="${escapeHtml(result.display_name)}">
                    <span class="restohub-result-icon">📍</span>
                    <span class="restohub-result-text">${escapeHtml(result.display_name)}</span>
                </div>
            `;
        });

        $results.html(html).show();
    }

    /**
     * Selecciona un resultado de autocompletado
     */
    function selectAddressResult($item) {
        const lat = parseFloat($item.data('lat'));
        const lng = parseFloat($item.data('lng'));
        const address = $item.data('address');

        // Actualizar campos
        $('#restohub-store-address').val(address);
        $('#restohub-store-lat').val(lat.toFixed(6));
        $('#restohub-store-lng').val(lng.toFixed(6));

        // Ocultar resultados
        $('#restohub-address-results').hide();

        // Actualizar mapa y marcador si existe
        if (map) {
            addStoreMarker(lat, lng);
            map.setView([lat, lng], 15);
        }
    }

    /**
     * Escapa HTML para prevenir XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Abre el modal para crear una nueva tienda
     */
    function openNewStoreModal() {
        // Limpiar formulario
        $('#restohub-store-form')[0].reset();
        $('#restohub-store-id').val('');
        $('#restohub-store-polygon').val('');
        $('#restohub-modal-title').text(restoHubAdmin.strings.drawPolygon || 'Nueva Tienda');
        $('#restohub-store-active').prop('checked', true);
        $('#restohub-address-results').hide();

        // Mostrar modal
        $('#restohub-store-modal').fadeIn(200);

        // Inicializar mapa después de que el modal sea visible
        setTimeout(function() {
            initMap();
        }, 300);
    }

    /**
     * Abre el modal para editar una tienda existente
     */
    function openEditStoreModal(storeId) {
        // Mostrar loading
        $('#restohub-store-modal').fadeIn(200);
        $('.restohub-modal-content').addClass('restohub-loading');
        $('#restohub-address-results').hide();

        // Obtener datos de la tienda
        $.ajax({
            url: restoHubAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'restohub_get_store',
                nonce: restoHubAdmin.nonce,
                store_id: storeId
            },
            success: function(response) {
                $('.restohub-modal-content').removeClass('restohub-loading');

                if (response.success) {
                    populateForm(response.data.store);
                    setTimeout(function() {
                        initMap(response.data.store);
                    }, 300);
                } else {
                    alert(response.data.message || 'Error al cargar la tienda');
                    closeModal();
                }
            },
            error: function() {
                $('.restohub-modal-content').removeClass('restohub-loading');
                alert('Error de conexión');
                closeModal();
            }
        });
    }

    /**
     * Llena el formulario con los datos de una tienda
     */
    function populateForm(store) {
        $('#restohub-store-id').val(store.id);
        $('#restohub-store-name').val(store.name);
        $('#restohub-store-address').val(store.address);
        $('#restohub-store-phone').val(store.phone);
        $('#restohub-store-lat').val(store.latitude);
        $('#restohub-store-lng').val(store.longitude);
        $('#restohub-store-active').prop('checked', store.active);
        $('#restohub-store-polygon').val(JSON.stringify(store.polygon || []));
        $('#restohub-modal-title').text('Editar Tienda: ' + store.name);
    }

    /**
     * Cierra el modal
     */
    function closeModal() {
        $('#restohub-store-modal').fadeOut(200);
        $('#restohub-address-results').hide();

        // Destruir el mapa para liberar memoria
        if (map) {
            map.remove();
            map = null;
            drawnItems = null;
            drawControl = null;
            currentPolygon = null;
            storeMarker = null;
        }
    }

    /**
     * Inicializa el mapa Leaflet con controles de dibujo
     * NOTA: El click en el mapa NO actualiza coordenadas de la tienda
     *       Solo el autocompletado o la edición manual lo hacen
     */
    function initMap(store = null) {
        // Si ya existe un mapa, destruirlo
        if (map) {
            map.remove();
        }

        // Determinar centro del mapa
        let center = DEFAULT_CENTER;
        let zoom = DEFAULT_ZOOM;

        if (store && store.latitude && store.longitude) {
            center = [parseFloat(store.latitude), parseFloat(store.longitude)];
            zoom = 14;
        }

        // Crear mapa
        map = L.map('restohub-map').setView(center, zoom);

        // Agregar capa de tiles (OpenStreetMap)
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19
        }).addTo(map);

        // Capa para elementos dibujados
        drawnItems = new L.FeatureGroup();
        map.addLayer(drawnItems);

        // Configurar controles de dibujo
        drawControl = new L.Control.Draw({
            position: 'topright',
            draw: {
                polygon: {
                    allowIntersection: false,
                    showArea: true,
                    shapeOptions: {
                        color: '#0073aa',
                        fillColor: '#0073aa',
                        fillOpacity: 0.3,
                        weight: 2
                    }
                },
                polyline: false,
                circle: false,
                rectangle: false,
                marker: false,
                circlemarker: false
            },
            edit: {
                featureGroup: drawnItems,
                remove: true
            }
        });
        map.addControl(drawControl);

        // Si hay un polígono existente, dibujarlo
        if (store && store.polygon && store.polygon.length > 0) {
            const latlngs = store.polygon.map(p => [p.lat, p.lng]);
            currentPolygon = L.polygon(latlngs, {
                color: '#0073aa',
                fillColor: '#0073aa',
                fillOpacity: 0.3,
                weight: 2
            }).addTo(drawnItems);

            // Ajustar vista al polígono
            map.fitBounds(currentPolygon.getBounds(), { padding: [20, 20] });
        }

        // Agregar marcador de la tienda
        if (store && store.latitude && store.longitude) {
            addStoreMarker(parseFloat(store.latitude), parseFloat(store.longitude));
        }

        // Evento: Polígono creado
        // NOTA: NO actualiza coordenadas de la tienda, solo guarda el polígono
        map.on(L.Draw.Event.CREATED, function(e) {
            // Remover polígono anterior si existe
            if (currentPolygon) {
                drawnItems.removeLayer(currentPolygon);
            }

            currentPolygon = e.layer;
            drawnItems.addLayer(currentPolygon);
            updatePolygonInput();
        });

        // Evento: Polígono editado
        map.on(L.Draw.Event.EDITED, function() {
            updatePolygonInput();
        });

        // Evento: Polígono eliminado
        map.on(L.Draw.Event.DELETED, function() {
            currentPolygon = null;
            $('#restohub-store-polygon').val('');
        });

        // ELIMINADO: El click en el mapa ya NO actualiza coordenadas
        // Las coordenadas solo se actualizan via autocompletado o edición manual

        // Forzar actualización del tamaño del mapa
        setTimeout(function() {
            map.invalidateSize();
        }, 100);
    }

    /**
     * Agrega o actualiza el marcador de la tienda
     */
    function addStoreMarker(lat, lng) {
        if (storeMarker) {
            map.removeLayer(storeMarker);
        }

        storeMarker = L.marker([lat, lng], {
            icon: L.divIcon({
                className: 'restohub-store-marker',
                iconSize: [20, 20],
                iconAnchor: [10, 10],
                html: '<div style="background: #dc3545; width: 20px; height: 20px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.3);"></div>'
            })
        }).addTo(map);

        storeMarker.bindTooltip('Ubicación de la tienda', {
            className: 'restohub-tooltip'
        });
    }

    /**
     * Actualiza el marcador cuando cambian los inputs de coordenadas manualmente
     */
    function updateStoreMarker() {
        const lat = parseFloat($('#restohub-store-lat').val());
        const lng = parseFloat($('#restohub-store-lng').val());

        if (!isNaN(lat) && !isNaN(lng) && map) {
            addStoreMarker(lat, lng);
            map.setView([lat, lng], 14);
        }
    }

    /**
     * Actualiza el input hidden con las coordenadas del polígono
     * NO modifica las coordenadas de la tienda
     */
    function updatePolygonInput() {
        if (!currentPolygon) {
            $('#restohub-store-polygon').val('');
            return;
        }

        const latlngs = currentPolygon.getLatLngs()[0];
        const polygon = latlngs.map(function(latlng) {
            return {
                lat: latlng.lat,
                lng: latlng.lng
            };
        });

        $('#restohub-store-polygon').val(JSON.stringify(polygon));
    }

    /**
     * Guarda la tienda (crear o actualizar)
     */
    function saveStore() {
        // Validaciones
        const name = $('#restohub-store-name').val().trim();
        const address = $('#restohub-store-address').val().trim();
        const phone = $('#restohub-store-phone').val().trim();
        const lat = $('#restohub-store-lat').val().trim();
        const lng = $('#restohub-store-lng').val().trim();
        const polygon = $('#restohub-store-polygon').val();

        if (!name) {
            alert('El nombre de la tienda es obligatorio.');
            $('#restohub-store-name').focus();
            return;
        }

        if (!address) {
            alert('La dirección es obligatoria.');
            $('#restohub-store-address').focus();
            return;
        }

        if (!lat || !lng) {
            alert('Las coordenadas son obligatorias. Selecciona una dirección del autocompletado o ingresa las coordenadas manualmente.');
            return;
        }

        // Mostrar loading
        $('.restohub-modal-content').addClass('restohub-loading');

        // Enviar datos
        $.ajax({
            url: restoHubAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'restohub_save_store',
                nonce: restoHubAdmin.nonce,
                store_id: $('#restohub-store-id').val(),
                name: name,
                address: address,
                phone: phone,
                latitude: lat,
                longitude: lng,
                active: $('#restohub-store-active').is(':checked') ? '1' : '0',
                polygon: polygon
            },
            success: function(response) {
                $('.restohub-modal-content').removeClass('restohub-loading');

                if (response.success) {
                    // Recargar la página para mostrar los cambios
                    location.reload();
                } else {
                    alert(response.data.message || restoHubAdmin.strings.saveError);
                }
            },
            error: function() {
                $('.restohub-modal-content').removeClass('restohub-loading');
                alert(restoHubAdmin.strings.saveError);
            }
        });
    }

    /**
     * Elimina una tienda
     */
    function deleteStore(storeId) {
        if (!confirm(restoHubAdmin.strings.confirmDelete)) {
            return;
        }

        // Mostrar loading en la fila
        const $row = $('tr[data-store-id="' + storeId + '"]');
        $row.addClass('restohub-loading');

        $.ajax({
            url: restoHubAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'restohub_delete_store',
                nonce: restoHubAdmin.nonce,
                store_id: storeId
            },
            success: function(response) {
                if (response.success) {
                    // Remover fila de la tabla
                    $row.fadeOut(300, function() {
                        $(this).remove();

                        // Si no quedan tiendas, mostrar mensaje
                        if ($('#restohub-stores-table tbody tr').length === 0) {
                            $('#restohub-stores-table tbody').html(
                                '<tr class="no-stores"><td colspan="5">No hay tiendas configuradas.</td></tr>'
                            );
                        }
                    });
                } else {
                    $row.removeClass('restohub-loading');
                    alert(response.data.message || restoHubAdmin.strings.deleteError);
                }
            },
            error: function() {
                $row.removeClass('restohub-loading');
                alert(restoHubAdmin.strings.deleteError);
            }
        });
    }

    /**
     * Guarda la configuración de cargos del checkout via AJAX
     */
    function saveCheckoutFees() {
        const $btn = $('#restohub-save-checkout-fees');
        const $status = $('#restohub-fees-save-status');

        $btn.prop('disabled', true).text('Guardando...');
        $status.text('');

        $.ajax({
            url: restoHubAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'restohub_save_checkout_fees',
                nonce: restoHubAdmin.nonce,
                sf_label: $('#restohub-sf-label').val(),
                sf_percentage: $('#restohub-sf-percentage').val(),
                sf_active: $('#restohub-sf-active').is(':checked') ? '1' : '',
                tip_active: $('#restohub-tip-active').is(':checked') ? '1' : ''
            },
            success: function(response) {
                if (response.success) {
                    $status.html('<span style="color: #46b450;">&#10004; ' + response.data.message + '</span>');
                } else {
                    $status.html('<span style="color: #dc3232;">&#10008; ' + (response.data.message || 'Error') + '</span>');
                }
            },
            error: function() {
                $status.html('<span style="color: #dc3232;">&#10008; Error de conexión</span>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Guardar Cargos');
                setTimeout(function() { $status.text(''); }, 4000);
            }
        });
    }

})(jQuery);
