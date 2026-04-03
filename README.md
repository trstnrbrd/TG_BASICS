# TG-BASICS

**Brokerage and Auto Shop Integrated Client System**

A full-stack web management system built exclusively for **TG Customworks and Basic Car Insurance Services** in Pandi, Bulacan, Philippines. Developed as a capstone project for STI College Sta. Maria — BSIT.

---

## Overview

TG Customworks operates as both an auto repair shop and a PhilBritish Insurance brokerage. Before this system, all client records, insurance policies, repair jobs, and billing were managed across disconnected Excel files, paper forms, and physical receipt books.

TG-BASICS consolidates every part of their workflow into one platform — from first client intake to final e-receipt generation.

---

## Features

- Role-based access with three user levels (Super Admin, Admin, Mechanic)
- Searchable client and vehicle records by name, plate number, or policy number
- 10-year PhilBritish eligibility validation on insurance policy creation
- Color-coded renewal tracking dashboard (Stable / Expiring / Urgent)
- Claims document tracker with completeness checklist (OR/CR, license, damage photos)
- Digital vehicle inspection checklist submitted by mechanics on arrival
- Repair job stage pipeline: Inspection → Repair → Paint → Curing → Final Release
- Quotation builder from a digital service catalog with auto-conversion to e-receipt
- Email-based two-factor authentication (2FA) toggle per account
- Full audit trail — every login, record change, and system action is logged
- Account lockout after repeated failed login attempts
- Dark and light mode on the landing page

---

## Modules

| # | Module | Key Functions |
|---|--------|---------------|
| 1 | **Client and Vehicle Records** | Add/edit/delete clients and vehicles, search by plate or policy number |
| 2 | **Insurance Eligibility and Policy Processing** | 10-year eligibility check, policy encoding with premium and participation fee |
| 3 | **Policy Status and Renewal Tracking** | Color-coded expiry dashboard, balance tracking, urgent policy badge in nav |
| 4 | **Claims Document Tracking** | Log claims, track document completeness, update status from collection to resolution |
| 5 | **Repair Job Management** | Digital inspection checklist, stage tracking, mechanic portal |
| 6 | **Quotation and E-Receipt Generator** | Build quotations from service catalog, auto-convert to formatted e-receipt on payment |

---

## User Roles

| Role | Dashboard | Access Level |
|------|-----------|--------------|
| **Super Admin** | Full system + admin panel | All modules, user account management, activity logs, system settings, 2FA config |
| **Admin** | Full system | All six modules — clients, insurance, renewals, claims, repair, billing |
| **Mechanic** | Repair portal only | Submit vehicle inspection checklist, update job stages |

No self-registration. All accounts are created by the Super Admin or Admin.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8 |
| Database | MySQL (via XAMPP) |
| Frontend | HTML5, CSS3, JavaScript |
| UI Components | React 18 (CDN), Babel Standalone, Swal2 |
| Icons | Heroicons (inline SVG via PHP helper) |
| Email | PHPMailer + Gmail SMTP |
| Environment | XAMPP (local deployment) |

---

## Security

- **Two-Factor Authentication** — Email OTP on login, toggleable per account via system settings
- **Account Lockout** — Automatic lockout after repeated failed login attempts
- **Role-Based Access Control** — Strict module-level permission enforcement; mechanics cannot access client or insurance data
- **Audit Trail** — All logins, record changes, and deletions are logged in `audit_logs` with timestamps and user details. Logs persist even after user account deletion.
- **Prepared Statements** — All database queries use `mysqli` prepared statements to prevent SQL injection
- **POST-only destructive actions** — Delete and update handlers reject non-POST requests
- **Foreign Key Constraints** — Database enforces referential integrity with CASCADE and SET NULL rules

---

## Project Structure

```
TG-BASICS/
├── assets/
│   ├── css/           # Stylesheets (index.css for landing, module CSS)
│   ├── img/           # Logos and images
│   └── js/            # Client-side scripts
├── auth/              # Login, logout, 2FA, password reset, email verification
├── config/            # Database and mailer config (excluded from version control)
├── includes/          # Shared PHP partials (header, footer, sidebar, icons)
├── modules/
│   ├── clients/       # Client and vehicle CRUD
│   ├── insurance/     # Policy eligibility and processing
│   ├── renewal/       # Renewal tracking and urgent count
│   ├── claims/        # Claims document tracker
│   ├── repair/        # Repair jobs and mechanic portal
│   ├── portal/        # Quotations and e-receipts
│   ├── dashboard_admin.php
│   ├── manage_users.php
│   ├── activity_log.php
│   └── settings.php
├── tests/             # Automated PHP test runner and manual test cases
├── uploads/           # User-uploaded files (profile photos, etc.)
├── vendor/            # Composer dependencies (PHPMailer)
└── index.php          # Landing page
```

---

## How to Run Locally

**Requirements:** XAMPP (Apache + MySQL)

1. Clone the repository into your XAMPP `htdocs` folder:
   ```bash
   git clone https://github.com/trstnrbrd/TG_BASICS.git C:/xampp/htdocs/TG-BASICS
   ```

2. Open **phpMyAdmin** and create a database named `tg-basics`.

3. Import the schema:
   ```
   database/tg_basics.sql
   ```

4. Set up the mailer config:
   ```bash
   cp config/mailer.example.php config/mailer.php
   ```
   Then edit `config/mailer.php` and add your Gmail App Password.

5. Start **Apache** and **MySQL** in the XAMPP Control Panel.

6. Open your browser and go to:
   ```
   http://localhost/TG-BASICS/
   ```

---

## Configuration

`config/db.php` and `config/mailer.php` are excluded from version control. Create them from the provided example files.

```php
// config/mailer.php
$mail->Username = 'your-email@gmail.com';
$mail->Password = 'your-gmail-app-password';
```

To generate a Gmail App Password: Google Account → Security → 2-Step Verification → App Passwords.

---

## Running Tests

An automated PHP test runner is included at `tests/run_tests.php`. It checks:

- Database connectivity and schema integrity
- Required file existence across all modules
- Security patterns (prepared statements, POST guards)
- Foreign key constraint configuration
- Business logic (eligibility rules, policy status thresholds)

Access it at:
```
http://localhost/TG-BASICS/tests/run_tests.php
```

Manual test cases are documented in `tests/TEST_CASES.md` (114 test cases across 12 categories).

---

## Project Info

| | |
|-|-|
| **Client** | TG Customworks and Basic Car Insurance |
| **Address** | 49 Villa Tierra St., San Roque, Pandi, Bulacan, Philippines |
| **Owner** | Gerald Peterson V. Carpio |
| **School** | STI College Sta. Maria |
| **Course** | Bachelor of Science in Information Technology |
| **Type** | Capstone Project |
| **Developer** | Me- Tristan Reboredo :D |

---

> Internal use only. Unauthorized access is prohibited.
