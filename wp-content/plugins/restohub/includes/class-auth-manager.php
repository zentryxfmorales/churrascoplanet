<?php
/**
 * Auth Manager - Modal de login/registro en el checkout
 *
 * Maneja autenticación por Google, registro como invitado
 * y login tradicional para usuarios no logueados en el checkout.
 *
 * @package RestoHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestoHub_Auth_Manager {

	/**
	 * Constructor - registra hooks
	 */
	public function __construct() {
		// Ocultar el prompt de login por defecto de WooCommerce
		add_filter( 'woocommerce_checkout_show_login_prompt', '__return_false' );

		// Renderizar modal antes del formulario de checkout
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_auth_modal' ), 5 );

		// Renderizar modal en el footer del resto de páginas (para botón "Ingresar" del header)
		add_action( 'wp_footer', array( $this, 'render_modal_in_footer' ) );

		// Encolar assets
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers (solo para usuarios no logueados)
		add_action( 'wp_ajax_nopriv_restohub_check_email', array( $this, 'ajax_check_email' ) );
		add_action( 'wp_ajax_nopriv_restohub_guest_login', array( $this, 'ajax_guest_register' ) );
		add_action( 'wp_ajax_nopriv_restohub_full_register', array( $this, 'ajax_full_register' ) );
		add_action( 'wp_ajax_nopriv_restohub_google_auth', array( $this, 'ajax_google_login' ) );
		add_action( 'wp_ajax_nopriv_restohub_traditional_login', array( $this, 'ajax_traditional_login' ) );

		// Source tagging: captura registros nativos de WooCommerce (My Account, checkout)
		// que no pasen por nuestros AJAX. Se ejecuta en prioridad 20 para que WooCommerce
		// ya haya asignado el rol 'customer' antes de que evaluemos.
		add_action( 'user_register', array( $this, 'tag_standard_registration' ), 20 );

		// Columna "Origen" en la lista de usuarios del panel de admin
		if ( is_admin() ) {
			add_filter( 'manage_users_columns', array( $this, 'add_source_column' ) );
			add_filter( 'manage_users_custom_column', array( $this, 'render_source_column' ), 10, 3 );
		}
	}

	/**
	 * Renderiza el modal de autenticación
	 */
	public function render_auth_modal(): void {
		// Solo mostrar si el usuario NO está logueado
		if ( is_user_logged_in() ) {
			return;
		}

		$settings         = get_option( 'restohub_settings', array() );
		$google_client_id = $settings['google_client_id'] ?? '';
		$has_google       = ! empty( $google_client_id );
		$lost_password_url = wp_lostpassword_url( wc_get_checkout_url() );

		?>
		<div id="restohub-auth-overlay" class="restohub-auth-overlay">
			<div class="restohub-auth-modal">
				<!-- Barra de acento naranja -->
				<div class="restohub-auth-accent-bar"></div>

				<!-- Header -->
				<div class="restohub-auth-header">
					<p class="restohub-auth-greeting"><?php esc_html_e( 'Hola! ¿Cómo quieres continuar?', 'restohub' ); ?></p>
					<button type="button" class="restohub-auth-close" id="restohub-auth-close" aria-label="<?php esc_attr_e( 'Cerrar', 'restohub' ); ?>">&times;</button>
				</div>

				<div class="restohub-auth-body">
					<!-- ========== VISTA INICIAL ========== -->
					<div id="restohub-view-initial" class="restohub-auth-view">
						<h2 class="restohub-auth-title"><?php esc_html_e( 'Inicio Sesión', 'restohub' ); ?></h2>

						<?php if ( $has_google ) : ?>
						<!-- Google Sign-In -->
						<div class="restohub-auth-google-section">
							<div id="restohub-google-signin-btn" class="restohub-google-btn-container"></div>
						</div>
						<?php endif; ?>

						<!-- Botón Invitado -->
						<button type="button" class="restohub-auth-btn restohub-auth-btn-guest" id="restohub-guest-btn">
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
							<?php esc_html_e( 'INGRESAR COMO INVITADO', 'restohub' ); ?>
						</button>

						<!-- Separador -->
						<div class="restohub-auth-separator">
							<span><?php esc_html_e( 'o ingresa con tu correo', 'restohub' ); ?></span>
						</div>

						<!-- Formulario de email -->
						<form id="restohub-email-form" class="restohub-auth-form" novalidate>
							<div class="restohub-auth-field-material">
								<input type="email"
								       id="restohub-email-input"
								       name="email"
								       required
								       autocomplete="email"
								       placeholder=" ">
								<label for="restohub-email-input"><?php esc_html_e( 'Ingresa tu Correo', 'restohub' ); ?></label>
							</div>

							<!-- Sección de contraseña (aparece tras verificar email) -->
							<div id="restohub-password-section" style="display:none;">
								<div class="restohub-auth-field-material">
									<input type="password"
									       id="restohub-password-input"
									       name="password"
									       required
									       autocomplete="current-password"
									       placeholder=" ">
									<label for="restohub-password-input"><?php esc_html_e( 'Contraseña', 'restohub' ); ?></label>
								</div>
								<button type="submit" class="restohub-auth-btn restohub-auth-btn-primary" id="restohub-login-submit">
									<span class="restohub-auth-btn-text"><?php esc_html_e( 'Iniciar Sesión', 'restohub' ); ?></span>
									<span class="restohub-auth-spinner" style="display:none;"></span>
								</button>
							</div>

							<div id="restohub-auth-error" class="restohub-auth-error" style="display:none;"></div>
						</form>

						<!-- Links -->
						<div class="restohub-auth-links">
							<a href="<?php echo esc_url( $lost_password_url ); ?>" class="restohub-auth-link"><?php esc_html_e( '¿Olvidaste tu contraseña?', 'restohub' ); ?></a>
							<button type="button" class="restohub-auth-link restohub-auth-link-btn" id="restohub-show-register"><?php echo wp_kses_post( __( '¿No tienes cuenta? <strong>Regístrate</strong>', 'restohub' ) ); ?></button>
						</div>
					</div>

					<!-- ========== VISTA FORMULARIO (Invitado / Registro) ========== -->
					<div id="restohub-view-form" class="restohub-auth-view" style="display:none;">
						<button type="button" class="restohub-auth-back" id="restohub-form-back">
							<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
							<?php esc_html_e( 'Volver', 'restohub' ); ?>
						</button>
						<h2 class="restohub-auth-title" id="restohub-form-title"><?php esc_html_e( 'Continuar como Invitado', 'restohub' ); ?></h2>

						<form id="restohub-guest-form" class="restohub-auth-form" novalidate>
							<div class="restohub-auth-field-material">
								<input type="text"
								       id="restohub-guest-name"
								       name="guest_name"
								       required
								       autocomplete="name"
								       placeholder=" ">
								<label for="restohub-guest-name"><?php esc_html_e( 'Nombre completo', 'restohub' ); ?></label>
							</div>
							<div class="restohub-auth-field-material">
								<input type="email"
								       id="restohub-guest-email"
								       name="guest_email"
								       required
								       autocomplete="email"
								       placeholder=" ">
								<label for="restohub-guest-email"><?php esc_html_e( 'Correo electrónico', 'restohub' ); ?></label>
							</div>


							<!-- Teléfono — solo visible en modo Registro -->
							<div class="restohub-auth-field-phone restohub-register-field" style="display:none;">
								<span class="restohub-field-label"><?php esc_html_e( 'Teléfono', 'restohub' ); ?></span>
								<div class="restohub-phone-row">
									<select id="restohub-phone-country" class="restohub-phone-country" autocomplete="tel-country-code">
										<option value="56">&#127464;&#127473; +56</option>
										<option value="54">&#127462;&#127479; +54</option>
										<option value="591">&#127463;&#127476; +591</option>
										<option value="55">&#127463;&#127479; +55</option>
										<option value="57">&#127464;&#127476; +57</option>
										<option value="593">&#127466;&#127464; +593</option>
										<option value="595">&#127477;&#127486; +595</option>
										<option value="51">&#127477;&#127466; +51</option>
										<option value="598">&#127482;&#127486; +598</option>
										<option value="58">&#127483;&#127466; +58</option>
										<option value="34">&#127466;&#127480; +34</option>
										<option value="1">&#127482;&#127480; +1</option>
									</select>
									<input type="tel"
									       id="restohub-phone-number"
									       class="restohub-phone-number"
									       placeholder="9 1234 5678"
									       autocomplete="tel-national">
								</div>
							</div>

							<!-- Contraseña — solo visible en modo Registro -->
							<div class="restohub-auth-field-material restohub-pw-field restohub-register-field" style="display:none;">
								<input type="password"
								       id="restohub-register-password"
								       name="register_password"
								       autocomplete="new-password"
								       placeholder=" ">
								<label for="restohub-register-password"><?php esc_html_e( 'Contraseña', 'restohub' ); ?></label>
								<button type="button" class="restohub-pw-toggle" id="restohub-pw-toggle" aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'restohub' ); ?>">
									<svg class="restohub-pw-icon-show" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
									<svg class="restohub-pw-icon-hide" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="display:none;"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>
								</button>
							</div>

							<!-- Barra de fuerza — solo visible en modo Registro -->
							<div class="restohub-pw-strength restohub-register-field" id="restohub-pw-strength-wrap" style="display:none;">
								<div class="restohub-pw-strength-track">
									<div class="restohub-pw-strength-bar" id="restohub-pw-strength-bar"></div>
								</div>
								<span class="restohub-pw-strength-label" id="restohub-pw-strength-label"></span>
							</div>
							<div id="restohub-guest-error" class="restohub-auth-error" style="display:none;"></div>
							<button type="submit" class="restohub-auth-btn restohub-auth-btn-primary" id="restohub-guest-submit">
								<span class="restohub-auth-btn-text"><?php esc_html_e( 'Continuar', 'restohub' ); ?></span>
								<span class="restohub-auth-spinner" style="display:none;"></span>
							</button>
						</form>
					</div>
				</div>

				<!-- Footer -->
				<div class="restohub-auth-footer">
					<span><?php esc_html_e( 'Powered by', 'restohub' ); ?></span>
					<strong>Zentryx</strong>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza el modal en el footer para páginas fuera del checkout.
	 * Permite abrir el modal desde el botón "Ingresar" del header.
	 */
	public function render_modal_in_footer(): void {
		// El checkout ya renderiza el modal antes del formulario.
		if ( is_checkout() || is_user_logged_in() ) {
			return;
		}
		$this->render_auth_modal();
	}

	/**
	 * Encola assets del modal de auth
	 */
	public function enqueue_assets(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		// CSS del modal
		wp_enqueue_style(
			'restohub-auth-modal',
			RESTOHUB_PLUGIN_URL . 'assets/css/auth-modal.css',
			array(),
			RESTOHUB_VERSION
		);

		// JS del modal
		wp_enqueue_script(
			'restohub-auth-modal',
			RESTOHUB_PLUGIN_URL . 'assets/js/auth-modal.js',
			array( 'jquery' ),
			RESTOHUB_VERSION,
			true
		);

		// Google Identity Services (si hay client ID configurado)
		$settings         = get_option( 'restohub_settings', array() );
		$google_client_id = $settings['google_client_id'] ?? '';

		if ( ! empty( $google_client_id ) ) {
			wp_enqueue_script(
				'google-identity-services',
				'https://accounts.google.com/gsi/client',
				array(),
				null,
				true
			);
		}

		// Datos para JS
		wp_localize_script( 'restohub-auth-modal', 'restoHubAuth', array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'restohub_auth_nonce' ),
			'googleClientId' => $google_client_id,
			'cartUrl'        => wc_get_cart_url(),
			'checkoutUrl'    => wc_get_checkout_url(),
			'isCheckout'     => is_checkout(),
			'strings'        => array(
				'emailRequired'    => __( 'Por favor ingresa tu correo electrónico.', 'restohub' ),
				'nameRequired'     => __( 'Por favor ingresa tu nombre.', 'restohub' ),
				'invalidEmail'     => __( 'El correo electrónico no es válido.', 'restohub' ),
				'passwordRequired' => __( 'Por favor ingresa tu contraseña.', 'restohub' ),
				'passwordShort'    => __( 'La contraseña debe tener al menos 8 caracteres.', 'restohub' ),
				'phoneRequired'    => __( 'Por favor ingresa tu teléfono.', 'restohub' ),
				'phoneInvalid'     => __( 'Por favor ingresa un teléfono válido (mínimo 7 dígitos).', 'restohub' ),
				'networkError'     => __( 'Error de conexión. Inténtalo de nuevo.', 'restohub' ),
				'emailExists'      => __( 'Este email ya tiene una cuenta. Por favor inicia sesión.', 'restohub' ),
				'emailNotFound'    => __( 'No existe una cuenta con este correo. Regístrate para continuar.', 'restohub' ),
				'loading'          => __( 'Cargando...', 'restohub' ),
				'guestTitle'       => __( 'Continuar como Invitado', 'restohub' ),
				'registerTitle'    => __( 'Crear Cuenta', 'restohub' ),
				'continueBtn'      => __( 'Continuar', 'restohub' ),
				'registerBtn'      => __( 'Registrarse', 'restohub' ),
				'strengthLabels'   => array(
					__( 'Muy débil', 'restohub' ),
					__( 'Débil', 'restohub' ),
					__( 'Regular', 'restohub' ),
					__( 'Fuerte', 'restohub' ),
					__( 'Muy fuerte', 'restohub' ),
				),
			),
		) );
	}

	/**
	 * AJAX: Verificar si un email ya existe
	 */
	public function ajax_check_email(): void {
		check_ajax_referer( 'restohub_auth_nonce', 'nonce' );

		// Rate limiting: 5 req/min por IP
		$ip  = $this->get_client_ip();
		$key = 'restohub_check_email_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= 5 ) {
			wp_send_json_error( array(
				'message' => __( 'Demasiados intentos. Espera un momento.', 'restohub' ),
			) );
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array(
				'message' => __( 'Email inválido.', 'restohub' ),
			) );
		}

		wp_send_json_success( array(
			'exists' => email_exists( $email ) !== false,
		) );
	}

	/**
	 * AJAX: Registro como invitado (crea cuenta automáticamente)
	 */
	public function ajax_guest_register(): void {
		check_ajax_referer( 'restohub_auth_nonce', 'nonce' );

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( array(
				'message' => __( 'El nombre es obligatorio.', 'restohub' ),
			) );
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array(
				'message' => __( 'Email inválido.', 'restohub' ),
			) );
		}

		// Si el email ya existe, indicar que inicie sesión
		if ( email_exists( $email ) ) {
			wp_send_json_error( array(
				'message'      => __( 'Este email ya tiene una cuenta registrada. Por favor inicia sesión con tu contraseña.', 'restohub' ),
				'email_exists' => true,
			) );
		}

		// Crear usuario
		$password = wp_generate_password( 12, true, true );
		$user_id  = wp_create_user( $email, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array(
				'message' => $user_id->get_error_message(),
			) );
		}

		// Configurar usuario
		$name_parts = explode( ' ', $name, 2 );
		$first_name = $name_parts[0];
		$last_name  = $name_parts[1] ?? '';

		wp_update_user( array(
			'ID'           => $user_id,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => $name,
			'role'         => 'customer',
		) );

		// Etiquetar origen del registro para segmentación de marketing
		update_user_meta( $user_id, '_restohub_source', 'guest' );

		// Sincronizar con chp_customers con nombre correcto y tipo 'guest'
		// (user_register disparó track_registered_customer antes de que los nombres se guardaran)
		if ( function_exists( 'chp_guest_tracker' ) ) {
			chp_guest_tracker()->upsert_customer( array(
				'email'               => $email,
				'first_name'          => $first_name,
				'last_name'           => $last_name,
				'customer_type'       => 'guest',
				'user_id'             => $user_id,
				'registration_source' => 'restohub_guest',
			) );
		}

		// Enviar email con contraseña al nuevo usuario
		wp_new_user_notification( $user_id, null, 'user' );

		// Login automático
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// Registrar evento
		if ( function_exists( 'restohub_auth_events_repository' ) ) {
			restohub_auth_events_repository()->log_event( array(
				'user_id'     => $user_id,
				'event_type'  => 'guest_register',
				'auth_method' => 'guest',
				'email'       => $email,
			) );
		}

		wp_send_json_success( array(
			'message' => __( 'Cuenta creada exitosamente.', 'restohub' ),
		) );
	}

	/**
	 * AJAX: Registro completo (Nombre + Email + Teléfono + Contraseña)
	 */
	public function ajax_full_register(): void {
		check_ajax_referer( 'restohub_auth_nonce', 'nonce' );

		// Rate limiting: 5 registros/min por IP
		$ip    = $this->get_client_ip();
		$key   = 'restohub_full_register_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= 5 ) {
			wp_send_json_error( array(
				'message' => __( 'Demasiados intentos. Espera un momento.', 'restohub' ),
			) );
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone_code   = isset( $_POST['phone_code'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_code'] ) ) : '';
		$phone_number = isset( $_POST['phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number'] ) ) : '';
		$password     = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';

		// Validaciones
		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'El nombre es obligatorio.', 'restohub' ) ) );
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Email inválido.', 'restohub' ) ) );
		}

		$phone_digits = preg_replace( '/[^0-9]/', '', $phone_number );
		if ( empty( $phone_code ) || strlen( $phone_digits ) < 7 ) {
			wp_send_json_error( array( 'message' => __( 'Por favor ingresa un teléfono válido.', 'restohub' ) ) );
		}

		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'La contraseña debe tener al menos 8 caracteres.', 'restohub' ) ) );
		}

		// Verificar que el email no exista ya
		if ( email_exists( $email ) ) {
			wp_send_json_error( array(
				'message'      => __( 'Este email ya tiene una cuenta registrada. Por favor inicia sesión.', 'restohub' ),
				'email_exists' => true,
			) );
		}

		// Crear usuario con la contraseña elegida
		$user_id = wp_create_user( $email, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		// Separar nombre y apellido
		$name_parts = explode( ' ', $name, 2 );
		$first_name = $name_parts[0];
		$last_name  = $name_parts[1] ?? '';

		wp_update_user( array(
			'ID'           => $user_id,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => $name,
			'role'         => 'customer',
		) );

		// Etiquetar origen del registro para segmentación de marketing
		update_user_meta( $user_id, '_restohub_source', 'standard' );

		// Guardar datos de billing para pre-llenar el checkout
		$full_phone   = '+' . preg_replace( '/[^0-9]/', '', $phone_code ) . $phone_digits;
		$country_map  = array(
			'56'  => 'CL', '54'  => 'AR', '591' => 'BO', '55'  => 'BR',
			'57'  => 'CO', '593' => 'EC', '595' => 'PY', '51'  => 'PE',
			'598' => 'UY', '58'  => 'VE', '34'  => 'ES', '1'   => 'US',
		);
		$country_code = $country_map[ $phone_code ] ?? '';

		update_user_meta( $user_id, 'billing_first_name', $first_name );
		update_user_meta( $user_id, 'billing_last_name', $last_name );
		update_user_meta( $user_id, 'billing_email', $email );
		update_user_meta( $user_id, 'billing_phone', $full_phone );
		if ( $country_code ) {
			update_user_meta( $user_id, 'billing_country', $country_code );
		}

		// Sincronizar con chp_customers con nombre/teléfono correctos y tipo 'registered'
		// (user_register disparó track_registered_customer antes de que los datos se guardaran)
		if ( function_exists( 'chp_guest_tracker' ) ) {
			chp_guest_tracker()->upsert_customer( array(
				'email'               => $email,
				'first_name'          => $first_name,
				'last_name'           => $last_name,
				'phone'               => $full_phone,
				'customer_type'       => 'registered',
				'user_id'             => $user_id,
				'registration_source' => 'restohub_register',
			) );
		}

		// Email de bienvenida (configurable desde Opciones Avanzadas)
		$settings = get_option( 'restohub_settings', array() );
		if ( ( $settings['send_welcome_email'] ?? 'yes' ) === 'yes' ) {
			$this->send_welcome_email( $user_id, $email, $first_name );
		}

		// Login automático
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// Registrar evento
		if ( function_exists( 'restohub_auth_events_repository' ) ) {
			restohub_auth_events_repository()->log_event( array(
				'user_id'     => $user_id,
				'event_type'  => 'full_register',
				'auth_method' => 'email',
				'email'       => $email,
			) );
		}

		wp_send_json_success( array(
			'message' => __( 'Cuenta creada exitosamente.', 'restohub' ),
		) );
	}

	/**
	 * Envía un email de bienvenida al nuevo cliente
	 *
	 * @param int    $user_id    ID del usuario creado.
	 * @param string $email      Email del usuario.
	 * @param string $first_name Primer nombre del usuario.
	 */
	private function send_welcome_email( int $user_id, string $email, string $first_name ): void {
		$blog_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '¡Bienvenido/a a %s!', 'restohub' ),
			$blog_name
		);

		$message = sprintf(
			/* translators: 1: first name, 2: site name */
			__( "Hola %1\$s,\n\nTu cuenta en %2\$s ha sido creada exitosamente.\nYa puedes completar tu pedido con todos tus datos pre-completados en el checkout.\n\n¡Gracias por registrarte!\n\nEl equipo de %2\$s", 'restohub' ),
			$first_name,
			$blog_name
		);

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', $blog_name, get_option( 'admin_email' ) ),
		);

		wp_mail( $email, $subject, $message, $headers );
	}

	/**
	 * AJAX: Login con Google Sign-In
	 */
	public function ajax_google_login(): void {
		check_ajax_referer( 'restohub_auth_nonce', 'nonce' );

		$credential = isset( $_POST['credential'] ) ? sanitize_text_field( wp_unslash( $_POST['credential'] ) ) : '';

		if ( empty( $credential ) ) {
			wp_send_json_error( array(
				'message' => __( 'Credencial de Google no proporcionada.', 'restohub' ),
			) );
		}

		// Verificar el JWT localmente usando JWKS de Google (sin petición HTTP por login).
		// Las claves públicas se cachean en WordPress transients durante 6 horas.
		$settings         = get_option( 'restohub_settings', array() );
		$google_client_id = $settings['google_client_id'] ?? '';

		if ( empty( $google_client_id ) ) {
			wp_send_json_error( array(
				'message' => __( 'Google Sign-In no está configurado.', 'restohub' ),
			) );
		}

		$payload = $this->verify_google_jwt( $credential, $google_client_id );

		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array(
				'message' => $payload->get_error_message(),
			) );
		}

		$email      = sanitize_email( $payload['email'] );
		$name       = sanitize_text_field( $payload['name'] ?? '' );
		$google_sub = sanitize_text_field( $payload['sub'] ?? '' );
		$existing   = email_exists( $email );

		if ( $existing ) {
			// Usuario existente - login
			$user_id    = $existing;
			$event_type = 'google_login';
		} else {
			// Nuevo usuario - crear cuenta
			$password = wp_generate_password( 16, true, true );
			$user_id  = wp_create_user( $email, $password, $email );

			if ( is_wp_error( $user_id ) ) {
				wp_send_json_error( array(
					'message' => $user_id->get_error_message(),
				) );
			}

			$name_parts = explode( ' ', $name, 2 );
			wp_update_user( array(
				'ID'           => $user_id,
				'first_name'   => $name_parts[0] ?? '',
				'last_name'    => $name_parts[1] ?? '',
				'display_name' => $name,
				'role'         => 'customer',
			) );

			// Etiquetar origen del registro para segmentación de marketing
			update_user_meta( $user_id, '_restohub_source', 'google' );

			// Marcar origen para clasificación correcta en chp_customers
			update_user_meta( $user_id, 'chp_registration_source', 'social_google' );
			update_user_meta( $user_id, 'chp_registration_date', current_time( 'mysql' ) );

			// Sincronizar con chp_customers con tipo 'social_google' correcto
			// (user_register disparó track_registered_customer antes de que los datos se guardaran)
			if ( function_exists( 'chp_guest_tracker' ) ) {
				chp_guest_tracker()->upsert_customer( array(
					'email'               => $email,
					'first_name'          => $name_parts[0] ?? '',
					'last_name'           => $name_parts[1] ?? '',
					'customer_type'       => 'social_google',
					'user_id'             => $user_id,
					'registration_source' => 'social_google',
				) );
			}

			$event_type = 'google_register';
		}

		// Login
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// Registrar evento
		if ( function_exists( 'restohub_auth_events_repository' ) ) {
			restohub_auth_events_repository()->log_event( array(
				'user_id'     => $user_id,
				'event_type'  => $event_type,
				'auth_method' => 'google',
				'email'       => $email,
				'metadata'    => array(
					'google_sub' => $google_sub,
					'name'       => $name,
				),
			) );
		}

		wp_send_json_success( array(
			'message' => __( 'Sesión iniciada con Google.', 'restohub' ),
		) );
	}

	/**
	 * AJAX: Login tradicional con email y contraseña
	 */
	public function ajax_traditional_login(): void {
		check_ajax_referer( 'restohub_auth_nonce', 'nonce' );

		// Rate limiting: máx 10 intentos/min por IP para prevenir fuerza bruta
		$ip      = $this->get_client_ip();
		$rl_key  = 'restohub_login_' . md5( $ip );
		$rl_count = (int) get_transient( $rl_key );

		if ( $rl_count >= 10 ) {
			wp_send_json_error( array(
				'message' => __( 'Demasiados intentos de inicio de sesión. Espera un momento e intenta de nuevo.', 'restohub' ),
			) );
		}

		set_transient( $rl_key, $rl_count + 1, MINUTE_IN_SECONDS );

		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';

		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array(
				'message' => __( 'Email inválido.', 'restohub' ),
			) );
		}

		if ( empty( $password ) ) {
			wp_send_json_error( array(
				'message' => __( 'La contraseña es obligatoria.', 'restohub' ),
			) );
		}

		$user = wp_authenticate( $email, $password );

		if ( is_wp_error( $user ) ) {
			// Registrar intento fallido
			if ( function_exists( 'restohub_auth_events_repository' ) ) {
				restohub_auth_events_repository()->log_event( array(
					'user_id'     => 0,
					'event_type'  => 'login_failed',
					'auth_method' => 'traditional',
					'email'       => $email,
				) );
			}

			wp_send_json_error( array(
				'message' => __( 'Email o contraseña incorrectos.', 'restohub' ),
			) );
		}

		// Login exitoso
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		// Registrar evento
		if ( function_exists( 'restohub_auth_events_repository' ) ) {
			restohub_auth_events_repository()->log_event( array(
				'user_id'     => $user->ID,
				'event_type'  => 'login',
				'auth_method' => 'traditional',
				'email'       => $email,
			) );
		}

		wp_send_json_success( array(
			'message' => __( 'Sesión iniciada correctamente.', 'restohub' ),
		) );
	}

	/**
	 * Obtiene la IP del cliente
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip_keys = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	// =========================================================================
	// SOURCE TAGGING — Etiquetado de origen para marketing
	// =========================================================================

	/**
	 * Tarea 2: Etiqueta registros estándar vía hook nativo `user_register`.
	 *
	 * Se ejecuta en prioridad 20 para que WooCommerce y otros plugins hayan
	 * asignado el rol 'customer' antes de que evaluemos. Actúa solo si:
	 *  a) El usuario tiene el rol 'customer' (descarta admin, shop_manager, etc.)
	 *  b) El meta `_restohub_source` está vacío (nuestros AJAX ya lo setean
	 *     explícitamente; esto es un catch-all para rutas externas como el
	 *     formulario de registro de WooCommerce en Mi Cuenta).
	 *
	 * @param int $user_id ID del usuario recién creado.
	 */
	public function tag_standard_registration( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		// Solo interesa etiquetar clientes finales, no staff
		if ( ! in_array( 'customer', (array) $user->roles, true ) ) {
			return;
		}

		// No sobreescribir si ya fue etiquetado por uno de nuestros AJAX handlers
		if ( '' !== (string) get_user_meta( $user_id, '_restohub_source', true ) ) {
			return;
		}

		update_user_meta( $user_id, '_restohub_source', 'standard' );
	}

	// =========================================================================
	// ADMIN — Columna "Origen" en la lista de usuarios
	// =========================================================================

	/**
	 * Tarea 3a: Registra la columna "Origen" en la lista de usuarios del admin.
	 *
	 * @param array $columns Columnas existentes.
	 * @return array
	 */
	public function add_source_column( array $columns ): array {
		$columns['restohub_source'] = __( 'Origen', 'restohub' );
		return $columns;
	}

	/**
	 * Tarea 3b: Renderiza el contenido de la columna "Origen" por usuario.
	 *
	 * Reglas de rol:
	 *  - Si el usuario NO tiene rol 'customer' → guión (no aplica para staff)
	 *  - Si tiene rol 'customer' → badge visual según `_restohub_source`
	 *
	 * @param string $output      Salida actual de la columna.
	 * @param string $column_name Nombre de la columna.
	 * @param int    $user_id     ID del usuario de la fila actual.
	 * @return string             HTML del badge o guión.
	 */
	public function render_source_column( string $output, string $column_name, int $user_id ): string {
		if ( 'restohub_source' !== $column_name ) {
			return $output;
		}

		$user = get_user_by( 'id', $user_id );

		// Solo mostrar badge para clientes; el staff muestra guión
		if ( ! $user instanceof WP_User || ! in_array( 'customer', (array) $user->roles, true ) ) {
			return '<span style="color:#999;font-size:13px;">—</span>';
		}

		$source = (string) get_user_meta( $user_id, '_restohub_source', true );

		$badges = array(
			'google'   => array(
				'label'  => 'Google Auth',
				'color'  => '#4285f4',
				'bg'     => 'rgba(66,133,244,0.1)',
				'border' => 'rgba(66,133,244,0.3)',
			),
			'guest'    => array(
				'label'  => 'Invitado',
				'color'  => '#f59e0b',
				'bg'     => 'rgba(245,158,11,0.1)',
				'border' => 'rgba(245,158,11,0.3)',
			),
			'standard' => array(
				'label'  => 'Estándar',
				'color'  => '#16a34a',
				'bg'     => 'rgba(22,163,74,0.1)',
				'border' => 'rgba(22,163,74,0.3)',
			),
		);

		if ( empty( $source ) || ! array_key_exists( $source, $badges ) ) {
			return '<span style="color:#999;font-size:11px;font-style:italic;">No definido</span>';
		}

		$b = $badges[ $source ];

		return sprintf(
			'<span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:0.3px;background:%s;color:%s;border:1px solid %s;white-space:nowrap;">%s</span>',
			esc_attr( $b['bg'] ),
			esc_attr( $b['color'] ),
			esc_attr( $b['border'] ),
			esc_html( $b['label'] )
		);
	}

	// =========================================================================
	// Verificación local de JWT con JWKS de Google
	// =========================================================================

	/**
	 * Verifica localmente un ID token JWT emitido por Google GIS.
	 *
	 * Descarga las claves públicas JWKS de Google (con caché de 6 h en transients)
	 * y verifica la firma RSA-SHA256 directamente, sin hacer una petición HTTP
	 * por cada login. Si el kid no está en caché, fuerza un refresco automático
	 * (Google rota sus claves periódicamente).
	 *
	 * @param string $credential    JWT recibido del callback de GIS.
	 * @param string $expected_aud  Client ID de Google registrado en la app.
	 * @return array|WP_Error       Payload decodificado y validado, o WP_Error.
	 */
	private function verify_google_jwt( string $credential, string $expected_aud ): array|WP_Error {

		// 1. Separar las tres partes: header.payload.signature
		$parts = explode( '.', $credential );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'invalid_jwt', __( 'Formato JWT inválido.', 'restohub' ) );
		}

		[ $header_b64, $payload_b64, $signature_b64 ] = $parts;

		$header  = json_decode( $this->base64url_decode( $header_b64 ), true );
		$payload = json_decode( $this->base64url_decode( $payload_b64 ), true );

		if ( ! is_array( $header ) || ! is_array( $payload ) ) {
			return new WP_Error( 'invalid_jwt', __( 'JWT no se pudo decodificar.', 'restohub' ) );
		}

		// 2. Solo aceptar RS256 (algoritmo estándar de Google para ID tokens)
		if ( 'RS256' !== ( $header['alg'] ?? '' ) ) {
			return new WP_Error( 'invalid_algorithm', __( 'Algoritmo JWT no soportado.', 'restohub' ) );
		}

		// 3. Buscar la clave pública por kid; si no está en caché, refrescar y reintentar
		$kid = $header['kid'] ?? '';
		$jwk = $this->find_google_jwk( $kid, false );

		if ( null === $jwk ) {
			$jwk = $this->find_google_jwk( $kid, true );
		}

		if ( null === $jwk ) {
			return new WP_Error( 'key_not_found', __( 'Clave pública de Google no encontrada.', 'restohub' ) );
		}

		// 4. Convertir JWK → PEM y verificar firma con OpenSSL
		$pem = $this->jwk_to_pem( $jwk );
		if ( is_wp_error( $pem ) ) {
			return $pem;
		}

		$signing_input = $header_b64 . '.' . $payload_b64;
		$signature     = $this->base64url_decode( $signature_b64 );
		$verified      = openssl_verify( $signing_input, $signature, $pem, OPENSSL_ALGO_SHA256 );

		if ( 1 !== $verified ) {
			return new WP_Error( 'invalid_signature', __( 'Firma del token de Google no válida.', 'restohub' ) );
		}

		// 5. Validar claims del payload
		$now = time();

		if ( ( $payload['exp'] ?? 0 ) < $now ) {
			return new WP_Error( 'token_expired', __( 'El token de Google ha expirado.', 'restohub' ) );
		}

		// Tolerancia de 60 s para desfases de reloj entre servidores
		if ( ( $payload['iat'] ?? 0 ) > ( $now + 60 ) ) {
			return new WP_Error( 'token_future', __( 'La fecha de emisión del token no es válida.', 'restohub' ) );
		}

		$valid_issuers = array( 'https://accounts.google.com', 'accounts.google.com' );
		if ( ! in_array( $payload['iss'] ?? '', $valid_issuers, true ) ) {
			return new WP_Error( 'invalid_issuer', __( 'El token no fue emitido por Google.', 'restohub' ) );
		}

		if ( ( $payload['aud'] ?? '' ) !== $expected_aud ) {
			return new WP_Error( 'invalid_audience', __( 'El token no es para esta aplicación.', 'restohub' ) );
		}

		// email_verified puede ser bool true o string "true" según el flujo de GIS
		$email_verified = $payload['email_verified'] ?? false;
		if ( ! $email_verified || 'false' === (string) $email_verified ) {
			return new WP_Error( 'email_not_verified', __( 'El email de Google no está verificado.', 'restohub' ) );
		}

		if ( empty( $payload['email'] ) ) {
			return new WP_Error( 'no_email', __( 'Google no proporcionó un email.', 'restohub' ) );
		}

		return $payload;
	}

	/**
	 * Busca un JWK concreto por su kid dentro del JWKS de Google.
	 * Si force_refresh es true, elimina el transient antes de buscar.
	 *
	 * @param string $kid           Key ID a buscar.
	 * @param bool   $force_refresh Si true, descarta el caché y descarga de nuevo.
	 * @return array|null           El JWK encontrado, o null.
	 */
	private function find_google_jwk( string $kid, bool $force_refresh ): ?array {
		if ( $force_refresh ) {
			delete_transient( 'restohub_google_jwks' );
		}

		$jwks = $this->get_google_jwks();
		if ( is_wp_error( $jwks ) ) {
			return null;
		}

		foreach ( $jwks['keys'] as $key ) {
			if ( ( $key['kid'] ?? '' ) === $kid ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Obtiene el JWKS de Google y lo cachea en WordPress transients.
	 *
	 * Google rota sus claves de firma cada ~6 horas. No se cachea más allá de
	 * ese tiempo para garantizar que siempre usamos claves vigentes.
	 *
	 * @return array|WP_Error  Array con la clave 'keys', o WP_Error.
	 */
	private function get_google_jwks(): array|WP_Error {
		$cached = get_transient( 'restohub_google_jwks' );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://www.googleapis.com/oauth2/v3/certs',
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'jwks_unreachable', __( 'No se pudo conectar con Google para obtener las claves públicas.', 'restohub' ) );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'jwks_error', __( 'Respuesta inválida al obtener las claves de Google.', 'restohub' ) );
		}

		$jwks = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $jwks['keys'] ) || ! is_array( $jwks['keys'] ) ) {
			return new WP_Error( 'jwks_invalid', __( 'El formato JWKS de Google no es válido.', 'restohub' ) );
		}

		// Google rota sus claves aproximadamente cada 6 horas
		set_transient( 'restohub_google_jwks', $jwks, 6 * HOUR_IN_SECONDS );

		return $jwks;
	}

	/**
	 * Convierte una clave JWK de tipo RSA a formato PEM (SubjectPublicKeyInfo).
	 *
	 * Google firma sus ID tokens con RSA-2048. Los parámetros 'n' (módulo) y
	 * 'e' (exponente) vienen en Base64URL. La construcción usa DER encoding
	 * manual (RFC 5280 / X.690), sin dependencias externas, compatible con
	 * openssl_verify().
	 *
	 * @param array $jwk  Clave en formato JWK.
	 * @return string|WP_Error  PEM de clave pública, o WP_Error.
	 */
	private function jwk_to_pem( array $jwk ): string|WP_Error {
		if ( 'RSA' !== ( $jwk['kty'] ?? '' ) ) {
			return new WP_Error( 'unsupported_key_type', __( 'Solo se soportan claves RSA.', 'restohub' ) );
		}

		if ( empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
			return new WP_Error( 'invalid_jwk', __( 'Clave JWK sin parámetros RSA requeridos (n, e).', 'restohub' ) );
		}

		$modulus  = $this->base64url_decode( $jwk['n'] );
		$exponent = $this->base64url_decode( $jwk['e'] );

		// RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
		$rsa_public_key = $this->der_sequence(
			$this->der_integer( $modulus ) . $this->der_integer( $exponent )
		);

		// AlgorithmIdentifier: OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL
		$algorithm_id = $this->der_sequence(
			"\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"
		);

		// SubjectPublicKeyInfo ::= SEQUENCE { algorithm, subjectPublicKey BIT STRING }
		$spki = $this->der_sequence(
			$algorithm_id . $this->der_bitstring( "\x00" . $rsa_public_key )
		);

		return "-----BEGIN PUBLIC KEY-----\n" .
			   chunk_split( base64_encode( $spki ), 64, "\n" ) .
			   "-----END PUBLIC KEY-----\n";
	}

	/**
	 * Decodifica una cadena Base64URL a binario.
	 *
	 * @param string $data  Cadena en Base64URL (usa - y _ en lugar de + y /).
	 * @return string       Binario decodificado.
	 */
	private function base64url_decode( string $data ): string {
		$padded = $data . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 );
		return base64_decode( strtr( $padded, '-_', '+/' ) );
	}

	// --- DER encoding helpers (RFC 5280 / X.690) ---

	/**
	 * DER SEQUENCE (tag 0x30).
	 */
	private function der_sequence( string $contents ): string {
		return "\x30" . $this->der_length( strlen( $contents ) ) . $contents;
	}

	/**
	 * DER INTEGER (tag 0x02).
	 * Añade prefijo 0x00 si el bit más significativo está activo (indica positivo en ASN.1).
	 */
	private function der_integer( string $value ): string {
		$value = ltrim( $value, "\x00" );
		if ( '' === $value ) {
			$value = "\x00";
		}
		if ( ord( $value[0] ) >= 0x80 ) {
			$value = "\x00" . $value;
		}
		return "\x02" . $this->der_length( strlen( $value ) ) . $value;
	}

	/**
	 * DER BIT STRING (tag 0x03).
	 */
	private function der_bitstring( string $value ): string {
		return "\x03" . $this->der_length( strlen( $value ) ) . $value;
	}

	/**
	 * Codifica una longitud en formato DER.
	 * Soporta longitudes > 127 con encoding multi-byte (necesario para RSA-2048).
	 *
	 * @param int $length  Longitud a codificar.
	 * @return string      Bytes de longitud DER.
	 */
	private function der_length( int $length ): string {
		if ( $length < 128 ) {
			return chr( $length );
		}

		$bytes = '';
		$temp  = $length;
		while ( $temp > 0 ) {
			$bytes = chr( $temp & 0xFF ) . $bytes;
			$temp >>= 8;
		}

		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}
}
