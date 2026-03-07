<?php
/**
 * Validador Geoespacial - Helper Class
 *
 * Proporciona una interfaz simplificada para validar ubicaciones
 * y asignar tiendas basándose en geofencing.
 *
 * @package WC_Uber_Direct_Connect
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase WCUDC_Geo_Validator
 *
 * Wrapper simplificado para operaciones de geofencing.
 * Usa WCUDC_Polygon_Validator internamente.
 */
class WCUDC_Geo_Validator {

	/**
	 * Instancia única (Singleton)
	 *
	 * @var WCUDC_Geo_Validator|null
	 */
	private static ?WCUDC_Geo_Validator $instance = null;

	/**
	 * Instancia del validador de polígonos
	 *
	 * @var WCUDC_Polygon_Validator
	 */
	private WCUDC_Polygon_Validator $polygon_validator;

	/**
	 * Obtiene la instancia única
	 *
	 * @return WCUDC_Geo_Validator
	 */
	public static function instance(): WCUDC_Geo_Validator {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor privado
	 */
	private function __construct() {
		$this->polygon_validator = wcudc_polygon_validator();
	}

	/**
	 * Determina si un punto está dentro de un polígono (Ray Casting Algorithm)
	 *
	 * @param array $point   Punto a verificar ['lat' => float, 'lng' => float].
	 * @param array $polygon Array de puntos del polígono.
	 * @return bool True si el punto está dentro del polígono.
	 */
	public function is_point_in_polygon( array $point, array $polygon ): bool {
		return $this->polygon_validator->is_point_in_polygon( $point, $polygon );
	}

	/**
	 * Obtiene la tienda asignada para un cliente según sus coordenadas
	 *
	 * Recorre todas las tiendas activas, verifica sus polígonos de zona
	 * y devuelve la primera tienda que cubra las coordenadas del cliente.
	 *
	 * @param float $customer_lat Latitud del cliente.
	 * @param float $customer_lng Longitud del cliente.
	 * @return array|false Datos de la tienda o false si está fuera de zona.
	 */
	public function get_assigned_store( float $customer_lat, float $customer_lng ) {
		$point = array(
			'lat' => $customer_lat,
			'lng' => $customer_lng,
		);

		$store = $this->polygon_validator->find_store_for_point( $point );

		return $store ?: false;
	}

	/**
	 * Verifica si hay cobertura de delivery para las coordenadas dadas
	 *
	 * @param float $lat Latitud.
	 * @param float $lng Longitud.
	 * @return bool True si hay cobertura.
	 */
	public function has_delivery_coverage( float $lat, float $lng ): bool {
		$point = array( 'lat' => $lat, 'lng' => $lng );
		return $this->polygon_validator->has_coverage( $point );
	}

	/**
	 * Obtiene todas las tiendas que cubren un punto (para zonas superpuestas)
	 *
	 * @param float $lat Latitud.
	 * @param float $lng Longitud.
	 * @return array Array de tiendas que cubren el punto.
	 */
	public function get_all_covering_stores( float $lat, float $lng ): array {
		$point = array( 'lat' => $lat, 'lng' => $lng );
		return $this->polygon_validator->find_all_stores_for_point( $point );
	}

	/**
	 * Obtiene la tienda más cercana (fallback si no hay cobertura)
	 *
	 * @param float $lat Latitud.
	 * @param float $lng Longitud.
	 * @return array|null Datos de la tienda más cercana.
	 */
	public function get_nearest_store( float $lat, float $lng ): ?array {
		$point = array( 'lat' => $lat, 'lng' => $lng );
		return $this->polygon_validator->find_nearest_store( $point );
	}

	/**
	 * Calcula la distancia entre dos puntos (Haversine)
	 *
	 * @param float $lat1 Latitud punto 1.
	 * @param float $lng1 Longitud punto 1.
	 * @param float $lat2 Latitud punto 2.
	 * @param float $lng2 Longitud punto 2.
	 * @return float Distancia en kilómetros.
	 */
	public function calculate_distance( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		return $this->polygon_validator->haversine_distance( $lat1, $lng1, $lat2, $lng2 );
	}

	/**
	 * Valida un polígono
	 *
	 * @param array $polygon Polígono a validar.
	 * @return array ['valid' => bool, 'errors' => array].
	 */
	public function validate_polygon( array $polygon ): array {
		return $this->polygon_validator->validate_polygon( $polygon );
	}

	/**
	 * Obtiene el resultado completo de verificación de zona
	 *
	 * @param float $lat Latitud.
	 * @param float $lng Longitud.
	 * @return array Resultado con toda la información relevante.
	 */
	public function check_zone( float $lat, float $lng ): array {
		$store = $this->get_assigned_store( $lat, $lng );

		if ( $store ) {
			return array(
				'success'     => true,
				'in_zone'     => true,
				'store_id'    => $store['id'],
				'store_name'  => $store['name'],
				'store_data'  => $store,
				'message'     => sprintf(
					__( 'Delivery disponible desde %s', 'wc-uber-direct-connect' ),
					$store['name']
				),
			);
		}

		$nearest = $this->get_nearest_store( $lat, $lng );

		return array(
			'success'       => false,
			'in_zone'       => false,
			'message'       => __( 'Fuera de zona de delivery', 'wc-uber-direct-connect' ),
			'nearest_store' => $nearest,
			'pickup_available' => true,
		);
	}
}

/**
 * Función helper para acceder al validador geoespacial
 *
 * @return WCUDC_Geo_Validator
 */
function wcudc_geo_validator(): WCUDC_Geo_Validator {
	return WCUDC_Geo_Validator::instance();
}
