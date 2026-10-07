<?php
use PHPMailer\PHPMailer\PHPMailer;

/**
 * includes/email_template.php — every outgoing email's content: the shared template, the logo, and the
 * twelve send*Email() functions. This file is tracked by git (unlike config/mailer.php, which holds the
 * real SMTP credentials and is gitignored, so it never reaches production through deploy — see that file's
 * header comment). A content or wording change belongs here, so it actually ships on the next push.
 *
 * config/mailer.php requires this file and must be loaded first: it defines newMailer() (a connected,
 * authenticated PHPMailer instance) and _smtpSetting(), which the functions below depend on.
 */

/** Internal helpers — read company info from DB settings when available. */
function _getCompanyName(): string {
    global $conn;
    return (isset($conn) && function_exists('getSetting'))
        ? getSetting($conn, 'company_name', 'TG Customworks & Basic Car Insurance')
        : 'TG Customworks & Basic Car Insurance';
}
function _getCompanyAddress(): string {
    global $conn;
    return (isset($conn) && function_exists('getSetting'))
        ? getSetting($conn, 'company_address', '49 Villa Tierra St., San Roque, Pandi, Bulacan')
        : '49 Villa Tierra St., San Roque, Pandi, Bulacan';
}

/**
 * Embeds both shop logos as inline (CID) images — not a remote <img src="https://…">, which many mail apps
 * block by default until the person clicks "show images". buildEmailTemplate()'s header references them as
 * cid:tg_logo / cid:basic_logo. The *_email.png files are small (resized copies of the real assets/img logos,
 * which are multi-megabyte originals meant for the web app, not for attaching to every email) — made once
 * with Resize-Logo in PowerShell (System.Drawing), not regenerated on the fly.
 */
function embedEmailLogos(PHPMailer $mail): void {
    $mail->addEmbeddedImage(__DIR__ . '/../assets/img/tg_logo_email.png', 'tg_logo', 'tg_logo.png');
    $mail->addEmbeddedImage(__DIR__ . '/../assets/img/LogoBasicCar_email.png', 'basic_logo', 'basic_logo.png');
}

/**
 * Shared email template builder — table-based layout for maximum email client compatibility.
 * Uses Plus Jakarta Sans (Google Fonts) for consistency with the web app. Colors match assets/css/shared/app.css
 * (--gold: #B8860B, --bg: #F4F1EC, --text-primary: #1A1814, --text-secondary: #5C5648, --text-muted: #9C9286).
 * $actionUrl can be '#' or empty to hide the button/fallback link section. Call embedEmailLogos($mail) before
 * sending, or the two <img src="cid:…"> in the header show as broken images.
 */
