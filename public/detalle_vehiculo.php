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
        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert">
                <?php echo $error_message; ?>
            </div>
            <a href="gestion_vehiculos.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg font-semibold hover:bg-gray-600 transition">Regresar a Gestión de Vehículos</a>
        <?php elseif (!$vehiculo): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4" role="alert">
                Vehículo no encontrado o no válido.
            </div>
            <a href="gestion_vehiculos.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg font-semibold hover:bg-gray-600 transition">Regresar a Gestión de Vehículos</a>
        <?php else: ?>
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-bold text-darkpurple">Detalle de Vehículo: <?php echo htmlspecialchars($vehiculo['marca'] . ' ' . $vehiculo['modelo'] . ' (' . $vehiculo['placas'] . ')'); ?></h1>
                <a href="gestion_vehiculos.php" class="bg-cambridge2 text-darkpurple px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition">
                    <i class="bi bi-arrow-left"></i> Volver a Gestión
                </a>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-darkpurple">Información General</h3>
                    <button type="button" class="bg-cambridge1 text-white px-3 py-1 rounded text-sm font-semibold hover:bg-cambridge2 transition" data-modal-target="addEditVehicleModal" data-action="edit"
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
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="mb-2"><span class="font-semibold text-darkpurple">Marca:</span> <?php echo htmlspecialchars($vehiculo['marca']); ?></p>
                    </div>
                    <div>
                        <p class="mb-2"><span class="font-semibold text-darkpurple">Modelo:</span> <?php echo htmlspecialchars($vehiculo['modelo']); ?></p>
                    </div>
                    <div>
                        <p class="mb-2"><span class="font-semibold text-darkpurple">Año:</span> <?php echo htmlspecialchars($vehiculo['anio']); ?></p>
                    </div>
                    <div>
                        <p class="mb-2"><span class="font-semibold text-darkpurple">Placas:</span> <?php echo htmlspecialchars($vehiculo['placas']); ?></p>
                    </div>
                    <div>
                        <p class="mb-2"><span class="font-semibold text-darkpurple">VIN:</span> <?php echo htmlspecialchars($vehiculo['vin'] ?? 'N/A'); ?></p>
                    </div>
                    <div>
                        <p class="mb-2"><span class="font-semibold text-darkpurple">Tipo de Combustible:</span> <?php echo htmlspecialchars($vehiculo['tipo_combustible']); ?></p>
                    </div>
                    <div>
                        <p class="mb-2"><span class="font-semibold text-darkpurple">Kilometraje Actual:</span> <?php echo htmlspecialchars(number_format($vehiculo['kilometraje_actual'])); ?> KM</p>
                    </div>
                    <div>
                        <p class="mb-2">
                            <span class="font-semibold text-darkpurple">Estatus:</span>
                            <?php
                            $status_class = '';
                            switch ($vehiculo['estatus']) {
                                case 'disponible':
                                    $status_class = 'bg-green-500 text-white';
                                    break;
                                case 'en_uso':
                                    $status_class = 'bg-cambridge1 text-white';
                                    break;
                                case 'en_mantenimiento':
                                    $status_class = 'bg-yellow-500 text-white';
                                    break;
                                case 'inactivo':
                                    $status_class = 'bg-red-500 text-white';
                                    break;
                            }
                            ?>
                            <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($vehiculo['estatus'])); ?></span>
                        </p>
                    </div>
                    <div>
                        <p class="mb-2"><span class="font-semibold text-darkpurple">Ubicación Actual:</span> <?php echo htmlspecialchars($vehiculo['ubicacion_actual'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="md:col-span-2">
                        <p class="mb-2"><span class="font-semibold text-darkpurple">Observaciones:</span> <?php echo htmlspecialchars($vehiculo['observaciones'] ?? 'Ninguna.'); ?></p>
                    </div>
                    <div class="md:col-span-2">
                        <p class="mb-2"><span class="font-semibold text-darkpurple">Fecha de Registro:</span> <?php echo date('d/m/Y H:i', strtotime($vehiculo['fecha_registro'])); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h3 class="text-lg font-semibold text-darkpurple mb-4">Historial de Solicitudes y Uso</h3>
                <?php if (empty($solicitudes_historicas)): ?>
                    <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded text-center" role="alert">
                        Este vehículo no tiene solicitudes o historial de uso registrado.
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach (
                            $solicitudes_historicas as $index => $solicitud): ?>
                            <div class="border border-cambridge2 rounded-lg bg-parchment shadow-sm">
                                <!-- Header colapsable -->
                                <div class="flex flex-wrap justify-between items-center p-4 cursor-pointer hover:bg-cambridge1 hover:bg-opacity-20 transition" onclick="toggleSolicitud(<?php echo $index; ?>)">
                                    <div class="flex items-center gap-3">
                                        <svg id="icon-<?php echo $index; ?>" class="w-5 h-5 text-darkpurple transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                        <span class="font-semibold text-darkpurple">Solicitud #<?php echo htmlspecialchars($solicitud['solicitud_id']); ?> - <?php echo htmlspecialchars($solicitud['evento']); ?> (<?php echo htmlspecialchars($solicitud['usuario_nombre']); ?>)</span>
                                    </div>
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
                                                echo 'bg-cambridge2 text-darkpurple';
                                                break;
                                            case 'completada':
                                                echo 'bg-gray-500 text-white';
                                                break;
                                            case 'cancelada':
                                                echo 'bg-blue-500 text-white';
                                                break;
                                        }
                                    ?>"><?php echo htmlspecialchars(ucfirst($solicitud['estatus_solicitud'])); ?></span>
                                </div>
                                
                                <!-- Contenido colapsable -->
                                <div id="content-<?php echo $index; ?>" class="hidden px-4 pb-4">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <p><span class="font-semibold text-darkpurple">Solicitante:</span> <?php echo htmlspecialchars($solicitud['usuario_nombre']); ?></p>
                                        <p><span class="font-semibold text-darkpurple">Fechas Solicitadas:</span> <?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_salida_solicitada'])); ?> a <?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_regreso_solicitada'])); ?></p>
                                        <p><span class="font-semibold text-darkpurple">Evento:</span> <?php echo htmlspecialchars($solicitud['evento']); ?></p>
                                        <p><span class="font-semibold text-darkpurple">Destino:</span> <?php echo htmlspecialchars($solicitud['destino']); ?></p>
                                    </div>
                                    <p class="mb-2"><span class="font-semibold text-darkpurple">Descripción:</span> <?php echo htmlspecialchars($solicitud['descripcion']); ?></p>
                                    <p class="mb-4"><span class="font-semibold text-darkpurple">Observaciones del Gestor:</span> <?php echo htmlspecialchars($solicitud['observaciones_aprobacion'] ?? 'Ninguna.'); ?></p>

                                    <?php if (!empty($solicitud['fecha_salida_real'])): ?>
                                        <div class="mb-2">
                                            <h6 class="font-semibold text-cambridge1">Registro de Salida:</h6>
                                            <p><strong>Fecha/Hora Salida Real:</strong> <?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_salida_real'])); ?></p>
                                            <p><strong>KM Salida:</strong> <?php echo htmlspecialchars(number_format($solicitud['kilometraje_salida'])); ?></p>
                                            <p><strong>Nivel Combustible Salida:</strong> <?php echo htmlspecialchars($solicitud['nivel_combustible_salida']); ?>%</p>
                                            <p><strong>Obs. Salida:</strong> <?php echo htmlspecialchars($solicitud['observaciones_salida'] ?? 'Ninguna.'); ?></p>
                                            <?php
                                            $fotos_salida_urls = json_decode($solicitud['fotos_salida_medidores_url'] ?? '[]', true);
                                            if (!empty($fotos_salida_urls)):
                                            ?>
                                                <div class="mb-2">
                                                    <p><strong>Fotos de Salida (Medidores):</strong></p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <?php foreach ($fotos_salida_urls as $url): ?>
                                                            <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                                                                <img src="<?php echo htmlspecialchars($url); ?>" class="h-20 rounded shadow-sm" alt="Foto Salida">
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php
                                            $fotos_salida_observaciones_urls = json_decode($solicitud['fotos_salida_observaciones_url'] ?? '[]', true);
                                            if (!empty($fotos_salida_observaciones_urls)):
                                            ?>
                                                <div class="mb-2">
                                                    <p><strong>Fotos de Salida (Observaciones):</strong></p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <?php foreach ($fotos_salida_observaciones_urls as $url): ?>
                                                            <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                                                                <img src="<?php echo htmlspecialchars($url); ?>" class="h-20 rounded shadow-sm" alt="Foto Salida">
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($solicitud['fecha_regreso_real'])): ?>
                                        <div class="mb-2">
                                            <h6 class="font-semibold text-cambridge1">Registro de Regreso:</h6>
                                            <p><strong>Fecha/Hora Regreso Real:</strong> <?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_regreso_real'])); ?></p>
                                            <p><strong>KM Regreso:</strong> <?php echo htmlspecialchars(number_format($solicitud['kilometraje_regreso'])); ?></p>
                                            <p><strong>Nivel Combustible Regreso:</strong> <?php echo htmlspecialchars($solicitud['nivel_combustible_regreso']); ?>%</p>
                                            <p><strong>Obs. Regreso:</strong> <?php echo htmlspecialchars($solicitud['observaciones_regreso'] ?? 'Ninguna.'); ?></p>
                                            <?php
                                            $fotos_regreso_medidores = json_decode($solicitud['fotos_regreso_medidores_url'] ?? '[]', true);
                                            if (!empty($fotos_regreso_medidores)):
                                            ?>
                                                <div class="mb-2">
                                                    <p><strong>Fotos de Regreso (Medidores):</strong></p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <?php foreach ($fotos_regreso_medidores as $url): ?>
                                                            <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                                                                <img src="<?php echo htmlspecialchars($url); ?>" class="h-20 rounded shadow-sm" alt="Foto Regreso Medidores">
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php
                                            $fotos_regreso_observaciones = json_decode($solicitud['fotos_regreso_observaciones_url'] ?? '[]', true);
                                            if (!empty($fotos_regreso_observaciones)):
                                            ?>
                                                <div class="mb-2">
                                                    <p><strong>Fotos de Regreso (Observaciones):</strong></p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <?php foreach ($fotos_regreso_observaciones as $url): ?>
                                                            <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                                                                <img src="<?php echo htmlspecialchars($url); ?>" class="h-20 rounded shadow-sm" alt="Foto Regreso Observaciones">
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h3 class="text-lg font-semibold text-darkpurple mb-4">Historial de Mantenimientos</h3>
                <?php if (empty($mantenimientos_historicos)): ?>
                    <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded text-center" role="alert">
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

            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h3 class="text-lg font-semibold text-darkpurple mb-4">Documentos del Vehículo</h3>
                <a href="gestion_documentos.php?vehiculo_id=<?php echo $vehiculo['id']; ?>" class="bg-cambridge2 text-white px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition">
                    <i class="bi bi-plus-circle"></i> Gestionar Documentos
                </a>
                <?php if (empty($documentos_vehiculo)): ?>
                    <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded text-center" role="alert">
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
                                            <a href="<?php echo htmlspecialchars($doc['ruta_archivo']); ?>" target="_blank" class="bg-cambridge2 text-white px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition">Ver</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- Modal para Agregar/Editar Vehículo -->
    <div id="addEditVehicleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center p-6 border-b border-gray-200">
                <h5 class="text-lg font-semibold text-gray-900" id="addEditVehicleModalLabel"></h5>
                <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('addEditVehicleModal')">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form action="gestion_vehiculos.php" method="POST">
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" id="modalActionVehicle">
                    <input type="hidden" name="id" id="vehicleId">

                    <div>
                        <label for="marca" class="block text-sm font-medium text-gray-700 mb-2">Marca</label>
                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="marca" name="marca" required>
                    </div>
                    <div>
                        <label for="modelo" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="modelo" name="modelo" required>
                    </div>
                    <div>
                        <label for="anio" class="block text-sm font-medium text-gray-700 mb-2">Año</label>
                        <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="anio" name="anio" min="1900" max="<?php echo date('Y') + 1; ?>" required>
                    </div>
                    <div>
                        <label for="placas" class="block text-sm font-medium text-gray-700 mb-2">Placas</label>
                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="placas" name="placas" required>
                    </div>
                    <div>
                        <label for="vin" class="block text-sm font-medium text-gray-700 mb-2">VIN (Número de Identificación Vehicular)</label>
                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="vin" name="vin">
                    </div>
                    <div>
                        <label for="tipo_combustible" class="block text-sm font-medium text-gray-700 mb-2">Tipo de Combustible</label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="tipo_combustible" name="tipo_combustible" required>
                            <option value="">Selecciona...</option>
                            <option value="Gasolina">Gasolina</option>
                            <option value="Diésel">Diésel</option>
                            <option value="Eléctrico">Eléctrico</option>
                            <option value="Híbrido">Híbrido</option>
                        </select>
                    </div>
                    <div>
                        <label for="kilometraje_actual" class="block text-sm font-medium text-gray-700 mb-2">Kilometraje Actual</label>
                        <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="kilometraje_actual" name="kilometraje_actual" min="0" required>
                    </div>
                    <div id="estatusField" class="hidden">
                        <label for="estatus" class="block text-sm font-medium text-gray-700 mb-2">Estatus</label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="estatus" name="estatus" required>
                            <option value="disponible">Disponible</option>
                            <option value="en_uso">En Uso</option>
                            <option value="en_mantenimiento">En Mantenimiento</option>
                            <option value="inactivo">Inactivo</option>
                        </select>
                    </div>
                    <div>
                        <label for="ubicacion_actual" class="block text-sm font-medium text-gray-700 mb-2">Ubicación Actual</label>
                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="ubicacion_actual" name="ubicacion_actual">
                    </div>
                    <div>
                        <label for="observaciones_vehiculo" class="block text-sm font-medium text-gray-700 mb-2">Observaciones</label>
                        <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="observaciones_vehiculo" name="observaciones" rows="3"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                    <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('addEditVehicleModal')">Cancelar</button>
                    <button type="submit" class="px-4 py-2 text-white rounded-md transition-colors" id="submitVehicleBtn"></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para Eliminar Vehículo -->
    <div id="deleteVehicleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="flex justify-between items-center p-6 border-b border-gray-200">
                <h5 class="text-lg font-semibold text-gray-900" id="deleteVehicleModalLabel">Confirmar Eliminación</h5>
                <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('deleteVehicleModal')">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form action="gestion_vehiculos.php" method="POST">
                <div class="p-6">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteVehicleId">
                    <p class="text-gray-700">¿Estás seguro de que quieres eliminar el vehículo con placas <strong id="deleteVehiclePlacas"></strong>?</p>
                    <p class="text-red-600 text-sm mt-2">Esta acción es irreversible.</p>
                </div>
                <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                    <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('deleteVehicleModal')">Cancelar</button>
                    <button type="submit" class="px-4 py-2 text-white bg-red-600 rounded-md hover:bg-red-700 transition-colors">Eliminar</button>
                </div>
            </form>
        </div>
    </div>

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

        // JavaScript para manejar los modales de agregar/editar vehículo
        document.addEventListener('DOMContentLoaded', function() {
            // Configurar botones para abrir modales
            document.querySelectorAll('[data-modal-target]').forEach(button => {
                button.addEventListener('click', function() {
                    const modalId = this.getAttribute('data-modal-target');
                    const action = this.getAttribute('data-action');
                    
                    if (modalId === 'addEditVehicleModal') {
                        setupAddEditVehicleModal(action, this);
                    } else if (modalId === 'deleteVehicleModal') {
                        setupDeleteVehicleModal(this);
                    }
                    
                    openModal(modalId);
                });
            });

            function setupAddEditVehicleModal(action, button) {
                var modalTitle = document.getElementById('addEditVehicleModalLabel');
                var modalActionInput = document.getElementById('modalActionVehicle');
                var vehicleIdInput = document.getElementById('vehicleId');
                var submitBtn = document.getElementById('submitVehicleBtn');
                var estatusField = document.getElementById('estatusField');
                var form = document.querySelector('#addEditVehicleModal form');

                form.reset();
                estatusField.classList.add('hidden');

                if (action === 'add') {
                    modalTitle.textContent = 'Agregar Nuevo Vehículo';
                    modalActionInput.value = 'add';
                    submitBtn.textContent = 'Guardar Vehículo';
                    submitBtn.className = 'px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors';
                    vehicleIdInput.value = '';
                } else if (action === 'edit') {
                    modalTitle.textContent = 'Editar Vehículo';
                    modalActionInput.value = 'edit';
                    submitBtn.textContent = 'Actualizar Vehículo';
                    submitBtn.className = 'px-4 py-2 text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors';
                    estatusField.classList.remove('hidden');

                    vehicleIdInput.value = button.getAttribute('data-id');
                    document.getElementById('marca').value = button.getAttribute('data-marca');
                    document.getElementById('modelo').value = button.getAttribute('data-modelo');
                    document.getElementById('anio').value = button.getAttribute('data-anio');
                    document.getElementById('placas').value = button.getAttribute('data-placas');
                    document.getElementById('vin').value = button.getAttribute('data-vin') === 'null' ? '' : button.getAttribute('data-vin');
                    document.getElementById('tipo_combustible').value = button.getAttribute('data-tipo-combustible');
                    document.getElementById('kilometraje_actual').value = button.getAttribute('data-kilometraje-actual');
                    document.getElementById('estatus').value = button.getAttribute('data-estatus');
                    document.getElementById('ubicacion_actual').value = button.getAttribute('data-ubicacion-actual') === 'null' ? '' : button.getAttribute('data-ubicacion-actual');
                    document.getElementById('observaciones_vehiculo').value = button.getAttribute('data-observaciones') === 'null' ? '' : button.getAttribute('data-observaciones');
                }
            }

            function setupDeleteVehicleModal(button) {
                var vehicleId = button.getAttribute('data-id');
                var vehiclePlacas = button.getAttribute('data-placas');

                document.getElementById('deleteVehicleId').value = vehicleId;
                document.getElementById('deleteVehiclePlacas').textContent = vehiclePlacas;
            }
        });
    </script>

    <!-- JavaScript para el acordeón del historial de solicitudes -->
    <script>
        function toggleSolicitud(index) {
            const content = document.getElementById(`content-${index}`);
            const icon = document.getElementById(`icon-${index}`);
            
            if (content.classList.contains('hidden')) {
                // Expandir
                content.classList.remove('hidden');
                icon.style.transform = 'rotate(90deg)';
            } else {
                // Contraer
                content.classList.add('hidden');
                icon.style.transform = 'rotate(0deg)';
            }
        }

        // Opcional: Expandir la primera solicitud por defecto
        document.addEventListener('DOMContentLoaded', function() {
            const firstContent = document.getElementById('content-0');
            const firstIcon = document.getElementById('icon-0');
            if (firstContent && firstIcon) {
                firstContent.classList.remove('hidden');
                firstIcon.style.transform = 'rotate(90deg)';
            }
        });
    </script>
</body>

</html>