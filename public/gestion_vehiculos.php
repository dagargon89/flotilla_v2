<?php
// public/gestion_vehiculos.php - CÓDIGO COMPLETO Y CORREGIDO (Error Undefined $db y Rol Admin)
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

$nombre_usuario = $_SESSION['user_name']; // Esta variable ya debería estar definida en la sesión
$rol_usuario = $_SESSION['user_role']; // Esta variable ya debería estar definida en la sesión

$success_message = '';
$error_message = $error_message ?? ''; // Mantener el error si ya viene del bloque de amonestaciones

$vehiculos = []; // Para guardar la lista de vehículos

// --- Lógica para procesar el formulario (Agregar/Editar/Eliminar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; // 'add', 'edit', 'delete'

    try {
        if ($action === 'add') {
            $marca = trim($_POST['marca'] ?? '');
            $modelo = trim($_POST['modelo'] ?? '');
            $anio = filter_var($_POST['anio'] ?? '', FILTER_VALIDATE_INT);
            $placas = trim($_POST['placas'] ?? '');
            $vin = trim($_POST['vin'] ?? '');
            $tipo_combustible = trim($_POST['tipo_combustible'] ?? '');
            $kilometraje_actual = filter_var($_POST['kilometraje_actual'] ?? '', FILTER_VALIDATE_INT);
            $ubicacion_actual = trim($_POST['ubicacion_actual'] ?? '');
            $observaciones = trim($_POST['observaciones'] ?? '');

            if (empty($marca) || empty($modelo) || $anio === false || empty($placas) || empty($tipo_combustible) || $kilometraje_actual === false) {
                throw new Exception("Por favor, completa todos los campos obligatorios para agregar un vehículo.");
            }

            $stmt = $db->prepare("INSERT INTO vehiculos (marca, modelo, anio, placas, vin, tipo_combustible, kilometraje_actual, ubicacion_actual, observaciones) VALUES (:marca, :modelo, :anio, :placas, :vin, :tipo_combustible, :kilometraje_actual, :ubicacion_actual, :observaciones)");
            $stmt->bindParam(':marca', $marca);
            $stmt->bindParam(':modelo', $modelo);
            $stmt->bindParam(':anio', $anio);
            $stmt->bindParam(':placas', $placas);
            $stmt->bindParam(':vin', $vin);
            $stmt->bindParam(':tipo_combustible', $tipo_combustible);
            $stmt->bindParam(':kilometraje_actual', $kilometraje_actual);
            $stmt->bindParam(':ubicacion_actual', $ubicacion_actual);
            $stmt->bindParam(':observaciones', $observaciones);
            $stmt->execute();
            $success_message = 'Vehículo agregado con éxito.';
        } elseif ($action === 'edit') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            $marca = trim($_POST['marca'] ?? '');
            $modelo = trim($_POST['modelo'] ?? '');
            $anio = filter_var($_POST['anio'] ?? '', FILTER_VALIDATE_INT);
            $placas = trim($_POST['placas'] ?? '');
            $vin = trim($_POST['vin'] ?? '');
            $tipo_combustible = trim($_POST['tipo_combustible'] ?? '');
            $kilometraje_actual = filter_var($_POST['kilometraje_actual'] ?? '', FILTER_VALIDATE_INT);
            $estatus = trim($_POST['estatus'] ?? '');
            $ubicacion_actual = trim($_POST['ubicacion_actual'] ?? '');
            $observaciones = trim($_POST['observaciones'] ?? '');

            if ($id === false || empty($marca) || empty($modelo) || $anio === false || empty($placas) || empty($tipo_combustible) || $kilometraje_actual === false || empty($estatus)) {
                throw new Exception("Por favor, completa todos los campos obligatorios para editar el vehículo.");
            }

            $stmt = $db->prepare("UPDATE vehiculos SET marca = :marca, modelo = :modelo, anio = :anio, placas = :placas, vin = :vin, tipo_combustible = :tipo_combustible, kilometraje_actual = :kilometraje_actual, estatus = :estatus, ubicacion_actual = :ubicacion_actual, observaciones = :observaciones WHERE id = :id");
            $stmt->bindParam(':marca', $marca);
            $stmt->bindParam(':modelo', $modelo);
            $stmt->bindParam(':anio', $anio);
            $stmt->bindParam(':placas', $placas);
            $stmt->bindParam(':vin', $vin);
            $stmt->bindParam(':tipo_combustible', $tipo_combustible);
            $stmt->bindParam(':kilometraje_actual', $kilometraje_actual);
            $stmt->bindParam(':estatus', $estatus);
            $stmt->bindParam(':ubicacion_actual', $ubicacion_actual);
            $stmt->bindParam(':observaciones', $observaciones);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $success_message = 'Vehículo actualizado con éxito.';
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false) {
                throw new Exception("ID de vehículo inválido para eliminar.");
            }

            $stmt = $db->prepare("DELETE FROM vehiculos WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $success_message = 'Vehículo eliminado con éxito.';
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            $error_message = 'Error: Las placas o el VIN ya existen. Por favor, verifica los datos.';
        }
        error_log("Error en gestión de vehículos: " . $e->getMessage());
    }
}

