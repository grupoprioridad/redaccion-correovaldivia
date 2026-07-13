<?php
/**
 * Envío de correos usando PHPMailer (heredado del sitio principal)
 */

require_once ROOT_PATH . '/includes/PHPMailer.php';
require_once ROOT_PATH . '/includes/SMTP.php';
require_once ROOT_PATH . '/includes/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envío de correos de marketing/newsletter con headers anti-spam.
 * Incluye List-Unsubscribe, Precedence: bulk, delay configurable.
 *
 * @param  string $to        Dirección del destinatario
 * @param  string $subject   Asunto
 * @param  string $htmlBody  Cuerpo HTML (debe incluir link de desuscripción)
 * @param  string $altBody   Versión texto plano (opcional, se genera auto si vacío)
 * @return bool
 */
function enviarEmailMarketing(string $to, string $subject, string $htmlBody, string $altBody = '', string $unsubUrl = ''): bool {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPOptions = ['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];

        // From / Reply-To
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);

        // Headers anti-spam
        $unsub_mail = 'mailto:' . SMTP_FROM . '?subject=' . rawurlencode('Desuscribirse - El Correo de Valdivia');
        $unsub_url  = $unsubUrl !== '' ? $unsubUrl : 'https://www.elcorreodevaldivia.cl/?desuscribir=1&correo=' . rawurlencode($to);
        $mail->addCustomHeader('List-Unsubscribe', "<$unsub_mail>, <$unsub_url>");
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $mail->addCustomHeader('Precedence', 'bulk');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

        // Contenido
        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Subject  = $subject;
        $mail->Body     = $htmlBody;
        $mail->AltBody  = $altBody ?: html_entity_decode(strip_tags(str_replace(['<br>','<br/>','</p>','</h1>','</h2>','</h3>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8');

        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('enviarEmailMarketing error a ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

function enviarEmail($to, $subject, $htmlBody) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ];
        
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error enviando email: " . $mail->ErrorInfo);
        return false;
    }
}
