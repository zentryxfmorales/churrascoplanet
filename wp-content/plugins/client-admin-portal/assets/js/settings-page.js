/**
 * Client Admin Portal - Settings Page JavaScript
 *
 * @package Client_Admin_Portal
 * @since   2.0.0
 */

(function($) {
    'use strict';

    // Ensure capSettings is available
    if (typeof capSettings === 'undefined') {
        console.error('CAP Settings: capSettings not defined');
        return;
    }

    /**
     * Settings Page Controller
     */
    const CAPSettings = {
        /**
         * Initialize
         */
        init: function() {
            this.initColorPickers();
            this.initPresetSelector();
            this.initRoleTabs();
            this.initBrandingForm();
            this.initLoginRedirect();
            this.initLogoUpload();
            this.initModuleToggles();
            this.initModuleAccess();
            this.initModuleManagement();
            this.initAuditLog();
            this.initExportImport();
            this.initSortable();
            this.initIconPicker();
        },

        /**
         * Initialize color pickers
         */
        initColorPickers: function() {
            $('.cap-color-picker').wpColorPicker({
                change: function(event, ui) {
                    // Trigger change for live preview
                    $(event.target).trigger('cap:colorChange', [ui.color.toString()]);
                }
            });
        },

        /**
         * Initialize preset selector
         */
        initPresetSelector: function() {
            const $customSection = $('.cap-custom-colors-section');

            $('input[name="settings[theme_preset]"]').on('change', function() {
                const preset = $(this).val();

                // Update selected state
                $('.cap-preset-card').removeClass('selected');
                $(this).closest('.cap-preset-card').addClass('selected');

                // Show/hide custom colors section
                if (preset === 'custom') {
                    $customSection.slideDown();
                } else {
                    $customSection.slideUp();

                    // Fill in preset colors
                    if (capSettings.presets[preset]) {
                        const colors = capSettings.presets[preset];
                        Object.keys(colors).forEach(function(key) {
                            if (key !== 'name') {
                                const $input = $('input[name="settings[' + key + ']"]');
                                if ($input.length) {
                                    $input.wpColorPicker('color', colors[key]);
                                }
                            }
                        });
                    }
                }
            });
        },

        /**
         * Initialize role tabs for per-role branding
         */
        initRoleTabs: function() {
            const self = this;

            $('.cap-role-tab').on('click', function() {
                const $tab = $(this);
                const role = $tab.data('role');

                // Update active state
                $('.cap-role-tab').removeClass('active');
                $tab.addClass('active');

                // Update hidden input
                $('#cap-branding-role').val(role);

                // Load role-specific settings
                self.loadRoleSettings(role);
            });
        },

        /**
         * Load settings for a specific role (or global when role is empty)
         */
        loadRoleSettings: function(role) {
            const self = this;
            const $form = $('#cap-branding-form');
            const $button = $form.find('button[type="submit"]');
            const $status = $('.cap-save-status');

            $button.prop('disabled', true);
            $status.text(capSettings.strings.loading).removeClass('success error');

            $.ajax({
                url: capSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cap_get_branding_settings',
                    nonce: capSettings.nonce,
                    role_slug: role
                },
                success: function(response) {
                    if (!response.success) {
                        $status.text(response.data.message || capSettings.strings.error).addClass('error');
                        return;
                    }

                    const settings     = response.data.settings;
                    const hasOverrides = response.data.has_overrides;

                    // 1. Update preset radio without firing the change handler
                    //    (which would overwrite DB colors with preset defaults).
                    const preset       = settings.theme_preset || 'dark_space';
                    const $presetRadio = $('input[name="settings[theme_preset]"][value="' + preset + '"]');

                    $('input[name="settings[theme_preset]"]').prop('checked', false);
                    $presetRadio.prop('checked', true);

                    // Sync visual selected state on preset cards.
                    $('.cap-preset-card').removeClass('selected');
                    $presetRadio.closest('.cap-preset-card').addClass('selected');

                    // Show / hide custom colors section.
                    if (preset === 'custom') {
                        $('.cap-custom-colors-section').show();
                    } else {
                        $('.cap-custom-colors-section').hide();
                    }

                    // 2. Set every color picker to the loaded value.
                    var colorKeys = [
                        'bg_main', 'bg_card', 'bg_input', 'bg_hover', 'bg_header',
                        'text_main', 'text_muted', 'text_faint',
                        'accent', 'accent_hover', 'border', 'border_light',
                        'success', 'warning', 'error', 'info'
                    ];
                    colorKeys.forEach(function(key) {
                        if (settings[key]) {
                            var $input = $('input[name="settings[' + key + ']"]');
                            if ($input.length) {
                                $input.wpColorPicker('color', settings[key]);
                            }
                        }
                    });

                    // 3. Logo.
                    var logoUrl = settings.logo_url || '';
                    $('#cap-logo-url').val(logoUrl);
                    if (logoUrl) {
                        $('.cap-logo-preview').html('<img src="' + self.escapeHtml(logoUrl) + '" alt="Logo">');
                        if (!$('.cap-remove-logo').length) {
                            $('.cap-upload-logo').after(
                                '<button type="button" class="button cap-remove-logo">' +
                                '<span class="dashicons dashicons-trash"></span></button>'
                            );
                        }
                    } else {
                        $('.cap-logo-preview').html('<span class="cap-no-logo">Sin logo</span>');
                        $('.cap-remove-logo').remove();
                    }

                    // 4. Text / textarea fields.
                    $('input[name="settings[logo_height]"]').val(settings.logo_height || '60');
                    $('input[name="settings[brand_name]"]').val(settings.brand_name || '');
                    $('input[name="settings[admin_footer]"]').val(settings.admin_footer || '');
                    $('textarea[name="settings[custom_css]"]').val(settings.custom_css || '');

                    // 5. Update the custom-override badge on the active role tab.
                    var $activeTab = $('.cap-role-tab.active');
                    if (hasOverrides && role) {
                        if (!$activeTab.find('.cap-custom-badge').length) {
                            $activeTab.append('<span class="cap-custom-badge">Custom</span>');
                        }
                    } else if (!role) {
                        // No badge on the Global tab.
                        $activeTab.find('.cap-custom-badge').remove();
                    }

                    $status.text(role ? 'Editando: ' + role : 'Editando: Global').removeClass('success error');
                },
                error: function() {
                    $status.text(capSettings.strings.error).addClass('error');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Initialize login redirect dropdowns (save on change + custom URL input)
         */
        initLoginRedirect: function() {
            const self = this;

            // Show/hide custom URL input and save on select change.
            $(document).on('change', '.cap-login-redirect', function() {
                const $select = $(this);
                const role    = $select.data('role');
                const value   = $select.val();
                const $card   = $select.closest('.cap-role-card');
                const $wrap   = $card.find('.cap-custom-redirect-wrap');

                if (value === 'custom') {
                    $wrap.slideDown(150);
                    $wrap.find('.cap-custom-redirect-input').focus();
                    // Wait for user to type before saving.
                } else {
                    $wrap.slideUp(150);
                    self.saveLoginRedirect(role, value, $card);
                }
            });

            // Save custom URL on blur (user finished typing).
            $(document).on('blur', '.cap-custom-redirect-input', function() {
                const $input = $(this);
                const role   = $input.data('role');
                const value  = $input.val().trim();
                const $card  = $input.closest('.cap-role-card');

                if (value) {
                    self.saveLoginRedirect(role, value, $card);
                }
            });

            // Also save on Enter key.
            $(document).on('keydown', '.cap-custom-redirect-input', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $(this).blur();
                }
            });
        },

        /**
         * Save login redirect for a role via AJAX
         */
        saveLoginRedirect: function(role, value, $card) {
            const $status = $card ? $card.find('.cap-redirect-status') : $();

            $status.text(capSettings.strings.loading).removeClass('success error');

            $.ajax({
                url: capSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action:    'cap_save_login_redirect',
                    nonce:     capSettings.nonce,
                    role_slug: role,
                    redirect:  value
                },
                success: function(response) {
                    if (response.success) {
                        $status.text('✓').addClass('success');
                    } else {
                        $status.text(response.data.message || capSettings.strings.error).addClass('error');
                    }
                },
                error: function() {
                    $status.text(capSettings.strings.error).addClass('error');
                },
                complete: function() {
                    setTimeout(function() {
                        $status.text('').removeClass('success error');
                    }, 2000);
                }
            });
        },

        /**
         * Initialize branding form
         */
        initBrandingForm: function() {
            const self = this;

            $('#cap-branding-form').on('submit', function(e) {
                e.preventDefault();
                self.saveBranding();
            });
        },

        /**
         * Save branding settings
         */
        saveBranding: function() {
            const $form = $('#cap-branding-form');
            const $status = $('.cap-save-status');
            const $button = $form.find('button[type="submit"]');

            // Collect form data
            const formData = new FormData($form[0]);
            formData.append('action', 'cap_save_branding');
            formData.append('nonce', capSettings.nonce);

            // Convert to object for settings
            const settings = {};
            $form.find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                if (name && name.startsWith('settings[')) {
                    const key = name.replace('settings[', '').replace(']', '');
                    if ($(this).attr('type') === 'radio') {
                        if ($(this).is(':checked')) {
                            settings[key] = $(this).val();
                        }
                    } else {
                        settings[key] = $(this).val();
                    }
                }
            });

            // Disable button
            $button.prop('disabled', true);
            $status.text(capSettings.strings.loading).removeClass('success error');

            $.ajax({
                url: capSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cap_save_branding',
                    nonce: capSettings.nonce,
                    role_slug: $('#cap-branding-role').val(),
                    settings: settings
                },
                success: function(response) {
                    if (response.success) {
                        $status.text(capSettings.strings.saved).addClass('success');
                    } else {
                        $status.text(response.data.message || capSettings.strings.error).addClass('error');
                    }
                },
                error: function() {
                    $status.text(capSettings.strings.error).addClass('error');
                },
                complete: function() {
                    $button.prop('disabled', false);
                    setTimeout(function() {
                        $status.text('').removeClass('success error');
                    }, 3000);
                }
            });
        },

        /**
         * Initialize logo upload
         */
        initLogoUpload: function() {
            let mediaUploader;

            $('.cap-upload-logo').on('click', function(e) {
                e.preventDefault();

                if (mediaUploader) {
                    mediaUploader.open();
                    return;
                }

                mediaUploader = wp.media({
                    title: capSettings.strings.selectImage,
                    button: {
                        text: capSettings.strings.useImage
                    },
                    multiple: false
                });

                mediaUploader.on('select', function() {
                    const attachment = mediaUploader.state().get('selection').first().toJSON();
                    $('#cap-logo-url').val(attachment.url);
                    $('.cap-logo-preview').html('<img src="' + attachment.url + '" alt="Logo">');

                    // Add remove button if not present
                    if (!$('.cap-remove-logo').length) {
                        $('.cap-upload-logo').after('<button type="button" class="button cap-remove-logo"><span class="dashicons dashicons-trash"></span></button>');
                    }
                });

                mediaUploader.open();
            });

            $(document).on('click', '.cap-remove-logo', function() {
                $('#cap-logo-url').val('');
                $('.cap-logo-preview').html('<span class="cap-no-logo">Sin logo</span>');
                $(this).remove();
            });
        },

        /**
         * Initialize module toggles
         */
        initModuleToggles: function() {
            $(document).on('change', '.cap-module-active-toggle', function() {
                const $toggle = $(this);
                const moduleSlug = $toggle.data('module');
                const isActive = $toggle.is(':checked');
                const $card = $toggle.closest('.cap-module-card');

                // Disable toggle while processing.
                $toggle.prop('disabled', true);

                $.ajax({
                    url: capSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'cap_save_module',
                        nonce: capSettings.nonce,
                        module_slug: moduleSlug,
                        is_active: isActive ? '1' : '0'
                    },
                    success: function(response) {
                        if (response.success) {
                            $card.toggleClass('is-active', isActive).toggleClass('is-inactive', !isActive);
                        } else {
                            // Revert.
                            $toggle.prop('checked', !isActive);
                            alert(response.data.message || capSettings.strings.error);
                        }
                    },
                    error: function(xhr, status, error) {
                        // Revert.
                        $toggle.prop('checked', !isActive);
                        console.error('CAP Module Toggle Error:', status, error);
                        alert(capSettings.strings.error);
                    },
                    complete: function() {
                        // Re-enable toggle.
                        $toggle.prop('disabled', false);
                    }
                });
            });
        },

        /**
         * Initialize module access toggles
         */
        initModuleAccess: function() {
            $(document).on('change', '.cap-module-role-toggle', function() {
                const $toggle = $(this);
                const moduleSlug = $toggle.data('module');
                const roleSlug = $toggle.data('role');
                const canView = $toggle.is(':checked');

                $.ajax({
                    url: capSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'cap_save_module_access',
                        nonce: capSettings.nonce,
                        module_slug: moduleSlug,
                        role_slug: roleSlug,
                        can_view: canView
                    },
                    success: function(response) {
                        if (!response.success) {
                            $toggle.prop('checked', !canView);
                            alert(response.data.message || capSettings.strings.error);
                        }
                    },
                    error: function() {
                        $toggle.prop('checked', !canView);
                        alert(capSettings.strings.error);
                    }
                });
            });
        },

        /**
         * Initialize audit log functionality
         */
        initAuditLog: function() {
            const self = this;

            // Filter button
            $('#cap-audit-filter').on('click', function() {
                self.loadAuditLogs(0);
            });

            // Reset button
            $('#cap-audit-reset').on('click', function() {
                $('#cap-audit-search').val('');
                $('#cap-audit-action').val('');
                $('#cap-audit-date-from').val('');
                $('#cap-audit-date-to').val('');
                self.loadAuditLogs(0);
            });

            // Load more
            $('#cap-audit-load-more').on('click', function() {
                const offset = parseInt($(this).data('offset'));
                self.loadAuditLogs(offset, true);
            });

            // View changes modal
            $(document).on('click', '.cap-view-changes', function() {
                const oldVal = $(this).data('old');
                const newVal = $(this).data('new');

                $('#cap-change-old-value').text(self.formatValue(oldVal));
                $('#cap-change-new-value').text(self.formatValue(newVal));
                $('#cap-changes-modal').show();
            });

            // Close modal
            $('.cap-modal-close, .cap-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#cap-changes-modal').hide();
                }
            });

            // Export audit log
            $('#cap-export-audit').on('click', function() {
                self.exportAuditLog();
            });
        },

        /**
         * Load audit logs
         */
        loadAuditLogs: function(offset, append) {
            const $tbody = $('#cap-audit-body');
            const $loadMore = $('#cap-audit-load-more');
            const $total = $('#cap-audit-total');

            const data = {
                action: 'cap_get_audit_logs',
                nonce: capSettings.nonce,
                offset: offset,
                limit: 50,
                search: $('#cap-audit-search').val(),
                action_filter: $('#cap-audit-action').val(),
                date_from: $('#cap-audit-date-from').val(),
                date_to: $('#cap-audit-date-to').val()
            };

            $.ajax({
                url: capSettings.ajaxUrl,
                type: 'POST',
                data: data,
                beforeSend: function() {
                    if (!append) {
                        $tbody.html('<tr><td colspan="6" style="text-align:center">' + capSettings.strings.loading + '</td></tr>');
                    }
                },
                success: function(response) {
                    if (response.success) {
                        const logs = response.data.logs;
                        const total = response.data.total;

                        $total.text('(' + total + ')');

                        if (!append) {
                            $tbody.empty();
                        }

                        if (logs.length === 0 && !append) {
                            $tbody.html('<tr class="no-items"><td colspan="6">No hay registros de actividad.</td></tr>');
                        } else {
                            logs.forEach(function(log) {
                                $tbody.append(CAPSettings.buildLogRow(log));
                            });
                        }

                        // Update load more button
                        $loadMore.data('offset', offset + 50);
                        $loadMore.prop('disabled', logs.length < 50);
                    }
                }
            });
        },

        /**
         * Build a log table row
         */
        buildLogRow: function(log) {
            const actionLabels = {
                'login': 'Login',
                'logout': 'Logout',
                'login_failed': 'Failed Login',
                'settings_change': 'Settings Change',
                'module_toggle': 'Module Toggle',
                'module_access': 'Module Access Change',
                'branding_change': 'Branding Change',
                'export': 'Export',
                'import': 'Import'
            };

            const date = new Date(log.created_at);
            const dateStr = date.toLocaleDateString('es-ES');
            const timeStr = date.toLocaleTimeString('es-ES');

            let changesButton = '<span class="cap-na">—</span>';
            if (log.old_value || log.new_value) {
                changesButton = '<button type="button" class="button button-small cap-view-changes" data-old="' +
                    this.escapeHtml(log.old_value || '') + '" data-new="' + this.escapeHtml(log.new_value || '') +
                    '"><span class="dashicons dashicons-visibility"></span></button>';
            }

            return '<tr>' +
                '<td class="column-date"><span class="cap-log-date">' + dateStr + '</span><span class="cap-log-time">' + timeStr + '</span></td>' +
                '<td class="column-user">' + this.escapeHtml(log.user_login) + '</td>' +
                '<td class="column-action"><span class="cap-action-badge cap-action-' + log.action + '">' + (actionLabels[log.action] || log.action) + '</span></td>' +
                '<td class="column-target">' + (log.target ? '<code>' + this.escapeHtml(log.target) + '</code>' : '<span class="cap-na">—</span>') + '</td>' +
                '<td class="column-changes">' + changesButton + '</td>' +
                '<td class="column-ip"><span class="cap-ip">' + this.escapeHtml(log.ip_address) + '</span></td>' +
                '</tr>';
        },

        /**
         * Export audit log
         */
        exportAuditLog: function() {
            $.ajax({
                url: capSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cap_export_audit_log',
                    nonce: capSettings.nonce,
                    action_filter: $('#cap-audit-action').val(),
                    date_from: $('#cap-audit-date-from').val(),
                    date_to: $('#cap-audit-date-to').val()
                },
                success: function(response) {
                    if (response.success) {
                        CAPSettings.downloadFile(response.data.filename, response.data.data, 'text/csv');
                    }
                }
            });
        },

        /**
         * Initialize export/import functionality
         */
        initExportImport: function() {
            const self = this;

            // Export settings
            $('#cap-export-settings').on('click', function() {
                $.ajax({
                    url: capSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'cap_export_settings',
                        nonce: capSettings.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            self.downloadFile(
                                response.data.filename,
                                JSON.stringify(response.data.data, null, 2),
                                'application/json'
                            );
                        }
                    }
                });
            });

            // Reset branding
            $('#cap-reset-branding').on('click', function() {
                if (confirm(capSettings.strings.confirmReset)) {
                    // Reset to default preset
                    $('input[name="settings[theme_preset]"][value="dark_space"]').prop('checked', true).trigger('change');
                }
            });
        },

        /**
         * Initialize sortable modules
         */
        initSortable: function() {
            if ($('#cap-modules-sortable').length && $.fn.sortable) {
                $('.cap-module-group').each(function() {
                    $(this).find('.cap-module-card').parent().sortable({
                        handle: '.cap-module-drag-handle',
                        placeholder: 'cap-module-placeholder',
                        update: function(event, ui) {
                            // Save new order via AJAX
                            const order = [];
                            $(this).find('.cap-module-card').each(function(index) {
                                order.push({
                                    slug: $(this).data('module'),
                                    order: index
                                });
                            });
                            // TODO: Save order
                        }
                    });
                });
            }
        },

        /**
         * Initialize module management (add, edit, delete, import)
         */
        initModuleManagement: function() {
            const self = this;

            // Manual module form submission
            $('#cap-manual-module-form').on('submit', function(e) {
                e.preventDefault();
                self.addManualModule();
            });

            // Edit module button
            $(document).on('click', '.cap-edit-module', function() {
                const slug = $(this).data('module');
                self.openEditModal(slug);
            });

            // Delete module button
            $(document).on('click', '.cap-delete-module', function() {
                const slug = $(this).data('module');
                if (confirm(capSettings.strings.confirmDelete || '¿Eliminar este módulo?')) {
                    self.deleteModule(slug);
                }
            });

            // Edit module form submission
            $('#cap-edit-module-form').on('submit', function(e) {
                e.preventDefault();
                self.updateModule();
            });

            // Close edit modal
            $('#cap-edit-module-modal .cap-modal-close, #cap-edit-module-modal .cap-modal-cancel').on('click', function() {
                $('#cap-edit-module-modal').hide();
            });
            $('#cap-edit-module-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });
        },

        /**
         * Add a manual module
         */
        addManualModule: function() {
            const $form = $('#cap-manual-module-form');
            const $status = $form.find('.cap-form-status');
            const $button = $form.find('button[type="submit"]');

            const data = {
                action: 'cap_add_module',
                nonce: capSettings.nonce,
                name: $form.find('[name="name"]').val(),
                description: $form.find('[name="description"]').val(),
                menu_slug: $form.find('[name="menu_slug"]').val(),
                plugin_source: $form.find('[name="plugin_source"]').val(),
                capability: $form.find('[name="capability"]').val(),
                icon: $form.find('[name="icon"]').val(),
                sort_order: $form.find('[name="sort_order"]').val()
            };

            $button.prop('disabled', true);
            $status.text(capSettings.strings.loading).removeClass('success error');

            $.ajax({
                url: capSettings.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        $status.text(response.data.message).addClass('success');
                        // Reset form
                        $form[0].reset();
                        $form.find('[name="icon"]').val('dashicons-admin-generic');
                        $form.find('.cap-icon-preview .dashicons').attr('class', 'dashicons dashicons-admin-generic');
                        // Reload page to show new module
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $status.text(response.data.message || capSettings.strings.error).addClass('error');
                    }
                },
                error: function() {
                    $status.text(capSettings.strings.error).addClass('error');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Open edit modal for a module
         */
        openEditModal: function(slug) {
            const self = this;

            $.ajax({
                url: capSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cap_get_module',
                    nonce: capSettings.nonce,
                    slug: slug
                },
                success: function(response) {
                    if (response.success) {
                        const module = response.data.module;
                        $('#cap-edit-module-slug').val(module.module_slug);
                        $('#cap-edit-module-name').val(module.module_name);
                        $('#cap-edit-module-description').val(module.module_description);
                        $('#cap-edit-module-menu-slug').val(module.menu_slug);
                        $('#cap-edit-module-icon').val(module.icon);
                        $('#cap-edit-module-order').val(module.sort_order);
                        // Update icon preview
                        $('#cap-edit-module-modal .cap-icon-preview .dashicons')
                            .attr('class', 'dashicons ' + module.icon);
                        $('#cap-edit-module-modal').show();
                    } else {
                        alert(response.data.message || capSettings.strings.error);
                    }
                }
            });
        },

        /**
         * Update a module
         */
        updateModule: function() {
            const $form = $('#cap-edit-module-form');
            const $button = $form.find('button[type="submit"]');

            const data = {
                action: 'cap_update_module',
                nonce: capSettings.nonce,
                slug: $('#cap-edit-module-slug').val(),
                name: $('#cap-edit-module-name').val(),
                description: $('#cap-edit-module-description').val(),
                menu_slug: $('#cap-edit-module-menu-slug').val(),
                icon: $('#cap-edit-module-icon').val(),
                sort_order: $('#cap-edit-module-order').val()
            };

            $button.prop('disabled', true);

            $.ajax({
                url: capSettings.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        $('#cap-edit-module-modal').hide();
                        location.reload();
                    } else {
                        alert(response.data.message || capSettings.strings.error);
                    }
                },
                error: function() {
                    alert(capSettings.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Delete a module
         */
        deleteModule: function(slug) {
            $.ajax({
                url: capSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cap_delete_module',
                    nonce: capSettings.nonce,
                    slug: slug
                },
                success: function(response) {
                    if (response.success) {
                        // Remove the module card from DOM
                        $('.cap-module-card[data-module="' + slug + '"]').fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        alert(response.data.message || capSettings.strings.error);
                    }
                }
            });
        },

        /**
         * Initialize icon picker
         */
        initIconPicker: function() {
            const self = this;
            let $targetInput = null;

            // Open icon picker
            $(document).on('click', '.cap-pick-icon', function() {
                $targetInput = $(this).closest('.cap-icon-selector').find('input');
                $('#cap-icon-picker-modal').show();
            });

            // Select icon
            $(document).on('click', '.cap-icon-option', function() {
                const icon = $(this).data('icon');
                if ($targetInput) {
                    $targetInput.val(icon);
                    $targetInput.closest('.cap-icon-selector').find('.cap-icon-preview .dashicons')
                        .attr('class', 'dashicons ' + icon);
                }
                $('#cap-icon-picker-modal').hide();
            });

            // Close icon picker modal
            $('#cap-icon-picker-modal .cap-modal-close').on('click', function() {
                $('#cap-icon-picker-modal').hide();
            });
            $('#cap-icon-picker-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            // Search icons
            $('#cap-icon-search').on('input', function() {
                const search = $(this).val().toLowerCase();
                $('.cap-icon-option').each(function() {
                    const icon = $(this).data('icon').toLowerCase();
                    $(this).toggle(icon.indexOf(search) !== -1);
                });
            });

            // Live update icon preview on input change
            $(document).on('input', '#cap-module-icon, #cap-edit-module-icon', function() {
                const icon = $(this).val();
                $(this).closest('.cap-icon-selector').find('.cap-icon-preview .dashicons')
                    .attr('class', 'dashicons ' + icon);
            });
        },

        /**
         * Helper: Download file
         */
        downloadFile: function(filename, content, mimeType) {
            const blob = new Blob([content], { type: mimeType });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        },

        /**
         * Helper: Format value for display
         */
        formatValue: function(value) {
            if (!value) return '(vacío)';

            try {
                const parsed = JSON.parse(value);
                return JSON.stringify(parsed, null, 2);
            } catch (e) {
                return value;
            }
        },

        /**
         * Helper: Escape HTML
         */
        escapeHtml: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        CAPSettings.init();
    });

})(jQuery);
