<?php
/**
 * Script para crear usuario de prueba
 *
 * Ejecutar una vez y luego eliminar el archivo.
 * URL: /wp-content/plugins/churrascoplanet-core/setup-test-user.php
 *
 * @package ChurrascoPlanet_Core
 */

// Cargar WordPress
require_once dirname(__FILE__) . '/../../../wp-load.php';

// Verificar que no esté logueado o sea admin
if (!current_user_can('manage_options') && is_user_logged_in()) {
    wp_die('No tienes permisos para ejecutar este script.');
}

// Datos del usuario de prueba
$user_data = array(
    'user_login'    => 'pedroweb',
    'user_email'    => 'momifev315@aixind.com',
    'user_pass'     => 'Pedr0123',
    'first_name'    => 'Pedro',
    'last_name'     => 'Cliente',
    'display_name'  => 'Pedro',
    'role'          => 'customer',
);

// Verificar si el usuario ya existe
$existing_user = get_user_by('login', $user_data['user_login']);
if ($existing_user) {
    echo '<h2>Usuario ya existe</h2>';
    echo '<p>El usuario <strong>pedroweb</strong> ya está registrado.</p>';
    echo '<p>ID: ' . $existing_user->ID . '</p>';
    echo '<p>Email: ' . $existing_user->user_email . '</p>';
    echo '<p><a href="' . wp_login_url() . '">Ir al login</a></p>';
    exit;
}

// Crear el usuario
$user_id = wp_insert_user($user_data);

if (is_wp_error($user_id)) {
    echo '<h2>Error al crear usuario</h2>';
    echo '<p>' . $user_id->get_error_message() . '</p>';
    exit;
}

// Agregar meta datos de WooCommerce
update_user_meta($user_id, 'billing_first_name', 'Pedro');
update_user_meta($user_id, 'billing_last_name', 'Cliente');
update_user_meta($user_id, 'billing_email', 'momifev315@aixind.com');
update_user_meta($user_id, 'billing_phone', '+56912345678');

// Agregar meta datos de ChurrascoPlanet
update_user_meta($user_id, 'chp_registration_source', 'normal');
update_user_meta($user_id, 'chp_registration_date', current_time('mysql'));
update_user_meta($user_id, 'chp_notification_email', '1');
update_user_meta($user_id, 'chp_notification_whatsapp', '0');

// Registrar en tabla de clientes si existe
if (function_exists('chp_guest_tracker')) {
    chp_guest_tracker()->upsert_customer([
        'email'              => 'momifev315@aixind.com',
        'first_name'         => 'Pedro',
        'last_name'          => 'Cliente',
        'phone'              => '+56912345678',
        'customer_type'      => 'registered',
        'user_id'            => $user_id,
        'registration_source'=> 'manual_setup',
    ]);
}

echo '<!DOCTYPE html>
<html>
<head>
    <title>Usuario de Prueba Creado</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; padding: 20px; border-radius: 8px; }
        .credentials { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .credentials p { margin: 5px 0; }
        .btn { display: inline-block; background: #C41E3A; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 15px; }
        .warning { background: #fff3cd; border: 1px solid #ffeeba; padding: 15px; border-radius: 5px; margin-top: 20px; color: #856404; }
    </style>
</head>
<body>
    <div class="success">
        <h2>Usuario de Prueba Creado Exitosamente</h2>
        <p>Se ha creado el usuario de prueba para testing del sistema de clientes.</p>
    </div>

    <div class="credentials">
        <h3>Credenciales:</h3>
        <p><strong>Usuario:</strong> pedroweb</p>
        <p><strong>Email:</strong> momifev315@aixind.com</p>
        <p><strong>Contraseña:</strong> Pedr0123</p>
        <p><strong>ID:</strong> ' . $user_id . '</p>
    </div>

    <a href="' . wc_get_page_permalink('myaccount') . '" class="btn">Ir a Mi Cuenta</a>
    <a href="' . wp_login_url(wc_get_page_permalink('myaccount')) . '" class="btn">Iniciar Sesión</a>

    <div class="warning">
        <strong>Importante:</strong> Elimina este archivo después de usarlo por seguridad.
        <br>Ruta: <code>wp-content/plugins/churrascoplanet-core/setup-test-user.php</code>
    </div>
</body>
</html>';
