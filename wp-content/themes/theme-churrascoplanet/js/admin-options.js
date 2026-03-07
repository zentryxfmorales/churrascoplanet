/**
 * ChurrascoPlanet - Admin Options JavaScript
 * Funcionalidades del panel "Aspecto PlanetaChurrascos"
 */

(function($) {
    'use strict';

    // Document ready
    $(document).ready(function() {
        initTabs();
        initColorPickers();
        initMediaUploaders();
        initRepeaters();
        initIconFields();
        initExtrasItems();
        initFormSubmit();
    });

    /**
     * Inicializar Tabs
     */
    function initTabs() {
        $('.churrascoplanet-admin-tabs .nav-tab').on('click', function(e) {
            e.preventDefault();

            var $this = $(this);
            var tabId = $this.attr('href');

            // Actualizar tabs activos
            $('.nav-tab').removeClass('nav-tab-active');
            $this.addClass('nav-tab-active');

            // Mostrar contenido del tab
            $('.tab-content').removeClass('active');
            $(tabId).addClass('active');

            // Guardar tab activo en localStorage
            localStorage.setItem('churrascoplanet_active_tab', tabId);

            // Reinicializar TinyMCE en el tab activo
            reinitEditors(tabId);
        });

        // Restaurar tab activo
        var activeTab = localStorage.getItem('churrascoplanet_active_tab');
        if (activeTab && $(activeTab).length) {
            $('.nav-tab[href="' + activeTab + '"]').trigger('click');
        }
    }

    /**
     * Reinicializar editores TinyMCE
     */
    function reinitEditors(tabId) {
        $(tabId).find('.wp-editor-area').each(function() {
            var editorId = $(this).attr('id');
            if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                tinymce.get(editorId).remove();
                tinymce.init(tinyMCEPreInit.mceInit[editorId]);
            }
        });
    }

    /**
     * Inicializar Color Pickers
     */
    function initColorPickers() {
        $('.color-picker').wpColorPicker({
            change: function(event, ui) {
                $(event.target).trigger('change');
            },
            clear: function() {
                $(this).trigger('change');
            }
        });
    }

    /**
     * Inicializar Media Uploaders
     */
    function initMediaUploaders() {
        $(document).on('click', '.select-image', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $field = $button.closest('.image-field');
            var $input = $field.find('.image-url');
            var $preview = $field.find('.image-preview');

            var mediaUploader = wp.media({
                title: churrascoplanetAdmin.strings.selectImage,
                button: {
                    text: churrascoplanetAdmin.strings.useImage
                },
                multiple: false
            });

            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                $input.val(attachment.url);
                $preview.find('img').attr('src', attachment.url);
                $preview.show();
            });

            mediaUploader.open();
        });

        // Remover imagen
        $(document).on('click', '.remove-image', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $field = $button.closest('.image-field');
            var $input = $field.find('.image-url');
            var $preview = $field.find('.image-preview');

            $input.val('');
            $preview.hide();
        });
    }

    /**
     * Inicializar Repeaters
     */
    function initRepeaters() {
        // Sortable
        $('.repeater-items').sortable({
            handle: '.drag-handle',
            placeholder: 'repeater-item-placeholder',
            update: function() {
                reindexRepeater($(this).closest('.repeater-field'));
            }
        });

        // Toggle item content
        $(document).on('click', '.repeater-item-header', function(e) {
            if ($(e.target).closest('.remove-item').length) return;

            var $item = $(this).closest('.repeater-item');
            $item.find('.repeater-item-content').slideToggle(200);
        });

        // Agregar nuevo item
        $(document).on('click', '.add-repeater-item', function(e) {
            e.preventDefault();

            var $repeater = $(this).closest('.repeater-field');
            var $items = $repeater.find('.repeater-items');
            var template = $repeater.find('.repeater-template').html();
            var newIndex = $items.find('.repeater-item').length;

            // Reemplazar placeholder de índice
            template = template.replace(/\{\{index\}\}/g, newIndex);

            var $newItem = $(template);
            $items.append($newItem);

            // Inicializar campos del nuevo item
            $newItem.find('.color-picker').wpColorPicker();
            initIconFieldsIn($newItem);

            // Abrir el nuevo item
            $newItem.find('.repeater-item-content').show();

            // Scroll al nuevo item
            $('html, body').animate({
                scrollTop: $newItem.offset().top - 100
            }, 300);
        });

        // Eliminar item
        $(document).on('click', '.remove-item', function(e) {
            e.preventDefault();
            e.stopPropagation();

            if (!confirm(churrascoplanetAdmin.strings.confirmDelete)) {
                return;
            }

            var $item = $(this).closest('.repeater-item');
            var $repeater = $item.closest('.repeater-field');

            $item.slideUp(200, function() {
                $(this).remove();
                reindexRepeater($repeater);
            });
        });
    }

    /**
     * Reindexar campos del repeater
     */
    function reindexRepeater($repeater) {
        var repeaterName = $repeater.data('repeater');

        $repeater.find('.repeater-item').each(function(index) {
            $(this).find('input, select, textarea').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    // Actualizar índice en el nombre del campo
                    name = name.replace(/\[\d+\]/g, '[' + index + ']');
                    $(this).attr('name', name);
                }
            });
        });
    }

    /**
     * Inicializar campos de iconos
     */
    function initIconFields() {
        initIconFieldsIn($(document));
    }

    function initIconFieldsIn($container) {
        $container.find('.icon-input').on('input change', function() {
            var $input = $(this);
            var $preview = $input.closest('.icon-field').find('.icon-preview i');
            var iconClass = $input.val().trim();

            if (iconClass) {
                $preview.attr('class', iconClass);
            }
        });
    }

    /**
     * Inicializar campos de extras de productos
     */
    function initExtrasItems() {
        // Agregar item de extras dentro de un grupo
        $(document).on('click', '.add-extras-item', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $list = $btn.siblings('.extras-items-list');
            var groupIndex = $btn.data('group');

            // Buscar el siguiente índice de item
            var itemIndex = $list.find('.extras-item-row').length;

            var html = '<div class="extras-item-row">' +
                '<span class="drag-handle-sm"><i class="fas fa-grip-vertical"></i></span>' +
                '<input type="text" name="churrascoplanet_options[extras_grupos][' + groupIndex + '][items][' + itemIndex + '][nombre]" value="" class="regular-text" placeholder="Nombre del extra">' +
                '<input type="number" name="churrascoplanet_options[extras_grupos][' + groupIndex + '][items][' + itemIndex + '][precio]" value="0" class="small-text extras-price-input" min="0" step="1" placeholder="$0">' +
                '<span class="extras-price-label">CLP</span>' +
                '<button type="button" class="button-link remove-extras-item" title="Eliminar"><i class="fas fa-times"></i></button>' +
                '</div>';

            $list.append(html);
        });

        // Eliminar item de extras
        $(document).on('click', '.remove-extras-item', function(e) {
            e.preventDefault();
            var $row = $(this).closest('.extras-item-row');
            var $list = $row.closest('.extras-items-list');
            var $group = $row.closest('.extras-group-item');

            $row.fadeOut(200, function() {
                $(this).remove();
                reindexExtrasItems($group);
            });
        });

        // Sortable para items de extras
        $('.extras-items-list').sortable({
            handle: '.drag-handle-sm',
            placeholder: 'extras-item-placeholder',
            update: function() {
                var $group = $(this).closest('.extras-group-item');
                reindexExtrasItems($group);
            }
        });
    }

    /**
     * Reindexar items de extras dentro de un grupo
     */
    function reindexExtrasItems($group) {
        var groupIndex = $group.index();
        $group.find('.extras-item-row').each(function(itemIndex) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    // Reemplazar [items][X] con el nuevo índice
                    name = name.replace(/\[items\]\[\d+\]/, '[items][' + itemIndex + ']');
                    $(this).attr('name', name);
                }
            });
        });
    }

    /**
     * Inicializar envío del formulario
     */
    function initFormSubmit() {
        $('#churrascoplanet-options-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $button = $('#save-options');
            var $status = $('.save-status');

            // Sincronizar TinyMCE con textareas
            if (typeof tinymce !== 'undefined') {
                tinymce.triggerSave();
            }

            // Mostrar estado de guardando
            $button.addClass('saving');
            $button.find('i').removeClass('fa-save').addClass('fa-spinner fa-spin');
            $status.removeClass('visible error').text('');

            // Recopilar datos del formulario
            var formData = new FormData($form[0]);
            formData.append('action', 'churrascoplanet_save_options');
            formData.append('nonce', churrascoplanetAdmin.nonce);

            // Convertir FormData a objeto para el AJAX
            var options = {};
            var formArray = $form.serializeArray();

            formArray.forEach(function(item) {
                // Parsear nombres con corchetes (arrays)
                var keys = item.name.match(/[^\[\]]+/g);
                if (!keys) return;

                var current = options;
                for (var i = 0; i < keys.length - 1; i++) {
                    if (!current[keys[i]]) {
                        current[keys[i]] = {};
                    }
                    current = current[keys[i]];
                }
                current[keys[keys.length - 1]] = item.value;
            });

            // Enviar AJAX
            $.ajax({
                url: churrascoplanetAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'churrascoplanet_save_options',
                    nonce: churrascoplanetAdmin.nonce,
                    options: options.churrascoplanet_options || {}
                },
                success: function(response) {
                    $button.removeClass('saving');
                    $button.find('i').removeClass('fa-spinner fa-spin').addClass('fa-save');

                    if (response.success) {
                        $status.text(churrascoplanetAdmin.strings.saved).addClass('visible');

                        // Ocultar mensaje después de 3 segundos
                        setTimeout(function() {
                            $status.removeClass('visible');
                        }, 3000);
                    } else {
                        $status.text(response.data.message || churrascoplanetAdmin.strings.error)
                               .addClass('visible error');
                    }
                },
                error: function() {
                    $button.removeClass('saving');
                    $button.find('i').removeClass('fa-spinner fa-spin').addClass('fa-save');
                    $status.text(churrascoplanetAdmin.strings.error).addClass('visible error');
                }
            });
        });
    }

})(jQuery);
