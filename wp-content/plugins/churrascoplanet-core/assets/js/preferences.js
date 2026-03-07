/**
 * Preferences JavaScript
 *
 * Maneja la interacción del formulario de preferencias del cliente.
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Preferences Module
     */
    var ChpPreferences = {
        /**
         * Configuration
         */
        config: {
            ajaxUrl: '',
            nonce: ''
        },

        /**
         * DOM Elements
         */
        elements: {
            form: null,
            storeOptions: null,
            emailToggle: null,
            whatsappToggle: null,
            whatsappField: null,
            phoneInput: null,
            saveButton: null
        },

        /**
         * State
         */
        state: {
            isDirty: false,
            isSaving: false
        },

        /**
         * Initialize
         */
        init: function() {
            // Load config
            if (typeof chpPreferences !== 'undefined') {
                this.config = $.extend(this.config, chpPreferences);
            }

            // Cache elements
            this.cacheElements();

            // Bind events
            this.bindEvents();

            console.log('ChpPreferences: Initialized');
        },

        /**
         * Cache DOM elements
         */
        cacheElements: function() {
            this.elements.form = $('#chp-preferences-form');
            this.elements.storeOptions = $('.chp-store-option');
            this.elements.emailToggle = $('input[name="notification_email"]');
            this.elements.whatsappToggle = $('#notification_whatsapp');
            this.elements.whatsappField = $('#whatsapp-number-field');
            this.elements.phoneInput = $('#whatsapp_number');
            this.elements.saveButton = $('.chp-save-btn');
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;

            // Store selection
            this.elements.storeOptions.find('input').on('change', function() {
                self.handleStoreSelect($(this));
            });

            // WhatsApp toggle
            this.elements.whatsappToggle.on('change', function() {
                self.handleWhatsappToggle($(this).is(':checked'));
            });

            // Phone input formatting
            this.elements.phoneInput.on('input', function() {
                self.formatPhoneInput($(this));
            });

            // Form change tracking
            this.elements.form.find('input, select, textarea').on('change input', function() {
                self.state.isDirty = true;
            });

            // Form submission
            this.elements.form.on('submit', function(e) {
                if (!self.validateForm()) {
                    e.preventDefault();
                    return false;
                }
            });

            // Warn on page leave if unsaved changes
            $(window).on('beforeunload', function(e) {
                if (self.state.isDirty && !self.state.isSaving) {
                    return 'Tienes cambios sin guardar. ¿Deseas salir?';
                }
            });

            // Clear dirty state on save
            this.elements.saveButton.on('click', function() {
                self.state.isSaving = true;
            });
        },

        /**
         * Handle store selection
         *
         * @param {jQuery} $input Selected radio input
         */
        handleStoreSelect: function($input) {
            // Visual feedback
            this.elements.storeOptions.removeClass('selected');
            $input.closest('.chp-store-option').addClass('selected');

            // Animate selection
            var $icon = $input.closest('.chp-store-option').find('.chp-store-icon');
            $icon.addClass('pulse');
            setTimeout(function() {
                $icon.removeClass('pulse');
            }, 300);
        },

        /**
         * Handle WhatsApp toggle
         *
         * @param {boolean} isEnabled
         */
        handleWhatsappToggle: function(isEnabled) {
            if (isEnabled) {
                this.elements.whatsappField.slideDown(200);
                // Focus on input after animation
                setTimeout(function() {
                    this.elements.phoneInput.focus();
                }.bind(this), 200);
            } else {
                this.elements.whatsappField.slideUp(200);
            }
        },

        /**
         * Format phone input
         *
         * @param {jQuery} $input Phone input element
         */
        formatPhoneInput: function($input) {
            var value = $input.val();

            // Remove non-numeric characters
            value = value.replace(/\D/g, '');

            // Limit to 9 digits
            if (value.length > 9) {
                value = value.substr(0, 9);
            }

            // Format: 9 1234 5678
            if (value.length > 5) {
                value = value.substr(0, 1) + ' ' + value.substr(1, 4) + ' ' + value.substr(5);
            } else if (value.length > 1) {
                value = value.substr(0, 1) + ' ' + value.substr(1);
            }

            $input.val(value);
        },

        /**
         * Validate form
         *
         * @return {boolean}
         */
        validateForm: function() {
            var isValid = true;
            var errors = [];

            // Validate WhatsApp number if enabled
            if (this.elements.whatsappToggle.is(':checked')) {
                var phoneValue = this.elements.phoneInput.val().replace(/\s/g, '');

                if (!phoneValue) {
                    errors.push('Por favor ingresa tu número de WhatsApp.');
                    this.highlightField(this.elements.phoneInput, true);
                    isValid = false;
                } else if (phoneValue.length !== 9 || !/^9\d{8}$/.test(phoneValue)) {
                    errors.push('El número de WhatsApp debe tener 9 dígitos y comenzar con 9.');
                    this.highlightField(this.elements.phoneInput, true);
                    isValid = false;
                } else {
                    this.highlightField(this.elements.phoneInput, false);
                }
            }

            // Show errors if any
            if (errors.length) {
                this.showErrors(errors);
            }

            return isValid;
        },

        /**
         * Highlight field with error state
         *
         * @param {jQuery} $field
         * @param {boolean} hasError
         */
        highlightField: function($field, hasError) {
            if (hasError) {
                $field.addClass('chp-field-error');
                $field.closest('.chp-phone-input').addClass('chp-input-error');
            } else {
                $field.removeClass('chp-field-error');
                $field.closest('.chp-phone-input').removeClass('chp-input-error');
            }
        },

        /**
         * Show error messages
         *
         * @param {Array} errors
         */
        showErrors: function(errors) {
            // Remove existing errors
            $('.chp-form-errors').remove();

            var $errorBox = $('<div class="chp-form-errors woocommerce-error">')
                .html('<ul><li>' + errors.join('</li><li>') + '</li></ul>')
                .prependTo(this.elements.form);

            // Scroll to errors
            $('html, body').animate({
                scrollTop: $errorBox.offset().top - 100
            }, 300);
        },

        /**
         * Save preferences via AJAX (optional, for auto-save)
         *
         * @param {Object} data Data to save
         * @return {jqXHR}
         */
        savePreferencesAjax: function(data) {
            var self = this;

            return $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: $.extend({
                    action: 'chp_save_preferences',
                    nonce: this.config.nonce
                }, data),
                beforeSend: function() {
                    self.elements.saveButton.prop('disabled', true);
                }
            })
            .done(function(response) {
                if (response.success) {
                    self.state.isDirty = false;
                    self.showNotification(response.data.message, 'success');
                } else {
                    self.showNotification(response.data.message || 'Error al guardar', 'error');
                }
            })
            .fail(function() {
                self.showNotification('Error de conexión', 'error');
            })
            .always(function() {
                self.elements.saveButton.prop('disabled', false);
            });
        },

        /**
         * Show notification
         *
         * @param {string} message
         * @param {string} type success|error
         */
        showNotification: function(message, type) {
            var $notification = $('<div class="chp-notification chp-notification-' + type + '">')
                .text(message);

            $('body').append($notification);

            setTimeout(function() {
                $notification.addClass('show');
            }, 10);

            setTimeout(function() {
                $notification.removeClass('show');
                setTimeout(function() {
                    $notification.remove();
                }, 300);
            }, 3000);
        }
    };

    // Initialize on DOM ready
    $(function() {
        if ($('.chp-preferences').length) {
            ChpPreferences.init();
        }
    });

    // Expose for external use
    window.ChpPreferences = ChpPreferences;

})(jQuery);

/**
 * Additional CSS (injected dynamically)
 */
(function() {
    var style = document.createElement('style');
    style.textContent = `
        /* Error states */
        .chp-field-error,
        .chp-input-error input {
            border-color: #dc3545 !important;
        }

        .chp-form-errors {
            margin-bottom: 1.5em;
        }

        /* Pulse animation for store icon */
        @keyframes chp-pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .chp-store-icon.pulse {
            animation: chp-pulse 0.3s ease;
        }

        /* Notification */
        .chp-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 24px;
            border-radius: 8px;
            color: #fff;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s ease;
        }

        .chp-notification.show {
            opacity: 1;
            transform: translateY(0);
        }

        .chp-notification-success {
            background: #28a745;
        }

        .chp-notification-error {
            background: #dc3545;
        }
    `;
    document.head.appendChild(style);
})();
