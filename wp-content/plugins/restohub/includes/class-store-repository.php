<?php
/**
 * Store Repository - Manejo de CRUD para tiendas
 *
 * Utiliza tablas personalizadas en lugar de wp_options
 * para mejor escalabilidad y consultas geográficas.
 *
 * @package RestoHub
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase RestoHub_Store_Repository
 *
 * Maneja todas las operaciones CRUD para la tabla de tiendas.
 * Utiliza la constante TABLE_STORES del Installer para consistencia.
 */
class RestoHub_Store_Repository {

	/**
	 * Instancia única (Singleton)
	 *
	 * @var RestoHub_Store_Repository|null
	 */
	private static ?RestoHub_Store_Repository $instance = null;

	/**
	 * Referencia global a wpdb
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Nombre completo de la tabla con prefijo
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Cache en memoria para evitar queries repetidas
	 *
	 * @var array|null
	 */
	private ?array $cache = null;

	/**
	 * Obtiene la instancia única
	 *
	 * @return RestoHub_Store_Repository
	 */
	public static function instance(): RestoHub_Store_Repository {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor privado
	 */
	private function __construct() {
		global $wpdb;
		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . RestoHub_Installer::TABLE_STORES;
	}

	/**
	 * Obtiene el nombre completo de la tabla
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		return $this->table;
	}

	/**
	 * Limpia la cache interna
	 */
	public function clear_cache(): void {
		$this->cache = null;
	}

	// =========================================================================
	// CREATE
	// =========================================================================

	/**
	 * Crea una nueva tienda
	 *
	 * @param array $data Datos de la tienda.
	 * @return int|false ID de la tienda creada o false en error.
	 */
	public function create( array $data ) {
		$defaults = array(
			'name'         => '',
			'address'      => '',
			'phone'        => '',
			'latitude'     => 0.0,
			'longitude'    => 0.0,
			'polygon_data' => '',
			'is_active'    => 1,
			'created_at'   => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		// Sanitizar datos
		$insert_data = $this->sanitize_store_data( $data );

		// Insertar
		$result = $this->db->insert(
			$this->table,
			$insert_data,
			$this->get_column_formats()
		);

		if ( false === $result ) {
			return false;
		}

		$this->clear_cache();

		return (int) $this->db->insert_id;
	}

	// =========================================================================
	// READ
	// =========================================================================

	/**
	 * Obtiene una tienda por ID
	 *
	 * @param int $id ID de la tienda.
	 * @return array|null Datos de la tienda o null.
	 */
	public function get( int $id ): ?array {
		$store = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $store ) {
			return null;
		}

		return $this->format_store_output( $store );
	}

	/**
	 * Obtiene todas las tiendas
	 *
	 * @param array $args Argumentos de filtrado.
	 * @return array
	 */
	public function get_all( array $args = array() ): array {
		$defaults = array(
			'is_active' => null,
			'orderby'   => 'name',
			'order'     => 'ASC',
			'limit'     => 0,
			'offset'    => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		// Construir query
		$sql = "SELECT * FROM {$this->table} WHERE 1=1";

		// Filtro por estado activo
		if ( ! is_null( $args['is_active'] ) ) {
			$sql .= $this->db->prepare( ' AND is_active = %d', $args['is_active'] ? 1 : 0 );
		}

		// Ordenamiento
		$allowed_orderby = array( 'id', 'name', 'created_at', 'latitude', 'longitude' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'name';
		$order   = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		// Límite y offset
		if ( $args['limit'] > 0 ) {
			$sql .= $this->db->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		}

		$stores = $this->db->get_results( $sql, ARRAY_A );

		if ( ! $stores ) {
			return array();
		}

		return array_map( array( $this, 'format_store_output' ), $stores );
	}

	/**
	 * Obtiene todas las tiendas activas
	 *
	 * @return array
	 */
	public function get_active(): array {
		// Usar cache si está disponible
		if ( ! is_null( $this->cache ) ) {
			return $this->cache;
		}

		$this->cache = $this->get_all( array( 'is_active' => true ) );

		return $this->cache;
	}

	/**
	 * Obtiene tiendas activas con polígonos definidos
	 *
	 * @return array
	 */
	public function get_active_with_polygons(): array {
		$stores = $this->get_active();

		return array_filter( $stores, function( $store ) {
			return ! empty( $store['polygon'] ) && count( $store['polygon'] ) >= 3;
		} );
	}

	/**
	 * Obtiene el conteo total de tiendas
	 *
	 * @param bool|null $is_active Filtrar por estado.
	 * @return int
	 */
	public function count( ?bool $is_active = null ): int {
		$sql = "SELECT COUNT(*) FROM {$this->table} WHERE 1=1";

		if ( ! is_null( $is_active ) ) {
			$sql .= $this->db->prepare( ' AND is_active = %d', $is_active ? 1 : 0 );
		}

		return (int) $this->db->get_var( $sql );
	}

	/**
	 * Busca tiendas por nombre o dirección
	 *
	 * @param string $search Término de búsqueda.
	 * @return array
	 */
	public function search( string $search ): array {
		$like = '%' . $this->db->esc_like( $search ) . '%';

		$stores = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE name LIKE %s OR address LIKE %s ORDER BY name ASC",
				$like,
				$like
			),
			ARRAY_A
		);

		if ( ! $stores ) {
			return array();
		}

		return array_map( array( $this, 'format_store_output' ), $stores );
	}

	/**
	 * Obtiene tiendas dentro de un radio (usando Haversine)
	 *
	 * @param float $lat    Latitud del punto.
	 * @param float $lng    Longitud del punto.
	 * @param float $radius Radio en kilómetros.
	 * @return array Tiendas ordenadas por distancia.
	 */
	public function get_within_radius( float $lat, float $lng, float $radius = 10.0 ): array {
		// Fórmula Haversine en SQL
		$sql = $this->db->prepare(
			"SELECT *,
				(6371 * ACOS(
					COS(RADIANS(%f)) * COS(RADIANS(latitude)) *
					COS(RADIANS(longitude) - RADIANS(%f)) +
					SIN(RADIANS(%f)) * SIN(RADIANS(latitude))
				)) AS distance
			FROM {$this->table}
			WHERE is_active = 1
			HAVING distance <= %f
			ORDER BY distance ASC",
			$lat,
			$lng,
			$lat,
			$radius
		);

		$stores = $this->db->get_results( $sql, ARRAY_A );

		if ( ! $stores ) {
			return array();
		}

		return array_map( function( $store ) {
			$formatted = $this->format_store_output( $store );
			$formatted['distance'] = round( (float) $store['distance'], 2 );
			return $formatted;
		}, $stores );
	}

	/**
	 * Obtiene la tienda más cercana a un punto
	 *
	 * @param float $lat Latitud.
	 * @param float $lng Longitud.
	 * @return array|null
	 */
	public function get_nearest( float $lat, float $lng ): ?array {
		$stores = $this->get_within_radius( $lat, $lng, 100.0 );

		return ! empty( $stores ) ? $stores[0] : null;
	}

	// =========================================================================
	// UPDATE
	// =========================================================================

	/**
	 * Actualiza una tienda
	 *
	 * @param int   $id   ID de la tienda.
	 * @param array $data Datos a actualizar.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		// Obtener tienda existente
		$existing = $this->get( $id );

		if ( ! $existing ) {
			return false;
		}

		// Sanitizar datos
		$update_data = $this->sanitize_store_data( $data, true );

		// Remover campos que no deben actualizarse
		unset( $update_data['id'], $update_data['created_at'] );

		if ( empty( $update_data ) ) {
			return false;
		}

		$result = $this->db->update(
			$this->table,
			$update_data,
			array( 'id' => $id ),
			$this->get_column_formats( array_keys( $update_data ) ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$this->clear_cache();

		return true;
	}

	/**
	 * Activa una tienda
	 *
	 * @param int $id ID de la tienda.
	 * @return bool
	 */
	public function activate( int $id ): bool {
		return $this->update( $id, array( 'is_active' => 1 ) );
	}

	/**
	 * Desactiva una tienda
	 *
	 * @param int $id ID de la tienda.
	 * @return bool
	 */
	public function deactivate( int $id ): bool {
		return $this->update( $id, array( 'is_active' => 0 ) );
	}

	/**
	 * Actualiza el polígono de una tienda
	 *
	 * @param int   $id      ID de la tienda.
	 * @param array $polygon Array de puntos del polígono.
	 * @return bool
	 */
	public function update_polygon( int $id, array $polygon ): bool {
		$polygon_json = wp_json_encode( $polygon );

		return $this->update( $id, array( 'polygon_data' => $polygon_json ) );
	}

	// =========================================================================
	// DELETE
	// =========================================================================

	/**
	 * Elimina una tienda
	 *
	 * @param int $id ID de la tienda.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$result = $this->db->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$this->clear_cache();

		return true;
	}

	/**
	 * Elimina múltiples tiendas
	 *
	 * @param array $ids Array de IDs.
	 * @return int Número de tiendas eliminadas.
	 */
	public function delete_many( array $ids ): int {
		if ( empty( $ids ) ) {
			return 0;
		}

		$ids = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$result = $this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->table} WHERE id IN ({$placeholders})",
				...$ids
			)
		);

		$this->clear_cache();

		return (int) $result;
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	/**
	 * Sanitiza los datos de una tienda
	 *
	 * @param array $data      Datos a sanitizar.
	 * @param bool  $is_update Si es actualización (campos opcionales).
	 * @return array
	 */
	private function sanitize_store_data( array $data, bool $is_update = false ): array {
		$sanitized = array();

		if ( isset( $data['name'] ) ) {
			$sanitized['name'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['address'] ) ) {
			$sanitized['address'] = sanitize_textarea_field( $data['address'] );
		}

		if ( isset( $data['phone'] ) ) {
			$sanitized['phone'] = sanitize_text_field( $data['phone'] );
		}

		if ( isset( $data['latitude'] ) ) {
			$sanitized['latitude'] = floatval( $data['latitude'] );
		}

		if ( isset( $data['longitude'] ) ) {
			$sanitized['longitude'] = floatval( $data['longitude'] );
		}

		if ( isset( $data['polygon_data'] ) ) {
			// Si viene como array, convertir a JSON
			if ( is_array( $data['polygon_data'] ) ) {
				$sanitized['polygon_data'] = wp_json_encode( $data['polygon_data'] );
			} else {
				// Validar que sea JSON válido
				$decoded = json_decode( $data['polygon_data'], true );
				$sanitized['polygon_data'] = is_array( $decoded ) ? $data['polygon_data'] : '';
			}
		}

		if ( isset( $data['is_active'] ) ) {
			$sanitized['is_active'] = $data['is_active'] ? 1 : 0;
		}

		if ( isset( $data['created_at'] ) && ! $is_update ) {
			$sanitized['created_at'] = sanitize_text_field( $data['created_at'] );
		}

		return $sanitized;
	}

	/**
	 * Formatea la salida de una tienda
	 *
	 * @param array $store Datos crudos de la BD.
	 * @return array Datos formateados.
	 */
	private function format_store_output( array $store ): array {
		// Decodificar polígono
		$polygon = array();
		if ( ! empty( $store['polygon_data'] ) ) {
			$decoded = json_decode( $store['polygon_data'], true );
			if ( is_array( $decoded ) ) {
				$polygon = $decoded;
			}
		}

		return array(
			'id'         => (int) $store['id'],
			'name'       => $store['name'],
			'address'    => $store['address'],
			'phone'      => $store['phone'] ?? '',
			'latitude'   => (float) $store['latitude'],
			'longitude'  => (float) $store['longitude'],
			'polygon'    => $polygon,
			'active'     => (bool) $store['is_active'],
			'is_active'  => (bool) $store['is_active'],
			'created_at' => $store['created_at'],
		);
	}

	/**
	 * Obtiene los formatos de columnas para wpdb
	 *
	 * @param array|null $columns Columnas específicas.
	 * @return array
	 */
	private function get_column_formats( ?array $columns = null ): array {
		$formats = array(
			'name'         => '%s',
			'address'      => '%s',
			'phone'        => '%s',
			'latitude'     => '%f',
			'longitude'    => '%f',
			'polygon_data' => '%s',
			'is_active'    => '%d',
			'created_at'   => '%s',
		);

		if ( is_null( $columns ) ) {
			return array_values( $formats );
		}

		$result = array();
		foreach ( $columns as $column ) {
			if ( isset( $formats[ $column ] ) ) {
				$result[] = $formats[ $column ];
			}
		}

		return $result;
	}

	/**
	 * Verifica si la tabla existe
	 *
	 * @return bool
	 */
	public function table_exists(): bool {
		$result = $this->db->get_var(
			$this->db->prepare(
				'SHOW TABLES LIKE %s',
				$this->table
			)
		);

		return $result === $this->table;
	}

	/**
	 * Migra datos desde wp_options (si existen)
	 *
	 * @return int Número de tiendas migradas.
	 */
	public function migrate_from_options(): int {
		$old_stores = get_option( 'restohub_stores', array() );

		if ( empty( $old_stores ) ) {
			return 0;
		}

		$migrated = 0;

		foreach ( $old_stores as $old_store ) {
			// Verificar si ya existe (por nombre y dirección)
			$existing = $this->db->get_var(
				$this->db->prepare(
					"SELECT id FROM {$this->table} WHERE name = %s AND address = %s",
					$old_store['name'] ?? '',
					$old_store['address'] ?? ''
				)
			);

			if ( $existing ) {
				continue;
			}

			// Preparar datos para migración
			$data = array(
				'name'         => $old_store['name'] ?? '',
				'address'      => $old_store['address'] ?? '',
				'phone'        => $old_store['phone'] ?? '',
				'latitude'     => floatval( $old_store['latitude'] ?? 0 ),
				'longitude'    => floatval( $old_store['longitude'] ?? 0 ),
				'polygon_data' => isset( $old_store['polygon'] ) ? $old_store['polygon'] : array(),
				'is_active'    => ! empty( $old_store['active'] ) ? 1 : 0,
				'created_at'   => $old_store['created'] ?? current_time( 'mysql' ),
			);

			if ( $this->create( $data ) ) {
				$migrated++;
			}
		}

		// Opcionalmente eliminar datos antiguos después de migrar
		if ( $migrated > 0 ) {
			// delete_option( 'restohub_stores' ); // Descomentar cuando esté seguro
		}

		return $migrated;
	}
}

/**
 * Función helper para acceder al repositorio de tiendas
 *
 * @return RestoHub_Store_Repository
 */
function restohub_store_repository(): RestoHub_Store_Repository {
	return RestoHub_Store_Repository::instance();
}
