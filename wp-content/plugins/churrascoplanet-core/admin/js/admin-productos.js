/**
 * ChurrascoPlanet Core - Módulo Productos
 * JavaScript para gestión de productos
 */
(function($) {
    'use strict';

    const config = window.chpProductos || {};
    const strings = config.strings || {};

    $(document).ready(function() {
        if ($('.chp-products-list').length) initProductList();
        if ($('.chp-product-form').length) initProductForm();
        if ($('.chp-taxonomy-page').length) initTaxonomyManager();
    });

    // ─── Product List ───
    function initProductList() {
        // Select all checkbox
        $(document).on('change', '#chp-select-all', function() {
            $('.chp-product-cb').prop('checked', $(this).is(':checked'));
        });

        // Delete product
        $(document).on('click', '.chp-delete-product', function(e) {
            e.preventDefault();
            if (!confirm(strings.confirmDelete)) return;

            var $row = $(this).closest('tr');
            var productId = $(this).data('id');

            $.post(config.ajaxUrl, {
                action: 'chp_producto_delete',
                nonce: config.nonce,
                product_id: productId
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    showNotice('success', strings.deleted);
                } else {
                    showNotice('error', res.data.message || strings.error);
                }
            });
        });

        // Toggle status
        $(document).on('click', '.chp-toggle-status', function(e) {
            e.preventDefault();
            var $badge = $(this);
            var productId = $badge.data('id');

            $.post(config.ajaxUrl, {
                action: 'chp_producto_toggle_status',
                nonce: config.nonce,
                product_id: productId
            }, function(res) {
                if (res.success) {
                    if (res.data.status === 'publish') {
                        $badge.text('Publicado').removeClass('chp-badge-draft').addClass('chp-badge-publish');
                    } else {
                        $badge.text('Borrador').removeClass('chp-badge-publish').addClass('chp-badge-draft');
                    }
                }
            });
        });

        // Bulk action
        $(document).on('click', '#chp-bulk-apply', function() {
            var action = $('#chp-bulk-action').val();
            if (!action) return;

            var ids = [];
            $('.chp-product-cb:checked').each(function() {
                ids.push($(this).val());
            });

            if (ids.length === 0) return;
            if (action === 'delete' && !confirm(strings.confirmBulkDelete)) return;

            $.post(config.ajaxUrl, {
                action: 'chp_producto_bulk_action',
                nonce: config.nonce,
                bulk_action: action,
                product_ids: ids
            }, function(res) {
                if (res.success) {
                    location.reload();
                } else {
                    showNotice('error', res.data.message || strings.error);
                }
            });
        });
    }

    // ─── Product Form ───
    function initProductForm() {
        initDataTabs();
        initStatusToggle();
        initPriceValidation();
        initProductImage();
        initProductGallery();
        initCategoryChecklist();
        initTagInput();
        initFormSubmit();
    }

    function initDataTabs() {
        $(document).on('click', '.chp-data-tab-link', function(e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            $(this).addClass('active').siblings().removeClass('active');
            $('#chp-data-' + tab).addClass('active').siblings('.chp-data-tab-content').removeClass('active');
        });
    }

    function initStatusToggle() {
        $(document).on('click', '.chp-status-opt', function() {
            var value = $(this).data('value');
            $('#chp-product-status').val(value);
            $('.chp-status-opt').removeClass('active');
            $(this).addClass('active');
        });
    }

    function initPriceValidation() {
        var $regular = $('#chp-regular-price');
        var $sale = $('#chp-sale-price');
        var $msg = $('#chp-price-validation');

        function validatePrices() {
            var reg = parseFloat($regular.val()) || 0;
            var sale = parseFloat($sale.val()) || 0;
            var saleEmpty = $sale.val() === '';

            $regular.closest('.chp-field').removeClass('has-error');
            $sale.closest('.chp-field').removeClass('has-error');
            $msg.hide().empty();

            if (saleEmpty) return true;

            if (sale >= reg && reg > 0) {
                $sale.closest('.chp-field').addClass('has-error');
                $msg.html('<i class="fas fa-exclamation-triangle"></i> El precio de oferta debe ser menor al precio regular ($' + reg.toLocaleString('es-CL') + ')')
                    .removeClass('warning info').addClass('error').show();
                return false;
            }

            if (reg === 0 && sale > 0) {
                $regular.closest('.chp-field').addClass('has-error');
                $msg.html('<i class="fas fa-exclamation-triangle"></i> Ingresa primero un precio regular antes de definir la oferta')
                    .removeClass('error info').addClass('warning').show();
                return false;
            }

            if (sale > 0 && reg > 0 && sale < reg) {
                var discount = Math.round(((reg - sale) / reg) * 100);
                $msg.html('<i class="fas fa-check-circle"></i> Descuento del ' + discount + '% — Ahorro de $' + (reg - sale).toLocaleString('es-CL') + ' por unidad')
                    .removeClass('error warning').addClass('info').show();
            }

            return true;
        }

        $regular.on('input', validatePrices);
        $sale.on('input', validatePrices);
        // Validar al cargar si ya hay datos
        if ($sale.val()) validatePrices();
    }

    function initProductImage() {
        $(document).on('click', '#chp-select-image', function(e) {
            e.preventDefault();
            var frame = wp.media({
                title: strings.selectImage,
                button: { text: strings.useImage },
                multiple: false
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#chp-product-image-id').val(attachment.id);
                $('#chp-image-preview').html(
                    '<img src="' + (attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url) + '" alt="">' +
                    '<button type="button" class="chp-remove-image"><i class="fas fa-times"></i></button>'
                ).show();
            });
            frame.open();
        });

        $(document).on('click', '.chp-remove-image', function() {
            $('#chp-product-image-id').val('');
            $('#chp-image-preview').empty().hide();
        });
    }

    function initProductGallery() {
        // Add gallery images
        $(document).on('click', '#chp-add-gallery', function(e) {
            e.preventDefault();
            var frame = wp.media({
                title: strings.selectGallery,
                button: { text: strings.addToGallery },
                multiple: true
            });
            frame.on('select', function() {
                var attachments = frame.state().get('selection').toJSON();
                var $container = $('#chp-gallery-images');
                attachments.forEach(function(att) {
                    var thumb = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    $container.append(
                        '<div class="chp-gallery-item" data-id="' + att.id + '">' +
                        '<img src="' + thumb + '" alt="">' +
                        '<button type="button" class="chp-remove-gallery-item"><i class="fas fa-times"></i></button>' +
                        '<input type="hidden" name="gallery_ids[]" value="' + att.id + '">' +
                        '</div>'
                    );
                });
            });
            frame.open();
        });

        // Remove gallery item
        $(document).on('click', '.chp-remove-gallery-item', function() {
            $(this).closest('.chp-gallery-item').remove();
        });

        // Sortable gallery
        if ($('#chp-gallery-images').length) {
            $('#chp-gallery-images').sortable({ items: '.chp-gallery-item', cursor: 'move' });
        }
    }

    function initCategoryChecklist() {
        // Toggle add new form
        $(document).on('click', '.chp-add-term-toggle', function() {
            $(this).siblings('.chp-add-term-form').toggleClass('show');
        });

        // Quick add category
        $(document).on('click', '#chp-add-cat-btn', function() {
            var name = $('#chp-new-cat-name').val().trim();
            if (!name) return;

            var parent = $('#chp-new-cat-parent').val() || 0;
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'chp_categoria_save',
                nonce: config.nonce,
                name: name,
                parent: parent
            }, function(res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    var depthClass = parent > 0 ? ' depth-1' : '';
                    $('#chp-category-checklist').append(
                        '<div class="chp-checklist-item' + depthClass + '">' +
                        '<input type="checkbox" name="category_ids[]" value="' + res.data.term_id + '" checked> ' +
                        name + '</div>'
                    );
                    $('#chp-new-cat-name').val('');
                }
            });
        });
    }

    function initTagInput() {
        $(document).on('click', '#chp-add-tag-btn', function() {
            var name = $('#chp-new-tag-input').val().trim();
            if (!name) return;

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'chp_etiqueta_save',
                nonce: config.nonce,
                name: name
            }, function(res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    $('#chp-tag-tokens').append(
                        '<span class="chp-tag-token" data-id="' + res.data.term_id + '">' +
                        name +
                        '<input type="hidden" name="tag_ids[]" value="' + res.data.term_id + '">' +
                        '<button type="button" class="remove-tag"><i class="fas fa-times"></i></button>' +
                        '</span>'
                    );
                    $('#chp-new-tag-input').val('');
                }
            });
        });

        // Enter key on tag input
        $(document).on('keypress', '#chp-new-tag-input', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#chp-add-tag-btn').trigger('click');
            }
        });

        // Remove tag
        $(document).on('click', '.remove-tag', function() {
            $(this).closest('.chp-tag-token').remove();
        });
    }

    function initFormSubmit() {
        $(document).on('click', '#chp-save-product', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $form = $('#chp-product-form');

            // Validar nombre
            var productName = $form.find('[name="product_name"]').val();
            if (!productName || !productName.trim()) {
                showNotice('error', 'El nombre del producto es obligatorio');
                $form.find('[name="product_name"]').focus();
                return;
            }

            // Validar precios
            var regPrice = parseFloat($('#chp-regular-price').val()) || 0;
            var salePrice = parseFloat($('#chp-sale-price').val()) || 0;
            var saleEmpty = $('#chp-sale-price').val() === '';

            if (!saleEmpty && salePrice >= regPrice && regPrice > 0) {
                showNotice('error', 'El precio de oferta ($' + salePrice.toLocaleString('es-CL') + ') no puede ser mayor o igual al precio regular ($' + regPrice.toLocaleString('es-CL') + ')');
                $('#chp-sale-price').focus();
                return;
            }

            if (!saleEmpty && salePrice > 0 && regPrice === 0) {
                showNotice('error', 'Debes ingresar un precio regular antes de definir un precio de oferta');
                $('#chp-regular-price').focus();
                return;
            }

            $btn.prop('disabled', true).html('<span class="chp-loading"></span> ' + strings.saving);

            // Collect form data
            var statusVal = $('#chp-product-status').val() || 'publish';
            var data = {
                action: 'chp_producto_save',
                nonce: config.nonce,
                product_id: $form.find('[name="product_id"]').val() || '',
                name: $form.find('[name="product_name"]').val(),
                status: statusVal,
                visibility: $form.find('[name="product_visibility"]').val() || 'visible',
                regular_price: $form.find('[name="regular_price"]').val(),
                sale_price: $form.find('[name="sale_price"]').val(),
                sku: $form.find('[name="sku"]').val(),
                manage_stock: $form.find('[name="manage_stock"]').is(':checked') ? 'yes' : 'no',
                stock_quantity: $form.find('[name="stock_quantity"]').val(),
                stock_status: $form.find('[name="stock_status"]').val(),
                backorders: $form.find('[name="backorders"]').val() || 'no',
                weight: $form.find('[name="weight"]').val(),
                image_id: $form.find('[name="image_id"]').val(),
                description: getEditorContent('product_description'),
                short_description: getEditorContent('product_short_description')
            };

            // Gallery
            var galleryIds = [];
            $form.find('input[name="gallery_ids[]"]').each(function() {
                galleryIds.push($(this).val());
            });
            data.gallery_ids = galleryIds;

            // Categories
            var catIds = [];
            $form.find('input[name="category_ids[]"]:checked').each(function() {
                catIds.push($(this).val());
            });
            data.category_ids = catIds;

            // Tags
            var tagIds = [];
            $form.find('input[name="tag_ids[]"]').each(function() {
                tagIds.push($(this).val());
            });
            data.tag_ids = tagIds;

            // Extras groups
            var extrasGroups = [];
            $form.find('input[name="extras_groups[]"]:checked').each(function() {
                extrasGroups.push($(this).val());
            });
            data.extras_groups = extrasGroups;

            $.post(config.ajaxUrl, data, function(res) {
                if (res.success) {
                    showNotice('success', res.data.message);
                    // Redirect to edit page if was new
                    if (!data.product_id && res.data.product_id) {
                        window.location.href = config.pageUrl + '&section=edit&product_id=' + res.data.product_id + '&saved=1';
                    } else {
                        $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Guardar Producto');
                    }
                } else {
                    showNotice('error', res.data.message || strings.error);
                    $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Guardar Producto');
                }
            }).fail(function() {
                showNotice('error', strings.error);
                $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Guardar Producto');
            });
        });
    }

    function getEditorContent(editorId) {
        if (typeof tinyMCE !== 'undefined') {
            var editor = tinyMCE.get(editorId);
            if (editor && !editor.isHidden()) {
                return editor.getContent();
            }
        }
        var $textarea = $('#' + editorId);
        return $textarea.length ? $textarea.val() : '';
    }

    // ─── Taxonomy Manager ───
    function initTaxonomyManager() {
        // Image upload for categories
        $(document).on('click', '#chp-select-cat-image', function(e) {
            e.preventDefault();
            var frame = wp.media({
                title: strings.selectImage,
                button: { text: strings.useImage },
                multiple: false
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#chp-cat-thumbnail-id').val(attachment.id);
                var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                $('#chp-cat-image-preview').html(
                    '<img src="' + thumb + '" style="max-width:100%;border-radius:6px;">' +
                    '<button type="button" class="chp-remove-cat-image chp-btn chp-btn-sm chp-btn-outline" style="margin-top:6px;">Quitar imagen</button>'
                );
            });
            frame.open();
        });

        $(document).on('click', '.chp-remove-cat-image', function() {
            $('#chp-cat-thumbnail-id').val('');
            $('#chp-cat-image-preview').empty();
        });

        // Save taxonomy term
        $(document).on('click', '#chp-save-term', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $form = $btn.closest('.chp-taxonomy-form');
            var taxonomy = $form.data('taxonomy'); // 'category' or 'tag'
            var action = taxonomy === 'category' ? 'chp_categoria_save' : 'chp_etiqueta_save';

            var data = {
                action: action,
                nonce: config.nonce,
                term_id: $form.find('[name="term_id"]').val() || '',
                name: $form.find('[name="term_name"]').val(),
                slug: $form.find('[name="term_slug"]').val(),
                description: $form.find('[name="term_description"]').val()
            };

            if (taxonomy === 'category') {
                data.parent = $form.find('[name="term_parent"]').val() || 0;
                data.thumbnail_id = $form.find('[name="thumbnail_id"]').val() || 0;
            }

            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, data, function(res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    location.reload();
                } else {
                    showNotice('error', res.data.message || strings.error);
                }
            });
        });

        // Edit term - load into form
        $(document).on('click', '.chp-edit-term', function(e) {
            e.preventDefault();
            var $row = $(this).closest('tr');
            var $form = $('.chp-taxonomy-form');

            $form.find('[name="term_id"]').val($row.data('id'));
            $form.find('[name="term_name"]').val($row.data('name'));
            $form.find('[name="term_slug"]').val($row.data('slug'));
            $form.find('[name="term_description"]').val($row.data('description') || '');

            if ($form.find('[name="term_parent"]').length) {
                $form.find('[name="term_parent"]').val($row.data('parent') || 0);
            }

            // Update button text
            $form.find('#chp-save-term').text('Actualizar');
            $form.find('.chp-cancel-edit').show();

            $('html, body').animate({ scrollTop: $form.offset().top - 50 }, 300);
        });

        // Cancel edit
        $(document).on('click', '.chp-cancel-edit', function(e) {
            e.preventDefault();
            var $form = $('.chp-taxonomy-form');
            $form.find('[name="term_id"]').val('');
            $form.find('[name="term_name"]').val('');
            $form.find('[name="term_slug"]').val('');
            $form.find('[name="term_description"]').val('');
            if ($form.find('[name="term_parent"]').length) {
                $form.find('[name="term_parent"]').val(0);
            }
            $('#chp-cat-thumbnail-id').val('');
            $('#chp-cat-image-preview').empty();
            $form.find('#chp-save-term').text('Agregar');
            $(this).hide();
        });

        // Delete term
        $(document).on('click', '.chp-delete-term', function(e) {
            e.preventDefault();
            var taxonomy = $(this).data('taxonomy');
            var confirmMsg = taxonomy === 'category' ? strings.confirmDeleteCat : strings.confirmDeleteTag;
            if (!confirm(confirmMsg)) return;

            var termId = $(this).data('id');
            var action = taxonomy === 'category' ? 'chp_categoria_delete' : 'chp_etiqueta_delete';
            var $row = $(this).closest('tr');

            $.post(config.ajaxUrl, {
                action: action,
                nonce: config.nonce,
                term_id: termId
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                } else {
                    showNotice('error', res.data.message || strings.error);
                }
            });
        });
    }

    // ─── Helpers ───
    function showNotice(type, message) {
        var $notice = $('<div class="chp-notice chp-notice-' + type + '"><i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message + '</div>');
        $('.chp-productos-content').prepend($notice);
        setTimeout(function() { $notice.fadeOut(300, function() { $(this).remove(); }); }, 4000);
    }

})(jQuery);
