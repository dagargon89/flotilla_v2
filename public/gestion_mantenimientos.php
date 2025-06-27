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
              parchment: '#F4ECD6',
            }
          }
        }
      }
    </script>
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

        <button type="button" class="bg-cambridge2 text-darkpurple px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition mb-6" data-bs-toggle="modal" data-bs-target="#addEditMaintenanceModal" data-action="add">
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
                                            <button type="button" class="bg-cambridge1 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-cambridge2 transition" data-bs-toggle="modal" data-bs-target="#addEditMaintenanceModal" data-action="edit"
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
                                            <button type="button" class="bg-red-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-700 transition" data-bs-toggle="modal" data-bs-target="#deleteMaintenanceModal" data-id="<?php echo htmlspecialchars($mantenimiento['id']); ?>" data-tipo="<?php echo htmlspecialchars($mantenimiento['tipo_mantenimiento']); ?>" data-placas="<?php echo htmlspecialchars($mantenimiento['placas']); ?>">
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

        <div class="modal fade" id="addEditMaintenanceModal" tabindex="-1" aria-labelledby="addEditMaintenanceModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addEditMaintenanceModalLabel"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_mantenimientos.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="action" id="modalActionMaintenance">
                            <input type="hidden" name="id" id="maintenanceId">

                            <div class="mb-3">
                                <label for="vehiculo_id" class="form-label">Vehículo</label>
                                <select class="form-select" id="vehiculo_id" name="vehiculo_id" required>
                                    <option value="">Selecciona un vehículo...</option>
                                    <?php foreach ($vehiculos_flotilla as $vehiculo_opt): ?>
                                        <option value="<?php echo htmlspecialchars($vehiculo_opt['id']); ?>">
                                            <?php echo htmlspecialchars($vehiculo_opt['marca'] . ' ' . $vehiculo_opt['modelo'] . ' (' . $vehiculo_opt['placas'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="tipo_mantenimiento" class="form-label">Tipo de Mantenimiento</label>
                                <input type="text" class="form-control" id="tipo_mantenimiento" name="tipo_mantenimiento" required>
                            </div>
                            <div class="mb-3">
                                <label for="fecha_mantenimiento" class="form-label">Fecha y Hora del Mantenimiento</label>
                                <input type="datetime-local" class="form-control" id="fecha_mantenimiento" name="fecha_mantenimiento" required>
                            </div>
                            <div class="mb-3">
                                <label for="kilometraje_mantenimiento" class="form-label">Kilometraje del Vehículo</label>
                                <input type="number" class="form-control" id="kilometraje_mantenimiento" name="kilometraje_mantenimiento" min="0" required>
                            </div>
                            <div class="mb-3">
                                <label for="costo" class="form-label">Costo ($)</label>
                                <input type="number" class="form-control" id="costo" name="costo" step="0.01" min="0">
                            </div>
                            <div class="mb-3">
                                <label for="taller" class="form-label">Taller / Proveedor</label>
                                <input type="text" class="form-control" id="taller" name="taller">
                            </div>
                            <div class="mb-3">
                                <label for="observaciones_mantenimiento" class="form-label">Observaciones</label>
                                <textarea class="form-control" id="observaciones_mantenimiento" name="observaciones" rows="3"></textarea>
                            </div>
                            <hr>
                            <h6>Próximo Mantenimiento (Opcional)</h6>
                            <div class="mb-3">
                                <label for="proximo_mantenimiento_km" class="form-label">Próximo KM</label>
                                <input type="number" class="form-control" id="proximo_mantenimiento_km" name="proximo_mantenimiento_km" min="0">
                            </div>
                            <div class="mb-3">
                                <label for="proximo_mantenimiento_fecha" class="form-label">Próxima Fecha</label>
                                <input type="date" class="form-control" id="proximo_mantenimiento_fecha" name="proximo_mantenimiento_fecha">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" id="submitMaintenanceBtn"></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteMaintenanceModal" tabindex="-1" aria-labelledby="deleteMaintenanceModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteMaintenanceModalLabel">Confirmar Eliminación</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_mantenimientos.php" method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteMaintenanceId">
                        <div class="modal-body">
                            ¿Estás seguro de que quieres eliminar el mantenimiento <strong id="deleteMaintenanceType"></strong> para el vehículo con placas <strong id="deleteMaintenancePlacas"></strong>?
                            Esta acción es irreversible.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-danger">Eliminar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="js/main.js"></script>
    <script>
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

            var addEditMaintenanceModal = document.getElementById('addEditMaintenanceModal');
            addEditMaintenanceModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var action = button.getAttribute('data-action');

                var modalTitle = addEditMaintenanceModal.querySelector('#addEditMaintenanceModalLabel');
                var modalActionInput = addEditMaintenanceModal.querySelector('#modalActionMaintenance');
                var maintenanceIdInput = addEditMaintenanceModal.querySelector('#maintenanceId');
                var submitBtn = addEditMaintenanceModal.querySelector('#submitMaintenanceBtn');
                var form = addEditMaintenanceModal.querySelector('form');

                form.reset();

                if (action === 'add') {
                    modalTitle.textContent = 'Registrar Nuevo Mantenimiento';
                    modalActionInput.value = 'add';
                    submitBtn.textContent = 'Guardar Mantenimiento';
                    submitBtn.className = 'btn btn-primary';
                    maintenanceIdInput.value = '';
                    flatpickr("#fecha_mantenimiento").setDate(new Date());
                    flatpickr("#proximo_mantenimiento_fecha").clear();
                } else if (action === 'edit') {
                    modalTitle.textContent = 'Editar Mantenimiento';
                    modalActionInput.value = 'edit';
                    submitBtn.textContent = 'Actualizar Mantenimiento';
                    submitBtn.className = 'btn btn-info text-white';

                    maintenanceIdInput.value = button.getAttribute('data-id');
                    addEditMaintenanceModal.querySelector('#vehiculo_id').value = button.getAttribute('data-vehiculo-id');
                    addEditMaintenanceModal.querySelector('#tipo_mantenimiento').value = button.getAttribute('data-tipo-mantenimiento');
                    addEditMaintenanceModal.querySelector('#fecha_mantenimiento').value = button.getAttribute('data-fecha-mantenimiento');
                    addEditMaintenanceModal.querySelector('#kilometraje_mantenimiento').value = button.getAttribute('data-kilometraje-mantenimiento');
                    addEditMaintenanceModal.querySelector('#costo').value = button.getAttribute('data-costo');
                    addEditMaintenanceModal.querySelector('#taller').value = button.getAttribute('data-taller');
                    addEditMaintenanceModal.querySelector('#observaciones_mantenimiento').value = button.getAttribute('data-observaciones');
                    addEditMaintenanceModal.querySelector('#proximo_mantenimiento_km').value = button.getAttribute('data-proximo-mantenimiento-km');
                    addEditMaintenanceModal.querySelector('#proximo_mantenimiento_fecha').value = button.getAttribute('data-proximo-mantenimiento-fecha');

                    flatpickr("#fecha_mantenimiento").setDate(button.getAttribute('data-fecha-mantenimiento'));
                    if (button.getAttribute('data-proximo-mantenimiento-fecha')) {
                        flatpickr("#proximo_mantenimiento_fecha").setDate(button.getAttribute('data-proximo-mantenimiento-fecha'));
                    } else {
                        flatpickr("#proximo_mantenimiento_fecha").clear();
                    }
                }
            });

            var deleteMaintenanceModal = document.getElementById('deleteMaintenanceModal');
            if (deleteMaintenanceModal) {
                deleteMaintenanceModal.addEventListener('show.bs.modal', function(event) {
                    var button = event.relatedTarget;
                    var maintenanceId = button.getAttribute('data-id');
                    var maintenanceType = button.getAttribute('data-tipo');
                    var maintenancePlacas = button.getAttribute('data-placas');

                    var modalMaintenanceId = deleteMaintenanceModal.querySelector('#deleteMaintenanceId');
                    var modalMaintenanceType = deleteMaintenanceModal.querySelector('#deleteMaintenanceType');
                    var modalMaintenancePlacas = deleteMaintenanceModal.querySelector('#deleteMaintenancePlacas');

                    modalMaintenanceId.value = maintenanceId;
                    modalMaintenanceType.textContent = maintenanceType;
                    modalMaintenancePlacas.textContent = maintenancePlacas;
                });
            }
        });
    </script>
</body>

</html>