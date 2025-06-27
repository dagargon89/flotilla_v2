<?php
// public/gestion_documentos.php - CÓDIGO COMPLETO Y CORREGIDO (Error Undefined $db)
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
        $stmt_user_full_status = $db->prepare("SELECT estatus_usuario FROM usuarios WHERE id = :user_id");
        $stmt_user_full_status->bindParam(':user_id', $_SESSION['user_id']);
        $stmt_user_full_status->execute();
        $user_full_status_result = $stmt_user_full_status->fetch(PDO::FETCH_ASSOC);
        if ($user_full_status_result) {
            $current_user_estatus_usuario = $user_full_status_result['estatus_usuario'];
            $_SESSION['user_estatus_usuario'] = $current_user_estatus_usuario; // Actualizar la sesión
        }

        // Si el usuario está 'amonestado', obtener los detalles de las amonestaciones para el banner
        if ($current_user_estatus_usuario === 'amonestado') {
            $stmt_amonestaciones = $db->prepare("
                SELECT COUNT(*) as total_count,
                       GROUP_CONCAT(CONCAT(DATE_FORMAT(fecha_amonestacion, '%d/%m'), ' (', tipo_amonestacion, ')') ORDER BY fecha_amonestacion DESC SEPARATOR '; ') AS recent_descriptions
                FROM amonestaciones
                WHERE usuario_id = :user_id
                LIMIT 3
            ");
            $stmt_amonestaciones->bindParam(':user_id', $_SESSION['user_id']);
            $stmt_amonestaciones->execute();
            $amonestacion_data = $stmt_amonestaciones->fetch(PDO::FETCH_ASSOC);

            if ($amonestacion_data) {
                $current_user_amonestaciones_count = $amonestacion_data['total_count'];
                $current_user_recent_amonestaciones_text = $amonestacion_data['recent_descriptions'] ?: 'Ninguna reciente.';
            }
        }
    } catch (PDOException $e) {
        error_log("Error al obtener estatus de usuario/amonestaciones para banner: " . $e->getMessage());
        $current_user_estatus_usuario = 'activo';
        $error_message = 'Error al cargar tu estatus o amonestaciones. Contacta al administrador.';
    }
}


// **VERIFICACIÓN DE ROL:**
// Solo 'admin' puede acceder a esta página (corregido de la versión anterior).
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php'); // Redirige al dashboard si no tiene permisos
    exit();
}

$nombre_usuario_sesion = $_SESSION['user_name'];
$rol_usuario_sesion = $_SESSION['user_role'];
$user_id_sesion = $_SESSION['user_id'];

$success_message = '';
$error_message = $error_message ?? ''; // Mantener el error si ya viene del bloque de amonestaciones

$documentos = []; // Para guardar la lista de documentos
$vehiculos_flotilla = []; // Para el dropdown de vehículos en los modales

// Ruta base para guardar los documentos (FUERA DE PUBLIC_HTML POR SEGURIDAD)
$upload_dir = __DIR__ . '/../storage/uploads/vehiculo_documentos/';

// Asegúrate de que el directorio de subida exista y tenga permisos de escritura
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
if (!is_writable($upload_dir)) {
    $error_message .= 'Error: El directorio de subida de documentos no tiene permisos de escritura. Por favor, configura los permisos de la carpeta: ' . htmlspecialchars($upload_dir);
    error_log("Permisos de escritura faltantes en: " . $upload_dir);
}

