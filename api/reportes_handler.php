<?php
// Archivo: api/reportes_handler.php
// Descripción: Gestiona el CRUD y la visualización de reportes con control de acceso por perfil.

// INICIO DE SESIÓN SEGURO
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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

// Variables de sesión
$user_id = $_SESSION['user_id'] ?? null;
$user_profile = $_SESSION['perfil'] ?? 'invitado';
$isAdmin = $user_profile === 'admin_global';
$method = $_SERVER['REQUEST_METHOD'];

// 1. Verificación de Autenticación
// Si el usuario no está logueado, no debe acceder a ninguna funcionalidad de reportes.
if (!$user_id) {
    sendResponse($conn, 'error', 'Acceso denegado. Se requiere autenticación.', null, 401);
}

// --- LECTURA (GET) ---
if ($method === 'GET') {
    // Si es admin, puede ver todos los reportes. Si no, filtramos por perfil.
    if ($isAdmin) {
        $sql = "SELECT id, nombre, url, descripcion, categoria, allowed_profiles FROM reportes ORDER BY categoria, nombre";
        $stmt = $conn->prepare($sql);
    } else {
        // JSON_CONTAINS busca un valor dentro de un array/objeto JSON (requiere MySQL 5.7+ o equivalente)
        // Buscamos perfiles que contengan el perfil del usuario logueado.
        $sql = "SELECT id, nombre, url, descripcion, categoria, allowed_profiles FROM reportes WHERE JSON_CONTAINS(allowed_profiles, ?)";
        $stmt = $conn->prepare($sql);
        
        // El perfil debe ser codificado como JSON para la función JSON_CONTAINS
        $profile_json = json_encode($user_profile);
        
        $stmt->bind_param("s", $profile_json);
    }

    if ($stmt === false) {
         sendResponse($conn, 'error', 'Error al preparar la consulta: ' . $conn->error, null, 500);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    $reports_by_category = [];
    while ($row = $result->fetch_assoc()) {
        // Para usuarios no admin, no es necesario enviar los perfiles permitidos en la data final
        if (!$isAdmin) {
            unset($row['allowed_profiles']);
        } else {
            // Decodificar los perfiles para que el frontend los pueda usar directamente para editar
            $row['allowed_profiles'] = json_decode($row['allowed_profiles'], true);
        }
        $reports_by_category[$row['categoria']][] = $row;
    }

    sendResponse($conn, 'success', 'Reportes obtenidos exitosamente.', $reports_by_category);
    $stmt->close();
}

// --- MODIFICACIÓN (POST) ---
if ($method === 'POST') {
    if (!$isAdmin) {
        sendResponse($conn, 'error', 'Acceso denegado. Se requiere perfil de Administrador Global.', null, 403);
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    if (!$data) {
        sendResponse($conn, 'error', 'Datos JSON inválidos o datos faltantes.', null, 400);
    }

    $action = $data['action'] ?? null;
    $id = $data['id'] ?? null;

    switch ($action) {
        case 'create':
        case 'update':
            $nombre = $data['nombre'] ?? null;
            $url = $data['url'] ?? null;
            $categoria = $data['categoria'] ?? 'General';
            $descripcion = $data['descripcion'] ?? '';
            
            // Aseguramos que allowed_profiles sea un array y luego lo codificamos a JSON
            $allowed_profiles_array = is_array($data['allowed_profiles'] ?? []) ? $data['allowed_profiles'] : [];
            $allowed_profiles = json_encode($allowed_profiles_array);

            if (!$nombre || !$url || !$categoria) {
                sendResponse($conn, 'error', 'Nombre, URL y categoría son campos requeridos.', null, 400);
            }

            if ($action === 'create') {
                $stmt = $conn->prepare("INSERT INTO reportes (nombre, url, descripcion, categoria, allowed_profiles) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $nombre, $url, $descripcion, $categoria, $allowed_profiles);
            } else { // update
                $stmt = $conn->prepare("UPDATE reportes SET nombre = ?, url = ?, descripcion = ?, categoria = ?, allowed_profiles = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $nombre, $url, $descripcion, $categoria, $allowed_profiles, $id);
            }

            if ($stmt->execute()) {
                sendResponse($conn, 'success', 'Reporte guardado exitosamente.');
            } else {
                sendResponse($conn, 'error', 'Error al guardar el reporte: ' . $stmt->error, null, 500);
            }
            $stmt->close();
            break;

        case 'delete':
            if (!$id) {
                sendResponse($conn, 'error', 'ID del reporte es requerido.', null, 400);
            }
            $stmt = $conn->prepare("DELETE FROM reportes WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                sendResponse($conn, 'success', 'Reporte eliminado exitosamente.');
            } else {
                sendResponse($conn, 'error', 'Error al eliminar el reporte.', null, 500);
            }
            $stmt->close();
            break;

        default:
            sendResponse($conn, 'error', 'Acción no reconocida.', null, 400);
            break;
    }
}
?>
