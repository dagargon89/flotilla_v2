<?php
// public/index.php - CÓDIGO ACTUALIZADO (Login para Suspended y Pendientes/Rechazados)
session_start();
require_once '../app/config/database.php';

// Si el usuario ya está logueado, redirigirlo al dashboard
if (isset($_SESSION['user_id'])) {
    // Si ya está logueado y es suspendido, mandarlo a la página de suspensión
    if (isset($_SESSION['user_estatus_usuario']) && $_SESSION['user_estatus_usuario'] === 'suspendido') {
        header('Location: suspended.php');
        exit();
    }
    header('Location: dashboard.php');
    exit();
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo_electronico'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($correo) || empty($password)) {
        $error_message = 'Por favor, ingresa tu correo y contraseña.';
    } else {
        $db = connectDB();
        if ($db) {
            try {
                $stmt = $db->prepare("SELECT id, nombre, correo_electronico, password, rol, estatus_cuenta, estatus_usuario FROM usuarios WHERE correo_electronico = :correo");
                $stmt->bindParam(':correo', $correo);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    if (password_verify($password, $user['password'])) {
                        // Contraseña correcta

                        // INICIAMOS LA SESIÓN PARA TODOS, PERO REDIRIGIMOS SEGÚN ESTATUS
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['nombre'];
                        $_SESSION['user_role'] = $user['rol'];
                        $_SESSION['user_estatus_usuario'] = $user['estatus_usuario']; // Guardar el estatus de uso en sesión

                        // Actualiza la última sesión del usuario en la BD
                        $update_stmt = $db->prepare("UPDATE usuarios SET ultima_sesion = NOW() WHERE id = :id");
                        $update_stmt->bindParam(':id', $user['id']);
                        $update_stmt->execute();

                        // Redirigir según el estatus de la cuenta/usuario
                        if ($user['estatus_cuenta'] === 'activa' && $user['estatus_usuario'] === 'activo') {
                            header('Location: dashboard.php');
                            exit();
                        } elseif ($user['estatus_usuario'] === 'suspendido') { // Si el usuario está suspendido
                            header('Location: suspended.php');
                            exit();
                        } elseif ($user['estatus_cuenta'] === 'pendiente_aprobacion') {
                            $error_message = 'Tu cuenta está pendiente de aprobación. Por favor, espera a que el administrador la active.';
                            // Opcional: Destruir sesión si está pendiente o rechazada para forzar login de nuevo
                            session_destroy(); // Destruye la sesión recién creada
                        } elseif ($user['estatus_cuenta'] === 'rechazada') {
                            $error_message = 'Tu solicitud de cuenta ha sido rechazada. Contacta al administrador si crees que es un error.';
                            session_destroy(); // Destruye la sesión recién creada
                        } else { // 'inactiva' o cualquier otro estatus no previsto
                            $error_message = 'Tu cuenta está inactiva. Contacta al administrador.';
                            session_destroy(); // Destruye la sesión recién creada
                        }
                    } else {
                        $error_message = 'Correo o contraseña incorrectos.';
                    }
                } else {
                    $error_message = 'Correo o contraseña incorrectos.';
                }
            } catch (PDOException $e) {
                error_log("Error de login: " . $e->getMessage());
                $error_message = 'Ocurrió un error al intentar iniciar sesión. Por favor, intenta de nuevo.';
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
    <title>Iniciar Sesión - Flotilla Interna</title>
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
                        parchment: '#FFFBFA',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="css/colors.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/password-toggle.css">
</head>

<body class="min-h-screen flex items-center justify-center bg-parchment">
    <div class="w-full max-w-md bg-white rounded-xl shadow-lg p-8 border border-cambridge2">
        <h2 class="text-2xl font-bold text-darkpurple text-center mb-6">Iniciar Sesión</h2>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm" role="alert">
                <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST" class="space-y-4">
            <div>
                <label for="correo_electronico" class="block text-sm font-medium text-darkpurple mb-1">Correo Electrónico</label>
                <input type="email" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple placeholder-mountbatten bg-parchment outline-none transition" id="correo_electronico" name="correo_electronico" required autocomplete="username">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-darkpurple mb-1">Contraseña</label>
                <input type="password" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple placeholder-mountbatten bg-parchment outline-none transition" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="w-full py-2 px-4 rounded-lg bg-darkpurple text-white font-semibold hover:bg-mountbatten transition">Entrar</button>
        </form>

        <hr class="my-6 border-cambridge2">

        <!--<div class="text-center">
            <button type="button" class="w-full py-2 px-4 rounded-lg border border-cambridge1 text-cambridge1 font-semibold bg-white flex items-center justify-center gap-2 mb-2 cursor-not-allowed opacity-60" disabled>
                <img src="https://img.icons8.com/color/16/000000/google-logo.png" alt="Google icon">
                Iniciar Sesión con Google (próximamente)
            </button>
        </div>-->
        <p class="text-center mt-4 text-sm text-mountbatten">
            ¿No tienes cuenta? <a href="register.php" class="text-cambridge1 hover:underline">Regístrate aquí</a>
        </p>
    </div>
    <script src="js/main.js"></script>
    <script src="js/password-toggle.js"></script>
</body>

</html>