<?php
header('Content-Type: application/json');
require_once '../../app/config/database.php';

if (!isset($_GET['vehiculo_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de vehículo no proporcionado']);
    exit;
}

$vehiculo_id = filter_var($_GET['vehiculo_id'], FILTER_VALIDATE_INT);

if (!$vehiculo_id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de vehículo inválido']);
    exit;
}

try {
    $db = connectDB();
    $stmt = $db->prepare("SELECT estatus FROM vehiculos WHERE id = :vehiculo_id");
    $stmt->bindParam(':vehiculo_id', $vehiculo_id);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        // Convertir el estado a un formato consistente
        $estado = strtolower(trim($result['estatus']));
        echo json_encode([
            'status' => $estado,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Vehículo no encontrado']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al obtener el estado del vehículo']);
}
