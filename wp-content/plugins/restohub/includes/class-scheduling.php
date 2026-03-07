<?php
/**
 * Servicio de Programación de Entregas/Retiros
 *
 * Clase de servicio puro (sin hooks propios) que maneja la lógica
 * de generación de slots, validación y cálculo de timestamps
 * para programar entregas y retiros.
 *
 * @package RestoHub
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase RestoHub_Scheduling
 */
class RestoHub_Scheduling {

    /**
     * Instancia singleton
     *
     * @var RestoHub_Scheduling|null
     */
    private static ?RestoHub_Scheduling $instance = null;

    /**
     * Settings cacheados
     *
     * @var array|null
     */
    private ?array $settings = null;

    /**
     * Mapa de día de la semana (PHP date('w')) a clave en store_hours
     *
     * @var array
     */
    private const DAY_MAP = array(
        1 => 'lunes',
        2 => 'martes',
        3 => 'miercoles',
        4 => 'jueves',
        5 => 'viernes',
        6 => 'sabado',
        0 => 'domingo',
    );

    /**
     * Nombres de días para display
     *
     * @var array
     */
    private const DAY_NAMES = array(
        'lunes'     => 'Lunes',
        'martes'    => 'Martes',
        'miercoles' => 'Miércoles',
        'jueves'    => 'Jueves',
        'viernes'   => 'Viernes',
        'sabado'    => 'Sábado',
        'domingo'   => 'Domingo',
    );

    /**
     * Constructor privado (singleton)
     */
    private function __construct() {}

