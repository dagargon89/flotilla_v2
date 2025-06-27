<?php
// app/config/mail.php

// Configuración para el envío de correos usando PHPMailer

define('MAIL_HOST', 'smtp.gmail.com');    // Tu servidor SMTP (ej. 'smtp.gmail.com' para Gmail)
define('MAIL_USERNAME', 'dgarcia@planjuarez.org'); // Tu dirección de correo
define('MAIL_PASSWORD', 'Yamivictoria891220*'); // Tu contraseña de correo
define('MAIL_SMTPSECURE', PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS); // Usar SMTPS (465)
// O para STARTTLS (587) usar: PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
define('MAIL_PORT', 465);                   // Puerto SMTP (465 para SMTPS, 587 para STARTTLS)
define('MAIL_FROM_EMAIL', 'dgarcia@planjuarez.org'); // Correo que aparecerá como remitente
define('MAIL_FROM_NAME', 'Sistema de Flotilla Interna');     // Nombre que aparecerá como remitente
define('MAIL_SMTP_AUTH', true);             // Requiere autenticación SMTP
define('MAIL_DEBUG', 2);                    // Nivel de debug: 0 = off, 1 = client, 2 = client and server

// Importar clases de PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Función para enviar correos
function sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText = '')
{
    $mail = new PHPMailer(true); // Pasar 'true' habilita las excepciones
    try {
        // Configuración del servidor
        $mail->SMTPDebug = MAIL_DEBUG;
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = MAIL_SMTP_AUTH;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_SMTPSECURE;
        $mail->Port = MAIL_PORT;
        $mail->CharSet = 'UTF-8'; // Para caracteres especiales como Ñ o acentos

        // Remitentes
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName); // Añadir destinatario

        // Contenido
        $mail->isHTML(true); // Establecer formato de correo a HTML
        $mail->Subject = $subject;
        $mail->Body = $bodyHtml;
        $mail->AltBody = $bodyText; // Texto plano para clientes de correo sin HTML

        $mail->send();
        error_log("Correo enviado a: " . $toEmail . " - Asunto: " . $subject);
        return true;
    } catch (Exception $e) {
        error_log("Error al enviar correo a " . $toEmail . ": " . $mail->ErrorInfo . " | " . $e->getMessage());
        return false;
    }
}
