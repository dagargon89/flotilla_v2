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

        <button type="button" class="bg-cambridge2 text-darkpurple px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition mb-4" data-bs-toggle="modal" data-bs-target="#addEditUserModal" data-action="add">
            <i class="bi bi-plus-circle"></i> Agregar Nuevo Usuario (Admin)
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
                                                <button type="button" class="bg-green-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-green-600 transition" data-bs-toggle="modal" data-bs-target="#approveRejectUserModal"
                                                    data-user-id="<?php echo $usuario['id']; ?>" data-action="approve_account" data-user-name="<?php echo htmlspecialchars($usuario['nombre']); ?>">
                                                    Aprobar
                                                </button>
                                                <button type="button" class="bg-red-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-600 transition" data-bs-toggle="modal" data-bs-target="#approveRejectUserModal"
                                                    data-user-id="<?php echo $usuario['id']; ?>" data-action="reject_account" data-user-name="<?php echo htmlspecialchars($usuario['nombre']); ?>">
                                                    Rechazar
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="bg-yellow-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-yellow-600 transition" data-bs-toggle="modal" data-bs-target="#addAmonestacionModal"
                                                data-user-id="<?php echo $usuario['id']; ?>" data-user-name="<?php echo htmlspecialchars($usuario['nombre']); ?>">
                                                Amonestar
                                            </button>
                                            <button type="button" class="bg-gray-500 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-gray-600 transition" data-bs-toggle="modal" data-bs-target="#viewUserHistoryModal"
                                                data-user-id="<?php echo $usuario['id']; ?>" data-user-name="<?php echo htmlspecialchars($usuario['nombre']); ?>">
                                                Historial Amon.
                                            </button>
                                            <button type="button" class="bg-cambridge1 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-cambridge2 transition" data-bs-toggle="modal" data-bs-target="#addEditUserModal" data-action="edit"
                                                data-id="<?php echo $usuario['id']; ?>"
                                                data-nombre="<?php echo htmlspecialchars($usuario['nombre']); ?>"
                                                data-correo="<?php echo htmlspecialchars($usuario['correo_electronico']); ?>"
                                                data-rol="<?php echo htmlspecialchars($usuario['rol']); ?>"
                                                data-estatus-cuenta="<?php echo htmlspecialchars($usuario['estatus_cuenta']); ?>"
                                                data-estatus-usuario="<?php echo htmlspecialchars($usuario['estatus_usuario']); ?>">
                                                Editar
                                            </button>
                                            <?php if ($usuario['id'] !== $user_id_sesion): ?>
                                                <button type="button" class="bg-red-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-700 transition" data-bs-toggle="modal" data-bs-target="#deleteUserModal" data-id="<?php echo $usuario['id']; ?>" data-nombre="<?php echo htmlspecialchars($usuario['nombre']); ?>">
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

        <div class="modal fade" id="addEditUserModal" tabindex="-1" aria-labelledby="addEditUserModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addEditUserModalLabel"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_usuarios.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="action" id="modalActionUser">
                            <input type="hidden" name="id" id="userId">

                            <div class="mb-3">
                                <label for="nombre" class="form-label">Nombre Completo</label>
                                <input type="text" class="form-control" id="nombre" name="nombre" required>
                            </div>
                            <div class="mb-3">
                                <label for="correo_electronico" class="form-label">Correo Electrónico</label>
                                <input type="email" class="form-control" id="correo_electronico" name="correo_electronico" required>
                            </div>
                            <div class="mb-3" id="passwordField">
                                <label for="password" class="form-label">Contraseña</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                                <small class="form-text text-muted" id="passwordHelp"></small>
                            </div>
                            <div class="mb-3">
                                <label for="rol" class="form-label">Rol</label>
                                <select class="form-select" id="rol" name="rol" required>
                                    <option value="empleado">Empleado</option>
                                    <option value="flotilla_manager">Manager de Flotilla</option>
                                    <?php if ($rol_usuario_sesion === 'admin'): ?>
                                        <option value="admin">Administrador</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="mb-3" id="estatusCuentaField">
                                <label for="estatus_cuenta" class="form-label">Estatus de Cuenta</label>
                                <select class="form-select" id="estatus_cuenta" name="estatus_cuenta" required>
                                    <option value="pendiente_aprobacion">Pendiente de Aprobación</option>
                                    <option value="activa">Activa</option>
                                    <option value="rechazada">Rechazada</option>
                                    <option value="inactiva">Inactiva</option>
                                </select>
                            </div>
                            <div class="mb-3" id="estatusUsuarioField">
                                <label for="estatus_usuario" class="form-label">Estatus de Uso (Vehículos)</label>
                                <select class="form-select" id="estatus_usuario" name="estatus_usuario" required>
                                    <option value="activo">Activo</option>
                                    <option value="amonestado">Amonestado</option>
                                    <option value="suspendido">Suspendido</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" id="submitUserBtn"></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteUserModalLabel">Confirmar Eliminación</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_usuarios.php" method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteUserId">
                        <div class="modal-body">
                            ¿Estás seguro de que quieres eliminar al usuario <strong id="deleteUserName"></strong>?
                            Esta acción es irreversible y eliminará también sus solicitudes asociadas.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-danger">Eliminar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="approveRejectUserModal" tabindex="-1" aria-labelledby="approveRejectUserModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="approveRejectUserModalLabel">Gestionar Solicitud de Cuenta</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_usuarios.php" method="POST">
                        <input type="hidden" name="user_id_action" id="modalUserActionId">
                        <input type="hidden" name="action" id="modalUserActionType">
                        <div class="modal-body">
                            Estás a punto de <strong id="modalUserActionText"></strong> la solicitud de cuenta para <strong id="modalUserActionName"></strong>.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn" id="modalUserSubmitBtn"></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="addAmonestacionModal" tabindex="-1" aria-labelledby="addAmonestacionModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addAmonestacionModalLabel">Registrar Amonestación para <span id="amonestacionUserName"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_usuarios.php" method="POST">
                        <input type="hidden" name="action" value="add_amonestacion">
                        <input type="hidden" name="amonestacion_user_id" id="amonestacionUserId">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="tipo_amonestacion" class="form-label">Tipo de Amonestación</label>
                                <select class="form-select" id="tipo_amonestacion" name="tipo_amonestacion" required>
                                    <option value="">Selecciona...</option>
                                    <option value="leve">Leve</option>
                                    <option value="grave">Grave</option>
                                    <option value="suspension">Suspensión (Automáticamente suspende al usuario)</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="descripcion_amonestacion" class="form-label">Descripción del Incidente</label>
                                <textarea class="form-control" id="descripcion_amonestacion" name="descripcion_amonestacion" rows="3" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Registrar Amonestación</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="viewUserHistoryModal" tabindex="-1" aria-labelledby="viewUserHistoryModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="viewUserHistoryModalLabel">Historial de Amonestaciones de <span id="historyUserName"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="amonestacionesHistoryTable" class="table-responsive">
                            <p class="text-center text-muted">Cargando historial...</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
    <script src="js/main.js"></script>
    <script>
        // JavaScript para manejar los modales de agregar/editar usuario
        document.addEventListener('DOMContentLoaded', function() {
            var addEditUserModal = document.getElementById('addEditUserModal');
            addEditUserModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var action = button.getAttribute('data-action');

                var modalTitle = addEditUserModal.querySelector('#addEditUserModalLabel');
                var modalActionInput = addEditUserModal.querySelector('#modalActionUser');
                var userIdInput = addEditUserModal.querySelector('#userId');
                var submitBtn = addEditUserModal.querySelector('#submitUserBtn');
                var passwordField = addEditUserModal.querySelector('#passwordField');
                var passwordInput = addEditUserModal.querySelector('#password');
                var passwordHelp = addEditUserModal.querySelector('#passwordHelp');
                var estatusCuentaField = addEditUserModal.querySelector('#estatusCuentaField');
                var estatusCuentaSelect = addEditUserModal.querySelector('#estatus_cuenta');
                var estatusUsuarioField = addEditUserModal.querySelector('#estatusUsuarioField');
                var estatusUsuarioSelect = addEditUserModal.querySelector('#estatus_usuario');
                var form = addEditUserModal.querySelector('form');

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
                    submitBtn.className = 'btn btn-primary';
                    userIdInput.value = '';
                    passwordHelp.textContent = '';
                } else if (action === 'edit') {
                    modalTitle.textContent = 'Editar Usuario';
                    modalActionInput.value = 'edit';
                    submitBtn.textContent = 'Actualizar Usuario';
                    submitBtn.className = 'btn btn-info text-white';

                    passwordInput.removeAttribute('required');
                    passwordInput.name = 'new_password';
                    passwordHelp.textContent = 'Deja este campo vacío para mantener la contraseña actual.';
                    estatusCuentaField.style.display = 'block';
                    estatusUsuarioField.style.display = 'block';

                    userIdInput.value = button.getAttribute('data-id');
                    addEditUserModal.querySelector('#nombre').value = button.getAttribute('data-nombre');
                    addEditUserModal.querySelector('#correo_electronico').value = button.getAttribute('data-correo');
                    addEditUserModal.querySelector('#rol').value = button.getAttribute('data-rol');
                    estatusCuentaSelect.value = button.getAttribute('data-estatus-cuenta');
                    estatusUsuarioSelect.value = button.getAttribute('data-estatus-usuario');
                }
            });

            // JavaScript para manejar el modal de eliminar usuario
            var deleteUserModal = document.getElementById('deleteUserModal');
            deleteUserModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var userId = button.getAttribute('data-id');
                var userName = button.getAttribute('data-nombre');

                var modalUserId = deleteUserModal.querySelector('#deleteUserId');
                var modalUserName = deleteUserModal.querySelector('#deleteUserName');

                modalUserId.value = userId;
                modalUserName.textContent = userName;
            });

            // JavaScript para manejar el modal de Aprobar/Rechazar Usuario (Solicitud de Cuenta)
            var approveRejectUserModal = document.getElementById('approveRejectUserModal');
            approveRejectUserModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var userId = button.getAttribute('data-user-id');
                var action = button.getAttribute('data-action');
                var userName = button.getAttribute('data-user-name');

                var modalUserActionId = approveRejectUserModal.querySelector('#modalUserActionId');
                var modalUserActionType = approveRejectUserModal.querySelector('#modalUserActionType');
                var modalUserActionText = approveRejectUserModal.querySelector('#modalUserActionText');
                var modalUserActionName = approveRejectUserModal.querySelector('#modalUserActionName');
                var modalUserSubmitBtn = approveRejectUserModal.querySelector('#modalUserSubmitBtn');

                modalUserActionId.value = userId;
                modalUserActionType.value = action;
                modalUserActionName.textContent = userName;

                if (action === 'approve_account') {
                    modalUserActionText.textContent = 'APROBAR';
                    modalUserSubmitBtn.textContent = 'Aprobar Cuenta';
                    modalUserSubmitBtn.className = 'btn btn-success';
                } else if (action === 'reject_account') {
                    modalUserActionText.textContent = 'RECHAZAR';
                    modalUserSubmitBtn.textContent = 'Rechazar Cuenta';
                    modalUserSubmitBtn.className = 'btn btn-danger';
                }
            });

            // JavaScript para manejar el modal de Añadir Amonestación
            var addAmonestacionModal = document.getElementById('addAmonestacionModal');
            addAmonestacionModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var userId = button.getAttribute('data-user-id');
                var userName = button.getAttribute('data-user-name');

                addAmonestacionModal.querySelector('#amonestacionUserName').textContent = userName;
                addAmonestacionModal.querySelector('#amonestacionUserId').value = userId;
                addAmonestacionModal.querySelector('form').reset();
            });

            // JavaScript para manejar el modal de Ver Historial de Amonestaciones
            var viewUserHistoryModal = document.getElementById('viewUserHistoryModal');
            viewUserHistoryModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var userId = button.getAttribute('data-user-id');
                var userName = button.getAttribute('data-user-name');

                viewUserHistoryModal.querySelector('#historyUserName').textContent = userName;
                var historyTableContainer = viewUserHistoryModal.querySelector('#amonestacionesHistoryTable');
                historyTableContainer.innerHTML = '<p class="text-center text-muted">Cargando historial...</p>';

                fetch('api/get_amonestaciones_history.php?user_id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            historyTableContainer.innerHTML = '<div class="alert alert-danger text-center">Error al cargar historial: ' + data.error + '</div>';
                            return;
                        }

                        if (data.history.length === 0) {
                            historyTableContainer.innerHTML = '<div class="alert alert-info text-center">No hay amonestaciones registradas para este usuario.</div>';
                        } else {
                            let tableHtml = `
                                <table class="table table-striped table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Tipo</th>
                                            <th>Descripción</th>
                                            <th>Amonestado Por</th>
                                            <th>Evidencia</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                            `;
                            data.history.forEach(item => {
                                const amonestadoPor = item.amonestado_por_nombre || 'N/A';
                                const evidenciaLink = item.evidencia_url ? `<a href="${item.evidencia_url}" target="_blank">Ver Evidencia</a>` : 'N/A';
                                let tipoClass = '';
                                switch (item.tipo_amonestacion) {
                                    case 'leve':
                                        tipoClass = 'badge bg-primary';
                                        break;
                                    case 'grave':
                                        tipoClass = 'badge bg-warning text-dark';
                                        break;
                                    case 'suspension':
                                        tipoClass = 'badge bg-danger';
                                        break;
                                }

                                tableHtml += `
                                    <tr>
                                        <td>${new Date(item.fecha_amonestacion).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' })}</td>
                                        <td><span class="${tipoClass}">${item.tipo_amonestacion.charAt(0).toUpperCase() + item.tipo_amonestacion.slice(1)}</span></td>
                                        <td>${item.descripcion}</td>
                                        <td>${amonestadoPor}</td>
                                        <td>${evidenciaLink}</td>
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
                        historyTableContainer.innerHTML = '<div class="alert alert-danger text-center">No se pudo cargar el historial de amonestaciones.</div>';
                    });
            });
        });
    </script>
</body>

</html>