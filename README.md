<div align="center">

<img src="assets/img/tg_logo.png" height="90" alt="TG Customworks logo" />
&nbsp;&nbsp;&nbsp;&nbsp;
<img src="assets/img/LogoBasicCar.png" height="90" alt="Basic Car Insurance logo" />

# TG-BASICS

### Brokerage and Auto Shop Integrated Central System

One web system for **TG Customworks** (auto repair shop) and **Basic Car Insurance** (insurance brokerage) — two businesses run by the same owner in Pandi, Bulacan, Philippines.

<br/>

![Status](https://img.shields.io/badge/status-live_%26_in_daily_use-22c55e?style=flat-square)
![Type](https://img.shields.io/badge/type-capstone_project-6366f1?style=flat-square)
![School](https://img.shields.io/badge/school-STI_College_Sta._Maria-0ea5e9?style=flat-square)
![License](https://img.shields.io/badge/license-private-ef4444?style=flat-square)

[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![MySQL](https://img.shields.io/badge/MySQL_/_MariaDB-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mariadb.org)
[![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)](https://developer.mozilla.org/docs/Web/JavaScript)
[![Chart.js](https://img.shields.io/badge/Chart.js-FF6384?style=for-the-badge&logo=chartdotjs&logoColor=white)](https://www.chartjs.org)
[![Motion](https://img.shields.io/badge/Motion-motion.dev-FFF312?style=for-the-badge&logoColor=black)](https://motion.dev)
[![GitHub Actions](https://img.shields.io/badge/deploy-GitHub_Actions-2088FF?style=for-the-badge&logo=githubactions&logoColor=white)](.github/workflows/deploy.yml)

<br/>

[Overview](#overview) · [Modules](#modules) · [Roles & access](#roles--access) · [Security](#security) · [Tech stack](#tech-stack) · [Getting started](#getting-started) · [Deployment](#deployment)

</div>

---

## Overview

The shop and the brokerage used to run on Excel files, paper forms and receipt books — no single client record, no renewal reminders, no trail of who changed what. **TG-BASICS** puts the whole workflow in one place, from the first client intake to the final e-receipt:

```
Client + vehicle  →  Eligibility check  →  Policy & installments  →  Renewal tracking
        │                                          │
        └──→  Repair job  →  Quotation  →  E-receipt        Claim  →  Billing
```

It is **live and used every day** by the owner and staff, so changes are built on a `dev` branch and deployed after shop hours (see [Deployment](#deployment)).

---

## Modules

| Module | What it does |
|---|---|
| **Client & Vehicle Records** | Client profiles with their vehicles. Each client has an **insurance agent** (whose client it is) separate from who encoded it. Optional contact, email, Facebook name, engine and chassis numbers. OR/CR scanning (OCR), document attachments, data-privacy consent, soft delete. |
| **Eligibility & Policy** | Vehicle-age eligibility check for **PhilBritish** and **Alpha Insurance & Surety Company Inc.** (age limit set in Settings). Policy encoding with premium, commission, participation fee and an installment schedule (1 time / 3 / 4 / 6 months). Amounts show thousands separators while typing. **Save & Check Eligibility** goes straight from a new client to the policy. |
| **Renewal Tracking** | Per-company lists with Urgent / Expiring / Stable / Expired / Renewed cards (thresholds set in Settings), an **insurance-agent filter**, a one-click PhilBritish / Alpha switch and an urgent-count badge in the sidebar. From a policy: record installment payments and receipts, **undo** a wrong payment, **edit** the policy, renew or delete it. |
| **Claims** | Claim filing with a 10-step status flow (Compiling Requirements → … → Resolved / Denied), a 7-document checklist, damage photos and OCR. |
| **Repair Jobs** | Vehicle inspection checklist on arrival, job photos, and stages Pending → In Progress → For Pickup → Completed. A dedicated mechanic dashboard. |
| **Quotations & E-Receipts** | Quotation builder from a service catalog, converted to an e-receipt on payment; print or email. |
| **Billing** | Billing statements for insurance claims — parts, labor, other costs and deductible, with the release documents checklist. |
| **Dashboard & Reports** | Owner/admin dashboard with animated charts (renewal alerts, client types, policies, repairs, payment status) and a printable monthly report. |
| **Users & Settings** | Accounts per role, activation by email, 2FA, transaction PIN, activity log, company details, business thresholds and themes (light, dark, warm, high contrast). |
| **Client Digital ID** | A public QR-code page per client (vehicles, policies, repair status). Contact details are partly masked and the page is kept out of search engines. |

---

## Roles & access

| | **Super Admin** (Owner) | **Admin** (insurance agent) | **Mechanic** |
|---|:-:|:-:|:-:|
| See clients | All | All | Walk-in clients only |
| Edit a client | All | Clients they encoded or are the agent of | — |
| Assign / change a client's agent | Yes | Only when adding a client | — |
| Policies: view | All | All (others' are view-only) | — |
| Policies: payments, undo, edit, renew, delete | All | Their own clients' policies | — |
| Renewal Tracking | Opens on own clients; any agent via filter | Opens on own clients; any agent via filter | — |
| Claims · Billing · Reports | Yes | Yes | — |
| Repair jobs · Quotations | Yes | Yes | Yes |
| User management · Activity log · System settings | Yes | — | — |

> [!NOTE]
> There is no self-registration — accounts are created by the Owner and activated by email.

---

## Security

- **Sign-in:** password + optional two-factor (email code **or** authenticator app with recovery codes); rate-limited login, PIN and 2FA attempts.
- **Sessions:** session ID rotated every 30 minutes, 60-minute idle timeout, role and active status re-checked about every minute.
- **Every request:** prepared statements only, a CSRF token on every form, POST-only writes, output escaped against XSS.
- **Sensitive actions:** a transaction PIN before deletes, payment undos and money changes on a policy.
- **Files:** uploads checked by real MIME type and size; upload and config folders not browsable.
- **Accountability:** an activity log of who did what (payments, undos, policy edits, deletes, logins…).
- **Transport & privacy:** HTTPS enforced in production, security headers, masked contact details and no-index on the public client page.

---

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2 (no framework — each page is its own entry point) |
| Database | MySQL / MariaDB via MySQLi prepared statements |
| Frontend | HTML, CSS, vanilla JavaScript; React 18 (CDN) for the login form and user menu |
| Charts & motion | Chart.js 4 · [Motion](https://motion.dev) (dashboard/report animations) · GSAP (public client page) |
| UI helpers | SweetAlert2 dialogs · inline SVG icon helper (`includes/icons.php`) |
| Email | PHPMailer over Gmail SMTP (bundled in `vendor/src`) |
| Integrations | OCR.space (OR/CR scanning) · QRCodeJS (client IDs) · Imagin Studio (vehicle preview) |
| Testing | PHPUnit 11 — validators and premium/commission math (the test suite is kept locally, not in this repository) |
| Hosting | XAMPP locally · InfinityFree in production · GitHub Actions FTP deploy |

<details>
<summary><b>Project structure</b></summary>

```
TG-BASICS/
├── .github/workflows/deploy.yml   # push to master → FTP deploy to InfinityFree
├── ajax/                          # small lookup endpoints (search, PIN check…)
├── assets/
│   ├── css/                       # shared + per-page styles
│   ├── img/                       # logos and static images
│   └── js/shared/                 # page scripts (agent filter, money input, dashboard motion…)
├── auth/                          # login, logout, 2FA (email + TOTP), password reset, activation
├── config/
│   ├── session.php                # bootstrap: session hardening, timezone, security headers
│   ├── access.php                 # who may see / change which client and policy
│   ├── validators.php             # input sanitizing + validation helpers
│   ├── settings.php               # key–value system settings
│   └── *.example.php              # templates for db.php, mailer.php, ocr.php (real ones are git-ignored)
├── includes/                      # header, navbar, topbar, footer, icons, agent filter
├── modules/
│   ├── admin/                     # dashboard, monthly report, users, activity log, settings
│   ├── clients/                   # clients and vehicles
│   ├── insurance/                 # eligibility check, add / renew policy
│   ├── renewal/                   # renewal tracking, view / edit policy
│   ├── claims/  billing/          # claims and billing
│   ├── repair/  quotations/       # repair jobs, mechanic dashboard, quotations, receipts
│   └── public/                    # Client Digital ID page, privacy notice, terms
├── uploads/                       # user files (git-ignored)
├── vendor/src/                    # PHPMailer
└── index.php                      # public landing page
```

</details>

---

## Getting started

**Requirements:** [XAMPP](https://www.apachefriends.org/) with PHP 8.2+ and MySQL/MariaDB, and Git.

1. **Clone** into the XAMPP web root:
   ```bash
   git clone https://github.com/trstnrbrd/TG_BASICS.git C:/xampp/htdocs/TG-BASICS
   ```

2. **Create the database** `tg-basics` in phpMyAdmin and load the schema.

   > [!IMPORTANT]
   > The SQL dump is **not in the repository** (`*.sql` files are git-ignored because they hold real client data). Export the structure from a running copy (phpMyAdmin → Export) or ask the maintainer.

3. **Create the config files** from their templates, then fill in your own values:
   ```bash
   cp config/db.example.php     config/db.php       # database credentials
   cp config/mailer.example.php config/mailer.php   # Gmail address + App Password
   cp config/ocr.example.php    config/ocr.php      # OCR.space API key
   ```

4. **Open** <http://localhost/TG-BASICS/> and sign in with an account from your database.


---

## Deployment

```
dev ──(tested, merged after shop hours)──▶ master ──(GitHub Actions FTP)──▶ InfinityFree (live)
```

- Day-to-day work goes to the **`dev`** branch — pushing it does **not** touch the live site.
- Pushing to **`master`** runs [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml), which uploads the code to InfinityFree over FTP.
- `config/db.php`, `config/mailer.php`, `config/ocr.php`, `uploads/`, `tests/` and `*.sql` are never uploaded — production config is edited by hand in InfinityFree's File Manager.

> [!WARNING]
> **Database changes are manual and go first.** When a release needs new columns, run the SQL in production phpMyAdmin (after exporting a backup) **before** merging to `master` — code that expects a missing column breaks those pages until the SQL is run.

**Release checklist**

1. Back up the production database (phpMyAdmin → Export).
2. Run the release's SQL on production.
3. `git checkout master` → `git merge dev` → `git push` → `git checkout dev`
4. Open the live site and click through the changed pages.

---

## Project info

| | |
|---|---|
| **Client** | TG Customworks & Basic Car Insurance |
| **Address** | 49 Villa Tierra St., San Roque, Pandi, Bulacan, Philippines |
| **Business owner** | Gerald Peterson V. Carpio |
| **School** | STI College Sta. Maria |
| **Program** | Bachelor of Science in Information Technology |
| **Project type** | Capstone / thesis project |
| **Developer** | Tristan Reboredo |

---

<div align="center">

*Internal system — unauthorized access is prohibited.*

Made with coffee and way too many late nights — **Tristan Reboredo, 2026**

</div>
