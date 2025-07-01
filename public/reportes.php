<?php
// public/reportes.php - CÓDIGO COMPLETO Y CORREGIDO (Error Undefined $db)
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
// Solo 'flotilla_manager' y 'admin' pueden acceder a esta página de reportes.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'flotilla_manager' && $_SESSION['user_role'] !== 'admin')) {
    header('Location: dashboard.php'); // Redirige al dashboard si no tiene permisos
    exit();
}

$nombre_usuario_sesion = $_SESSION['user_name'];
$rol_usuario_sesion = $_SESSION['user_role'];

$error_message = $error_message ?? ''; // Mantener el error si ya viene del bloque de amonestaciones

// --- Variables para los filtros ---
$filter_start_date = $_GET['start_date'] ?? date('Y-m-01');
$filter_end_date = $_GET['end_date'] ?? date('Y-m-t');
$filter_vehiculo_id = filter_var($_GET['vehiculo_id'] ?? null, FILTER_VALIDATE_INT);
$filter_estatus_solicitud = $_GET['estatus_solicitud'] ?? '';

// Obtener lista de vehículos para el filtro
$vehiculos_para_filtro = [];
if ($db) { // $db ya está definida.
    try {
        $stmt_veh_filtro = $db->query("SELECT id, marca, modelo, placas FROM vehiculos ORDER BY marca, modelo");
        $vehiculos_para_filtro = $stmt_veh_filtro->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al cargar vehículos para filtros: " . $e->getMessage());
    }
}


$report_data = [
    'vehiculos_por_estatus' => [],
    'kilometros_por_vehiculo' => [],
    'costos_mantenimiento_por_vehiculo' => [],
    'uso_vehiculos_por_mes' => [],
    'detalle_solicitudes_filtradas' => []
];

