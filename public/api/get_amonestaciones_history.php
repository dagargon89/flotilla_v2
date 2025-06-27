<?php
// public/api/get_amonestaciones_history.php
// API para obtener el historial de amonestaciones de un usuario específico

header('Content-Type: application/json'); // ¡Importante!

require_once '../../app/config/database.php'; // Ruta a tu archivo de conexión

$user_id = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
$history = [];

if (!$user_id) {
    echo json_encode(['error' => 'ID de usuario no proporcionado o inválido.']);
    exit();
}

$db = connectDB();

if ($db) {
    try {
        // Consulta para obtener el historial de amonestaciones del usuario
        $stmt = $db->prepare("
            SELECT
                a.id,
                a.fecha_amonestacion,
                a.tipo_amonestacion,
                a.descripcion,
                a.evidencia_url,
                u.nombre AS amonestado_por_nombre
            FROM amonestaciones a
            LEFT JOIN usuarios u ON a.amonestado_por = u.id
            WHERE a.usuario_id = :user_id
            ORDER BY a.fecha_amonestacion DESC
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error en API de historial de amonestaciones: " . $e->getMessage());
        echo json_encode(['error' => 'Error al cargar el historial: ' . $e->getMessage()]);
        exit();
    }
} else {
    echo json_encode(['error' => 'No se pudo conectar a la base de datos.']);
    exit();
}

echo json_encode(['history' => $history]);
