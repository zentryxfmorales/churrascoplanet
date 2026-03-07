/**
 * WC Uber Direct Connect - Admin JavaScript
 *
 * Maneja el CRUD de tiendas y la integración con Leaflet para dibujar polígonos
 */

(function($) {
    'use strict';

    // Variables globales del módulo
    let map = null;
    let drawnItems = null;
    let drawControl = null;
    let currentPolygon = null;
    let storeMarker = null;

    // Configuración por defecto del mapa (Lima, Perú)
    const DEFAULT_CENTER = [-12.0464, -77.0428];
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
        $('#wcudc-add-store').on('click', openNewStoreModal);

        // Botones editar tienda
        $(document).on('click', '.wcudc-edit-store', function() {
            const storeId = $(this).data('id');
            openEditStoreModal(storeId);
        });

        // Botones eliminar tienda
        $(document).on('click', '.wcudc-delete-store', function() {
            const storeId = $(this).data('id');
            deleteStore(storeId);
        });

        // Cerrar modal
        $('.wcudc-modal-close, .wcudc-modal-cancel').on('click', closeModal);

        // Click fuera del modal para cerrar
        $('#wcudc-store-modal').on('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Envío del formulario
        $('#wcudc-store-form').on('submit', function(e) {
            e.preventDefault();
            saveStore();
        });

        // Actualizar marcador cuando cambian las coordenadas
        $('#wcudc-store-lat, #wcudc-store-lng').on('change', updateStoreMarker);
    }

    /**
     * Abre el modal para crear una nueva tienda
     */
    function openNewStoreModal() {
        // Limpiar formulario
        $('#wcudc-store-form')[0].reset();
        $('#wcudc-store-id').val('');
        $('#wcudc-store-polygon').val('');
        $('#wcudc-modal-title').text(wcudcAdmin.strings.drawPolygon || 'Nueva Tienda');
        $('#wcudc-store-active').prop('checked', true);

        // Mostrar modal
        $('#wcudc-store-modal').fadeIn(200);

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
        $('#wcudc-store-modal').fadeIn(200);
        $('.wcudc-modal-content').addClass('wcudc-loading');

        // Obtener datos de la tienda
        $.ajax({
            url: wcudcAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wcudc_get_store',
                nonce: wcudcAdmin.nonce,
                store_id: storeId
            },
            success: function(response) {
                $('.wcudc-modal-content').removeClass('wcudc-loading');

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
                $('.wcudc-modal-content').removeClass('wcudc-loading');
                alert('Error de conexión');
                closeModal();
            }
        });
    }

    /**
     * Llena el formulario con los datos de una tienda
     */
    function populateForm(store) {
        $('#wcudc-store-id').val(store.id);
        $('#wcudc-store-name').val(store.name);
        $('#wcudc-store-address').val(store.address);
        $('#wcudc-store-phone').val(store.phone);
        $('#wcudc-store-lat').val(store.latitude);
        $('#wcudc-store-lng').val(store.longitude);
        $('#wcudc-store-active').prop('checked', store.active);
        $('#wcudc-store-polygon').val(JSON.stringify(store.polygon || []));
        $('#wcudc-modal-title').text('Editar Tienda: ' + store.name);
    }

    /**
     * Cierra el modal
     */
    function closeModal() {
        $('#wcudc-store-modal').fadeOut(200);

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
            center = [store.latitude, store.longitude];
            zoom = 14;
        }

        // Crear mapa
        map = L.map('wcudc-map').setView(center, zoom);

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
            addStoreMarker(store.latitude, store.longitude);
        }

        // Evento: Polígono creado
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
            $('#wcudc-store-polygon').val('');
        });

        // Evento: Click en el mapa para actualizar coordenadas
        map.on('click', function(e) {
            $('#wcudc-store-lat').val(e.latlng.lat.toFixed(6));
            $('#wcudc-store-lng').val(e.latlng.lng.toFixed(6));
            addStoreMarker(e.latlng.lat, e.latlng.lng);
        });

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
                className: 'wcudc-store-marker',
                iconSize: [16, 16],
                iconAnchor: [8, 8]
            })
        }).addTo(map);

        storeMarker.bindTooltip('Ubicación de la tienda', {
            className: 'wcudc-tooltip'
        });
    }

    /**
     * Actualiza el marcador cuando cambian los inputs de coordenadas
     */
    function updateStoreMarker() {
        const lat = parseFloat($('#wcudc-store-lat').val());
        const lng = parseFloat($('#wcudc-store-lng').val());

        if (!isNaN(lat) && !isNaN(lng) && map) {
            addStoreMarker(lat, lng);
            map.setView([lat, lng], 14);
        }
    }

    /**
     * Actualiza el input hidden con las coordenadas del polígono
     */
    function updatePolygonInput() {
        if (!currentPolygon) {
            $('#wcudc-store-polygon').val('');
            return;
        }

        const latlngs = currentPolygon.getLatLngs()[0];
        const polygon = latlngs.map(function(latlng) {
            return {
                lat: latlng.lat,
                lng: latlng.lng
            };
        });

        $('#wcudc-store-polygon').val(JSON.stringify(polygon));
    }

    /**
     * Guarda la tienda (crear o actualizar)
     */
    function saveStore() {
        // Validaciones
        const name = $('#wcudc-store-name').val().trim();
        const address = $('#wcudc-store-address').val().trim();
        const phone = $('#wcudc-store-phone').val().trim();
        const lat = $('#wcudc-store-lat').val().trim();
        const lng = $('#wcudc-store-lng').val().trim();
        const polygon = $('#wcudc-store-polygon').val();

        if (!name) {
            alert('El nombre de la tienda es obligatorio.');
            $('#wcudc-store-name').focus();
            return;
        }

        if (!address) {
            alert('La dirección es obligatoria.');
            $('#wcudc-store-address').focus();
            return;
        }

        if (!lat || !lng) {
            alert('Las coordenadas son obligatorias. Haz clic en el mapa para seleccionar la ubicación.');
            return;
        }

        // Mostrar loading
        $('.wcudc-modal-content').addClass('wcudc-loading');

        // Enviar datos
        $.ajax({
            url: wcudcAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wcudc_save_store',
                nonce: wcudcAdmin.nonce,
                store_id: $('#wcudc-store-id').val(),
                name: name,
                address: address,
                phone: phone,
                latitude: lat,
                longitude: lng,
                active: $('#wcudc-store-active').is(':checked') ? '1' : '0',
                polygon: polygon
            },
            success: function(response) {
                $('.wcudc-modal-content').removeClass('wcudc-loading');

                if (response.success) {
                    // Recargar la página para mostrar los cambios
                    location.reload();
                } else {
                    alert(response.data.message || wcudcAdmin.strings.saveError);
                }
            },
            error: function() {
                $('.wcudc-modal-content').removeClass('wcudc-loading');
                alert(wcudcAdmin.strings.saveError);
            }
        });
    }

    /**
     * Elimina una tienda
     */
    function deleteStore(storeId) {
        if (!confirm(wcudcAdmin.strings.confirmDelete)) {
            return;
        }

        // Mostrar loading en la fila
        const $row = $('tr[data-store-id="' + storeId + '"]');
        $row.addClass('wcudc-loading');

        $.ajax({
            url: wcudcAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wcudc_delete_store',
                nonce: wcudcAdmin.nonce,
                store_id: storeId
            },
            success: function(response) {
                if (response.success) {
                    // Remover fila de la tabla
                    $row.fadeOut(300, function() {
                        $(this).remove();

                        // Si no quedan tiendas, mostrar mensaje
                        if ($('#wcudc-stores-table tbody tr').length === 0) {
                            $('#wcudc-stores-table tbody').html(
                                '<tr class="no-stores"><td colspan="5">No hay tiendas configuradas.</td></tr>'
                            );
                        }
                    });
                } else {
                    $row.removeClass('wcudc-loading');
                    alert(response.data.message || wcudcAdmin.strings.deleteError);
                }
            },
            error: function() {
                $row.removeClass('wcudc-loading');
                alert(wcudcAdmin.strings.deleteError);
            }
        });
    }

})(jQuery);
