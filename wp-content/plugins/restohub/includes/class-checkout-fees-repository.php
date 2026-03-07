<?php
/**
 * Checkout Fees Repository - CRUD para configuración de cargos y registro por orden
 *
 * @package RestoHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestoHub_Checkout_Fees_Repository {

	/**
	 * @var RestoHub_Checkout_Fees_Repository|null
	 */
	private static ?RestoHub_Checkout_Fees_Repository $instance = null;

	/**
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * @var string
	 */
	private string $fees_table;

	/**
	 * @var string
	 */
	private string $order_fees_table;

	public static function instance(): RestoHub_Checkout_Fees_Repository {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->db               = $wpdb;
		$this->fees_table       = $wpdb->prefix . RestoHub_Installer::TABLE_CHECKOUT_FEES;
		$this->order_fees_table = $wpdb->prefix . RestoHub_Installer::TABLE_ORDER_FEES;
	}

	// =========================================================================
	// FEE CONFIG - READ
	// =========================================================================

	/**
	 * Obtiene todos los cargos configurados para una tienda (fallback a global store_id=0)
	 *
	 * @param int $store_id
	 * @return array
	 */
	public function get_fees( int $store_id = 0 ): array {
		$results = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->fees_table} WHERE store_id = %d ORDER BY fee_type ASC, percentage ASC",
				$store_id
			),
			ARRAY_A
		);

		// Fallback a global si no hay resultados para la tienda específica
		if ( empty( $results ) && $store_id > 0 ) {
			return $this->get_fees( 0 );
		}

		return $results ?: array();
	}

	/**
	 * Obtiene un cargo por ID
	 *
	 * @param int $id
	 * @return array|null
	 */
	public function get_fee( int $id ): ?array {
		$result = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->fees_table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $result ?: null;
	}

	/**
	 * Obtiene la configuración de cuota de servicio
	 *
	 * @param int $store_id
	 * @return array|null
	 */
	public function get_service_fee( int $store_id = 0 ): ?array {
		$result = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->fees_table} WHERE store_id = %d AND fee_type = 'service_fee' LIMIT 1",
				$store_id
			),
			ARRAY_A
		);

		// Fallback a global
		if ( ! $result && $store_id > 0 ) {
			return $this->get_service_fee( 0 );
		}

		return $result ?: null;
	}

	/**
	 * Obtiene las opciones de propina disponibles
	 *
	 * @param int $store_id
	 * @return array
	 */
	public function get_tip_options( int $store_id = 0 ): array {
		$results = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->fees_table} WHERE store_id = %d AND fee_type = 'tip' AND is_active = 1 ORDER BY percentage ASC",
				$store_id
			),
			ARRAY_A
		);

		// Fallback a global
		if ( empty( $results ) && $store_id > 0 ) {
			return $this->get_tip_options( 0 );
		}

		return $results ?: array();
	}

	// =========================================================================
	// FEE CONFIG - WRITE
	// =========================================================================

	/**
	 * Guarda (crea o actualiza) la configuración de un cargo
	 *
	 * @param array $data
	 * @return int|false ID del registro o false en error
	 */
	public function save_fee( array $data ) {
		$id = ! empty( $data['id'] ) ? absint( $data['id'] ) : 0;

		$row = array(
			'store_id'   => absint( $data['store_id'] ?? 0 ),
			'fee_type'   => sanitize_text_field( $data['fee_type'] ?? '' ),
			'label'      => sanitize_text_field( $data['label'] ?? '' ),
			'percentage' => floatval( $data['percentage'] ?? 0 ),
			'is_active'  => ! empty( $data['is_active'] ) ? 1 : 0,
		);

		$formats = array( '%d', '%s', '%s', '%f', '%d' );

		if ( $id > 0 ) {
			$this->db->update( $this->fees_table, $row, array( 'id' => $id ), $formats, array( '%d' ) );
			return $id;
		}

		$row['created_at'] = current_time( 'mysql' );
		$formats[]         = '%s';

		$result = $this->db->insert( $this->fees_table, $row, $formats );
		return false !== $result ? (int) $this->db->insert_id : false;
	}

	/**
	 * Actualiza solo la cuota de servicio (store_id=0)
	 *
	 * @param array $data Keys: label, percentage, is_active
	 * @return bool
	 */
	public function update_service_fee( array $data ): bool {
		$existing = $this->get_service_fee( 0 );

		if ( ! $existing ) {
			// Crear si no existe
			$data['store_id'] = 0;
			$data['fee_type'] = 'service_fee';
			return false !== $this->save_fee( $data );
		}

		$update = array();
		$formats = array();

		if ( isset( $data['label'] ) ) {
			$update['label'] = sanitize_text_field( $data['label'] );
			$formats[]       = '%s';
		}
		if ( isset( $data['percentage'] ) ) {
			$update['percentage'] = floatval( $data['percentage'] );
			$formats[]            = '%f';
		}
		if ( isset( $data['is_active'] ) ) {
			$update['is_active'] = ! empty( $data['is_active'] ) ? 1 : 0;
			$formats[]           = '%d';
		}

		if ( empty( $update ) ) {
			return false;
		}

		return false !== $this->db->update(
			$this->fees_table,
			$update,
			array( 'id' => $existing['id'] ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Actualiza el estado activo de las propinas globalmente
	 *
	 * @param bool $is_active
	 * @return bool
	 */
	public function set_tips_active( bool $is_active ): bool {
		return false !== $this->db->update(
			$this->fees_table,
			array( 'is_active' => $is_active ? 1 : 0 ),
			array( 'store_id' => 0, 'fee_type' => 'tip' ),
			array( '%d' ),
			array( '%d', '%s' )
		);
	}

	// =========================================================================
	// ORDER FEES - WRITE / READ
	// =========================================================================

	/**
	 * Guarda un cargo aplicado a una orden
	 *
	 * @param array $data
	 * @return int|false
	 */
	public function save_order_fee( array $data ) {
		$row = array(
			'order_id'    => absint( $data['order_id'] ?? 0 ),
			'fee_type'    => sanitize_text_field( $data['fee_type'] ?? '' ),
			'label'       => sanitize_text_field( $data['label'] ?? '' ),
			'percentage'  => floatval( $data['percentage'] ?? 0 ),
			'base_amount' => floatval( $data['base_amount'] ?? 0 ),
			'fee_amount'  => floatval( $data['fee_amount'] ?? 0 ),
			'created_at'  => current_time( 'mysql' ),
		);

		$result = $this->db->insert(
			$this->order_fees_table,
			$row,
			array( '%d', '%s', '%s', '%f', '%f', '%f', '%s' )
		);

		return false !== $result ? (int) $this->db->insert_id : false;
	}

	/**
	 * Obtiene los cargos aplicados a una orden
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function get_order_fees( int $order_id ): array {
		$results = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->order_fees_table} WHERE order_id = %d ORDER BY id ASC",
				$order_id
			),
			ARRAY_A
		);

		return $results ?: array();
	}
}

/**
 * Helper global para acceder al repositorio de cargos
 *
 * @return RestoHub_Checkout_Fees_Repository
 */
function restohub_checkout_fees_repository(): RestoHub_Checkout_Fees_Repository {
	return RestoHub_Checkout_Fees_Repository::instance();
}
