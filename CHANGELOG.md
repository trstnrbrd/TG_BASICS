# Changelog

All notable changes to TG-BASICS. Versions follow [Semantic Versioning](https://semver.org/) (MAJOR.MINOR.PATCH):

- **MAJOR**: a change that reworks what came before (database schema, a module rebuilt)
- **MINOR**: a new feature that keeps everything else working
- **PATCH**: a bug fix only

The current version is set in `config/version.php` and shown at the bottom of the sidebar, in the landing-page footer, and in Settings > My Account > About This System. Versions before 1.0.0 were reconstructed from the Git history (first commit 2026-03-17).

## [Unreleased]

### Changed
- Activity Log: entries written by the scheduled jobs (renewal / overdue reminders, weekly backup, automatic purges) show as "System — Automatic job" instead of "Deleted User", in the table and the Excel export; the User filter has a "System (automatic jobs)" option

## [2.2.0] - 2026-11-03

### Added
- Client reference ID (`CL-0001`, `CL-0002`, ...) on Client Records, searchable from every client search
- Circuit breaker for outside services (OCR.space, Pusher): after 3 failures in a row a service is skipped for 5 minutes instead of slowing every request

### Changed
- Renewal-reminder job retries the Puppeteer run on a temporary failure
- Clients with the same name are listed in a fixed order

## [2.1.0] - 2026-10-31

### Added
- Recently Deleted clients: restore, permanent delete and "Delete All" (Transaction PIN required)
- Automatic purge of old data (expired codes and links, clients deleted 30+ days ago, year-old cancelled quotations and sign-in logs)
- Images are compressed in the browser before upload

## [2.0.0] - 2026-10-29 / 2026-10-30 (panel revision)

### Changed (breaking: database schema)
- Lists that were hardcoded are now database tables: insurance companies, mortgagees, claim types, service types, billing documents, signatories, and one `statuses` table for billing / claims / repairs / quotations / receipts
- Data Access Layer (`includes/data/`): every SQL query moved into named, prepared-statement functions
- Shop logos and testimonials are read from the database
- "Job #" renamed to "Repair ID"; billing and quotation numbers get prefixes
- One function writes all audit-log entries

### Added
- Repair progress photos, optionally shown on the client's QR page, with GPS and device data removed
- Theme management with validation
- Live updates on the client's public QR page

## [1.5.0] - 2026-10-27 / 2026-10-28

### Added
- Live updates: open pages reload themselves when another user saves (Pusher, with 20-second polling as a fallback)
- Money input validation across billing and payments
- Character limits on notes

## [1.4.0] - 2026-10-17 / 2026-10-24

### Added
- Daily renewal-reminder emails (scheduled through GitHub Actions)
- Weekly full backup (database and uploads) and overdue-payment digest
- Transaction PIN checked on the server for sensitive actions

## [1.3.0] - 2026-10-10 / 2026-10-11

### Added
- Installable app (PWA) with offline caching of static files
- Mobile touch and layout improvements

## [1.2.0] - 2026-10-05 / 2026-10-08

### Changed
- One JSON reply format for every AJAX endpoint
- Single role guard and helper functions (`is_staff()`, `is_mechanic()`, `is_super_admin()`)
- Multi-row saves wrapped in database transactions
- Claim and policy status rules centralized

### Added
- Demo-site deploy workflow
- Email template with logos

## [1.1.0] - 2026-09-24 / 2026-09-25

### Added
- Insurance agents and agent filters, Edit Policy, payment undo
- Pagination on every list page
- Client CSV import, duplicate policy and name checks
- One-click database backup for the owner

### Fixed
- Race conditions under concurrent users (record numbers)
- Crashes on malformed form input

## [1.0.0] - 2026-09-21 (go-live)

### Added
- Commission-based premium computation; Billing Statement
- Automatic FTP deploy to production on every push to `master`
- Data Privacy Act compliance, keyboard accessibility, mobile overhaul

### Security
- Upload validation, HTTPS enforcement, access-control and rate-limiter fixes, CSRF checks
- Permission and data-safety hardening before go-live

## Development releases (before go-live)

### [0.6.0] - 2026-05-29 to 2026-07-16
- Role-based restrictions, quotation edit mode, monthly report print
- PHPUnit test suite
- Landing page 3D cards and navbar redesign

### [0.5.0] - 2026-05-14 to 2026-05-25
- OCR scanning for Add Client and Add Policy
- Authenticator-app (TOTP) 2FA, theme engine
- Global search, team directory, vehicle view page, mobile app shell

### [0.4.0] - 2026-04-13 to 2026-04-25
- Repair module, quotations and e-receipts, Billing module
- Transaction PIN, receipt attachments, dashboard charts
- Custom 404 page

### [0.3.0] - 2026-04-04 to 2026-04-12
- Claims module with document requirements and email notifications
- Policy renewal flow, mechanic dashboard, online presence
- IP-based rate limiting, input sanitization

### [0.2.0] - 2026-03-19 to 2026-04-03
- Policy status and renewal tracking (Module 3)
- Forgot password, activity log (audit trail), System Settings with email 2FA
- Dual branding, responsive design, foreign-key constraints

### [0.1.0] - 2026-03-17
- Initial commit: client and vehicle records, insurance policies, sign-in
