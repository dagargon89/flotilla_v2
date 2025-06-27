<?php
// public/register.php - Página de Auto-registro de Usuarios
session_start();
require_once '../app/config/database.php';

// Si el usuario ya está logueado, redirigirlo al dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$success_message = '';
$error_message = '';

// Inicializar variables del formulario
$nombre = '';
$correo_electronico = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $correo_electronico = trim($_POST['correo_electronico'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($nombre) || empty($correo_electronico) || empty($password) || empty($confirm_password)) {
        $error_message = 'Por favor, completa todos los campos.';
    } elseif (!filter_var($correo_electronico, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'El formato del correo electrónico no es válido.';
    } elseif ($password !== $confirm_password) {
        $error_message = 'Las contraseñas no coinciden.';
    } elseif (strlen($password) < 6) { // Mínimo 6 caracteres, puedes hacerlo más estricto
        $error_message = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        $db = connectDB();
        if ($db) {
            try {
                // Verificar si el correo ya existe
                $stmt_check_email = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE correo_electronico = :correo");
                $stmt_check_email->bindParam(':correo', $correo_electronico);
                $stmt_check_email->execute();
                if ($stmt_check_email->fetchColumn() > 0) {
                    $error_message = 'Este correo electrónico ya está registrado. Intenta con otro o inicia sesión.';
                } else {
                    // Hashear la contraseña
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Insertar usuario con estatus 'pendiente_aprobacion' y rol 'empleado' por defecto
                    $stmt_insert_user = $db->prepare("INSERT INTO usuarios (nombre, correo_electronico, password, rol, estatus_cuenta) VALUES (:nombre, :correo, :password, 'empleado', 'pendiente_aprobacion')");
                    $stmt_insert_user->bindParam(':nombre', $nombre);
                    $stmt_insert_user->bindParam(':correo', $correo_electronico);
                    $stmt_insert_user->bindParam(':password', $hashed_password);
                    $stmt_insert_user->execute();

                    $success_message = '¡Tu solicitud de registro ha sido enviada! Espera la aprobación del administrador.';
                    // Limpiar campos del formulario
                    $nombre = '';
                    $correo_electronico = '';
                }
            } catch (PDOException $e) {
                error_log("Error al registrar usuario: " . $e->getMessage());
                $error_message = 'Ocurrió un error al intentar registrarte. Intenta de nuevo.';
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
    <title>Registro - Flotilla Interna</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
    <div class="card shadow p-4" style="max-width: 500px; width: 100%;">
        <h2 class="card-title text-center mb-4">Solicitar Cuenta</h2>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" role="alert">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST">
            <div class="mb-3">
                <label for="nombre" class="form-label">Nombre Completo</label>
                <input type="text" class="form-control" id="nombre" name="nombre" value="<?php echo htmlspecialchars($nombre); ?>" required>
            </div>
            <div class="mb-3">
                <label for="correo_electronico" class="form-label">Correo Electrónico</label>
                <input type="email" class="form-control" id="correo_electronico" name="correo_electronico" value="<?php echo htmlspecialchars($correo_electronico); ?>" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Contraseña</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirmar Contraseña</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Enviar Solicitud de Cuenta</button>
        </form>

        <hr class="my-4">
        <p class="text-center">¿Ya tienes cuenta? <a href="index.php">Inicia Sesión aquí</a></p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/main.js"></script>
</body>

</html>