    /**
     * Obtiene la instancia singleton
     *
     * @return RestoHub_Scheduling
     */
    public static function instance(): RestoHub_Scheduling {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtiene los settings del plugin (con cache)
     *
     * @return array
     */
    private function get_settings(): array {
        if ( is_null( $this->settings ) ) {
            $this->settings = get_option( 'restohub_settings', array() );
        }
        return $this->settings;
    }

    /**
     * Verifica si la programación está habilitada
     *
     * @return bool
     */
    public function is_enabled(): bool {
        $settings = $this->get_settings();
        // Default true
        return ! isset( $settings['scheduling_enabled'] ) || ! empty( $settings['scheduling_enabled'] );
    }

    /**
     * Verifica si la tienda está actualmente abierta
     *
     * Soporta special_hours con prioridad sobre store_hours regulares.
     *
     * @return bool
     */
    public function is_store_currently_open(): bool {
        $today = current_time( 'Y-m-d' );
        $hours = $this->get_hours_for_date( $today );

        if ( ! empty( $hours['closed'] ) ) {
            return false;
        }

        $current_time = current_time( 'H:i' );
        $open  = $hours['open'] ?? '00:00';
        $close = $hours['close'] ?? '23:59';

        return ( $current_time >= $open && $current_time <= $close );
    }

    /**
     * Obtiene los horarios para una fecha específica
     *
     * Prioridad: special_hours (match por fecha, repeat_yearly) > store_hours regulares
     *
     * @param string $date Fecha en formato Y-m-d.
     * @return array {open, close, closed, is_special, name}
     */
    public function get_hours_for_date( string $date ): array {
        $settings      = $this->get_settings();
        $special_hours = $settings['special_hours'] ?? array();
        $store_hours   = $settings['store_hours'] ?? array();

        // 1. Buscar en special_hours
        foreach ( $special_hours as $special ) {
            $special_date = $special['date'] ?? '';

            if ( empty( $special_date ) ) {
                continue;
            }

            $matches = false;

            // Match exacto
            if ( $special_date === $date ) {
                $matches = true;
            }
            // Repeat yearly: comparar mes-día
            elseif ( ! empty( $special['repeat_yearly'] ) ) {
                $special_md = substr( $special_date, 5 ); // MM-DD
                $date_md    = substr( $date, 5 );
                if ( $special_md === $date_md ) {
                    $matches = true;
                }
            }

            if ( $matches ) {
                return array(
                    'open'       => $special['open'] ?? '09:00',
                    'close'      => $special['close'] ?? '21:00',
                    'closed'     => ! empty( $special['closed'] ),
                    'is_special' => true,
                    'name'       => $special['name'] ?? '',
                );
            }
        }

        // 2. Horario regular del día de la semana
        $timestamp = strtotime( $date );
        if ( $timestamp === false ) {
            return array(
                'open'       => '09:00',
                'close'      => '21:00',
                'closed'     => false,
                'is_special' => false,
                'name'       => '',
            );
        }

        $day_of_week = (int) date( 'w', $timestamp );
        $day_key     = self::DAY_MAP[ $day_of_week ] ?? 'lunes';
        $day_hours   = $store_hours[ $day_key ] ?? array();

        // Sin horario configurado = siempre abierto con horario default
        if ( empty( $store_hours ) ) {
            return array(
                'open'       => '09:00',
                'close'      => '21:00',
                'closed'     => false,
                'is_special' => false,
                'name'       => '',
            );
        }

        return array(
            'open'       => $day_hours['open'] ?? '09:00',
            'close'      => $day_hours['close'] ?? '21:00',
            'closed'     => ! empty( $day_hours['closed'] ),
            'is_special' => false,
            'name'       => '',
        );
    }

    /**
     * Genera los slots disponibles para una fecha
     *
     * @param string $date Fecha en formato Y-m-d.
     * @return array Array de slots [{time: 'HH:mm', label: 'HH:mm'}, ...]
     */
    public function get_available_slots( string $date ): array {
        $hours = $this->get_hours_for_date( $date );

        // Día cerrado = sin slots
        if ( ! empty( $hours['closed'] ) ) {
            return array();
        }

        $settings         = $this->get_settings();
        $slot_interval    = (int) ( $settings['scheduling_slot_interval'] ?? 30 );
        $lead_time        = (int) ( $settings['scheduling_lead_time'] ?? 45 );
        $prep_buffer      = (int) ( $settings['scheduling_prep_buffer'] ?? 15 );

        $open_time  = $hours['open'] ?? '09:00';
        $close_time = $hours['close'] ?? '21:00';

        // Generar slots desde open hasta (close - prep_buffer)
        $open_minutes  = $this->time_to_minutes( $open_time );
        $close_minutes = $this->time_to_minutes( $close_time ) - $prep_buffer;

        if ( $close_minutes <= $open_minutes ) {
            return array();
        }

        $slots = array();
        $today = current_time( 'Y-m-d' );
        $is_today = ( $date === $today );
        $current_minutes = $is_today ? $this->time_to_minutes( current_time( 'H:i' ) ) : 0;

        // Si es hoy, el slot mínimo es ahora + lead_time
        $min_slot_minutes = $is_today ? $current_minutes + $lead_time : 0;

        for ( $minutes = $open_minutes; $minutes <= $close_minutes; $minutes += $slot_interval ) {
            // Si es hoy, filtrar slots pasados y aplicar lead_time
            if ( $is_today && $minutes < $min_slot_minutes ) {
                continue;
            }

            $time_str = $this->minutes_to_time( $minutes );
            $slots[] = array(
                'time'  => $time_str,
                'label' => $time_str,
            );
        }

        return $slots;
    }

    /**
     * Obtiene las fechas programables con sus slots
     *
     * @return array [{date, label, day_name, slots}, ...]
     */
    public function get_schedulable_dates(): array {
        $settings = $this->get_settings();
        $max_days = (int) ( $settings['scheduling_max_days'] ?? 1 );

        $dates = array();
        $today = current_time( 'Y-m-d' );

        for ( $i = 0; $i <= $max_days; $i++ ) {
            $date = date( 'Y-m-d', strtotime( $today . ' +' . $i . ' days' ) );
            $slots = $this->get_available_slots( $date );

            $timestamp    = strtotime( $date );
            $day_of_week  = (int) date( 'w', $timestamp );
            $day_key      = self::DAY_MAP[ $day_of_week ] ?? 'lunes';
            $day_name     = self::DAY_NAMES[ $day_key ] ?? '';

            if ( $i === 0 ) {
                $label = __( 'Hoy', 'restohub' );
            } elseif ( $i === 1 ) {
                $label = __( 'Mañana', 'restohub' );
            } else {
                $label = $day_name;
            }

            $hours = $this->get_hours_for_date( $date );

            $dates[] = array(
                'date'     => $date,
                'label'    => $label,
                'day_name' => $day_name,
                'slots'    => $slots,
                'closed'   => ! empty( $hours['closed'] ),
                'special'  => ! empty( $hours['is_special'] ) ? $hours['name'] : '',
            );
        }

        return $dates;
    }

    /**
     * Determina el modo de programación
     *
     * @return string 'both' (tienda abierta) | 'schedule_only' (tienda cerrada)
     */
    public function get_scheduling_mode(): string {
        return $this->is_store_currently_open() ? 'both' : 'schedule_only';
    }

    /**
     * Valida un horario programado
     *
     * @param string $date Fecha en formato Y-m-d.
     * @param string $time Hora en formato HH:mm.
     * @return true|WP_Error
     */
    public function validate_scheduled_time( string $date, string $time ) {
        $settings  = $this->get_settings();
        $max_days  = (int) ( $settings['scheduling_max_days'] ?? 1 );
        $lead_time = (int) ( $settings['scheduling_lead_time'] ?? 45 );

        $today = current_time( 'Y-m-d' );

        // Validar que la fecha sea hoy o dentro del rango permitido
        $date_diff = ( strtotime( $date ) - strtotime( $today ) ) / 86400;
        if ( $date_diff < 0 || $date_diff > $max_days ) {
            return new WP_Error(
                'invalid_date',
                __( 'La fecha seleccionada no está disponible para programar.', 'restohub' )
            );
        }

        // Validar que el día no esté cerrado
        $hours = $this->get_hours_for_date( $date );
        if ( ! empty( $hours['closed'] ) ) {
            return new WP_Error(
                'store_closed',
                __( 'La tienda está cerrada en la fecha seleccionada.', 'restohub' )
            );
        }

        // Validar que la hora esté dentro del horario
        $open  = $hours['open'] ?? '00:00';
        $close = $hours['close'] ?? '23:59';

        if ( $time < $open || $time > $close ) {
            return new WP_Error(
                'outside_hours',
                __( 'La hora seleccionada está fuera del horario de atención.', 'restohub' )
            );
        }

        // Si es hoy, validar que la hora sea futuro + lead_time
        if ( $date === $today ) {
            $current_minutes = $this->time_to_minutes( current_time( 'H:i' ) );
            $slot_minutes    = $this->time_to_minutes( $time );

            if ( $slot_minutes < $current_minutes + $lead_time ) {
                return new WP_Error(
                    'too_soon',
                    sprintf(
                        /* translators: %d: minutes */
                        __( 'Debes programar con al menos %d minutos de anticipación.', 'restohub' ),
                        $lead_time
                    )
                );
            }
        }

        return true;
    }

    /**
     * Calcula el timestamp para disparar la acción de Uber
     *
     * Para pedidos programados: timestamp del horario - prep_buffer minutos
     * Para pedidos ASAP: time()
     *
     * @param string|null $date Fecha del schedule (null = ASAP).
     * @param string|null $time Hora del schedule (null = ASAP).
     * @return int Unix timestamp
     */
    public function get_uber_trigger_timestamp( ?string $date, ?string $time ): int {
        if ( empty( $date ) || empty( $time ) ) {
            return time();
        }

        $settings    = $this->get_settings();
        $prep_buffer = (int) ( $settings['scheduling_prep_buffer'] ?? 15 );

        // Construir timestamp del slot en la timezone de WordPress
        $wp_timezone    = wp_timezone();
        $scheduled_dt   = new \DateTime( $date . ' ' . $time . ':00', $wp_timezone );
        $scheduled_unix = $scheduled_dt->getTimestamp();

        // Restar prep_buffer para disparar antes
        $trigger_time = $scheduled_unix - ( $prep_buffer * 60 );

        // No disparar en el pasado
        if ( $trigger_time < time() ) {
            return time();
        }

        return $trigger_time;
    }

    /**
     * Genera el pickup_ready_dt en formato RFC 3339 para la API de Uber
     *
     * @param string $date Fecha en formato Y-m-d.
     * @param string $time Hora en formato HH:mm.
     * @return string RFC 3339 con timezone
     */
    public function get_pickup_ready_dt( string $date, string $time ): string {
        $wp_timezone  = wp_timezone();
        $scheduled_dt = new \DateTime( $date . ' ' . $time . ':00', $wp_timezone );

        return $scheduled_dt->format( 'c' ); // ISO 8601 / RFC 3339
    }

    /**
     * Convierte HH:mm a minutos desde medianoche
     *
     * @param string $time Hora en formato HH:mm.
     * @return int Minutos.
     */
    private function time_to_minutes( string $time ): int {
        $parts = explode( ':', $time );
        return ( (int) $parts[0] * 60 ) + (int) ( $parts[1] ?? 0 );
    }

    /**
     * Convierte minutos desde medianoche a HH:mm
     *
     * @param int $minutes Minutos.
     * @return string Hora en formato HH:mm.
     */
    private function minutes_to_time( int $minutes ): string {
        $hours = intdiv( $minutes, 60 );
        $mins  = $minutes % 60;
        return sprintf( '%02d:%02d', $hours, $mins );
    }
}

/**
 * Helper global para acceder al servicio de scheduling
 *
 * @return RestoHub_Scheduling
 */
function restohub_scheduling(): RestoHub_Scheduling {
    return RestoHub_Scheduling::instance();
}
