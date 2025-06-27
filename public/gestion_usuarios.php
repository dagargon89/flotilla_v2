<?php
// public/gestion_usuarios.php - CÓDIGO COMPLETO Y CORREGIDO (Error Undefined $db y Lógica de Estatus/Amonestaciones)
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
// Solo 'admin' puede acceder a esta página.
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php'); // Redirige al dashboard si no tiene permisos
    exit();
}

$nombre_usuario_sesion = $_SESSION['user_name'];
$rol_usuario_sesion = $_SESSION['user_role'];
$user_id_sesion = $_SESSION['user_id'];

$success_message = '';
$error_message = $error_message ?? ''; // Mantener el error si ya viene del bloque de amonestaciones

$usuarios = []; // Para guardar la lista de usuarios
$historial_amonestaciones_modal = []; // Para el historial en el modal de detalle

// --- Lógica para procesar el formulario (Agregar/Editar/Eliminar/Aprobar/Rechazar/Amonestar Usuario) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; // 'add', 'edit', 'delete', 'approve_account', 'reject_account', 'add_amonestacion'

    try {
        if ($action === 'add') {
            $nombre = trim($_POST['nombre'] ?? '');
            $correo_electronico = trim($_POST['correo_electronico'] ?? '');
            $password = $_POST['password'] ?? '';
            $rol = $_POST['rol'] ?? 'empleado';

            if (empty($nombre) || empty($correo_electronico) || empty($password)) {
                throw new Exception("Por favor, completa todos los campos obligatorios para agregar un usuario.");
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("INSERT INTO usuarios (nombre, correo_electronico, password, rol, estatus_cuenta, estatus_usuario) VALUES (:nombre, :correo_electronico, :password, :rol, 'activa', 'activo')");
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':correo_electronico', $correo_electronico);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':rol', $rol);
            $stmt->execute();
            $success_message = 'Usuario agregado con éxito (activado directamente).';
        } elseif ($action === 'edit') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            $nombre = trim($_POST['nombre'] ?? '');
            $correo_electronico = trim($_POST['correo_electronico'] ?? '');
            $rol = $_POST['rol'] ?? 'empleado';
            $estatus_cuenta = $_POST['estatus_cuenta'] ?? 'pendiente_aprobacion';
            $estatus_usuario = $_POST['estatus_usuario'] ?? 'activo';
            $new_password = $_POST['new_password'] ?? '';

            if ($id === false || empty($nombre) || empty($correo_electronico) || empty($rol) || empty($estatus_cuenta) || empty($estatus_usuario)) {
                throw new Exception("Por favor, completa todos los campos obligatorios para editar el usuario.");
            }

            $sql = "UPDATE usuarios SET nombre = :nombre, correo_electronico = :correo_electronico, rol = :rol, estatus_cuenta = :estatus_cuenta, estatus_usuario = :estatus_usuario";
            if (!empty($new_password)) {
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                $sql .= ", password = :password";
            }
            $sql .= " WHERE id = :id";

            $stmt = $db->prepare($sql);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':correo_electronico', $correo_electronico);
            $stmt->bindParam(':rol', $rol);
            $stmt->bindParam(':estatus_cuenta', $estatus_cuenta);
            $stmt->bindParam(':estatus_usuario', $estatus_usuario);
            if (!empty($new_password)) {
                $stmt->bindParam(':password', $hashed_new_password);
            }
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            $success_message = 'Usuario actualizado con éxito.';
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

            if ($id === false) {
                throw new Exception("ID de usuario inválido para eliminar.");
            }

            if ($id == $user_id_sesion) {
                throw new Exception("No puedes eliminar tu propia cuenta de administrador.");
            }

            $stmt = $db->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $success_message = 'Usuario eliminado con éxito.';
        } elseif ($action === 'approve_account' || $action === 'reject_account') {
            $id = filter_var($_POST['user_id_action'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false) {
                throw new Exception("ID de usuario inválido para aprobar/rechazar.");
            }

            if ($id == $user_id_sesion) {
                throw new Exception("No puedes aprobar/rechazar tu propia cuenta.");
            }

            $new_estatus = ($action === 'approve_account') ? 'activa' : 'rechazada';

            $stmt = $db->prepare("UPDATE usuarios SET estatus_cuenta = :estatus_cuenta WHERE id = :id AND estatus_cuenta = 'pendiente_aprobacion'");
            $stmt->bindParam(':estatus_cuenta', $new_estatus);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $success_message = 'Cuenta de usuario ' . ($action === 'approve_account' ? 'aprobada' : 'rechazada') . ' con éxito.';
            } else {
                $error_message = 'No se pudo actualizar el estatus de la cuenta. Puede que ya haya sido procesada.';
            }
        } elseif ($action === 'add_amonestacion') {
            $usuario_id = filter_var($_POST['amonestacion_user_id'] ?? null, FILTER_VALIDATE_INT);
            $tipo_amonestacion = trim($_POST['tipo_amonestacion'] ?? '');
            $descripcion_amonestacion = trim($_POST['descripcion_amonestacion'] ?? '');

            if ($usuario_id === false || empty($tipo_amonestacion) || empty($descripcion_amonestacion)) {
                throw new Exception("Completa todos los campos para la amonestación.");
            }

            $db->beginTransaction();

            $stmt_amonestacion = $db->prepare("INSERT INTO amonestaciones (usuario_id, tipo_amonestacion, descripcion, amonestado_por) VALUES (:usuario_id, :tipo_amonestacion, :descripcion, :amonestado_por)");
            $stmt_amonestacion->bindParam(':usuario_id', $usuario_id);
            $stmt_amonestacion->bindParam(':tipo_amonestacion', $tipo_amonestacion);
            $stmt_amonestacion->bindParam(':descripcion', $descripcion_amonestacion);
            $stmt_amonestacion->bindParam(':amonestado_por', $user_id_sesion);
            $stmt_amonestacion->execute();

            if ($tipo_amonestacion === 'suspension') {
                $stmt_update_user_status = $db->prepare("UPDATE usuarios SET estatus_usuario = 'suspendido' WHERE id = :usuario_id");
                $stmt_update_user_status->bindParam(':usuario_id', $usuario_id);
                $stmt_update_user_status->execute();
            } elseif ($tipo_amonestacion === 'amonestado') { // Si quieres cambiar a 'amonestado' por una amonestación (no suspension)
                // Solo si el estatus actual es 'activo', para no sobrescribir 'suspendido' si ya lo está
                $stmt_update_user_status = $db->prepare("UPDATE usuarios SET estatus_usuario = 'amonestado' WHERE id = :usuario_id AND estatus_usuario = 'activo'");
                $stmt_update_user_status->bindParam(':usuario_id', $usuario_id);
                $stmt_update_user_status->execute();
            }

            $db->commit();
            $success_message = 'Amonestación registrada con éxito.';
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'Error: ' . $e->getMessage();
        if (strpos($e->getMessage(), 'Duplicate entry') !== false && strpos($e->getMessage(), 'correo_electronico') !== false) {
            $error_message = 'Error: El correo electrónico ya está registrado. Por favor, usa otro.';
        }
        error_log("Error en gestión de usuarios: " . $e->getMessage());
    }
}