// --- Lógica para procesar el formulario (Subir/Editar/Eliminar Documento) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; // 'add', 'edit', 'delete'

    try {
        if ($action === 'add') {
            $vehiculo_id = filter_var($_POST['vehiculo_id'] ?? '', FILTER_VALIDATE_INT);
            $nombre_documento = trim($_POST['nombre_documento'] ?? '');
            $fecha_vencimiento = trim($_POST['fecha_vencimiento'] ?? '');
            $uploaded_file = $_FILES['archivo'] ?? null;

            if ($vehiculo_id === false || empty($nombre_documento) || empty($uploaded_file['name'])) {
                throw new Exception("Por favor, selecciona un vehículo, un nombre y un archivo para el documento.");
            }

            // Procesar la subida del archivo
            if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
                $file_ext = pathinfo($uploaded_file['name'], PATHINFO_EXTENSION);
                $new_file_name = uniqid('doc_') . '.' . $file_ext;
                $destination_path = $upload_dir . $new_file_name;

                if (!move_uploaded_file($uploaded_file['tmp_name'], $destination_path)) {
                    throw new Exception("Error al mover el archivo subido.");
                }
                // Ruta a guardar en BD (URL accesible por el navegador)
                $ruta_archivo = '/flotilla/storage/uploads/vehiculo_documentos/' . $new_file_name;
            } else {
                throw new Exception("Error en la subida del archivo: " . $uploaded_file['error']);
            }

            $fecha_vencimiento = empty($fecha_vencimiento) ? NULL : $fecha_vencimiento;

            $stmt = $db->prepare("INSERT INTO documentos_vehiculos (vehiculo_id, nombre_documento, ruta_archivo, fecha_vencimiento, subido_por) VALUES (:vehiculo_id, :nombre_documento, :ruta_archivo, :fecha_vencimiento, :subido_por)");
            $stmt->bindParam(':vehiculo_id', $vehiculo_id);
            $stmt->bindParam(':nombre_documento', $nombre_documento);
            $stmt->bindParam(':ruta_archivo', $ruta_archivo);
            $stmt->bindParam(':fecha_vencimiento', $fecha_vencimiento);
            $stmt->bindParam(':subido_por', $user_id_sesion);
            $stmt->execute();
            $success_message = 'Documento cargado con éxito.';
        } elseif ($action === 'edit') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            $vehiculo_id = filter_var($_POST['vehiculo_id'] ?? '', FILTER_VALIDATE_INT);
            $nombre_documento = trim($_POST['nombre_documento'] ?? '');
            $fecha_vencimiento = trim($_POST['fecha_vencimiento'] ?? '');
            $current_ruta_archivo = trim($_POST['current_ruta_archivo'] ?? ''); // Ruta actual si no se sube uno nuevo
            $uploaded_file = $_FILES['archivo'] ?? null;

            if ($id === false || $vehiculo_id === false || empty($nombre_documento)) {
                throw new Exception("Por favor, completa los campos obligatorios para editar el documento.");
            }

            $ruta_archivo_final = $current_ruta_archivo; // Por defecto, usa la actual

            // Si se sube un nuevo archivo, procesarlo
            if (!empty($uploaded_file['name']) && $uploaded_file['error'] === UPLOAD_ERR_OK) {
                $file_ext = pathinfo($uploaded_file['name'], PATHINFO_EXTENSION);
                $new_file_name = uniqid('doc_') . '.' . $file_ext;
                $destination_path = $upload_dir . $new_file_name;

                if (!move_uploaded_file($uploaded_file['tmp_name'], $destination_path)) {
                    throw new Exception("Error al mover el nuevo archivo subido.");
                }
                $ruta_archivo_final = '/flotilla/storage/uploads/vehiculo_documentos/' . $new_file_name;
            } elseif (!empty($uploaded_file['name']) && $uploaded_file['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new Exception("Error en la subida del nuevo archivo: " . $uploaded_file['error']);
            }


            $fecha_vencimiento = empty($fecha_vencimiento) ? NULL : $fecha_vencimiento;

            $stmt = $db->prepare("UPDATE documentos_vehiculos SET vehiculo_id = :vehiculo_id, nombre_documento = :nombre_documento, ruta_archivo = :ruta_archivo, fecha_vencimiento = :fecha_vencimiento WHERE id = :id");
            $stmt->bindParam(':vehiculo_id', $vehiculo_id);
            $stmt->bindParam(':nombre_documento', $nombre_documento);
            $stmt->bindParam(':ruta_archivo', $ruta_archivo_final);
            $stmt->bindParam(':fecha_vencimiento', $fecha_vencimiento);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $success_message = 'Documento actualizado con éxito.';
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false) {
                throw new Exception("ID de documento inválido para eliminar.");
            }

            $stmt_get_file = $db->prepare("SELECT ruta_archivo FROM documentos_vehiculos WHERE id = :id");
            $stmt_get_file->bindParam(':id', $id);
            $stmt_get_file->execute();
            $file_to_delete = $stmt_get_file->fetch(PDO::FETCH_ASSOC);

            if ($file_to_delete && !empty($file_to_delete['ruta_archivo'])) {
                $file_path_on_server = str_replace('/flotilla', '/var/www/html/flotilla', $file_to_delete['ruta_archivo']);
                if (file_exists($file_path_on_server)) {
                    unlink($file_path_on_server);
                    error_log("Archivo eliminado físicamente: " . $file_path_on_server);
                } else {
                    error_log("Advertencia: Archivo físico no encontrado para eliminar: " . $file_path_on_server);
                }
            }

            $stmt = $db->prepare("DELETE FROM documentos_vehiculos WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $success_message = 'Documento eliminado con éxito.';
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
        error_log("Error en gestión de documentos: " . $e->getMessage());
    }
}

