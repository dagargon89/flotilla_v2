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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
    <div class="card shadow p-4" style="max-width: 400px; width: 100%;">
        <h2 class="card-title text-center mb-4">Iniciar Sesión</h2>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST">
            <div class="mb-3">
                <label for="correo_electronico" class="form-label">Correo Electrónico</label>
                <input type="email" class="form-control" id="correo_electronico" name="correo_electronico" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Contraseña</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Entrar</button>
        </form>

        <hr class="my-4">

        <div class="text-center">
            <button type="button" class="btn btn-outline-secondary w-100 mb-2" disabled>
                <img src="https://img.icons8.com/color/16/000000/google-logo.png" alt="Google icon" class="me-2">
                Iniciar Sesión con Google (próximamente)
            </button>
        </div>
        <p class="text-center mt-3">¿No tienes cuenta? <a href="register.php">Regístrate aquí</a></p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/main.js"></script>
</body>

</html>