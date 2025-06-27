<?php
// app/config/database.php

// --- INSTRUCCIONES PARA CONFIGURACIÓN DE BASE DE DATOS ---
// Las credenciales de la base de datos y otras configuraciones sensibles
// deben gestionarse a través de un archivo .env en la raíz del proyecto.
//
// 1. Copia el archivo .env.example a .env (ej: cp .env.example .env).
// 2. Edita el archivo .env con tus credenciales y configuraciones específicas.
// 3. Asegúrate de que el archivo .env esté listado en tu .gitignore para
//    evitar subirlo al repositorio.
// ---------------------------------------------------------------------

// --- Configuración para mostrar errores en desarrollo ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------------------

// --- Carga de variables de entorno desde .env ---
$dotenv_path = __DIR__ . '/../../.env'; // Ruta al archivo .env en la raíz del proyecto

if (file_exists($dotenv_path)) {
    $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) { // Ignorar comentarios
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // Remover comillas si existen al inicio y al final del valor
        if (substr($value, 0, 1) == '"' && substr($value, -1) == '"') {
            $value = substr($value, 1, -1);
        } elseif (substr($value, 0, 1) == "'" && substr($value, -1) == "'") {
            $value = substr($value, 1, -1);
        }

        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
// -------------------------------------------------

// Define las constantes para la conexión a la base de datos
// Se usarán las variables de entorno si están disponibles, de lo contrario, valores por defecto.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'flotilla_interna');

/**
 * Función para establecer la conexión a la base de datos.
 * @return PDO|null Objeto PDO si la conexión es exitosa, null si falla.
 */
function connectDB() {
    try {
        // Crea una nueva instancia de PDO (PHP Data Objects)
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lanza excepciones en caso de error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Devuelve los resultados como arrays asociativos
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Deshabilita la emulación de prepared statements (más seguro)
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        // Si hay un error en la conexión, lo mostramos
        error_log("Error de conexión a la base de datos: " . $e->getMessage());
        // En lugar de die(), retornamos null para que el script que llama pueda manejar el error.
        return null;
    }
}

// Opcional: Para probar la conexión una vez que tengas este archivo
// (Asegúrate de tener un archivo .env o que los valores por defecto sean correctos)
// $db = connectDB();
// if ($db) {
//     echo "¡Conexión a la base de datos exitosa!";
// }
?>