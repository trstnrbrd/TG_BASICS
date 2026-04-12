<?php
/**
 * config/mailer.php — Email / SMTP configuration
 *
 * SETUP: Copy this file to config/mailer.php and fill in your values.
 *        config/mailer.php is listed in .gitignore and will never be committed.
 *
 * SMTP credentials are stored in the database (system_settings table) and
 * managed through the Settings page. This file only contains PHPMailer setup
 * and shared email template functions — no hardcoded credentials.
 *
 * Required system_settings keys:
 *   smtp_host          — e.g. smtp.gmail.com
 *   smtp_port          — e.g. 587
 *   smtp_username      — your Gmail/SMTP email address
 *   smtp_password      — your app password (NOT your account password)
 *   smtp_encryption    — tls or ssl
 *   smtp_sender_email  — from address shown in emails
 *   smtp_sender_name   — display name (e.g. TG-BASICS System)
 *
 * For Gmail: generate an App Password at https://myaccount.google.com/apppasswords
 * (requires 2-Step Verification enabled on your Google account)
 */

// This file is intentionally left as a template.
// The actual mailer functions are defined in your local config/mailer.php.
// Copy this file to config/mailer.php and ensure the system_settings table
// has the SMTP keys populated via the Settings page in TG-BASICS.
