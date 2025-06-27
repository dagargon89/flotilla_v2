<?php
// app/includes/global_auth_redirect.php
// Este archivo se incluye al inicio de cada página protegida para manejar redirecciones por estatus.

// Asegúrate de que $db ya esté conectado y $_SESSION['user_id'] esté establecido
// Y que $current_user_estatus_usuario ya haya sido obtenido de la DB.

// Páginas a las que un usuario suspendido SÍ puede acceder
$allowed_pages_for_suspended = ['suspended.php', 'logout.php'];

// Si el usuario está logueado y su estatus_usuario es 'suspendido'
if (isset($_SESSION['user_id']) && isset($current_user_estatus_usuario) && $current_user_estatus_usuario === 'suspendido') {
    // Obtener el nombre del archivo actual
    $current_page = basename($_SERVER['PHP_SELF']);

    // Si la página actual NO es la página de suspendido ni la de logout, redirigir
    if (!in_array($current_page, $allowed_pages_for_suspended)) {
        header('Location: suspended.php');
        exit();
    }
}
// Si no está suspendido, la ejecución de la página continúa normalmente.
