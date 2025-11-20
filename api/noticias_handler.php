<?php
// Archivo: api/noticias_handler.php
// Descripción: Gestiona las peticiones CRUD para las Noticias Internas.
// Requiere sesión de usuario iniciada. Las acciones de modificación requieren perfil 'admin_global'.

header('Content-Type: application/json');

// Incluimos la conexión a la base de datos y verificamos la sesión
require_once __DIR__ . '/db_connect.php';

// Iniciamos la sesión si aún no está iniciada (necesario para la autenticación)
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
    $conn->close();
    die();
}

// Variables de sesión
$user_id = $_SESSION['user_id'] ?? null;
$user_profile = $_SESSION['perfil'] ?? 'invitado'; // Aseguramos que tenga un valor por defecto

// 1. Verificación de Autenticación
if (!$user_id) {
    sendResponse($conn, 'error', 'Acceso denegado. Se requiere autenticación.', null, 401);
}

// Leemos el contenido de la petición (POST) o parámetros GET
$method = $_SERVER['REQUEST_METHOD'];

// ====================================================================
// --- LÓGICA DE LECTURA (READ) ---
// ====================================================================
if ($method === 'GET') {
    $id = $_GET['id'] ?? null;

    // --- Obtener Detalle de una Noticia ---
    if ($id) {
        $stmt = $conn->prepare("SELECT id, titulo, extracto, contenido_html, imagen_url, categoria, fecha_publicacion FROM noticias WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $noticia = $result->fetch_assoc();
            sendResponse($conn, 'success', 'Detalle de noticia obtenido.', $noticia);
        } else {
            sendResponse($conn, 'error', 'Noticia no encontrada.', null, 404);
        }
        $stmt->close();
    } 
    // --- Obtener Lista de Noticias ---
    else {
        // Obtenemos un parámetro de límite opcional para el dashboard (por defecto 10)
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        
        // La consulta base para la lista. Solo necesitamos el extracto para la vista de lista.
        $sql = "SELECT id, titulo, extracto, imagen_url, categoria, fecha_publicacion FROM noticias ORDER BY fecha_publicacion DESC LIMIT ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result) {
            $noticias = [];
            while ($row = $result->fetch_assoc()) {
                $noticias[] = $row;
            }
            sendResponse($conn, 'success', 'Lista de noticias obtenida.', $noticias);
        } else {
            sendResponse($conn, 'error', 'Error al consultar la base de datos.', null, 500);
        }
        $stmt->close();
    }
} 
// ====================================================================
// --- LÓGICA DE MODIFICACIÓN (CREATE, UPDATE, DELETE) ---
// ====================================================================
else if ($method === 'POST') {
    
    // 2. Verificar Permiso (Solo 'admin_global' puede modificar)
    if ($user_profile !== 'admin_global') {
        sendResponse($conn, 'error', 'Permiso denegado. Solo Administradores Globales pueden gestionar noticias.', null, 403);
    }

    // Leemos y decodificamos el JSON
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if ($data === null) {
        sendResponse($conn, 'error', 'Formato JSON inválido o datos faltantes.', null, 400);
    }

    $action = $data['action'] ?? null;
    $id = $data['id'] ?? null;
    $titulo = $data['titulo'] ?? null;
    $extracto = $data['extracto'] ?? null;
    $contenido_html = $data['contenido_html'] ?? null;
    $imagen_url = $data['imagen_url'] ?? null;
    $categoria = $data['categoria'] ?? 'General';

    // --- CREACIÓN (CREATE) ---
    if ($action === 'create') {
        if (!$titulo || !$extracto || !$contenido_html) {
            sendResponse($conn, 'error', 'Faltan campos requeridos (título, extracto o contenido).', null, 400);
        }

        $stmt = $conn->prepare("INSERT INTO noticias (titulo, extracto, contenido_html, imagen_url, categoria) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $titulo, $extracto, $contenido_html, $imagen_url, $categoria);

        if ($stmt->execute()) {
            sendResponse($conn, 'success', 'Noticia creada exitosamente.', ['id' => $stmt->insert_id]);
        } else {
            sendResponse($conn, 'error', 'Error al crear la noticia: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }
    
    // --- ACTUALIZACIÓN (UPDATE) ---
    else if ($action === 'update') {
        if (!$id || !$titulo || !$extracto || !$contenido_html) {
            sendResponse($conn, 'error', 'Faltan campos requeridos para la actualización.', null, 400);
        }

        $stmt = $conn->prepare("UPDATE noticias SET titulo = ?, extracto = ?, contenido_html = ?, imagen_url = ?, categoria = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $titulo, $extracto, $contenido_html, $imagen_url, $categoria, $id);

        if ($stmt->execute()) {
             if ($stmt->affected_rows > 0) {
                 sendResponse($conn, 'success', 'Noticia actualizada exitosamente.', ['id' => $id]);
            } else {
                 sendResponse($conn, 'success', 'Noticia actualizada exitosamente (o no se encontraron cambios).', ['id' => $id]);
            }
        } else {
            sendResponse($conn, 'error', 'Error al actualizar la noticia: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    }

    // --- ELIMINACIÓN (DELETE) ---
    else if ($action === 'delete') {
        if (!$id) {
            sendResponse($conn, 'error', 'ID de la noticia es requerido para eliminar.', null, 400);
        }

        $stmt = $conn->prepare("DELETE FROM noticias WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
             if ($stmt->affected_rows > 0) {
                 sendResponse($conn, 'success', 'Noticia eliminada exitosamente.', ['id' => $id]);
            } else {
                 sendResponse($conn, 'error', 'No se encontró la noticia con el ID proporcionado.', null, 404);
            }
        } else {
            sendResponse($conn, 'error', 'Error al eliminar la noticia: ' . $stmt->error, null, 500);
        }
        $stmt->close();
    } 

    // --- ACCIÓN INVÁLIDA ---
    else {
        sendResponse($conn, 'error', 'Acción no válida o reconocida.', null, 405);
    }
}
// --- MÉTODO NO SOPORTADO ---
else {
    sendResponse($conn, 'error', 'Método no soportado.', null, 405);
}
?>
