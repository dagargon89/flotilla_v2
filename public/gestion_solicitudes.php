<?php
// public/gestion_solicitudes.php - CÓDIGO COMPLETO Y ACTUALIZADO (Edición de Solicitudes Aprobadas)
session_start();
require_once '../app/config/database.php';

$db = connectDB();

// Fetch current user's detailed status and amonestaciones for banner and logic
$current_user_estatus_usuario = $_SESSION['user_role'] ?? 'empleado';
$current_user_amonestaciones_count = 0;
$current_user_recent_amonestaciones_text = '';
$error_message = ''; // Inicializa error_message para esta página

if (isset($_SESSION['user_id']) && $db) {
    try {
        $stmt_user_full_status = $db->prepare("SELECT estatus_usuario FROM usuarios WHERE id = :user_id");
        $stmt_user_full_status->bindParam(':user_id', $_SESSION['user_id']);
        $stmt_user_full_status->execute();
        $user_full_status_result = $stmt_user_full_status->fetch(PDO::FETCH_ASSOC);
        if ($user_full_status_result) {
            $current_user_estatus_usuario = $user_full_status_result['estatus_usuario'];
            $_SESSION['user_estatus_usuario'] = $current_user_estatus_usuario;
        }

        if ($current_user_estatus_usuario === 'amonestado') {
            $stmt_amonestaciones = $db->prepare("
                SELECT COUNT(*) as total_count,
                       GROUP_CONCAT(CONCAT(DATE_FORMAT(fecha_amonestacion, '%d/%m'), ' (', tipo_amonestacion, ')') ORDER BY fecha_amonestacion DESC SEPARATOR '; ') AS recent_descriptions
                FROM amonestaciones
                WHERE usuario_id = :user_id
                LIMIT 3
            ");
            $stmt_amonestaciones->bindParam(':user_id', $_SESSION['user_id']);
            $stmt_amonestaciones->execute();
            $amonestacion_data = $stmt_amonestaciones->fetch(PDO::FETCH_ASSOC);

            if ($amonestacion_data) {
                $current_user_amonestaciones_count = $amonestacion_data['total_count'];
                $current_user_recent_amonestaciones_text = $amonestacion_data['recent_descriptions'] ?: 'Ninguna reciente.';
            }
        }
    } catch (PDOException $e) {
        error_log("Error al obtener estatus de usuario/amonestaciones para banner: " . $e->getMessage());
        $current_user_estatus_usuario = 'activo';
        $error_message .= ' Error al cargar tu estatus o amonestaciones. Contacta al administrador.';
    }
}

require_once '../app/includes/global_auth_redirect.php';

// **VERIFICACIÓN DE ROL:**
// Este archivo es para 'flotilla_manager' y 'admin'.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'flotilla_manager' && $_SESSION['user_role'] !== 'admin')) {
    header('Location: dashboard.php'); // Redirige al dashboard si no tiene permisos
    exit();
}

$nombre_usuario_sesion = $_SESSION['user_name'];
$user_id = $_SESSION['user_id'];
$rol_usuario_sesion = $_SESSION['user_role'];

$success_message = '';
$error_message = $error_message ?? '';

$solicitudes = [];
$vehiculos_flotilla_para_modales = []; // Para el dropdown de edición