// --- Obtener todos los documentos para mostrar en la tabla ---
if ($db) { // $db ya está definida.
    try {
        $stmt_documentos = $db->query("
            SELECT d.*, v.marca, v.modelo, v.placas, u.nombre as subido_por_nombre
            FROM documentos_vehiculos d
            JOIN vehiculos v ON d.vehiculo_id = v.id
            LEFT JOIN usuarios u ON d.subido_por = u.id
            ORDER BY d.fecha_subida DESC
        ");
        $documentos = $stmt_documentos->fetchAll(PDO::FETCH_ASSOC);

        // Obtener todos los vehículos para el dropdown en los modales
        $stmt_vehiculos_flotilla = $db->query("SELECT id, marca, modelo, placas FROM vehiculos ORDER BY marca, modelo");
        $vehiculos_flotilla = $stmt_vehiculos_flotilla->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al cargar documentos o vehículos para el formulario: " . $e->getMessage());
        $error_message = 'No se pudieron cargar los datos.';
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Documentos - Flotilla Interna</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

<body class="bg-parchment min-h-screen">
    <?php
    $nombre_usuario_sesion = $_SESSION['user_name'] ?? 'Usuario';
    $rol_usuario_sesion = $_SESSION['user_role'] ?? 'empleado';
    require_once '../app/includes/navbar.php';
    ?>
    <?php require_once '../app/includes/alert_banner.php'; // Incluir el banner de alertas 
    ?>

    <div class="container mx-auto px-4 py-6">
        <h1 class="text-3xl font-bold text-darkpurple mb-6">Gestión de Documentos de Vehículos</h1>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4" role="alert">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <button type="button" class="bg-cambridge2 text-darkpurple px-4 py-2 rounded-lg font-semibold hover:bg-cambridge1 transition mb-6" data-bs-toggle="modal" data-bs-target="#addEditDocumentModal" data-action="add">
            <i class="bi bi-file-earmark-plus"></i> Cargar Nuevo Documento
        </button>

        <?php if (empty($documentos)): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded" role="alert">
                No hay documentos registrados para los vehículos.
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-cambridge2">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-cambridge1 text-white">
                                <th class="px-4 py-3 text-left">ID</th>
                                <th class="px-4 py-3 text-left">Vehículo</th>
                                <th class="px-4 py-3 text-left">Nombre Documento</th>
                                <th class="px-4 py-3 text-left">Fecha Vencimiento</th>
                                <th class="px-4 py-3 text-left">Fecha Subida</th>
                                <th class="px-4 py-3 text-left">Subido Por</th>
                                <th class="px-4 py-3 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documentos as $doc): ?>
                                <tr class="border-b border-cambridge2 hover:bg-parchment">
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($doc['id']); ?></td>
                                    <td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars($doc['marca'] . ' ' . $doc['modelo'] . ' (' . $doc['placas'] . ')'); ?></td>
                                    <td class="px-4 py-3">
                                        <a href="<?php echo htmlspecialchars($doc['ruta_archivo']); ?>" target="_blank" class="inline-block bg-cambridge1 text-white px-3 py-1 rounded text-sm font-semibold hover:bg-cambridge2 transition">
                                            <i class="bi bi-file-earmark"></i> <?php echo htmlspecialchars($doc['nombre_documento']); ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-sm"><?php echo $doc['fecha_vencimiento'] ? date('d/m/Y', strtotime($doc['fecha_vencimiento'])) : 'N/A'; ?></td>
                                    <td class="px-4 py-3 text-sm text-mountbatten"><?php echo date('d/m/Y H:i', strtotime($doc['fecha_subida'])); ?></td>
                                    <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($doc['subido_por_nombre'] ?? 'Desconocido'); ?></td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <button type="button" class="bg-cambridge1 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-cambridge2 transition" data-bs-toggle="modal" data-bs-target="#addEditDocumentModal" data-action="edit"
                                                data-id="<?php echo htmlspecialchars($doc['id']); ?>"
                                                data-vehiculo-id="<?php echo htmlspecialchars($doc['vehiculo_id']); ?>"
                                                data-nombre-documento="<?php echo htmlspecialchars($doc['nombre_documento']); ?>"
                                                data-ruta-archivo="<?php echo htmlspecialchars($doc['ruta_archivo']); ?>"
                                                data-fecha-vencimiento="<?php echo htmlspecialchars($doc['fecha_vencimiento'] ?? ''); ?>">
                                                Editar
                                            </button>
                                            <button type="button" class="bg-red-600 text-white px-2 py-1 rounded text-xs font-semibold hover:bg-red-700 transition" data-bs-toggle="modal" data-bs-target="#deleteDocumentModal" data-id="<?php echo htmlspecialchars($doc['id']); ?>" data-nombre="<?php echo htmlspecialchars($doc['nombre_documento']); ?>" data-placas="<?php echo htmlspecialchars($doc['placas']); ?>">
                                                Eliminar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="modal fade" id="addEditDocumentModal" tabindex="-1" aria-labelledby="addEditDocumentModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addEditDocumentModalLabel"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_documentos.php" method="POST" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="action" id="modalActionDocument">
                            <input type="hidden" name="id" id="documentId">
                            <input type="hidden" name="current_ruta_archivo" id="currentRutaArchivo">

                            <div class="mb-3">
                                <label for="vehiculo_id" class="form-label">Vehículo</label>
                                <select class="form-select" id="vehiculo_id" name="vehiculo_id" required>
                                    <option value="">Selecciona un vehículo...</option>
                                    <?php foreach ($vehiculos_flotilla as $vehiculo_opt): ?>
                                        <option value="<?php echo htmlspecialchars($vehiculo_opt['id']); ?>">
                                            <?php echo htmlspecialchars($vehiculo_opt['marca'] . ' ' . $vehiculo_opt['modelo'] . ' (' . $vehiculo_opt['placas'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="nombre_documento" class="form-label">Nombre del Documento</label>
                                <input type="text" class="form-control" id="nombre_documento" name="nombre_documento" required>
                            </div>
                            <div class="mb-3">
                                <label for="fecha_vencimiento" class="form-label">Fecha de Vencimiento (Opcional)</label>
                                <input type="date" class="form-control" id="fecha_vencimiento" name="fecha_vencimiento">
                            </div>
                            <div class="mb-3">
                                <label for="archivo" class="form-label">Subir Archivo (PDF, JPG, PNG)</label>
                                <input type="file" class="form-control" id="archivo" name="archivo" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="form-text text-muted" id="fileHelpText">Tamaño máximo: <?php echo ini_get('upload_max_filesize'); ?></small>
                            </div>
                            <div id="currentFileDisplay" class="mb-3" style="display: none;">
                                Documento actual: <a id="currentFileLink" href="#" target="_blank"></a>
                                <small class="form-text text-muted"> (Si subes uno nuevo, el anterior será reemplazado).</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" id="submitDocumentBtn"></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteDocumentModal" tabindex="-1" aria-labelledby="deleteDocumentModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteDocumentModalLabel">Confirmar Eliminación</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="gestion_documentos.php" method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteDocumentId">
                        <div class="modal-body">
                            ¿Estás seguro de que quieres eliminar el documento <strong id="deleteDocumentName"></strong> del vehículo con placas <strong id="deleteDocumentPlacas"></strong>?
                            Esta acción eliminará el archivo del servidor y es irreversible.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-danger">Eliminar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="js/main.js"></script>
    <script>
        // JavaScript para manejar los modales de cargar/editar documento
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr("#fecha_vencimiento", {
                dateFormat: "Y-m-d",
                minDate: "today"
            });

            var addEditDocumentModal = document.getElementById('addEditDocumentModal');
            addEditDocumentModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var action = button.getAttribute('data-action');

                var modalTitle = addEditDocumentModal.querySelector('#addEditDocumentModalLabel');
                var modalActionInput = addEditDocumentModal.querySelector('#modalActionDocument');
                var documentIdInput = addEditDocumentModal.querySelector('#documentId');
                var submitBtn = addEditDocumentModal.querySelector('#submitDocumentBtn');
                var fileInput = addEditDocumentModal.querySelector('#archivo');
                var fileHelpText = addEditDocumentModal.querySelector('#fileHelpText');
                var currentFileDisplay = addEditDocumentModal.querySelector('#currentFileDisplay');
                var currentFileLink = addEditDocumentModal.querySelector('#currentFileLink');
                var currentRutaArchivoInput = addEditDocumentModal.querySelector('#currentRutaArchivo');
                var form = addEditDocumentModal.querySelector('form');

                form.reset();
                currentFileDisplay.style.display = 'none';
                fileInput.setAttribute('required', 'required');

                if (action === 'add') {
                    modalTitle.textContent = 'Cargar Nuevo Documento';
                    modalActionInput.value = 'add';
                    submitBtn.textContent = 'Guardar Documento';
                    submitBtn.className = 'btn btn-primary';
                    documentIdInput.value = '';
                    fileHelpText.textContent = 'Tamaño máximo: <?php echo ini_get('upload_max_filesize'); ?>';
                    flatpickr("#fecha_vencimiento").clear();
                } else if (action === 'edit') {
                    modalTitle.textContent = 'Editar Documento';
                    modalActionInput.value = 'edit';
                    submitBtn.textContent = 'Actualizar Documento';
                    submitBtn.className = 'btn btn-info text-white';
                    fileInput.removeAttribute('required');
                    fileHelpText.textContent = 'Deja este campo vacío para mantener el archivo actual.';
                    currentFileDisplay.style.display = 'block';

                    documentIdInput.value = button.getAttribute('data-id');
                    addEditDocumentModal.querySelector('#vehiculo_id').value = button.getAttribute('data-vehiculo-id');
                    addEditDocumentModal.querySelector('#nombre_documento').value = button.getAttribute('data-nombre-documento');
                    addEditDocumentModal.querySelector('#fecha_vencimiento').value = button.getAttribute('data-fecha-vencimiento');
                    currentRutaArchivoInput.value = button.getAttribute('data-ruta-archivo');
                    currentFileLink.href = button.getAttribute('data-ruta-archivo');
                    currentFileLink.textContent = button.getAttribute('data-nombre-documento');

                    if (button.getAttribute('data-fecha-vencimiento')) {
                        flatpickr("#fecha_vencimiento").setDate(button.getAttribute('data-fecha-vencimiento'));
                    } else {
                        flatpickr("#fecha_vencimiento").clear();
                    }
                }
            });

            var deleteDocumentModal = document.getElementById('deleteDocumentModal');
            if (deleteDocumentModal) {
                deleteDocumentModal.addEventListener('show.bs.modal', function(event) {
                    var button = event.relatedTarget;
                    var documentId = button.getAttribute('data-id');
                    var documentName = button.getAttribute('data-nombre');
                    var documentPlacas = button.getAttribute('data-placas');

                    var modalDocumentId = deleteDocumentModal.querySelector('#deleteDocumentId');
                    var modalDocumentName = deleteDocumentModal.querySelector('#deleteDocumentName');
                    var modalDocumentPlacas = deleteDocumentModal.querySelector('#deleteDocumentPlacas');

                    modalDocumentId.value = documentId;
                    modalDocumentName.textContent = documentName;
                    modalDocumentPlacas.textContent = documentPlacas;
                });
            }
        });
    </script>
</body>

</html>