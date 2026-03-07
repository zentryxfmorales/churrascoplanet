<?php
/**
 * Validador de polígonos para geofencing
 *
 * Implementa el algoritmo Ray Casting para determinar si un punto
 * está dentro de un polígono (zona de reparto).
 *
 * @package WC_Uber_Direct_Connect
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase WCUDC_Polygon_Validator
 *
 * Proporciona funciones de geofencing para asignar tiendas según ubicación del cliente
 */
class WCUDC_Polygon_Validator {

    /**
     * Instancia única (Singleton)
     *
     * @var WCUDC_Polygon_Validator|null
     */
    private static ?WCUDC_Polygon_Validator $instance = null;

    /**
     * Logger de WooCommerce
     *
     * @var WC_Logger_Interface|null
     */
    private ?WC_Logger_Interface $logger = null;

    /**
     * Contexto del logger
     */
    private const LOG_SOURCE = 'wc-uber-direct-connect';

    /**
     * Obtiene la instancia única
     *
     * @return WCUDC_Polygon_Validator
     */
    public static function instance(): WCUDC_Polygon_Validator {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor privado
     */
    private function __construct() {
        if ( function_exists( 'wc_get_logger' ) ) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Registra un mensaje en el log
     *
     * @param string $level   Nivel del log.
     * @param string $message Mensaje.
     */
    private function log( string $level, string $message ): void {
        if ( $this->logger ) {
            $this->logger->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
        }
    }

    /**
     * Determina si un punto está dentro de un polígono usando Ray Casting Algorithm
     *
     * El algoritmo funciona trazando una línea horizontal desde el punto hacia el infinito
     * y contando cuántas veces cruza los bordes del polígono. Si el número de cruces es
     * impar, el punto está dentro; si es par, está fuera.
     *
     * @param array $point   Punto a verificar ['lat' => float, 'lng' => float].
     * @param array $polygon Array de puntos del polígono [['lat' => float, 'lng' => float], ...].
     * @return bool True si el punto está dentro del polígono.
     */
    public function is_point_in_polygon( array $point, array $polygon ): bool {
        // Validar entrada
        if ( ! isset( $point['lat'], $point['lng'] ) ) {
            $this->log( 'error', 'Punto inválido: faltan lat o lng.' );
            return false;
        }

        if ( count( $polygon ) < 3 ) {
            $this->log( 'warning', 'Polígono inválido: debe tener al menos 3 puntos.' );
            return false;
        }

        $x = (float) $point['lng'];
        $y = (float) $point['lat'];

        $n      = count( $polygon );
        $inside = false;

        $p1x = (float) $polygon[0]['lng'];
        $p1y = (float) $polygon[0]['lat'];

        for ( $i = 1; $i <= $n; $i++ ) {
            $p2x = (float) $polygon[ $i % $n ]['lng'];
            $p2y = (float) $polygon[ $i % $n ]['lat'];

            if ( $y > min( $p1y, $p2y ) ) {
                if ( $y <= max( $p1y, $p2y ) ) {
                    if ( $x <= max( $p1x, $p2x ) ) {
                        if ( $p1y !== $p2y ) {
                            $xinters = ( $y - $p1y ) * ( $p2x - $p1x ) / ( $p2y - $p1y ) + $p1x;
                        }

                        if ( $p1x === $p2x || $x <= $xinters ) {
                            $inside = ! $inside;
                        }
                    }
                }
            }

            $p1x = $p2x;
            $p1y = $p2y;
        }

        return $inside;
    }

    /**
     * Encuentra la tienda que cubre un punto dado
     *
     * Busca en todas las tiendas activas cuál tiene un polígono que contenga
     * las coordenadas del cliente.
     *
     * @param array $point Coordenadas del cliente ['lat' => float, 'lng' => float].
     * @return array|null Datos de la tienda o null si no hay cobertura.
     */
    public function find_store_for_point( array $point ): ?array {
        if ( ! isset( $point['lat'], $point['lng'] ) ) {
            $this->log( 'error', 'find_store_for_point: Coordenadas inválidas.' );
            return null;
        }

        $this->log( 'debug', sprintf(
            'Buscando tienda para punto: lat=%s, lng=%s',
            $point['lat'],
            $point['lng']
        ) );

        // Obtener tiendas activas
        $stores = $this->get_active_stores();

        if ( empty( $stores ) ) {
            $this->log( 'warning', 'No hay tiendas activas configuradas.' );
            return null;
        }

        foreach ( $stores as $store ) {
            // Verificar que la tienda tenga polígono definido
            if ( empty( $store['polygon'] ) || count( $store['polygon'] ) < 3 ) {
                $this->log( 'debug', sprintf(
                    'Tienda "%s" omitida: sin polígono válido.',
                    $store['name']
                ) );
                continue;
            }

            // Verificar si el punto está dentro del polígono de esta tienda
            if ( $this->is_point_in_polygon( $point, $store['polygon'] ) ) {
                $this->log( 'info', sprintf(
                    'Punto asignado a tienda "%s" (ID: %s)',
                    $store['name'],
                    $store['id']
                ) );
                return $store;
            }
        }

        $this->log( 'warning', sprintf(
            'Sin cobertura para punto: lat=%s, lng=%s',
            $point['lat'],
            $point['lng']
        ) );

        return null;
    }

    /**
     * Verifica si hay cobertura para un punto dado
     *
     * @param array $point Coordenadas ['lat' => float, 'lng' => float].
     * @return bool True si hay al menos una tienda que cubra el punto.
     */
    public function has_coverage( array $point ): bool {
        return $this->find_store_for_point( $point ) !== null;
    }

    /**
     * Obtiene todas las tiendas que cubren un punto
     *
     * Útil si hay polígonos superpuestos y se necesita elegir entre varias tiendas.
     *
     * @param array $point Coordenadas ['lat' => float, 'lng' => float].
     * @return array Array de tiendas que cubren el punto.
     */
    public function find_all_stores_for_point( array $point ): array {
        if ( ! isset( $point['lat'], $point['lng'] ) ) {
            return array();
        }

        $stores          = $this->get_active_stores();
        $matching_stores = array();

        foreach ( $stores as $store ) {
            if ( empty( $store['polygon'] ) || count( $store['polygon'] ) < 3 ) {
                continue;
            }

            if ( $this->is_point_in_polygon( $point, $store['polygon'] ) ) {
                $matching_stores[] = $store;
            }
        }

        return $matching_stores;
    }

    /**
     * Encuentra la tienda más cercana a un punto (fallback si no hay cobertura por polígono)
     *
     * Calcula la distancia usando la fórmula de Haversine.
     *
     * @param array $point Coordenadas ['lat' => float, 'lng' => float].
     * @return array|null Tienda más cercana o null si no hay tiendas.
     */
    public function find_nearest_store( array $point ): ?array {
        if ( ! isset( $point['lat'], $point['lng'] ) ) {
            return null;
        }

        $stores = $this->get_active_stores();

        if ( empty( $stores ) ) {
            return null;
        }

        $nearest_store    = null;
        $shortest_distance = PHP_FLOAT_MAX;

        foreach ( $stores as $store ) {
            if ( empty( $store['latitude'] ) || empty( $store['longitude'] ) ) {
                continue;
            }

            $distance = $this->haversine_distance(
                (float) $point['lat'],
                (float) $point['lng'],
                (float) $store['latitude'],
                (float) $store['longitude']
            );

            if ( $distance < $shortest_distance ) {
                $shortest_distance = $distance;
                $nearest_store     = $store;
            }
        }

        if ( $nearest_store ) {
            $this->log( 'info', sprintf(
                'Tienda más cercana: "%s" a %.2f km',
                $nearest_store['name'],
                $shortest_distance
            ) );
        }

        return $nearest_store;
    }

    /**
     * Calcula la distancia entre dos puntos usando la fórmula de Haversine
     *
     * @param float $lat1 Latitud del punto 1.
     * @param float $lng1 Longitud del punto 1.
     * @param float $lat2 Latitud del punto 2.
     * @param float $lng2 Longitud del punto 2.
     * @return float Distancia en kilómetros.
     */
    public function haversine_distance( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
        $earth_radius = 6371; // Radio de la Tierra en km

        $lat1_rad = deg2rad( $lat1 );
        $lat2_rad = deg2rad( $lat2 );
        $delta_lat = deg2rad( $lat2 - $lat1 );
        $delta_lng = deg2rad( $lng2 - $lng1 );

        $a = sin( $delta_lat / 2 ) * sin( $delta_lat / 2 ) +
             cos( $lat1_rad ) * cos( $lat2_rad ) *
             sin( $delta_lng / 2 ) * sin( $delta_lng / 2 );

        $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

        return $earth_radius * $c;
    }

    /**
     * Calcula el centroide (centro geométrico) de un polígono
     *
     * @param array $polygon Array de puntos del polígono.
     * @return array|null Centro del polígono ['lat' => float, 'lng' => float].
     */
    public function get_polygon_centroid( array $polygon ): ?array {
        if ( count( $polygon ) < 3 ) {
            return null;
        }

        $lat_sum = 0;
        $lng_sum = 0;
        $n       = count( $polygon );

        foreach ( $polygon as $point ) {
            $lat_sum += (float) $point['lat'];
            $lng_sum += (float) $point['lng'];
        }

        return array(
            'lat' => $lat_sum / $n,
            'lng' => $lng_sum / $n,
        );
    }

    /**
     * Calcula el área de un polígono usando la fórmula del Shoelace
     *
     * Útil para ordenar tiendas por tamaño de zona o validar polígonos.
     *
     * @param array $polygon Array de puntos del polígono.
     * @return float Área en unidades cuadradas (aproximadas para lat/lng).
     */
    public function get_polygon_area( array $polygon ): float {
        $n = count( $polygon );
        if ( $n < 3 ) {
            return 0;
        }

        $area = 0;

        for ( $i = 0; $i < $n; $i++ ) {
            $j     = ( $i + 1 ) % $n;
            $area += (float) $polygon[ $i ]['lng'] * (float) $polygon[ $j ]['lat'];
            $area -= (float) $polygon[ $j ]['lng'] * (float) $polygon[ $i ]['lat'];
        }

        return abs( $area / 2 );
    }

    /**
     * Valida que un polígono sea válido (al menos 3 puntos, cerrado correctamente)
     *
     * @param array $polygon Polígono a validar.
     * @return array Resultado de validación ['valid' => bool, 'errors' => array].
     */
    public function validate_polygon( array $polygon ): array {
        $errors = array();

        if ( count( $polygon ) < 3 ) {
            $errors[] = __( 'El polígono debe tener al menos 3 puntos.', 'wc-uber-direct-connect' );
        }

        foreach ( $polygon as $index => $point ) {
            if ( ! isset( $point['lat'] ) || ! isset( $point['lng'] ) ) {
                $errors[] = sprintf(
                    /* translators: %d: point index */
                    __( 'El punto %d no tiene coordenadas válidas.', 'wc-uber-direct-connect' ),
                    $index + 1
                );
                continue;
            }

            $lat = (float) $point['lat'];
            $lng = (float) $point['lng'];

            // Validar rangos de coordenadas
            if ( $lat < -90 || $lat > 90 ) {
                $errors[] = sprintf(
                    /* translators: %d: point index */
                    __( 'El punto %d tiene latitud fuera de rango (-90 a 90).', 'wc-uber-direct-connect' ),
                    $index + 1
                );
            }

            if ( $lng < -180 || $lng > 180 ) {
                $errors[] = sprintf(
                    /* translators: %d: point index */
                    __( 'El punto %d tiene longitud fuera de rango (-180 a 180).', 'wc-uber-direct-connect' ),
                    $index + 1
                );
            }
        }

        // Verificar que el área no sea cero (puntos colineales)
        if ( empty( $errors ) && $this->get_polygon_area( $polygon ) === 0.0 ) {
            $errors[] = __( 'Los puntos del polígono son colineales (no forman un área).', 'wc-uber-direct-connect' );
        }

        return array(
            'valid'  => empty( $errors ),
            'errors' => $errors,
        );
    }

    /**
     * Obtiene las tiendas activas desde la configuración
     *
     * @return array
     */
    private function get_active_stores(): array {
        $stores = get_option( 'wcudc_stores', array() );
        return array_filter( $stores, fn( $store ) => ! empty( $store['active'] ) );
    }

    /**
     * Genera un bounding box para un polígono (optimización de búsqueda)
     *
     * @param array $polygon Polígono.
     * @return array ['min_lat', 'max_lat', 'min_lng', 'max_lng'].
     */
    public function get_bounding_box( array $polygon ): array {
        if ( empty( $polygon ) ) {
            return array(
                'min_lat' => 0,
                'max_lat' => 0,
                'min_lng' => 0,
                'max_lng' => 0,
            );
        }

        $lats = array_column( $polygon, 'lat' );
        $lngs = array_column( $polygon, 'lng' );

        return array(
            'min_lat' => min( $lats ),
            'max_lat' => max( $lats ),
            'min_lng' => min( $lngs ),
            'max_lng' => max( $lngs ),
        );
    }

    /**
     * Verifica rápidamente si un punto está dentro del bounding box de un polígono
     *
     * Esta es una optimización: si el punto no está en el bounding box,
     * no necesitamos ejecutar el algoritmo Ray Casting completo.
     *
     * @param array $point   Punto a verificar.
     * @param array $polygon Polígono.
     * @return bool True si está dentro del bounding box.
     */
    public function is_in_bounding_box( array $point, array $polygon ): bool {
        $box = $this->get_bounding_box( $polygon );
        $lat = (float) $point['lat'];
        $lng = (float) $point['lng'];

        return $lat >= $box['min_lat'] && $lat <= $box['max_lat'] &&
               $lng >= $box['min_lng'] && $lng <= $box['max_lng'];
    }

    /**
     * Versión optimizada de is_point_in_polygon con bounding box check
     *
     * @param array $point   Punto a verificar.
     * @param array $polygon Polígono.
     * @return bool True si el punto está dentro del polígono.
     */
    public function is_point_in_polygon_optimized( array $point, array $polygon ): bool {
        // Primero verificar bounding box (rápido)
        if ( ! $this->is_in_bounding_box( $point, $polygon ) ) {
            return false;
        }

        // Si está en el bounding box, verificar con Ray Casting (preciso)
        return $this->is_point_in_polygon( $point, $polygon );
    }
}

/**
 * Función helper para acceder al validador de polígonos
 *
 * @return WCUDC_Polygon_Validator
 */
function wcudc_polygon_validator(): WCUDC_Polygon_Validator {
    return WCUDC_Polygon_Validator::instance();
}
