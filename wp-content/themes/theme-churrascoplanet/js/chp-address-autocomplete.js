/**
 * chp-address-autocomplete.js
 *
 * Autocompletado de dirección en Mi Cuenta → Editar dirección de entrega.
 * Usa Nominatim (OpenStreetMap) — el mismo motor que el checkout.
 *
 * Flujo:
 *  1. El usuario escribe en #chp-shipping-search
 *  2. Se llama a Nominatim con debounce de 500 ms
 *  3. Los resultados aparecen en #chp-shipping-results
 *  4. Al seleccionar → rellena: shipping_address_1 (hidden), shipping_city,
 *     shipping_state (select), shipping_postcode (hidden)
 *  5. Mientras escribe (sin seleccionar) → shipping_address_1 se sincroniza
 *     con el texto, para que el campo nunca quede vacío al guardar.
 */
jQuery(function($) {
    'use strict';

    var config       = window.chpAddressEdit || {};
    var nominatimUrl = config.nominatimUrl || 'https://nominatim.openstreetmap.org';
    var countryCode  = config.countryCode  || 'cl';
    var strings      = config.strings      || {};

    var $searchInput  = $('#chp-shipping-search');
    var $resultsPanel = $('#chp-shipping-results');
    var $addr1Hidden  = $('#shipping_address_1');
    var $cityField    = $('#shipping_city');
    var $stateField   = $('#shipping_state');
    var $postcodeHid  = $('#shipping_postcode');

    var searchTimer   = null;
    var lastResults   = [];  // Almacena resultados para referenciar por índice

    // ──────────────────────────────────────────────────────────────────────────
    // Inicialización
    // ──────────────────────────────────────────────────────────────────────────
    if (!$searchInput.length) {
        return; // No estamos en el formulario correcto
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Escuchar tecleo en el campo de búsqueda
    // ──────────────────────────────────────────────────────────────────────────
    $searchInput.on('input', function() {
        var q = $(this).val().trim();

        // Sincronizar siempre con el hidden (modo manual)
        $addr1Hidden.val(q);

        clearTimeout(searchTimer);

        if (q.length < 4) {
            $resultsPanel.hide().empty();
            return;
        }

        searchTimer = setTimeout(function() {
            searchAddress(q);
        }, 500);
    });

    // Evitar submit con Enter en el campo de búsqueda
    $searchInput.on('keydown', function(e) {
        if (e.which === 13) {
            e.preventDefault();
        }
    });

    // Cerrar resultados al hacer clic fuera
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#chp-shipping-search-wrapper, #chp-shipping-results').length) {
            $resultsPanel.hide();
        }
    });

    // ──────────────────────────────────────────────────────────────────────────
    // Búsqueda en Nominatim
    // ──────────────────────────────────────────────────────────────────────────
    function searchAddress(q) {
        var url = nominatimUrl + '/search'
            + '?format=json'
            + '&limit=5'
            + '&addressdetails=1'
            + '&q=' + encodeURIComponent(q);

        if (countryCode) {
            url += '&countrycodes=' + encodeURIComponent(countryCode);
        }

        $resultsPanel
            .html('<div class="chp-addr-loading"><i class="fas fa-spinner fa-spin"></i> ' + escapeHtml(strings.searching || 'Buscando...') + '</div>')
            .show();

        $.ajax({
            url:      url,
            type:     'GET',
            dataType: 'json',
            headers:  { 'Accept-Language': 'es' },
            success: function(results) {
                renderResults(results);
            },
            error: function() {
                $resultsPanel.html(
                    '<div class="chp-addr-error">' + escapeHtml(strings.connError || 'Error de conexión') + '</div>'
                );
            }
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Render de resultados
    // ──────────────────────────────────────────────────────────────────────────
    function renderResults(results) {
        if (!results || !results.length) {
            $resultsPanel.html(
                '<div class="chp-addr-empty">' + escapeHtml(strings.noResults || 'No se encontraron resultados') + '</div>'
            );
            return;
        }

        lastResults = results;
        var html = '';

        results.forEach(function(r, idx) {
            var display = r.display_name || '';
            // Mostrar solo las 3 primeras partes del nombre completo
            var short = display.split(',').slice(0, 3).join(',').trim();

            html += '<div class="chp-addr-result" role="option" tabindex="0" data-idx="' + idx + '">'
                  + '<i class="fas fa-map-marker-alt chp-addr-result-icon" aria-hidden="true"></i>'
                  + '<span class="chp-addr-result-text">' + escapeHtml(short) + '</span>'
                  + '</div>';
        });

        $resultsPanel.html(html).show();

        // Selección con clic
        $resultsPanel.find('.chp-addr-result').on('click', function() {
            var idx = parseInt($(this).attr('data-idx'), 10);
            applyResult(idx);
        });

        // Selección con teclado (accesibilidad)
        $resultsPanel.find('.chp-addr-result').on('keydown', function(e) {
            if (e.which === 13 || e.which === 32) {
                e.preventDefault();
                var idx = parseInt($(this).attr('data-idx'), 10);
                applyResult(idx);
            }
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Aplicar resultado seleccionado a los campos del formulario
    // ──────────────────────────────────────────────────────────────────────────
    function applyResult(idx) {
        var r = lastResults[idx];
        if (!r) return;

        var addr     = r.address || {};
        var road     = addr.road || addr.pedestrian || addr.path || '';
        var number   = addr.house_number || '';
        var street   = road && number ? road + ' ' + number
                     : road || r.display_name.split(',')[0].trim();
        var city     = addr.city || addr.town || addr.village || addr.municipality || addr.suburb || '';
        var state    = addr.state || addr.region || '';
        var postcode = addr.postcode || '';

        // Mostrar calle en el campo de búsqueda visible
        $searchInput.val(street);

        // Poblar el campo real shipping_address_1
        $addr1Hidden.val(street).trigger('change');

        // Comuna / Ciudad
        if (city && $cityField.length) {
            $cityField.val(city).trigger('change');
        }

        // Región — busca la opción en el select por coincidencia parcial
        if (state && $stateField.length) {
            if ($stateField.is('select')) {
                var stateLower = state.toLowerCase().replace(/región\s*/i, '').trim();
                var $matched   = $stateField.find('option').filter(function() {
                    var optText = $(this).text().toLowerCase().replace(/región\s*/i, '').trim();
                    return optText.indexOf(stateLower) !== -1 || stateLower.indexOf(optText) !== -1;
                });
                if ($matched.length) {
                    $stateField.val($matched.first().val()).trigger('change');
                }
            } else {
                $stateField.val(state).trigger('change');
            }
        }

        // Código postal (oculto)
        if (postcode && $postcodeHid.length) {
            $postcodeHid.val(postcode);
        }

        // Cerrar panel de resultados
        $resultsPanel.hide().empty();
        lastResults = [];

        // Devolver foco al input
        $searchInput.trigger('focus');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Utilidades
    // ──────────────────────────────────────────────────────────────────────────
    function escapeHtml(str) {
        if (typeof str !== 'string') return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
});