// --- Obtener todos los vehículos para mostrar en la tabla ---
if ($db) {
    try {
        $stmt = $db->query("SELECT * FROM vehiculos ORDER BY marca, modelo");
        $vehiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException | Exception $e) {
        error_log("Error al cargar vehículos: " . $e->getMessage());
        $error_message = 'No se pudieron cargar los vehículos.';
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Vehículos - Flotilla Interna</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body>
    <?php
    $nombre_usuario_sesion = $_SESSION['user_name'] ?? 'Usuario';
    $rol_usuario_sesion = $_SESSION['user_role'] ?? 'empleado';
    require_once '../app/includes/navbar.php';
    ?>
    <?php require_once '../app/includes/alert_banner.php'; // Incluir el banner de alertas 
    ?>

    <div class="container mt-4">
        <h1 class="mb-4">Gestión de Vehículos</h1>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" role="alert">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addEditVehicleModal" data-action="add">
            <i class="bi bi-plus-circle"></i> Agregar Nuevo Vehículo
        </button>

        <?php if (empty($vehiculos)): ?>
            <div class="alert alert-info" role="alert">
                No hay vehículos registrados en la flotilla.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Marca</th>
                            <th>Modelo</th>
                            <th>Año</th>
                            <th>Placas</th>
                            <th>VIN</th>
                            <th>Combustible</th>
                            <th>KM Actual</th>
                            <th>Estatus</th>
                            <th>Ubicación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vehiculos as $vehiculo): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($vehiculo['marca']); ?></td>
                                <td><?php echo htmlspecialchars($vehiculo['modelo']); ?></td>
                                <td><?php echo htmlspecialchars($vehiculo['anio']); ?></td>
                                <td><?php echo htmlspecialchars($vehiculo['placas']); ?></td>
                                <td><?php echo htmlspecialchars($vehiculo['vin'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($vehiculo['tipo_combustible']); ?></td>
                                <td><?php echo htmlspecialchars(number_format($vehiculo['kilometraje_actual'])); ?></td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    switch ($vehiculo['estatus']) {
                                        case 'disponible':
                                            $status_class = 'badge bg-success';
                                            break;
                                        case 'en_uso':
                                            $status_class = 'badge bg-primary';
                                            break;
                                        case 'en_mantenimiento':
                                            $status_class = 'badge bg-warning text-dark';
                                            break;
                                        case 'inactivo':
                                            $status_class = 'badge bg-danger';
                                            break;
                                    }
                                    ?>
                                    <span class="<?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($vehiculo['estatus'])); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($vehiculo['ubicacion_actual'] ?? 'N/A'); ?></td>
                                <td>
                                    <a href="detalle_vehiculo.php?id=<?php echo $vehiculo['id']; ?>" class="btn btn-sm btn-secondary me-1">
                                        Ver Detalles
                                    </a> <button type="button" class="btn btn-sm btn-info text-white me-1" data-bs-toggle="modal" data-bs-target="#addEditVehicleModal" data-action="edit"
                                        data-id="<?php echo $vehiculo['id']; ?>"
                                        data-marca="<?php echo htmlspecialchars($vehiculo['marca']); ?>"
                                        data-modelo="<?php echo htmlspecialchars($vehiculo['modelo']); ?>"
                                        data-anio="<?php echo htmlspecialchars($vehiculo['anio']); ?>"
                                        data-placas="<?php echo htmlspecialchars($vehiculo['placas']); ?>"
                                        data-vin="<?php echo htmlspecialchars($vehiculo['vin']); ?>"
                                        data-tipo-combustible="<?php echo htmlspecialchars($vehiculo['tipo_combustible']); ?>"
                                        data-kilometraje-actual="<?php echo htmlspecialchars($vehiculo['kilometraje_actual']); ?>"
                                        data-estatus="<?php echo htmlspecialchars($vehiculo['estatus']); ?>"
                                        data-ubicacion-actual="<?php echo htmlspecialchars($vehiculo['ubicacion_actual']); ?>"
                                        data-observaciones="<?php echo htmlspecialchars($vehiculo['observaciones']); ?>">
                                        <i class="bi bi-pencil-square"></i> Editar
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteVehicleModal" data-id="<?php echo $vehiculo['id']; ?>" data-placas="<?php echo htmlspecialchars($vehiculo['placas']); ?>">
                                        <i class="bi bi-trash"></i> Eliminar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="modal fade" id="addEditVehicleModal" tabindex="-1" aria-labelledby="addEditVehicleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addEditVehicleModalLabel"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_vehiculos.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="action" id="modalActionVehicle">
                            <input type="hidden" name="id" id="vehicleId">

                            <div class="mb-3">
                                <label for="marca" class="form-label">Marca</label>
                                <input type="text" class="form-control" id="marca" name="marca" required>
                            </div>
                            <div class="mb-3">
                                <label for="modelo" class="form-label">Modelo</label>
                                <input type="text" class="form-control" id="modelo" name="modelo" required>
                            </div>
                            <div class="mb-3">
                                <label for="anio" class="form-label">Año</label>
                                <input type="number" class="form-control" id="anio" name="anio" min="1900" max="<?php echo date('Y') + 1; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="placas" class="form-label">Placas</label>
                                <input type="text" class="form-control" id="placas" name="placas" required>
                            </div>
                            <div class="mb-3">
                                <label for="vin" class="form-label">VIN (Número de Identificación Vehicular)</label>
                                <input type="text" class="form-control" id="vin" name="vin">
                            </div>
                            <div class="mb-3">
                                <label for="tipo_combustible" class="form-label">Tipo de Combustible</label>
                                <select class="form-select" id="tipo_combustible" name="tipo_combustible" required>
                                    <option value="">Selecciona...</option>
                                    <option value="Gasolina">Gasolina</option>
                                    <option value="Diésel">Diésel</option>
                                    <option value="Eléctrico">Eléctrico</option>
                                    <option value="Híbrido">Híbrido</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="kilometraje_actual" class="form-label">Kilometraje Actual</label>
                                <input type="number" class="form-control" id="kilometraje_actual" name="kilometraje_actual" min="0" required>
                            </div>
                            <div class="mb-3" id="estatusField" style="display: none;">
                                <label for="estatus" class="form-label">Estatus</label>
                                <select class="form-select" id="estatus" name="estatus" required>
                                    <option value="disponible">Disponible</option>
                                    <option value="en_uso">En Uso</option>
                                    <option value="en_mantenimiento">En Mantenimiento</option>
                                    <option value="inactivo">Inactivo</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="ubicacion_actual" class="form-label">Ubicación Actual</label>
                                <input type="text" class="form-control" id="ubicacion_actual" name="ubicacion_actual">
                            </div>
                            <div class="mb-3">
                                <label for="observaciones_vehiculo" class="form-label">Observaciones</label>
                                <textarea class="form-control" id="observaciones_vehiculo" name="observaciones" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" id="submitVehicleBtn"></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteVehicleModal" tabindex="-1" aria-labelledby="deleteVehicleModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteVehicleModalLabel">Confirmar Eliminación</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_vehiculos.php" method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteVehicleId">
                        <div class="modal-body">
                            ¿Estás seguro de que quieres eliminar el vehículo con placas <strong id="deleteVehiclePlacas"></strong>?
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/main.js"></script>
    <script>
        // JavaScript para manejar los modales de agregar/editar vehículo
        document.addEventListener('DOMContentLoaded', function() {
            var addEditVehicleModal = document.getElementById('addEditVehicleModal');
            addEditVehicleModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var action = button.getAttribute('data-action');

                var modalTitle = addEditVehicleModal.querySelector('#addEditVehicleModalLabel');
                var modalActionInput = addEditVehicleModal.querySelector('#modalActionVehicle');
                var vehicleIdInput = addEditVehicleModal.querySelector('#vehicleId');
                var submitBtn = addEditVehicleModal.querySelector('#submitVehicleBtn');
                var estatusField = addEditVehicleModal.querySelector('#estatusField');
                var form = addEditVehicleModal.querySelector('form');

                form.reset();
                estatusField.style.display = 'none';

                if (action === 'add') {
                    modalTitle.textContent = 'Agregar Nuevo Vehículo';
                    modalActionInput.value = 'add';
                    submitBtn.textContent = 'Guardar Vehículo';
                    submitBtn.className = 'btn btn-primary';
                    vehicleIdInput.value = '';
                } else if (action === 'edit') {
                    modalTitle.textContent = 'Editar Vehículo';
                    modalActionInput.value = 'edit';
                    submitBtn.textContent = 'Actualizar Vehículo';
                    submitBtn.className = 'btn btn-info text-white';
                    estatusField.style.display = 'block';

                    vehicleIdInput.value = button.getAttribute('data-id');
                    addEditVehicleModal.querySelector('#marca').value = button.getAttribute('data-marca');
                    addEditVehicleModal.querySelector('#modelo').value = button.getAttribute('data-modelo');
                    addEditVehicleModal.querySelector('#anio').value = button.getAttribute('data-anio');
                    addEditVehicleModal.querySelector('#placas').value = button.getAttribute('data-placas');
                    addEditVehicleModal.querySelector('#vin').value = button.getAttribute('data-vin') === 'null' ? '' : button.getAttribute('data-vin');
                    addEditVehicleModal.querySelector('#tipo_combustible').value = button.getAttribute('data-tipo-combustible');
                    addEditVehicleModal.querySelector('#kilometraje_actual').value = button.getAttribute('data-kilometraje-actual');
                    addEditVehicleModal.querySelector('#estatus').value = button.getAttribute('data-estatus');
                    addEditVehicleModal.querySelector('#ubicacion_actual').value = button.getAttribute('data-ubicacion-actual') === 'null' ? '' : button.getAttribute('data-ubicacion-actual');
                    addEditVehicleModal.querySelector('#observaciones_vehiculo').value = button.getAttribute('data-observaciones') === 'null' ? '' : button.getAttribute('data-observaciones');
                }
            });

            var deleteVehicleModal = document.getElementById('deleteVehicleModal');
            if (deleteVehicleModal) {
                deleteVehicleModal.addEventListener('show.bs.modal', function(event) {
                    var button = event.relatedTarget;
                    var vehicleId = button.getAttribute('data-id');
                    var vehiclePlacas = button.getAttribute('data-placas');

                    var modalVehicleId = deleteVehicleModal.querySelector('#deleteVehicleId');
                    var modalVehiclePlacas = deleteVehicleModal.querySelector('#deleteVehiclePlacas');

                    modalVehicleId.value = vehicleId;
                    modalVehiclePlacas.textContent = vehiclePlacas;
                });
            }
        });
    </script>
</body>

</html>