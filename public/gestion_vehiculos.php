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

// --- Configuración de filtros y paginación ---
$filtros = [
    'marca' => $_GET['filtro_marca'] ?? '',
    'modelo' => $_GET['filtro_modelo'] ?? '',
    'placas' => $_GET['filtro_placas'] ?? '',
    'estatus' => $_GET['filtro_estatus'] ?? '',
    'tipo_combustible' => $_GET['filtro_tipo_combustible'] ?? '',
    'ubicacion' => $_GET['filtro_ubicacion'] ?? ''
];

$registros_por_pagina = $_GET['registros_por_pagina'] ?? 10;
$pagina_actual = $_GET['pagina'] ?? 1;

// Validar registros por página
$opciones_registros = [10, 30, 50, 'todos'];
if (!in_array($registros_por_pagina, $opciones_registros)) {
    $registros_por_pagina = 10;
}

// --- Obtener todos los vehículos con filtros y paginación ---
if ($db) {
    try {
        // Construir la consulta base
        $sql_base = "SELECT * FROM vehiculos";

        // Construir las condiciones WHERE
        $where_conditions = [];
        $params = [];

        if (!empty($filtros['marca'])) {
            $where_conditions[] = "marca LIKE :marca";
            $params[':marca'] = '%' . $filtros['marca'] . '%';
        }

        if (!empty($filtros['modelo'])) {
            $where_conditions[] = "modelo LIKE :modelo";
            $params[':modelo'] = '%' . $filtros['modelo'] . '%';
        }

        if (!empty($filtros['placas'])) {
            $where_conditions[] = "placas LIKE :placas";
            $params[':placas'] = '%' . $filtros['placas'] . '%';
        }

        if (!empty($filtros['estatus'])) {
            $where_conditions[] = "estatus = :estatus";
            $params[':estatus'] = $filtros['estatus'];
        }

        if (!empty($filtros['tipo_combustible'])) {
            $where_conditions[] = "tipo_combustible = :tipo_combustible";
            $params[':tipo_combustible'] = $filtros['tipo_combustible'];
        }

        if (!empty($filtros['ubicacion'])) {
            $where_conditions[] = "ubicacion_actual LIKE :ubicacion";
            $params[':ubicacion'] = '%' . $filtros['ubicacion'] . '%';
        }

        // Agregar condiciones WHERE si existen
        if (!empty($where_conditions)) {
            $sql_base .= " WHERE " . implode(' AND ', $where_conditions);
        }

        $sql_base .= " ORDER BY marca, modelo";

        // Obtener el total de registros para paginación
        $sql_count = "SELECT COUNT(*) FROM vehiculos";
        if (!empty($where_conditions)) {
            $sql_count .= " WHERE " . implode(' AND ', $where_conditions);
        }

        $stmt_count = $db->prepare($sql_count);
        foreach ($params as $key => $value) {
            $stmt_count->bindValue($key, $value);
        }
        $stmt_count->execute();
        $total_registros = $stmt_count->fetchColumn();

        // Calcular paginación
        $total_paginas = 1;
        $offset = 0;

        if ($registros_por_pagina !== 'todos') {
            $total_paginas = ceil($total_registros / $registros_por_pagina);
            $offset = ($pagina_actual - 1) * $registros_por_pagina;

            // Agregar LIMIT a la consulta
            $sql_base .= " LIMIT :limit OFFSET :offset";
            $params[':limit'] = (int)$registros_por_pagina;
            $params[':offset'] = $offset;
        }

        // Ejecutar la consulta principal
        $stmt = $db->prepare($sql_base);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
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
    <link rel="stylesheet" href="css/colors.css">
    <link rel="stylesheet" href="css/style.css">
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
        <h1 class="text-3xl font-bold text-darkpurple mb-6">Gestión de Vehículos</h1>

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

        <button type="button" class="bg-cambridge2 text-darkpurple px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition mb-6" data-modal-target="addEditVehicleModal" data-action="add">
            <i class="bi bi-plus-circle"></i> Agregar Nuevo Vehículo
        </button>

        <!-- Filtros y Controles -->
        <div class="bg-white rounded-xl shadow-lg border border-cambridge2 mb-6">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-darkpurple mb-4">Filtros y Controles</h3>

                <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-7 gap-4">
                    <!-- Filtro por Marca -->
                    <div>
                        <label for="filtro_marca" class="block text-sm font-medium text-gray-700 mb-1">Marca</label>
                        <input type="text" name="filtro_marca" id="filtro_marca" value="<?php echo htmlspecialchars($filtros['marca']); ?>" placeholder="Buscar por marca" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1">
                    </div>

                    <!-- Filtro por Modelo -->
                    <div>
                        <label for="filtro_modelo" class="block text-sm font-medium text-gray-700 mb-1">Modelo</label>
                        <input type="text" name="filtro_modelo" id="filtro_modelo" value="<?php echo htmlspecialchars($filtros['modelo']); ?>" placeholder="Buscar por modelo" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1">
                    </div>

                    <!-- Filtro por Placas -->
                    <div>
                        <label for="filtro_placas" class="block text-sm font-medium text-gray-700 mb-1">Placas</label>
                        <input type="text" name="filtro_placas" id="filtro_placas" value="<?php echo htmlspecialchars($filtros['placas']); ?>" placeholder="Buscar por placas" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1">
                    </div>

                    <!-- Filtro por Estatus -->
                    <div>
                        <label for="filtro_estatus" class="block text-sm font-medium text-gray-700 mb-1">Estatus</label>
                        <select name="filtro_estatus" id="filtro_estatus" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1">
                            <option value="">Todos los estatus</option>
                            <option value="disponible" <?php echo $filtros['estatus'] === 'disponible' ? 'selected' : ''; ?>>Disponible</option>
                            <option value="en_uso" <?php echo $filtros['estatus'] === 'en_uso' ? 'selected' : ''; ?>>En Uso</option>
                            <option value="en_mantenimiento" <?php echo $filtros['estatus'] === 'en_mantenimiento' ? 'selected' : ''; ?>>En Mantenimiento</option>
                            <option value="inactivo" <?php echo $filtros['estatus'] === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                        </select>
                    </div>

                    <!-- Filtro por Tipo de Combustible -->
                    <div>
                        <label for="filtro_tipo_combustible" class="block text-sm font-medium text-gray-700 mb-1">Combustible</label>
                        <select name="filtro_tipo_combustible" id="filtro_tipo_combustible" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1">
                            <option value="">Todos los tipos</option>
                            <option value="Gasolina" <?php echo $filtros['tipo_combustible'] === 'Gasolina' ? 'selected' : ''; ?>>Gasolina</option>
                            <option value="Diésel" <?php echo $filtros['tipo_combustible'] === 'Diésel' ? 'selected' : ''; ?>>Diésel</option>
                            <option value="Eléctrico" <?php echo $filtros['tipo_combustible'] === 'Eléctrico' ? 'selected' : ''; ?>>Eléctrico</option>
                            <option value="Híbrido" <?php echo $filtros['tipo_combustible'] === 'Híbrido' ? 'selected' : ''; ?>>Híbrido</option>
                        </select>
                    </div>

                    <!-- Filtro por Ubicación -->
                    <div>
                        <label for="filtro_ubicacion" class="block text-sm font-medium text-gray-700 mb-1">Ubicación</label>
                        <input type="text" name="filtro_ubicacion" id="filtro_ubicacion" value="<?php echo htmlspecialchars($filtros['ubicacion']); ?>" placeholder="Buscar por ubicación" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1">
                    </div>

                    <!-- Registros por página -->
                    <div>
                        <label for="registros_por_pagina" class="block text-sm font-medium text-gray-700 mb-1">Registros por página</label>
                        <select name="registros_por_pagina" id="registros_por_pagina" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1">
                            <option value="10" <?php echo $registros_por_pagina == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="30" <?php echo $registros_por_pagina == 30 ? 'selected' : ''; ?>>30</option>
                            <option value="50" <?php echo $registros_por_pagina == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="todos" <?php echo $registros_por_pagina == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        </select>
                    </div>

                    <!-- Botones de acción -->
                    <div class="flex gap-2 items-end">
                        <button type="submit" class="bg-cambridge1 text-white px-4 py-2 rounded-md hover:bg-cambridge2 transition-colors">
                            <i class="bi bi-search"></i> Filtrar
                        </button>
                        <a href="gestion_vehiculos.php" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 transition-colors">
                            <i class="bi bi-arrow-clockwise"></i> Limpiar
                        </a>
                    </div>
                </form>

                <!-- Información de resultados -->
                <div class="mt-4 text-sm text-gray-600">
                    Mostrando <?php echo count($vehiculos); ?> de <?php echo $total_registros; ?> vehículos
                    <?php if (!empty(array_filter($filtros))): ?>
                        (con filtros aplicados)
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (empty($vehiculos)): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded" role="alert">
                No hay vehículos registrados en la flotilla.
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-cambridge2">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-cambridge1 text-white">
                                <th class="px-4 py-3 text-left">Marca</th>
                                <th class="px-4 py-3 text-left">Modelo</th>
                                <th class="px-4 py-3 text-left">Año</th>
                                <th class="px-4 py-3 text-left">Placas</th>
                                <th class="px-4 py-3 text-left">VIN</th>
                                <th class="px-4 py-3 text-left">Combustible</th>
                                <th class="px-4 py-3 text-left">KM Actual</th>
                                <th class="px-4 py-3 text-left">Estatus</th>
                                <th class="px-4 py-3 text-left">Ubicación</th>
                                <th class="px-4 py-3 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehiculos as $vehiculo): ?>
                                <tr class="border-b border-cambridge2 hover:bg-parchment">
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($vehiculo['marca']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($vehiculo['modelo']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($vehiculo['anio']); ?></td>
                                    <td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars($vehiculo['placas']); ?></td>
                                    <td class="px-4 py-3 text-sm text-mountbatten"><?php echo htmlspecialchars($vehiculo['vin'] ?? 'N/A'); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($vehiculo['tipo_combustible']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars(number_format($vehiculo['kilometraje_actual'])); ?></td>
                                    <td class="px-4 py-3">
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
                                    </td>
                                    <td class="px-4 py-3 text-sm text-mountbatten"><?php echo htmlspecialchars($vehiculo['ubicacion_actual'] ?? 'N/A'); ?></td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <a href="detalle_vehiculo.php?id=<?php echo $vehiculo['id']; ?>" class="bg-gray-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-gray-600 transition">
                                                Ver Detalles
                                            </a>
                                            <button type="button" class="bg-cambridge1 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-cambridge2 transition" data-modal-target="addEditVehicleModal" data-action="edit"
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
                                            <button type="button" class="bg-red-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-700 transition" data-modal-target="deleteVehicleModal" data-id="<?php echo $vehiculo['id']; ?>" data-placas="<?php echo htmlspecialchars($vehiculo['placas']); ?>">
                                                <i class="bi bi-trash"></i> Eliminar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Paginación -->
            <?php if ($registros_por_pagina !== 'todos' && $total_paginas > 1): ?>
                <div class="bg-white border-t border-cambridge2 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>
                        </div>

                        <div class="flex items-center space-x-2">
                            <?php
                            // Construir parámetros de URL para mantener filtros
                            $url_params = array_filter($filtros);
                            $url_params['registros_por_pagina'] = $registros_por_pagina;
                            $query_string = http_build_query($url_params);
                            ?>

                            <!-- Botón Anterior -->
                            <?php if ($pagina_actual > 1): ?>
                                <a href="?<?php echo $query_string; ?>&pagina=<?php echo $pagina_actual - 1; ?>"
                                    class="px-3 py-2 text-sm bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors">
                                    ← Anterior
                                </a>
                            <?php endif; ?>

                            <!-- Números de página -->
                            <div class="flex space-x-1">
                                <?php
                                $inicio = max(1, $pagina_actual - 2);
                                $fin = min($total_paginas, $pagina_actual + 2);

                                if ($inicio > 1): ?>
                                    <a href="?<?php echo $query_string; ?>&pagina=1"
                                        class="px-3 py-2 text-sm bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors">
                                        1
                                    </a>
                                    <?php if ($inicio > 2): ?>
                                        <span class="px-2 py-2 text-gray-400">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $inicio; $i <= $fin; $i++): ?>
                                    <a href="?<?php echo $query_string; ?>&pagina=<?php echo $i; ?>"
                                        class="px-3 py-2 text-sm rounded-md transition-colors <?php echo $i == $pagina_actual ? 'bg-cambridge1 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($fin < $total_paginas): ?>
                                    <?php if ($fin < $total_paginas - 1): ?>
                                        <span class="px-2 py-2 text-gray-400">...</span>
                                    <?php endif; ?>
                                    <a href="?<?php echo $query_string; ?>&pagina=<?php echo $total_paginas; ?>"
                                        class="px-3 py-2 text-sm bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors">
                                        <?php echo $total_paginas; ?>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <!-- Botón Siguiente -->
                            <?php if ($pagina_actual < $total_paginas): ?>
                                <a href="?<?php echo $query_string; ?>&pagina=<?php echo $pagina_actual + 1; ?>"
                                    class="px-3 py-2 text-sm bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors">
                                    Siguiente →
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

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

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                        </div>
                        <div>
                            <label for="observaciones_vehiculo" class="block text-sm font-medium text-gray-700 mb-2">Observaciones</label>
                            <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="observaciones_vehiculo" name="observaciones" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('addEditVehicleModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors" id="submitVehicleBtn"></button>
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
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteVehicleId">
                    <div class="p-6">
                        <p class="text-gray-700">¿Estás seguro de que quieres eliminar el vehículo con placas <strong id="deleteVehiclePlacas"></strong>?</p>
                        <p class="text-sm text-red-600 mt-2">Esta acción es irreversible.</p>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('deleteVehicleModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white bg-red-600 rounded-md hover:bg-red-700 transition-colors">Eliminar</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <script src="js/main.js"></script>
    <script src="js/table-filters.js"></script>
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
                        setupVehicleModal(action, this);
                    } else if (modalId === 'deleteVehicleModal') {
                        setupDeleteVehicleModal(this);
                    }

                    openModal(modalId);
                });
            });

            function setupVehicleModal(action, button) {
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
                    submitBtn.className = 'px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors';
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
</body>

</html>