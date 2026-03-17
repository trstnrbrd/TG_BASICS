# TG-BASICS
### Brokerage and Auto Shop Integrated Client System

A web-based management system built exclusively for **TG Customworks and Basic Car Insurance Services** in Pandi, Bulacan, Philippines. Developed as a capstone project for STI College Sta. Maria.

---

## About the System

TG Customworks operates as both an auto repair shop and a PhilBritish Insurance brokerage. Before this system, all client records, insurance policies, and repair jobs were managed through separate Excel files, physical receipt books, and paper forms. TG-BASICS centralizes all of these into one connected platform.

---

## Tech Stack

- **Backend** - PHP 8, MySQL
- **Frontend** - HTML, CSS, JavaScript, React 18 (via CDN)
- **Icons** - Heroicons (inline SVG)
- **Email** - PHPMailer + Gmail SMTP
- **Environment** - XAMPP (local deployment)

---

## Modules

| # | Module | Description |
|---|--------|-------------|
| 1 | Client and Vehicle Records | Centralized client profiles and vehicle details, searchable by name, plate number, or policy number |
| 2 | Insurance Eligibility and Policy Processing | 10-year eligibility check for PhilBritish coverage, full policy encoding with premium breakdown |
| 3 | Policy Status and Renewal Tracking | Color-coded expiry dashboard - Stable, Expiring (30 days), Urgent (7 days) |
| 4 | Claims Document Tracking | Log claims and track document completeness from submission to resolution |
| 5 | Repair Job Management and Quotation to E-Receipt | Digital inspection checklist, quotation preparation, and automatic e-receipt generation |

---

## User Roles

| Role | Access |
|------|--------|
| Super Admin (Owner) | Full system access + account management |
| Admin | Full system access |
| Mechanic | Repair job panel only |

---

## How to Run Locally

1. Install [XAMPP](https://www.apachefriends.org/)
2. Clone this repository into `C:\xampp\htdocs\`
   ```
   git clone https://github.com/trstnrbrd/TG_BASICS.git
   ```
3. Open **phpMyAdmin** and create a database named `tg-basics`
4. Import the database schema from `database/tg_basics.sql`
5. Copy `config/mailer.example.php` to `config/mailer.php` and add your Gmail App Password
6. Start Apache and MySQL in XAMPP
7. Open `http://localhost/TG_BASICS/` in your browser

---

## Configuration

Create `config/mailer.php` based on the example file. This file is excluded from the repository for security reasons.

```php
<?php
// config/mailer.php
// Add your Gmail credentials here
$mail->Username = 'your-email@gmail.com';
$mail->Password = 'your-app-password';
```

---

## Project Info

- **Client** - TG Customworks and Basic Car Insurance Services
- **Address** - 49 Villa Tierra St., San Roque, Pandi, Bulacan
- **Owner** - Gerald Peterson V. Carpio
- **School** - STI College Sta. Maria
- **Course** - Bachelor of Science in Information Technology
- **Type** - Capstone Project

---

> Internal use only. Unauthorized access is prohibited.
