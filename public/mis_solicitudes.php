<?php
// public/mis_solicitudes.php - CÓDIGO COMPLETO Y CORREGIDO (Error Undefined $db y Bloqueo Amonestado)
session_start();
require_once '../app/config/database.php';

// ¡CORRECCIÓN CRÍTICA! Establecer la conexión a la base de datos aquí, al inicio.
$db = connectDB();

// Fetch current user's detailed status and amonestaciones for banner and logic
$current_user_estatus_usuario = $_SESSION['user_role'] ?? 'empleado'; // Default, will be overwritten
$current_user_amonestaciones_count = 0;
$current_user_recent_amonestaciones_text = ''; // Texto para el banner

if (isset($_SESSION['user_id']) && $db) {
    try {
        // Obtener el estatus_usuario del usuario logueado desde la DB (más fiable que la sesión sola)
        $stmt_user_full_status = $db->prepare("SELECT estatus_usuario FROM usuarios WHERE id = :user_id");
        $stmt_user_full_status->bindParam(':user_id', $_SESSION['user_id']);
        $stmt_user_full_status->execute();
        $user_full_status_result = $stmt_user_full_status->fetch(PDO::FETCH_ASSOC);
        if ($user_full_status_result) {
            $current_user_estatus_usuario = $user_full_status_result['estatus_usuario'];
            $_SESSION['user_estatus_usuario'] = $current_user_estatus_usuario; // Actualizar la sesión
        }

        // Si el usuario está 'amonestado', obtener los detalles de las amonestaciones para el banner
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
        $error_message = 'Error al cargar tu estatus o amonestaciones. Contacta al administrador.';
    }
}


// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$nombre_usuario_sesion = $_SESSION['user_name'] ?? 'Usuario';
$rol_usuario_sesion = $_SESSION['user_role'] ?? 'empleado';

$success_message = '';
$error_message = $error_message ?? ''; // Mantener el error si ya viene del bloque de amonestaciones

$solicitudes_usuario = [];

// Ruta base para guardar las imágenes (FUERA DE PUBLIC_HTML POR SEGURIDAD)
$upload_dir = __DIR__ . '/../storage/uploads/vehiculo_evidencias/';

// Asegúrate de que el directorio de subida exista y tenga permisos de escritura
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
if (!is_writable($upload_dir)) {
    $error_message .= 'Error: El directorio de subida de imágenes no tiene permisos de escritura. Por favor, configura los permisos de la carpeta: ' . htmlspecialchars($upload_dir);
    error_log("Permisos de escritura faltantes en: " . $upload_dir);
}


