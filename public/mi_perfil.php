<?php
// public/mi_perfil.php - Administración de Perfil de Usuario
session_start();
require_once '../app/config/database.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$db = connectDB();
$user_id = $_SESSION['user_id'];
$nombre_usuario_sesion = $_SESSION['user_name'] ?? 'Usuario';
$rol_usuario_sesion = $_SESSION['user_role'] ?? 'empleado';

$success_message = '';
$error_message = '';

// Fetch current user's detailed status and amonestaciones for banner and logic
$current_user_estatus_usuario = $_SESSION['user_role'] ?? 'empleado';
$current_user_amonestaciones_count = 0;
$current_user_recent_amonestaciones_text = '';

if (isset($_SESSION['user_id']) && $db) {
    try {
        $stmt_user_full_status = $db->prepare("SELECT estatus_usuario FROM usuarios WHERE id = :user_id");
        $stmt_user_full_status->bindParam(':user_id', $_SESSION['user_id']);
        $stmt_user_full_status->execute();
        $user_full_status_result = $stmt_user_full_status->fetch(PDO::FETCH_ASSOC);
        if ($user_full_status_result) {
            $current_user_estatus_usuario = $user_full_status_result['estatus_usuario'];
            $_SESSION['user_estatus_usuario'] = $current_user_estatus_usuario;
        }

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

// Obtener información actual del usuario
$usuario_actual = null;
if ($db) {
    try {
        $stmt = $db->prepare("SELECT id, nombre, correo_electronico, rol, estatus_usuario FROM usuarios WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $usuario_actual = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al obtener información del usuario: " . $e->getMessage());
        $error_message = 'Error al cargar tu información.';
    }
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'cambiar_nombre') {
            $nuevo_nombre = trim($_POST['nuevo_nombre'] ?? '');

            if (empty($nuevo_nombre)) {
                $error_message = 'El nombre no puede estar vacío.';
            } elseif (strlen($nuevo_nombre) < 2) {
                $error_message = 'El nombre debe tener al menos 2 caracteres.';
            } elseif (strlen($nuevo_nombre) > 50) {
                $error_message = 'El nombre no puede tener más de 50 caracteres.';
            } else {
                try {
                    $stmt = $db->prepare("UPDATE usuarios SET nombre = :nombre WHERE id = :user_id");
                    $stmt->bindParam(':nombre', $nuevo_nombre);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();

                    if ($stmt->rowCount() > 0) {
                        $success_message = 'Nombre actualizado correctamente.';
                        $_SESSION['user_name'] = $nuevo_nombre;
                        $usuario_actual['nombre'] = $nuevo_nombre;
                    } else {
                        $error_message = 'No se pudo actualizar el nombre.';
                    }
                } catch (PDOException $e) {
                    error_log("Error al actualizar nombre: " . $e->getMessage());
                    $error_message = 'Error al actualizar el nombre.';
                }
            }
        } elseif ($action === 'cambiar_password') {
            $password_actual = $_POST['password_actual'] ?? '';
            $nueva_password = $_POST['nueva_password'] ?? '';
            $confirmar_password = $_POST['confirmar_password'] ?? '';

            if (empty($password_actual) || empty($nueva_password) || empty($confirmar_password)) {
                $error_message = 'Todos los campos de contraseña son obligatorios.';
            } elseif ($nueva_password !== $confirmar_password) {
                $error_message = 'Las contraseñas nuevas no coinciden.';
            } elseif (strlen($nueva_password) < 6) {
                $error_message = 'La nueva contraseña debe tener al menos 6 caracteres.';
            } else {
                try {
                    // Verificar contraseña actual
                    $stmt = $db->prepare("SELECT password FROM usuarios WHERE id = :user_id");
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$usuario || !password_verify($password_actual, $usuario['password'])) {
                        $error_message = 'La contraseña actual es incorrecta.';
                    } else {
                        // Actualizar contraseña
                        $password_hash = password_hash($nueva_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE usuarios SET password = :password WHERE id = :user_id");
                        $stmt->bindParam(':password', $password_hash);
                        $stmt->bindParam(':user_id', $user_id);
                        $stmt->execute();

                        if ($stmt->rowCount() > 0) {
                            $success_message = 'Contraseña actualizada correctamente.';
                        } else {
                            $error_message = 'No se pudo actualizar la contraseña.';
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Error al actualizar contraseña: " . $e->getMessage());
                    $error_message = 'Error al actualizar la contraseña.';
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Flotilla Interna</title>
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
    <?php require_once '../app/includes/alert_banner.php'; ?>

    <div class="container mx-auto px-4 py-6">
        <h1 class="text-3xl font-bold text-darkpurple mb-6">Mi Perfil</h1>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4" role="alert">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Información del Usuario -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h2 class="text-xl font-semibold text-darkpurple mb-4">Información Personal</h2>

                <?php if ($usuario_actual): ?>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                            <span class="font-medium text-darkpurple">Nombre:</span>
                            <span class="text-gray-700"><?php echo htmlspecialchars($usuario_actual['nombre']); ?></span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                            <span class="font-medium text-darkpurple">Correo:</span>
                            <span class="text-gray-700"><?php echo htmlspecialchars($usuario_actual['correo_electronico']); ?></span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                            <span class="font-medium text-darkpurple">Rol:</span>
                            <span class="text-gray-700"><?php echo htmlspecialchars(ucfirst($usuario_actual['rol'])); ?></span>
                        </div>
                        <div class="flex justify-between items-center py-2">
                            <span class="font-medium text-darkpurple">Estatus:</span>
                            <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full 
                                <?php echo ($usuario_actual['estatus_usuario'] === 'activo' ? 'bg-green-100 text-green-800' : ($usuario_actual['estatus_usuario'] === 'suspendido' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800')); ?>">
                                <?php echo htmlspecialchars(ucfirst($usuario_actual['estatus_usuario'])); ?>
                            </span>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500">No se pudo cargar la información del usuario.</p>
                <?php endif; ?>
            </div>

            <!-- Cambiar Nombre -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2">
                <h2 class="text-xl font-semibold text-darkpurple mb-4">Cambiar Nombre</h2>

                <form action="mi_perfil.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="cambiar_nombre">

                    <div>
                        <label for="nuevo_nombre" class="block text-sm font-medium text-darkpurple mb-1">Nuevo Nombre</label>
                        <input type="text"
                            class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition"
                            id="nuevo_nombre"
                            name="nuevo_nombre"
                            value="<?php echo htmlspecialchars($usuario_actual['nombre'] ?? ''); ?>"
                            required
                            minlength="2"
                            maxlength="50">
                    </div>

                    <button type="submit" class="w-full py-2 px-4 rounded-lg bg-darkpurple text-white font-semibold hover:bg-mountbatten transition">
                        Actualizar Nombre
                    </button>
                </form>
            </div>

            <!-- Cambiar Contraseña -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-cambridge2 lg:col-span-2">
                <h2 class="text-xl font-semibold text-darkpurple mb-4">Cambiar Contraseña</h2>

                <form action="mi_perfil.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="cambiar_password">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="password_actual" class="block text-sm font-medium text-darkpurple mb-1">Contraseña Actual</label>
                            <input type="password"
                                class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition"
                                id="password_actual"
                                name="password_actual"
                                required>
                        </div>

                        <div>
                            <label for="nueva_password" class="block text-sm font-medium text-darkpurple mb-1">Nueva Contraseña</label>
                            <input type="password"
                                class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition"
                                id="nueva_password"
                                name="nueva_password"
                                required
                                minlength="6">
                        </div>

                        <div>
                            <label for="confirmar_password" class="block text-sm font-medium text-darkpurple mb-1">Confirmar Nueva Contraseña</label>
                            <input type="password"
                                class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple bg-parchment outline-none transition"
                                id="confirmar_password"
                                name="confirmar_password"
                                required
                                minlength="6">
                        </div>
                    </div>

                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                        <p class="text-sm text-blue-700">
                            <strong>Requisitos de la contraseña:</strong>
                        <ul class="list-disc list-inside mt-1 space-y-1">
                            <li>Mínimo 6 caracteres</li>
                            <li>Se recomienda usar letras, números y símbolos</li>
                            <li>No compartas tu contraseña con nadie</li>
                        </ul>
                        </p>
                    </div>

                    <button type="submit" class="w-full py-2 px-4 rounded-lg bg-darkpurple text-white font-semibold hover:bg-mountbatten transition">
                        Cambiar Contraseña
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Validación de contraseñas en tiempo real
        document.addEventListener('DOMContentLoaded', function() {
            const nuevaPassword = document.getElementById('nueva_password');
            const confirmarPassword = document.getElementById('confirmar_password');

            function validarContraseñas() {
                if (nuevaPassword.value && confirmarPassword.value) {
                    if (nuevaPassword.value !== confirmarPassword.value) {
                        confirmarPassword.setCustomValidity('Las contraseñas no coinciden');
                    } else {
                        confirmarPassword.setCustomValidity('');
                    }
                }
            }

            nuevaPassword.addEventListener('input', validarContraseñas);
            confirmarPassword.addEventListener('input', validarContraseñas);
        });
    </script>
</body>

</html>