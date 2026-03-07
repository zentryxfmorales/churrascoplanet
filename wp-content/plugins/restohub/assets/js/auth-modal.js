/**
 * Auth Modal - Login/Registro en el Checkout
 *
 * Flujo:
 * 1. Vista inicial: Google btn, Guest btn, email input, links
 * 2. Email → check → si existe muestra password → login
 * 3. Guest btn → vista formulario (nombre + email)
 * 4. Regístrate → vista formulario (nombre + email + teléfono + contraseña)
 *
 * @package RestoHub
 */
(function($) {
	'use strict';

	var AuthModal = {

		// Elementos DOM
		$overlay: null,
		$viewInitial: null,
		$viewForm: null,
		$emailForm: null,
		$guestForm: null,
		$error: null,
		$guestError: null,

		// Estado
		emailChecked: false,
		formMode: 'guest', // 'guest' | 'register'

		/**
		 * Contexto de apertura del modal:
		 *  'checkout' — modal obligatorio (no se puede cerrar, X → carrito)
		 *  'header'   — modal opcional (X y Escape cierran sin redirigir)
		 */
		context: 'checkout',

		/**
		 * Inicializa el modal
		 */
		init: function() {
			this.$overlay     = $('#restohub-auth-overlay');
			this.$viewInitial = $('#restohub-view-initial');
			this.$viewForm    = $('#restohub-view-form');
			this.$emailForm   = $('#restohub-email-form');
			this.$guestForm   = $('#restohub-guest-form');
			this.$error       = $('#restohub-auth-error');
			this.$guestError  = $('#restohub-guest-error');

			if (!this.$overlay.length) {
				return;
			}

			this.bindEvents();

			// Solo abrir automáticamente en el checkout (flujo obligatorio).
			// En el resto de páginas, el modal se abre solo al disparar restohub:openAuth.
			if (restoHubAuth.isCheckout) {
				this.context = 'checkout';
				this.showModal();
			}

			// Inicializar Google Sign-In si está configurado
			if (restoHubAuth.googleClientId) {
				this.initGoogleSignIn();
			}
		},

		/**
		 * Vincula eventos
		 */
		bindEvents: function() {
			var self = this;

			// Cerrar modal
			// context='checkout': redirige al carrito (el usuario no puede saltarse el login)
			// context='header':   cierra el modal sin redirigir (el usuario quiere navegar)
			$('#restohub-auth-close').on('click', function() {
				if (self.context === 'checkout') {
					window.location.href = restoHubAuth.cartUrl;
				} else {
					self.hideModal();
				}
			});

			// Botón invitado → vista formulario
			$('#restohub-guest-btn').on('click', function() {
				self.showFormView('guest');
			});

			// Link "Regístrate" → vista formulario
			$('#restohub-show-register').on('click', function(e) {
				e.preventDefault();
				self.showFormView('register');
			});

			// Botón volver → vista inicial
			$('#restohub-form-back').on('click', function() {
				self.showInitialView();
			});

			// Submit del formulario de email (login)
			this.$emailForm.on('submit', function(e) {
				e.preventDefault();
				if ($('#restohub-password-section').is(':visible')) {
					self.handleLoginSubmit();
				} else {
					self.handleEmailCheck();
				}
			});

			// Submit del formulario de invitado/registro — enrutado por formMode
			this.$guestForm.on('submit', function(e) {
				e.preventDefault();
				if (self.formMode === 'register') {
					self.handleRegisterSubmit();
				} else {
					self.handleGuestSubmit();
				}
			});

			// Cuando el usuario cambia el email, ocultar la sección de password
			$('#restohub-email-input').on('input', function() {
				if (self.emailChecked) {
					self.emailChecked = false;
					$('#restohub-password-section').slideUp(200);
					self.hideError(self.$error);
				}
			});

			// Toggle mostrar/ocultar contraseña
			$(document).on('click', '#restohub-pw-toggle', function() {
				var $input = $('#restohub-register-password');
				var isPassword = $input.attr('type') === 'password';
				$input.attr('type', isPassword ? 'text' : 'password');
				$(this).find('.restohub-pw-icon-show').toggle(!isPassword);
				$(this).find('.restohub-pw-icon-hide').toggle(isPassword);
			});

			// Calcular fuerza de contraseña al escribir
			$(document).on('input', '#restohub-register-password', function() {
				if (self.formMode === 'register') {
					self.updateStrengthUI(self.calcPasswordStrength($(this).val()));
				}
			});

			// Click en el fondo del overlay
			// context='checkout': shake (el usuario no puede saltar el login)
			// context='header':   cierra el modal
			this.$overlay.on('click', function(e) {
				if ($(e.target).is('.restohub-auth-overlay')) {
					if (self.context === 'checkout') {
						self.$overlay.find('.restohub-auth-modal').addClass('restohub-auth-shake');
						setTimeout(function() {
							self.$overlay.find('.restohub-auth-modal').removeClass('restohub-auth-shake');
						}, 500);
					} else {
						self.hideModal();
					}
				}
			});

			// Tecla Escape
			// context='checkout': bloqueada (flujo obligatorio)
			// context='header':   cierra el modal
			$(document).on('keydown', function(e) {
				if (e.key === 'Escape' && self.$overlay.hasClass('restohub-auth-visible')) {
					if (self.context === 'checkout') {
						e.preventDefault();
					} else {
						self.hideModal();
					}
				}
			});
		},

		/**
		 * Recarga la página actual tras login exitoso.
		 * WooCommerce fusiona el carrito de invitado automáticamente en la siguiente carga.
		 */
		redirectAfterLogin: function() {
			window.location.reload();
		},

		/**
		 * Muestra el modal
		 */
		showModal: function() {
			this.$overlay.addClass('restohub-auth-visible');
			$('body').addClass('restohub-auth-modal-open');
		},

		/**
		 * Oculta el modal
		 */
		hideModal: function() {
			this.$overlay.removeClass('restohub-auth-visible');
			$('body').removeClass('restohub-auth-modal-open');
		},

		/**
		 * Muestra la vista de formulario (invitado o registro)
		 *
		 * @param {string} mode - 'guest' o 'register'
		 */
		showFormView: function(mode) {
			var isRegister = (mode === 'register');
			var title  = isRegister ? restoHubAuth.strings.registerTitle : restoHubAuth.strings.guestTitle;
			var btnTxt = isRegister ? restoHubAuth.strings.registerBtn : restoHubAuth.strings.continueBtn;

			this.formMode = mode;

			// Mostrar u ocultar campos exclusivos del registro
			$('.restohub-register-field').toggle(isRegister);

			// Si modo registro, restablecer la barra de fuerza
			if (isRegister) {
				this.updateStrengthUI(0);
			}

			// Si hay un email en la vista inicial, pre-llenarlo
			var currentEmail = $.trim($('#restohub-email-input').val());
			if (currentEmail) {
				$('#restohub-guest-email').val(currentEmail);
			}

			$('#restohub-form-title').text(title);
			$('#restohub-guest-submit .restohub-auth-btn-text').text(btnTxt);

			this.$viewInitial.hide();
			this.$viewForm.show();

			setTimeout(function() {
				$('#restohub-guest-name').trigger('focus');
			}, 200);
		},

		/**
		 * Vuelve a la vista inicial
		 */
		showInitialView: function() {
			this.formMode = 'guest';
			$('.restohub-register-field').hide();
			$('#restohub-register-password').attr('type', 'password');
			$('#restohub-pw-toggle .restohub-pw-icon-show').show();
			$('#restohub-pw-toggle .restohub-pw-icon-hide').hide();
			this.updateStrengthUI(0);
			this.$viewForm.hide();
			this.$viewInitial.show();
			this.hideError(this.$guestError);
			this.$guestForm[0].reset();
		},

		/**
		 * Verifica si un email ya existe (flujo email-first)
		 */
		handleEmailCheck: function() {
			var self  = this;
			var email = $.trim($('#restohub-email-input').val());

			this.hideError(this.$error);

			if (!email) {
				this.showError(this.$error, restoHubAuth.strings.emailRequired);
				$('#restohub-email-input').trigger('focus');
				return;
			}

			if (!this.isValidEmail(email)) {
				this.showError(this.$error, restoHubAuth.strings.invalidEmail);
				$('#restohub-email-input').trigger('focus');
				return;
			}

			$.ajax({
				url: restoHubAuth.ajaxUrl,
				type: 'POST',
				data: {
					action: 'restohub_check_email',
					nonce:  restoHubAuth.nonce,
					email:  email
				},
				success: function(res) {
					self.emailChecked = true;

					if (res.success && res.data.exists) {
						// Email existe → mostrar campo de contraseña
						$('#restohub-password-section').slideDown(300);
						setTimeout(function() {
							$('#restohub-password-input').trigger('focus');
						}, 300);
					} else {
						// Email no existe → sugerir registro
						self.showError(self.$error, restoHubAuth.strings.emailNotFound);
					}
				},
				error: function() {
					self.showError(self.$error, restoHubAuth.strings.networkError);
				}
			});
		},

		/**
		 * Login tradicional (email + password)
		 */
		handleLoginSubmit: function() {
			var self     = this;
			var email    = $.trim($('#restohub-email-input').val());
			var password = $('#restohub-password-input').val();

			this.hideError(this.$error);

			if (!password) {
				this.showError(this.$error, restoHubAuth.strings.passwordRequired);
				$('#restohub-password-input').trigger('focus');
				return;
			}

			this.setLoading($('#restohub-login-submit'), true);

			$.ajax({
				url: restoHubAuth.ajaxUrl,
				type: 'POST',
				data: {
					action:   'restohub_traditional_login',
					nonce:    restoHubAuth.nonce,
					email:    email,
					password: password
				},
				success: function(res) {
					if (res.success) {
						self.redirectAfterLogin();
					} else {
						self.showError(self.$error, res.data.message || restoHubAuth.strings.networkError);
						self.setLoading($('#restohub-login-submit'), false);
					}
				},
				error: function() {
					self.showError(self.$error, restoHubAuth.strings.networkError);
					self.setLoading($('#restohub-login-submit'), false);
				}
			});
		},

		/**
		 * Submit del formulario de invitado (Nombre + Email)
		 */
		handleGuestSubmit: function() {
			var self  = this;
			var name  = $.trim($('#restohub-guest-name').val());
			var email = $.trim($('#restohub-guest-email').val());

			this.hideError(this.$guestError);

			if (!name) {
				this.showError(this.$guestError, restoHubAuth.strings.nameRequired);
				$('#restohub-guest-name').trigger('focus');
				return;
			}

			if (!email) {
				this.showError(this.$guestError, restoHubAuth.strings.emailRequired);
				$('#restohub-guest-email').trigger('focus');
				return;
			}

			if (!this.isValidEmail(email)) {
				this.showError(this.$guestError, restoHubAuth.strings.invalidEmail);
				$('#restohub-guest-email').trigger('focus');
				return;
			}

			this.setLoading($('#restohub-guest-submit'), true);

			// Verificar primero si el email ya existe
			$.ajax({
				url: restoHubAuth.ajaxUrl,
				type: 'POST',
				data: {
					action: 'restohub_check_email',
					nonce:  restoHubAuth.nonce,
					email:  email
				},
				success: function(res) {
					if (res.success && res.data.exists) {
						// Email ya existe → volver a vista inicial con email pre-llenado
						self.showError(self.$guestError, restoHubAuth.strings.emailExists);
						self.setLoading($('#restohub-guest-submit'), false);

						setTimeout(function() {
							self.showInitialView();
							$('#restohub-email-input').val(email);
							self.handleEmailCheck();
						}, 1500);
					} else {
						// Email nuevo → registrar como invitado
						self.doGuestRegister(name, email);
					}
				},
				error: function() {
					// Si falla la verificación, intentar registrar directamente
					self.doGuestRegister(name, email);
				}
			});
		},

		/**
		 * Submit del formulario de registro completo (Nombre + Email + Teléfono + Contraseña)
		 */
		handleRegisterSubmit: function() {
			var self        = this;
			var name        = $.trim($('#restohub-guest-name').val());
			var email       = $.trim($('#restohub-guest-email').val());
			var phoneCode   = $('#restohub-phone-country').val();
			var phoneNumber = $.trim($('#restohub-phone-number').val());
			var password    = $('#restohub-register-password').val();

			this.hideError(this.$guestError);

			if (!name) {
				this.showError(this.$guestError, restoHubAuth.strings.nameRequired);
				$('#restohub-guest-name').trigger('focus');
				return;
			}

			if (!email || !this.isValidEmail(email)) {
				this.showError(this.$guestError, restoHubAuth.strings.invalidEmail);
				$('#restohub-guest-email').trigger('focus');
				return;
			}

			if (!phoneNumber || phoneNumber.replace(/[^0-9]/g, '').length < 7) {
				this.showError(this.$guestError, restoHubAuth.strings.phoneInvalid);
				$('#restohub-phone-number').trigger('focus');
				return;
			}

			if (!password || password.length < 8) {
				this.showError(this.$guestError, restoHubAuth.strings.passwordShort);
				$('#restohub-register-password').trigger('focus');
				return;
			}

			this.setLoading($('#restohub-guest-submit'), true);

			$.ajax({
				url: restoHubAuth.ajaxUrl,
				type: 'POST',
				data: {
					action:       'restohub_full_register',
					nonce:        restoHubAuth.nonce,
					name:         name,
					email:        email,
					phone_code:   phoneCode,
					phone_number: phoneNumber,
					password:     password
				},
				success: function(res) {
					if (res.success) {
						self.redirectAfterLogin();
					} else {
						self.showError(self.$guestError, res.data.message || restoHubAuth.strings.networkError);

						// Si el email ya existe, redirigir a login
						if (res.data && res.data.email_exists) {
							setTimeout(function() {
								self.showInitialView();
								$('#restohub-email-input').val(email);
								self.handleEmailCheck();
							}, 1500);
						}

						self.setLoading($('#restohub-guest-submit'), false);
					}
				},
				error: function() {
					self.showError(self.$guestError, restoHubAuth.strings.networkError);
					self.setLoading($('#restohub-guest-submit'), false);
				}
			});
		},

		/**
		 * Ejecuta el registro como invitado
		 */
		doGuestRegister: function(name, email) {
			var self = this;

			$.ajax({
				url: restoHubAuth.ajaxUrl,
				type: 'POST',
				data: {
					action: 'restohub_guest_login',
					nonce:  restoHubAuth.nonce,
					name:   name,
					email:  email
				},
				success: function(res) {
					if (res.success) {
						self.redirectAfterLogin();
					} else {
						self.showError(self.$guestError, res.data.message || restoHubAuth.strings.networkError);

						// Si el email ya existe, redirigir a login
						if (res.data.email_exists) {
							setTimeout(function() {
								self.showInitialView();
								$('#restohub-email-input').val(email);
								self.handleEmailCheck();
							}, 1500);
						}

						self.setLoading($('#restohub-guest-submit'), false);
					}
				},
				error: function() {
					self.showError(self.$guestError, restoHubAuth.strings.networkError);
					self.setLoading($('#restohub-guest-submit'), false);
				}
			});
		},

		/**
		 * Calcula la fuerza de la contraseña
		 *
		 * @param  {string} pw Contraseña a evaluar.
		 * @return {number}    Score del 0 (vacía) al 5 (muy fuerte).
		 */
		calcPasswordStrength: function(pw) {
			if (!pw) return 0;
			if (pw.length < 8) return 1;

			var types = 0;
			if (/[a-z]/.test(pw)) types++;
			if (/[A-Z]/.test(pw)) types++;
			if (/[0-9]/.test(pw)) types++;
			if (/[^a-zA-Z0-9]/.test(pw)) types++;

			if (types === 4 && pw.length >= 12) return 5;
			if (types >= 3) return 4;
			if (types >= 2) return 3;
			return 2;
		},

		/**
		 * Actualiza la barra y etiqueta de fuerza de contraseña
		 *
		 * @param {number} score Score de 0 a 5.
		 */
		updateStrengthUI: function(score) {
			var $bar   = $('#restohub-pw-strength-bar');
			var $label = $('#restohub-pw-strength-label');
			var widths = ['0%', '20%', '40%', '60%', '80%', '100%'];
			var colors = ['', '#ef5350', '#ff9800', '#ffc107', '#66bb6a', '#42a5f5'];
			var labels = restoHubAuth.strings.strengthLabels || [];

			$bar.css({
				width: widths[score] || '0%',
				background: colors[score] || 'transparent'
			});

			$label.text(score > 0 ? (labels[score - 1] || '') : '').css(
				'color', colors[score] || '#999'
			);
		},

		/**
		 * Inicializa Google Sign-In
		 */
		initGoogleSignIn: function() {
			var self = this;

			// Esperar a que Google Identity Services esté cargado
			var checkGoogle = setInterval(function() {
				if (typeof google !== 'undefined' && google.accounts && google.accounts.id) {
					clearInterval(checkGoogle);

					google.accounts.id.initialize({
						client_id: restoHubAuth.googleClientId,
						callback: function(response) {
							self.handleGoogleCallback(response);
						},
						auto_select: false,
						cancel_on_tap_outside: false
					});

					var container = document.getElementById('restohub-google-signin-btn');
					if (container) {
						var width = container.parentElement.offsetWidth || 360;

						google.accounts.id.renderButton(container, {
							theme: 'outline',
							size: 'large',
							width: width,
							text: 'signin_with',
							shape: 'pill',
							locale: 'es'
						});
					}
				}
			}, 200);

			// Timeout después de 10 segundos
			setTimeout(function() {
				clearInterval(checkGoogle);
			}, 10000);
		},

		/**
		 * Callback de Google Sign-In
		 */
		handleGoogleCallback: function(response) {
			var self = this;

			if (!response.credential) {
				return;
			}

			$.ajax({
				url: restoHubAuth.ajaxUrl,
				type: 'POST',
				data: {
					action:     'restohub_google_auth',
					nonce:      restoHubAuth.nonce,
					credential: response.credential
				},
				success: function(res) {
					if (res.success) {
						self.redirectAfterLogin();
					} else {
						self.showError(self.$error, res.data.message || restoHubAuth.strings.networkError);
					}
				},
				error: function() {
					self.showError(self.$error, restoHubAuth.strings.networkError);
				}
			});
		},

		/**
		 * Muestra un error
		 */
		showError: function($el, message) {
			$el.text(message).slideDown(200);
		},

		/**
		 * Oculta un error
		 */
		hideError: function($el) {
			$el.slideUp(150).text('');
		},

		/**
		 * Activa/desactiva estado de loading en un botón
		 */
		setLoading: function($btn, loading) {
			if (loading) {
				$btn.prop('disabled', true);
				$btn.find('.restohub-auth-btn-text').css('visibility', 'hidden');
				$btn.find('.restohub-auth-spinner').show();
			} else {
				$btn.prop('disabled', false);
				$btn.find('.restohub-auth-btn-text').css('visibility', 'visible');
				$btn.find('.restohub-auth-spinner').hide();
			}
		},

		/**
		 * Valida formato de email
		 */
		isValidEmail: function(email) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
		}
	};

	// Inicializar cuando el DOM esté listo
	$(document).ready(function() {
		AuthModal.init();
	});

	// Permite abrir el modal desde cualquier parte del sitio
	// (ej: botón "Ingresar" en el header del tema).
	// Se fuerza contexto 'header': el modal es opcional y se puede cerrar libremente.
	$(document).on('restohub:openAuth', function() {
		AuthModal.context = 'header';
		AuthModal.showModal();
	});

})(jQuery);
