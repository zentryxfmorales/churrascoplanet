<?php
/**
 * Plugin Installer - Creación de Tablas Personalizadas
 *
 * Esta clase maneja la instalación y actualización del schema de base de datos.
 * Se ejecuta automáticamente al activar el plugin en cualquier servidor.
 *
 * PORTABILIDAD: El plugin es 'plug-and-play'. Al copiar la carpeta a otro
 * servidor y activarlo, las tablas se crean automáticamente usando dbDelta().
 *
 * @package RestoHub
 * @since   1.0.0
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase RestoHub_Installer
 *
 * Instalador del plugin - maneja la creación de tablas con dbDelta()
 */
class RestoHub_Installer {

	/**
	 * Versión del schema de base de datos
	 *
	 * Incrementar cuando se modifique la estructura de tablas.
	 * Esto dispara la actualización automática del schema.
	 */
	const DB_VERSION = '1.2.0';

	/**
	 * Nombre de la opción que almacena la versión de BD instalada
	 */
	const DB_VERSION_OPTION = 'restohub_db_version';

	/**
	 * Nombre de la tabla de tiendas (sin prefijo)
	 */
	const TABLE_STORES = 'restohub_stores';

	/**
	 * Nombre de la tabla de configuración de cargos del checkout
	 */
	const TABLE_CHECKOUT_FEES = 'restohub_checkout_fees';

	/**
	 * Nombre de la tabla de cargos aplicados por orden
	 */
	const TABLE_ORDER_FEES = 'restohub_order_fees';

	/**
	 * Nombre de la tabla de eventos de autenticación
	 */
	const TABLE_AUTH_EVENTS = 'restohub_auth_events';

	/**
	 * Método principal de instalación
	 *
	 * Se ejecuta en: register_activation_hook()
	 * También se puede llamar manualmente para actualizaciones.
	 *
	 * @return void
	 */
	public static function install(): void {
		// Verificar permisos (solo en contexto admin)
		if ( ! is_blog_installed() ) {
			return;
		}

		// Prevenir ejecución paralela
		if ( 'yes' === get_transient( 'restohub_installing' ) ) {
			return;
		}

		set_transient( 'restohub_installing', 'yes', MINUTE_IN_SECONDS * 5 );

		// Ejecutar instalación
		self::create_tables();
		self::create_options();
		self::maybe_migrate_from_options();
		self::update_db_version();

		delete_transient( 'restohub_installing' );

		// Limpiar reglas de reescritura
		flush_rewrite_rules();

		/**
		 * Acción disparada después de la instalación del plugin
		 *
		 * @since 1.0.0
		 */
		do_action( 'restohub_installed' );
	}

