<?php
// app/includes/alert_banner.php
// Este archivo muestra una alerta si el usuario está 'amonestado' o 'suspendido'.
// Requiere que las variables $current_user_estatus_usuario, $current_user_amonestaciones_count
// y $current_user_recent_amonestaciones_text estén definidas en la página que lo incluye.

if (isset($current_user_estatus_usuario) && ($current_user_estatus_usuario === 'amonestado' || $current_user_estatus_usuario === 'suspendido')):
    $alert_class = 'bg-yellow-100 border-yellow-400 text-yellow-800';
    $alert_icon = '⚠️'; // Icono de advertencia
    $alert_title = '¡ATENCIÓN!';
    $alert_message = '';

    if ($current_user_estatus_usuario === 'amonestado') {
        $alert_class = 'bg-yellow-100 border-yellow-400 text-yellow-800';
        $alert_icon = '⚠️';
        $alert_title = '¡Usuario AMONESTADO!';
        $alert_message = 'Tu cuenta ha sido amonestada. Tienes ' . htmlspecialchars($current_user_amonestaciones_count) . ' amonestación(es) registrada(s).';
        if ($current_user_amonestaciones_count > 0 && !empty($current_user_recent_amonestaciones_text)) {
            $alert_message .= ' Amonestaciones recientes: ' . htmlspecialchars($current_user_recent_amonestaciones_text) . '.';
        }
        $alert_message .= ' Continúa con el buen uso de los vehículos para evitar suspensiones.';
    } elseif ($current_user_estatus_usuario === 'suspendido') {
        $alert_class = 'bg-red-100 border-red-400 text-red-800';
        $alert_icon = '🚫'; // Icono de prohibición
        $alert_title = '¡CUENTA SUSPENDIDA!';
        $alert_message = 'Tu cuenta está SUSPENDIDA y NO puedes solicitar ni utilizar vehículos. Contacta al administrador para más detalles.';
    }
?>
    <div class="max-w-3xl mx-auto mt-3">
        <div class="flex items-center border-l-4 p-4 rounded shadow <?php echo $alert_class; ?>">
            <span class="text-2xl mr-4"><?php echo $alert_icon; ?></span>
            <div>
                <div class="font-bold text-lg mb-1"><?php echo $alert_title; ?></div>
                <div class="text-sm"><?php echo $alert_message; ?></div>
            </div>
        </div>
    </div>
<?php endif; ?>