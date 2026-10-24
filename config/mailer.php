<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/src/Exception.php';
require_once __DIR__ . '/../vendor/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/src/SMTP.php';
require_once __DIR__ . '/settings.php';

// SMTP connection only. Credentials come from system_settings (Settings page), never from this file.
// Email content (template, logo, send*Email() functions) lives in includes/email_template.php.

/** Read an SMTP-related setting from the DB settings table, with a default when there is no DB connection yet. */
function _smtpSetting(string $key, string $default = ''): string {
    global $conn;
    return (isset($conn) && function_exists('getSetting'))
        ? getSetting($conn, $key, $default)
        : $default;
}

/** A connected, authenticated PHPMailer instance, ready for addAddress() + Subject + Body. */
function newMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = _smtpSetting('smtp_host', 'smtp.gmail.com');
    $mail->SMTPAuth   = true;
    $mail->Username   = _smtpSetting('smtp_username');
    $mail->Password   = _smtpSetting('smtp_password');
    $mail->SMTPSecure = _smtpSetting('smtp_encryption', 'tls') === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)_smtpSetting('smtp_port', '587');
    $mail->Timeout    = 10;

    $mail->setFrom(
        _smtpSetting('smtp_sender_email'),
        _smtpSetting('smtp_sender_name', 'TG-BASICS System')
    );

    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);
    return $mail;
}

require_once __DIR__ . '/../includes/email_template.php';
