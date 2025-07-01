<?php
// public/gestion_mantenimientos.php - CÓDIGO COMPLETO Y CORREGIDO (Error Undefined $db y Rol Admin)
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


// **VERIFICACIÓN DE ROL:**
// Solo 'admin' puede acceder a esta página (corregido de la versión anterior).
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php'); // Redirige al dashboard si no tiene permisos
    exit();
}

$nombre_usuario_sesion = $_SESSION['user_name'];
$rol_usuario_sesion = $_SESSION['user_role'];

$success_message = '';
$error_message = $error_message ?? ''; // Mantener el error si ya viene del bloque de amonestaciones

$mantenimientos = []; // Para guardar la lista de mantenimientos
$vehiculos_flotilla = []; // Para el dropdown de vehículos en los modales

// --- Lógica para procesar el formulario (Agregar/Editar/Eliminar Mantenimiento) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; // 'add', 'edit', 'delete'

    try {
        if ($action === 'add') {
            $vehiculo_id = filter_var($_POST['vehiculo_id'] ?? '', FILTER_VALIDATE_INT);
            $tipo_mantenimiento = trim($_POST['tipo_mantenimiento'] ?? '');
            $fecha_mantenimiento = trim($_POST['fecha_mantenimiento'] ?? '');
            $kilometraje_mantenimiento = filter_var($_POST['kilometraje_mantenimiento'] ?? '', FILTER_VALIDATE_INT);
            $costo = filter_var($_POST['costo'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            $taller = trim($_POST['taller'] ?? '');
            $observaciones = trim($_POST['observaciones'] ?? '');
            $proximo_mantenimiento_km = filter_var($_POST['proximo_mantenimiento_km'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            $proximo_mantenimiento_fecha = trim($_POST['proximo_mantenimiento_fecha'] ?? '');

            if ($vehiculo_id === false || empty($tipo_mantenimiento) || empty($fecha_mantenimiento) || $kilometraje_mantenimiento === false) {
                throw new Exception("Por favor, completa los campos obligatorios para agregar el mantenimiento (vehículo, tipo, fecha, kilometraje).");
            }

            $proximo_mantenimiento_fecha = empty($proximo_mantenimiento_fecha) ? NULL : $proximo_mantenimiento_fecha;

            $db->beginTransaction();

            $stmt = $db->prepare("INSERT INTO mantenimientos (vehiculo_id, tipo_mantenimiento, fecha_mantenimiento, kilometraje_mantenimiento, costo, taller, observaciones, proximo_mantenimiento_km, proximo_mantenimiento_fecha) VALUES (:vehiculo_id, :tipo_mantenimiento, :fecha_mantenimiento, :kilometraje_mantenimiento, :costo, :taller, :observaciones, :proximo_mantenimiento_km, :proximo_mantenimiento_fecha)");
            $stmt->bindParam(':vehiculo_id', $vehiculo_id);
            $stmt->bindParam(':tipo_mantenimiento', $tipo_mantenimiento);
            $stmt->bindParam(':fecha_mantenimiento', $fecha_mantenimiento);
            $stmt->bindParam(':kilometraje_mantenimiento', $kilometraje_mantenimiento);
            $stmt->bindParam(':costo', $costo);
            $stmt->bindParam(':taller', $taller);
            $stmt->bindParam(':observaciones', $observaciones);
            $stmt->bindParam(':proximo_mantenimiento_km', $proximo_mantenimiento_km);
            $stmt->bindParam(':proximo_mantenimiento_fecha', $proximo_mantenimiento_fecha);
            $stmt->execute();

            $stmt_update_veh_km = $db->prepare("UPDATE vehiculos SET kilometraje_actual = GREATEST(kilometraje_actual, :new_km) WHERE id = :vehiculo_id");
            $stmt_update_veh_km->bindParam(':new_km', $kilometraje_mantenimiento);
            $stmt_update_veh_km->bindParam(':vehiculo_id', $vehiculo_id);
            $stmt_update_veh_km->execute();

            $db->commit();
            $success_message = 'Mantenimiento registrado con éxito.';
        } elseif ($action === 'edit') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            $vehiculo_id = filter_var($_POST['vehiculo_id'] ?? '', FILTER_VALIDATE_INT);
            $tipo_mantenimiento = trim($_POST['tipo_mantenimiento'] ?? '');
            $fecha_mantenimiento = trim($_POST['fecha_mantenimiento'] ?? '');
            $kilometraje_mantenimiento = filter_var($_POST['kilometraje_mantenimiento'] ?? '', FILTER_VALIDATE_INT);
            $costo = filter_var($_POST['costo'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            $taller = trim($_POST['taller'] ?? '');
            $observaciones = trim($_POST['observaciones'] ?? '');
            $proximo_mantenimiento_km = filter_var($_POST['proximo_mantenimiento_km'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            $proximo_mantenimiento_fecha = trim($_POST['proximo_mantenimiento_fecha'] ?? '');

            if ($id === false || $vehiculo_id === false || empty($tipo_mantenimiento) || empty($fecha_mantenimiento) || $kilometraje_mantenimiento === false) {
                throw new Exception("Por favor, completa los campos obligatorios para editar el mantenimiento.");
            }

            $proximo_mantenimiento_fecha = empty($proximo_mantenimiento_fecha) ? NULL : $proximo_mantenimiento_fecha;

            $db->beginTransaction();

            $stmt = $db->prepare("UPDATE mantenimientos SET vehiculo_id = :vehiculo_id, tipo_mantenimiento = :tipo_mantenimiento, fecha_mantenimiento = :fecha_mantenimiento, kilometraje_mantenimiento = :kilometraje_mantenimiento, costo = :costo, taller = :taller, observaciones = :observaciones, proximo_mantenimiento_km = :proximo_mantenimiento_km, proximo_mantenimiento_fecha = :proximo_mantenimiento_fecha WHERE id = :id");
            $stmt->bindParam(':vehiculo_id', $vehiculo_id);
            $stmt->bindParam(':tipo_mantenimiento', $tipo_mantenimiento);
            $stmt->bindParam(':fecha_mantenimiento', $fecha_mantenimiento);
            $stmt->bindParam(':kilometraje_mantenimiento', $kilometraje_mantenimiento);
            $stmt->bindParam(':costo', $costo);
            $stmt->bindParam(':taller', $taller);
            $stmt->bindParam(':observaciones', $observaciones);
            $stmt->bindParam(':proximo_mantenimiento_km', $proximo_mantenimiento_km);
            $stmt->bindParam(':proximo_mantenimiento_fecha', $proximo_mantenimiento_fecha);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            $stmt_update_veh_km = $db->prepare("UPDATE vehiculos SET kilometraje_actual = GREATEST(kilometraje_actual, :new_km) WHERE id = :vehiculo_id");
            $stmt_update_veh_km->bindParam(':new_km', $kilometraje_mantenimiento);
            $stmt_update_veh_km->bindParam(':vehiculo_id', $vehiculo_id);
            $stmt_update_veh_km->execute();

            $db->commit();
            $success_message = 'Mantenimiento actualizado con éxito.';
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false) {
                throw new Exception("ID de mantenimiento inválido para eliminar.");
            }

            $db->beginTransaction();
            $stmt = $db->prepare("DELETE FROM mantenimientos WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $db->commit();
            $success_message = 'Mantenimiento eliminado con éxito.';
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'Error: ' . $e->getMessage();
        error_log("Error en gestión de mantenimientos: " . $e->getMessage());
    }
}

// --- Obtener todos los mantenimientos para mostrar en la tabla ---
if ($db) {
    try {
        $stmt_mantenimientos = $db->query("
            SELECT m.*, v.marca, v.modelo, v.placas
            FROM mantenimientos m
            JOIN vehiculos v ON m.vehiculo_id = v.id
            ORDER BY m.fecha_mantenimiento DESC
        ");
        $mantenimientos = $stmt_mantenimientos->fetchAll(PDO::FETCH_ASSOC);

        // Obtener todos los vehículos para el dropdown en los modales
        $stmt_vehiculos_flotilla = $db->query("SELECT id, marca, modelo, placas FROM vehiculos ORDER BY marca, modelo");
        $vehiculos_flotilla = $stmt_vehiculos_flotilla->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al cargar mantenimientos o vehículos para el formulario: " . $e->getMessage());
        $error_message = 'No se pudieron cargar los datos.';
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Mantenimientos - Flotilla Interna</title>
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        <h1 class="text-3xl font-bold text-darkpurple mb-6">Gestión de Mantenimientos de Vehículos</h1>

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

        <button type="button" class="bg-cambridge2 text-darkpurple px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition mb-6" data-modal-target="addEditMaintenanceModal" data-action="add">
            <i class="bi bi-tools"></i> Registrar Nuevo Mantenimiento
        </button>

        <?php if (empty($mantenimientos)): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded" role="alert">
                No hay mantenimientos registrados.
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-cambridge2">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-cambridge1 text-white">
                                <th class="px-4 py-3 text-left">ID</th>
                                <th class="px-4 py-3 text-left">Vehículo</th>
                                <th class="px-4 py-3 text-left">Tipo de Mantenimiento</th>
                                <th class="px-4 py-3 text-left">Fecha</th>
                                <th class="px-4 py-3 text-left">KM</th>
                                <th class="px-4 py-3 text-left">Costo</th>
                                <th class="px-4 py-3 text-left">Taller</th>
                                <th class="px-4 py-3 text-left">Próx. KM</th>
                                <th class="px-4 py-3 text-left">Próx. Fecha</th>
                                <th class="px-4 py-3 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mantenimientos as $mantenimiento): ?>
                                <tr class="border-b border-cambridge2 hover:bg-parchment">
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($mantenimiento['id']); ?></td>
                                    <td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars($mantenimiento['marca'] . ' ' . $mantenimiento['modelo'] . ' (' . $mantenimiento['placas'] . ')'); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($mantenimiento['tipo_mantenimiento']); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo date('d/m/Y', strtotime($mantenimiento['fecha_mantenimiento'])); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars(number_format($mantenimiento['kilometraje_mantenimiento'])); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo $mantenimiento['costo'] !== null ? '$' . number_format($mantenimiento['costo'], 2) : 'N/A'; ?></td>
                                    <td class="px-4 py-3 text-sm text-mountbatten"><?php echo htmlspecialchars($mantenimiento['taller'] ?? 'N/A'); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo $mantenimiento['proximo_mantenimiento_km'] !== null ? htmlspecialchars(number_format($mantenimiento['proximo_mantenimiento_km'])) . ' KM' : 'N/A'; ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo $mantenimiento['proximo_mantenimiento_fecha'] !== null ? date('d/m/Y', strtotime($mantenimiento['proximo_mantenimiento_fecha'])) : 'N/A'; ?></td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <button type="button" class="bg-cambridge1 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-cambridge2 transition" data-modal-target="addEditMaintenanceModal" data-action="edit"
                                                data-id="<?php echo htmlspecialchars($mantenimiento['id']); ?>"
                                                data-vehiculo-id="<?php echo htmlspecialchars($mantenimiento['vehiculo_id']); ?>"
                                                data-tipo-mantenimiento="<?php echo htmlspecialchars($mantenimiento['tipo_mantenimiento']); ?>"
                                                data-fecha-mantenimiento="<?php echo date('Y-m-d\TH:i', strtotime($mantenimiento['fecha_mantenimiento'])); ?>"
                                                data-kilometraje-mantenimiento="<?php echo htmlspecialchars($mantenimiento['kilometraje_mantenimiento']); ?>"
                                                data-costo="<?php echo htmlspecialchars($mantenimiento['costo'] ?? ''); ?>"
                                                data-taller="<?php echo htmlspecialchars($mantenimiento['taller'] ?? ''); ?>"
                                                data-observaciones="<?php echo htmlspecialchars($mantenimiento['observaciones'] ?? ''); ?>"
                                                data-proximo-mantenimiento-km="<?php echo htmlspecialchars($mantenimiento['proximo_mantenimiento_km'] ?? ''); ?>"
                                                data-proximo-mantenimiento-fecha="<?php echo htmlspecialchars($mantenimiento['proximo_mantenimiento_fecha'] ? date('Y-m-d', strtotime($mantenimiento['proximo_mantenimiento_fecha'])) : ''); ?>">
                                                Editar
                                            </button>
                                            <button type="button" class="bg-red-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-700 transition" data-modal-target="deleteMaintenanceModal" data-id="<?php echo htmlspecialchars($mantenimiento['id']); ?>" data-tipo="<?php echo htmlspecialchars($mantenimiento['tipo_mantenimiento']); ?>" data-placas="<?php echo htmlspecialchars($mantenimiento['placas']); ?>">
                                                Eliminar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal para Agregar/Editar Mantenimiento -->
        <div id="addEditMaintenanceModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="addEditMaintenanceModalLabel"></h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('addEditMaintenanceModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form action="gestion_mantenimientos.php" method="POST">
                    <div class="p-6 space-y-4">
                        <input type="hidden" name="action" id="modalActionMaintenance">
                        <input type="hidden" name="id" id="maintenanceId">

                        <div>
                            <label for="vehiculo_id" class="block text-sm font-medium text-gray-700 mb-2">Vehículo</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="vehiculo_id" name="vehiculo_id" required>
                                <option value="">Selecciona un vehículo...</option>
                                <?php foreach ($vehiculos_flotilla as $vehiculo_opt): ?>
                                    <option value="<?php echo htmlspecialchars($vehiculo_opt['id']); ?>">
                                        <?php echo htmlspecialchars($vehiculo_opt['marca'] . ' ' . $vehiculo_opt['modelo'] . ' (' . $vehiculo_opt['placas'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="tipo_mantenimiento" class="block text-sm font-medium text-gray-700 mb-2">Tipo de Mantenimiento</label>
                            <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="tipo_mantenimiento" name="tipo_mantenimiento" required>
                        </div>
                        <div>
                            <label for="fecha_mantenimiento" class="block text-sm font-medium text-gray-700 mb-2">Fecha y Hora del Mantenimiento</label>
                            <input type="datetime-local" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="fecha_mantenimiento" name="fecha_mantenimiento" required>
                        </div>
                        <div>
                            <label for="kilometraje_mantenimiento" class="block text-sm font-medium text-gray-700 mb-2">Kilometraje del Vehículo</label>
                            <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="kilometraje_mantenimiento" name="kilometraje_mantenimiento" min="0" required>
                        </div>
                        <div>
                            <label for="costo" class="block text-sm font-medium text-gray-700 mb-2">Costo ($)</label>
                            <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="costo" name="costo" step="0.01" min="0">
                        </div>
                        <div>
                            <label for="taller" class="block text-sm font-medium text-gray-700 mb-2">Taller / Proveedor</label>
                            <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="taller" name="taller">
                        </div>
                        <div>
                            <label for="observaciones_mantenimiento" class="block text-sm font-medium text-gray-700 mb-2">Observaciones</label>
                            <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="observaciones_mantenimiento" name="observaciones" rows="3"></textarea>
                        </div>
                        <hr class="border-gray-300">
                        <h6 class="text-lg font-semibold text-gray-900">Próximo Mantenimiento (Opcional)</h6>
                        <div>
                            <label for="proximo_mantenimiento_km" class="block text-sm font-medium text-gray-700 mb-2">Próximo KM</label>
                            <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="proximo_mantenimiento_km" name="proximo_mantenimiento_km" min="0">
                        </div>
                        <div>
                            <label for="proximo_mantenimiento_fecha" class="block text-sm font-medium text-gray-700 mb-2">Próxima Fecha</label>
                            <input type="date" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="proximo_mantenimiento_fecha" name="proximo_mantenimiento_fecha">
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('addEditMaintenanceModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors" id="submitMaintenanceBtn"></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para Eliminar Mantenimiento -->
        <div id="deleteMaintenanceModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="deleteMaintenanceModalLabel">Confirmar Eliminación</h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('deleteMaintenanceModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form action="gestion_mantenimientos.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteMaintenanceId">
                    <div class="p-6">
                        <p class="text-gray-700">¿Estás seguro de que quieres eliminar el mantenimiento <strong id="deleteMaintenanceType"></strong> para el vehículo con placas <strong id="deleteMaintenancePlacas"></strong>?</p>
                        <p class="text-sm text-red-600 mt-2">Esta acción es irreversible.</p>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('deleteMaintenanceModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white bg-red-600 rounded-md hover:bg-red-700 transition-colors">Eliminar</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

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

        // JavaScript para manejar los modales de agregar/editar mantenimiento
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr("#fecha_mantenimiento", {
                enableTime: true,
                dateFormat: "Y-m-dTH:i",
                defaultDate: new Date()
            });
            flatpickr("#proximo_mantenimiento_fecha", {
                dateFormat: "Y-m-d",
                minDate: "today"
            });

            // Configurar botones para abrir modales
            document.querySelectorAll('[data-modal-target]').forEach(button => {
                button.addEventListener('click', function() {
                    const modalId = this.getAttribute('data-modal-target');
                    const action = this.getAttribute('data-action');

                    if (modalId === 'addEditMaintenanceModal') {
                        setupMaintenanceModal(action, this);
                    } else if (modalId === 'deleteMaintenanceModal') {
                        setupDeleteMaintenanceModal(this);
                    }

                    openModal(modalId);
                });
            });

            function setupMaintenanceModal(action, button) {
                var modalTitle = document.getElementById('addEditMaintenanceModalLabel');
                var modalActionInput = document.getElementById('modalActionMaintenance');
                var maintenanceIdInput = document.getElementById('maintenanceId');
                var submitBtn = document.getElementById('submitMaintenanceBtn');
                var form = document.querySelector('#addEditMaintenanceModal form');

                form.reset();

                if (action === 'add') {
                    modalTitle.textContent = 'Registrar Nuevo Mantenimiento';
                    modalActionInput.value = 'add';
                    submitBtn.textContent = 'Guardar Mantenimiento';
                    submitBtn.className = 'px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors';
                    maintenanceIdInput.value = '';
                    flatpickr("#fecha_mantenimiento").setDate(new Date());
                    flatpickr("#proximo_mantenimiento_fecha").clear();
                } else if (action === 'edit') {
                    modalTitle.textContent = 'Editar Mantenimiento';
                    modalActionInput.value = 'edit';
                    submitBtn.textContent = 'Actualizar Mantenimiento';
                    submitBtn.className = 'px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors';

                    maintenanceIdInput.value = button.getAttribute('data-id');
                    document.getElementById('vehiculo_id').value = button.getAttribute('data-vehiculo-id');
                    document.getElementById('tipo_mantenimiento').value = button.getAttribute('data-tipo-mantenimiento');
                    document.getElementById('fecha_mantenimiento').value = button.getAttribute('data-fecha-mantenimiento');
                    document.getElementById('kilometraje_mantenimiento').value = button.getAttribute('data-kilometraje-mantenimiento');
                    document.getElementById('costo').value = button.getAttribute('data-costo');
                    document.getElementById('taller').value = button.getAttribute('data-taller');
                    document.getElementById('observaciones_mantenimiento').value = button.getAttribute('data-observaciones');
                    document.getElementById('proximo_mantenimiento_km').value = button.getAttribute('data-proximo-mantenimiento-km');
                    document.getElementById('proximo_mantenimiento_fecha').value = button.getAttribute('data-proximo-mantenimiento-fecha');

                    flatpickr("#fecha_mantenimiento").setDate(button.getAttribute('data-fecha-mantenimiento'));
                    if (button.getAttribute('data-proximo-mantenimiento-fecha')) {
                        flatpickr("#proximo_mantenimiento_fecha").setDate(button.getAttribute('data-proximo-mantenimiento-fecha'));
                    } else {
                        flatpickr("#proximo_mantenimiento_fecha").clear();
                    }
                }
            }

            function setupDeleteMaintenanceModal(button) {
                var maintenanceId = button.getAttribute('data-id');
                var maintenanceType = button.getAttribute('data-tipo');
                var maintenancePlacas = button.getAttribute('data-placas');

                document.getElementById('deleteMaintenanceId').value = maintenanceId;
                document.getElementById('deleteMaintenanceType').textContent = maintenanceType;
                document.getElementById('deleteMaintenancePlacas').textContent = maintenancePlacas;
            }
        });
    </script>
</body>

</html>