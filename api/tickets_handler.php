<?php
// Archivo: api/tickets_handler.php
// Descripción: Gestiona las peticiones CRUD y de lectura para la Mesa de Ayuda (Tickets).
// Los usuarios pueden crear, ver y comentar sus propios tickets.
// Los administradores (perfil 'admin_global') pueden ver, actualizar estado/departamento de todos los tickets.

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

// 1. Verificación de Autenticación
$user_id = $_SESSION['user_id'] ?? null;
$user_profile = $_SESSION['perfil'] ?? 'invitado';
$isAdmin = ($user_profile === 'admin_global');
$method = $_SERVER['REQUEST_METHOD'];

if (!$user_id) {
    sendResponse($conn, 'error', 'Acceso denegado. Se requiere autenticación.', null, 401);
}

// ====================================================================
// --- LÓGICA DE LECTURA (GET) ---
// ====================================================================
if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? null;

    // --- Obtener Respuestas de un Ticket ---
    if ($action === 'replies' && $id) {
        $sql = "SELECT tr.id, tr.mensaje, tr.fecha_respuesta, u.nombre, u.foto_url, u.perfil 
                FROM ticket_replies tr
                JOIN usuarios u ON tr.user_id = u.id
                WHERE tr.ticket_id = ?
                ORDER BY tr.fecha_respuesta ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $replies = [];
        while ($row = $result->fetch_assoc()) {
            $replies[] = $row;
        }
        $stmt->close();
        sendResponse($conn, 'success', 'Respuestas obtenidas.', $replies);
    }
    
    // --- Obtener Detalle de un Ticket ---
    else if ($id) {
        // Consulta base para el detalle del ticket, incluyendo el nombre del creador
        $sql = "SELECT t.*, u.nombre as creador_nombre, u.email as creador_email
                FROM tickets t
                JOIN usuarios u ON t.user_id = u.id
                WHERE t.id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $ticket = $result->fetch_assoc();
            $stmt->close();

            // Verificar permisos: el usuario debe ser admin O el creador del ticket.
            if ($isAdmin || (int)$ticket['user_id'] === (int)$user_id) {
                sendResponse($conn, 'success', 'Detalle de ticket obtenido.', $ticket);
            } else {
                sendResponse($conn, 'error', 'Permiso denegado para ver este ticket.', null, 403);
            }
        } else {
            sendResponse($conn, 'error', 'Ticket no encontrado.', null, 404);
        }

    } 
    // --- Obtener Lista de Tickets ---
    else {
        // Si es admin, ve todos los tickets. Si no, solo ve sus propios tickets.
        if ($isAdmin) {
             // Admin: Ver todos, ordenados por estado (abiertos primero) y prioridad (más alta primero)
            $sql = "SELECT t.id, t.titulo, t.departamento_asignado, t.estado, t.prioridad, t.fecha_creacion, u.nombre as creador_nombre
                    FROM tickets t
                    JOIN usuarios u ON t.user_id = u.id
                    ORDER BY FIELD(t.estado, 'Abierto', 'En Proceso', 'Cerrado'), FIELD(t.prioridad, 'Urgente', 'Alta', 'Media', 'Baja') DESC";
            $stmt = $conn->prepare($sql);
        } else {
            // Usuario normal: Ver solo sus tickets
            $sql = "SELECT t.id, t.titulo, t.departamento_asignado, t.estado, t.prioridad, t.fecha_creacion, u.nombre as creador_nombre
                    FROM tickets t
                    JOIN usuarios u ON t.user_id = u.id
                    WHERE t.user_id = ?
                    ORDER BY FIELD(t.estado, 'Abierto', 'En Proceso', 'Cerrado'), t.fecha_creacion DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            $tickets[] = $row;
        }
        $stmt->close();
        sendResponse($conn, 'success', 'Lista de tickets obtenida.', $tickets);
    }
} 
// ====================================================================
// --- LÓGICA DE MODIFICACIÓN (POST) ---
// ====================================================================
else if ($method === 'POST') {
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if ($data === null) {
        sendResponse($conn, 'error', 'Formato JSON inválido o datos faltantes.', null, 400);
    }

    $action = $data['action'] ?? null;
    $id = $data['id'] ?? null;

    switch ($action) {
        // --- CREAR TICKET (CREATE) ---
        case 'create':
            $titulo = $data['titulo'] ?? null;
            $descripcion = $data['descripcion'] ?? null;
            $departamento = $data['departamento_asignado'] ?? 'Sistemas';
            $prioridad = $data['prioridad'] ?? 'Media';

            if (!$titulo || !$descripcion) {
                sendResponse($conn, 'error', 'Título y descripción son requeridos.', null, 400);
            }
            
            $stmt = $conn->prepare("INSERT INTO tickets (user_id, titulo, descripcion, departamento_asignado, prioridad) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $user_id, $titulo, $descripcion, $departamento, $prioridad);

            if ($stmt->execute()) {
                sendResponse($conn, 'success', 'Ticket creado exitosamente.', ['id' => $stmt->insert_id]);
            } else {
                sendResponse($conn, 'error', 'Error al crear el ticket: ' . $stmt->error, null, 500);
            }
            $stmt->close();
            break;

        // --- AÑADIR RESPUESTA/COMENTARIO ---
        case 'reply':
            $mensaje = $data['mensaje'] ?? null;
            if (!$id || !$mensaje) {
                sendResponse($conn, 'error', 'ID del ticket y mensaje son requeridos.', null, 400);
            }

            // Antes de responder, verificar si el usuario es admin O el creador del ticket
            $stmt = $conn->prepare("SELECT user_id FROM tickets WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $ticket = $result->fetch_assoc();
            $stmt->close();

            if (!$ticket) {
                 sendResponse($conn, 'error', 'Ticket no encontrado.', null, 404);
            }

            $isTicketCreator = (int)$ticket['user_id'] === (int)$user_id;

            if ($isAdmin || $isTicketCreator) {
                $stmt = $conn->prepare("INSERT INTO ticket_replies (ticket_id, user_id, mensaje) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $id, $user_id, $mensaje);

                if ($stmt->execute()) {
                    // Si se responde, actualizamos el estado a 'En Proceso' si estaba 'Abierto'
                    $conn->query("UPDATE tickets SET estado = IF(estado = 'Abierto', 'En Proceso', estado) WHERE id = {$id}");
                    sendResponse($conn, 'success', 'Respuesta añadida exitosamente.');
                } else {
                    sendResponse($conn, 'error', 'Error al añadir respuesta: ' . $stmt->error, null, 500);
                }
                $stmt->close();
            } else {
                 sendResponse($conn, 'error', 'Permiso denegado para responder este ticket.', null, 403);
            }
            break;
            
        // --- ACTUALIZAR TICKET (Solo Admin) ---
        case 'update_status':
        case 'update_admin':
            if (!$isAdmin) {
                sendResponse($conn, 'error', 'Permiso denegado. Se requiere perfil de Administrador Global para actualizar estado/departamento.', null, 403);
            }
            if (!$id) {
                sendResponse($conn, 'error', 'ID del ticket es requerido.', null, 400);
            }

            $estado = $data['estado'] ?? null;
            $departamento = $data['departamento_asignado'] ?? null;
            $prioridad = $data['prioridad'] ?? null;

            $updates = [];
            $bindTypes = '';
            $bindValues = [];

            if ($estado) {
                $updates[] = "estado = ?";
                $bindTypes .= 's';
                $bindValues[] = $estado;
            }
            if ($departamento) {
                $updates[] = "departamento_asignado = ?";
                $bindTypes .= 's';
                $bindValues[] = $departamento;
            }
            if ($prioridad) {
                $updates[] = "prioridad = ?";
                $bindTypes .= 's';
                $bindValues[] = $prioridad;
            }

            if (empty($updates)) {
                sendResponse($conn, 'error', 'No se proporcionaron campos para actualizar.', null, 400);
            }
            
            $sql = "UPDATE tickets SET " . implode(', ', $updates) . " WHERE id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $id;

            $stmt = $conn->prepare($sql);
            // Esto es necesario para el call_user_func_array
            $params = array_merge([$bindTypes], $bindValues); 
            call_user_func_array([$stmt, 'bind_param'], $params);

            if ($stmt->execute()) {
                sendResponse($conn, 'success', 'Ticket actualizado exitosamente.', ['id' => $id]);
            } else {
                sendResponse($conn, 'error', 'Error al actualizar el ticket: ' . $stmt->error, null, 500);
            }
            $stmt->close();
            break;

        default:
            sendResponse($conn, 'error', 'Acción no válida o reconocida.', null, 405);
            break;
    }
} else {
    sendResponse($conn, 'error', 'Método HTTP no soportado.', null, 405);
}
?>