function buildEmailTemplate(string $eyebrow, string $heading, string $body, string $actionUrl, string $actionLabel, string $notice): string {
    $font = "'Plus Jakarta Sans','Segoe UI',Arial,Helvetica,sans-serif";

    $buttonHtml = '';
    if ($actionUrl && $actionUrl !== '#') {
        $buttonHtml = '
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;"><tr>
        <td align="center">
          <a href="' . $actionUrl . '" style="font-family:' . $font . ';display:inline-block;background-color:#1A1814;color:#D4A017;text-decoration:none;font-weight:700;font-size:14px;letter-spacing:0.3px;padding:13px 32px;border-radius:6px;">' . $actionLabel . '</a>
        </td>
      </tr></table>
      <p style="font-family:' . $font . ';font-size:11px;color:#A09890;margin:0 0 24px 0;line-height:1.6;word-break:break-all;">
        If the button doesn\'t work, copy and paste this link into your browser:<br>
        <a href="' . $actionUrl . '" style="color:#B8860B;text-decoration:underline;">' . $actionUrl . '</a>
      </p>';
    }

    return '
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background-color:#F4F1EC;font-family:' . $font . ';">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F4F1EC;padding:40px 16px;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background-color:#FFFFFF;border-radius:8px;overflow:hidden;border:1px solid #E0D8CE;">

  <!-- TOP ACCENT -->
  <tr><td style="height:4px;background-color:#B8860B;"></td></tr>

  <!-- HEADER -->
  <tr>
    <td style="padding:28px 36px 22px;background-color:#FFFFFF;border-bottom:1px solid #F0EDE8;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="width:34px;vertical-align:middle;">
          <img src="cid:tg_logo" width="34" height="34" alt="TG Customworks" style="display:block;border-radius:7px;">
        </td>
        <td style="width:9px;vertical-align:middle;">
          <div style="width:1px;height:26px;background-color:#E0D8CE;"></div>
        </td>
        <td style="width:34px;vertical-align:middle;padding-left:9px;">
          <img src="cid:basic_logo" width="34" height="34" alt="Basic Car Insurance" style="display:block;border-radius:7px;">
        </td>
        <td style="padding-left:12px;vertical-align:middle;">
          <div style="font-family:' . $font . ';font-size:16px;font-weight:800;color:#1A1814;line-height:1.1;">TG<span style="color:#B8860B;">-BASICS</span></div>
          <div style="font-family:' . $font . ';font-size:10px;color:#A09890;letter-spacing:0.3px;margin-top:3px;">Brokerage &amp; Auto Shop Integrated Central System</div>
        </td>
      </tr></table>
    </td>
  </tr>

  <!-- BODY -->
  <tr>
    <td style="padding:32px 36px 28px;">

      <!-- Eyebrow -->
      <div style="font-family:' . $font . ';font-size:10px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:#B8860B;margin-bottom:8px;">' . $eyebrow . '</div>

      <!-- Heading -->
      <div style="font-family:' . $font . ';font-size:22px;font-weight:800;color:#1A1814;margin-bottom:6px;line-height:1.3;">' . $heading . '</div>

      <!-- Divider -->
      <div style="height:1px;background-color:#F0EDE8;margin-bottom:20px;margin-top:14px;"></div>

      <!-- Body content -->
      <div style="font-family:' . $font . ';font-size:14px;color:#5C5648;line-height:1.85;margin:0 0 24px 0;">' . $body . '</div>

      ' . $buttonHtml . '

      <!-- Notice box -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>
        <td style="background-color:#FDF8EE;border-left:3px solid #D4A017;border-radius:4px;padding:12px 16px;">
          <p style="font-family:' . $font . ';font-size:12px;color:#8A7A50;line-height:1.7;margin:0;">' . $notice . '</p>
        </td>
      </tr></table>

    </td>
  </tr>

  <!-- FOOTER -->
  <tr>
    <td style="border-top:1px solid #F0EDE8;background-color:#FAFAF8;padding:18px 36px;">
      <p style="font-family:' . $font . ';font-size:11px;color:#B0A898;margin:0;line-height:1.8;text-align:center;">
        <strong style="color:#6B6358;">' . htmlspecialchars(_getCompanyName()) . '</strong><br>
        ' . htmlspecialchars(_getCompanyAddress()) . '
      </p>
    </td>
  </tr>

</table>
</td></tr></table>
</body>
</html>';
}

