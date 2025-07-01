<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
                v.estatus AS vehiculo_estatus,
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
                        parchment: '#FFFBFA',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="css/colors.css">
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
                                            <?php if ($solicitud['estatus_solicitud'] === 'aprobada' && !$solicitud['historial_id'] && ($solicitud['vehiculo_estatus'] !== 'en_mantenimiento' && $solicitud['vehiculo_estatus'] !== 'inactivo')): ?>
                                                <button type="button" class="bg-green-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-green-600 transition" data-modal-target="useVehicleModal" data-action="marcar_salida" data-solicitud-id="<?php echo $solicitud['solicitud_id']; ?>" data-vehiculo-id="<?php echo $solicitud['vehiculo_id']; ?>" data-vehiculo-info="<?php echo htmlspecialchars($solicitud['marca'] . ' ' . $solicitud['modelo'] . ' (' . $solicitud['placas'] . ')'); ?>" data-kilometraje-actual="<?php echo $solicitud['kilometraje_actual'] ?? ''; ?>">
                                                    Salida
                                                </button>
                                            <?php elseif ($solicitud['estatus_solicitud'] === 'aprobada' && !$solicitud['historial_id'] && ($solicitud['vehiculo_estatus'] === 'en_mantenimiento' || $solicitud['vehiculo_estatus'] === 'inactivo')): ?>
                                                <button type="button" class="bg-gray-400 text-white px-2 py-1 rounded text-xs font-semibold cursor-not-allowed opacity-60" disabled title="No disponible por estatus del vehículo">
                                                    Salida (No disponible)
                                                </button>
                                            <?php elseif ($solicitud['estatus_solicitud'] === 'en_curso' && $solicitud['fecha_salida_real'] && !$solicitud['fecha_regreso_real']): ?>
                                                <button type="button" class="bg-red-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-600 transition" data-modal-target="useVehicleModal" data-action="marcar_regreso" data-solicitud-id="<?php echo $solicitud['solicitud_id']; ?>" data-vehiculo-id="<?php echo $solicitud['vehiculo_id']; ?>" data-vehiculo-info="<?php echo htmlspecialchars($solicitud['marca'] . ' ' . $solicitud['modelo'] . ' (' . $solicitud['placas'] . ')'); ?>" data-kilometraje-salida="<?php echo $solicitud['kilometraje_salida'] ?? ''; ?>">
                                                    Regreso
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <button type="button" class="bg-cambridge1 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-cambridge2 transition" data-modal-target="viewDetailsModal" data-solicitud="<?php echo htmlspecialchars(json_encode($solicitud)); ?>">
                                            Ver
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal para Marcar Salida/Regreso -->
        <div id="useVehicleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="useVehicleModalLabel"></h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('useVehicleModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form action="mis_solicitudes.php" method="POST" enctype="multipart/form-data">
                    <div class="p-6 space-y-4">
                        <input type="hidden" name="action_uso" id="useAction">
                        <input type="hidden" name="solicitud_id" id="useSolicitudId">
                        <input type="hidden" name="vehiculo_id" id="useVehiculoId">

                        <p class="text-gray-700">Vehículo: <strong id="useVehicleInfo"></strong></p>
                        <div>
                            <label for="kilometraje" class="block text-sm font-medium text-gray-700 mb-2">Kilometraje Actual</label>
                            <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="kilometraje" name="kilometraje" min="0" required>
                            <small class="text-sm text-gray-500" id="currentKmHint"></small>
                        </div>
                        <div>
                            <label for="nivel_combustible" class="block text-sm font-medium text-gray-700 mb-2">Nivel de Combustible (%)</label>
                            <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="nivel_combustible" name="nivel_combustible" min="0" max="100" required>
                        </div>
                        <div>
                            <label for="fotos_medidores" class="block text-sm font-medium text-gray-700 mb-2">Fotos de Evidencia del Kilometraje y Nivel de Combustible</label>
                            <input type="file" class="w-full px-3 py-2 border border-cambridge1 rounded-lg focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-darkpurple bg-parchment transition file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-cambridge1 file:text-white hover:file:bg-cambridge2" id="fotos_medidores" name="fotos_medidores[]" accept="image/*" multiple capture="environment">
                            <small class="text-sm text-gray-500">Sube o toma fotos claras del tablero mostrando el kilometraje y del medidor de combustible (máx. <?php echo ini_get('upload_max_filesize'); ?> por archivo).</small>
                            <div id="preview_fotos_medidores" class="flex flex-wrap gap-2 mt-2"></div>
                        </div>
                        <div>
                            <label for="tiene_observaciones" class="block text-sm font-medium text-gray-700 mb-2">¿Hay observaciones o detalles que reportar?</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="tiene_observaciones" name="tiene_observaciones">
                                <option value="no">No</option>
                                <option value="si">Sí</option>
                            </select>
                        </div>
                        <div id="seccion_observaciones" class="hidden space-y-4">
                            <div>
                                <label for="observaciones" class="block text-sm font-medium text-gray-700 mb-2">Observaciones (detalles, golpes, limpieza)</label>
                                <textarea class="w-full px-3 py-2 border border-cambridge1 rounded-lg focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-darkpurple bg-parchment transition" id="observaciones" name="observaciones" rows="3"></textarea>
                            </div>
                            <div>
                                <label for="fotos_observaciones" class="block text-sm font-medium text-gray-700 mb-2">Fotos de Evidencia de las Observaciones</label>
                                <input type="file" class="w-full px-3 py-2 border border-cambridge1 rounded-lg focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-darkpurple bg-parchment transition file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-cambridge1 file:text-white hover:file:bg-cambridge2" id="fotos_observaciones" name="fotos_observaciones[]" accept="image/*" multiple capture="environment">
                                <small class="text-sm text-gray-500">Sube o toma fotos que evidencien los detalles mencionados en las observaciones (golpes, limpieza, etc.) (máx. <?php echo ini_get('upload_max_filesize'); ?> por archivo).</small>
                                <div id="preview_fotos_observaciones" class="flex flex-wrap gap-2 mt-2"></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('useVehicleModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white rounded-md transition-colors" id="useSubmitBtn"></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para Ver Detalles -->
        <div id="viewDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="viewDetailsModalLabel">Detalles de Solicitud</h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('viewDetailsModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="p-6 space-y-6">
                    <div>
                        <h6 class="text-lg font-semibold text-gray-900 mb-3">Detalles de la Solicitud:</h6>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <p class="text-gray-700"><strong>Salida Deseada:</strong> <span id="detailFechaSalida"></span></p>
                            <p class="text-gray-700"><strong>Regreso Deseado:</strong> <span id="detailFechaRegreso"></span></p>
                            <p class="text-gray-700"><strong>Evento:</strong> <span id="detailEvento"></span></p>
                            <p class="text-gray-700"><strong>Descripción:</strong> <span id="detailDescripcion"></span></p>
                            <p class="text-gray-700"><strong>Destino:</strong> <span id="detailDestino"></span></p>
                            <p class="text-gray-700"><strong>Vehículo Asignado:</strong> <span id="detailVehiculoAsignado"></span></p>
                            <p class="text-gray-700"><strong>Estatus:</strong> <span id="detailEstatus" class="inline-block px-2 py-1 text-xs font-semibold rounded-full"></span></p>
                            <p class="text-gray-700"><strong>Observaciones del Gestor:</strong> <span id="detailObservacionesAprobacion"></span></p>
                        </div>
                    </div>

                    <div>
                        <h6 class="text-lg font-semibold text-gray-900 mb-3">Registro de Salida del Vehículo:</h6>
                        <div id="salidaDetails" class="space-y-2">
                            <p class="text-gray-700"><strong>Kilometraje de Salida:</strong> <span id="detailKmSalida"></span> KM</p>
                            <p class="text-gray-700"><strong>Combustible de Salida:</strong> <span id="detailGasSalida"></span>%</p>
                            <p class="text-gray-700"><strong>Fecha y Hora de Salida Real:</strong> <span id="detailFechaSalidaReal"></span></p>
                            <p class="text-gray-700"><strong>Observaciones al Salir:</strong> <span id="detailObsSalida"></span></p>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4" id="detailFotosSalida">
                            </div>
                        </div>
                        <div id="noSalidaDetails" class="hidden bg-blue-50 border border-blue-200 rounded-md p-4 text-center text-blue-700">
                            Aún no se ha registrado la salida de este vehículo.
                        </div>
                    </div>

                    <div>
                        <h6 class="text-lg font-semibold text-gray-900 mb-3">Registro de Regreso del Vehículo:</h6>
                        <div id="regresoDetails" class="space-y-2">
                            <p class="text-gray-700"><strong>Kilometraje de Regreso:</strong> <span id="detailKmRegreso"></span> KM</p>
                            <p class="text-gray-700"><strong>Combustible de Regreso:</strong> <span id="detailGasRegreso"></span>%</p>
                            <p class="text-gray-700"><strong>Fecha y Hora de Regreso Real:</strong> <span id="detailFechaRegresoReal"></span></p>
                            <p class="text-gray-700"><strong>Observaciones al Regresar:</strong> <span id="detailObsRegreso"></span></p>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4" id="detailFotosRegreso">
                            </div>
                        </div>
                        <div id="noRegresoDetails" class="hidden bg-blue-50 border border-blue-200 rounded-md p-4 text-center text-blue-700">
                            Aún no se ha registrado el regreso de este vehículo.
                        </div>
                    </div>
                </div>
                <div class="flex justify-end p-6 border-t border-gray-200">
                    <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('viewDetailsModal')">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
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

        // JavaScript para manejar el modal de Marcar Salida/Regreso
        document.addEventListener('DOMContentLoaded', function() {
            // Configurar botones para abrir modales
            document.querySelectorAll('[data-modal-target]').forEach(button => {
                button.addEventListener('click', function() {
                    const modalId = this.getAttribute('data-modal-target');

                    if (modalId === 'useVehicleModal') {
                        setupUseVehicleModal(this);
                    } else if (modalId === 'viewDetailsModal') {
                        setupViewDetailsModal(this);
                    }

                    openModal(modalId);
                });
            });

            function setupUseVehicleModal(button) {
                var action = button.getAttribute('data-action');
                var solicitudId = button.getAttribute('data-solicitud-id');
                var vehiculoId = button.getAttribute('data-vehiculo-id');
                var vehiculoInfo = button.getAttribute('data-vehiculo-info');
                var kilometrajeActual = button.getAttribute('data-kilometraje-actual');
                var kilometrajeSalida = button.getAttribute('data-kilometraje-salida');

                var modalTitle = document.getElementById('useVehicleModalLabel');
                var useAction = document.getElementById('useAction');
                var useSolicitudId = document.getElementById('useSolicitudId');
                var useVehiculoId = document.getElementById('useVehiculoId');
                var useVehicleInfo = document.getElementById('useVehicleInfo');
                var kilometrajeInput = document.getElementById('kilometraje');
                var currentKmHint = document.getElementById('currentKmHint');
                var useSubmitBtn = document.getElementById('useSubmitBtn');
                var form = document.querySelector('#useVehicleModal form');

                form.reset();

                useSolicitudId.value = solicitudId;
                useVehiculoId.value = vehiculoId;
                useVehicleInfo.textContent = vehiculoInfo;

                if (action === 'marcar_salida') {
                    modalTitle.textContent = 'Marcar Salida del Vehículo';
                    useAction.value = 'marcar_salida';
                    useSubmitBtn.textContent = 'Registrar Salida';
                    useSubmitBtn.className = 'px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors';
                    currentKmHint.textContent = 'Kilometraje actual del vehículo: ' + kilometrajeActual + ' KM (debe ser mayor o igual)';
                    kilometrajeInput.min = kilometrajeActual;
                    kilometrajeInput.value = kilometrajeActual;
                } else if (action === 'marcar_regreso') {
                    modalTitle.textContent = 'Marcar Regreso del Vehículo';
                    useAction.value = 'marcar_regreso';
                    useSubmitBtn.textContent = 'Registrar Regreso';
                    useSubmitBtn.className = 'px-4 py-2 text-white bg-gray-600 rounded-md hover:bg-gray-700 transition-colors';
                    currentKmHint.textContent = 'Kilometraje de salida registrado: ' + kilometrajeSalida + ' KM (el de regreso debe ser mayor)';
                    kilometrajeInput.min = parseInt(kilometrajeSalida) + 1;
                }
            }

            function setupViewDetailsModal(button) {
                var solicitudData = JSON.parse(button.getAttribute('data-solicitud'));

                document.getElementById('detailFechaSalida').textContent = new Date(solicitudData.fecha_salida_solicitada).toLocaleString('es-MX');
                document.getElementById('detailFechaRegreso').textContent = new Date(solicitudData.fecha_regreso_solicitada).toLocaleString('es-MX');
                document.getElementById('detailEvento').textContent = solicitudData.evento;
                document.getElementById('detailDescripcion').textContent = solicitudData.descripcion;
                document.getElementById('detailDestino').textContent = solicitudData.destino;
                document.getElementById('detailVehiculoAsignado').textContent = solicitudData.marca ? solicitudData.marca + ' ' + solicitudData.modelo + ' (' + solicitudData.placas + ')' : 'Sin asignar';
                document.getElementById('detailObservacionesAprobacion').textContent = solicitudData.observaciones_aprobacion || 'Sin observaciones';

                // Aplicar clase de color según el estatus
                var estatusElement = document.getElementById('detailEstatus');
                estatusElement.textContent = solicitudData.estatus_solicitud.charAt(0).toUpperCase() + solicitudData.estatus_solicitud.slice(1);
                estatusElement.className = 'inline-block px-2 py-1 text-xs font-semibold rounded-full';

                switch (solicitudData.estatus_solicitud.toLowerCase()) {
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

                // Mostrar detalles de salida si existen
                if (solicitudData.fecha_salida_real) {
                    document.getElementById('salidaDetails').classList.remove('hidden');
                    document.getElementById('noSalidaDetails').classList.add('hidden');
                    document.getElementById('detailKmSalida').textContent = solicitudData.kilometraje_salida;
                    document.getElementById('detailGasSalida').textContent = solicitudData.combustible_salida;
                    document.getElementById('detailFechaSalidaReal').textContent = new Date(solicitudData.fecha_salida_real).toLocaleString('es-MX');
                    document.getElementById('detailObsSalida').textContent = solicitudData.observaciones_salida || 'Sin observaciones';

                    // Mostrar fotos de salida si existen
                    var fotosSalidaContainer = document.getElementById('detailFotosSalida');
                    fotosSalidaContainer.innerHTML = '';
                    if (solicitudData.fotos_salida) {
                        var fotosSalida = JSON.parse(solicitudData.fotos_salida);
                        fotosSalida.forEach(function(foto) {
                            var img = document.createElement('img');
                            img.src = 'uploads/' + foto;
                            img.className = 'w-full h-24 object-cover rounded-md';
                            img.alt = 'Foto de salida';
                            fotosSalidaContainer.appendChild(img);
                        });
                    }
                } else {
                    document.getElementById('salidaDetails').classList.add('hidden');
                    document.getElementById('noSalidaDetails').classList.remove('hidden');
                }

                // Mostrar detalles de regreso si existen
                if (solicitudData.fecha_regreso_real) {
                    document.getElementById('regresoDetails').classList.remove('hidden');
                    document.getElementById('noRegresoDetails').classList.add('hidden');
                    document.getElementById('detailKmRegreso').textContent = solicitudData.kilometraje_regreso;
                    document.getElementById('detailGasRegreso').textContent = solicitudData.combustible_regreso;
                    document.getElementById('detailFechaRegresoReal').textContent = new Date(solicitudData.fecha_regreso_real).toLocaleString('es-MX');
                    document.getElementById('detailObsRegreso').textContent = solicitudData.observaciones_regreso || 'Sin observaciones';

                    // Mostrar fotos de regreso si existen
                    var fotosRegresoContainer = document.getElementById('detailFotosRegreso');
                    fotosRegresoContainer.innerHTML = '';
                    if (solicitudData.fotos_regreso) {
                        var fotosRegreso = JSON.parse(solicitudData.fotos_regreso);
                        fotosRegreso.forEach(function(foto) {
                            var img = document.createElement('img');
                            img.src = 'uploads/' + foto;
                            img.className = 'w-full h-24 object-cover rounded-md';
                            img.alt = 'Foto de regreso';
                            fotosRegresoContainer.appendChild(img);
                        });
                    }
                } else {
                    document.getElementById('regresoDetails').classList.add('hidden');
                    document.getElementById('noRegresoDetails').classList.remove('hidden');
                }
            }

            // Mostrar/ocultar sección de observaciones según selección
            document.getElementById('tiene_observaciones').addEventListener('change', function() {
                var seccionObservaciones = document.getElementById('seccion_observaciones');
                if (this.value === 'si') {
                    seccionObservaciones.classList.remove('hidden');
                } else {
                    seccionObservaciones.classList.add('hidden');
                }
            });

            // Previsualización de imágenes para fotos_medidores y fotos_observaciones con opción de eliminar
            function previewImages(input, previewContainerId) {
                const preview = document.getElementById(previewContainerId);
                preview.innerHTML = '';
                if (input.files) {
                    // Convertir FileList a Array para manipulación
                    let filesArray = Array.from(input.files);
                    filesArray.forEach((file, idx) => {
                        if (!file.type.startsWith('image/')) return;
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const wrapper = document.createElement('div');
                            wrapper.className = 'relative inline-block';
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.className = 'h-20 w-20 object-cover rounded shadow border border-cambridge1';
                            // Botón de eliminar
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.innerHTML = '&times;';
                            btn.className = 'absolute top-0 right-0 bg-red-600 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs font-bold shadow hover:bg-red-700 focus:outline-none';
                            btn.title = 'Eliminar foto';
                            btn.onclick = function() {
                                filesArray.splice(idx, 1);
                                // Crear un nuevo FileList
                                const dataTransfer = new DataTransfer();
                                filesArray.forEach(f => dataTransfer.items.add(f));
                                input.files = dataTransfer.files;
                                previewImages(input, previewContainerId);
                            };
                            wrapper.appendChild(img);
                            wrapper.appendChild(btn);
                            preview.appendChild(wrapper);
                        };
                        reader.readAsDataURL(file);
                    });
                }
            }
            document.getElementById('fotos_medidores').addEventListener('change', function() {
                previewImages(this, 'preview_fotos_medidores');
            });
            if (document.getElementById('fotos_observaciones')) {
                document.getElementById('fotos_observaciones').addEventListener('change', function() {
                    previewImages(this, 'preview_fotos_observaciones');
                });
            }
        });
    </script>
</body>

</html>