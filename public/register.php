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
    <link rel="stylesheet" href="css/colors.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/password-toggle.css">
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

<body class="min-h-screen flex items-center justify-center bg-parchment">
    <div class="w-full max-w-lg bg-white rounded-xl shadow-lg p-8 border border-cambridge2">
        <h2 class="text-2xl font-bold text-darkpurple text-center mb-6">Solicitar Cuenta</h2>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 text-sm" role="alert">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm" role="alert">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST" class="space-y-4">
            <div>
                <label for="nombre" class="block text-sm font-medium text-darkpurple mb-1">Nombre Completo</label>
                <input type="text" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple placeholder-mountbatten bg-parchment outline-none transition" id="nombre" name="nombre" value="<?php echo htmlspecialchars($nombre); ?>" required autocomplete="name">
            </div>
            <div>
                <label for="correo_electronico" class="block text-sm font-medium text-darkpurple mb-1">Correo Electrónico</label>
                <input type="email" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple placeholder-mountbatten bg-parchment outline-none transition" id="correo_electronico" name="correo_electronico" value="<?php echo htmlspecialchars($correo_electronico); ?>" required autocomplete="email">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-darkpurple mb-1">Contraseña</label>
                <input type="password" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple placeholder-mountbatten bg-parchment outline-none transition" id="password" name="password" required autocomplete="new-password">
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-darkpurple mb-1">Confirmar Contraseña</label>
                <input type="password" class="block w-full rounded-lg border border-cambridge1 focus:border-darkpurple focus:ring-2 focus:ring-cambridge1 px-3 py-2 text-darkpurple placeholder-mountbatten bg-parchment outline-none transition" id="confirm_password" name="confirm_password" required autocomplete="new-password">
            </div>
            <button type="submit" class="w-full py-2 px-4 rounded-lg bg-darkpurple text-white font-semibold hover:bg-mountbatten transition">Enviar Solicitud de Cuenta</button>
        </form>

        <hr class="my-6 border-cambridge2">
        <p class="text-center text-sm text-mountbatten">
            ¿Ya tienes cuenta? <a href="index.php" class="text-cambridge1 hover:underline">Inicia Sesión aquí</a>
        </p>
    </div>
    <script src="js/main.js"></script>
    <script src="js/password-toggle.js"></script>
</body>

</html>