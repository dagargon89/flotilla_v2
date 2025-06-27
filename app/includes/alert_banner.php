<?php
// app/includes/alert_banner.php
// Este archivo muestra una alerta si el usuario está 'amonestado' o 'suspendido'.
// Requiere que las variables $current_user_estatus_usuario, $current_user_amonestaciones_count
// y $current_user_recent_amonestaciones_text estén definidas en la página que lo incluye.

if (isset($current_user_estatus_usuario) && ($current_user_estatus_usuario === 'amonestado' || $current_user_estatus_usuario === 'suspendido')):
    $alert_class = 'alert-warning';
    $alert_icon = '⚠️'; // Icono de advertencia
    $alert_title = '¡ATENCIÓN!';
    $alert_message = '';

    if ($current_user_estatus_usuario === 'amonestado') {
        $alert_class = 'alert-warning';
        $alert_icon = '⚠️';
        $alert_title = '¡Usuario AMONESTADO!';
        $alert_message = 'Tu cuenta ha sido amonestada. Tienes ' . htmlspecialchars($current_user_amonestaciones_count) . ' amonestación(es) registrada(s).';
        if ($current_user_amonestaciones_count > 0 && !empty($current_user_recent_amonestaciones_text)) {
            $alert_message .= ' Amonestaciones recientes: ' . htmlspecialchars($current_user_recent_amonestaciones_text) . '.';
        }
        $alert_message .= ' Continúa con el buen uso de los vehículos para evitar suspensiones.';
    } elseif ($current_user_estatus_usuario === 'suspendido') {
        $alert_class = 'alert-danger';
        $alert_icon = '🚫'; // Icono de prohibición
        $alert_title = '¡CUENTA SUSPENDIDA!';
        $alert_message = 'Tu cuenta está SUSPENDIDA y NO puedes solicitar ni utilizar vehículos. Contacta al administrador para más detalles.';
    }
?>
    <div class="container mt-3">
        <div class="alert <?php echo $alert_class; ?> d-flex align-items-center" role="alert">
            <span class="fs-4 me-3"><?php echo $alert_icon; ?></span>
            <div>
                <h4 class="alert-heading"><?php echo $alert_title; ?></h4>
                <p class="mb-0"><?php echo $alert_message; ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>