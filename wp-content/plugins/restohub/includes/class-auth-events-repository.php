<?php
/**
 * Auth Events Repository - Registro de eventos de autenticación
 *
 * @package RestoHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestoHub_Auth_Events_Repository {

	/**
	 * @var RestoHub_Auth_Events_Repository|null
	 */
	private static ?RestoHub_Auth_Events_Repository $instance = null;

	/**
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * @var string
	 */
	private string $table;

	public static function instance(): RestoHub_Auth_Events_Repository {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . RestoHub_Installer::TABLE_AUTH_EVENTS;
	}

	/**
	 * Registra un evento de autenticación
	 *
	 * @param array $data Datos del evento.
	 * @return int|false ID del registro o false.
	 */
	public function log_event( array $data ) {
		$defaults = array(
			'user_id'     => 0,
			'event_type'  => '',
			'auth_method' => '',
			'email'       => '',
			'ip_address'  => $this->get_client_ip(),
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'metadata'    => null,
			'created_at'  => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		// Convertir metadata a JSON si es array
		if ( is_array( $data['metadata'] ) ) {
			$data['metadata'] = wp_json_encode( $data['metadata'] );
		}

		$result = $this->db->insert(
			$this->table,
			array(
				'user_id'     => absint( $data['user_id'] ),
				'event_type'  => sanitize_key( $data['event_type'] ),
				'auth_method' => sanitize_key( $data['auth_method'] ),
				'email'       => sanitize_email( $data['email'] ),
				'ip_address'  => sanitize_text_field( $data['ip_address'] ),
				'user_agent'  => $data['user_agent'],
				'metadata'    => $data['metadata'],
				'created_at'  => $data['created_at'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Obtiene eventos con filtros
	 *
	 * @param array $args Filtros.
	 * @return array
	 */
	public function get_events( array $args = array() ): array {
		$defaults = array(
			'event_type'  => '',
			'auth_method' => '',
			'user_id'     => 0,
			'date_from'   => '',
			'date_to'     => '',
			'orderby'     => 'created_at',
			'order'       => 'DESC',
			'limit'       => 50,
			'offset'      => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$sql    = "SELECT * FROM {$this->table} WHERE 1=1";
		$values = array();

		if ( ! empty( $args['event_type'] ) ) {
			$sql     .= ' AND event_type = %s';
			$values[] = $args['event_type'];
		}

		if ( ! empty( $args['auth_method'] ) ) {
			$sql     .= ' AND auth_method = %s';
			$values[] = $args['auth_method'];
		}

		if ( $args['user_id'] > 0 ) {
			$sql     .= ' AND user_id = %d';
			$values[] = $args['user_id'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$sql     .= ' AND created_at >= %s';
			$values[] = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$sql     .= ' AND created_at <= %s';
			$values[] = $args['date_to'];
		}

		$allowed_orderby = array( 'id', 'created_at', 'event_type', 'auth_method' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		$sql     .= ' LIMIT %d OFFSET %d';
		$values[] = $args['limit'];
		$values[] = $args['offset'];

		if ( ! empty( $values ) ) {
			$sql = $this->db->prepare( $sql, ...$values );
		}

		$results = $this->db->get_results( $sql, ARRAY_A );

		return $results ?: array();
	}

	/**
	 * Cuenta eventos con filtros
	 *
	 * @param array $args Filtros.
	 * @return int
	 */
	public function get_events_count( array $args = array() ): int {
		$defaults = array(
			'event_type'  => '',
			'auth_method' => '',
			'user_id'     => 0,
			'date_from'   => '',
			'date_to'     => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$sql    = "SELECT COUNT(*) FROM {$this->table} WHERE 1=1";
		$values = array();

		if ( ! empty( $args['event_type'] ) ) {
			$sql     .= ' AND event_type = %s';
			$values[] = $args['event_type'];
		}

		if ( ! empty( $args['auth_method'] ) ) {
			$sql     .= ' AND auth_method = %s';
			$values[] = $args['auth_method'];
		}

		if ( $args['user_id'] > 0 ) {
			$sql     .= ' AND user_id = %d';
			$values[] = $args['user_id'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$sql     .= ' AND created_at >= %s';
			$values[] = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$sql     .= ' AND created_at <= %s';
			$values[] = $args['date_to'];
		}

		if ( ! empty( $values ) ) {
			$sql = $this->db->prepare( $sql, ...$values );
		}

		return (int) $this->db->get_var( $sql );
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
				// Si hay múltiples IPs (proxy), tomar la primera
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
}

/**
 * Helper para acceder al repositorio de auth events
 *
 * @return RestoHub_Auth_Events_Repository
 */
function restohub_auth_events_repository(): RestoHub_Auth_Events_Repository {
	return RestoHub_Auth_Events_Repository::instance();
}