	/**
	 * Crea las tablas personalizadas usando dbDelta()
	 *
	 * dbDelta() es la función nativa de WordPress para crear/actualizar tablas.
	 * Compara el schema actual con el deseado y aplica solo los cambios necesarios.
	 *
	 * IMPORTANTE para dbDelta():
	 * - Debe haber DOS espacios después de PRIMARY KEY
	 * - Cada campo en su propia línea
	 * - No usar IF NOT EXISTS
	 * - Los índices KEY deben ir después de las columnas
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		// Charset y collation para soporte de UTF-8 (tildes, ñ, emojis)
		$charset_collate = $wpdb->get_charset_collate();

		// Nombre completo de la tabla con prefijo
		$table_name = $wpdb->prefix . self::TABLE_STORES;

		/**
		 * Schema de la tabla restohub_stores
		 *
		 * Campos:
		 * - id: Identificador único autoincremental
		 * - name: Nombre de la tienda (máx 255 caracteres)
		 * - address: Dirección completa (texto largo)
		 * - phone: Teléfono de contacto
		 * - latitude: Coordenada GPS con precisión de 8 decimales (±0.001m)
		 * - longitude: Coordenada GPS con precisión de 8 decimales
		 * - polygon_data: JSON con los puntos del polígono de cobertura
		 * - is_active: Estado de la tienda (1=activa, 0=inactiva)
		 * - created_at: Fecha de creación
		 *
		 * Índices:
		 * - PRIMARY KEY: id
		 * - idx_is_active: Filtrado rápido por estado
		 * - idx_location: Consultas geográficas (Haversine)
		 * - idx_name: Búsqueda por nombre
		 */
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '',
			address text NOT NULL,
			phone varchar(50) NOT NULL DEFAULT '',
			latitude decimal(10,8) NOT NULL DEFAULT 0.00000000,
			longitude decimal(11,8) NOT NULL DEFAULT 0.00000000,
			polygon_data longtext,
			is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_is_active (is_active),
			KEY idx_location (latitude, longitude),
			KEY idx_name (name(100))
		) {$charset_collate};\n";

		// --- Tabla: restohub_checkout_fees (configuración de cargos) ---
		$fees_table = $wpdb->prefix . self::TABLE_CHECKOUT_FEES;

		$sql .= "CREATE TABLE {$fees_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			store_id bigint(20) unsigned NOT NULL DEFAULT 0,
			fee_type varchar(50) NOT NULL,
			label varchar(255) NOT NULL,
			percentage decimal(5,2) NOT NULL DEFAULT 0.00,
			is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_store_fee (store_id, fee_type),
			KEY idx_active (is_active)
		) {$charset_collate};\n";

		// --- Tabla: restohub_order_fees (cargos aplicados por orden) ---
		$order_fees_table = $wpdb->prefix . self::TABLE_ORDER_FEES;

		$sql .= "CREATE TABLE {$order_fees_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			fee_type varchar(50) NOT NULL,
			label varchar(255) NOT NULL,
			percentage decimal(5,2) NOT NULL DEFAULT 0.00,
			base_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			fee_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_order (order_id),
			KEY idx_type (fee_type),
			KEY idx_created (created_at)
		) {$charset_collate};\n";

		// --- Tabla: restohub_auth_events (eventos de autenticación) ---
		$auth_events_table = $wpdb->prefix . self::TABLE_AUTH_EVENTS;

		$sql .= "CREATE TABLE {$auth_events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(50) NOT NULL,
			auth_method varchar(50) NOT NULL,
			email varchar(255) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			user_agent text,
			metadata longtext,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user (user_id),
			KEY idx_event (event_type),
			KEY idx_method (auth_method),
			KEY idx_created (created_at)
		) {$charset_collate};\n";

		// Incluir el archivo necesario para dbDelta
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Ejecutar dbDelta - crea o actualiza las tablas
		dbDelta( $sql );

		// Log de instalación (solo si WooCommerce está activo)
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				'WCUDC tables created/updated successfully.',
				array( 'source' => 'restohub' )
			);
		}
	}

	/**
	 * Crea las opciones por defecto del plugin
	 *
	 * @return void
	 */
	private static function create_options(): void {
		// Configuración general del plugin
		$default_settings = array(
			'client_id'        => '',
			'client_secret'    => '',
			'customer_id'      => '',
			'webhook_secret'   => '',
			'google_client_id' => '',
			'sandbox_mode'     => 'yes',
			'store_hours'      => array(),
		);

		// Solo crear si no existe (no sobrescribir configuración existente)
		if ( false === get_option( 'restohub_settings' ) ) {
			add_option( 'restohub_settings', $default_settings, '', 'no' );
		}

		// Seed: Configuración de cargos del checkout
		self::seed_checkout_fees();
	}

	/**
	 * Inserta datos iniciales de cargos del checkout si la tabla está vacía
	 */
	private static function seed_checkout_fees(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_CHECKOUT_FEES;

		// Verificar que la tabla exista
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $table !== $table_exists ) {
			return;
		}

		// Solo insertar si la tabla está vacía
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}

		// Cuota de servicio (5% por defecto)
		$wpdb->insert( $table, array(
			'store_id'   => 0,
			'fee_type'   => 'service_fee',
			'label'      => 'Cuota de servicio',
			'percentage' => 5.00,
			'is_active'  => 1,
			'created_at' => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%f', '%d', '%s' ) );

		// Opciones de propina (0%, 5%, 10%, 15%)
		$tip_percentages = array( 0, 5, 10, 15 );
		foreach ( $tip_percentages as $pct ) {
			$wpdb->insert( $table, array(
				'store_id'   => 0,
				'fee_type'   => 'tip',
				'label'      => 'Propina',
				'percentage' => $pct,
				'is_active'  => 1,
				'created_at' => current_time( 'mysql' ),
			), array( '%d', '%s', '%s', '%f', '%d', '%s' ) );
		}
	}

	/**
	 * Migra datos desde wp_options si existen (retrocompatibilidad)
	 *
	 * Esta función se ejecuta solo si hay datos en el formato antiguo.
	 * Migra las tiendas de wp_options a la nueva tabla personalizada.
	 *
	 * @return void
	 */
	private static function maybe_migrate_from_options(): void {
		// Verificar si hay datos antiguos
		$old_stores = get_option( 'restohub_stores', array() );

		if ( empty( $old_stores ) ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_STORES;

		// Verificar que la tabla exista
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( $table_name !== $table_exists ) {
			return;
		}

		$migrated = 0;

		foreach ( $old_stores as $store_key => $store ) {
			// Verificar si ya existe (evitar duplicados)
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_name} WHERE name = %s AND address = %s LIMIT 1",
					$store['name'] ?? '',
					$store['address'] ?? ''
				)
			);

			if ( $exists ) {
				continue;
			}

			// Preparar datos del polígono
			$polygon_data = '';
			if ( ! empty( $store['polygon'] ) ) {
				$polygon_data = is_array( $store['polygon'] )
					? wp_json_encode( $store['polygon'] )
					: $store['polygon'];
			}

			// Insertar en la nueva tabla
			$result = $wpdb->insert(
				$table_name,
				array(
					'name'         => sanitize_text_field( $store['name'] ?? '' ),
					'address'      => sanitize_textarea_field( $store['address'] ?? '' ),
					'phone'        => sanitize_text_field( $store['phone'] ?? '' ),
					'latitude'     => floatval( $store['latitude'] ?? 0 ),
					'longitude'    => floatval( $store['longitude'] ?? 0 ),
					'polygon_data' => $polygon_data,
					'is_active'    => ! empty( $store['active'] ) ? 1 : 0,
					'created_at'   => $store['created'] ?? current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%f', '%f', '%s', '%d', '%s' )
			);

			if ( false !== $result ) {
				$migrated++;
			}
		}

		// Log de migración
		if ( $migrated > 0 && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				sprintf( 'Migrated %d stores from wp_options to custom table.', $migrated ),
				array( 'source' => 'restohub' )
			);
		}

		// Marcar migración como completada (no eliminar datos antiguos por seguridad)
		update_option( 'restohub_migration_completed', current_time( 'mysql' ) );
	}

	/**
	 * Actualiza la versión de BD en las opciones
	 *
	 * @return void
	 */
	private static function update_db_version(): void {
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Verifica si se necesita actualización de BD
	 *
	 * Útil para ejecutar migraciones cuando el plugin se actualiza.
	 *
	 * @return bool
	 */
	public static function needs_update(): bool {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0' );
		return version_compare( $installed_version, self::DB_VERSION, '<' );
	}

	/**
	 * Ejecuta actualización de BD si es necesario
	 *
	 * Se llama en plugins_loaded para manejar actualizaciones via FTP.
	 *
	 * @return void
	 */
	public static function maybe_update(): void {
		if ( self::needs_update() ) {
			self::install();
		}
	}

	/**
	 * Desinstala el plugin (elimina tablas y opciones)
	 *
	 * CUIDADO: Solo usar en uninstall.php
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		// Eliminar tablas
		$table_name = $wpdb->prefix . self::TABLE_STORES;
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		$fees_table = $wpdb->prefix . self::TABLE_CHECKOUT_FEES;
		$wpdb->query( "DROP TABLE IF EXISTS {$fees_table}" );

		$order_fees_table = $wpdb->prefix . self::TABLE_ORDER_FEES;
		$wpdb->query( "DROP TABLE IF EXISTS {$order_fees_table}" );

		$auth_events_table = $wpdb->prefix . self::TABLE_AUTH_EVENTS;
		$wpdb->query( "DROP TABLE IF EXISTS {$auth_events_table}" );

		// Eliminar opciones
		delete_option( 'restohub_settings' );
		delete_option( self::DB_VERSION_OPTION );
		delete_option( 'restohub_migration_completed' );
		delete_option( 'restohub_stores' ); // Datos antiguos

		// Limpiar transients
		delete_transient( 'restohub_installing' );
	}

	/**
	 * Obtiene información sobre las tablas instaladas
	 *
	 * @return array
	 */
	public static function get_tables_info(): array {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_STORES;

		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		) === $table_name;

		if ( ! $exists ) {
			return array(
				'table_name' => $table_name,
				'exists'     => false,
				'rows'       => 0,
				'size'       => '0 B',
			);
		}

		$status = $wpdb->get_row(
			$wpdb->prepare( "SHOW TABLE STATUS LIKE %s", $table_name ),
			ARRAY_A
		);

		$size = ( $status['Data_length'] ?? 0 ) + ( $status['Index_length'] ?? 0 );

		return array(
			'table_name' => $table_name,
			'exists'     => true,
			'rows'       => (int) ( $status['Rows'] ?? 0 ),
			'size'       => size_format( $size ),
			'engine'     => $status['Engine'] ?? 'Unknown',
			'collation'  => $status['Collation'] ?? 'Unknown',
			'db_version' => get_option( self::DB_VERSION_OPTION, '0' ),
		);
	}
}