// --- Lógica para procesar las acciones de aprobar/rechazar/asignar/editar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $solicitud_id = $_POST['solicitud_id'] ?? null;
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'aprobar' || $action === 'rechazar') {
            $observaciones = trim($_POST['observaciones_aprobacion'] ?? '');
            $vehiculo_asignado_id = filter_var($_POST['vehiculo_asignado_id'] ?? null, FILTER_VALIDATE_INT);

            if ($solicitud_id === false) { // Validate after filter_var
                throw new Exception("ID de solicitud inválido.");
            }

            $db->beginTransaction();

            $new_status = ($action === 'aprobar') ? 'aprobada' : 'rechazada';
            $vehiculo_to_update = null;

            $stmt_get_solicitud_info = $db->prepare("
                SELECT s.vehiculo_id, s.fecha_salida_solicitada, s.fecha_regreso_solicitada,
                       u.nombre AS solicitante_nombre, u.correo_electronico AS solicitante_correo,
                       s.evento, s.descripcion, s.destino
                FROM solicitudes_vehiculos s
                JOIN usuarios u ON s.usuario_id = u.id
                WHERE s.id = :solicitud_id FOR UPDATE");
            $stmt_get_solicitud_info->bindParam(':solicitud_id', $solicitud_id);
            $stmt_get_solicitud_info->execute();
            $current_solicitud_info = $stmt_get_solicitud_info->fetch(PDO::FETCH_ASSOC);

            if (!$current_solicitud_info) {
                throw new Exception("Solicitud no encontrada para procesar.");
            }

            if ($action === 'aprobar') {
                if (!$vehiculo_asignado_id) {
                    throw new Exception("Debes seleccionar un vehículo para aprobar la solicitud.");
                }

                $stmt_overlap = $db->prepare("
                    SELECT COUNT(*) FROM solicitudes_vehiculos
                    WHERE vehiculo_id = :vehiculo_id
                    AND estatus_solicitud IN ('aprobada', 'en_curso')
                    AND (
                        (fecha_salida_solicitada < :fecha_regreso AND fecha_regreso_solicitada > :fecha_salida)
                    )
                    AND id != :solicitud_id_exclude
                ");
                $stmt_overlap->bindParam(':vehiculo_id', $vehiculo_asignado_id);
                $stmt_overlap->bindParam(':fecha_salida', $current_solicitud_info['fecha_salida_solicitada']);
                $stmt_overlap->bindParam(':fecha_regreso', $current_solicitud_info['fecha_regreso_solicitada']);
                $stmt_overlap->bindParam(':solicitud_id_exclude', $solicitud_id);
                $stmt_overlap->execute();

                if ($stmt_overlap->fetchColumn() > 0) {
                    throw new Exception("El vehículo seleccionado no está disponible en las fechas solicitadas. Por favor, elige otro.");
                }

                $vehiculo_to_update = $vehiculo_asignado_id;
            } else {
                $vehiculo_to_update = null;
            }

            $stmt_update_sol = $db->prepare("UPDATE solicitudes_vehiculos SET estatus_solicitud = :new_status, fecha_aprobacion = NOW(), aprobado_por = :aprobado_por, observaciones_aprobacion = :observaciones, vehiculo_id = :vehiculo_id WHERE id = :solicitud_id");
            $stmt_update_sol->bindParam(':new_status', $new_status);
            $stmt_update_sol->bindParam(':aprobado_por', $user_id);
            $stmt_update_sol->bindParam(':observaciones', $observaciones);
            $stmt_update_sol->bindParam(':vehiculo_id', $vehiculo_to_update);
            $stmt_update_sol->bindParam(':solicitud_id', $solicitud_id);
            $stmt_update_sol->execute();

            if ($stmt_update_sol->rowCount() > 0) {
                $success_message = 'Solicitud ' . ($action === 'aprobar' ? 'aprobada' : 'rechazada') . ' con éxito.';
                // Correo electrónico (pendiente de configurar en mail.php)
            } else {
                $error_message = 'La solicitud no pudo ser actualizada. Asegúrate de que no esté ya procesada o de que el ID sea correcto.';
                $db->rollBack();
            }

            $db->commit();
        } elseif ($action === 'edit_approved_request') { // NUEVA ACCIÓN: Editar una solicitud aprobada
            $vehiculo_id_new = filter_var($_POST['vehiculo_id_new'] ?? null, FILTER_VALIDATE_INT);
            $fecha_salida_new = trim($_POST['fecha_salida_new'] ?? '');
            $fecha_regreso_new = trim($_POST['fecha_regreso_new'] ?? '');
            $observaciones_edit = trim($_POST['observaciones_edit'] ?? '');

            if ($solicitud_id === false || !$vehiculo_id_new || empty($fecha_salida_new) || empty($fecha_regreso_new)) {
                throw new Exception("Datos incompletos para editar la solicitud aprobada.");
            }
            if (strtotime($fecha_salida_new) >= strtotime($fecha_regreso_new)) {
                throw new Exception("La fecha de regreso debe ser posterior a la fecha de salida.");
            }

            $db->beginTransaction();

            // 1. Verificar disponibilidad del NUEVO vehículo en las NUEVAS fechas
            $stmt_overlap_edit = $db->prepare("
                SELECT COUNT(*) FROM solicitudes_vehiculos
                WHERE vehiculo_id = :vehiculo_id
                AND estatus_solicitud IN ('aprobada', 'en_curso')
                AND (
                    (fecha_salida_solicitada < :fecha_regreso AND fecha_regreso_solicitada > :fecha_salida)
                )
                AND id != :solicitud_id_exclude -- Excluir la propia solicitud que estamos editando
            ");
            $stmt_overlap_edit->bindParam(':vehiculo_id', $vehiculo_id_new);
            $stmt_overlap_edit->bindParam(':fecha_salida', $fecha_salida_new);
            $stmt_overlap_edit->bindParam(':fecha_regreso', $fecha_regreso_new);
            $stmt_overlap_edit->bindParam(':solicitud_id_exclude', $solicitud_id);
            $stmt_overlap_edit->execute();

            if ($stmt_overlap_edit->fetchColumn() > 0) {
                throw new Exception("El nuevo vehículo seleccionado no está disponible en las fechas de edición. Por favor, elige otro o ajusta las fechas.");
            }

            // 2. Actualizar la solicitud
            $stmt_update_approved = $db->prepare("
                UPDATE solicitudes_vehiculos
                SET vehiculo_id = :vehiculo_id_new,
                    fecha_salida_solicitada = :fecha_salida_new,
                    fecha_regreso_solicitada = :fecha_regreso_new,
                    observaciones_aprobacion = :observaciones_edit
                WHERE id = :solicitud_id AND estatus_solicitud = 'aprobada'
            ");
            $stmt_update_approved->bindParam(':vehiculo_id_new', $vehiculo_id_new);
            $stmt_update_approved->bindParam(':fecha_salida_new', $fecha_salida_new);
            $stmt_update_approved->bindParam(':fecha_regreso_new', $fecha_regreso_new);
            $stmt_update_approved->bindParam(':observaciones_edit', $observaciones_edit);
            $stmt_update_approved->bindParam(':solicitud_id', $solicitud_id);
            $stmt_update_approved->execute();

            $db->commit();
            $success_message = 'Solicitud aprobada actualizada con éxito.';
        } else {
            throw new Exception("Acción no reconocida.");
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Error en gestión de solicitudes: " . $e->getMessage());
        $error_message = 'Ocurrió un error al procesar la solicitud: ' . $e->getMessage();
    }
}

// --- Obtener todas las solicitudes para mostrar en la tabla ---
if ($db) {
    try {
        $stmt = $db->query("
            SELECT
                s.id AS solicitud_id,
                u.nombre AS usuario_nombre,
                s.fecha_salida_solicitada,
                s.fecha_regreso_solicitada,
                s.evento,
                s.descripcion,
                s.destino,
                s.estatus_solicitud,
                s.observaciones_aprobacion,
                v.marca,
                v.modelo,
                v.placas,
                v.id AS vehiculo_actual_id
            FROM solicitudes_vehiculos s
            JOIN usuarios u ON s.usuario_id = u.id
            LEFT JOIN vehiculos v ON s.vehiculo_id = v.id
            ORDER BY s.fecha_creacion DESC
        ");
        $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- Obtener todos los vehículos para el dropdown en los modales (incluyendo los que no están 'disponibles') ---
        $stmt_vehiculos_flotilla = $db->query("SELECT id, marca, modelo, placas FROM vehiculos ORDER BY marca, modelo");
        $vehiculos_flotilla_para_modales = $stmt_vehiculos_flotilla->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al cargar datos para gestión de solicitudes: " . $e->getMessage());
        $error_message .= ' No se pudieron cargar las solicitudes o vehículos para la tabla. Detalle: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Solicitudes - Flotilla Interna</title>
    <!-- Eliminar Bootstrap y Bootstrap Icons -->
    <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> -->
    <link rel="stylesheet" href="css/style.css">
    <!-- Agregar Tailwind CSS CDN y configuración de colores personalizados -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        darkpurple: '#310A31',
                        mountbatten: '#847996',
                        cambridge1: '#88B7B5',
                        cambridge2: '#A7CAB1',
                        parchment: '#FFFBFA',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body class="bg-parchment min-h-screen">
    <?php
    $nombre_usuario_sesion = $_SESSION['user_name'] ?? 'Usuario';
    $rol_usuario_sesion = $_SESSION['user_role'] ?? 'empleado';
    require_once '../app/includes/navbar.php';
    ?>
    <?php require_once '../app/includes/alert_banner.php'; ?>

    <div class="container mx-auto px-4 py-6">
        <h1 class="text-3xl font-bold text-darkpurple mb-6">Gestión de Solicitudes de Vehículos</h1>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4" role="alert">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($solicitudes)): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded" role="alert">
                No hay solicitudes de vehículos para mostrar.
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-cambridge2">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-cambridge1 text-white">
                                <th class="px-4 py-3 text-left">ID Sol.</th>
                                <th class="px-4 py-3 text-left">Solicitante</th>
                                <th class="px-4 py-3 text-left">Salida Deseada</th>
                                <th class="px-4 py-3 text-left">Regreso Deseado</th>
                                <th class="px-4 py-3 text-left">Evento</th>
                                <th class="px-4 py-3 text-left">Descripción</th>
                                <th class="px-4 py-3 text-left">Destino</th>
                                <th class="px-4 py-3 text-left">Vehículo Asignado</th>
                                <th class="px-4 py-3 text-left">Estatus</th>
                                <th class="px-4 py-3 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitudes as $solicitud): ?>
                                <tr class="border-b border-cambridge2 hover:bg-parchment">
                                    <td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars($solicitud['solicitud_id']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($solicitud['usuario_nombre']); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_salida_solicitada'])); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_regreso_solicitada'])); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($solicitud['evento']); ?></td>
                                    <td class="px-4 py-3 text-sm text-mountbatten"><?php echo htmlspecialchars($solicitud['descripcion']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($solicitud['destino']); ?></td>
                                    <td class="px-4 py-3">
                                        <?php if ($solicitud['marca']): ?>
                                            <span class="font-semibold"><?php echo htmlspecialchars($solicitud['marca'] . ' ' . $solicitud['modelo'] . ' (' . $solicitud['placas'] . ')'); ?></span>
                                        <?php else: ?>
                                            <span class="text-mountbatten">Sin asignar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $status_class = '';
                                        switch ($solicitud['estatus_solicitud']) {
                                            case 'pendiente':
                                                $status_class = 'bg-yellow-500 text-white';
                                                break;
                                            case 'aprobada':
                                                $status_class = 'bg-green-500 text-white';
                                                break;
                                            case 'rechazada':
                                                $status_class = 'bg-red-500 text-white';
                                                break;
                                            case 'en_curso':
                                                $status_class = 'bg-cambridge1 text-white';
                                                break;
                                            case 'completada':
                                                $status_class = 'bg-gray-500 text-white';
                                                break;
                                            case 'cancelada':
                                                $status_class = 'bg-blue-500 text-white';
                                                break;
                                        }
                                        ?>
                                        <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($solicitud['estatus_solicitud'])); ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($solicitud['estatus_solicitud'] === 'pendiente'): ?>
                                            <div class="flex flex-wrap gap-1">
                                                <button type="button" class="bg-green-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-green-600 transition" data-modal-target="approveRejectModal"
                                                    data-solicitud-id="<?php echo $solicitud['solicitud_id']; ?>" data-action="aprobar"
                                                    data-usuario="<?php echo htmlspecialchars($solicitud['usuario_nombre']); ?>"
                                                    data-salida="<?php echo htmlspecialchars($solicitud['fecha_salida_solicitada']); ?>"
                                                    data-regreso="<?php echo htmlspecialchars($solicitud['fecha_regreso_solicitada']); ?>"
                                                    data-observaciones-aprobacion="<?php echo (empty($solicitud['observaciones_aprobacion']) || strpos($solicitud['observaciones_aprobacion'], 'Deprecated') !== false ? '' : htmlspecialchars($solicitud['observaciones_aprobacion'])); ?>"
                                                    data-vehiculo-actual-id="<?php echo htmlspecialchars($solicitud['vehiculo_actual_id']); ?>"
                                                    data-vehiculo-info-display="<?php echo htmlspecialchars($solicitud['marca'] ? $solicitud['marca'] . ' ' . $solicitud['modelo'] . ' (' . $solicitud['placas'] . ')' : 'Sin asignar'); ?>">
                                                    <i class="bi bi-check-lg"></i> Aprobar
                                                </button>
                                                <button type="button" class="bg-red-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-600 transition" data-modal-target="approveRejectModal"
                                                    data-solicitud-id="<?php echo $solicitud['solicitud_id']; ?>" data-action="rechazar"
                                                    data-usuario="<?php echo htmlspecialchars($solicitud['usuario_nombre']); ?>"
                                                    data-observaciones-aprobacion="<?php echo (empty($solicitud['observaciones_aprobacion']) || strpos($solicitud['observaciones_aprobacion'], 'Deprecated') !== false ? '' : htmlspecialchars($solicitud['observaciones_aprobacion'])); ?>">
                                                    <i class="bi bi-x-lg"></i> Rechazar
                                                </button>
                                            </div>
                                        <?php elseif ($solicitud['estatus_solicitud'] === 'aprobada' || $solicitud['estatus_solicitud'] === 'en_curso'): ?>
                                            <div class="flex flex-wrap gap-1">
                                                <button type="button" class="bg-cambridge1 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-cambridge2 transition" data-modal-target="editApprovedRequestModal"
                                                    data-solicitud-id="<?php echo htmlspecialchars($solicitud['solicitud_id']); ?>"
                                                    data-usuario="<?php echo htmlspecialchars($solicitud['usuario_nombre']); ?>"
                                                    data-salida="<?php echo htmlspecialchars($solicitud['fecha_salida_solicitada']); ?>"
                                                    data-regreso="<?php echo htmlspecialchars($solicitud['fecha_regreso_solicitada']); ?>"
                                                    data-vehiculo-actual-id="<?php echo htmlspecialchars($solicitud['vehiculo_actual_id'] ?? ''); ?>"
                                                    data-observaciones-aprobacion="<?php echo (empty($solicitud['observaciones_aprobacion']) || strpos($solicitud['observaciones_aprobacion'], 'Deprecated') !== false ? '' : htmlspecialchars($solicitud['observaciones_aprobacion'])); ?>">
                                                    <i class="bi bi-pencil-fill"></i> Editar Asignación
                                                </button>
                                                <button type="button" class="bg-gray-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-gray-600 transition" data-modal-target="viewDetailsModal"
                                                    data-solicitud-id="<?php echo $solicitud['solicitud_id']; ?>"
                                                    data-usuario="<?php echo htmlspecialchars($solicitud['usuario_nombre']); ?>"
                                                    data-salida="<?php echo htmlspecialchars($solicitud['fecha_salida_solicitada']); ?>"
                                                    data-regreso="<?php echo htmlspecialchars($solicitud['fecha_regreso_solicitada']); ?>"
                                                    data-evento="<?php echo htmlspecialchars($solicitud['evento']); ?>"
                                                    data-descripcion="<?php echo htmlspecialchars($solicitud['descripcion']); ?>"
                                                    data-destino="<?php echo htmlspecialchars($solicitud['destino']); ?>"
                                                    data-vehiculo="<?php echo htmlspecialchars($solicitud['marca'] ? $solicitud['marca'] . ' ' . $solicitud['modelo'] . ' (' . $solicitud['placas'] . ')' : 'Sin asignar'); ?>"
                                                    data-estatus="<?php echo htmlspecialchars(ucfirst($solicitud['estatus_solicitud'])); ?>"
                                                    data-observaciones-aprobacion="<?php echo (empty($solicitud['observaciones_aprobacion']) || strpos($solicitud['observaciones_aprobacion'], 'Deprecated') !== false ? '' : htmlspecialchars($solicitud['observaciones_aprobacion'])); ?>">
                                                    <i class="bi bi-eye-fill"></i> Ver Detalles
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="flex flex-wrap gap-1">
                                                <button type="button" class="bg-gray-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-gray-600 transition" data-modal-target="viewDetailsModal"
                                                    data-solicitud-id="<?php echo $solicitud['solicitud_id']; ?>"
                                                    data-usuario="<?php echo htmlspecialchars($solicitud['usuario_nombre']); ?>"
                                                    data-salida="<?php echo htmlspecialchars($solicitud['fecha_salida_solicitada']); ?>"
                                                    data-regreso="<?php echo htmlspecialchars($solicitud['fecha_regreso_solicitada']); ?>"
                                                    data-evento="<?php echo htmlspecialchars($solicitud['evento']); ?>"
                                                    data-descripcion="<?php echo htmlspecialchars($solicitud['descripcion']); ?>"
                                                    data-destino="<?php echo htmlspecialchars($solicitud['destino']); ?>"
                                                    data-vehiculo="<?php echo htmlspecialchars($solicitud['marca'] ? $solicitud['marca'] . ' ' . $solicitud['modelo'] . ' (' . $solicitud['placas'] . ')' : 'Sin asignar'); ?>"
                                                    data-estatus="<?php echo htmlspecialchars(ucfirst($solicitud['estatus_solicitud'])); ?>"
                                                    data-observaciones-aprobacion="<?php echo (empty($solicitud['observaciones_aprobacion']) || strpos($solicitud['observaciones_aprobacion'], 'Deprecated') !== false ? '' : htmlspecialchars($solicitud['observaciones_aprobacion'])); ?>">
                                                    <i class="bi bi-eye-fill"></i> Ver Detalles
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal para Aprobar/Rechazar Solicitud -->
        <div id="approveRejectModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="approveRejectModalLabel">Gestionar Solicitud</h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('approveRejectModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form action="gestion_solicitudes.php" method="POST">
                    <div class="p-6 space-y-4">
                        <input type="hidden" name="solicitud_id" id="modalSolicitudId">
                        <input type="hidden" name="action" id="modalAction">
                        <input type="hidden" name="vehiculo_info_display_modal" id="vehiculoInfoDisplayModal">
                        <p class="text-gray-700">Estás a punto de <strong id="modalActionText"></strong> la solicitud de <strong id="modalUserName"></strong>.</p>
                        <div id="vehicleAssignmentSection">
                            <div>
                                <label for="vehiculo_asignado_id" class="block text-sm font-medium text-gray-700 mb-2">Asignar Vehículo Disponible</label>
                                <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="vehiculo_asignado_id" name="vehiculo_asignado_id">
                                    <option value="">Selecciona un vehículo (Obligatorio para Aprobar)</option>
                                    <?php foreach (
                                        $vehiculos_flotilla_para_modales as $vehiculo_opcion
                                    ): ?>
                                        <option value="<?php echo htmlspecialchars($vehiculo_opcion['id']); ?>"
                                            <?php echo (isset($solicitud['vehiculo_actual_id']) && $solicitud['vehiculo_actual_id'] == $vehiculo_opcion['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($vehiculo_opcion['marca'] . ' ' . $vehiculo_opcion['modelo'] . ' (' . $vehiculo_opcion['placas'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <p class="text-sm text-blue-600">Solo se muestran vehículos que no están asignados actualmente a otras solicitudes *aprobadas* en las fechas solicitadas.</p>
                        </div>
                        <div>
                            <label for="observaciones_aprobacion" class="block text-sm font-medium text-gray-700 mb-2">Observaciones (Opcional)</label>
                            <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="observaciones_aprobacion_modal" name="observaciones_aprobacion" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('approveRejectModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white rounded-md transition-colors" id="modalSubmitBtn"></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para Editar Solicitud Aprobada -->
        <div id="editApprovedRequestModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="editApprovedRequestModalLabel">Editar Solicitud Aprobada</h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('editApprovedRequestModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form action="gestion_solicitudes.php" method="POST">
                    <div class="p-6 space-y-4">
                        <input type="hidden" name="action" value="edit_approved_request">
                        <input type="hidden" name="solicitud_id" id="editApprovedSolicitudId">
                        <p class="text-gray-700">Editando solicitud de: <strong id="editApprovedUserName"></strong></p>

                        <div>
                            <label for="vehiculo_id_new" class="block text-sm font-medium text-gray-700 mb-2">Cambiar Vehículo Asignado</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="vehiculo_id_new" name="vehiculo_id_new" required>
                                <option value="">Selecciona un vehículo</option>
                                <?php foreach ($vehiculos_flotilla_para_modales as $vehiculo_opt): ?>
                                    <option value="<?php echo htmlspecialchars($vehiculo_opt['id']); ?>">
                                        <?php echo htmlspecialchars($vehiculo_opt['marca'] . ' ' . $vehiculo_opt['modelo'] . ' (' . $vehiculo_opt['placas'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="fecha_salida_new" class="block text-sm font-medium text-gray-700 mb-2">Nueva Fecha y Hora de Salida</label>
                            <input type="datetime-local" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="fecha_salida_new" name="fecha_salida_new" required>
                        </div>
                        <div>
                            <label for="fecha_regreso_new" class="block text-sm font-medium text-gray-700 mb-2">Nueva Fecha y Hora de Regreso</label>
                            <input type="datetime-local" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="fecha_regreso_new" name="fecha_regreso_new" required>
                        </div>
                        <div>
                            <label for="observaciones_edit" class="block text-sm font-medium text-gray-700 mb-2">Observaciones del Gestor (actualizar)</label>
                            <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="observaciones_edit" name="observaciones_edit" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('editApprovedRequestModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para Ver Detalles -->
        <div id="viewDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="viewDetailsModalLabel">Detalles de Solicitud</h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('viewDetailsModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="p-6 space-y-3">
                    <p class="text-gray-700"><strong>Solicitante:</strong> <span id="detailUserName"></span></p>
                    <p class="text-gray-700"><strong>Salida Deseada:</strong> <span id="detailFechaSalida"></span></p>
                    <p class="text-gray-700"><strong>Regreso Deseado:</strong> <span id="detailFechaRegreso"></span></p>
                    <p class="text-gray-700"><strong>Evento:</strong> <span id="detailEvento"></span></p>
                    <p class="text-gray-700"><strong>Descripción:</strong> <span id="detailDescripcion"></span></p>
                    <p class="text-gray-700"><strong>Destino:</strong> <span id="detailDestino"></span></p>
                    <p class="text-gray-700"><strong>Vehículo Asignado:</strong> <span id="detailVehiculoAsignado"></span></p>
                    <p class="text-gray-700"><strong>Estatus:</strong> <span id="detailEstatus" class="inline-block px-2 py-1 text-xs font-semibold rounded-full"></span></p>
                    <p class="text-gray-700"><strong>Observaciones del Gestor:</strong> <span id="detailObservacionesAprobacion"></span></p>
                </div>
                <div class="flex justify-end p-6 border-t border-gray-200">
                    <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('viewDetailsModal')">Cerrar</button>
                </div>
            </div>
        </div>

    </div>

    <!-- Eliminar Bootstrap y Bootstrap Icons -->
    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="js/main.js"></script>
    <script>
        // Funciones para manejar modales
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Cerrar modal al hacer clic fuera de él
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('fixed') && event.target.classList.contains('bg-black')) {
                event.target.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        });

        // JavaScript para manejar el modal de Aprobar/Rechazar Solicitud
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr("#fecha_salida_new", {
                enableTime: true,
                dateFormat: "Y-m-dTH:i",
                minDate: "today"
            });
            flatpickr("#fecha_regreso_new", {
                enableTime: true,
                dateFormat: "Y-m-dTH:i",
                minDate: "today"
            });

            // Configurar botones para abrir modales
            document.querySelectorAll('[data-modal-target]').forEach(button => {
                button.addEventListener('click', function() {
                    const modalId = this.getAttribute('data-modal-target');
                    const action = this.getAttribute('data-action');

                    if (modalId === 'approveRejectModal') {
                        setupApproveRejectModal(action, this);
                    } else if (modalId === 'editApprovedRequestModal') {
                        setupEditApprovedRequestModal(this);
                    } else if (modalId === 'viewDetailsModal') {
                        setupViewDetailsModal(this);
                    }

                    openModal(modalId);
                });
            });

            function setupApproveRejectModal(action, button) {
                var solicitudId = button.getAttribute('data-solicitud-id');
                var usuario = button.getAttribute('data-usuario');
                var observacionesAprobacion = button.getAttribute('data-observaciones-aprobacion');
                var vehiculoActualId = button.getAttribute('data-vehiculo-actual-id');

                document.getElementById('modalSolicitudId').value = solicitudId;
                document.getElementById('modalAction').value = action;
                document.getElementById('modalUserName').textContent = usuario;
                document.getElementById('observaciones_aprobacion_modal').value = (observacionesAprobacion === null || observacionesAprobacion === 'null' || typeof observacionesAprobacion === 'undefined') ? '' : observacionesAprobacion;
                if (vehiculoActualId) {
                    document.getElementById('vehiculo_asignado_id').value = vehiculoActualId;
                } else {
                    document.getElementById('vehiculo_asignado_id').value = '';
                }

                var modalActionText = document.getElementById('modalActionText');
                var modalSubmitBtn = document.getElementById('modalSubmitBtn');
                var vehicleAssignmentSection = document.getElementById('vehicleAssignmentSection');

                if (action === 'aprobar') {
                    modalActionText.textContent = 'APROBAR';
                    modalSubmitBtn.textContent = 'Aprobar Solicitud';
                    modalSubmitBtn.className = 'px-4 py-2 text-white bg-green-600 rounded-md hover:bg-green-700 transition-colors';
                    vehicleAssignmentSection.style.display = 'block';
                } else if (action === 'rechazar') {
                    modalActionText.textContent = 'RECHAZAR';
                    modalSubmitBtn.textContent = 'Rechazar Solicitud';
                    modalSubmitBtn.className = 'px-4 py-2 text-white bg-red-600 rounded-md hover:bg-red-700 transition-colors';
                    vehicleAssignmentSection.style.display = 'none';
                }
            }

            function setupEditApprovedRequestModal(button) {
                var solicitudId = button.getAttribute('data-solicitud-id');
                var usuario = button.getAttribute('data-usuario');
                var salida = button.getAttribute('data-salida');
                var regreso = button.getAttribute('data-regreso');
                var vehiculoActualId = button.getAttribute('data-vehiculo-actual-id');
                var observacionesAprobacion = button.getAttribute('data-observaciones-aprobacion');

                document.getElementById('editApprovedSolicitudId').value = solicitudId;
                document.getElementById('editApprovedUserName').textContent = usuario;
                document.getElementById('vehiculo_id_new').value = vehiculoActualId || '';
                document.getElementById('fecha_salida_new').value = salida;
                document.getElementById('fecha_regreso_new').value = regreso;
                document.getElementById('observaciones_edit').value = observacionesAprobacion || '';

                flatpickr("#fecha_salida_new").setDate(salida);
                flatpickr("#fecha_regreso_new").setDate(regreso);
            }

            function setupViewDetailsModal(button) {
                var usuario = button.getAttribute('data-usuario');
                var salida = button.getAttribute('data-salida');
                var regreso = button.getAttribute('data-regreso');
                var evento = button.getAttribute('data-evento');
                var descripcion = button.getAttribute('data-descripcion');
                var destino = button.getAttribute('data-destino');
                var vehiculo = button.getAttribute('data-vehiculo');
                var estatus = button.getAttribute('data-estatus');
                var observacionesAprobacion = button.getAttribute('data-observaciones-aprobacion');

                document.getElementById('detailUserName').textContent = usuario;
                document.getElementById('detailFechaSalida').textContent = new Date(salida).toLocaleString('es-MX');
                document.getElementById('detailFechaRegreso').textContent = new Date(regreso).toLocaleString('es-MX');
                document.getElementById('detailEvento').textContent = evento;
                document.getElementById('detailDescripcion').textContent = descripcion;
                document.getElementById('detailDestino').textContent = destino;
                document.getElementById('detailVehiculoAsignado').textContent = vehiculo;
                document.getElementById('detailEstatus').textContent = estatus;
                document.getElementById('detailObservacionesAprobacion').textContent = observacionesAprobacion || 'Sin observaciones';

                // Aplicar clase de color según el estatus
                var estatusElement = document.getElementById('detailEstatus');
                estatusElement.className = 'inline-block px-2 py-1 text-xs font-semibold rounded-full';

                switch (estatus.toLowerCase()) {
                    case 'pendiente':
                        estatusElement.classList.add('bg-yellow-100', 'text-yellow-800');
                        break;
                    case 'aprobada':
                        estatusElement.classList.add('bg-green-100', 'text-green-800');
                        break;
                    case 'rechazada':
                        estatusElement.classList.add('bg-red-100', 'text-red-800');
                        break;
                    case 'en_curso':
                        estatusElement.classList.add('bg-cambridge1', 'text-white');
                        break;
                    case 'completada':
                        estatusElement.classList.add('bg-gray-100', 'text-gray-800');
                        break;
                    case 'cancelada':
                        estatusElement.classList.add('bg-blue-100', 'text-blue-800');
                        break;
                }
            }
        });
    </script>
</body>

</html>