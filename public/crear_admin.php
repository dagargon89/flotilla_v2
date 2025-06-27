<?php
// public/crear_admin.php
// ¡¡¡ADVERTENCIA: ELIMINA ESTE ARCHIVO DESPUÉS DE USARLO!!!

require_once '../app/config/database.php'; // Incluye el archivo de conexión

// Define los datos del usuario administrador de prueba
$admin_nombre = 'David García';
$admin_correo = 'dgarcia@planjuarez.org'; // ¡CAMBIA ESTE CORREO POR UNO REAL DE TU ORGANIZACIÓN!
$admin_password_plana = 'Gagd891220'; // ¡¡¡CAMBIA ESTA CONTRASEÑA POR UNA FUERTE!!!
$admin_rol = 'admin';

// *** PASO CRUCIAL: HASH de la contraseña ***
$hashed_password = password_hash($admin_password_plana, PASSWORD_DEFAULT);

$db = connectDB(); // Conecta a la base de datos

$success_message = '';
$error_message = '';

if ($db) {
    try {
        // Prepara la consulta para insertar el usuario
        $stmt = $db->prepare("INSERT INTO usuarios (nombre, correo_electronico, password, rol) VALUES (:nombre, :correo, :password, :rol)");

        // Asigna los valores a los parámetros de la consulta
        $stmt->bindParam(':nombre', $admin_nombre);
        $stmt->bindParam(':correo', $admin_correo);
        $stmt->bindParam(':password', $hashed_password); // Guarda la contraseña hasheada
        $stmt->bindParam(':rol', $admin_rol);

        // Ejecuta la consulta
        $stmt->execute();

        $success_message = "¡Usuario Administrador creado exitosamente!";

    } catch (PDOException $e) {
        // Si el usuario ya existe (por el correo_electronico UNIQUE) o hay otro error
        if ($e->getCode() == 23000) { // Código para violación de clave única
            $error_message = "Error: El usuario con este correo (" . htmlspecialchars($admin_correo) . ") ya existe.";
        } else {
            error_log("Error al crear usuario admin: " . $e->getMessage());
            $error_message = "Ocurrió un error al intentar crear el usuario administrador.";
        }
    }
} else {
    $error_message = "Error: No se pudo conectar a la base de datos.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Administrador - Flotilla Interna</title>
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
</head>

<body class="bg-parchment min-h-screen flex items-center justify-center">
    <div class="max-w-2xl mx-auto px-4 py-8">
        <div class="bg-white rounded-xl shadow-lg p-8 border border-cambridge2">
            <h1 class="text-3xl font-bold text-darkpurple text-center mb-6">Crear Usuario Administrador</h1>
            
            <?php if (!empty($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6" role="alert">
                    <h2 class="text-xl font-semibold mb-2"><?php echo $success_message; ?></h2>
                    <div class="space-y-2 text-sm">
                        <p><span class="font-semibold">Correo:</span> <?php echo htmlspecialchars($admin_correo); ?></p>
                        <p><span class="font-semibold">Contraseña:</span> <?php echo htmlspecialchars($admin_password_plana); ?> <span class="text-xs">(¡Recuerda que esta es la contraseña PLANA, en la DB está HASHADA!)</span></p>
                        <p><span class="font-semibold">Rol:</span> <?php echo htmlspecialchars($admin_rol); ?></p>
                    </div>
                    <div class="mt-4 p-4 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded">
                        <p class="font-bold">!!! RECUERDA: ELIMINA este archivo (crear_admin.php) de tu servidor cuando hayas terminado de crear el usuario. !!!</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6" role="alert">
                    <h2 class="text-xl font-semibold mb-2"><?php echo $error_message; ?></h2>
                    <?php if (strpos($error_message, 'ya existe') !== false): ?>
                        <p class="text-sm">Puedes intentar iniciar sesión en el sistema si ya lo creaste.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="text-center">
                <a href="index.php" class="inline-block bg-darkpurple text-white px-6 py-3 rounded-lg font-semibold hover:bg-mountbatten transition">
                    Ir al Login
                </a>
            </div>
        </div>
    </div>
</body>
</html>