<?php
// public/api/get_calendar_events.php - CÓDIGO COMPLETO Y ACTUALIZADO (Con EVENTO y DESCRIPCION)

header('Content-Type: application/json'); // ¡Importante! Le dice al navegador que es JSON

require_once '../../app/config/database.php'; // Ruta a tu archivo de conexión

$db = connectDB();
$events = []; // Array donde guardaremos los eventos

if ($db) {
    try {
        // FullCalendar puede enviar parámetros 'start' y 'end' (rango de fechas visible en el calendario)
        // Se usa para optimizar y solo traer eventos del mes/semana que se está viendo.
        $start_param = $_GET['start'] ?? null; // Formato YYYY-MM-DD
        $end_param = $_GET['end'] ?? null; // Formato YYYY-MM-DD

        $sql = "
            SELECT
                s.id AS solicitud_id,
                s.fecha_salida_solicitada AS start_date,
                s.fecha_regreso_solicitada AS end_date,
                s.evento,             /* <<-- NUEVO */
                s.descripcion,        /* <<-- RENOMBRADO */
                s.estatus_solicitud,
                u.nombre AS usuario_nombre,
                v.marca,
                v.modelo,
                v.placas
            FROM solicitudes_vehiculos s
            JOIN usuarios u ON s.usuario_id = u.id
            JOIN vehiculos v ON s.vehiculo_id = v.id -- INNER JOIN porque solo queremos solicitudes con vehículo asignado
            WHERE s.estatus_solicitud IN ('aprobada', 'en_curso')
        ";

        if ($start_param && $end_param) {
            $sql .= " AND s.fecha_salida_solicitada < :end_range AND s.fecha_regreso_solicitada > :start_range";
        }
        $sql .= " ORDER BY s.fecha_salida_solicitada ASC";

        $stmt = $db->prepare($sql);

        if ($start_param && $end_param) {
            $stmt->bindParam(':start_range', $start_param);
            $stmt->bindParam(':end_range', $end_param);
        }

        $stmt->execute();
        $solicitudes_aprobadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($solicitudes_aprobadas as $solicitud) {
            $color = '#28a745'; // Verde para aprobadas por defecto
            if ($solicitud['estatus_solicitud'] === 'en_curso') {
                $color = '#007bff'; // Azul para en curso
            }

            $events[] = [
                'id' => $solicitud['solicitud_id'],
                'title' => $solicitud['evento'] . ' - ' . $solicitud['placas'], // Título del evento en el calendario
                'start' => $solicitud['start_date'],
                'end' => $solicitud['end_date'],
                'color' => $color, // Color del evento
                'allDay' => false, // Las solicitudes tienen hora, no son de todo el día
                'extendedProps' => [ // Datos adicionales que puedes usar
                    'evento' => $solicitud['evento'],              /* <<-- NUEVO */
                    'descripcion' => $solicitud['descripcion'],    /* <<-- RENOMBRADO */
                    'estatus' => $solicitud['estatus_solicitud'],
                    'solicitante' => $solicitud['usuario_nombre'],
                    'vehiculo' => $solicitud['marca'] . ' ' . $solicitud['modelo'] . ' (' . $solicitud['placas'] . ')'
                ]
            ];
        }
    } catch (PDOException $e) {
        error_log("Error al obtener eventos del calendario: " . $e->getMessage());
        echo json_encode(['error' => 'Error al cargar eventos: ' . $e->getMessage()]);
        exit();
    }
} else {
    echo json_encode(['error' => 'No se pudo conectar a la base de datos para eventos.']);
    exit();
}

// Devuelve los eventos en formato JSON
echo json_encode($events);
