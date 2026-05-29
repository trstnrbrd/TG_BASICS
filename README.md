<div align="center">

<img src="assets/img/tg_logo.png" height="80" alt="TG Customworks Logo" />
&nbsp;&nbsp;&nbsp;
<img src="assets/img/LogoBasicCar.png" height="80" alt="Basic Car Insurance Logo" />

<br/><br/>

# TG-BASICS

**Brokerage and Auto Shop Integrated Central System**

*Purpose-built for TG Customworks & Basic Car Insurance — Pandi, Bulacan, Philippines*

<br/>

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-ES6+-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)
![React](https://img.shields.io/badge/React-18_CDN-61DAFB?style=for-the-badge&logo=react&logoColor=black)

![Chart.js](https://img.shields.io/badge/Chart.js-FF6384?style=for-the-badge&logo=chartdotjs&logoColor=white)
![PHPMailer](https://img.shields.io/badge/PHPMailer-Gmail_SMTP-D14836?style=for-the-badge&logo=gmail&logoColor=white)
![XAMPP](https://img.shields.io/badge/XAMPP-FB7A24?style=for-the-badge&logo=xampp&logoColor=white)
![Git](https://img.shields.io/badge/Git-F05032?style=for-the-badge&logo=git&logoColor=white)

<br/>

![Status](https://img.shields.io/badge/Status-Active_Development-22c55e?style=flat-square)
![Type](https://img.shields.io/badge/Type-Capstone_Project-6366f1?style=flat-square)
![School](https://img.shields.io/badge/School-STI_College_Sta._Maria-0ea5e9?style=flat-square)
![License](https://img.shields.io/badge/License-Private-ef4444?style=flat-square)

</div>

---

## Overview

TG Customworks operates as both an **auto repair shop** and a **PhilBritish Insurance brokerage**. Before this system, all client records, insurance policies, repair jobs, and billing were managed across disconnected Excel files, paper forms, and physical receipt books — with no central record-keeping, no automated renewal tracking, and no digital receipts.

**TG-BASICS** consolidates every part of their business workflow into a single platform — from first client intake all the way to final e-receipt generation.

---

## Modules

| # | Module | Description |
|:-:|--------|-------------|
| 1 | **Client & Vehicle Records** | Full client profiles with linked vehicles. Search by name, plate number, or policy number. Soft-delete with audit trail. |
| 2 | **Insurance Eligibility & Policy Processing** | 10-year PhilBritish eligibility validation. Policy encoding with OR/CR renewal, premium computation, and participation fees. |
| 3 | **Policy Status & Renewal Tracking** | Color-coded expiry dashboard (Stable / Expiring / Urgent). Balance tracking. Urgent renewal badge in nav. |
| 4 | **Claims Document Tracking** | Log claims with status flow. Document completeness checklist (OR/CR, license, police report, damage photos). |
| 5 | **Repair Job Management** | Digital vehicle inspection checklist on arrival. Stage tracking: Inspection → Repair → Paint → Curing → Final Release. Mechanic portal. |
| 6 | **Quotation & E-Receipt Generator** | Build quotations from a service catalog. Auto-convert to formatted e-receipt on payment confirmation. Email delivery. |
| 7 | **Monthly Reports & Analytics** | Year-over-year charts for client types, policies, and repairs. Printable A4 document layout. |
| 8 | **User & Account Management** | Role-based user creation, profile management, PIN protection, 2FA config, activity logs. |

---

## Features

### Core
- Role-based access with three user levels — Super Admin, Admin, and Mechanic
- Searchable and filterable records across all modules
- Full audit trail — every login, change, and deletion is logged with timestamp and user

### Insurance
- 10-year PhilBritish eligibility window enforcement
- Automated OR/CR renewal computation with participation fee
- Color-coded renewal urgency: Stable (>90 days) / Expiring (≤90) / Urgent (≤30)

### Repair Shop
- Digital inspection checklist submitted by mechanics on vehicle arrival
- Stage pipeline with timestamps per phase
- Mechanic portal with job queue, quick actions, and team presence

### Billing
- Quotation builder from a categorized service catalog
- Draft → Approval → Receipt conversion flow
- PDF-ready e-receipt with company header, itemized breakdown, and digital signature area

### Security
- Email-based Two-Factor Authentication (OTP), toggleable per account
- Account lockout after repeated failed login attempts with rate limiting
- Admin PIN confirmation required for destructive actions (delete, override)
- All DB queries use `mysqli` prepared statements — zero string interpolation in queries
- POST-only guards on all write/delete handlers
- CSRF token verification on all forms

### UX
- Dark mode / Light mode toggle (landing page)
- Fully responsive — mobile sidebar, card-reflow tables, touch-friendly interactions
- Toast notifications via SweetAlert2
- Real-time search and filters without page reload
- Online presence indicator for team members

---

## User Roles

| Role | Dashboard | Access |
|------|-----------|--------|
| **Super Admin** | Full system + owner panel | All modules, user management, activity logs, system settings, 2FA global config |
| **Admin** | Full system | All six core modules — clients, insurance, renewals, claims, repair, billing |
| **Mechanic** | Repair portal only | View assigned jobs, submit inspection checklist, update repair stages, view quotations |

> No self-registration. All accounts are provisioned by Super Admin or Admin.

---

## Tech Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Backend** | PHP 8 | Server-side logic, routing, session management |
| **Database** | MySQL via MySQLi | Relational data, prepared statements, foreign key constraints |
| **Frontend** | HTML5 / CSS3 / JavaScript ES6+ | UI structure, styling, client-side interaction |
| **UI Components** | React 18 (CDN) + Babel Standalone | Dynamic quotation builder, interactive form components |
| **Charts** | Chart.js 4 | Monthly analytics bar charts on dashboards and reports |
| **Alerts** | SweetAlert2 | Toast notifications, confirmation dialogs, PIN prompt |
| **Icons** | Heroicons (inline SVG via PHP helper) | Consistent icon system across all pages |
| **Email** | PHPMailer + Gmail SMTP | Account activation, 2FA OTP, password reset, e-receipt delivery |
| **Environment** | XAMPP (Apache + MySQL) | Local development server |
| **Version Control** | Git + GitHub | Source control and collaboration |

---

## Project Structure

```
TG-BASICS/
├── assets/
│   ├── css/               # Stylesheets per module + shared
│   │   ├── index.css      # Landing page
│   │   ├── dashboard.css  # Shared dashboard layout
│   │   ├── sidebar.css    # Navigation sidebar
│   │   ├── auth/          # Login, activation, reset
│   │   ├── mechanic/      # Mechanic portal styles
│   │   └── shared/        # Reusable module styles
│   ├── img/               # Logos and static images
│   └── js/                # Client-side scripts per module
│
├── auth/                  # Login, logout, 2FA, password reset, email verification
├── ajax/                  # AJAX endpoints (search, lookup, pin verify, etc.)
│
├── config/
│   ├── db.php             # Database connection (excluded from VCS)
│   ├── mailer.php         # PHPMailer + Gmail config (excluded from VCS)
│   ├── session.php        # Session bootstrap and security headers
│   ├── validators.php     # Input sanitization and validation helpers
│   └── rate_limit.php     # Login rate limiting
│
├── includes/              # Shared PHP partials
│   ├── header.php         # HTML head + asset includes
│   ├── navbar.php         # Sidebar navigation
│   ├── topbar.php         # Page topbar with breadcrumb + clock
│   ├── footer.php         # Footer + script includes
│   └── icons.php          # Heroicons SVG helper function
│
├── modules/
│   ├── admin/             # Dashboard, monthly report, manage users, activity log, settings
│   ├── clients/           # Client and vehicle CRUD + view pages
│   ├── insurance/         # Policy eligibility check and policy add
│   ├── renewal/           # Policy view and renewal tracking
│   ├── claims/            # Claims list, add, and view
│   ├── billing/           # Billing view
│   ├── quotations/        # Quotation list, add, view, print receipt
│   └── repair/            # Repair list, add, view, mechanic dashboard
│
├── uploads/               # User-uploaded files (avatars, claim documents)
├── vendor/                # Composer dependencies (PHPMailer)
├── index.php              # Public landing page
└── README.md
```

---

## Getting Started

### Prerequisites
- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL)
- PHP 8.0 or higher
- Git

### Installation

**1. Clone the repository**
```bash
git clone https://github.com/trstnrbrd/TG_BASICS.git C:/xampp/htdocs/TG-BASICS
```

**2. Create the database**

Open [phpMyAdmin](http://localhost/phpmyadmin) and create a database named `tg-basics`, then import:
```
database/tg_basics.sql
```

**3. Configure the database connection**
```bash
cp config/db.example.php config/db.php
```
Edit `config/db.php`:
```php
$host = 'localhost';
$db   = 'tg-basics';
$user = 'root';
$pass = '';
```

**4. Configure the mailer**
```bash
cp config/mailer.example.php config/mailer.php
```
Edit `config/mailer.php`:
```php
$mail->Username = 'your-email@gmail.com';
$mail->Password = 'your-gmail-app-password';
```
> To generate a Gmail App Password: Google Account → Security → 2-Step Verification → App Passwords

**5. Start XAMPP and open the app**
```
http://localhost/TG-BASICS/
```

---

## Deployment

The application is also hosted on **InfinityFree** for live demo purposes.

When deploying, update `config/db.php` with the InfinityFree database credentials:
```php
$host = 'sql###.infinityfree.com';
$db   = 'if0_xxxxx_tgbasics';
$user = 'if0_xxxxx';
$pass = 'your_db_password';
```

> The reset/activation link URLs are built dynamically using `$_SERVER['HTTP_HOST']` — they automatically use the correct domain on the live server.

---

## Running Tests

An automated PHP test runner is included:

```
http://localhost/TG-BASICS/tests/run_tests.php
```

Covers:
- Database connectivity and schema integrity
- Required file existence across all modules
- Security patterns (prepared statements, POST guards)
- Foreign key constraint configuration
- Business logic (eligibility rules, policy status thresholds)

Manual test cases are documented in `tests/TEST_CASES.md` — **114 test cases** across 12 categories.

---

## Project Info

| | |
|-|-|
| **Client** | TG Customworks & Basic Car Insurance |
| **Address** | 49 Villa Tierra St., San Roque, Pandi, Bulacan, Philippines |
| **Business Owner** | Gerald Peterson V. Carpio |
| **School** | STI College Sta. Maria |
| **Program** | Bachelor of Science in Information Technology |
| **Project Type** | Capstone / Thesis Project |
| **Developer** | Tristan Reboredo |

---

<div align="center">

*Internal use only. Unauthorized access is prohibited.*

<br/>

Made with ☕ and way too many late nights — **Tristan Reboredo, 2026**

</div>
