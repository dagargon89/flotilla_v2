<?php
// public/detalle_vehiculo.php - CÓDIGO COMPLETO Y REVISADO (¡Verificación Exhaustiva de Llaves y Sintaxis!)
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
// Solo 'flotilla_manager' y 'admin' pueden acceder a esta página.
if (!isset($_SESSION['user_id'])) { // Si no hay sesión, redirigir
    header('Location: index.php');
    exit();
}
// Ahora verificamos el rol después de asegurar que $_SESSION['user_role'] está disponible
if ($_SESSION['user_role'] !== 'flotilla_manager' && $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php'); // Redirige al dashboard si no tiene permisos
    exit();
}

$nombre_usuario_sesion = $_SESSION['user_name'] ?? 'Usuario';
$rol_usuario_sesion = $_SESSION['user_role'] ?? 'empleado';

// La variable $error_message ya puede venir del bloque de amonestaciones, si no, se inicializa aquí
$error_message = $error_message ?? '';
$vehiculo_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

$vehiculo = null;
$solicitudes_historicas = [];
$mantenimientos_historicos = [];
$documentos_vehiculo = [];

if (!$vehiculo_id) {
    $error_message = 'ID de vehículo no proporcionado o inválido.';
} else { // Abre el ELSE para cuando $vehiculo_id es válido
    if ($db) { // Abre el IF para la conexión a la base de datos
        try {
            // 1. Obtener datos generales del vehículo
            $stmt_vehiculo = $db->prepare("SELECT * FROM vehiculos WHERE id = :vehiculo_id");
            $stmt_vehiculo->bindParam(':vehiculo_id', $vehiculo_id);
            $stmt_vehiculo->execute();
            $vehiculo = $stmt_vehiculo->fetch(PDO::FETCH_ASSOC);

            if (!$vehiculo) {
                $error_message = 'Vehículo no encontrado.';
            } else { // Abre el ELSE para cuando el vehículo es encontrado
                // 2. Obtener historial de solicitudes y uso para este vehículo
                $stmt_solicitudes = $db->prepare("
                    SELECT 
                        s.id AS solicitud_id,
                        s.usuario_id,
                        s.fecha_salida_solicitada,
                        s.fecha_regreso_solicitada,
                        s.evento,
                        s.descripcion,
                        s.destino,
                        s.estatus_solicitud,
                        s.observaciones_aprobacion,
                        u.nombre AS usuario_nombre,
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
                    JOIN usuarios u ON s.usuario_id = u.id
                    LEFT JOIN historial_uso_vehiculos hu ON s.id = hu.solicitud_id
                    WHERE s.vehiculo_id = :vehiculo_id
                    ORDER BY s.fecha_salida_solicitada DESC
                ");
                $stmt_solicitudes->bindParam(':vehiculo_id', $vehiculo_id);
                $stmt_solicitudes->execute();
                $solicitudes_historicas = $stmt_solicitudes->fetchAll(PDO::FETCH_ASSOC);

                // 3. Obtener historial de mantenimientos para este vehículo
                $stmt_mantenimientos = $db->prepare("SELECT * FROM mantenimientos WHERE vehiculo_id = :vehiculo_id ORDER BY fecha_mantenimiento DESC");
                $stmt_mantenimientos->bindParam(':vehiculo_id', $vehiculo_id);
                $stmt_mantenimientos->execute();
                $mantenimientos_historicos = $stmt_mantenimientos->fetchAll(PDO::FETCH_ASSOC);

                // 4. Obtener documentos del vehículo
                $stmt_documentos = $db->prepare("SELECT * FROM documentos_vehiculos WHERE vehiculo_id = :vehiculo_id ORDER BY fecha_subida DESC");
                $stmt_documentos->bindParam(':vehiculo_id', $vehiculo_id);
                $stmt_documentos->execute();
                $documentos_vehiculo = $stmt_documentos->fetchAll(PDO::FETCH_ASSOC);
            } // Cierra el ELSE para cuando el vehículo es encontrado
        } catch (PDOException $e) {
            error_log("Error al cargar detalle de vehículo: " . $e->getMessage());
            $error_message = 'Ocurrió un error al cargar los detalles del vehículo: ' . $e->getMessage();
        }
    } else { // Cierra el IF para la conexión a la base de datos (si $db es null)
        $error_message = 'No se pudo conectar a la base de datos.';
    }
} // Cierra el ELSE para cuando $vehiculo_id es válido
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de Vehículo - Flotilla Interna</title>
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
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo $error_message; ?>
            </div>
            <a href="gestion_vehiculos.php" class="btn btn-secondary mt-3">Regresar a Gestión de Vehículos</a>
        <?php elseif (!$vehiculo): ?>
            <div class="alert alert-info" role="alert">
                Vehículo no encontrado o no válido.
            </div>
            <a href="gestion_vehiculos.php" class="btn btn-secondary mt-3">Regresar a Gestión de Vehículos</a>
        <?php else: ?>
            <h1 class="mb-4">Detalle de Vehículo: <?php echo htmlspecialchars($vehiculo['marca'] . ' ' . $vehiculo['modelo'] . ' (' . $vehiculo['placas'] . ')'); ?></h1>

            <div class="card mb-4 shadow-sm">
                <div class="card-header">
                    Información General
                    <button type="button" class="btn btn-sm btn-outline-info float-end me-2" data-bs-toggle="modal" data-bs-target="#addEditVehicleModal" data-action="edit"
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
                        Editar Vehículo
                    </button>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Marca:</strong> <?php echo htmlspecialchars($vehiculo['marca']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Modelo:</strong> <?php echo htmlspecialchars($vehiculo['modelo']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Año:</strong> <?php echo htmlspecialchars($vehiculo['anio']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Placas:</strong> <?php echo htmlspecialchars($vehiculo['placas']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>VIN:</strong> <?php echo htmlspecialchars($vehiculo['vin'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Tipo de Combustible:</strong> <?php echo htmlspecialchars($vehiculo['tipo_combustible']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Kilometraje Actual:</strong> <?php echo htmlspecialchars(number_format($vehiculo['kilometraje_actual'])); ?> KM</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Estatus:</strong>
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
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Ubicación Actual:</strong> <?php echo htmlspecialchars($vehiculo['ubicacion_actual'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-12">
                            <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($vehiculo['observaciones'] ?? 'Ninguna.'); ?></p>
                        </div>
                        <div class="col-12">
                            <p><strong>Fecha de Registro:</strong> <?php echo date('d/m/Y H:i', strtotime($vehiculo['fecha_registro'])); ?></p>
                        </div>
                    </div>
                </div>

                <div class="card mb-4 shadow-sm">
                    <div class="card-header">
                        Historial de Solicitudes y Uso
                    </div>
                    <div class="card-body">
                        <?php if (empty($solicitudes_historicas)): ?>
                            <div class="alert alert-info text-center" role="alert">
                                Este vehículo no tiene solicitudes o historial de uso registrado.
                            </div>
                        <?php else: ?>
                            <div class="accordion" id="accordionSolicitudes">
                                <?php foreach ($solicitudes_historicas as $index => $solicitud): ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="heading<?php echo $solicitud['solicitud_id']; ?>">
                                            <button class="accordion-button <?php echo ($index !== 0) ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $solicitud['solicitud_id']; ?>" aria-expanded="<?php echo ($index === 0) ? 'true' : 'false'; ?>" aria-controls="collapse<?php echo $solicitud['solicitud_id']; ?>">
                                                Solicitud #<?php echo htmlspecialchars($solicitud['solicitud_id']); ?> - <?php echo htmlspecialchars($solicitud['evento']); ?> (<?php echo htmlspecialchars($solicitud['usuario_nombre']); ?>)
                                                <span class="badge bg-<?php
                                                                        switch ($solicitud['estatus_solicitud']) {
                                                                            case 'pendiente':
                                                                                echo 'warning text-dark';
                                                                                break;
                                                                            case 'aprobada':
                                                                                echo 'success';
                                                                                break;
                                                                            case 'rechazada':
                                                                                echo 'danger';
                                                                                break;
                                                                            case 'en_curso':
                                                                                echo 'primary';
                                                                                break;
                                                                            case 'completada':
                                                                                echo 'secondary';
                                                                                break;
                                                                            case 'cancelada':
                                                                                echo 'info';
                                                                                break;
                                                                        }
                                                                        ?> ms-3"><?php echo htmlspecialchars(ucfirst($solicitud['estatus_solicitud'])); ?></span>
                                            </button>
                                        </h2>
                                        <div id="collapse<?php echo $solicitud['solicitud_id']; ?>" class="accordion-collapse collapse <?php echo ($index === 0) ? 'show' : ''; ?>" aria-labelledby="heading<?php echo $solicitud['solicitud_id']; ?>" data-bs-parent="#accordionSolicitudes">
                                            <div class="accordion-body">
                                                <p><strong>Solicitante:</strong> <?php echo htmlspecialchars($solicitud['usuario_nombre']); ?></p>
                                                <p><strong>Fechas Solicitadas:</strong> <?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_salida_solicitada'])); ?> a <?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_regreso_solicitada'])); ?></p>
                                                <p><strong>Evento:</strong> <?php echo htmlspecialchars($solicitud['evento']); ?></p>
                                                <p><strong>Descripción:</strong> <?php echo htmlspecialchars($solicitud['descripcion']); ?></p>
                                                <p><strong>Destino:</strong> <?php echo htmlspecialchars($solicitud['destino']); ?></p>
                                                <p><strong>Observaciones del Gestor:</strong> <?php echo htmlspecialchars($solicitud['observaciones_aprobacion'] ?? 'Ninguna.'); ?></p>

                                                <?php if (!empty($solicitud['fecha_salida_real'])): ?>
                                                    <h6>Registro de Salida:</h6>
                                                    <p><strong>Fecha/Hora Salida Real:</strong> <?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_salida_real'])); ?></p>
                                                    <p><strong>KM Salida:</strong> <?php echo htmlspecialchars(number_format($solicitud['kilometraje_salida'])); ?></p>
                                                    <p><strong>Nivel Combustible Salida:</strong> <?php echo htmlspecialchars($solicitud['nivel_combustible_salida']); ?>%</p>
                                                    <p><strong>Obs. Salida:</strong> <?php echo htmlspecialchars($solicitud['observaciones_salida'] ?? 'Ninguna.'); ?></p>
                                                    <?php
                                                    $fotos_salida_urls = json_decode($solicitud['fotos_salida_medidores_url'] ?? '[]', true);
                                                    if (!empty($fotos_salida_urls)):
                                                    ?>
                                                        <div class="row mb-3">
                                                            <p><strong>Fotos de Salida (Medidores):</strong></p>
                                                            <?php foreach ($fotos_salida_urls as $url): ?>
                                                                <div class="col-4 col-md-3 mb-2">
                                                                    <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                                                                        <img src="<?php echo htmlspecialchars($url); ?>" class="img-fluid rounded shadow-sm" alt="Foto Salida">
                                                                    </a>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php
                                                    $fotos_salida_observaciones_urls = json_decode($solicitud['fotos_salida_observaciones_url'] ?? '[]', true);
                                                    if (!empty($fotos_salida_observaciones_urls)):
                                                    ?>
                                                        <div class="row mb-3">
                                                            <p><strong>Fotos de Salida (Observaciones):</strong></p>
                                                            <?php foreach ($fotos_salida_observaciones_urls as $url): ?>
                                                                <div class="col-4 col-md-3 mb-2">
                                                                    <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                                                                        <img src="<?php echo htmlspecialchars($url); ?>" class="img-fluid rounded shadow-sm" alt="Foto Salida">
                                                                    </a>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if (!empty($solicitud['fecha_regreso_real'])): ?>
                                                    <h6>Registro de Regreso:</h6>
                                                    <p><strong>Fecha/Hora Regreso Real:</strong> <?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_regreso_real'])); ?></p>
                                                    <p><strong>KM Regreso:</strong> <?php echo htmlspecialchars(number_format($solicitud['kilometraje_regreso'])); ?></p>
                                                    <p><strong>Nivel Combustible Regreso:</strong> <?php echo htmlspecialchars($solicitud['nivel_combustible_regreso']); ?>%</p>
                                                    <p><strong>Obs. Regreso:</strong> <?php echo htmlspecialchars($solicitud['observaciones_regreso'] ?? 'Ninguna.'); ?></p>
                                                    <?php
                                                    $fotos_regreso_medidores = json_decode($solicitud['fotos_regreso_medidores_url'] ?? '[]', true);
                                                    if (!empty($fotos_regreso_medidores)):
                                                    ?>
                                                        <div class="row mb-3">
                                                            <p><strong>Fotos de Regreso (Medidores):</strong></p>
                                                            <?php foreach ($fotos_regreso_medidores as $url): ?>
                                                                <div class="col-4 col-md-3 mb-2">
                                                                    <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                                                                        <img src="<?php echo htmlspecialchars($url); ?>" class="img-fluid rounded shadow-sm" alt="Foto Regreso Medidores">
                                                                    </a>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php
                                                    $fotos_regreso_observaciones = json_decode($solicitud['fotos_regreso_observaciones_url'] ?? '[]', true);
                                                    if (!empty($fotos_regreso_observaciones)):
                                                    ?>
                                                        <div class="row mb-3">
                                                            <p><strong>Fotos de Regreso (Observaciones):</strong></p>
                                                            <?php foreach ($fotos_regreso_observaciones as $url): ?>
                                                                <div class="col-4 col-md-3 mb-2">
                                                                    <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                                                                        <img src="<?php echo htmlspecialchars($url); ?>" class="img-fluid rounded shadow-sm" alt="Foto Regreso Observaciones">
                                                                    </a>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4 shadow-sm">
                    <div class="card-header">
                        Historial de Mantenimientos
                    </div>
                    <div class="card-body">
                        <?php if (empty($mantenimientos_historicos)): ?>
                            <div class="alert alert-info text-center" role="alert">
                                Este vehículo no tiene mantenimientos registrados.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Fecha</th>
                                            <th>KM</th>
                                            <th>Costo</th>
                                            <th>Taller</th>
                                            <th>Observaciones</th>
                                            <th>Próx. KM</th>
                                            <th>Próx. Fecha</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mantenimientos_historicos as $mantenimiento): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($mantenimiento['tipo_mantenimiento']); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($mantenimiento['fecha_mantenimiento'])); ?></td>
                                                <td><?php echo htmlspecialchars(number_format($mantenimiento['kilometraje_mantenimiento'])); ?></td>
                                                <td><?php echo $mantenimiento['costo'] !== null ? '$' . number_format($mantenimiento['costo'], 2) : 'N/A'; ?></td>
                                                <td><?php echo htmlspecialchars($mantenimiento['taller'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($mantenimiento['observaciones'] ?? 'N/A'); ?></td>
                                                <td><?php echo $mantenimiento['proximo_mantenimiento_km'] !== null ? htmlspecialchars(number_format($mantenimiento['proximo_mantenimiento_km'])) . ' KM' : 'N/A'; ?></td>
                                                <td><?php echo $mantenimiento['proximo_mantenimiento_fecha'] !== null ? date('d/m/Y', strtotime($mantenimiento['proximo_mantenimiento_fecha'])) : 'N/A'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4 shadow-sm">
                    <div class="card-header">
                        Documentos del Vehículo
                        <a href="gestion_documentos.php?vehiculo_id=<?php echo $vehiculo['id']; ?>" class="btn btn-sm btn-outline-success float-end">
                            Gestionar Documentos
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($documentos_vehiculo)): ?>
                            <div class="alert alert-info text-center" role="alert">
                                Este vehículo no tiene documentos cargados.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>Vencimiento</th>
                                            <th>Subido</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($documentos_vehiculo as $doc): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($doc['nombre_documento']); ?></td>
                                                <td><?php echo $doc['fecha_vencimiento'] ? date('d/m/Y', strtotime($doc['fecha_vencimiento'])) : 'N/A'; ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($doc['fecha_subida'])); ?></td>
                                                <td>
                                                    <a href="<?php echo htmlspecialchars($doc['ruta_archivo']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">Ver</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>
            </div>

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

            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
            <script src="js/main.js"></script>
            <script>
                // JavaScript para manejar los modales de agregar/editar vehículo (copiado de gestion_vehiculos.php para que funcione el botón "Editar Vehículo")
                // Este modal HTML también debe ser copiado de gestion_vehiculos.php y pegado en detalle_vehiculo.php antes del </body>
                document.addEventListener('DOMContentLoaded', function() {
                    var addEditVehicleModal = document.getElementById('addEditVehicleModal');
                    if (addEditVehicleModal) {
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
                    }

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