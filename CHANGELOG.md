# Changelog

All notable changes to TG-BASICS. Versions follow [Semantic Versioning](https://semver.org/) (MAJOR.MINOR.PATCH):

- **MAJOR**: a change that reworks what came before (database schema, a module rebuilt)
- **MINOR**: a new feature that keeps everything else working
- **PATCH**: a bug fix only

The current version is set in `config/version.php` and shown at the bottom of the sidebar, in the landing-page footer, and in Settings > My Account > About This System. Versions before 1.0.0 were reconstructed from the Git history (first commit 2026-03-17).

## [2.6.0] - 2026-11-20

### Changed
- New Repair Job > Unit Inspection: one car seen from above (front at the top, driver and passenger sides labelled) instead of five separate views; each part carries a number that matches the list
- The inspection list is grouped into sectors (Front, Left side, Right side, Rear, Wheels), each with an "OK" / "n damaged" badge, plus a summary of damaged areas at the top
- Each area's condition is one No Damage / Minor Scratch / Major Damage switch; its notes field appears once the area is marked damaged
- Hovering a row lights up its part on the car and the other way round; parts can be opened with the keyboard (Enter) and closed with Escape
- On phones the car sits above the list with no sideways scrolling, and a tapped part opens a sheet at the bottom of the screen with large buttons
- View Claim reorganised: the status steps, then one Next Step card (the current status, what it means, and the steps allowed now, each explaining what it does or why it is blocked), then the requirements beside one Claim Details card (incident, notes, client, vehicle, policy). The name, status and claim type no longer repeat across the page
- View Claim: Send to Admin is now the "Sent to Admin" step itself: it emails the requirements and moves the claim from Compiling Requirements to Sent to Admin in one go. While the claim is still with the admin, the requirements can be emailed again without changing the status
- View Claim: once the requirements are locked, they show a Locked badge and "Not submitted" for the missing ones, instead of a "remaining" count and empty checkboxes
- View Quotation redesigned: a header with the quotation number, status, total and the actions for its current step, a progress bar (Draft → Pending Approval → Approved → Converted, with Cancelled as its own step), the line items with their area and a highlighted total, and beside them the client and vehicle (plate, vehicle, Repair ID link) and the billing statement with a paid-of-total bar and Update Payment. When no statement exists yet, it says what is needed and offers Convert to Billing Statement once approved. On phones the line items stack and nothing scrolls sideways
- View Billing redesigned to match: a header with the billing number, status, insurer, client, plate and policy, the Total Amount Due (with the deductible), milestones (Created, Sent to insurer, Documents received, Last updated) and the Update Status / Print / Delete buttons. The printable statement stays on the left, unchanged on paper; on the right are the client and vehicle card (with links to the claim and the client), the Required Documents with a progress bar, and Edit Details, now folded until opened. The separate Timeline card is gone (its dates are in the milestones). It now also fits tablets and phones
- View Policy restyled, same layout as before (top bar, dark banner, Client | Vehicle, Policy Details, Payment Status): the banner also shows the insurer, client, plate and Total Premium, with a coverage bar from start to end date and the days left; the client and vehicle cards open with an identity row (initials / plate); the coverage period is a row of tiles; Payment Status adds a paid-of-total bar above Total Payable / Amount Paid / Balance Remaining. The payment schedule and payment form are unchanged. It fits tablets and phones
- View Client restyled, same layout as before (banner, Client Information with the QR, Registered Vehicles, Policy Documents, Insurance Policies, Claims History, Repair Jobs): the banner shows the client's initials, Client ID and contact / agent chips; each client field has an icon (empty ones in grey); the Digital ID panel is larger with a short explanation; plates look like plates; Attach PDF opens a drop zone. It fits phones
- Admin Dashboard polished, same layout: a greeting with a one-line summary and chips for what needs attention (urgent renewals, renewals coming up, overdue payments); a small trend line or meter in each stat card; Client Types can step back through the last 6 months (‹ ›); the charts take the theme's colours (dark mode too) and say how this month compares with last month; renewal alerts show how close each expiry is; the Payment Status legend highlights its slice on hover and hides / shows it on click; team members show who is online
- Monthly Report polished, same layout: the years are one-click pills; each stat card has a trend line and its best month; Client Types is a stacked chart and Policies & Repairs shows repairs as a line over the policy bars, both stopping at the current month; legend chips hide / show a series; pointing at a month in the Monthly Breakdown lights it up in both charts (and clicking a bar jumps to its row); each number in the table has a small bar. Colours follow the theme, including dark mode; the printout is unchanged
- Manage Users restyled, same layout: a search box (name, username or email) and filter tabs with counts (All, Admins, Mechanics, and Pending / Deactivated when there are any); a Last Seen column (Online now, minutes or days ago, Never signed in); the email under each username; the actions say what they do (Deactivate / Reactivate / Delete) instead of showing only an icon; the Create New Account form explains the activation email and picks the role from two cards that say what each role can do
- Settings redesigned like typical enterprise software: a page header with who is signed in; a menu on the left that stays in place, grouped into Account (Profile, Username, Password, Two-Factor, Authenticator App, Transaction PIN, About), Appearance (Theme, Layout & Text) and Organization for the owner (Backup, Company Profile, Email, Insurance, Login Security); a link opens its group and scrolls to the section, the section in view is lit in the menu, and each section has its own address (settings.php#st-insurance and so on). Sections are flat, quiet cards; the Save button of Appearance and Organization stays at the bottom of the screen and says "You have unsaved changes" once something is edited. On phones the menu becomes three tabs. Nothing about what is saved changed
- Edit Profile (from the user menu on every page) is now a panel that slides in from the right: a header with the photo (with a camera button to change it), name, role and @username, then Personal information and Email address with a line on what each is used for, a link to the password / two-factor / PIN settings, and Cancel / Save Changes pinned at the bottom. On phones it takes the whole screen

### Fixed
- View Claim: Lack of Requirements was blocked on screen when nothing had been uploaded, or when the policy had expired, although the server allowed it; the page and the server now follow the same rule
- View Billing: the TOTAL AMOUNT DUE on the statement now lines up with the amounts above it
- View Claim: the progress bar showed every denied claim as denied at Sent to Head Office (even one denied later), and showed no current step at all for a Lack of Requirements claim; both now appear as their own step after the bar
- Unit Inspection: the fenders and roof could be clicked on the diagram but were never saved; only the areas that are saved can be clicked now

## [2.5.0] - 2026-11-11

### Added
- Sign-in pages (sign in, two-step verification, activation, forgot / reset password): an animated black-to-gold background; devices without WebGL2 keep the previous background
- Client QR page: an animated gold scanner background, on both the card and the "link not found" page; devices without WebGL2 keep the plain dark background
- Both backgrounds lower their frame rate and resolution on a weak device, and show a still frame with Data Saver on

## [2.4.0] - 2026-11-06

### Added
- New landing page: intro, floating glass navigation that follows the page, line-by-line headline with the typewriter, "How it works" steps, the six modules as cards with a miniature of each real screen (red for insurance, yellow for the repair shop), stats, testimonials and a dark footer; light and dark themes; fits phones, tablets and wide screens
- Landing page: shop card with "Open in Google Maps", "See all reviews on Google", and a contact sheet with call, email and Facebook links
- Settings > Company Info: Google Maps Link field
- Insurance companies: optional website (`insurance_companies.website`), shown as a link on the landing page
- Per-user Design Preferences: theme ("Auto" follows the device), text size, compact sidebar and page width
- Forms that create a record carry a one-time key, so a double click, a resend or Back + Submit saves it only once

### Changed
- Every save that writes more than one row goes through one transaction (Add Quotation, Edit Policy, Undo Payment, Import Clients, Convert to Receipt)
- Status changes (claims, quotations, repair jobs) only apply from the status the page showed, so two people cannot overwrite each other

### Fixed
- The Transaction PIN is checked on the server for Delete Policy, Undo Payment, deleting a payment receipt and changing a peso amount in Edit Policy (it was only asked in the browser)
- File New Claim refuses a policy that is not the client's or has expired
- Convert to Receipt and the client PDF upload say why they failed instead of acting as if they succeeded

### Security
- README, CHANGELOG, Composer and PHPUnit files are no longer deployed, and the web server refuses them

## [2.3.0] - 2026-11-04

### Added
- Sidebar: the gold highlight slides to the clicked section before the page opens (Motion; a shorter, gentler slide when Windows animation effects are off)
- Renewal Tracking: the insurance company switch slides its highlight to the chosen company

### Removed
- View Client: the four count cards (Registered Vehicles, Total Policies, Active Policies, Total Claims); the Vehicles and Policies cards still show their counts

## [2.2.1] - 2026-11-04

### Fixed
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
