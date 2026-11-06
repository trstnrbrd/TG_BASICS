<?php
// Privacy Notice / Terms / Disclaimer modal of the landing page (index.php), shown on a first visit and from the footer.
// Uses the insurer phrases index.php sets ($ins_legal_or, $ins_legal, $ins_first, $ins_and).
?>
<!-- LEGAL MODAL (Privacy + Terms tabs) -->
<style>
  #privacy-modal { display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0);backdrop-filter:blur(0px);align-items:center;justify-content:center;padding:1.5rem;transition:background 0.3s,backdrop-filter 0.3s; }
  #privacy-modal.show { display:flex;background:rgba(0,0,0,0.65);backdrop-filter:blur(6px); }
  #privacy-modal-box { background:#1C1A17;border:1px solid rgba(212,160,23,0.25);border-radius:18px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,0.6),0 0 0 1px rgba(212,160,23,0.05);width:100%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;transform:translateY(32px) scale(0.97);opacity:0;transition:transform 0.35s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s ease; }
  #privacy-modal.show #privacy-modal-box { transform:translateY(0) scale(1);opacity:1; }
  #privacy-modal-body::-webkit-scrollbar { width:3px; }
  #privacy-modal-body::-webkit-scrollbar-thumb { background:rgba(212,160,23,0.3);border-radius:2px; }
  .legal-tab-btn { flex:1;padding:0.65rem 1rem;background:none;border:none;font-family:inherit;font-size:0.78rem;font-weight:700;color:#7A7268;cursor:pointer;border-bottom:2px solid transparent;transition:all 0.15s;letter-spacing:0.2px; }
  .legal-tab-btn.active { color:#D4A017;border-bottom-color:#D4A017; }
  .legal-tab-btn:hover:not(.active) { color:#B8B0A4; }
  .footer-legal-link { display:inline-flex;align-items:center;gap:0.4rem;font-size:0.72rem;color:rgba(200,192,176,0.5);text-decoration:none;transition:color 0.15s;font-family:inherit; }
  .footer-legal-link:hover { color:#D4A017; }
</style>

<div id="privacy-modal">
  <div id="privacy-modal-box">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.75rem;border-bottom:1px solid rgba(255,255,255,0.07);background:rgba(212,160,23,0.06);flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:0.85rem;">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,#D4A017,#B8860B);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 12px rgba(184,134,11,0.35);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div>
          <div style="font-size:0.95rem;font-weight:800;color:#E8E2D8;letter-spacing:-0.3px;">Legal Documents</div>
          <div style="font-size:0.67rem;color:#7A7268;margin-top:0.1rem;">TG Customworks &amp; Basic Car Insurance &mdash; TG-BASICS</div>
        </div>
      </div>
      <button id="close-privacy-modal" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);color:#7A7268;cursor:pointer;padding:0.4rem;border-radius:8px;transition:all 0.15s;line-height:1;display:flex;" onmouseover="this.style.background='rgba(255,255,255,0.1)';this.style.color='#E8E2D8'" onmouseout="this.style.background='rgba(255,255,255,0.05)';this.style.color='#7A7268'">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div style="display:flex;border-bottom:1px solid rgba(255,255,255,0.07);background:rgba(0,0,0,0.2);flex-shrink:0;">
      <button class="legal-tab-btn active" data-tab="privacy">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:0.35rem;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Privacy Notice
      </button>
      <button class="legal-tab-btn" data-tab="terms">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:0.35rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Terms &amp; Conditions
      </button>
      <button class="legal-tab-btn" data-tab="disclaimer">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:0.35rem;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Disclaimer
      </button>
    </div>
    <div id="privacy-modal-body" style="padding:1.5rem 1.75rem;display:flex;flex-direction:column;gap:1.1rem;font-size:0.82rem;line-height:1.8;color:#B8B0A4;overflow-y:auto;">
      <div id="tab-privacy">
        <div style="background:rgba(212,160,23,0.07);border:1px solid rgba(212,160,23,0.15);border-radius:9px;padding:0.85rem 1.1rem;font-size:0.74rem;color:#9C9286;margin-bottom:1.1rem;">
          <strong style="color:#E8E2D8;">Effective Date:</strong> April 2026 &nbsp;&bull;&nbsp;
          <strong style="color:#E8E2D8;">Legal Basis:</strong> RA 10173 — Data Privacy Act of 2012
        </div>
        <?php
        $privacy_sections = [
          ['Purpose', 'TG-BASICS collects and processes personal information solely for managing client insurance policies, vehicle records, claims processing, and repair job coordination — exclusively for the internal business operations of TG Customworks &amp; Basic Car Insurance.'],
          ['Data Collected', '<ul style="margin-top:0.4rem;margin-left:1.25rem;display:flex;flex-direction:column;gap:0.25rem;"><li>Full name, contact number, and email address</li><li>Home or billing address</li><li>Vehicle information (plate number, make, model, chassis and engine numbers)</li><li>Insurance policy details and payment status</li><li>Claims documentation and incident details</li><li>System user credentials (hashed) and audit logs</li></ul>'],
          ['Legal Basis', 'Processing is carried out in compliance with <strong style="color:#E8E2D8;">Republic Act No. 10173 (Data Privacy Act of 2012)</strong>. Collection is based on the legitimate interests of the business and the performance of an insurance policy agreement with the data subject.'],
          ['Data Retention', 'Personal data is retained for a minimum of <strong style="color:#E8E2D8;">five (5) years</strong> after the last transaction, in compliance with insurance regulations and RA 10173.'],
          ['Security', 'Access is strictly role-based (Super Admin, Admin, Mechanic). Passwords are hashed. All data operations are logged through a full system audit trail.'],
          ['Your Rights', '<ul style="margin-top:0.4rem;margin-left:1.25rem;display:flex;flex-direction:column;gap:0.25rem;"><li><strong style="color:#E8E2D8;">Be Informed</strong> — know what data is collected and how it is used</li><li><strong style="color:#E8E2D8;">Access</strong> — request a copy of your personal data</li><li><strong style="color:#E8E2D8;">Rectification</strong> — request correction of inaccurate information</li><li><strong style="color:#E8E2D8;">Erasure</strong> — request deletion subject to legal retention requirements</li></ul>'],
          ['Data Sharing', 'Personal data is <strong style="color:#E8E2D8;">not shared, sold, or disclosed</strong> to third parties except as required by law or when necessary for policy and claims processing with the insuring company you are enrolled under — ' . $ins_legal_or . '.'],
        ];
        foreach ($privacy_sections as $i => $sec): ?>
        <div style="margin-bottom:1.1rem;">
          <div style="font-size:0.63rem;letter-spacing:1.5px;text-transform:uppercase;color:#D4A017;font-weight:700;margin-bottom:0.35rem;"><?= ($i+1) ?>. <?= $sec[0] ?></div>
          <p><?= $sec[1] ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <div id="tab-terms" style="display:none;">
        <div style="background:rgba(212,160,23,0.07);border:1px solid rgba(212,160,23,0.15);border-radius:9px;padding:0.85rem 1.1rem;font-size:0.74rem;color:#9C9286;margin-bottom:1.1rem;">
          <strong style="color:#E8E2D8;">Effective Date:</strong> April 2026 &nbsp;&bull;&nbsp;
          <strong style="color:#E8E2D8;">Applies To:</strong> All authorized users of TG-BASICS
        </div>
        <?php
        $terms_sections = [
          ['Authorized Access Only', 'TG-BASICS is a private, internal system exclusively for authorized personnel of TG Customworks &amp; Basic Car Insurance. Unauthorized access, use, or attempt to access this system is strictly prohibited and may be subject to legal action.'],
          ['User Responsibilities', 'Each user is responsible for maintaining the confidentiality of their login credentials. You must not share your account with anyone. You are fully accountable for all actions performed under your account.'],
          ['Role-Based Usage', '<ul style="margin-top:0.4rem;margin-left:1.25rem;display:flex;flex-direction:column;gap:0.25rem;"><li><strong style="color:#E8E2D8;">Super Admin</strong> — Full system access; manages users and system settings</li><li><strong style="color:#E8E2D8;">Admin</strong> — Manages clients, policies, claims, and renewals</li><li><strong style="color:#E8E2D8;">Mechanic</strong> — Limited to repair jobs and quotations only</li></ul>'],
          ['Data Accuracy', 'Users are responsible for ensuring the accuracy and completeness of all data entered into the system. Inputting false, misleading, or unauthorized information is a violation of these terms.'],
          ['Confidentiality', 'All client records, policy details, claims information, and financial data accessed through TG-BASICS are strictly confidential. Users must not disclose, copy, or transmit any system data to unauthorized parties.'],
          ['Audit &amp; Accountability', 'All user actions within TG-BASICS are recorded in an audit log. This includes logins, data entries, updates, and deletions. Users consent to this monitoring as a condition of system use.'],
          ['Prohibited Actions', '<ul style="margin-top:0.4rem;margin-left:1.25rem;display:flex;flex-direction:column;gap:0.25rem;"><li>Unauthorized deletion or modification of records</li><li>Sharing login credentials with others</li><li>Attempting to bypass role-based access controls</li><li>Using the system for personal or non-business purposes</li></ul>'],
          ['Termination of Access', 'The Super Admin reserves the right to suspend or permanently revoke system access for any user who violates these Terms &amp; Conditions without prior notice.'],
        ];
        foreach ($terms_sections as $i => $sec): ?>
        <div style="margin-bottom:1.1rem;">
          <div style="font-size:0.63rem;letter-spacing:1.5px;text-transform:uppercase;color:#D4A017;font-weight:700;margin-bottom:0.35rem;"><?= ($i+1) ?>. <?= $sec[0] ?></div>
          <p><?= $sec[1] ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <div id="tab-disclaimer" style="display:none;">
        <div style="background:rgba(212,160,23,0.07);border:1px solid rgba(212,160,23,0.15);border-radius:9px;padding:0.85rem 1.1rem;font-size:0.74rem;color:#9C9286;margin-bottom:1.1rem;">
          <strong style="color:#E8E2D8;">Effective Date:</strong> April 2026 &nbsp;&bull;&nbsp;
          <strong style="color:#E8E2D8;">Scope:</strong> All users and stakeholders of TG-BASICS
        </div>
        <?php
        $disclaimer_sections = [
          ['General Disclaimer', 'TG-BASICS is developed as a capstone project for academic purposes at STI College Sta. Maria. While every effort has been made to ensure the accuracy, reliability, and completeness of the system, <strong style="color:#E8E2D8;">TG Customworks &amp; Basic Car Insurance makes no warranties</strong>, expressed or implied, regarding the system\'s performance, fitness for a particular purpose, or freedom from errors.'],
          ['System Accuracy', 'The information displayed within TG-BASICS — including policy records, premium computations, claim statuses, and renewal dates — is dependent on the accuracy of data entered by authorized users. <strong style="color:#E8E2D8;">The system operators are not liable for errors arising from incorrect data entry, system misconfiguration, or user misuse.</strong>'],
          ['Business Decisions', 'TG-BASICS is a management tool intended to assist — not replace — professional judgment. <strong style="color:#E8E2D8;">No business decision should be made solely based on system output</strong> without proper verification. TG Customworks &amp; Basic Car Insurance assumes no liability for losses or damages arising from decisions made based on system-generated information.'],
          ['System Availability', 'Uptime, data persistence, and system availability are not guaranteed. <strong style="color:#E8E2D8;">The operators are not responsible for data loss, system downtime, or service interruptions</strong> caused by hosting provider outages, hardware failure, software errors, power outages, or other unforeseen circumstances.'],
          ['Insurance Liability', 'TG-BASICS facilitates the encoding and tracking of insurance policies under ' . $ins_legal . '. <strong style="color:#E8E2D8;">The system does not constitute an official insurance contract.</strong> All insurance coverage and claims are governed by the actual policy documents issued by the insuring company. Discrepancies between system records and official policy documents shall defer to the official policy documents.'],
          ['Limitation of Liability', 'To the maximum extent permitted by applicable law, TG Customworks &amp; Basic Car Insurance, its staff, and the system developers <strong style="color:#E8E2D8;">shall not be held liable</strong> for any direct, indirect, incidental, or consequential damages arising from the use of or inability to use TG-BASICS, even if advised of the possibility of such damages.'],
          ['Academic Context', 'This system was developed in partial fulfillment of the requirements for the Bachelor of Science in Information Technology at <strong style="color:#E8E2D8;">STI College Sta. Maria</strong>. The academic institution bears no responsibility for the deployment, operation, or outcomes of the system in a business environment.'],
        ];
        foreach ($disclaimer_sections as $i => $sec): ?>
        <div style="margin-bottom:1.1rem;">
          <div style="font-size:0.63rem;letter-spacing:1.5px;text-transform:uppercase;color:#D4A017;font-weight:700;margin-bottom:0.35rem;"><?= ($i+1) ?>. <?= $sec[0] ?></div>
          <p><?= $sec[1] ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="padding:1rem 1.75rem;border-top:1px solid rgba(255,255,255,0.07);display:flex;justify-content:space-between;align-items:center;background:rgba(0,0,0,0.25);flex-shrink:0;gap:1rem;flex-wrap:wrap;">
      <span style="font-size:0.7rem;color:#7A7268;line-height:1.5;">By accessing TG-BASICS, you acknowledge and agree to our<br/>Privacy Notice, Terms &amp; Conditions, and Disclaimer.</span>
      <button id="close-privacy-modal-btn" style="background:linear-gradient(135deg,#D4A017,#B8860B);color:#fff;border:none;padding:0.6rem 1.5rem;border-radius:9px;font-size:0.8rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all 0.15s;box-shadow:0 4px 14px rgba(184,134,11,0.35);white-space:nowrap;" onmouseover="this.style.boxShadow='0 6px 20px rgba(184,134,11,0.5)'" onmouseout="this.style.boxShadow='0 4px 14px rgba(184,134,11,0.35)'">
        I Understand &amp; Continue
      </button>
    </div>
  </div>
</div>