// --- Lógica para procesar las acciones de Salida/Regreso ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_uso'])) {
    // Bloquear si el usuario está suspendido O amonestado
    if ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado') {
        $error_message = 'No puedes realizar acciones de vehículo porque tu cuenta está ' . htmlspecialchars(ucfirst($current_user_estatus_usuario)) . '. Contacta al administrador.';
        header('Location: mis_solicitudes.php?error=' . urlencode($error_message)); // Redirigir para mostrar el error
        exit();
    }

    $action_uso = $_POST['action_uso'] ?? '';
    $solicitud_id = filter_var($_POST['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
    $kilometraje = filter_var($_POST['kilometraje'] ?? null, FILTER_VALIDATE_INT);
    $nivel_combustible = filter_var($_POST['nivel_combustible'] ?? null, FILTER_VALIDATE_FLOAT);
    $observaciones = trim($_POST['observaciones'] ?? '');

    // Para las fotos
    $fotos_urls = ['medidores' => [], 'observaciones' => []];
    $uploaded_files = $_FILES['fotos'] ?? [];

    if ($solicitud_id === false || $solicitud_id <= 0) {
        $error_message = 'ID de solicitud inválido.';
    } elseif ($kilometraje === false || $kilometraje < 0) {
        $error_message = 'El kilometraje debe ser un número válido y no negativo.';
    } elseif ($nivel_combustible === false || $nivel_combustible < 0 || $nivel_combustible > 100) {
        $error_message = 'El nivel de combustible debe ser un número entre 0 y 100.';
    } else {
        try {
            $db->beginTransaction();

            // Lógica para subir las fotos
            // Procesar fotos de medidores
            $fotos_medidores = $_FILES['fotos_medidores'] ?? [];
            if (!empty($fotos_medidores['name'][0])) {
                foreach ($fotos_medidores['name'] as $key => $name) {
                    $tmp_name = $fotos_medidores['tmp_name'][$key];
                    $error_code = $fotos_medidores['error'][$key];

                    if ($error_code === UPLOAD_ERR_OK) {
                        $file_ext = pathinfo($name, PATHINFO_EXTENSION);
                        $new_file_name = uniqid('evidencia_medidores_') . '.' . $file_ext;
                        $destination_path = $upload_dir . $new_file_name;

                        if (move_uploaded_file($tmp_name, $destination_path)) {
                            $fotos_urls['medidores'][] = '/flotilla/storage/uploads/vehiculo_evidencias/' . $new_file_name;
                        }
                    }
                }
            }

            // Procesar fotos de observaciones
            $fotos_observaciones = $_FILES['fotos_observaciones'] ?? [];
            if (!empty($fotos_observaciones['name'][0])) {
                foreach ($fotos_observaciones['name'] as $key => $name) {
                    $tmp_name = $fotos_observaciones['tmp_name'][$key];
                    $error_code = $fotos_observaciones['error'][$key];

                    if ($error_code === UPLOAD_ERR_OK) {
                        $file_ext = pathinfo($name, PATHINFO_EXTENSION);
                        $new_file_name = uniqid('evidencia_observaciones_') . '.' . $file_ext;
                        $destination_path = $upload_dir . $new_file_name;

                        if (move_uploaded_file($tmp_name, $destination_path)) {
                            $fotos_urls['observaciones'][] = '/flotilla/storage/uploads/vehiculo_evidencias/' . $new_file_name;
                        }
                    }
                }
            }

            if ($action_uso === 'marcar_salida') {
                $stmt_check = $db->prepare("SELECT vehiculo_id, estatus_solicitud FROM solicitudes_vehiculos WHERE id = :solicitud_id AND usuario_id = :user_id FOR UPDATE");
                $stmt_check->bindParam(':solicitud_id', $solicitud_id);
                $stmt_check->bindParam(':user_id', $user_id);
                $stmt_check->execute();
                $solicitud_info = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if (!$solicitud_info || $solicitud_info['estatus_solicitud'] !== 'aprobada' || !$solicitud_info['vehiculo_id']) {
                    throw new Exception("La solicitud no está aprobada o no tiene un vehículo asignado para marcar la salida.");
                }

                $stmt_insert = $db->prepare("INSERT INTO historial_uso_vehiculos (solicitud_id, vehiculo_id, usuario_id, kilometraje_salida, nivel_combustible_salida, fecha_salida_real, observaciones_salida, fotos_salida_medidores_url, fotos_salida_observaciones_url) VALUES (:solicitud_id, :vehiculo_id, :usuario_id, :kilometraje, :nivel_combustible, NOW(), :observaciones, :fotos_medidores_urls, :fotos_observaciones_urls)");
                $stmt_insert->bindParam(':solicitud_id', $solicitud_id);
                $stmt_insert->bindParam(':vehiculo_id', $solicitud_info['vehiculo_id']);
                $stmt_insert->bindParam(':usuario_id', $user_id);
                $stmt_insert->bindParam(':kilometraje', $kilometraje);
                $stmt_insert->bindParam(':nivel_combustible', $nivel_combustible);
                $stmt_insert->bindParam(':observaciones', $observaciones);
                $stmt_insert->bindValue(':fotos_medidores_urls', json_encode($fotos_urls['medidores']), PDO::PARAM_STR);
                $stmt_insert->bindValue(':fotos_observaciones_urls', json_encode($fotos_urls['observaciones']), PDO::PARAM_STR);
                $stmt_insert->execute();

                $stmt_update_sol = $db->prepare("UPDATE solicitudes_vehiculos SET estatus_solicitud = 'en_curso' WHERE id = :solicitud_id");
                $stmt_update_sol->bindParam(':solicitud_id', $solicitud_id);
                $stmt_update_sol->execute();

                $stmt_update_veh = $db->prepare("UPDATE vehiculos SET kilometraje_actual = :kilometraje, estatus = 'en_uso' WHERE id = :vehiculo_id");
                $stmt_update_veh->bindParam(':kilometraje', $kilometraje);
                $stmt_update_veh->bindParam(':vehiculo_id', $solicitud_info['vehiculo_id']);
                $stmt_update_veh->execute();

                $success_message = '¡Salida del vehículo registrada con éxito!';
            } elseif ($action_uso === 'marcar_regreso') {
                $stmt_hist = $db->prepare("SELECT id, vehiculo_id, kilometraje_salida FROM historial_uso_vehiculos WHERE solicitud_id = :solicitud_id");
                $stmt_hist->bindParam(':solicitud_id', $solicitud_id);
                $stmt_hist->execute();
                $historial_entry = $stmt_hist->fetch(PDO::FETCH_ASSOC);

                if (!$historial_entry) {
                    throw new Exception("No se encontró un registro de salida para esta solicitud. No se puede marcar el regreso.");
                }
                if ($kilometraje < $historial_entry['kilometraje_salida']) {
                    throw new Exception("El kilometraje de regreso no puede ser menor que el de salida.");
                }

                $stmt_update_hist = $db->prepare("UPDATE historial_uso_vehiculos SET kilometraje_regreso = :kilometraje, nivel_combustible_regreso = :nivel_combustible, fecha_regreso_real = NOW(), observaciones_regreso = :observaciones, fotos_regreso_medidores_url = :fotos_medidores_urls, fotos_regreso_observaciones_url = :fotos_observaciones_urls WHERE id = :historial_id");
                $stmt_update_hist->bindParam(':kilometraje', $kilometraje);
                $stmt_update_hist->bindParam(':nivel_combustible', $nivel_combustible);
                $stmt_update_hist->bindParam(':observaciones', $observaciones);
                $stmt_update_hist->bindValue(':fotos_medidores_urls', json_encode($fotos_urls['medidores']), PDO::PARAM_STR);
                $stmt_update_hist->bindValue(':fotos_observaciones_urls', json_encode($fotos_urls['observaciones']), PDO::PARAM_STR);
                $stmt_update_hist->bindParam(':historial_id', $historial_entry['id']);
                $stmt_update_hist->execute();

                $stmt_update_sol = $db->prepare("UPDATE solicitudes_vehiculos SET estatus_solicitud = 'completada' WHERE id = :solicitud_id");
                $stmt_update_sol->bindParam(':solicitud_id', $solicitud_id);
                $stmt_update_sol->execute();

                $stmt_update_veh = $db->prepare("UPDATE vehiculos SET kilometraje_actual = :kilometraje, estatus = 'disponible' WHERE id = :vehiculo_id");
                $stmt_update_veh->bindParam(':kilometraje', $kilometraje);
                $stmt_update_veh->bindParam(':vehiculo_id', $historial_entry['vehiculo_id']);
                $stmt_update_veh->execute();

                $success_message = '¡Regreso del vehículo registrado con éxito!';
            }

            $db->commit();
            header('Location: mis_solicitudes.php?success=' . urlencode($success_message));
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error en registro de uso: " . $e->getMessage());
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// --- Obtener las solicitudes del usuario logueado y su historial ---
if ($db) {
    try {
        $stmt_solicitudes = $db->prepare("
            SELECT
                s.id AS solicitud_id,
                s.fecha_salida_solicitada,
                s.fecha_regreso_solicitada,
                s.evento,
                s.descripcion,
                s.destino,
                s.estatus_solicitud,
                v.marca,
                v.modelo,
                v.placas,
                v.id AS vehiculo_id,
                s.fecha_creacion,
                s.observaciones_aprobacion,
                hu.id AS historial_id,
                hu.kilometraje_salida,
                hu.nivel_combustible_salida,
                hu.fecha_salida_real,
                hu.observaciones_salida,
                hu.fotos_salida_medidores_url,
                hu.fotos_salida_observaciones_url,
                hu.kilometraje_regreso,
                hu.nivel_combustible_regreso,
                hu.fecha_regreso_real,
                hu.observaciones_regreso,
                hu.fotos_regreso_medidores_url,
                hu.fotos_regreso_observaciones_url
            FROM solicitudes_vehiculos s
            LEFT JOIN vehiculos v ON s.vehiculo_id = v.id
            LEFT JOIN historial_uso_vehiculos hu ON s.id = hu.solicitud_id
            WHERE s.usuario_id = :user_id
            ORDER BY s.fecha_creacion DESC
        ");
        $stmt_solicitudes->bindParam(':user_id', $user_id);
        $stmt_solicitudes->execute();
        $solicitudes_usuario = $stmt_solicitudes->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al cargar mis solicitudes y historial: " . $e->getMessage());
        $error_message = 'No se pudieron cargar tus solicitudes o historial en este momento.';
    }
}

// Mostrar mensajes de éxito/error de la redirección
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Solicitudes - Flotilla Interna</title>
    <!-- Eliminar Bootstrap y Bootstrap Icons -->
    <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> -->
    <!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"> -->
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
              parchment: '#F4ECD6',
            }
          }
        }
      }
    </script>
    <link rel="stylesheet" href="css/style.css">
    <style>
        #vehicle-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
        }
        .text-danger {
            color: #dc3545 !important;
        }
        button[disabled] {
            cursor: not-allowed;
            opacity: 0.6;
        }
    </style>