// --- Obtener todos los usuarios para mostrar en la tabla ---
$requested_user_id_for_history = filter_var($_GET['view_history_id'] ?? null, FILTER_VALIDATE_INT);
$historial_amonestaciones_modal = [];

if ($db) {
    try {
        $stmt = $db->query("SELECT id, nombre, correo_electronico, rol, estatus_cuenta, estatus_usuario, fecha_creacion, ultima_sesion FROM usuarios ORDER BY nombre ASC");
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($requested_user_id_for_history) {
            $stmt_history = $db->prepare("
                SELECT a.id, a.fecha_amonestacion, a.tipo_amonestacion, a.descripcion, a.evidencia_url,
                       u.nombre AS amonestado_por_nombre
                FROM amonestaciones a
                LEFT JOIN usuarios u ON a.amonestado_por = u.id
                WHERE a.usuario_id = :user_id
                ORDER BY a.fecha_amonestacion DESC
            ");
            $stmt_history->bindParam(':user_id', $requested_user_id_for_history);
            $stmt_history->execute();
            $historial_amonestaciones_modal = $stmt_history->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error al cargar usuarios o historial de amonestaciones: " . $e->getMessage());
        $error_message = 'No se pudieron cargar los datos de usuarios o su historial.';
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Flotilla Interna</title>
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
        <h1 class="text-3xl font-bold text-darkpurple mb-6">Gestión de Usuarios</h1>

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

        <button type="button" class="bg-cambridge2 text-darkpurple px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition mb-4" data-modal-target="addEditUserModal" data-action="add">
            <i class="bi bi-person-plus"></i> Agregar Nuevo Usuario
        </button>
        <p class="text-sm text-mountbatten mb-6">Para solicitudes de cuenta, ve a la tabla de abajo y busca el estatus "Pendiente de Aprobación".</p>

        <?php if (empty($usuarios)): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded" role="alert">
                No hay usuarios registrados en el sistema.
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-cambridge2">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-cambridge1 text-white">
                                <th class="px-4 py-3 text-left">ID</th>
                                <th class="px-4 py-3 text-left">Nombre</th>
                                <th class="px-4 py-3 text-left">Correo Electrónico</th>
                                <th class="px-4 py-3 text-left">Rol</th>
                                <th class="px-4 py-3 text-left">Estatus Cuenta</th>
                                <th class="px-4 py-3 text-left">Estatus Uso</th>
                                <th class="px-4 py-3 text-left">Última Sesión</th>
                                <th class="px-4 py-3 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $usuario): ?>
                                <tr class="border-b border-cambridge2 hover:bg-parchment">
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($usuario['id']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($usuario['nombre']); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($usuario['correo_electronico']); ?></td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $rol_class = '';
                                        switch ($usuario['rol']) {
                                            case 'admin':
                                                $rol_class = 'bg-red-500 text-white';
                                                break;
                                            case 'flotilla_manager':
                                                $rol_class = 'bg-yellow-500 text-white';
                                                break;
                                            case 'empleado':
                                                $rol_class = 'bg-cambridge1 text-white';
                                                break;
                                        }
                                        ?>
                                        <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?php echo $rol_class; ?>"><?php echo htmlspecialchars(ucfirst($usuario['rol'])); ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $estatus_cuenta_class = '';
                                        switch ($usuario['estatus_cuenta']) {
                                            case 'pendiente_aprobacion':
                                                $estatus_cuenta_class = 'bg-blue-500 text-white';
                                                break;
                                            case 'activa':
                                                $estatus_cuenta_class = 'bg-green-500 text-white';
                                                break;
                                            case 'rechazada':
                                                $estatus_cuenta_class = 'bg-red-500 text-white';
                                                break;
                                            case 'inactiva':
                                                $estatus_cuenta_class = 'bg-gray-500 text-white';
                                                break;
                                        }
                                        ?>
                                        <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?php echo $estatus_cuenta_class; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $usuario['estatus_cuenta']))); ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $estatus_usuario_class = '';
                                        switch ($usuario['estatus_usuario']) {
                                            case 'activo':
                                                $estatus_usuario_class = 'bg-green-500 text-white';
                                                break;
                                            case 'amonestado':
                                                $estatus_usuario_class = 'bg-yellow-500 text-white';
                                                break;
                                            case 'suspendido':
                                                $estatus_usuario_class = 'bg-red-500 text-white';
                                                break;
                                        }
                                        ?>
                                        <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?php echo $estatus_usuario_class; ?>"><?php echo htmlspecialchars(ucfirst($usuario['estatus_usuario'])); ?></span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-mountbatten"><?php echo $usuario['ultima_sesion'] ? date('d/m/Y H:i', strtotime($usuario['ultima_sesion'])) : 'Nunca'; ?></td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <?php if ($usuario['estatus_cuenta'] === 'pendiente_aprobacion'): ?>
                                                <button type="button" class="bg-green-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-green-600 transition" data-modal-target="approveRejectUserModal"
                                                    data-user-id="<?php echo $usuario['id']; ?>"
                                                    data-user-name="<?php echo htmlspecialchars($usuario['nombre']); ?>"
                                                    data-action-type="approve"
                                                    data-action-text="aprobar">
                                                    Aprobar
                                                </button>
                                                <button type="button" class="bg-red-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-600 transition" data-modal-target="approveRejectUserModal"
                                                    data-user-id="<?php echo $usuario['id']; ?>"
                                                    data-user-name="<?php echo htmlspecialchars($usuario['nombre']); ?>"
                                                    data-action-type="reject"
                                                    data-action-text="rechazar">
                                                    Rechazar
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="bg-yellow-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-yellow-600 transition" data-modal-target="addAmonestacionModal"
                                                data-user-id="<?php echo $usuario['id']; ?>"
                                                data-user-name="<?php echo htmlspecialchars($usuario['nombre']); ?>">
                                                Amonestar
                                            </button>
                                            <button type="button" class="bg-gray-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-gray-600 transition" data-modal-target="viewUserHistoryModal"
                                                data-user-id="<?php echo $usuario['id']; ?>"
                                                data-user-name="<?php echo htmlspecialchars($usuario['nombre']); ?>">
                                                Historial
                                            </button>
                                            <button type="button" class="bg-cambridge1 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-cambridge2 transition" data-modal-target="addEditUserModal" data-action="edit"
                                                data-id="<?php echo $usuario['id']; ?>"
                                                data-nombre="<?php echo htmlspecialchars($usuario['nombre']); ?>"
                                                data-correo="<?php echo htmlspecialchars($usuario['correo_electronico']); ?>"
                                                data-rol="<?php echo htmlspecialchars($usuario['rol']); ?>"
                                                data-estatus-cuenta="<?php echo htmlspecialchars($usuario['estatus_cuenta']); ?>"
                                                data-estatus-usuario="<?php echo htmlspecialchars($usuario['estatus_usuario']); ?>">
                                                Editar
                                            </button>
                                            <?php if ($usuario['id'] !== $user_id_sesion): ?>
                                                <button type="button" class="bg-red-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-700 transition" data-modal-target="deleteUserModal" data-id="<?php echo $usuario['id']; ?>" data-nombre="<?php echo htmlspecialchars($usuario['nombre']); ?>">
                                                    Eliminar
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal para Agregar/Editar Usuario -->
        <div id="addEditUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="addEditUserModalLabel"></h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('addEditUserModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form action="gestion_usuarios.php" method="POST">
                    <div class="p-6 space-y-4">
                        <input type="hidden" name="action" id="modalActionUser">
                        <input type="hidden" name="id" id="userId">

                        <div>
                            <label for="nombre" class="block text-sm font-medium text-gray-700 mb-2">Nombre Completo</label>
                            <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="nombre" name="nombre" required>
                        </div>
                        <div>
                            <label for="correo_electronico" class="block text-sm font-medium text-gray-700 mb-2">Correo Electrónico</label>
                            <input type="email" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="correo_electronico" name="correo_electronico" required>
                        </div>
                        <div id="passwordField">
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Contraseña</label>
                            <input type="password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="password" name="password" required>
                            <small class="text-sm text-gray-500" id="passwordHelp"></small>
                        </div>
                        <div>
                            <label for="rol" class="block text-sm font-medium text-gray-700 mb-2">Rol</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="rol" name="rol" required>
                                <option value="empleado">Empleado</option>
                                <option value="flotilla_manager">Manager de Flotilla</option>
                                <?php if ($rol_usuario_sesion === 'admin'): ?>
                                    <option value="admin">Administrador</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div id="estatusCuentaField">
                            <label for="estatus_cuenta" class="block text-sm font-medium text-gray-700 mb-2">Estatus de Cuenta</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="estatus_cuenta" name="estatus_cuenta" required>
                                <option value="pendiente_aprobacion">Pendiente de Aprobación</option>
                                <option value="activa">Activa</option>
                                <option value="rechazada">Rechazada</option>
                                <option value="inactiva">Inactiva</option>
                            </select>
                        </div>
                        <div id="estatusUsuarioField">
                            <label for="estatus_usuario" class="block text-sm font-medium text-gray-700 mb-2">Estatus de Uso (Vehículos)</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="estatus_usuario" name="estatus_usuario" required>
                                <option value="activo">Activo</option>
                                <option value="amonestado">Amonestado</option>
                                <option value="suspendido">Suspendido</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('addEditUserModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors" id="submitUserBtn"></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para Eliminar Usuario -->
        <div id="deleteUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="deleteUserModalLabel">Confirmar Eliminación</h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('deleteUserModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form action="gestion_usuarios.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteUserId">
                    <div class="p-6">
                        <p class="text-gray-700">¿Estás seguro de que quieres eliminar al usuario <strong id="deleteUserName"></strong>?</p>
                        <p class="text-sm text-red-600 mt-2">Esta acción es irreversible y eliminará también sus solicitudes asociadas.</p>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('deleteUserModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white bg-red-600 rounded-md hover:bg-red-700 transition-colors">Eliminar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para Aprobar/Rechazar Usuario -->
        <div id="approveRejectUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="approveRejectUserModalLabel">Gestionar Solicitud de Cuenta</h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('approveRejectUserModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form action="gestion_usuarios.php" method="POST">
                    <input type="hidden" name="user_id_action" id="modalUserActionId">
                    <input type="hidden" name="action" id="modalUserActionType">
                    <div class="p-6">
                        <p class="text-gray-700">Estás a punto de <strong id="modalUserActionText"></strong> la solicitud de cuenta para <strong id="modalUserActionName"></strong>.</p>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('approveRejectUserModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white rounded-md transition-colors" id="modalUserSubmitBtn"></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para Agregar Amonestación -->
        <div id="addAmonestacionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="addAmonestacionModalLabel">Registrar Amonestación para <span id="amonestacionUserName"></span></h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('addAmonestacionModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form action="gestion_usuarios.php" method="POST">
                    <input type="hidden" name="action" value="add_amonestacion">
                    <input type="hidden" name="amonestacion_user_id" id="amonestacionUserId">
                    <div class="p-6 space-y-4">
                        <div>
                            <label for="tipo_amonestacion" class="block text-sm font-medium text-gray-700 mb-2">Tipo de Amonestación</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="tipo_amonestacion" name="tipo_amonestacion" required>
                                <option value="">Selecciona...</option>
                                <option value="leve">Leve</option>
                                <option value="grave">Grave</option>
                                <option value="suspension">Suspensión (Automáticamente suspende al usuario)</option>
                            </select>
                        </div>
                        <div>
                            <label for="descripcion_amonestacion" class="block text-sm font-medium text-gray-700 mb-2">Descripción del Incidente</label>
                            <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-cambridge1 focus:border-cambridge1" id="descripcion_amonestacion" name="descripcion_amonestacion" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 p-6 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('addAmonestacionModal')">Cancelar</button>
                        <button type="submit" class="px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors">Registrar Amonestación</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para Ver Historial de Usuario -->
        <div id="viewUserHistoryModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center p-6 border-b border-gray-200">
                    <h5 class="text-lg font-semibold text-gray-900" id="viewUserHistoryModalLabel">Historial de Amonestaciones de <span id="historyUserName"></span></h5>
                    <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" onclick="closeModal('viewUserHistoryModal')">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="p-6">
                    <div id="amonestacionesHistoryTable" class="overflow-x-auto">
                        <p class="text-center text-gray-500">Cargando historial...</p>
                    </div>
                </div>
                <div class="flex justify-end p-6 border-t border-gray-200">
                    <button type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeModal('viewUserHistoryModal')">Cerrar</button>
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

        // JavaScript para manejar los modales de agregar/editar usuario
        document.addEventListener('DOMContentLoaded', function() {
            // Configurar botones para abrir modales
            document.querySelectorAll('[data-modal-target]').forEach(button => {
                button.addEventListener('click', function() {
                    const modalId = this.getAttribute('data-modal-target');
                    const action = this.getAttribute('data-action');
                    const actionType = this.getAttribute('data-action-type');
                    const actionText = this.getAttribute('data-action-text');
                    
                    if (modalId === 'addEditUserModal') {
                        setupUserModal(action, this);
                    } else if (modalId === 'deleteUserModal') {
                        setupDeleteUserModal(this);
                    } else if (modalId === 'approveRejectUserModal') {
                        setupApproveRejectUserModal(this, actionType, actionText);
                    } else if (modalId === 'addAmonestacionModal') {
                        setupAmonestacionModal(this);
                    } else if (modalId === 'viewUserHistoryModal') {
                        setupViewUserHistoryModal(this);
                    }
                    
                    openModal(modalId);
                });
            });

            function setupUserModal(action, button) {
                var modalTitle = document.getElementById('addEditUserModalLabel');
                var modalActionInput = document.getElementById('modalActionUser');
                var userIdInput = document.getElementById('userId');
                var submitBtn = document.getElementById('submitUserBtn');
                var passwordField = document.getElementById('passwordField');
                var passwordInput = document.getElementById('password');
                var passwordHelp = document.getElementById('passwordHelp');
                var estatusCuentaField = document.getElementById('estatusCuentaField');
                var estatusCuentaSelect = document.getElementById('estatus_cuenta');
                var estatusUsuarioField = document.getElementById('estatusUsuarioField');
                var estatusUsuarioSelect = document.getElementById('estatus_usuario');
                var form = document.querySelector('#addEditUserModal form');

                form.reset();
                passwordField.style.display = 'block';
                passwordInput.setAttribute('required', 'required');
                passwordInput.name = 'password';
                estatusCuentaField.style.display = 'none';
                estatusUsuarioField.style.display = 'none';

                if (action === 'add') {
                    modalTitle.textContent = 'Agregar Nuevo Usuario';
                    modalActionInput.value = 'add';
                    submitBtn.textContent = 'Guardar Usuario';
                    submitBtn.className = 'px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors';
                    userIdInput.value = '';
                    passwordHelp.textContent = '';
                } else if (action === 'edit') {
                    modalTitle.textContent = 'Editar Usuario';
                    modalActionInput.value = 'edit';
                    submitBtn.textContent = 'Actualizar Usuario';
                    submitBtn.className = 'px-4 py-2 text-white bg-cambridge1 rounded-md hover:bg-cambridge2 transition-colors';

                    passwordInput.removeAttribute('required');
                    passwordInput.name = 'new_password';
                    passwordHelp.textContent = 'Deja este campo vacío para mantener la contraseña actual.';
                    estatusCuentaField.style.display = 'block';
                    estatusUsuarioField.style.display = 'block';

                    userIdInput.value = button.getAttribute('data-id');
                    document.getElementById('nombre').value = button.getAttribute('data-nombre');
                    document.getElementById('correo_electronico').value = button.getAttribute('data-correo');
                    document.getElementById('rol').value = button.getAttribute('data-rol');
                    estatusCuentaSelect.value = button.getAttribute('data-estatus-cuenta');
                    estatusUsuarioSelect.value = button.getAttribute('data-estatus-usuario');
                }
            }

            function setupDeleteUserModal(button) {
                var userId = button.getAttribute('data-id');
                var userName = button.getAttribute('data-nombre');

                document.getElementById('deleteUserId').value = userId;
                document.getElementById('deleteUserName').textContent = userName;
            }

            function setupApproveRejectUserModal(button, actionType, actionText) {
                var userId = button.getAttribute('data-user-id');
                var userName = button.getAttribute('data-user-name');

                document.getElementById('modalUserActionId').value = userId;
                document.getElementById('modalUserActionType').value = actionType === 'approve' ? 'approve_account' : 'reject_account';
                document.getElementById('modalUserActionName').textContent = userName;

                var modalUserActionText = document.getElementById('modalUserActionText');
                var modalUserSubmitBtn = document.getElementById('modalUserSubmitBtn');

                if (actionType === 'approve') {
                    modalUserActionText.textContent = 'APROBAR';
                    modalUserSubmitBtn.textContent = 'Aprobar Cuenta';
                    modalUserSubmitBtn.className = 'px-4 py-2 text-white bg-green-600 rounded-md hover:bg-green-700 transition-colors';
                } else if (actionType === 'reject') {
                    modalUserActionText.textContent = 'RECHAZAR';
                    modalUserSubmitBtn.textContent = 'Rechazar Cuenta';
                    modalUserSubmitBtn.className = 'px-4 py-2 text-white bg-red-600 rounded-md hover:bg-red-700 transition-colors';
                }
            }

            function setupAmonestacionModal(button) {
                var userId = button.getAttribute('data-user-id');
                var userName = button.getAttribute('data-user-name');

                document.getElementById('amonestacionUserName').textContent = userName;
                document.getElementById('amonestacionUserId').value = userId;
                document.querySelector('#addAmonestacionModal form').reset();
            }

            function setupViewUserHistoryModal(button) {
                var userId = button.getAttribute('data-user-id');
                var userName = button.getAttribute('data-user-name');

                document.getElementById('historyUserName').textContent = userName;
                var historyTableContainer = document.getElementById('amonestacionesHistoryTable');
                historyTableContainer.innerHTML = '<p class="text-center text-gray-500">Cargando historial...</p>';

                fetch('api/get_amonestaciones_history.php?user_id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            historyTableContainer.innerHTML = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-center">Error al cargar historial: ' + data.error + '</div>';
                            return;
                        }

                        if (data.history.length === 0) {
                            historyTableContainer.innerHTML = '<div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded text-center">No hay amonestaciones registradas para este usuario.</div>';
                        } else {
                            let tableHtml = `
                                <table class="min-w-full bg-white border border-gray-300 rounded-lg overflow-hidden">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descripción</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amonestado Por</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Evidencia</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                            `;
                            data.history.forEach(item => {
                                const amonestadoPor = item.amonestado_por_nombre || 'N/A';
                                const evidenciaLink = item.evidencia_url ? `<a href="${item.evidencia_url}" target="_blank" class="text-cambridge1 hover:text-cambridge2 underline">Ver Evidencia</a>` : 'N/A';
                                let tipoClass = '';
                                switch (item.tipo_amonestacion) {
                                    case 'leve':
                                        tipoClass = 'bg-blue-100 text-blue-800';
                                        break;
                                    case 'grave':
                                        tipoClass = 'bg-yellow-100 text-yellow-800';
                                        break;
                                    case 'suspension':
                                        tipoClass = 'bg-red-100 text-red-800';
                                        break;
                                }

                                tableHtml += `
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${new Date(item.fecha_amonestacion).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' })}</td>
                                        <td class="px-6 py-4 whitespace-nowrap"><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${tipoClass}">${item.tipo_amonestacion.charAt(0).toUpperCase() + item.tipo_amonestacion.slice(1)}</span></td>
                                        <td class="px-6 py-4 text-sm text-gray-900">${item.descripcion}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${amonestadoPor}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${evidenciaLink}</td>
                                    </tr>
                                `;
                            });
                            tableHtml += `
                                    </tbody>
                                </table>
                            `;
                            historyTableContainer.innerHTML = tableHtml;
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching amonestaciones history:', error);
                        historyTableContainer.innerHTML = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-center">No se pudo cargar el historial de amonestaciones.</div>';
                    });
            }
        });
    </script>
</body>

</html>