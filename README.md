<div align="center">

<img src="assets/img/tg_logo.png" height="90" alt="TG Customworks Logo" />
&nbsp;&nbsp;&nbsp;
<img src="assets/img/LogoBasicCar.png" height="90" alt="Basic Car Insurance Logo" />

<br/><br/>

# TG-BASICS

### Brokerage and Auto Shop Integrated Central System

*Purpose-built for TG Customworks & Basic Car Insurance — Pandi, Bulacan, Philippines*

<br/>

[![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com)
[![JavaScript](https://img.shields.io/badge/JavaScript-ES6+-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)](https://developer.mozilla.org/en-US/docs/Web/JavaScript)
[![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)](https://developer.mozilla.org/en-US/docs/Web/HTML)
[![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)](https://developer.mozilla.org/en-US/docs/Web/CSS)
[![React](https://img.shields.io/badge/React-18_CDN-61DAFB?style=for-the-badge&logo=react&logoColor=black)](https://react.dev)

[![Chart.js](https://img.shields.io/badge/Chart.js-FF6384?style=for-the-badge&logo=chartdotjs&logoColor=white)](https://www.chartjs.org)
[![PHPMailer](https://img.shields.io/badge/PHPMailer-Gmail_SMTP-D14836?style=for-the-badge&logo=gmail&logoColor=white)](https://github.com/PHPMailer/PHPMailer)
[![XAMPP](https://img.shields.io/badge/XAMPP-FB7A24?style=for-the-badge&logo=xampp&logoColor=white)](https://www.apachefriends.org)
[![Git](https://img.shields.io/badge/Git-F05032?style=for-the-badge&logo=git&logoColor=white)](https://git-scm.com)
[![OCR.space](https://img.shields.io/badge/OCR.space-API-6366f1?style=for-the-badge&logo=amazonaws&logoColor=white)](https://ocr.space)
[![QRCodeJS](https://img.shields.io/badge/QRCodeJS-QR_Generator-22c55e?style=for-the-badge&logo=qrcode&logoColor=white)](https://github.com/davidshimjs/qrcodejs)
[![Imagin Studio](https://img.shields.io/badge/Imagin_Studio-3D_Car_Viewer-0ea5e9?style=for-the-badge&logo=autodesk&logoColor=white)](https://www.imaginstudio.com)

<br/>

![Status](https://img.shields.io/badge/Status-Active_Development-22c55e?style=flat-square)
![Type](https://img.shields.io/badge/Type-Capstone_Project-6366f1?style=flat-square)
![School](https://img.shields.io/badge/School-STI_College_Sta._Maria-0ea5e9?style=flat-square)
![License](https://img.shields.io/badge/License-Private-ef4444?style=flat-square)

</div>

---

## What is TG-BASICS?

TG Customworks runs both an **auto repair shop** and a **PhilBritish Insurance brokerage** under one roof. Before this system, everything — client records, policies, repair jobs, billing — was scattered across Excel files, paper forms, and receipt books. No central records, no renewal alerts, no digital trail.

**TG-BASICS** brings it all into one platform. From first client intake to final e-receipt, every part of their workflow is covered.

---

## Modules

| # | Module | Description |
|:-:|--------|-------------|
| 1 | **Client & Vehicle Records** | Client profiles with linked vehicles. Search by name, plate, or policy number. Soft-delete with full audit trail. |
| 2 | **Insurance Eligibility & Policy Processing** | 10-year PhilBritish eligibility check. Policy encoding with OR/CR renewal, premium computation, and participation fees. |
| 3 | **Policy Renewal Tracking** | Color-coded expiry status (Stable / Expiring / Urgent). Balance tracking and urgent renewal badge in nav. |
| 4 | **Claims Document Tracking** | Claims logging with status flow. Document checklist (OR/CR, license, police report, damage photos). |
| 5 | **Repair Job Management** | Digital vehicle inspection checklist. Stage tracking: Pending → In Progress → For Pickup → Completed. Mechanic portal. |
| 6 | **Quotation & E-Receipt Generator** | Quotation builder from a service catalog. Auto-converts to e-receipt on payment. Email delivery. |
| 7 | **Monthly Reports & Analytics** | Year-over-year charts for clients, policies, and repairs. Printable A4 layout. |
| 8 | **User & Account Management** | Role-based accounts, profile management, PIN protection, 2FA, and activity logs. |

---

## Features

### Security
- Email-based Two-Factor Authentication (OTP), toggleable per account
- Account lockout after repeated failed login attempts
- Transaction PIN required for destructive actions (delete, override)
- All queries use `mysqli` prepared statements — no raw string interpolation
- CSRF token verification on every form
- POST-only guards on all write and delete handlers

### Insurance
- 10-year PhilBritish eligibility window enforcement
- Automated OR/CR renewal with participation fee computation
- Renewal urgency: **Stable** (>90 days) / **Expiring** (≤90) / **Urgent** (≤30)

### Repair Shop
- Digital inspection checklist submitted on vehicle arrival
- Stage pipeline with per-phase timestamps
- Mechanic portal with job queue, quick actions, and team presence indicator

### Billing
- Quotation builder from a categorized service catalog
- Draft → Approval → Receipt flow
- PDF-ready e-receipt with company header, itemized breakdown, and signature area

### UX
- Dark / Light mode toggle on landing page
- Fully responsive — mobile sidebar, card-reflow tables, touch-friendly
- Toast notifications via SweetAlert2
- Real-time search and filters
- Online presence indicator for team members

---

## User Roles

| Role | Dashboard | Access |
|------|-----------|--------|
| **Super Admin** | Full system + owner panel | All modules, user management, activity logs, system settings |
| **Admin** | Full system | Clients, insurance, renewals, claims, repair, billing |
| **Mechanic** | Repair portal only | View jobs, submit inspection checklist, update repair stages, view quotations |

> No self-registration. All accounts are created by Super Admin or Admin.

---

## Tech Stack

| Layer | Technology | Notes |
|-------|------------|-------|
| Backend | PHP 8 | Server-side logic, routing, session management |
| Database | MySQL via MySQLi | Prepared statements, foreign key constraints |
| Frontend | HTML5 / CSS3 / JavaScript ES6+ | UI structure, styling, client-side interaction |
| UI Components | React 18 (CDN) + Babel | Dynamic quotation builder, interactive components |
| Charts | Chart.js 4 | Analytics dashboards and monthly reports |
| Alerts | SweetAlert2 | Toasts, confirmation dialogs, PIN prompts |
| Icons | Heroicons (inline SVG) | Consistent icon system via PHP helper |
| Email | PHPMailer + Gmail SMTP | Activation, 2FA OTP, password reset, e-receipt delivery |
| OCR | OCR.space API | Scan OR/CR and license plate text from images |
| QR Code | QRCodeJS | Scannable digital ID QR codes on client profiles |
| 3D Viewer | Imagin Studio API | 360° vehicle preview on vehicle profile pages |
| Environment | XAMPP (Apache + MySQL) | Local development |
| Version Control | Git + GitHub | Source control |

---

## Project Structure

```
TG-BASICS/
├── assets/
│   ├── css/               # Stylesheets per module + shared
│   ├── img/               # Logos and static images
│   └── js/                # Client-side scripts per module
│
├── auth/                  # Login, logout, 2FA, password reset, email verification
├── ajax/                  # AJAX endpoints (search, lookup, pin verify, etc.)
│
├── config/
│   ├── db.php             # Database connection (git-ignored)
│   ├── mailer.php         # PHPMailer config (git-ignored)
│   ├── session.php        # Session bootstrap + security headers
│   ├── validators.php     # Input sanitization helpers
│   └── settings.php       # System settings helper
│
├── includes/              # Shared PHP partials
│   ├── header.php         # HTML head + asset includes
│   ├── navbar.php         # Sidebar navigation
│   ├── topbar.php         # Topbar with breadcrumb + clock
│   ├── footer.php         # Footer + scripts
│   └── icons.php          # Heroicons SVG helper
│
├── modules/
│   ├── admin/             # Dashboard, reports, user management, activity log, settings
│   ├── clients/           # Client and vehicle CRUD
│   ├── insurance/         # Eligibility check and policy add
│   ├── renewal/           # Policy view and renewal tracking
│   ├── claims/            # Claims list, add, view
│   ├── billing/           # Billing view
│   ├── quotations/        # Quotation list, add, view, print receipt
│   └── repair/            # Repair list, add, view, mechanic dashboard
│
├── uploads/               # User-uploaded files (avatars, documents, photos)
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

Open [phpMyAdmin](http://localhost/phpmyadmin), create a database named `tg-basics`, then import:
```
database/tg_basics.sql
```

**3. Configure the database**
```bash
cp config/db.example.php config/db.php
```
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
```php
$mail->Username = 'your-email@gmail.com';
$mail->Password = 'your-gmail-app-password';
```
> Gmail App Password: Google Account → Security → 2-Step Verification → App Passwords

**5. Run the app**
```
http://localhost/TG-BASICS/
```

---

## Deployment

The system is also hosted on **InfinityFree** for live demo access.

Update `config/db.php` with InfinityFree credentials:
```php
$host = 'sql###.infinityfree.com';
$db   = 'if0_xxxxx_tgbasics';
$user = 'if0_xxxxx';
$pass = 'your_db_password';
```

> Link URLs (reset, activation) are built from `$_SERVER['HTTP_HOST']` — they auto-adapt to the live domain.

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
