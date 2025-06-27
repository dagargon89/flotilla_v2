<?php
// public/solicitar_vehiculo.php - CÓDIGO COMPLETO Y CORREGIDO (Error Undefined $db y Bloqueo Amonestado)
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
        $current_user_estatus_usuario = 'activo'; // Para no bloquear si hay un error en la consulta
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
// La variable $error_message ya puede venir del bloque de amonestaciones, si no, se inicializa aquí
$error_message = $error_message ?? '';


// Inicializar variables del formulario para evitar "Undefined variable" Warnings
$selected_vehiculo_id = '';
$fecha_salida_solicitada = '';
$fecha_regreso_solicitada = '';
$evento = '';
$descripcion = '';
$destino = '';

$vehiculos_flotilla = []; // Para el dropdown de vehículos

// Obtener lista de TODOS los vehículos para el dropdown
if ($db) { // $db ya está definida.
    try {
        $stmt_vehiculos = $db->query("SELECT id, marca, modelo, placas FROM vehiculos ORDER BY marca, modelo");
        $vehiculos_flotilla = $stmt_vehiculos->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al cargar vehículos para solicitud: " . $e->getMessage());
        $error_message = 'No se pudieron cargar los vehículos disponibles.';
    }
}


// Lógica para procesar la solicitud cuando se envía el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bloquear si el usuario está suspendido O amonestado
    if ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado') {
        $error_message = 'No puedes solicitar vehículos porque tu cuenta está ' . htmlspecialchars(ucfirst($current_user_estatus_usuario)) . '. Contacta al administrador.';
        exit(); // Asegura que no se procesa nada más del POST
    }

    $selected_vehiculo_id = filter_var($_POST['vehiculo_id'] ?? null, FILTER_VALIDATE_INT);
    $fecha_salida_solicitada = trim($_POST['fecha_salida_solicitada'] ?? '');
    $fecha_regreso_solicitada = trim($_POST['fecha_regreso_solicitada'] ?? '');
    $evento = trim($_POST['evento'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $destino = trim($_POST['destino'] ?? '');

    if (empty($selected_vehiculo_id) || empty($fecha_salida_solicitada) || empty($fecha_regreso_solicitada) || empty($evento) || empty($descripcion) || empty($destino)) {
        $error_message = 'Por favor, completa todos los campos requeridos, incluyendo la selección del vehículo.';
    } elseif (strtotime($fecha_salida_solicitada) >= strtotime($fecha_regreso_solicitada)) {
        $error_message = 'La fecha y hora de regreso deben ser posteriores a la fecha y hora de salida.';
    } else {
        if ($db) {
            try {
                $stmt_overlap = $db->prepare("
                    SELECT COUNT(*) FROM solicitudes_vehiculos
                    WHERE vehiculo_id = :vehiculo_id
                    AND estatus_solicitud IN ('aprobada', 'en_curso')
                    AND (
                        (fecha_salida_solicitada < :fecha_regreso AND fecha_regreso_solicitada > :fecha_salida)
                    )
                ");
                $stmt_overlap->bindParam(':vehiculo_id', $selected_vehiculo_id);
                $stmt_overlap->bindParam(':fecha_salida', $fecha_salida_solicitada);
                $stmt_overlap->bindParam(':fecha_regreso', $fecha_regreso_solicitada);
                $stmt_overlap->execute();

                if ($stmt_overlap->fetchColumn() > 0) {
                    $error_message = 'El vehículo seleccionado NO está disponible en las fechas que has elegido. Por favor, revisa la disponibilidad y selecciona otras fechas o vehículo.';
                } else {
                    $stmt = $db->prepare("INSERT INTO solicitudes_vehiculos (usuario_id, vehiculo_id, fecha_salida_solicitada, fecha_regreso_solicitada, evento, descripcion, destino) VALUES (:usuario_id, :vehiculo_id, :fecha_salida, :fecha_regreso, :evento, :descripcion, :destino)");
                    $stmt->bindParam(':usuario_id', $user_id);
                    $stmt->bindParam(':vehiculo_id', $selected_vehiculo_id);
                    $stmt->bindParam(':fecha_salida', $fecha_salida_solicitada);
                    $stmt->bindParam(':fecha_regreso', $fecha_regreso_solicitada);
                    $stmt->bindParam(':evento', $evento);
                    $stmt->bindParam(':descripcion', $descripcion);
                    $stmt->bindParam(':destino', $destino);
                    $stmt->execute();

                    $success_message = '¡Tu solicitud ha sido enviada con éxito! Espera la aprobación. Vehículo: ' . htmlspecialchars($_POST['vehiculo_info_display'] ?? '');

                    $selected_vehiculo_id = '';
                    $fecha_salida_solicitada = '';
                    $fecha_regreso_solicitada = '';
                    $evento = '';
                    $descripcion = '';
                    $destino = '';
                }
            } catch (PDOException $e) {
                error_log("Error al enviar solicitud de vehículo: " . $e->getMessage());
                $error_message = 'Ocurrió un error al procesar tu solicitud. Intenta de nuevo.';
            }
        } else {
            $error_message = 'No se pudo conectar a la base de datos.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Vehículo - Flotilla Interna</title>
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
        <h1 class="text-3xl font-bold text-darkpurple mb-6">Solicitar un Vehículo</h1>

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

        <?php if ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado'): ?>
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4 text-center" role="alert">
                <strong>¡Tu cuenta está <?php echo htmlspecialchars(ucfirst($current_user_estatus_usuario)); ?>!</strong> No puedes solicitar vehículos en este momento. Contacta al administrador para más información.
            </div>
            <p class="text-center mb-6">Estatus de tu cuenta: <span class="inline-block px-3 py-1 rounded-full text-sm font-semibold <?php echo ($current_user_estatus_usuario === 'suspendido' ? 'bg-red-500 text-white' : 'bg-yellow-500 text-white'); ?>"><?php echo htmlspecialchars(ucfirst($current_user_estatus_usuario)); ?></span></p>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <form action="solicitar_vehiculo.php" method="POST" class="space-y-4">
                    <div>
                        <label for="vehiculo_id" class="block text-sm font-medium text-darkpurple mb-1">Selecciona el Vehículo</label>
                        <select class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="vehiculo_id" name="vehiculo_id" <?php echo ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado') ? 'disabled' : ''; ?> required>
                            <option value="">-- Selecciona un vehículo --</option>
                            <?php foreach ($vehiculos_flotilla as $vehiculo): ?>
                                <option value="<?php echo htmlspecialchars($vehiculo['id']); ?>" data-placas="<?php echo htmlspecialchars($vehiculo['placas']); ?>" <?php echo ($selected_vehiculo_id == $vehiculo['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($vehiculo['marca'] . ' ' . $vehiculo['modelo'] . ' (' . $vehiculo['placas'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="vehiculo_info_display" id="vehiculo_info_display">
                    </div>
                    <div>
                        <label for="fecha_salida_solicitada" class="block text-sm font-medium text-darkpurple mb-1">Fecha y Hora de Salida Deseada</label>
                        <input type="datetime-local" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="fecha_salida_solicitada" name="fecha_salida_solicitada" value="<?php echo htmlspecialchars($fecha_salida_solicitada); ?>" <?php echo ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado') ? 'disabled' : ''; ?> required>
                    </div>
                    <div>
                        <label for="fecha_regreso_solicitada" class="block text-sm font-medium text-darkpurple mb-1">Fecha y Hora de Regreso Deseada</label>
                        <input type="datetime-local" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="fecha_regreso_solicitada" name="fecha_regreso_solicitada" value="<?php echo htmlspecialchars($fecha_regreso_solicitada); ?>" <?php echo ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado') ? 'disabled' : ''; ?> required>
                    </div>
                    <div>
                        <label for="evento" class="block text-sm font-medium text-darkpurple mb-1">Evento</label>
                        <input type="text" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="evento" name="evento" value="<?php echo htmlspecialchars($evento); ?>" <?php echo ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado') ? 'disabled' : ''; ?> required>
                    </div>
                    <div>
                        <label for="descripcion" class="block text-sm font-medium text-darkpurple mb-1">Descripción del Viaje</label>
                        <textarea class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="descripcion" name="descripcion" rows="3" <?php echo ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado') ? 'disabled' : ''; ?> required><?php echo htmlspecialchars($descripcion); ?></textarea>
                    </div>
                    <div>
                        <label for="destino" class="block text-sm font-medium text-darkpurple mb-1">Destino / Ruta</label>
                        <input type="text" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="destino" name="destino" value="<?php echo htmlspecialchars($destino); ?>" <?php echo ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado') ? 'disabled' : ''; ?> required>
                    </div>
                    <button type="submit" class="w-full py-2 px-4 rounded-lg bg-darkpurple text-white font-semibold hover:bg-mountbatten transition" <?php echo ($current_user_estatus_usuario === 'suspendido' || $current_user_estatus_usuario === 'amonestado') ? 'disabled' : ''; ?>>Enviar Solicitud</button>
                </form>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h5 class="text-lg font-semibold text-darkpurple mb-4">Próximas Ocupaciones del Vehículo Seleccionado</h5>
                <div id="availability_list_container" class="hidden">
                    <ul class="space-y-2 mb-4" id="occupied_dates_list">
                    </ul>
                    <p class="text-sm text-mountbatten" id="occupied_dates_hint"></p>
                </div>
                <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded text-center" id="no_vehicle_selected_message">
                    Selecciona un vehículo para ver sus próximas ocupaciones.
                </div>
                <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded text-center hidden" id="no_occupied_dates_message">
                    ¡Este vehículo no tiene ocupaciones registradas para el futuro cercano!
                </div>
                <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded text-center <?php echo empty($vehiculos_flotilla) ? 'block' : 'hidden'; ?>" id="no_vehicles_message">
                    No hay vehículos disponibles para solicitar en este momento.
                </div>
            </div>
        </div>
    </div>

    <!-- Eliminar Bootstrap y Bootstrap Icons -->
    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="js/main.js"></script>
    <script>
        // Inicializa Flatpickr para los inputs de fecha
        const flatpickrSalida = flatpickr("#fecha_salida_solicitada", {
            enableTime: true,
            dateFormat: "Y-m-dTH:i",
            minDate: "today",
            defaultDate: new Date(),
            onChange: function(selectedDates, dateStr, instance) {
                if (selectedDates.length > 0) {
                    flatpickrRegreso.set('minDate', selectedDates[0]);
                }
            }
        });
        const flatpickrRegreso = flatpickr("#fecha_regreso_solicitada", {
            enableTime: true,
            dateFormat: "Y-m-dTH:i",
            minDate: "today",
            defaultDate: new Date().fp_incr(1)
        });

        // --- Lógica para la vista de lista de disponibilidad ---
        const vehiculoSelect = document.getElementById('vehiculo_id');
        const availabilityListContainer = document.getElementById('availability_list_container');
        const occupiedDatesList = document.getElementById('occupied_dates_list');
        const noVehicleSelectedMessage = document.getElementById('no_vehicle_selected_message');
        const noOccupiedDatesMessage = document.getElementById('no_occupied_dates_message');
        const vehiculoInfoDisplay = document.getElementById('vehiculo_info_display');
        const occupiedDatesHint = document.getElementById('occupied_dates_hint');

        // Función para formatear fechas para la lista
        function formatDateTime(dateTimeString) {
            const isoDateTimeString = dateTimeString.replace(' ', 'T');
            const date = new Date(isoDateTimeString);

            if (isNaN(date.getTime())) {
                console.error("Fecha inválida recibida:", dateTimeString);
                return 'Fecha Inválida';
            }

            const options = {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            };
            return date.toLocaleString('es-MX', options);
        }

        // Función para cargar la disponibilidad y actualizar la lista
        function loadAvailabilityList(vehiculoId) {
            if (!vehiculoId) {
                availabilityListContainer.style.display = 'none';
                noVehicleSelectedMessage.style.display = 'block';
                noOccupiedDatesMessage.style.display = 'none';
                occupiedDatesList.innerHTML = '';
                occupiedDatesHint.textContent = '';
                return;
            }

            occupiedDatesList.innerHTML = '<li class="list-group-item text-center text-muted">Cargando ocupaciones...</li>';
            availabilityListContainer.style.display = 'block';
            noVehicleSelectedMessage.style.display = 'none';
            noOccupiedDatesMessage.style.display = 'none';
            occupiedDatesHint.textContent = '';

            fetch('api/get_vehiculo_availability.php?vehiculo_id=' + vehiculoId)
                .then(response => response.json())
                .then(data => {
                    occupiedDatesList.innerHTML = '';
                    if (data.error) {
                        console.error('Error al cargar ocupaciones:', data.error);
                        occupiedDatesList.innerHTML = '<li class="list-group-item text-center alert alert-danger">Error al cargar la disponibilidad: ' + data.error + '</li>';
                        return;
                    }

                    if (data.occupied_ranges && data.occupied_ranges.length > 0) {
                        data.occupied_ranges.sort((a, b) => new Date(a.fecha_salida_solicitada.replace(' ', 'T')) - new Date(b.fecha_salida_solicitada.replace(' ', 'T')));

                        data.occupied_ranges.forEach(range => {
                            const listItem = document.createElement('li');
                            listItem.className = 'list-group-item';
                            listItem.innerHTML = `
                                <strong>Ocupado desde:</strong> ${formatDateTime(range.fecha_salida_solicitada)}<br>
                                <strong>Hasta:</strong> ${formatDateTime(range.fecha_regreso_solicitada)}<br>
                                <strong>Evento:</strong> ${range.evento || 'N/A'}<br>
                                <strong>Descripción:</strong> ${range.descripcion || 'N/A'}<br>
                                <strong>Solicitado por:</strong> ${range.solicitante_nombre || 'Desconocido'}
                            `;
                            occupiedDatesList.appendChild(listItem);
                        });
                        occupiedDatesHint.textContent = 'Estas son las próximas fechas en que el vehículo estará ocupado por solicitudes aprobadas o en curso.';
                        noOccupiedDatesMessage.style.display = 'none';
                    } else {
                        noOccupiedDatesMessage.style.display = 'block';
                        occupiedDatesHint.textContent = '';
                    }
                })
                .catch(error => {
                    console.error('Error fetching availability list:', error);
                    occupiedDatesList.innerHTML = '<li class="list-group-item text-center alert alert-danger">No se pudo cargar la lista de ocupaciones.</li>';
                });
        }

        // Event listener para el cambio de selección de vehículo
        vehiculoSelect.addEventListener('change', function() {
            const selectedId = this.value;
            const selectedOption = this.options[this.selectedIndex];
            const selectedInfo = selectedOption ? selectedOption.textContent : '';
            vehiculoInfoDisplay.value = selectedInfo;

            loadAvailabilityList(selectedId);
        });

        // Cargar disponibilidad si ya hay un vehículo preseleccionado al cargar la página
        if (vehiculoSelect.value) {
            loadAvailabilityList(vehiculoSelect.value);
        } else {
            availabilityListContainer.style.display = 'none';
            noVehicleSelectedMessage.style.display = 'block';
            noOccupiedDatesMessage.style.display = 'none';
        }
    </script>
</body>

</html>