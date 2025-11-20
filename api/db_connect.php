<?php
// Archivo: api/db_connect.php
// Descripción: Script reutilizable para establecer la conexión con la base de datos.

// Incluimos el archivo de configuración. 'require_once' se asegura de que se incluya
// una sola vez y detiene la ejecución si el archivo no se encuentra.
// Usamos '__DIR__ . /../' para navegar un nivel hacia arriba desde /api/ y encontrar config.php
require_once __DIR__ . '/../config.php';

// Intentamos crear una nueva conexión a la base de datos usando las constantes de config.php
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Verificación de la conexión: Es una práctica de seguridad fundamental.
if ($conn->connect_error) {
    // Si la conexión falla, detenemos todo y enviamos una respuesta de error genérica.
    // Esto evita exponer detalles sensibles del servidor.
    header('Content-Type: application/json');
    http_response_code(500); // Código de error del servidor
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión con la base de datos.']);
    die(); // Detiene la ejecución del script.
}

// Establecemos el conjunto de caracteres a utf8mb4 para soportar acentos y caracteres especiales.
$conn->set_charset("utf8mb4");

// Si todo salió bien, la variable $conn está lista para ser usada por otros scripts.
