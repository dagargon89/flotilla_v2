<?php
// public/suspended.php - Página exclusiva para usuarios suspendidos
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
        $stmt_user_full_status = $db->prepare("SELECT estatus_usuario, nombre FROM usuarios WHERE id = :user_id");
        $stmt_user_full_status->bindParam(':user_id', $_SESSION['user_id']);
        $stmt_user_full_status->execute();
        $user_full_status_result = $stmt_user_full_status->fetch(PDO::FETCH_ASSOC);
        if ($user_full_status_result) {
            $current_user_estatus_usuario = $user_full_status_result['estatus_usuario'];
            $_SESSION['user_estatus_usuario'] = $current_user_estatus_usuario; // Actualizar la sesión
            $nombre_usuario_sesion = $user_full_status_result['nombre'];
        }

        // Obtener TODAS las amonestaciones para este usuario
        $stmt_amonestaciones_full = $db->prepare("
            SELECT fecha_amonestacion, tipo_amonestacion, descripcion, evidencia_url, u.nombre AS amonestado_por_nombre
            FROM amonestaciones a
            LEFT JOIN usuarios u ON a.amonestado_por = u.id
            WHERE a.usuario_id = :user_id
            ORDER BY fecha_amonestacion DESC
        ");
        $stmt_amonestaciones_full->bindParam(':user_id', $_SESSION['user_id']);
        $stmt_amonestaciones_full->execute();
        $amonestaciones_detalles = $stmt_amonestaciones_full->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al cargar detalles de suspensión: " . $e->getMessage());
        $error_message = 'Error al cargar los detalles de tu suspensión. Contacta al administrador.';
    }
} else {
    // Si no está logueado o no hay DB, redirigir al login
    header('Location: index.php');
    exit();
}

// Redirigir si el usuario NO está suspendido (solo suspendidos pueden ver esta página)
if ($current_user_estatus_usuario !== 'suspendido') {
    header('Location: dashboard.php'); // O a la página principal
    exit();
}

// Las variables para el navbar se necesitan aquí también
$rol_usuario_sesion = $_SESSION['user_role'] ?? 'empleado';
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuenta Suspendida - Flotilla Interna</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body>
    <?php
    // La navbar en esta página solo debería mostrar el nombre del usuario y la opción de cerrar sesión
    // Por lo tanto, pasamos solo las variables necesarias y el navbar tendrá lógica para esto.
    require_once '../app/includes/navbar.php';
    ?>
    <?php require_once '../app/includes/alert_banner.php'; // Mostrará el banner rojo de suspensión 
    ?>

    <div class="container mt-4">
        <div class="card shadow p-4 mx-auto" style="max-width: 800px;">
            <h1 class="card-title text-center mb-4">¡Tu Cuenta Está Suspendida! 🚫</h1>

            <div class="alert alert-danger text-center mb-4" role="alert">
                <p class="mb-0">No puedes solicitar ni utilizar vehículos en este momento.</p>
                <p class="mb-0">Esta acción es resultado de un incumplimiento de las políticas de uso de vehículos.</p>
            </div>

            <h4 class="mb-3">Historial Detallado de Amonestaciones:</h4>
            <?php if (empty($amonestaciones_detalles)): ?>
                <div class="alert alert-info text-center">
                    No se encontró un historial de amonestaciones para esta cuenta.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered table-sm">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Tipo de Amonestación</th>
                                <th>Descripción</th>
                                <th>Amonestado Por</th>
                                <th>Evidencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($amonestaciones_detalles as $amonestacion): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i', strtotime($amonestacion['fecha_amonestacion'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php
                                                                switch ($amonestacion['tipo_amonestacion']) {
                                                                    case 'leve':
                                                                        echo 'primary';
                                                                        break;
                                                                    case 'grave':
                                                                        echo 'warning text-dark';
                                                                        break;
                                                                    case 'suspension':
                                                                        echo 'danger';
                                                                        break;
                                                                }
                                                                ?>"><?php echo htmlspecialchars(ucfirst($amonestacion['tipo_amonestacion'])); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($amonestacion['descripcion']); ?></td>
                                    <td><?php echo htmlspecialchars($amonestacion['amonestado_por_nombre'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php if (!empty($amonestacion['evidencia_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($amonestacion['evidencia_url']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Ver Evidencia</a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h4 class="mt-5 mb-3">Contacto para Apelación:</h4>
            <p>Si consideras que esta suspensión es un error o tienes alguna pregunta, por favor contacta al administrador de la flotilla:</p>
            <p><strong>Correo Electrónico:</strong> <a href="mailto:admin@tuorganizacion.com">admin@tuorganizacion.com</a></p>
            <p class="text-center mt-4">
                <a href="logout.php" class="btn btn-danger btn-lg">Cerrar Sesión</a>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>