</head>

<body class="bg-parchment min-h-screen">
    <?php
    $nombre_usuario_sesion = $_SESSION['user_name'] ?? 'Usuario';
    $rol_usuario_sesion = $_SESSION['user_role'] ?? 'empleado';
    require_once '../app/includes/navbar.php';
    ?>
    <?php require_once '../app/includes/alert_banner.php'; // Incluir el banner de alertas 
    ?>

    <div class="container mx-auto px-4 py-6">
        <h1 class="text-3xl font-bold text-darkpurple mb-6">Mis Solicitudes y Historial de Uso</h1>

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

        <?php if (empty($solicitudes_usuario)): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded" role="alert">
                Aún no has realizado ninguna solicitud de vehículo o no tienes historial de uso. ¡Anímate a <a href="solicitar_vehiculo.php" class="font-semibold underline">solicitar uno</a>!
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-cambridge2">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-cambridge1 text-white">
                                <th class="px-4 py-3 text-left">ID Sol.</th>
                                <th class="px-4 py-3 text-left">Salida Deseada</th>
                                <th class="px-4 py-3 text-left">Regreso Deseada</th>
                                <th class="px-4 py-3 text-left">Evento</th>
                                <th class="px-4 py-3 text-left">Descripción</th>
                                <th class="px-4 py-3 text-left">Vehículo Asignado</th>
                                <th class="px-4 py-3 text-left">Estatus</th>
                                <th class="px-4 py-3 text-left">Estado Vehículo</th>
                                <th class="px-4 py-3 text-left">Acciones</th>
                                <th class="px-4 py-3 text-left">Ver Detalles</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitudes_usuario as $solicitud): ?>
                                <tr class="border-b border-cambridge2 hover:bg-parchment">
                                    <td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars($solicitud['solicitud_id']); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_salida_solicitada'])); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_regreso_solicitada'])); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($solicitud['evento']); ?></td>
                                    <td class="px-4 py-3 text-sm text-mountbatten"><?php echo htmlspecialchars($solicitud['descripcion']); ?></td>
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
                                        <?php
                                        // Determinar el estado del vehículo basado en el historial
                                        $vehicle_status = 'Sin uso';
                                        $vehicle_status_class = 'bg-gray-500 text-white';
                                        
                                        if ($solicitud['historial_id']) {
                                            if ($solicitud['fecha_salida_real'] && !$solicitud['fecha_regreso_real']) {
                                                $vehicle_status = 'En uso';
                                                $vehicle_status_class = 'bg-cambridge1 text-white';
                                            } elseif ($solicitud['fecha_regreso_real']) {
                                                $vehicle_status = 'Completado';
                                                $vehicle_status_class = 'bg-green-500 text-white';
                                            }
                                        }
                                        ?>
                                        <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?php echo $vehicle_status_class; ?>"><?php echo $vehicle_status; ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <?php if ($solicitud['estatus_solicitud'] === 'aprobada' && !$solicitud['historial_id']): ?>
                                                <button type="button" class="bg-green-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-green-600 transition" data-bs-toggle="modal" data-bs-target="#salidaVehiculoModal" data-solicitud-id="<?php echo $solicitud['solicitud_id']; ?>" data-vehiculo-id="<?php echo $solicitud['vehiculo_id']; ?>" data-placas="<?php echo htmlspecialchars($solicitud['placas']); ?>">
                                                    <i class="bi bi-play-circle"></i> Salida
                                                </button>
                                            <?php elseif ($solicitud['estatus_solicitud'] === 'en_curso' && $solicitud['fecha_salida_real'] && !$solicitud['fecha_regreso_real']): ?>
                                                <button type="button" class="bg-red-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-600 transition" data-bs-toggle="modal" data-bs-target="#regresoVehiculoModal" data-solicitud-id="<?php echo $solicitud['solicitud_id']; ?>" data-vehiculo-id="<?php echo $solicitud['vehiculo_id']; ?>" data-placas="<?php echo htmlspecialchars($solicitud['placas']); ?>" data-km-salida="<?php echo $solicitud['kilometraje_salida']; ?>">
                                                    <i class="bi bi-stop-circle"></i> Regreso
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <button type="button" class="bg-cambridge1 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-cambridge2 transition" data-bs-toggle="modal" data-bs-target="#detallesSolicitudModal" data-solicitud="<?php echo htmlspecialchars(json_encode($solicitud)); ?>">
                                            <i class="bi bi-eye"></i> Ver
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="modal fade" id="useVehicleModal" tabindex="-1" aria-labelledby="useVehicleModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="useVehicleModalLabel"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="mis_solicitudes.php" method="POST" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="action_uso" id="useAction">
                            <input type="hidden" name="solicitud_id" id="useSolicitudId">
                            <input type="hidden" name="vehiculo_id" id="useVehiculoId">

                            <p>Vehículo: <strong id="useVehicleInfo"></strong></p>
                            <div class="mb-3">
                                <label for="kilometraje" class="form-label">Kilometraje Actual</label>
                                <input type="number" class="form-control" id="kilometraje" name="kilometraje" min="0" required>
                                <small class="form-text text-muted" id="currentKmHint"></small>
                            </div>
                            <div class="mb-3">
                                <label for="nivel_combustible" class="form-label">Nivel de Combustible (%)</label>
                                <input type="number" class="form-control" id="nivel_combustible" name="nivel_combustible" min="0" max="100" required>
                            </div>
                            <div class="mb-3">
                                <label for="fotos_medidores" class="form-label">Fotos de Evidencia del Kilometraje y Nivel de Combustible</label>
                                <input type="file" class="form-control" id="fotos_medidores" name="fotos_medidores[]" accept="image/*" multiple>
                                <small class="form-text text-muted">Sube fotos claras del tablero mostrando el kilometraje y del medidor de combustible (máx. <?php echo ini_get('upload_max_filesize'); ?> por archivo).</small>
                            </div>
                            <div class="mb-3">
                                <label for="tiene_observaciones" class="form-label">¿Hay observaciones o detalles que reportar?</label>
                                <select class="form-control" id="tiene_observaciones" name="tiene_observaciones">
                                    <option value="no">No</option>
                                    <option value="si">Sí</option>
                                </select>
                            </div>
                            <div id="seccion_observaciones" style="display: none;">
                                <div class="mb-3">
                                    <label for="observaciones" class="form-label">Observaciones (detalles, golpes, limpieza)</label>
                                    <textarea class="form-control" id="observaciones" name="observaciones" rows="3"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="fotos_observaciones" class="form-label">Fotos de Evidencia de las Observaciones</label>
                                    <input type="file" class="form-control" id="fotos_observaciones" name="fotos_observaciones[]" accept="image/*" multiple>
                                    <small class="form-text text-muted">Sube fotos que evidencien los detalles mencionados en las observaciones (golpes, limpieza, etc.) (máx. <?php echo ini_get('upload_max_filesize'); ?> por archivo).</small>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn" id="useSubmitBtn"></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-labelledby="viewDetailsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="viewDetailsModalLabel">Detalles de Solicitud</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <h6>Detalles de la Solicitud:</h6>
                        <p><strong>Salida Deseada:</strong> <span id="detailFechaSalida"></span></p>
                        <p><strong>Regreso Deseado:</strong> <span id="detailFechaRegreso"></span></p>
                        <p><strong>Evento:</strong> <span id="detailEvento"></span></p>
                        <p><strong>Descripción:</strong> <span id="detailDescripcion"></span></p>
                        <p><strong>Destino:</strong> <span id="detailDestino"></span></p>
                        <p><strong>Vehículo Asignado:</strong> <span id="detailVehiculoAsignado"></span></p>
                        <p><strong>Estatus:</strong> <span id="detailEstatus" class="badge"></span></p>
                        <p><strong>Observaciones del Gestor:</strong> <span id="detailObservacionesAprobacion"></span></p>

                        <h6 class="mt-4">Registro de Salida del Vehículo:</h6>
                        <div id="salidaDetails">
                            <p><strong>Kilometraje de Salida:</strong> <span id="detailKmSalida"></span> KM</p>
                            <p><strong>Combustible de Salida:</strong> <span id="detailGasSalida"></span>%</p>
                            <p><strong>Fecha y Hora de Salida Real:</strong> <span id="detailFechaSalidaReal"></span></p>
                            <p><strong>Observaciones al Salir:</strong> <span id="detailObsSalida"></span></p>
                            <div class="row" id="detailFotosSalida">
                            </div>
                        </div>
                        <div id="noSalidaDetails" class="alert alert-info text-center" style="display: none;">
                            Aún no se ha registrado la salida de este vehículo.
                        </div>

                        <h6 class="mt-4">Registro de Regreso del Vehículo:</h6>
                        <div id="regresoDetails">
                            <p><strong>Kilometraje de Regreso:</strong> <span id="detailKmRegreso"></span> KM</p>
                            <p><strong>Combustible de Regreso:</strong> <span id="detailGasRegreso"></span>%</p>
                            <p><strong>Fecha y Hora de Regreso Real:</strong> <span id="detailFechaRegresoReal"></span></p>
                            <p><strong>Observaciones al Regresar:</strong> <span id="detailObsRegreso"></span></p>
                            <div class="row" id="detailFotosRegreso">
                            </div>
                        </div>
                        <div id="noRegresoDetails" class="alert alert-info text-center" style="display: none;">
                            Aún no se ha registrado el regreso de este vehículo.
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
    <script src="js/main.js"></script>
    <script>
        // JavaScript para manejar el modal de Marcar Salida/Regreso
        document.addEventListener('DOMContentLoaded', function() {
            var useVehicleModal = document.getElementById('useVehicleModal');
            useVehicleModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var action = button.getAttribute('data-action');
                var solicitudId = button.getAttribute('data-solicitud-id');
                var vehiculoId = button.getAttribute('data-vehiculo-id');
                var vehiculoInfo = button.getAttribute('data-vehiculo-info');
                var kilometrajeActual = button.getAttribute('data-kilometraje-actual');

                var modalTitle = useVehicleModal.querySelector('#useVehicleModalLabel');
                var useAction = useVehicleModal.querySelector('#useAction');
                var useSolicitudId = useVehicleModal.querySelector('#useSolicitudId');
                var useVehiculoId = useVehicleModal.querySelector('#useVehiculoId');
                var useVehicleInfo = useVehicleModal.querySelector('#useVehicleInfo');
                var kilometrajeInput = useVehicleModal.querySelector('#kilometraje');
                var currentKmHint = useVehicleModal.querySelector('#currentKmHint');
                var useSubmitBtn = useVehicleModal.querySelector('#useSubmitBtn');
                var form = useVehicleModal.querySelector('form');

                form.reset();

                useSolicitudId.value = solicitudId;
                useVehiculoId.value = vehiculoId;
                useVehicleInfo.textContent = vehiculoInfo;
                kilometrajeInput.value = kilometrajeActual;

                if (action === 'marcar_salida') {
                    modalTitle.textContent = 'Marcar Salida del Vehículo';
                    useAction.value = 'marcar_salida';
                    useSubmitBtn.textContent = 'Registrar Salida';
                    useSubmitBtn.className = 'btn btn-primary';
                    currentKmHint.textContent = 'Kilometraje actual del vehículo: ' + kilometrajeActual + ' KM (debe ser mayor o igual)';
                    kilometrajeInput.min = kilometrajeActual;
                } else if (action === 'marcar_regreso') {
                    modalTitle.textContent = 'Marcar Regreso del Vehículo';
                    useAction.value = 'marcar_regreso';
                    useSubmitBtn.textContent = 'Registrar Regreso';
                    useSubmitBtn.className = 'btn btn-secondary';
                    currentKmHint.textContent = 'Kilometraje de salida registrado: X KM (el de regreso debe ser mayor)';
                }
            });

            // Mostrar/ocultar sección de observaciones según selección
            document.getElementById('tiene_observaciones').addEventListener('change', function() {
                var seccionObservaciones = document.getElementById('seccion_observaciones');
                if (this.value === 'si') {
                    seccionObservaciones.style.display = 'block';
                } else {
                    seccionObservaciones.style.display = 'none';
                }
            });

            // Función para formatear fechas para la lista y modales (Solución para "Invalid Date" más robusta)
            function formatDateTime(dateTimeString) {
                // Manejar valores null, cadenas vacías o la fecha "cero" de MySQL
                if (!dateTimeString || dateTimeString === '0000-00-00 00:00:00') {
                    return 'N/A';
                }
                // Reemplazar el espacio con 'T' para asegurar el formato ISO 8601
                const isoDateTimeString = dateTimeString.replace(' ', 'T');
                let date = new Date(isoDateTimeString);

                // Si new Date() aún no funciona (ej. en Safari con algunos formatos), intentar parseo manual
                if (isNaN(date.getTime())) {
                    const parts = dateTimeString.match(/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/);
                    if (parts) {
                        // Crear fecha en UTC para evitar problemas de zona horaria si no se especifica
                        date = new Date(Date.UTC(parts[1], parts[2] - 1, parts[3], parts[4], parts[5], parts[6]));
                    } else {
                        console.error("Fecha inválida no parseable:", dateTimeString);
                        return 'Fecha Inválida';
                    }
                }

                const options = {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                };
                return date.toLocaleString('es-MX', options);
            }

            // JavaScript para manejar el modal de Ver Detalles
            var viewDetailsModal = document.getElementById('viewDetailsModal');
            viewDetailsModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;

                document.getElementById('detailFechaSalida').textContent = formatDateTime(button.getAttribute('data-fecha-salida'));
                document.getElementById('detailFechaRegreso').textContent = formatDateTime(button.getAttribute('data-regreso'));
                document.getElementById('detailEvento').textContent = button.getAttribute('data-evento');
                document.getElementById('detailDescripcion').textContent = button.getAttribute('data-descripcion');
                document.getElementById('detailDestino').textContent = button.getAttribute('data-destino');
                document.getElementById('detailVehiculoAsignado').textContent = button.getAttribute('data-vehiculo-info');

                var statusBadge = document.getElementById('detailEstatus');
                statusBadge.textContent = button.getAttribute('data-estatus');
                statusBadge.className = 'badge';
                switch (button.getAttribute('data-estatus').toLowerCase()) {
                    case 'pendiente':
                        statusBadge.classList.add('bg-warning', 'text-dark');
                        break;
                    case 'aprobada':
                        statusBadge.classList.add('bg-success');
                        break;
                    case 'rechazada':
                        statusBadge.classList.add('bg-danger');
                        break;
                    case 'en_curso':
                        statusBadge.classList.add('bg-primary');
                        break;
                    case 'completada':
                        statusBadge.classList.add('bg-secondary');
                        break;
                    case 'cancelada':
                        statusBadge.classList.add('bg-info');
                        break;
                }
                document.getElementById('detailObservacionesAprobacion').textContent = button.getAttribute('data-observaciones-aprobacion') || 'Ninguna observación.';

                // Detalles de Salida
                var historialId = button.getAttribute('data-historial-id');
                var salidaDetails = document.getElementById('salidaDetails');
                var noSalidaDetails = document.getElementById('noSalidaDetails');
                var regresoDetails = document.getElementById('regresoDetails');
                var noRegresoDetails = document.getElementById('noRegresoDetails');

                if (historialId) {
                    salidaDetails.style.display = 'block';
                    noSalidaDetails.style.display = 'none';
                    document.getElementById('detailKmSalida').textContent = button.getAttribute('data-km-salida');
                    document.getElementById('detailGasSalida').textContent = button.getAttribute('data-gas-salida');
                    document.getElementById('detailFechaSalidaReal').textContent = formatDateTime(button.getAttribute('data-fecha-salida-real'));
                    document.getElementById('detailObsSalida').textContent = button.getAttribute('data-obs-salida') || 'Ninguna.';

                    // Cargar fotos de salida
                    var fotosSalidaContainer = document.getElementById('detailFotosSalida');
                    fotosSalidaContainer.innerHTML = '';
                    var fotosSalidaMedidores = JSON.parse(button.getAttribute('data-fotos-salida-medidores') || '[]');
                    var fotosSalidaObservaciones = JSON.parse(button.getAttribute('data-fotos-salida-observaciones') || '[]');
                    
                    if (fotosSalidaMedidores.length > 0 || fotosSalidaObservaciones.length > 0) {
                        // Mostrar fotos de medidores
                        if (fotosSalidaMedidores.length > 0) {
                            fotosSalidaContainer.innerHTML += '<div class="col-12"><h6>Fotos de Kilometraje y Combustible:</h6></div>';
                            fotosSalidaMedidores.forEach(url => {
                                var col = document.createElement('div');
                                col.className = 'col-6 col-md-4 mb-3';
                                var img = document.createElement('img');
                                img.src = url;
                                img.alt = 'Evidencia de medidores en salida';
                                img.className = 'img-fluid rounded shadow-sm';
                                img.style.cursor = 'pointer';
                                img.onclick = () => window.open(url, '_blank');
                                col.appendChild(img);
                                fotosSalidaContainer.appendChild(col);
                            });
                        }
                        
                        // Mostrar fotos de observaciones
                        if (fotosSalidaObservaciones.length > 0) {
                            fotosSalidaContainer.innerHTML += '<div class="col-12"><h6 class="mt-3">Fotos de Observaciones:</h6></div>';
                            fotosSalidaObservaciones.forEach(url => {
                                var col = document.createElement('div');
                                col.className = 'col-6 col-md-4 mb-3';
                                var img = document.createElement('img');
                                img.src = url;
                                img.alt = 'Evidencia de observaciones en salida';
                                img.className = 'img-fluid rounded shadow-sm';
                                img.style.cursor = 'pointer';
                                img.onclick = () => window.open(url, '_blank');
                                col.appendChild(img);
                                fotosSalidaContainer.appendChild(col);
                            });
                        }
                    } else {
                        fotosSalidaContainer.innerHTML = '<div class="col-12"><p class="text-muted">No hay fotos de evidencia.</p></div>';
                    }

                    // Detalles de Regreso
                    if (button.getAttribute('data-km-regreso') && button.getAttribute('data-fecha-regreso-real')) {
                        regresoDetails.style.display = 'block';
                        noRegresoDetails.style.display = 'none';
                        document.getElementById('detailKmRegreso').textContent = button.getAttribute('data-km-regreso');
                        document.getElementById('detailGasRegreso').textContent = button.getAttribute('data-gas-regreso');
                        document.getElementById('detailFechaRegresoReal').textContent = formatDateTime(button.getAttribute('data-fecha-regreso-real'));
                        document.getElementById('detailObsRegreso').textContent = button.getAttribute('data-obs-regreso') || 'Ninguna.';

                        // Cargar fotos de regreso
                        var fotosRegresoContainer = document.getElementById('detailFotosRegreso');
                        fotosRegresoContainer.innerHTML = '';
                        var fotosRegresoMedidores = JSON.parse(button.getAttribute('data-fotos-regreso-medidores') || '[]');
                        var fotosRegresoObservaciones = JSON.parse(button.getAttribute('data-fotos-regreso-observaciones') || '[]');
                        
                        if (fotosRegresoMedidores.length > 0 || fotosRegresoObservaciones.length > 0) {
                            // Mostrar fotos de medidores
                            if (fotosRegresoMedidores.length > 0) {
                                fotosRegresoContainer.innerHTML += '<div class="col-12"><h6>Fotos de Kilometraje y Combustible:</h6></div>';
                                fotosRegresoMedidores.forEach(url => {
                                    var col = document.createElement('div');
                                    col.className = 'col-6 col-md-4 mb-3';
                                    var img = document.createElement('img');
                                    img.src = url;
                                    img.alt = 'Evidencia de medidores en regreso';
                                    img.className = 'img-fluid rounded shadow-sm';
                                    img.style.cursor = 'pointer';
                                    img.onclick = () => window.open(url, '_blank');
                                    col.appendChild(img);
                                    fotosRegresoContainer.appendChild(col);
                                });
                            }
                            
                            // Mostrar fotos de observaciones
                            if (fotosRegresoObservaciones.length > 0) {
                                fotosRegresoContainer.innerHTML += '<div class="col-12"><h6 class="mt-3">Fotos de Observaciones:</h6></div>';
                                fotosRegresoObservaciones.forEach(url => {
                                    var col = document.createElement('div');
                                    col.className = 'col-6 col-md-4 mb-3';
                                    var img = document.createElement('img');
                                    img.src = url;
                                    img.alt = 'Evidencia de observaciones en regreso';
                                    img.className = 'img-fluid rounded shadow-sm';
                                    img.style.cursor = 'pointer';
                                    img.onclick = () => window.open(url, '_blank');
                                    col.appendChild(img);
                                    fotosRegresoContainer.appendChild(col);
                                });
                            }
                        } else {
                            fotosRegresoContainer.innerHTML = '<div class="col-12"><p class="text-muted">No hay fotos de evidencia.</p></div>';
                        }

                    } else {
                        regresoDetails.style.display = 'none';
                        noRegresoDetails.style.display = 'block';
                    }

                } else {
                    salidaDetails.style.display = 'none';
                    noSalidaDetails.style.display = 'block';
                    regresoDetails.style.display = 'none';
                    noRegresoDetails.style.display = 'block';
                }
            });
        });

    // Función para verificar el estado del vehículo
    function checkVehicleStatus(vehiculoId) {
        fetch(`api/get_vehiculo_status.php?vehiculo_id=${vehiculoId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.status) {
                    const statusElement = document.getElementById(`vehicle-status-${vehiculoId}`);
                    const buttons = document.querySelectorAll(`button[data-vehiculo-id="${vehiculoId}"]`);
                    
                    if (statusElement) {
                        // Formatear el estado para mostrar
                        const estadoFormateado = data.status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                        statusElement.textContent = estadoFormateado;
                        
                        // Actualizar clases visuales según el estado
                        statusElement.className = ''; // Limpiar clases existentes
                        if (['en_mantenimiento', 'inactivo', 'en_uso'].includes(data.status)) {
                            statusElement.classList.add('text-danger');
                        } else if (data.status === 'activo') {
                            statusElement.classList.add('text-success');
                        }

                        // Actualizar todos los botones asociados al vehículo
                        buttons.forEach(button => {
                            if (['en_mantenimiento', 'inactivo', 'en_uso'].includes(data.status)) {
                                button.disabled = true;
                                button.title = `Vehículo no disponible: ${estadoFormateado}`;
                                // Agregar clase visual para botones deshabilitados
                                button.classList.add('btn-disabled');
                            } else {
                                button.disabled = false;
                                button.title = '';
                                button.classList.remove('btn-disabled');
                            }
                        });

                        console.log(`Estado actualizado para vehículo ${vehiculoId}: ${data.status} at ${data.timestamp}`);
                    }
                }
            })
            .catch(error => {
                console.error('Error al verificar el estado del vehículo:', error);
                const statusElement = document.getElementById(`vehicle-status-${vehiculoId}`);
                if (statusElement) {
                    statusElement.textContent = 'Error al verificar estado';
                    statusElement.classList.add('text-warning');
                }
            });
        }

        // Verificar el estado cada 30 segundos
        document.addEventListener('DOMContentLoaded', function() {
            const buttons = document.querySelectorAll('[data-vehiculo-id]');
            buttons.forEach(button => {
                const vehiculoId = button.dataset.vehiculoId;
                checkVehicleStatus(vehiculoId, button);
                setInterval(() => checkVehicleStatus(vehiculoId, button), 30000);
            });
        });
    </script>
</body>

</html>