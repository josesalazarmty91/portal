<?php
// Archivo: api/auth_handler.php
// Descripción: Gestiona el inicio y cierre de sesión de usuarios.

// Iniciar sesión para usar $_SESSION
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/db_connect.php';

// Definimos la función para enviar respuestas JSON
function sendResponse($conn, $status, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    // NOTA: No cerramos la conexión aquí, la cerramos en la función principal
    echo json_encode($response);
    $conn->close();
    die();
}

// Leemos el contenido de la petición (debe ser JSON)
$input = file_get_contents("php://input");
$data = json_decode($input, true);

$action = $data['action'] ?? null;

// ====================================================================
// --- LÓGICA DE LOGIN ---
// ====================================================================
if ($action === 'login') {
    $email = $data['email'] ?? null;
    $password = $data['password'] ?? null;

    if (empty($email) || empty($password)) {
        sendResponse($conn, 'error', 'Email y contraseña son requeridos.', null, 400);
    }

    // 1. Preparar la consulta
    // Usamos prepared statements para prevenir inyección SQL
    $stmt = $conn->prepare("SELECT id, nombre, email, password_hash, perfil FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $stmt->close();
        
        // 2. Verificar la contraseña
        if (password_verify($password, $user['password_hash'])) {
            
            // 3. Crear la sesión
            // Guardamos solo la información no sensible y relevante para el frontend
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['nombre'];
            $_SESSION['user_profile'] = $user['perfil']; // admin_global, diseno, usuario, invitado
            
            // 4. Respuesta exitosa
            sendResponse($conn, 'success', 'Inicio de sesión exitoso.', [
                'name' => $user['nombre'],
                'profile' => $user['perfil']
            ]);
            
        } else {
            sendResponse($conn, 'error', 'Credenciales inválidas (contraseña).', null, 401);
        }
    } else {
        // En un entorno real, es mejor dar un mensaje genérico para no dar pistas
        sendResponse($conn, 'error', 'Credenciales inválidas (email no encontrado).', null, 401);
    }
} 
// ====================================================================
// --- LÓGICA DE LOGOUT ---
// ====================================================================
else if ($action === 'logout') {
    // Destruir la sesión
    session_unset(); // Elimina las variables de sesión
    session_destroy(); // Destruye la sesión
    
    sendResponse($conn, 'success', 'Sesión cerrada exitosamente.');
} 
// ====================================================================
// --- LÓGICA PARA VERIFICAR SESIÓN (útil para recargar la página) ---
// ====================================================================
else if ($action === 'check_session') {
    if (isset($_SESSION['user_id'])) {
        sendResponse($conn, 'success', 'Sesión activa.', [
            'name' => $_SESSION['user_name'],
            'profile' => $_SESSION['user_profile']
        ]);
    } else {
        sendResponse($conn, 'error', 'No hay sesión activa.', null, 401);
    }
}
// ====================================================================
// --- ACCIÓN NO VÁLIDA ---
// ====================================================================
else {
    sendResponse($conn, 'error', 'Acción no válida.', null, 400);
}

// Cerramos la conexión (aunque la función sendResponse ya lo hace, es una buena práctica)
$conn->close();
?>
