<?php
// Archivo: api/directorio_handler.php
// Descripción: Gestiona las peticiones CRUD y de lectura para el Directorio de Empleados.

// Iniciar sesión de forma segura
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/db_connect.php'; 

// Definimos la función principal para devolver una respuesta JSON y cerrar la conexión.
function sendResponse($conn, $status, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    $conn->close();
    die();
}

// 1. Verificación de Autenticación y Permisos
$isAuthenticated = isset($_SESSION['user_id']);
$userProfile = $_SESSION['perfil'] ?? 'invitado';
$isAdminGlobal = ($userProfile === 'admin_global');

if (!$isAuthenticated) {
    sendResponse($conn, 'error', 'Acceso denegado. Se requiere autenticación.', null, 401);
}


// ====================================================================
// --- LÓGICA DE LECTURA (READ) y Búsqueda/Filtro (GET) ---
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Lógica para obtener todos los empleados (el filtrado se hace en el frontend)
    $sql = "SELECT id, nombre, puesto, departamento, email, telefono, ubicacion, foto_url FROM empleados ORDER BY nombre ASC";
    
    $result = $conn->query($sql);

    if ($result) {
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        sendResponse($conn, 'success', 'Empleados obtenidos exitosamente.', $employees);
    } else {
        sendResponse($conn, 'error', 'Error al ejecutar la consulta de empleados: ' . $conn->error, null, 500);
    }
} 
// ====================================================================
// --- LÓGICA DE MODIFICACIÓN (CREATE, UPDATE, DELETE) (POST) ---
// ====================================================================
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Solo el administrador global puede realizar CRUD en el Directorio
    if (!$isAdminGlobal) {
        sendResponse($conn, 'error', 'Permiso denegado. Se requiere perfil de Administrador Global para modificar el Directorio.', null, 403);
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if ($data === null) {
        sendResponse($conn, 'error', 'Formato JSON inválido o datos faltantes.', null, 400);
    }

    $action = $data['action'] ?? null;
    $id = $data['id'] ?? null;
    $nombre = $data['nombre'] ?? null;
    $puesto = $data['puesto'] ?? null;
    $departamento = $data['departamento'] ?? null;
    $email = $data['email'] ?? null;
    $telefono = $data['telefono'] ?? null;
    $ubicacion = $data['ubicacion'] ?? null;
    $foto_url = $data['foto_url'] ?? null;

    // --- CREACIÓN (CREATE) ---
    if ($action === 'create') {
        if (!$nombre || !$puesto || !$departamento || !$email) {
            sendResponse($conn, 'error', 'Faltan campos obligatorios para crear el empleado.', null, 400);
        }

        $stmt = $conn->prepare("INSERT INTO empleados (nombre, puesto, departamento, email, telefono, ubicacion, foto_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $nombre, $puesto, $departamento, $email, $telefono, $ubicacion, $foto_url);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            sendResponse($conn, 'success', 'Empleado creado exitosamente.', ['id' => $new_id]);
        } else {
            // Verificar error de duplicado (ej. email)
            if ($conn->errno === 1062) {
                sendResponse($conn, 'error', 'Error: El correo electrónico ya está registrado.', null, 409);
            }
            sendResponse($conn, 'error', 'Error al crear el empleado: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }
    
    // --- ACTUALIZACIÓN (UPDATE) ---
    else if ($action === 'update') {
        if (!$id || !$nombre || !$puesto || !$departamento || !$email) {
            sendResponse($conn, 'error', 'Faltan campos obligatorios para la actualización.', null, 400);
        }

        $stmt = $conn->prepare("UPDATE empleados SET nombre = ?, puesto = ?, departamento = ?, email = ?, telefono = ?, ubicacion = ?, foto_url = ? WHERE id = ?");
        $stmt->bind_param("sssssssi", $nombre, $puesto, $departamento, $email, $telefono, $ubicacion, $foto_url, $id);

        if ($stmt->execute()) {
            sendResponse($conn, 'success', 'Empleado actualizado exitosamente.', ['id' => $id]);
        } else {
            sendResponse($conn, 'error', 'Error al actualizar el empleado: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }

    // --- ELIMINACIÓN (DELETE) ---
    else if ($action === 'delete') {
        if (!$id) {
            sendResponse($conn, 'error', 'ID del empleado es requerido para eliminar.', null, 400);
        }

        $stmt = $conn->prepare("DELETE FROM empleados WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
             if ($stmt->affected_rows > 0) {
                 sendResponse($conn, 'success', 'Empleado eliminado exitosamente.', ['id' => $id]);
            } else {
                 sendResponse($conn, 'error', 'No se encontró el empleado con el ID proporcionado.', null, 404);
            }
        } else {
            sendResponse($conn, 'error', 'Error al eliminar el empleado: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    } 

    // --- ACCIÓN INVÁLIDA ---
    else {
        sendResponse($conn, 'error', 'Petición no válida o acción no reconocida.', null, 405);
    }
}
?>

