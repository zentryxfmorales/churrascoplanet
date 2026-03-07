/**
 * Order Tracking JavaScript
 *
 * Maneja el polling y actualización en tiempo real del estado de pedidos.
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Order Tracking Module
     */
    var ChpOrderTracking = {
        /**
         * Configuration
         */
        config: {
            orderId: 0,
            pollInterval: 30000, // 30 seconds
            restUrl: '',
            nonce: '',
            strings: {}
        },

        /**
         * State
         */
        state: {
            isPolling: false,
            pollTimer: null,
            currentStatus: ''
        },

        /**
         * DOM Elements
         */
        elements: {
            container: null,
            refreshBtn: null,
            statusLabel: null,
            timeline: null,
            etaBanner: null,
            courierInfo: null,
            lastUpdate: null
        },

        /**
         * Initialize
         */
        init: function() {
            // Load config from localized data
            if (typeof chpTracking !== 'undefined') {
                this.config = $.extend(this.config, chpTracking);
            }

            // Verify we have required data
            if (!this.config.orderId || !this.config.restUrl) {
                console.warn('ChpOrderTracking: Missing required configuration');
                return;
            }

            // Cache DOM elements
            this.cacheElements();

            // Bind events
            this.bindEvents();

            // Start polling
            this.startPolling();

            console.log('ChpOrderTracking: Initialized for order #' + this.config.orderId);
        },

        /**
         * Cache DOM elements
         */
        cacheElements: function() {
            this.elements.container = $('.chp-order-tracking');
            this.elements.refreshBtn = $('#chp-refresh-tracking');
            this.elements.statusLabel = $('.chp-current-status .chp-status-label');
            this.elements.timeline = $('.chp-timeline');
            this.elements.etaBanner = $('.chp-eta-banner');
            this.elements.courierInfo = $('.chp-courier-info');
            this.elements.lastUpdate = $('.chp-last-update small');
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;

            // Refresh button click
            this.elements.refreshBtn.on('click', function(e) {
                e.preventDefault();
                self.refreshTracking();
            });

            // Pause polling when page is hidden
            $(document).on('visibilitychange', function() {
                if (document.hidden) {
                    self.pausePolling();
                } else {
                    self.resumePolling();
                }
            });
        },

        /**
         * Start automatic polling
         */
        startPolling: function() {
            if (this.state.isPolling) return;

            this.state.isPolling = true;
            this.schedulePoll();
        },

        /**
         * Schedule next poll
         */
        schedulePoll: function() {
            var self = this;

            this.state.pollTimer = setTimeout(function() {
                self.fetchTracking(false).always(function() {
                    if (self.state.isPolling) {
                        self.schedulePoll();
                    }
                });
            }, this.config.pollInterval);
        },

        /**
         * Pause polling
         */
        pausePolling: function() {
            if (this.state.pollTimer) {
                clearTimeout(this.state.pollTimer);
                this.state.pollTimer = null;
            }
        },

        /**
         * Resume polling
         */
        resumePolling: function() {
            if (this.state.isPolling && !this.state.pollTimer) {
                this.fetchTracking(false);
                this.schedulePoll();
            }
        },

        /**
         * Stop polling completely
         */
        stopPolling: function() {
            this.state.isPolling = false;
            this.pausePolling();
        },

        /**
         * Manual refresh
         */
        refreshTracking: function() {
            var self = this;

            // Disable button and show loading
            this.elements.refreshBtn
                .prop('disabled', true)
                .addClass('loading');

            this.fetchTracking(true).always(function() {
                self.elements.refreshBtn
                    .prop('disabled', false)
                    .removeClass('loading');
            });
        },

        /**
         * Fetch tracking data from API
         *
         * @param {boolean} forceRefresh Whether to force a refresh from Uber API
         * @return {jqXHR}
         */
        fetchTracking: function(forceRefresh) {
            var self = this;
            var endpoint = forceRefresh ? 'tracking/refresh' : 'tracking';
            var method = forceRefresh ? 'POST' : 'GET';

            return $.ajax({
                url: this.config.restUrl + 'order/' + this.config.orderId + '/' + endpoint,
                method: method,
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', self.config.nonce);
                }
            })
            .done(function(response) {
                self.updateUI(response);
            })
            .fail(function(xhr) {
                self.handleError(xhr);
            });
        },

        /**
         * Update UI with tracking data
         *
         * @param {Object} data Tracking data
         */
        updateUI: function(data) {
            // Check if status changed
            var statusChanged = this.state.currentStatus && this.state.currentStatus !== data.status;
            this.state.currentStatus = data.status;

            // Update status badge
            this.updateStatusBadge(data.status_info);

            // Update timeline
            this.updateTimeline(data.timeline);

            // Update ETA
            this.updateETA(data.eta_minutes);

            // Update courier info
            this.updateCourierInfo(data.courier);

            // Update last update time
            this.updateLastUpdate();

            // If order is delivered or canceled, stop polling
            if (data.status === 'delivered' || data.status === 'canceled') {
                this.stopPolling();
            }

            // Notify if status changed
            if (statusChanged) {
                this.notifyStatusChange(data.status_info);
            }
        },

        /**
         * Update status badge
         *
         * @param {Object} statusInfo Status information
         */
        updateStatusBadge: function(statusInfo) {
            var $badge = $('.chp-current-status');

            $badge
                .css('background-color', statusInfo.color)
                .find('i')
                    .attr('class', 'fas ' + statusInfo.icon)
                .end()
                .find('.chp-status-label')
                    .text(statusInfo.label);
        },

        /**
         * Update timeline
         *
         * @param {Array} timeline Timeline steps
         */
        updateTimeline: function(timeline) {
            var $steps = this.elements.timeline.find('.chp-timeline-step');

            $.each(timeline, function(index, step) {
                var $step = $steps.eq(index);

                $step
                    .toggleClass('completed', step.completed)
                    .toggleClass('active', step.active);

                if (step.completed || step.active) {
                    $step.find('.chp-timeline-marker')
                        .css('background-color', step.color);
                }
            });
        },

        /**
         * Update ETA banner
         *
         * @param {number|null} etaMinutes ETA in minutes
         */
        updateETA: function(etaMinutes) {
            var $banner = this.elements.etaBanner;

            if (etaMinutes && this.state.currentStatus !== 'delivered' && this.state.currentStatus !== 'canceled') {
                $banner
                    .find('span')
                    .text(this.config.strings.eta_text || 'Tiempo estimado de llegada: ~' + etaMinutes + ' minutos');
                $banner.show();
            } else {
                $banner.hide();
            }
        },

        /**
         * Update courier info section
         *
         * @param {Object} courier Courier information
         */
        updateCourierInfo: function(courier) {
            if (!courier || !courier.name) {
                this.elements.courierInfo.hide();
                return;
            }

            var $section = this.elements.courierInfo;
            $section.find('.chp-courier-name').text(courier.name);

            if (courier.phone) {
                $section.find('.chp-courier-phone')
                    .attr('href', 'tel:' + courier.phone)
                    .find('span').text(courier.phone);
            }

            $section.show();
        },

        /**
         * Update last update timestamp
         */
        updateLastUpdate: function() {
            var now = new Date();
            var timeStr = now.toLocaleTimeString();
            this.elements.lastUpdate.text(
                (this.config.strings.last_update || 'Última actualización:') + ' ' + timeStr
            );
        },

        /**
         * Handle API error
         *
         * @param {jqXHR} xhr
         */
        handleError: function(xhr) {
            var message = this.config.strings.error || 'Error al obtener estado del pedido.';

            if (xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }

            // Don't show error if rate limited (429)
            if (xhr.status === 429) {
                console.log('ChpOrderTracking: Rate limited, waiting...');
                return;
            }

            console.error('ChpOrderTracking Error:', message);

            // Show a subtle notification instead of an alert
            this.showNotification(message, 'error');
        },

        /**
         * Notify user of status change
         *
         * @param {Object} statusInfo New status info
         */
        notifyStatusChange: function(statusInfo) {
            // Browser notification if supported and permitted
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('ChurrascoPlanet', {
                    body: statusInfo.label,
                    icon: '/wp-content/themes/theme-churrascoplanet/img/favicon.png'
                });
            }

            // Visual notification
            this.showNotification(statusInfo.label, 'success');

            // Play sound if available
            this.playNotificationSound();
        },

        /**
         * Show inline notification
         *
         * @param {string} message
         * @param {string} type success|error|info
         */
        showNotification: function(message, type) {
            var $notification = $('<div class="chp-tracking-notification chp-notification-' + type + '">')
                .text(message)
                .prependTo(this.elements.container);

            setTimeout(function() {
                $notification.addClass('show');
            }, 10);

            setTimeout(function() {
                $notification.removeClass('show');
                setTimeout(function() {
                    $notification.remove();
                }, 300);
            }, 3000);
        },

        /**
         * Play notification sound
         */
        playNotificationSound: function() {
            // Simple beep using Web Audio API
            try {
                var audioContext = new (window.AudioContext || window.webkitAudioContext)();
                var oscillator = audioContext.createOscillator();
                var gainNode = audioContext.createGain();

                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);

                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
                gainNode.gain.value = 0.1;

                oscillator.start();
                setTimeout(function() {
                    oscillator.stop();
                }, 200);
            } catch (e) {
                // Audio not supported
            }
        },

        /**
         * Request notification permission
         */
        requestNotificationPermission: function() {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        }
    };

    // Initialize on DOM ready
    $(function() {
        if ($('.chp-order-tracking').length) {
            ChpOrderTracking.init();

            // Request notification permission after user interaction
            $(document).one('click', function() {
                ChpOrderTracking.requestNotificationPermission();
            });
        }
    });

    // Expose for external use
    window.ChpOrderTracking = ChpOrderTracking;

})(jQuery);

/**
 * CSS for notifications (injected dynamically)
 */
(function() {
    var style = document.createElement('style');
    style.textContent = `
        .chp-tracking-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: #fff;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
        }
        .chp-tracking-notification.show {
            opacity: 1;
            transform: translateX(0);
        }
        .chp-notification-success {
            background: #28a745;
        }
        .chp-notification-error {
            background: #dc3545;
        }
        .chp-notification-info {
            background: #17a2b8;
        }
    `;
    document.head.appendChild(style);
})();