function sendActivationEmail(string $toEmail, string $toName, string $activationLink): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $expiry = _smtpSetting('activation_link_expiry', '24');

        $mail->Subject = 'Activate Your TG-BASICS Account';
        $mail->Body = buildEmailTemplate(
            'Account Activation',
            'Your account is ready, ' . htmlspecialchars($toName),
            'Your <strong>TG-BASICS</strong> account has been created by the system administrator. Click the button below to activate your account and set up your password.',
            $activationLink,
            'Activate My Account',
            'This activation link expires in <strong>' . $expiry . ' hour' . ($expiry != 1 ? 's' : '') . '</strong>. If you did not expect this email, you can safely ignore it.'
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function send2FACodeEmail(string $toEmail, string $toName, string $code): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $font = "'Plus Jakarta Sans','Segoe UI',Arial,Helvetica,sans-serif";

        $mail->Subject = 'Your TG-BASICS Verification Code';
        $mail->Body = buildEmailTemplate(
            'Two-Factor Authentication',
            'Your verification code',
            'Use the code below to complete your sign-in to <strong>TG-BASICS</strong>.<br><br>
            <div style="text-align:center;margin:8px 0;">
              <span style="font-family:' . $font . ';font-size:32px;font-weight:800;letter-spacing:8px;color:#1A1814;background:#FFF8E7;border:2px solid #E8D5A3;border-radius:12px;padding:12px 28px;display:inline-block;">' . htmlspecialchars($code) . '</span>
            </div>',
            '',
            '',
            'This code expires in <strong>10 minutes</strong>. If you did not attempt to sign in, please change your password immediately.'
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function sendEmailVerificationEmail(string $toEmail, string $toName, string $verifyLink): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $mail->Subject = 'Confirm Your New Email Address - TG-BASICS';
        $mail->Body = buildEmailTemplate(
            'Email Verification',
            'Confirm your new email address',
            'You requested to change your email address on <strong>TG-BASICS</strong> to this address. Click the button below to confirm this change.',
            $verifyLink,
            'Verify Email Address',
            'This link expires in <strong>24 hours</strong>. If you did not request this change, you can safely ignore this email and your email address will remain unchanged.'
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function sendPasswordChangeNotification(string $toEmail, string $toName): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $mail->Subject = 'Your TG-BASICS Password Was Changed';
        $mail->Body = buildEmailTemplate(
            'Security Alert',
            'Your password was changed',
            'Your <strong>TG-BASICS</strong> account password was successfully changed on <strong>' . date('M d, Y \a\t h:i A') . '</strong>.<br><br>If you made this change, no further action is needed. If you did not change your password, please contact your system administrator immediately.',
            '',
            '',
            'If you did not make this change, your account may be compromised. Contact the system administrator right away.'
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Sent to the business owner (visible Super Admin account) whenever the
 * Renewal Tracking vault password is changed. Deliberately includes the
 * new plaintext password — this is a shared operational password the
 * owner must know, not an individual login credential.
 */
function sendVaultPasswordChangeEmail(string $toEmail, string $toName, string $newPassword, string $changedByName): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $font = "'Plus Jakarta Sans','Segoe UI',Arial,Helvetica,sans-serif";

        $mail->Subject = 'Renewal Tracking Vault Password Updated';
        $mail->Body = buildEmailTemplate(
            'Vault Password Changed',
            'Your Renewal Tracking vault password',
            'The <strong>Renewal Tracking</strong> vault password was changed by <strong>' . htmlspecialchars($changedByName) . '</strong>. All Admin accounts have been automatically re-locked and must enter the new password below to regain access.<br><br>
            <div style="text-align:center;margin:8px 0;">
              <span style="font-family:' . $font . ';font-size:26px;font-weight:800;letter-spacing:2px;color:#1A1814;background:#FFF8E7;border:2px solid #E8D5A3;border-radius:12px;padding:12px 28px;display:inline-block;">' . htmlspecialchars($newPassword) . '</span>
            </div>',
            '',
            '',
            'Keep this password confidential. Share it only with Admins you personally authorize to access PhilBritish and Alpha Insurance renewal records.'
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Sent to Admin-role staff whenever the Renewal Tracking vault password
 * changes. Never includes the new password — only the Super Admin
 * (via sendVaultPasswordChangeEmail) receives the plaintext value.
 */
function sendVaultRelockedNotice(string $toEmail, string $toName): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $mail->Subject = 'Renewal Tracking Access Locked — Password Updated';
        $mail->Body = buildEmailTemplate(
            'Security Notice',
            'Renewal Tracking has been re-locked',
            'Dear <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>The Renewal Tracking vault password was just changed. Your access has been automatically locked and you will need to enter the new password the next time you open PhilBritish or Alpha Insurance renewal records.<br><br>Please contact the Super Admin to obtain the new password.',
            '',
            '',
            'This is a routine security measure — no action is needed unless you need renewed access to Renewal Tracking.'
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function sendClaimStatusEmail(string $toEmail, string $toName, string $claimType, string $newStatus, string $policyNumber, string $plateNumber, ?string $denialReason = null): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $status_labels = [
            'document_collection' => 'Document Collection',
            'submitted'           => 'Forwarded to Head Office',
            'under_review'        => 'Under Adjuster Review',
            'approved'            => 'Approved',
            'denied'              => 'Denied',
            'resolved'            => 'Resolved',
        ];
        $status_label = $status_labels[$newStatus] ?? ucfirst($newStatus);
        $claim_label  = $claimType === 'repair' ? 'Repair Claim' : 'Cash Claim';

        $body = 'Dear <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>'
              . 'Your <strong>' . $claim_label . '</strong> has a new status update.<br><br>'
              . '<strong>Policy No.:</strong> ' . htmlspecialchars($policyNumber) . '<br>'
              . '<strong>Vehicle:</strong> ' . htmlspecialchars($plateNumber) . '<br>'
              . '<strong>Claim Type:</strong> ' . $claim_label . '<br>'
              . '<strong>New Status:</strong> ' . $status_label . '<br>';

        if ($newStatus === 'denied' && $denialReason) {
            $body .= '<br><strong>Reason for Denial:</strong> ' . htmlspecialchars($denialReason) . '<br>';
        }

        if ($newStatus === 'approved') {
            $body .= '<br>Congratulations! Your claim has been <strong>approved</strong>. Our team will be in touch with you shortly regarding the next steps.';
        } elseif ($newStatus === 'resolved') {
            $body .= '<br>Your claim has been <strong>fully resolved</strong>. Thank you for your patience throughout this process.';
        } elseif ($newStatus === 'denied') {
            $body .= '<br>We regret to inform you that your claim has been <strong>denied</strong>. Please visit our office if you have questions or wish to appeal.';
        } else {
            $body .= '<br>Our team is processing your claim. We will notify you of any further updates.';
        }

        $notice = 'For inquiries, please visit our office or contact us directly. Do not reply to this email.';

        $mail->Subject = '=?UTF-8?B?' . base64_encode('Claim Update - ' . $status_label . ' | ' . _getCompanyName()) . '?=';
        $mail->Body    = buildEmailTemplate(
            'Claims Notification',
            'Your claim status has been updated',
            $body,
            '#',
            '',
            $notice
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function sendReceiptEmail(
    string $toEmail,
    string $toName,
    string $receiptNumber,
    string $quotationNumber,
    string $jobNumber,
    string $plateNumber,
    string $vehicle,
    string $serviceType,
    float  $total,
    float  $amountPaid,
    float  $balance,
    string $paymentMethod,
    string $issuedAt,
    array  $items
): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $font = "'Plus Jakarta Sans','Segoe UI',Arial,Helvetica,sans-serif";

        $pay_methods = ['cash' => 'Cash', 'e_wallet' => 'E-Wallet', 'bank_transfer' => 'Bank Transfer'];
        $pay_label   = $pay_methods[$paymentMethod] ?? $paymentMethod;

        // Build items rows
        $item_rows = '';
        foreach ($items as $it) {
            $item_rows .= '
            <tr>
              <td style="font-family:' . $font . ';font-size:13px;color:#3C3830;padding:8px 10px;border-bottom:1px solid #F0EDE8;">' . htmlspecialchars($it['description']) . '</td>
              <td style="font-family:' . $font . ';font-size:13px;color:#9C9286;padding:8px 10px;border-bottom:1px solid #F0EDE8;text-align:center;">' . number_format($it['qty'], 2) . '</td>
              <td style="font-family:' . $font . ';font-size:13px;color:#3C3830;padding:8px 10px;border-bottom:1px solid #F0EDE8;text-align:right;">PHP ' . number_format($it['unit_price'], 2) . '</td>
              <td style="font-family:' . $font . ';font-size:13px;font-weight:700;color:#1A1814;padding:8px 10px;border-bottom:1px solid #F0EDE8;text-align:right;">PHP ' . number_format($it['subtotal'], 2) . '</td>
            </tr>';
        }

        $balance_color = $balance > 0 ? '#B8860B' : '#2E7D52';
        $balance_label = $balance > 0 ? 'PHP ' . number_format($balance, 2) . ' remaining' : 'Fully paid';

        $body = '
        Dear <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>
        Your repair job at <strong>' . htmlspecialchars(_getCompanyName()) . '</strong> has been processed and a billing statement has been issued. Please see the details below.<br><br>

        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:16px;">
          <tr>
            <td style="font-family:' . $font . ';font-size:12px;color:#9C9286;width:130px;">Statement #</td>
            <td style="font-family:' . $font . ';font-size:13px;font-weight:700;color:#1A1814;">' . htmlspecialchars($receiptNumber) . '</td>
          </tr>
          <tr>
            <td style="font-family:' . $font . ';font-size:12px;color:#9C9286;padding-top:4px;">Job #</td>
            <td style="font-family:' . $font . ';font-size:13px;color:#3C3830;padding-top:4px;">' . htmlspecialchars($jobNumber) . '</td>
          </tr>
          <tr>
            <td style="font-family:' . $font . ';font-size:12px;color:#9C9286;padding-top:4px;">Vehicle</td>
            <td style="font-family:' . $font . ';font-size:13px;color:#3C3830;padding-top:4px;">' . htmlspecialchars($vehicle) . ' (' . htmlspecialchars($plateNumber) . ')</td>
          </tr>
          <tr>
            <td style="font-family:' . $font . ';font-size:12px;color:#9C9286;padding-top:4px;">Service</td>
            <td style="font-family:' . $font . ';font-size:13px;color:#3C3830;padding-top:4px;">' . htmlspecialchars($serviceType) . '</td>
          </tr>
          <tr>
            <td style="font-family:' . $font . ';font-size:12px;color:#9C9286;padding-top:4px;">Date Issued</td>
            <td style="font-family:' . $font . ';font-size:13px;color:#3C3830;padding-top:4px;">' . htmlspecialchars($issuedAt) . '</td>
          </tr>
        </table>

        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #E2D9CC;border-radius:10px;overflow:hidden;margin-bottom:16px;">
          <tr style="background:#F4F1EC;">
            <th style="font-family:' . $font . ';font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9C9286;padding:7px 10px;text-align:left;">Description</th>
            <th style="font-family:' . $font . ';font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9C9286;padding:7px 10px;text-align:center;">Qty</th>
            <th style="font-family:' . $font . ';font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9C9286;padding:7px 10px;text-align:right;">Unit Price</th>
            <th style="font-family:' . $font . ';font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9C9286;padding:7px 10px;text-align:right;">Amount</th>
          </tr>
          ' . $item_rows . '
        </table>

        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:16px;">
          <tr>
            <td style="font-family:' . $font . ';font-size:13px;font-weight:800;color:#1A1814;padding-top:4px;">TOTAL</td>
            <td style="font-family:' . $font . ';font-size:14px;font-weight:800;color:#1A1814;text-align:right;">PHP ' . number_format($total, 2) . '</td>
          </tr>
          <tr>
            <td style="font-family:' . $font . ';font-size:12px;color:#9C9286;padding-top:4px;">Amount Paid</td>
            <td style="font-family:' . $font . ';font-size:13px;font-weight:700;color:#2E7D52;text-align:right;">PHP ' . number_format($amountPaid, 2) . '</td>
          </tr>
          <tr>
            <td style="font-family:' . $font . ';font-size:12px;color:#9C9286;padding-top:4px;">Balance</td>
            <td style="font-family:' . $font . ';font-size:13px;font-weight:700;color:' . $balance_color . ';text-align:right;">' . $balance_label . '</td>
          </tr>
          <tr>
            <td style="font-family:' . $font . ';font-size:12px;color:#9C9286;padding-top:4px;">Payment Method</td>
            <td style="font-family:' . $font . ';font-size:13px;color:#3C3830;text-align:right;">' . htmlspecialchars($pay_label) . '</td>
          </tr>
        </table>';

        $notice = 'If you have questions about this billing statement, please visit our office or contact us directly. Do not reply to this email.';

        $mail->Subject = '=?UTF-8?B?' . base64_encode('Billing Statement ' . $receiptNumber . ' — ' . _getCompanyName()) . '?=';
        $mail->Body    = buildEmailTemplate(
            'Billing Statement',
            'Your billing statement is ready, ' . htmlspecialchars($toName),
            $body,
            '',
            '',
            $notice
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function sendPasswordResetEmail(string $toEmail, string $toName, string $resetLink): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $expiry = _smtpSetting('reset_link_expiry', '1');

        $mail->Subject = 'Reset Your TG-BASICS Password';
        $mail->Body = buildEmailTemplate(
            'Password Reset',
            'Reset your password, ' . htmlspecialchars($toName),
            'We received a request to reset your <strong>TG-BASICS</strong> password. Click the button below to set a new password. If you did not make this request, you can safely ignore this email.',
            $resetLink,
            'Reset My Password',
            'This link expires in <strong>' . $expiry . ' hour' . ($expiry != 1 ? 's' : '') . '</strong>. If you did not request a password reset, no action is needed.'
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function sendRepairCompletedEmail(
    string $toEmail,
    string $toName,
    string $jobNumber,
    string $plateNumber,
    string $vehicle,
    string $serviceType,
    ?string $releaseDate,
    string $publicProfileUrl
): bool {
    try {
        $mail = newMailer();
        $mail->addAddress($toEmail, $toName);
        embedEmailLogos($mail);

        $release_line = $releaseDate
            ? '<strong>Release Date:</strong> ' . htmlspecialchars(date('F d, Y', strtotime($releaseDate))) . '<br>'
            : '';

        $body = 'Dear <strong>' . htmlspecialchars($toName) . '</strong>,<br><br>'
              . 'Great news! Your vehicle is now ready for pickup at '
              . '<strong>' . htmlspecialchars(_getCompanyName()) . '</strong>.<br><br>'
              . '<strong>Job #:</strong> ' . htmlspecialchars($jobNumber) . '<br>'
              . '<strong>Vehicle:</strong> ' . htmlspecialchars($vehicle) . ' (' . htmlspecialchars($plateNumber) . ')<br>'
              . '<strong>Service:</strong> ' . htmlspecialchars($serviceType) . '<br>'
              . $release_line
              . '<br>Please visit our shop at your earliest convenience to pick up your vehicle.<br><br>'
              . 'You can also view your full client profile and repair status anytime by scanning the QR code at our shop or visiting the link below.';

        $notice = 'For inquiries, please visit our office or contact us directly. Do not reply to this email.';

        $mail->Subject = '=?UTF-8?B?' . base64_encode('Your Vehicle is Ready for Pickup — ' . $jobNumber) . '?=';
        $mail->Body    = buildEmailTemplate(
            'Repair Complete',
            'Your vehicle is ready for pickup!',
            $body,
            $publicProfileUrl,
            'View My Client Profile',
            $notice
        );

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Send claim requirements status email to the admin (Jean Paolo).
 * Lists which docs are received and which are still pending.
 *
 * $docStatus = [['label' => 'Policy', 'received' => true], ...]
 */
function sendClaimRequirementsEmail(
    array  $toEmails,
    string $clientName,
    string $policyNumber,
    string $plateNumber,
    string $claimType,
    string $incidentDate,
    array  $docStatus,
    ?string $notes = null,
    array $attachments = []  // [ ['path' => '/abs/path/file.jpg', 'name' => 'Policy.jpg'], ... ]
): bool {
    try {
        $mail = newMailer();
        foreach ($toEmails as $toEmail) {
            $mail->addAddress($toEmail);
        }
        embedEmailLogos($mail);

        $font = "'Plus Jakarta Sans','Segoe UI',Arial,Helvetica,sans-serif";

        $received_count = count(array_filter($docStatus, fn($d) => $d['received']));
        $missing_count  = count($docStatus) - $received_count;

        $doc_rows = '';
        foreach ($docStatus as $doc) {
            $color  = $doc['received'] ? '#1a7a4a' : '#c0392b';
            $ico    = $doc['received'] ? '&#10003;' : '&#10007;';
            $rlabel = $doc['received'] ? 'Received' : 'Pending';
            $doc_rows .= '
            <tr>
              <td style="font-family:' . $font . ';font-size:13px;color:#3C3830;padding:8px 12px;border-bottom:1px solid #F0EDE8;">' . htmlspecialchars($doc['label']) . '</td>
              <td style="font-family:' . $font . ';font-size:13px;font-weight:700;color:' . $color . ';padding:8px 12px;border-bottom:1px solid #F0EDE8;text-align:center;">' . $ico . ' ' . $rlabel . '</td>
            </tr>';
        }

        $notes_block = $notes
            ? '<br><strong>Notes:</strong><br><em>' . nl2br(htmlspecialchars($notes)) . '</em><br>'
            : '';

        $status_line = $missing_count > 0
            ? '<br>' . $missing_count . ' item(s) still pending follow-up.'
            : '<br><strong style="color:#1a7a4a;">All requirements are complete.</strong>';

        $body = '
        A claim requirements update has been recorded in <strong>TG-BASICS</strong>. Please review the current status below.<br><br>
        <strong>Client:</strong> ' . htmlspecialchars($clientName) . '<br>
        <strong>Policy No.:</strong> ' . htmlspecialchars($policyNumber) . '<br>
        <strong>Vehicle:</strong> ' . htmlspecialchars($plateNumber) . '<br>
        <strong>Claim Type:</strong> ' . htmlspecialchars(ucfirst($claimType)) . '<br>
        <strong>Incident Date:</strong> ' . htmlspecialchars($incidentDate) . '<br>'
        . $notes_block . '
        <br>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #E2D9CC;border-radius:10px;overflow:hidden;margin-bottom:8px;">
          <tr style="background:#F4F1EC;">
            <th style="font-family:' . $font . ';font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9C9286;padding:8px 12px;text-align:left;">Requirement</th>
            <th style="font-family:' . $font . ';font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9C9286;padding:8px 12px;text-align:center;">Status</th>
          </tr>
          ' . $doc_rows . '
        </table>
        <br>
        <strong>' . $received_count . ' of ' . count($docStatus) . ' requirements received.</strong>'
        . $status_line;

        $notice = 'This is an internal notification from TG-BASICS. Log in to the system to view the full claim record and attached files.';

        $mail->Subject = '=?UTF-8?B?' . base64_encode('Claim Requirements — ' . $clientName . ' | ' . $policyNumber) . '?=';
        $mail->Body    = buildEmailTemplate(
            'Insurance Claim Requirements',
            'Requirements Update: ' . htmlspecialchars($clientName),
            $body,
            '',
            '',
            $notice
        );

        // Attach received documents
        foreach ($attachments as $att) {
            if (!empty($att['path']) && file_exists($att['path'])) {
                $mail->addAttachment($att['path'], $att['name']);
            }
        }

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
