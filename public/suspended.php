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
    // La navbar en esta página solo debería mostrar el nombre del usuario y la opción de cerrar sesión
    // Por lo tanto, pasamos solo las variables necesarias y el navbar tendrá lógica para esto.
    require_once '../app/includes/navbar.php';
    ?>
    <?php require_once '../app/includes/alert_banner.php'; // Mostrará el banner rojo de suspensión 
    ?>

    <div class="container mx-auto px-4 py-6">
        <div class="max-w-4xl mx-auto bg-white rounded-xl shadow-lg p-8 border border-cambridge2">
            <h1 class="text-3xl font-bold text-darkpurple text-center mb-6">¡Tu Cuenta Está Suspendida! 🚫</h1>

            <div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6 text-center" role="alert">
                <p class="mb-2 font-semibold">No puedes solicitar ni utilizar vehículos en este momento.</p>
                <p class="text-sm">Esta acción es resultado de un incumplimiento de las políticas de uso de vehículos.</p>
            </div>

            <h4 class="text-xl font-semibold text-darkpurple mb-4">Historial Detallado de Amonestaciones:</h4>
            <?php if (empty($amonestaciones_detalles)): ?>
                <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded text-center">
                    No se encontró un historial de amonestaciones para esta cuenta.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-cambridge1">
                        <thead>
                            <tr class="bg-cambridge1 text-white">
                                <th class="border border-cambridge1 px-4 py-2 text-left">Fecha</th>
                                <th class="border border-cambridge1 px-4 py-2 text-left">Tipo de Amonestación</th>
                                <th class="border border-cambridge1 px-4 py-2 text-left">Descripción</th>
                                <th class="border border-cambridge1 px-4 py-2 text-left">Amonestado Por</th>
                                <th class="border border-cambridge1 px-4 py-2 text-left">Evidencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($amonestaciones_detalles as $amonestacion): ?>
                                <tr class="hover:bg-parchment">
                                    <td class="border border-cambridge1 px-4 py-2"><?php echo date('d/m/Y H:i', strtotime($amonestacion['fecha_amonestacion'])); ?></td>
                                    <td class="border border-cambridge1 px-4 py-2">
                                        <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?php
                                                                                                                switch ($amonestacion['tipo_amonestacion']) {
                                                                                                                    case 'leve':
                                                                                                                        echo 'bg-cambridge1 text-white';
                                                                                                                        break;
                                                                                                                    case 'grave':
                                                                                                                        echo 'bg-yellow-500 text-white';
                                                                                                                        break;
                                                                                                                    case 'suspension':
                                                                                                                        echo 'bg-red-500 text-white';
                                                                                                                        break;
                                                                                                                }
                                                                                                                ?>"><?php echo htmlspecialchars(ucfirst($amonestacion['tipo_amonestacion'])); ?></span>
                                    </td>
                                    <td class="border border-cambridge1 px-4 py-2"><?php echo htmlspecialchars($amonestacion['descripcion']); ?></td>
                                    <td class="border border-cambridge1 px-4 py-2"><?php echo htmlspecialchars($amonestacion['amonestado_por_nombre'] ?? 'N/A'); ?></td>
                                    <td class="border border-cambridge1 px-4 py-2">
                                        <?php if (!empty($amonestacion['evidencia_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($amonestacion['evidencia_url']); ?>" target="_blank" class="inline-block bg-cambridge2 text-darkpurple px-3 py-1 rounded text-sm font-semibold hover:bg-cambridge1 transition">Ver Evidencia</a>
                                        <?php else: ?>
                                            <span class="text-mountbatten">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="mt-8 p-6 bg-parchment rounded-lg border border-cambridge2">
                <h4 class="text-xl font-semibold text-darkpurple mb-3">Contacto para Apelación:</h4>
                <p class="text-mountbatten mb-2">Si consideras que esta suspensión es un error o tienes alguna pregunta, por favor contacta al administrador de la flotilla:</p>
                <p class="text-darkpurple"><strong>Correo Electrónico:</strong> <a href="mailto:admin@tuorganizacion.com" class="text-cambridge1 hover:underline">admin@tuorganizacion.com</a></p>
            </div>

            <div class="text-center mt-6">
                <a href="logout.php" class="inline-block bg-red-600 text-white px-8 py-3 rounded-lg font-semibold hover:bg-red-700 transition">Cerrar Sesión</a>
            </div>
        </div>
    </div>
</body>

</html>