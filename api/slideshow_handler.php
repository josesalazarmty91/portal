<?php
// Archivo: api/slideshow_handler.php
// Descripción: Gestiona las peticiones relacionadas con los slides (CRUD) con protección de sesión.

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php'; 

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

// ====================================================================
// --- VERIFICACIÓN DE PERFIL ---
// ====================================================================
$current_profile = $_SESSION['perfil'] ?? 'invitado'; // CORREGIDO: Usar 'perfil'
$can_modify = in_array($current_profile, ['admin_global', 'diseno']);
$method = $_SERVER['REQUEST_METHOD'];

// ====================================================================
// --- LÓGICA DE LECTURA (READ) ---
// ====================================================================
if ($method === 'GET') {
    $sql = "SELECT id, titulo, descripcion, imagen_url, fecha_creacion FROM slideshow ORDER BY id DESC";
    $result = $conn->query($sql);

    if ($result) {
        $slides = [];
        while ($row = $result->fetch_assoc()) {
            $slides[] = $row;
        }
        sendResponse($conn, 'success', 'Slides obtenidos exitosamente.', $slides);
    } else {
        sendResponse($conn, 'error', 'Error al ejecutar la consulta SELECT en la base de datos.', null, 500);
    }
} 
// ====================================================================
// --- LÓGICA DE MODIFICACIÓN (CREATE, UPDATE, DELETE) ---
// ====================================================================
else {
    if (!$can_modify) {
        sendResponse($conn, 'error', 'Acceso denegado. Se requiere el perfil de Administrador Global o Diseño.', null, 403);
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if ($data === null) {
        sendResponse($conn, 'error', 'Formato JSON inválido o datos faltantes.', null, 400);
    }

    $action = $data['action'] ?? null;
    $id = $data['id'] ?? null;
    $titulo = $data['title'] ?? null;
    $descripcion = $data['description'] ?? null;
    $imagen_url = $data['image-url'] ?? null;

    // --- CREACIÓN (CREATE) ---
    if ($action === 'create') {
        if (!$titulo || !$descripcion || !$imagen_url) {
            sendResponse($conn, 'error', 'Faltan campos requeridos (título, descripción o URL de imagen).', null, 400);
        }
        $stmt = $conn->prepare("INSERT INTO slideshow (titulo, descripcion, imagen_url) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $titulo, $descripcion, $imagen_url);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            sendResponse($conn, 'success', 'Slide creado exitosamente.', ['id' => $new_id]);
        } else {
            sendResponse($conn, 'error', 'Error al crear el slide: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }
    
    // --- ACTUALIZACIÓN (UPDATE) ---
    else if ($action === 'update') {
        if (!$id || !$titulo || !$descripcion || !$imagen_url) {
            sendResponse($conn, 'error', 'Faltan campos requeridos para la actualización.', null, 400);
        }
        $stmt = $conn->prepare("UPDATE slideshow SET titulo = ?, descripcion = ?, imagen_url = ? WHERE id = ?");
        $stmt->bind_param("sssi", $titulo, $descripcion, $imagen_url, $id);

        if ($stmt->execute()) {
            sendResponse($conn, 'success', 'Slide actualizado exitosamente.', ['id' => $id]);
        } else {
            sendResponse($conn, 'error', 'Error al actualizar el slide: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }

    // --- ELIMINACIÓN (DELETE) ---
    else if ($action === 'delete') {
        if (!$id) {
            sendResponse($conn, 'error', 'ID del slide es requerido para eliminar.', null, 400);
        }
        $stmt = $conn->prepare("DELETE FROM slideshow WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
             if ($stmt->affected_rows > 0) {
                 sendResponse($conn, 'success', 'Slide eliminado exitosamente.', ['id' => $id]);
            } else {
                 sendResponse($conn, 'error', 'No se encontró el slide con el ID proporcionado.', null, 404);
            }
        } else {
            sendResponse($conn, 'error', 'Error al eliminar el slide: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    } 

    else {
        sendResponse($conn, 'error', 'Petición no válida o acción no reconocida.', null, 405);
    }
}
?>

