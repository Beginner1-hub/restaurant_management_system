<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/* ── SMTP SETTINGS (Brevo — free real email delivery) ────────────
 *  1. Sign up free at https://brevo.com
 *  2. Go to Settings → SMTP & API → Generate SMTP Key
 *  3. Paste your login email and the generated SMTP key below.
 *  Free plan: 300 real emails/day, no credit card needed.
 * ──────────────────────────────────────────────────────────────── */
define('MAIL_HOST',     'smtp-relay.brevo.com');
define('MAIL_PORT',     587);
define('MAIL_USERNAME', 'a5ebcf001@smtp-brevo.com'); // your Brevo SMTP login
define('MAIL_PASSWORD', 'bskXy2ueYgL3Npu');        // <-- paste the full SMTP key here
define('MAIL_FROM',     'sujanadhikari053@gmail.com'); // <-- your actual email (must be verified in Brevo)
define('MAIL_FROM_NAME','Restaurant Reservations');

/**
 * sendEmail($to, $subject, $htmlBody)
 * Returns true on success, false on failure.
 */
function sendEmail(string $to, string $subject, string $htmlBody): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>
