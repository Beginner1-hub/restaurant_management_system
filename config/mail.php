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
define('MAIL_PORT',     465);
define('MAIL_USERNAME', 'a5ebcf001@smtp-brevo.com'); // your Brevo SMTP login
define('MAIL_PASSWORD', 'bskPgjmJd6e9MPL');        // <-- paste the full SMTP key here
define('MAIL_FROM',     'sujanadhikari053@gmail.com'); // <-- your actual email (must be verified in Brevo)
define('MAIL_FROM_NAME','Restaurant Reservations');

/**
 * sendEmail($to, $subject, $htmlBody)
 * Returns true on success, false on failure.
 */
/**
 * sendEmail($to, $subject, $htmlBody, $textBody = '')
 * Returns true on success, false on failure.
 */
function sendEmail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    if (empty(trim($to))) {
        error_log("Mailer Error: recipient email is empty");
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->Timeout    = 30;

        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        // Plain-text fallback improves deliverability / avoids spam flags
        $mail->AltBody = $textBody ?: strip_tags(preg_replace('/<style[^>]*>.*?<\/style>/is', '', $htmlBody));

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error to <$to>: " . $mail->ErrorInfo);
        return false;
    }
}
