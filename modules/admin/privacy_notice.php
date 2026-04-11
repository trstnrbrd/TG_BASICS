<?php
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$page_title  = 'Privacy Notice';
$active_page = '';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Privacy Notice';
$topbar_breadcrumb = ['System', 'Privacy Notice'];
require_once '../../includes/topbar.php';
?>

  <div class="content" style="max-width:820px;margin:0 auto;">

    <div class="card">
      <div class="card-header">
        <div class="card-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </div>
        <div>
          <div class="card-title">Privacy Notice</div>
          <div class="card-sub">In accordance with Republic Act No. 10173 — Data Privacy Act of 2012</div>
        </div>
      </div>

      <div style="padding:2rem 2.25rem;display:flex;flex-direction:column;gap:1.75rem;line-height:1.8;font-size:0.85rem;color:var(--text-secondary);">

        <!-- Effective Date -->
        <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:10px;padding:1rem 1.25rem;font-size:0.78rem;color:var(--text-muted);">
          <strong style="color:var(--text-primary);">Effective Date:</strong> April 2026 &nbsp;&bull;&nbsp;
          <strong style="color:var(--text-primary);">System:</strong> TG-BASICS Management System &nbsp;&bull;&nbsp;
          <strong style="color:var(--text-primary);">Operator:</strong> TG Customworks &amp; Basic Car Insurance
        </div>

        <!-- Section 1 -->
        <div>
          <div style="font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:0.6rem;">1. Purpose of Data Collection</div>
          <p>TG-BASICS collects and processes personal information solely for the purpose of managing client insurance policies, vehicle records, claims processing, and repair job coordination. All data collected is used exclusively for internal business operations of TG Customworks &amp; Basic Car Insurance.</p>
        </div>

        <!-- Section 2 -->
        <div>
          <div style="font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:0.6rem;">2. Data Collected</div>
          <p>The system collects and stores the following personal data:</p>
          <ul style="margin-top:0.6rem;margin-left:1.5rem;display:flex;flex-direction:column;gap:0.35rem;">
            <li>Full name, contact number, and email address of clients</li>
            <li>Home or billing address</li>
            <li>Vehicle information (plate number, make, model, year, chassis and engine numbers)</li>
            <li>Insurance policy details (policy number, coverage type, premium, payment status)</li>
            <li>Claims documentation and incident details</li>
            <li>System user credentials (username, hashed password, role)</li>
            <li>Activity and audit logs (actions performed within the system)</li>
          </ul>
        </div>

        <!-- Section 3 -->
        <div>
          <div style="font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:0.6rem;">3. Legal Basis</div>
          <p>Data processing is carried out in compliance with <strong style="color:var(--text-primary);">Republic Act No. 10173</strong>, otherwise known as the <strong style="color:var(--text-primary);">Data Privacy Act of 2012</strong>, and its Implementing Rules and Regulations. The processing of personal data is based on the legitimate interests of the business and the performance of a contract with the data subject (insurance policy agreement).</p>
        </div>

        <!-- Section 4 -->
        <div>
          <div style="font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:0.6rem;">4. Data Retention</div>
          <p>Personal data is retained for as long as the client relationship is active and for a minimum of <strong style="color:var(--text-primary);">five (5) years</strong> after the last transaction, in accordance with applicable insurance regulations and the Data Privacy Act. Data may be retained longer if required by law or for legitimate business purposes.</p>
        </div>

        <!-- Section 5 -->
        <div>
          <div style="font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:0.6rem;">5. Access and Security</div>
          <p>Access to personal data within TG-BASICS is strictly role-based. Only authorized personnel (Super Admin, Admin) may view and manage client records. All passwords are encrypted using industry-standard hashing. System activity is logged through an audit trail to ensure accountability and traceability of all data operations.</p>
        </div>

        <!-- Section 6 -->
        <div>
          <div style="font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:0.6rem;">6. Data Subject Rights</div>
          <p>Under RA 10173, clients and data subjects have the right to:</p>
          <ul style="margin-top:0.6rem;margin-left:1.5rem;display:flex;flex-direction:column;gap:0.35rem;">
            <li><strong style="color:var(--text-primary);">Be informed</strong> — know what data is collected and how it is used</li>
            <li><strong style="color:var(--text-primary);">Access</strong> — request a copy of their personal data held by the system</li>
            <li><strong style="color:var(--text-primary);">Rectification</strong> — request correction of inaccurate or outdated information</li>
            <li><strong style="color:var(--text-primary);">Erasure</strong> — request deletion of data when no longer necessary, subject to legal retention requirements</li>
            <li><strong style="color:var(--text-primary);">Object</strong> — object to the processing of personal data in certain circumstances</li>
          </ul>
        </div>

        <!-- Section 7 -->
        <div>
          <div style="font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:0.6rem;">7. Data Sharing</div>
          <p>Personal data collected through TG-BASICS is <strong style="color:var(--text-primary);">not shared, sold, or disclosed</strong> to third parties except when required by law, or when necessary for the processing of insurance claims with the insuring company (PhilBritish Insurance Corporation).</p>
        </div>

        <!-- Section 8 -->
        <div>
          <div style="font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:0.6rem;">8. Contact</div>
          <p>For privacy-related concerns, requests, or inquiries, please contact the system administrator or the business owner directly at the TG Customworks &amp; Basic Car Insurance office.</p>
        </div>

        <!-- Footer note -->
        <div style="border-top:1px solid var(--border);padding-top:1.25rem;font-size:0.72rem;color:var(--text-muted);text-align:center;">
          This Privacy Notice is an internal document of TG-BASICS and applies to all authorized users of the system. &mdash; TG Customworks &amp; Basic Car Insurance &copy; 2026
        </div>

      </div>
    </div>

    <div style="text-align:center;margin-top:0.5rem;margin-bottom:2rem;">
      <a href="javascript:history.back()" class="btn-ghost" style="font-size:0.78rem;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </a>
    </div>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