if ($db) { // $db ya está definida.
    try {
        // Reporte 1: Vehículos por Estatus
        $stmt = $db->query("SELECT estatus, COUNT(*) as count FROM vehiculos GROUP BY estatus");
        $report_data['vehiculos_por_estatus'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- Construir consultas con filtros dinámicos ---
        $start_datetime = $filter_start_date . ' 00:00:00';
        $end_datetime = $filter_end_date . ' 23:59:59';

        $common_params = [
            ':start_date' => $start_datetime,
            ':end_date' => $end_datetime
        ];
        if ($filter_vehiculo_id) {
            $common_params[':vehiculo_id'] = $filter_vehiculo_id;
        }
        if (!empty($filter_estatus_solicitud)) {
            $common_params[':estatus_solicitud'] = $filter_estatus_solicitud;
        }


        // Reporte 2: Kilómetros recorridos por Vehículo (Filtrado)
        $sql_km = "
            SELECT
                v.placas,
                v.marca,
                v.modelo,
                SUM(COALESCE(hu.kilometraje_regreso, 0) - COALESCE(hu.kilometraje_salida, 0)) as total_km
            FROM solicitudes_vehiculos s
            JOIN usuarios u ON s.usuario_id = u.id
            JOIN vehiculos v ON s.vehiculo_id = v.id
            LEFT JOIN historial_uso_vehiculos hu ON s.id = hu.solicitud_id
            WHERE s.estatus_solicitud IN ('en_curso', 'completada')
            AND s.fecha_salida_solicitada BETWEEN :start_date AND :end_date
        ";
        $params_km = [
            ':start_date' => $start_datetime,
            ':end_date' => $end_datetime
        ];
        if ($filter_vehiculo_id) {
            $sql_km .= " AND v.id = :vehiculo_id";
            $params_km[':vehiculo_id'] = $filter_vehiculo_id;
        }
        $sql_km .= " GROUP BY v.id, v.placas, v.marca, v.modelo HAVING total_km > 0 ORDER BY total_km DESC";
        $stmt_km = $db->prepare($sql_km);
        $stmt_km->execute($params_km);
        $report_data['kilometros_por_vehiculo'] = $stmt_km->fetchAll(PDO::FETCH_ASSOC);


        // Reporte 3: Costos de Mantenimiento por Vehículo (Filtrado por rango de fecha del mantenimiento)
        $sql_mantenimiento = "
            SELECT
                v.placas,
                v.marca,
                v.modelo,
                SUM(m.costo) as total_costo
            FROM mantenimientos m
            JOIN vehiculos v ON m.vehiculo_id = v.id
            WHERE m.fecha_mantenimiento BETWEEN :start_date_maint AND :end_date_maint
        ";
        $params_maint = [
            ':start_date_maint' => $start_datetime,
            ':end_date_maint' => $end_datetime
        ];
        if ($filter_vehiculo_id) {
            $sql_mantenimiento .= " AND m.vehiculo_id = :vehiculo_id_maint";
            $params_maint[':vehiculo_id_maint'] = $filter_vehiculo_id;
        }
        $sql_mantenimiento .= " GROUP BY v.id, v.placas, v.marca, v.modelo HAVING total_costo > 0 ORDER BY total_costo DESC";
        $stmt_maint = $db->prepare($sql_mantenimiento);
        $stmt_maint->execute($params_maint);
        $report_data['costos_mantenimiento_por_vehiculo'] = $stmt_maint->fetchAll(PDO::FETCH_ASSOC);


        // Reporte 4: Uso de Vehículos por Mes (Filtrado)
        $sql_uso_mes = "
            SELECT
                DATE_FORMAT(s.fecha_salida_solicitada, '%Y-%m') as mes,
                COUNT(*) as count
            FROM solicitudes_vehiculos s
            WHERE s.fecha_salida_solicitada BETWEEN :start_date_uso AND :end_date_uso
        ";
        $params_uso_mes = [
            ':start_date_uso' => $start_datetime,
            ':end_date_uso' => $end_datetime
        ];
        if ($filter_vehiculo_id) {
            $sql_uso_mes .= " AND s.vehiculo_id = :vehiculo_id_uso";
            $params_uso_mes[':vehiculo_id_uso'] = $filter_vehiculo_id;
        }
        if (!empty($filter_estatus_solicitud)) {
            $sql_uso_mes .= " AND s.estatus_solicitud = :estatus_solicitud_uso";
            $params_uso_mes[':estatus_solicitud_uso'] = $filter_estatus_solicitud;
        }
        $sql_uso_mes .= " GROUP BY mes ORDER BY mes ASC";
        $stmt_uso_mes = $db->prepare($sql_uso_mes);
        $stmt_uso_mes->execute($params_uso_mes);
        $report_data['uso_vehiculos_por_mes'] = $stmt_uso_mes->fetchAll(PDO::FETCH_ASSOC);


        // Detalle de Solicitudes Filtradas para la tabla y descarga
        $sql_detalle = "
            SELECT
                s.id AS solicitud_id,
                u.nombre AS usuario_nombre,
                s.fecha_salida_solicitada,
                s.fecha_regreso_solicitada,
                s.evento,
                s.descripcion,
                s.destino,
                s.estatus_solicitud,
                v.marca,
                v.modelo,
                v.placas,
                hu.kilometraje_salida,
                hu.kilometraje_regreso
            FROM solicitudes_vehiculos s
            JOIN usuarios u ON s.usuario_id = u.id
            LEFT JOIN vehiculos v ON s.vehiculo_id = v.id
            LEFT JOIN historial_uso_vehiculos hu ON s.id = hu.solicitud_id
            WHERE s.fecha_salida_solicitada BETWEEN :start_date_detalle AND :end_date_detalle
        ";
        $params_detalle = [
            ':start_date_detalle' => $start_datetime,
            ':end_date_detalle' => $end_datetime
        ];
        if ($filter_vehiculo_id) {
            $sql_detalle .= " AND s.vehiculo_id = :vehiculo_id_detalle";
            $params_detalle[':vehiculo_id_detalle'] = $filter_vehiculo_id;
        }
        if (!empty($filter_estatus_solicitud)) {
            $sql_detalle .= " AND s.estatus_solicitud = :estatus_solicitud_detalle";
            $params_detalle[':estatus_solicitud_detalle'] = $filter_estatus_solicitud;
        }
        $sql_detalle .= " ORDER BY s.fecha_salida_solicitada DESC";
        $stmt_detalle = $db->prepare($sql_detalle);
        $stmt_detalle->execute($params_detalle);
        $report_data['detalle_solicitudes_filtradas'] = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al cargar datos de reportes: " . $e->getMessage());
        $error_message = 'No se pudieron cargar los datos de los reportes. Detalle para desarrollo: ' . $e->getMessage();
    }
}
// Convertir los datos PHP a JSON para pasarlos a JavaScript
$report_data_json = json_encode($report_data);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes y Estadísticas - Flotilla Interna</title>
    <link rel="stylesheet" href="css/colors.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
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
        <h1 class="text-3xl font-bold text-darkpurple mb-6">Reportes y Estadísticas de la Flotilla</h1>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2 mb-6">
            <h3 class="text-lg font-semibold text-darkpurple mb-4">Filtros de Reporte</h3>
            <form action="reportes.php" method="GET">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-darkpurple mb-1">Fecha de Inicio (Salida)</label>
                        <input type="date" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="start_date" name="start_date" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-darkpurple mb-1">Fecha de Fin (Salida)</label>
                        <input type="date" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="end_date" name="end_date" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                    <div>
                        <label for="vehiculo_id" class="block text-sm font-medium text-darkpurple mb-1">Vehículo</label>
                        <select class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="vehiculo_id" name="vehiculo_id">
                            <option value="">Todos los vehículos</option>
                            <?php foreach ($vehiculos_para_filtro as $vehiculo_opt): ?>
                                <option value="<?php echo htmlspecialchars($vehiculo_opt['id']); ?>" <?php echo ($filter_vehiculo_id == $vehiculo_opt['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($vehiculo_opt['marca'] . ' ' . $vehiculo_opt['modelo'] . ' (' . $vehiculo_opt['placas'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="estatus_solicitud" class="block text-sm font-medium text-darkpurple mb-1">Estatus de Solicitud</label>
                        <select class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition" id="estatus_solicitud" name="estatus_solicitud">
                            <option value="">Todos los estatus</option>
                            <option value="pendiente" <?php echo ($filter_estatus_solicitud == 'pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                            <option value="aprobada" <?php echo ($filter_estatus_solicitud == 'aprobada') ? 'selected' : ''; ?>>Aprobada</option>
                            <option value="rechazada" <?php echo ($filter_estatus_solicitud == 'rechazada') ? 'selected' : ''; ?>>Rechazada</option>
                            <option value="en_curso" <?php echo ($filter_estatus_solicitud == 'en_curso') ? 'selected' : ''; ?>>En Curso</option>
                            <option value="completada" <?php echo ($filter_estatus_solicitud == 'completada') ? 'selected' : ''; ?>>Completada</option>
                            <option value="cancelada" <?php echo ($filter_estatus_solicitud == 'cancelada') ? 'selected' : ''; ?>>Cancelada</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-darkpurple text-white px-4 py-2 rounded-lg font-semibold hover:bg-mountbatten transition">Aplicar Filtros</button>
                    <a href="reportes.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg font-semibold hover:bg-gray-600 transition">Limpiar Filtros</a>
                </div>
            </form>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h3 class="text-lg font-semibold text-darkpurple mb-4">Vehículos por Estatus</h3>
                <canvas id="vehiculosEstatusChart"></canvas>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h3 class="text-lg font-semibold text-darkpurple mb-4">Kilómetros Recorridos por Vehículo</h3>
                <canvas id="kilometrosVehiculoChart"></canvas>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h3 class="text-lg font-semibold text-darkpurple mb-4">Costos de Mantenimiento por Vehículo</h3>
                <canvas id="costosMantenimientoChart"></canvas>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h3 class="text-lg font-semibold text-darkpurple mb-4">Uso de Vehículos por Mes</h3>
                <canvas id="usoVehiculosMesChart"></canvas>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-darkpurple">Detalle de Solicitudes Filtradas</h3>
                <button type="button" class="bg-cambridge2 text-darkpurple px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition" id="downloadCsvBtn">
                    <i class="bi bi-download"></i> Descargar CSV
                </button>
            </div>
            <?php if (empty($report_data['detalle_solicitudes_filtradas'])): ?>
                <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded text-center" role="alert">
                    No hay solicitudes que coincidan con los filtros aplicados.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-cambridge1 text-white">
                                <th class="px-4 py-3 text-left">ID Sol.</th>
                                <th class="px-4 py-3 text-left">Solicitante</th>
                                <th class="px-4 py-3 text-left">Salida Deseada</th>
                                <th class="px-4 py-3 text-left">Regreso Deseada</th>
                                <th class="px-4 py-3 text-left">Evento</th>
                                <th class="px-4 py-3 text-left">Descripción</th>
                                <th class="px-4 py-3 text-left">Destino</th>
                                <th class="px-4 py-3 text-left">Vehículo Asignado</th>
                                <th class="px-4 py-3 text-left">Estatus</th>
                                <th class="px-4 py-3 text-left">KM Salida</th>
                                <th class="px-4 py-3 text-left">KM Regreso</th>
                                <th class="px-4 py-3 text-left">KM Recorridos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data['detalle_solicitudes_filtradas'] as $solicitud): ?>
                                <tr class="border-b border-cambridge2 hover:bg-parchment">
                                    <td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars($solicitud['solicitud_id']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($solicitud['usuario_nombre']); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_salida_solicitada'])); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_regreso_solicitada'])); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($solicitud['evento']); ?></td>
                                    <td class="px-4 py-3 text-sm text-mountbatten"><?php echo htmlspecialchars($solicitud['descripcion']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($solicitud['destino']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($solicitud['marca'] ? $solicitud['marca'] . ' ' . $solicitud['modelo'] . ' (' . $solicitud['placas'] . ')' : 'Sin asignar'); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?php
                                                                                                                switch ($solicitud['estatus_solicitud']) {
                                                                                                                    case 'pendiente':
                                                                                                                        echo 'bg-yellow-500 text-white';
                                                                                                                        break;
                                                                                                                    case 'aprobada':
                                                                                                                        echo 'bg-green-500 text-white';
                                                                                                                        break;
                                                                                                                    case 'rechazada':
                                                                                                                        echo 'bg-red-500 text-white';
                                                                                                                        break;
                                                                                                                    case 'en_curso':
                                                                                                                        echo 'bg-cambridge1 text-white';
                                                                                                                        break;
                                                                                                                    case 'completada':
                                                                                                                        echo 'bg-gray-500 text-white';
                                                                                                                        break;
                                                                                                                    case 'cancelada':
                                                                                                                        echo 'bg-blue-500 text-white';
                                                                                                                        break;
                                                                                                                }
                                                                                                                ?>"><?php echo htmlspecialchars(ucfirst($solicitud['estatus_solicitud'])); ?></span>
                                    </td>
                                    <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($solicitud['kilometraje_salida'] ?? 'N/A'); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($solicitud['kilometraje_regreso'] ?? 'N/A'); ?></td>
                                    <td class="px-4 py-3 text-sm font-semibold"><?php echo htmlspecialchars($solicitud['kilometros_recorridos'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="js/main.js"></script>
        <script>
            // Pasamos los datos de PHP a JavaScript
            const reportData = <?php echo $report_data_json; ?>;

            // Inicializar Flatpickr para los filtros de fecha
            flatpickr("#start_date", {
                dateFormat: "Y-m-d"
            });
            flatpickr("#end_date", {
                dateFormat: "Y-m-d"
            });

            // Función para generar colores aleatorios (útil para gráficas de pastel)
            function generateRandomColors(num) {
                const colors = [];
                for (let i = 0; i < num; i++) {
                    const r = Math.floor(Math.random() * 255);
                    const g = Math.floor(Math.random() * 255);
                    const b = Math.floor(Math.random() * 255);
                    colors.push(`rgba(${r}, ${g}, ${b}, 0.6)`);
                }
                return colors;
            }

            // --- Gráfica 1: Vehículos por Estatus (Pastel) ---
            const ctxEstatus = document.getElementById('vehiculosEstatusChart');
            if (reportData.vehiculos_por_estatus.length > 0) {
                const estatusLabels = reportData.vehiculos_por_estatus.map(item => item.estatus.charAt(0).toUpperCase() + item.estatus.slice(1));
                const estatusCounts = reportData.vehiculos_por_estatus.map(item => item.count);
                const estatusColors = generateRandomColors(estatusLabels.length);

                new Chart(ctxEstatus, {
                    type: 'pie',
                    data: {
                        labels: estatusLabels,
                        datasets: [{
                            data: estatusCounts,
                            backgroundColor: estatusColors,
                            borderColor: '#fff',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            title: {
                                display: true,
                                text: 'Distribución de Vehículos por Estatus'
                            }
                        }
                    }
                });
            } else {
                ctxEstatus.parentNode.innerHTML = '<p class="text-center text-muted">No hay datos de estatus de vehículos.</p>';
            }

            // --- Gráfica 2: Kilómetros Recorridos por Vehículo (Barras) ---
            const ctxKm = document.getElementById('kilometrosVehiculoChart');
            if (reportData.kilometros_por_vehiculo.length > 0) {
                const kmLabels = reportData.kilometros_por_vehiculo.map(item => `${item.marca} ${item.modelo} (${item.placas})`);
                const kmData = reportData.kilometros_por_vehiculo.map(item => item.total_km);

                new Chart(ctxKm, {
                    type: 'bar',
                    data: {
                        labels: kmLabels,
                        datasets: [{
                            label: 'Kilómetros Recorridos',
                            data: kmData,
                            backgroundColor: 'rgba(54, 162, 235, 0.6)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: 'Kilómetros Recorridos por Vehículo'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Kilómetros'
                                }
                            }
                        }
                    }
                });
            } else {
                ctxKm.parentNode.innerHTML = '<p class="text-center text-muted">No hay datos de kilómetros recorridos.</p>';
            }

            // --- Gráfica 3: Costos de Mantenimiento por Vehículo (Barras) ---
            const ctxCostos = document.getElementById('costosMantenimientoChart');
            if (reportData.costos_mantenimiento_por_vehiculo.length > 0) {
                const costoLabels = reportData.costos_mantenimiento_por_vehiculo.map(item => `${item.marca} ${item.modelo} (${item.placas})`);
                const costoData = reportData.costos_mantenimiento_por_vehiculo.map(item => item.total_costo);

                new Chart(ctxCostos, {
                    type: 'bar',
                    data: {
                        labels: costoLabels,
                        datasets: [{
                            label: 'Costo Total de Mantenimiento',
                            data: costoData,
                            backgroundColor: 'rgba(255, 99, 132, 0.6)',
                            borderColor: 'rgba(255, 99, 132, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: 'Costos de Mantenimiento por Vehículo'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Costo ($)'
                                }
                            }
                        }
                    }
                });
            } else {
                ctxCostos.parentNode.innerHTML = '<p class="text-center text-muted">No hay datos de costos de mantenimiento.</p>';
            }

            // --- Gráfica 4: Uso de Vehículos por Mes (Líneas) ---
            const ctxUsoMes = document.getElementById('usoVehiculosMesChart');
            if (reportData.uso_vehiculos_por_mes.length > 0) {
                const usoMesLabels = reportData.uso_vehiculos_por_mes.map(item => item.mes);
                const usoMesData = reportData.uso_vehiculos_por_mes.map(item => item.count);

                new Chart(ctxUsoMes, {
                    type: 'line',
                    data: {
                        labels: usoMesLabels,
                        datasets: [{
                            label: 'Número de Solicitudes (Aprobadas/En Curso/Completadas)',
                            data: usoMesData,
                            fill: false,
                            borderColor: 'rgb(75, 192, 192)',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            title: {
                                display: true,
                                text: 'Uso de Vehículos por Mes'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Número de Solicitudes'
                                }
                            }
                        }
                    }
                });
            } else {
                ctxUsoMes.parentNode.innerHTML = '<p class="text-center text-muted">No hay datos de uso por mes.</p>';
            }

            // --- Lógica para Descargar CSV ---
            document.getElementById('downloadCsvBtn').addEventListener('click', function() {
                const data = reportData.detalle_solicitudes_filtradas;
                if (data.length === 0) {
                    alert('No hay datos para descargar.');
                    return;
                }

                // Generar encabezados del CSV
                const headers = [
                    'ID Solicitud', 'Solicitante', 'Fecha Salida Deseada', 'Fecha Regreso Deseada',
                    'Evento', 'Descripcion', 'Destino', 'Vehiculo Asignado', 'Estatus', 'KM Salida', 'KM Regreso', 'KM Recorridos'
                ];
                let csvContent = headers.join(',') + '\n';

                // Generar filas del CSV
                data.forEach(row => {
                    const vehiculoAsignado = row.marca ? `${row.marca} ${row.modelo} (${row.placas})` : 'Sin asignar';
                    const kmRecorridos = (row.kilometraje_regreso !== null && row.kilometraje_salida !== null) ?
                        (row.kilometraje_regreso - row.kilometraje_salida) : 'N/A';

                    const rowData = [
                        row.solicitud_id,
                        row.usuario_nombre,
                        `"${new Date(row.fecha_salida_solicitada).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' })}"`,
                        `"${new Date(row.fecha_regreso_solicitada).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' })}"`,
                        `"${row.evento.replace(/"/g, '""')}"`,
                        `"${row.descripcion.replace(/"/g, '""')}"`,
                        `"${row.destino.replace(/"/g, '""')}"`,
                        `"${vehiculoAsignado.replace(/"/g, '""')}"`,
                        `"${row.estatus_solicitud.charAt(0).toUpperCase() + row.estatus_solicitud.slice(1)}"`,
                        row.kilometraje_salida,
                        row.kilometraje_regreso,
                        kmRecorridos
                    ];
                    csvContent += rowData.join(',') + '\n';
                });

                // Crear y descargar el archivo CSV
                const blob = new Blob([csvContent], {
                    type: 'text/csv;charset=utf-8;'
                });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                const today = new Date();
                const fileName = `reporte_solicitudes_${today.getFullYear()}-${(today.getMonth()+1).toString().padStart(2, '0')}-${today.getDate().toString().padStart(2, '0')}.csv`;
                link.setAttribute('href', url);
                link.setAttribute('download', fileName);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            });
        </script>
    </div>
</body>

</html>