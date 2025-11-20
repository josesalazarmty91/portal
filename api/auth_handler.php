<?php
// Archivo: api/auth_handler.php
// Descripción: Gestiona las peticiones de autenticación y CRUD de perfil.

header('Content-Type: application/json');

// Incluimos la conexión a la base de datos
require_once __DIR__ . '/db_connect.php';

// Iniciamos la sesión si aún no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Función auxiliar para enviar una respuesta JSON y cerrar la conexión
function sendResponse($conn, $status, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    if ($conn) $conn->close();
    die();
}

// Variables de sesión
$user_id = $_SESSION['user_id'] ?? null;
$user_perfil = $_SESSION['perfil'] ?? null; // Variable corregida para consistencia

// Obtenemos el método de la petición HTTP
$method = $_SERVER['REQUEST_METHOD'];

// ====================================================================
// --- LÓGICA DE LECTURA (GET) ---
// Maneja acciones que consultan el estado o el perfil.
// ====================================================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? null;

    // --- ACCIÓN: check_session ---
    if ($action === 'check_session') {
        if ($user_id) {
            // Obtener datos completos del usuario para el frontend
            $stmt = $conn->prepare("SELECT id, nombre, email, perfil, foto_url FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user_data = $result->fetch_assoc();
                sendResponse($conn, 'success', 'Sesión activa.', $user_data);
            } else {
                // Inconsistencia: Destruir sesión si el usuario no existe en DB
                session_destroy();
                sendResponse($conn, 'error', 'Sesión inactiva. Usuario no encontrado.', null, 401);
            }
            $stmt->close();
        } else {
            sendResponse($conn, 'error', 'Sesión inactiva.', null, 401);
        }
    }
    
    // --- ACCIÓN: get_profile ---
    else if ($action === 'get_profile') {
        if (!$user_id) {
            sendResponse($conn, 'error', 'Acceso denegado. Se requiere autenticación.', null, 401);
        }
        
        $stmt = $conn->prepare("SELECT id, nombre, email, perfil, foto_url FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user_data = $result->fetch_assoc();
            sendResponse($conn, 'success', 'Datos de perfil obtenidos.', $user_data);
        } else {
            sendResponse($conn, 'error', 'Usuario no encontrado.', null, 404);
        }
        $stmt->close();
    }
    else {
        sendResponse($conn, 'error', 'Acción GET no reconocida.', null, 400);
    }
} 
// ====================================================================
// --- LÓGICA DE ESCRITURA (POST) ---
// Maneja login, logout y actualizaciones de perfil.
// ====================================================================
else if ($method === 'POST') {
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if ($data === null) {
        sendResponse($conn, 'error', 'Formato JSON inválido o datos faltantes.', null, 400);
    }

    $action = $data['action'] ?? null;

    // --- ACCIÓN: login ---
    if ($action === 'login') {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            sendResponse($conn, 'error', 'Correo y contraseña son requeridos.', null, 400);
        }

        $stmt = $conn->prepare("SELECT id, nombre, perfil, password_hash, foto_url FROM usuarios WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password_hash'])) {
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['perfil'] = $user['perfil']; // Usamos 'perfil' consistentemente

                sendResponse($conn, 'success', 'Inicio de sesión exitoso.', [
                    'id' => $user['id'], 
                    'nombre' => $user['nombre'], 
                    'perfil' => $user['perfil']
                ]);
            } else {
                sendResponse($conn, 'error', 'Credenciales incorrectas.', null, 401);
            }
        } else {
            sendResponse($conn, 'error', 'Credenciales incorrectas.', null, 401);
        }
        $stmt->close();
    }

    // --- ACCIÓN: logout ---
    else if ($action === 'logout') {
        session_destroy();
        sendResponse($conn, 'success', 'Sesión cerrada exitosamente.');
    }
    
    // --- ACCIÓN: update_profile ---
    else if ($action === 'update_profile') {
        if (!$user_id) { sendResponse($conn, 'error', 'No autenticado.', null, 401); }
        
        $nombre = $data['nombre'] ?? '';
        $foto_url = $data['foto_url'] ?? null;

        $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, foto_url = ? WHERE id = ?");
        $stmt->bind_param("ssi", $nombre, $foto_url, $user_id);

        if ($stmt->execute()) {
             if ($stmt->affected_rows > 0) {
                sendResponse($conn, 'success', 'Perfil actualizado exitosamente.');
            } else {
                 sendResponse($conn, 'success', 'Perfil actualizado exitosamente (sin cambios detectados).');
            }
        } else {
            sendResponse($conn, 'error', 'Error al actualizar el perfil: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }
    
    // --- ACCIÓN: change_password ---
    else if ($action === 'change_password') {
        if (!$user_id) { sendResponse($conn, 'error', 'No autenticado.', null, 401); }
        
        $current_password = $data['current_password'] ?? '';
        $new_password = $data['new_password'] ?? '';

        if (empty($current_password) || empty($new_password)) {
             sendResponse($conn, 'error', 'Todos los campos de contraseña son requeridos.', null, 400);
        }
        
        $stmt = $conn->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($current_password, $user['password_hash'])) {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
            $stmt->bind_param("si", $new_password_hash, $user_id);
            
            if ($stmt->execute()) {
                sendResponse($conn, 'success', 'Contraseña cambiada exitosamente.');
            } else {
                sendResponse($conn, 'error', 'Error al cambiar la contraseña: ' . $stmt->error, null, 500);
            }
            $stmt->close();
        } else {
             sendResponse($conn, 'error', 'Contraseña actual incorrecta.', null, 401);
        }
    }

    else {
        sendResponse($conn, 'error', 'Acción POST no válida o reconocida.', null, 405);
    }
} 
else {
    sendResponse($conn, 'error', 'Método HTTP no soportado.', null, 405);
}
?>

