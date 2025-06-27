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

        echo "<h1>¡Usuario Administrador creado exitosamente!</h1>";
        echo "<p><strong>Correo:</strong> " . htmlspecialchars($admin_correo) . "</p>";
        echo "<p><strong>Contraseña:</strong> " . htmlspecialchars($admin_password_plana) . " (¡Recuerda que esta es la contraseña PLANA, en la DB está HASHADA!)</p>";
        echo "<p><strong>Rol:</strong> " . htmlspecialchars($admin_rol) . "</p>";
        echo "<hr>";
        echo "<p>¡Ahora puedes intentar iniciar sesión en <a href='index.php'>index.php</a>!</p>";
        echo "<p style='color: red; font-weight: bold;'>!!! RECUERDA: ELIMINA este archivo (crear_admin.php) de tu servidor cuando hayas terminado de crear el usuario. !!!</p>";

    } catch (PDOException $e) {
        // Si el usuario ya existe (por el correo_electronico UNIQUE) o hay otro error
        if ($e->getCode() == 23000) { // Código para violación de clave única
            echo "<h1>Error: El usuario con este correo (" . htmlspecialchars($admin_correo) . ") ya existe.</h1>";
            echo "<p>Puedes intentar iniciar sesión en <a href='index.php'>index.php</a> si ya lo creaste.</p>";
        } else {
            error_log("Error al crear usuario admin: " . $e->getMessage());
            echo "<h1>Ocurrió un error al intentar crear el usuario administrador.</h1>";
            echo "<p>Detalles del error (solo para desarrollo): " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
} else {
    echo "<h1>Error: No se pudo conectar a la base de datos.</h1>";
}
?>