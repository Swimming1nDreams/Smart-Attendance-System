# Office Attendance and Analytic System

OAAS is a PHP 8+ and MySQL custom MVC application for attendance management and analytics.

## Current Authorization Contract

The running source code, migrations, `README.md`, and `DEVELOPMENT_MAP.md` are the current project baseline. The attached OAPAS user/admin project books are historical references from the earlier two-role version; they do not describe the current Department Head role and must not be used as the authorization specification.

| Role | Scope | Current authority |
| --- | --- | --- |
| Administrator | Entire system | Full management authority: users, master data, attendance corrections, leave decisions, work policies, overtime decisions, reports, analytics, audit logs, and exports. |
| Department Head | Assigned department only | Delegated operational authority over Employee accounts in the assigned department: team attendance create/edit corrections; leave detail/supporting-document review; leave approve/reject/cancel; and team employees, analytics, reports, and exports. Also retains personal attendance, leave, profile, holiday, notification, and work-policy self-service. Cannot manage users/master data, another department, another Department Head, their own leave decision, attendance deletion, policies, or overtime decisions. A department assignment is mandatory. |
| Employee | Own records only | Personal dashboard, attendance Check In/Check Out and history, leave submission/history/cancellation, profile, holidays, notifications, and current work policy. |

Authorization is enforced server-side. Interface visibility is not treated as a permission boundary, and Department Head scope is refreshed from the database during authenticated-session validation. An Administrator delegates Department Head authority by assigning that role and its department; the Administrator retains full system authority.

## Technology Stack

- PHP 8+
- MySQL
- PDO prepared statements
- Composer autoload foundation
- Tailwind CSS build workflow
- Custom MVC architecture
- Session-based authentication and CSRF protection
- Tailwind CSS utility classes in PHP views
- JavaScript for small client-side interactions
- WAMP/local PHP development

No Laravel, Symfony, or external PHP framework is used.

## Project Structure

- `public/index.php` - application entry point and route registration.
- `config/` - app and database configuration.
- `src/Core/` - application, router, request, controller, and session classes.
- `src/Controllers/` - request handlers and page controllers.
- `src/Models/` - PDO-based data access layer.
- `src/Services/` - service layer, including authentication.
- `views/` - PHP view templates, shared layouts, and partials.
- `lang/` - PHP language files for the current English/Myanmar localization foundation.
- `tests/` - lightweight dependency-free regression tests for the current stable checkpoint.
- `docs/history/` - dated patch, hotfix-installation, and legacy manual-test notes retained for historical reference only.
- `assets/` - Tailwind source CSS and JavaScript assets.
- `public/assets/css/tailwind.css` - generated runtime stylesheet served by the app.
- `migrations/` - SQL migrations and migration runner.
- `attendance_records` remains the one-row-per-employee/day summary; the additive `attendance_sessions` foundation stores physical intervals for the staged multi-session architecture.

## Required Extensions

- PHP `mbstring` is required by the application.
- PDO and the PDO MySQL driver are required for database access.

## Setup

1. Create a MySQL database, for example `smart_attendance`.
2. Configure environment variables or use the local defaults in `config/database.php`:

```text
APP_BASE_URL=http://localhost:8000
APP_TIMEZONE=Asia/Yangon
DB_HOST=127.0.0.1
DB_NAME=smart_attendance
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4
DB_TIMEZONE=+06:30
```

3. Run migrations from the project root:

```bash
php migrations/run_migrations.php
```

Run the same command after pulling updates. The July 22 security migration adds `users.session_version` with a safe default for existing accounts. The July 28 company schedule foundation migration adds `company_profiles`, `work_schedule_templates`, and `work_schedule_days`, then seeds one default company profile and one default office schedule. The Workforce Policy Center migration adds policy-version metadata, attendance/overtime policy settings, effective-dated assignments, Attendance V3 fields, and a company assignment seeded from the existing default schedule. The August migrations add optional leave attachments, Early Departure workflow fields, the `out_of_schedule` attendance state, the additive `attendance_sessions` child table, the nullable employee-profile photo filename, leave reason categories, the Department Head role, scheduled policy-assignment notifications, and Notification Center V2 fields for category, priority, structured context, deduplication, archive, and cancellation. After the validated Phase 4 historical backfill, employee self-service Check In/Check Out uses the Phase 5 transactional child-session write path while `attendance_records` remains the one-row-per-employee/day compatibility summary.

4. Configure trusted application time. OAAS defaults to `Asia/Yangon`, while every MySQL connection sets its session offset to `+06:30`. Keep `APP_TIMEZONE=Asia/Yangon` and `DB_TIMEZONE=+06:30` in the web-server environment unless the whole deployment is intentionally moved to another timezone. Official attendance timestamps come from PHP server time; browser/device time is display-only and is never accepted as the authoritative Check In or Check Out value. On the Windows demonstration computer, enable **Set time automatically** and synchronize the clock before attendance testing.

5. Configure email verification delivery as needed. For local development, copy `config/mail.local.example.php` to `config/mail.local.php` and edit that local file. The default `transport` is `log`, which records delivery metadata only and omits verification codes and message content. Gmail SMTP delivery works through `config/mail.local.php`; use `transport => smtp` for Gmail or Mailtrap-style testing.

6. Install Composer dependencies when Composer is available locally. PHP must have `pdo`, `pdo_mysql`, and `mbstring` enabled; Composer checks these extensions. Composer dependencies are required for the Dompdf fallback used by PDF report export. Excel `.xlsx` export is generated by the built-in OAAS writer and does not require an additional spreadsheet package.

```bash
composer install
```

The app still runs without a `vendor/` directory because it keeps the existing project autoloader as a fallback, but PDF export will show a localized error until dependencies are installed. Excel `.xlsx` export remains available because it is dependency-free. Myanmar-language PDF export uses a local Chrome/Edge/Chromium engine when available so Burmese text is shaped correctly. Browser discovery prefers the configured path, then `OAAS_CHROME_PATH`, the legacy `SAPAS_CHROME_PATH` fallback, `CHROME_PATH`, standard macOS, Windows, and Linux locations, then `PATH`, before falling back to Dompdf.

7. Install UI tooling and build the Tailwind runtime stylesheet:

```bash
npm install
npm run build:css
```

## Mail Configuration

Mail settings for OAAS verification emails belong in `config/mail.local.php` for local development and demos. This file is ignored by Git. Never commit real Gmail addresses, App Passwords, or other SMTP credentials.

Setup:

```bash
copy config\mail.local.example.php config\mail.local.php
```

For dry runs, leave:

```php
'transport' => 'log',
```

Log mode does not send email. It records non-secret delivery metadata only; verification codes and message content are deliberately omitted. Configure SMTP when an employee must receive and enter a real verification code.

For Gmail SMTP testing, set these values in `config/mail.local.php`:

```php
'transport' => 'smtp',
'host' => 'smtp.gmail.com',
'port' => 587,
'username' => 'your-gmail-address@gmail.com',
'password' => 'your-16-character-google-app-password',
'encryption' => 'tls',
'from_email' => 'your-gmail-address@gmail.com',
'from_name' => 'Office Attendance and Analytic System (OAAS)',
'verification_subject' => 'Your Office Attendance and Analytic System verification code',
'local_host' => 'localhost',
```

Gmail still requires manual account setup outside OAAS: enable 2-Step Verification on the Google account, create an App Password, and use that App Password as `password`. Do not use your normal Gmail password.

`local_host` is optional and supplies the hostname used by the SMTP `EHLO` command. It does not affect code generation, expiry, or the address where the employee enters the code.

If only dry-run delivery metadata appears in the PHP error log, confirm the active file is named exactly `config/mail.local.php`, set its transport to `smtp`, and replace every placeholder; files such as `mail.local.off` are not loaded. Delivery failures are shown on the verification screen and recorded without passwords, verification codes, or message content. Unsupported or misspelled transports fail explicitly instead of silently falling back to log mode.

## Security Hardening Phase 1

- `.env`, `.env.local`, and `config/mail.local.php` are local-only files. Never commit, share, or place them in release archives. Keep real database and SMTP credentials outside source control and use `.env.example` plus `config/mail.local.example.php` only as placeholder templates.
- Protected requests revalidate the authenticated user against the database. Missing, inactive, role-changed, or session-version-mismatched accounts are signed out with a generic message.
- Password reset, role change, activation, and deactivation atomically increment `users.session_version`, invalidating older sessions immediately on their next protected request.
- Administrators cannot deactivate their own account or remove their own administrator role. The final active administrator cannot be deactivated or downgraded.
- New and reset passwords require at least 10 characters, one letter, one number, a matching confirmation, and must not be an extremely common password.
- Employee account and profile creation/update operations are committed together or rolled back together.

## Authenticated Self Profile

Authenticated Administrators, Department Heads, and Employees can open `GET /profile` without an employee ID in the URL. Every self-profile read/write derives the target from the authenticated session. Users may update only their own phone number, address, and profile photo; name, account email, role, status, department, position, staff code, employment type, join date, expected hours, and overtime eligibility remain read-only. Email is shown as account identity/security information with its verification state. Administrators retain their separate employee-management screens, where an existing account email is also read-only and role/status/password access consequences are disclosed before save.

The profile Account Security section links every authenticated role to `GET /profile/password`. `POST /profile/password/update` requires CSRF validation, verifies the current password, applies `PasswordPolicyService`, updates only the session user's password hash, increments `users.session_version`, invalidates the current session, and returns the user to Login to authenticate with the new password. The form warns that the password change signs the user out.

Profile photos use `POST /profile/photo` and `POST /profile/photo/remove`, both protected by authentication and CSRF checks. Accepted uploads are real JPG, PNG, or WebP images no larger than 2 MB. OAAS verifies the upload status, actual size, extension, MIME type, and decoded image metadata and stores a cryptographically random filename under ignored `storage/private/profile_photos/`. `GET /profile/photo` serves a user's own referenced image; the same endpoint accepts a validated employee ID only for authenticated administrators viewing Employee Details. Filenames never come from request data. The database stores only `employee_profiles.profile_photo_filename`; apply migrations before opening the profile module.

The profile page presents the effective Workforce Policy resolved for today through `WorkScheduleResolverService` and `EmployeeWorkPolicyPresenter`, including assignment source, schedule, expected regular work, grace period, and overtime eligibility. The legacy `expected_daily_work_hours` field remains available for compatibility and historical behavior but is labeled accordingly and is not presented as the authoritative current schedule. Administrator and Department Head topbars resolve their own private profile photos using the same avatar path as Employees.

After pulling this phase, apply the database migration:

```bash
php migrations/run_migrations.php
```

## Optional Office Network Restriction

Employee self Check In and Check Out can be limited to exact approved office IPv4 or IPv6 addresses. The restriction is disabled by default, does not change Attendance V3 calculations, and does not apply to authorized manager attendance corrections.

```text
ATTENDANCE_OFFICE_NETWORK_ENABLED=0
ATTENDANCE_OFFICE_ALLOWED_IPS=127.0.0.1,::1
```

When enabled, OAAS uses only `REMOTE_ADDR` and requires it to match one configured address. It does not trust `X-Forwarded-For`, `Forwarded`, or `X-Real-IP`; deployments behind a reverse proxy must leave this application-level restriction disabled unless the immediate proxy address is intentionally the approved address. There is no automatic localhost bypass. Keep the feature disabled for local demonstrations, or explicitly list the addresses used by the demonstration machine.

If the restriction is enabled with an empty or invalid allowlist, employee self Check In and Check Out fail closed. Update the exported environment values and restart the PHP/web-server process when an office address changes. Administrator and in-scope Department Head create/edit remain available as correction paths during network outages or configuration changes.

## Safe Release Packaging

Generate a timestamped release ZIP from the project root:

```bash
php scripts/package-release.php
```

The archive is written under `releases/`. It includes application source, migrations, tests, documentation, and required public assets while excluding local secrets, Git metadata, dependencies, temporary uploads, generated reports, session files, backups, logs, scratch diagnostics, transient test-output files, cache/temp data, and prior ZIP files. Install excluded dependencies from `composer.lock` and `package-lock.json` in the target environment.

Start the local server with:

```bash
php -d upload_max_filesize=5M -d post_max_size=6M -S localhost:8000 -t public public/index.php
```

When used as the PHP built-in server router, `public/index.php` passes existing files under `public/` through to the server. This keeps `/assets/css/tailwind.css` and other static assets available while application routes continue through the front controller.

WAMP/Apache deployments running PHP as `php_module` apply the matching 5 MB file and 6 MB request limits from `public/.htaccess` when overrides are enabled. PHP's built-in server does not read `.htaccess`, so keep the `-d upload_max_filesize=5M -d post_max_size=6M` flags shown above. The application still validates the 5 MB leave-attachment limit and the separate 2 MB profile-photo limit itself.

For Mailtrap-style testing, use the SMTP host, port, username, and password from the Mailtrap inbox. Server environment variables such as `MAIL_TRANSPORT`, `MAIL_HOST`, `MAIL_PASSWORD`, and `MAIL_LOCAL_HOST` still override `config/mail.local.php` when your web server exports them, but OAAS does not auto-load `.env` files.

Tailwind source lives in `assets/css/tailwind.css`, configuration lives in `tailwind.config.js`, and the generated app stylesheet remains `public/assets/css/tailwind.css`. `package.json` and `package-lock.json` are the source of truth for UI tooling; `node_modules/` is intentionally ignored and should be recreated with `npm install`.

6. Configure the web server document root to `public/`, or use PHP built-in server for local testing:

```bash
php -d upload_max_filesize=5M -d post_max_size=6M -S localhost:8000 -t public public/index.php
```

Open:

```text
http://localhost:8000/login
```

## Automated Regression Tests

OAAS includes a small PHP-native regression test runner for checkpoint protection. It does not require PHPUnit. The suite now includes unit-style checks, structural source-inspection guards, and guarded MySQL-backed integration tests that create/reset only a test database whose name contains `test`.

Run from the project root:

```bash
php tests/run.php
```

By default the integration helper uses `sapas_test`. Override the test database connection only when needed:

```bash
OAAS_TEST_DB_NAME=oaas_test php tests/run.php
```

On PowerShell:

```powershell
$env:OAAS_TEST_DB_NAME='oaas_test'
php tests/run.php
```

OAAS-prefixed tooling variables are preferred. Existing `SAPAS_TEST_DB_*` names remain supported as legacy fallback aliases, and the unchanged `sapas_test` default database name remains available for compatibility.

Current automated checks cover authentication/session basics, six-digit verification-code generation and hashed storage, valid/invalid/expired code handling, resend rotation and attempt limits, verification-email branding, employee employment/work-rule options, employee self-profile ownership/contact-field guards, private profile-photo validation/removal, admin-versus-employee photo-view authorization, shared avatar fallback/topbar state, English/Myanmar language switching, strict date/month/range validation, attendance grace boundaries, employee-specific expected minutes, exact duration/formatting, schedule-boundary and special-day overtime, Workforce Policy Center resolution and centralized attendance recalculation, transactional attendance rollback/duplicate/clock-out guards, transactional leave overlap/state/attendance-conflict rollback, controlled database failure handling, cross-platform PDF browser discovery, real seeded login checks, report query filters, notification read ownership, release-package hygiene, editable/runtime asset synchronization, and structural route/security guards.

The integration test schema in `tests/Support/TestDatabase.php` is an intentionally maintained minimal mirror, not a migration runner. Update it alongside relevant production migrations/schema changes to avoid drift.

For seeded authenticated browser QA, prepare a separate browser test database:

```bash
php tests/browser_seed.php
```

Then start the app with `DB_NAME=sapas_browser_test` and follow `tests/Browser/seeded_flows.md`.

## Final Release Verification

Before a demonstration or submission build, use a machine with PHP `pdo_mysql`, `mbstring`, and `zip` enabled, then run:

```powershell
composer install
npm install
npm run build:css
php migrations/run_migrations.php
php tests/run.php
php scripts/package-release.php
```

Verify the main admin and employee flow against a dedicated test database: login, Check In, Check Out, weekend/holiday attendance, leave submission and decision, analytics, PDF export, Excel export, notifications, audit logs, language switching, pagination, and logout. The release archive must not contain `.env`, `config/mail.local.php`, `.uploads`, generated reports, sessions, backups, private credentials, logs, dependency folders, Git data, or previous ZIP files.

The editable JavaScript source at `assets/js/main.js` and the served runtime copy at `public/assets/js/main.js` must remain identical. Rebuild CSS after changing `assets/css/tailwind.css`.

## Development Sample Data

For realistic local UI review, screenshots, analytics, and report testing, OAAS includes a repeatable dev-only workforce seed:

```bash
OAAS_ALLOW_DEMO_SEED=1 php seeds/seed_demo_data.php
```

On PowerShell:

```powershell
$env:OAAS_ALLOW_DEMO_SEED='1'
php seeds/seed_demo_data.php
```

The seed creates a coherent internal workforce using the configured demo email domain, with professional department descriptions, employee profiles, official 2026 Myanmar public holidays, clearly identified company holidays, leave decisions, notifications, attendance history through July 21, and policy-calculated demonstration attendance for August 1–4, 2026. The August records include normal, late, early-departure, absent, incomplete, weekend/non-working-day, and calculated-overtime examples. Existing non-seed attendance on the same employee/date is protected and skipped. General seeded employee passwords are `Password123!`. This script is for local development databases only.

The holiday calendar and August dataset can also be refreshed independently after the main workforce seed:

```bash
OAAS_ALLOW_DEMO_SEED=1 php seeds/seed_2026_holidays.php
OAAS_ALLOW_DEMO_SEED=1 php seeds/seed_august_2026_demo.php
```

On PowerShell:

```powershell
$env:OAAS_ALLOW_DEMO_SEED='1'
php seeds/seed_2026_holidays.php
php seeds/seed_august_2026_demo.php
```

The legacy `SAPAS_ALLOW_DEMO_SEED` and `SAPAS_DEMO_EMAIL_DOMAIN` names remain accepted as fallback aliases. New setup should use `OAAS_ALLOW_DEMO_SEED` and `OAAS_DEMO_EMAIL_DOMAIN`.

Windows users can run both updates together from the project root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/apply_demo_updates_2026_08.ps1
```

The independent August seed is idempotent: it updates only rows carrying the `August 2026 demo seed` remark, skips unrelated existing records, and always recalculates Attendance V3 and overtime through the centralized services.

## Current Features

- Administrator, Department Head, and Employee login with database-backed role/session revalidation.
- Inactive users are blocked from login and cannot access protected pages.
- Admin-created accounts require email verification before normal login. Six-digit verification codes expire after 15 minutes, are stored only as password hashes, allow at most five incorrect attempts, and can be resent with a 10-minute cooldown.
- The Add Employee form requires the Administrator to assign a unique staff code containing exactly six ASCII digits; codes are never auto-generated. The form also includes account status, optional immediate verification-code delivery, department, position, employment type, join date, expected daily work hours, overtime eligibility, phone, and address. Employee create/update validation accepts English and Myanmar names, normalizes email domains and supported Myanmar phone formats, and keeps password rules centralized. It does not include salary or payroll fields.
- Departments, positions, holidays, and employees can be managed by admins.
- Admins can manage the Workforce Policy Center foundation, including organization information, reusable policy versions, weekly schedules, effective-dated company/department/employee assignments, attendance settings, overtime settings, and overtime review decisions.
- Employees and Department Heads can view a personal dashboard, attendance history, the official holiday calendar, and `GET /my-work-policy`, a read-only projection of the currently effective policy resolved for their authenticated identity. Holiday and policy-management actions remain admin-only.
- Employees and Department Heads can check in and check out for themselves on normal workdays, weekends, and company holidays unless approved leave blocks the date.
- Overtime V3 stores raw component minutes, calculated overtime, approved overtime, and approval state separately. New attendance writes resolve the employee's effective policy by business date; non-working-day and holiday work keep special-day status with zero weekday late minutes. Approved leave and overtime-ineligible employees remain excluded. No payroll rates or salary calculations are included.
- Employees and Department Heads can submit leave requests with a required free-text reason (maximum 1,000 characters), filter and paginate their own leave history, open their own leave details, and cancel only their own pending leave requests.
- Administrators can perform leave review and decisions system-wide, including Department Head requests. Department Heads can review supporting documents and approve, reject with a note, or cancel requests only for Employee accounts in their assigned department. A Department Head cannot decide another Department Head's request or their own request.
- Approved leave blocks employee check-in on covered working days and is shown as separate context in attendance analytics.
- Administrators can view and filter attendance system-wide and create/edit corrections for Employee records. Department Heads can view/filter and create/edit corrections only for Employee records in their assigned department. The Attendance listing excludes `absent` records; those records are shown only on the separate Absence page. Attendance deletion is not permitted through the application.
- Attendance analytics are available system-wide to administrators and within the assigned department to Department Heads, with bilingual labels, monthly KPI context, approved-leave context, overtime context, and employee drill-down links. A missing attendance row for a resolved working day is counted as absent only strictly after that employee's resolved scheduled start plus 60 minutes. The calculation excludes approved leave, holidays, non-working days, and future/pending dates; it is derived at read time, creates no attendance row, and yields to any later real attendance record.
- Administrators can open Reports across the system; Department Heads can use the same reports and PDF/Excel exports only for their assigned department. Department scope is enforced inside report filters and data services, including Employee Attendance Detail and Leave Request exports, and cross-department employee IDs are rejected. Active filters are preserved and exports contain the complete authorized filtered dataset rather than only the current page. Attendance Summary and Employee Attendance Detail exclude `absent` rows; Absence now has a dedicated report screen plus complete PDF/Excel exports, while the legacy late/absent route reports late attendance only.
- Authenticated users have Notification Center V2 with category, priority, and status filters; unread/read controls; recipient-owned archive; pagination; and the topbar unread count. Policy assignments create a high-priority reminder for every active account inside the assigned company, department, or employee scope. Reminders become visible at midnight one day before the effective date (or immediately for late assignment), and the reconciliation command includes staff who enter the scope after the assignment was created. Notifications also cover leave submission, authorized Administrator/Department Head leave decisions, pending overtime review, and overtime decision updates.

Run the policy reminder reconciler once per day (for example through Windows Task Scheduler) after migrations:

```powershell
php .\scripts\reconcile_policy_notifications.php
```
- English and Myanmar language switching is available through the shared compact language control in the app and guest shells, alongside bilingual sidebar navigation, topbar labels, role labels, login/auth surface text, common actions, statuses, leave type display labels, and selected shared auth/security messages.
- Admin and employee dashboards use the bilingual dashboard UI direction: the admin dashboard is organized around today's operations, priority watchlists, shortcuts, holidays, and recent attendance; the employee dashboard is organized around today's status, primary clock action, leave context, monthly summary, and recent attendance.
- Main operational and analytics views now use bilingual-ready display labels for high-traffic attendance, leave, reports, employee, master-data, and analytics surfaces while preserving stored values and user-entered content.

## UI and Layout System

- Shared render flow is implemented in `src/Core/Controller.php`.
- App pages use `views/layouts/app.php` with shared partials: `sidebar.php`, `topbar.php`, `page_header.php`, and `flash.php`.
- Guest pages use `views/layouts/guest.php`.
- The login screen uses one centered light sign-in panel with a large OAAS wordmark and the full Office Attendance and Analytic System name. It intentionally omits the former navy promotional side, feature cards, statistics, charts, and illustrations while preserving language switching, accessible email/password fields, password visibility, security reassurance, short-screen responsiveness, and existing authentication behavior. OAAS does not provide a separate public `/about` page, public signup, or demo login.
- Email verification uses a focused single-panel guest variant and carries the full Office Attendance and Analytic System identity through the screen title, sender name, subject, message body, and success copy.
- Branding is handled through `views/partials/brand.php`, using a compact "O" mark with the visible "OAAS" wordmark and supporting product text. The login screen also shows the full official name, "Office Attendance and Analytic System", while preserving the existing localized authentication flow.
- Icons are handled through `views/partials/icon.php`, a small server-rendered line-icon set used for shared navigation, dashboard scan cues, login fields, guest capability cards, report cards, notifications, and empty states.
- Shared interaction polish is defined in `assets/css/tailwind.css` and `assets/js/main.js`: the sidebar uses a sliding navigation rail; buttons use semantic gradient/glass treatments, tactile press states, and consistent focus/disabled states, with pointer-reactive spotlights reserved for edit/delete actions; interactive cards, tables, forms, feedback, and dialogs use restrained motion. Shared list pagination renders a compact five-page window with previous/next controls. Pointer effects run only for fine pointers, and `prefers-reduced-motion` disables decorative motion.
- Views receive `csrf_token`, `flash`, `userName`, `userRole`, `currentPath`, locale metadata, language switch URLs, and a small translation callable automatically.
- Localization uses `src/Core/Localization.php` with simple PHP files in `lang/en.php` and `lang/my.php`. The default language is English, selected language is stored in session, and `?lang=en` or `?lang=my` can change it.
- Shared vocabulary keys now cover common actions, common labels, account/attendance/leave statuses, leave type labels, report/export labels, login/auth labels, and high-traffic validation/flash messages.

### Phase 4 Lite UI modernization

- The authenticated shell now uses role-aware navigation groups, clearer current-page context, a keyboard-accessible skip link, and a true mobile drawer with backdrop, Escape handling, focus return, and background-scroll locking.
- Shared feedback, form, button, table, and empty-state styling has been tightened around a restrained navy, white, and sky palette. Responsive tables retain horizontal scrolling instead of forcing page-wide overflow.
- Login keeps the existing authentication flow while presenting a shorter product overview, a password-visibility control, and concise security reassurance. Dashboards, analytics, and reports receive presentation-only hierarchy improvements; routes, permissions, filters, calculations, and attendance rules are unchanged.
- UI/UX Polish Sprints 1 and 2 add the shared OAAS mark, a tinted application canvas with clear primary/nested/semantic surface tiers, the focused single-panel login, tighter dashboard and employee-form presentation, and standardized table headers, filter actions, status badges, export controls, and empty states. These changes remain view/CSS/localization-only and do not alter queries, calculations, routes, or permissions.
- The progressive-enhancement source remains `assets/js/main.js`, with its served mirror at `public/assets/js/main.js`. Rebuild the canonical runtime CSS after presentation changes with `npm.cmd run build:css` on Windows or `npm run build:css` elsewhere.

## Security Protections

- Passwords are hashed with `password_hash` and verified with `password_verify`.
- Password creation and reset share one centralized minimum policy and confirmation check.
- Inactive users cannot log in.
- After a correct password is entered, an unverified user is sent to the dedicated verification-code screen instead of receiving an authenticated session.
- Email verification stores only a password hash of the six-digit code, expires codes after 15 minutes, limits incorrect attempts to five, and clears code state after successful verification.
- Authenticated employees whose accounts become inactive are signed out before returning to login.
- Session ID is regenerated after successful login.
- Session cookies are configured with `HttpOnly` and `SameSite=Lax`.
- Session idle timeout and maximum lifetime are enforced.
- Protected requests validate the current database role, active status, and `session_version`; mismatches clear authentication and CSRF state.
- Department Head sessions additionally require a valid department and refresh `department_id` from the database before authorization, preventing stale or client-selected scope.
- Role, status, and password changes atomically increment `session_version` so older sessions cannot retain stale access.
- Self-deactivation, self-role downgrade, and removal of the final active administrator are rejected server-side inside guarded transactions.
- Login rate limiting is implemented in the session layer.
- POST actions are protected by CSRF validation with safe internal failure redirects.
- System-wide mutations use explicit Administrator checks. Delegated attendance and leave mutations accept Administrators or Department Heads, then enforce the target Employee's database department in both the controller and transactional service. Requested cross-department filters and writes are rejected.
- Personal attendance, leave, profile, notification, and work-policy access is scoped to the logged-in identity.
- SQL uses prepared statements.
- Views escape dynamic output with `htmlspecialchars`.
- Uncaught bootstrap/runtime exceptions return a generic service-unavailable response and log details server-side.

## Attendance Rules

- Working days, scheduled start/end, break rules, grace minutes, and expected regular minutes come from the employee's resolved Workforce Policy Center policy for the business date.
- The seeded default policy starts at `09:00` and has a 30-minute grace threshold. Arrival before or at the grace threshold results in `late_minutes = 0`; arrival after the threshold calculates late minutes from scheduled start, not from the grace threshold.
- Supported statuses include `present`, `absent`, `holiday`, and `weekend`.
- Holidays come from the `holidays` table and override the weekly schedule.
- Non-working days are resolved from the employee's effective schedule; OAAS does not hardcode Saturday or Sunday as weekends.
- Employees may check in on holidays and resolved non-working days when policy allows it; the saved compatibility status remains `holiday` or `weekend`, and Attendance V3 stores `day_type`.
- Inactive employees cannot check in or check out.
- Employees cannot check in on days covered by approved leave.
- Approved leave does not create or update `attendance_records`; leave remains stored only in `leave_requests`.
- Admin attendance create/edit rejects present or absent records that conflict with approved leave.
- `expected_daily_work_hours` is normalized once to integer minutes. Missing, non-numeric, below-0.5-hour, or above-24-hour values fall back to 8 hours; valid employee-specific values are preserved.
- Completed work duration is `check_out_at - check_in_at`. Check-out before check-in is rejected unless an explicit overnight policy allows a next-day checkout. Incomplete records have no completed duration or overtime and display an em dash.
- New centralized writes store raw overtime components, calculated overtime, approved overtime, approval state, policy version, assignment, day type, and calculation version while preserving legacy fields for existing dashboards, reports, PDF, and Excel exports.

## Workforce Policy Center

The admin-facing Workforce Policy Center is organized for ordinary administrators around four primary workspaces: Overview, Work Rules, Assignments, and Overtime Review. Policy History and Organization Information remain available from the Advanced menu. Creating or changing work rules uses `Create New Work Policy` / `Change Work Rules`, which creates a protected draft from the current, blank, or selected existing policy and schedules it for a future effective date.

Admin routes:

- `GET /admin/company-profile`
- `GET /admin/company-profile/edit`
- `POST /admin/company-profile/update`
- `GET /admin/work-schedules`
- `GET /admin/work-schedules/create`
- `POST /admin/work-schedules`
- `GET /admin/work-schedules/edit`
- `POST /admin/work-schedules/update`
- `POST /admin/work-schedules/toggle-status`
- `POST /admin/work-schedules/clone`
- `POST /admin/work-schedules/update-weekly-schedule`
- `POST /admin/work-schedules/update-attendance-rules`
- `POST /admin/work-schedules/update-overtime-rules`
- `POST /admin/work-schedules/schedule-version`
- `POST /admin/work-schedules/cancel-schedule`
- `POST /admin/work-schedules/archive-version`
- `GET /admin/work-schedules/assignments`
- `GET /admin/work-schedules/assignments/create`
- `POST /admin/work-schedules/assignments`
- `GET /admin/work-schedules/assignments/edit`
- `POST /admin/work-schedules/assignments/update`
- `POST /admin/work-schedules/assignments/end`
- `POST /admin/work-schedules/assignments/remove-employee-override`
- `GET /admin/overtime`
- `GET /admin/overtime/show`
- `POST /admin/overtime/approve`
- `POST /admin/overtime/reject`
- `POST /admin/overtime/adjust`
- `POST /admin/overtime/reset-pending`

Seeded default presentation values:

- Company: `Office Attendance and Analytic System`
- Short name: `OAAS`
- Timezone: `Asia/Yangon`
- Default schedule: `OAAS Standard Office`
- Working days: Monday-Friday
- Non-working days: Saturday-Sunday
- Time: `09:00`-`17:00`
- Break minutes: `0`
- Grace minutes: `30`
- Expected minutes: `480`

Existing databases may still contain the original legacy company and default-policy labels. Organization and work-policy screens present those defaults as the OAAS identity without rewriting stored profile or schedule data; custom organization and policy names remain unchanged.

The foundation validates company name, email format, IANA timezone, allowed date/time formats, active default schedule, seven weekday rows, required working-day times, explicit overnight schedules, break start/end pairing, expected-minute consistency, effective date ordering, and reasonable break/grace/expected-minute ranges. Policy-version changes are admin-only, POST/CSRF protected, transactional, and audit logged.

New employee/admin attendance writes use `WorkScheduleResolverService` and `AttendanceCalculationService` through `AttendanceOperationService`. Assignment changes use `WorkScheduleAssignmentService`; overtime decisions use `OvertimeReviewService` and keep decision history in `overtime_decisions` while updating the current approval state on `attendance_records`. Historical attendance rows are not bulk-rewritten; run `php scripts/diagnose_attendance_consistency.php --limit=100` for a dry-run review report before any manual repair.

Employee `GET /my-work-policy` uses that same `WorkScheduleResolverService` for the logged-in employee and current application business date. It displays exactly one effective Employee > Department > Company > compatibility-default result, then reads the seven normalized weekday rows belonging to the resolver-selected template for presentation only. The page accepts no employee, assignment, or template identifier and exposes no policy-management actions.

The multi-session architecture remains staged but employee self-service writes are now cut over. `attendance_sessions` can hold multiple physical intervals under one daily `attendance_records` parent, and `AttendanceIntervalAggregationService`/`AttendanceSessionReadService` provide interval-union and dual-read support when child sessions exist. The Phase 4 tooling (`AttendanceSessionBackfillPlanner`, `AttendanceSessionBackfillValidator`, `AttendanceSessionBackfillService`, and `php scripts/rehearse_attendance_session_backfill.php`) audits legacy rows, supports clone-only rehearsal inserts, and exposes a separate guarded live backfill only after full readiness validation. Phase 5 makes employee Check In/Check Out transactional child-session writes: only one open session is allowed per employee, a completed same-day session no longer blocks a later Check In, Check Out closes only the active child, preview and parent summary values are recalculated from the union of all daily sessions, and audit entries include the affected session. An open session read within the same whole second as an in-schedule Check In recognizes scheduled participation immediately while keeping actual work, scheduled overlap, and overtime duration at zero until measurable time exists. `attendance_records` retains its unique `(user_id, attendance_date)` key and stores first Check In, final Check Out when no child is open, union working time, Attendance state, Early Departure, and overtime compatibility summaries. Admin manual attendance create/edit remains on the daily-parent workflow pending a later session-correction phase.

Final V3 verification hardens the read side as well. `WorkScheduleResolverService::resolveMany()` now batch-loads employees, effective assignments, referenced schedule versions, schedule days, attendance/overtime policy settings, company profile fallback data, and holidays for the requested date range instead of resolving one attendance row at a time. The regression suite uses a test-only `QueryCountingPDO` wrapper to prove that a 500 employee/date resolver fixture stays within a bounded query budget (`<= 12` resolver SQL executions) and that the old single-row path remains materially larger. Dashboard and analytics schedule checks use the resolver when available, so Saturday/Sunday are no longer assumed to be weekends.

`AttendanceReportContextService` is the shared attendance read context for report/export records. It prefers stored Attendance V3 values on finalized rows, marks deterministic legacy fallback rows internally as `resolved_legacy`, and does not write fallback calculations back to the database. Screen reports, PDF source data, and Excel source data continue to flow through the same report services; generated exports should be visually inspected on the target Windows machine before release. Attendance record `2155` remains a diagnostic/manual-review item and must not be changed until its real-world outcome is known.

Final UAT cleanup on 2026-07-29 archived the previous test-only policy versions and confirmed that no UAT policy can become active. A temporary `UAT Overtime Employee` fixture was created for browser acceptance of Overtime Review, then removed after testing; audit history was preserved and no production employee attendance was changed. Overtime Review now rejects stale terminal decisions so a second browser tab cannot approve or reject a record after its latest state has already changed. The resolver diagnostic prints the measured performance fixture with `php scripts/measure_policy_resolver_queries.php`; the current measurement is 500 requests, legacy path 4890 queries, batch path 9 queries.


### Multi-session backfill rehearsal (Phase 4)

The Phase 4 tool is deliberately safe-by-default. A normal run is read-only:

```bash
php scripts/rehearse_attendance_session_backfill.php
```

It prints a summary and writes a detailed JSON report under `storage/generated-reports/`. To rehearse inserts, first clone the application database to a disposable name such as `smart_attendance_backfill_test`, point `DB_NAME` at that clone, and then run:

```bash
php scripts/rehearse_attendance_session_backfill.php --apply
```

Clone rehearsal `--apply` remains refused for `smart_attendance` and for database names that do not contain a clear test/rehearsal marker. The rehearsal inserts only completed child-session rows, is idempotent for exact existing matches, skips historical missing-checkout rows, blocks ambiguous/conflicting data, verifies legacy single-session calculations against the session calculator, and checks that `attendance_records` plus `overtime_decisions` remain unchanged. Its report keeps all persisted mismatch records visible while separating narrowly reviewed legacy drift from unexplained persisted drift; reviewed drift alone does not block live-backfill readiness, while semantic mismatches, manual-review rows, conflicts, resolution errors, and unexplained drift remain blocking.

After a clone rehearsal and a full read-only `smart_attendance` report both return `Ready for live backfill: YES`, the same tool exposes a separate guarded `--live-apply` path. It requires the exact `smart_attendance` database, the exact confirmation phrase `BACKFILL_SMART_ATTENDANCE`, a verified non-empty `.sql` backup containing the attendance tables, and the exact expected candidate count from the latest read-only report. The live operation runs preflight, inserts, parent/decision preservation checks, and postflight inside one transaction and rolls back on any failed guard. The backfill itself does not perform the write cutover; Phase 5 separately enables employee self-service Check In/Check Out session writes in application code after the historical child rows have been validated.

### Multi-session transactional write cutover (Phase 5)

Employee self-service Check In/Check Out now writes physical intervals to `attendance_sessions` when the production service is explicitly constructed with both the schedule resolver and session model. The employee row and daily parent are locked in one transaction. Check In refuses any already-open child session across the employee, creates or reuses the unique daily parent, inserts one open child session, recalculates the daily union, and persists the compatibility summary. After a completed session, another same-day Check In is allowed. Check Out finds and locks only the employee's active child session, previews the proposed close against all daily child intervals, validates Early Departure context, closes that child, recalculates the union, then persists the daily parent and audit data atomically.

The daily parent remains a compatibility/read-summary row rather than a physical interval. While a child is open its final `clock_out_time` is null; when all children are closed it stores the latest completed checkout. `working_hours` uses union time so overlapping/touching intervals are not double-counted. An Early Departure candidate remains provisional until the scheduled end; the checkout modal still captures its reason, reports/analytics do not count it as final early, and a later return can clear it because the whole day is recalculated. If a reviewed approval-required overtime candidate is no longer final because a later session is opened or the calculated candidate changes, the prior approved value is cleared and a `reset_to_pending` decision is appended before the parent is committed. Admin manual attendance create/edit is intentionally not converted into session editing in Phase 5.

## Correctness Hardening Phase 2

- `DateValidationService` strictly validates `Y-m-d`, `Y-m`, optional filters, and ordered ranges. Invalid filters produce localized feedback and never broaden into unfiltered queries.
- `AttendancePolicyService` is the single source for expected minutes, late minutes, actual duration, overtime, derived saved status, derived late display status, and duration formatting across controllers, models, dashboards, analytics, reports, and PDF rows.
- `AttendanceOperationService` wraps employee clock actions and authorized manager attendance create/edit in transactions. It locks the affected employee/date state, rechecks the Administrator/Department Head target scope, approved leave, and the existing unique employee/date constraint, derives server-owned values, and rolls back on failure.
- `php scripts/diagnose_attendance_consistency.php --limit=100` is a read-only dry run for historical attendance rows whose stored status/times no longer match the centralized attendance policy. It reports rows for manual review and does not repair data.
- `LeaveOperationService` wraps creation, approval, rejection, and cancellation in transactions. It locks the relevant employee/request state and rechecks overlaps, attendance conflicts, ownership, and transition state immediately before writes.
- New employee leave submissions store a leave-type-dependent reason category separately from required free-text reason details. `LeaveReasonCatalog` supplies one mapping for server validation, localized browser options, and display/export labels. The existing nullable `leave_requests.reason_category VARCHAR(32)` column is sufficient, earlier generic values remain displayable without backfill, and admin `review_note` remains separate.
- Database access failures are raised as controlled operation errors, logged server-side without exposing SQL or credentials, and shown to users through localized generic messages. A valid empty result remains an ordinary empty array or count.

## Maintainability and Audit Hardening Phase 3

- Report input normalization is centralized in `ReportFilterService`; focused attendance-summary, employee-attendance, leave, and exception report services prepare the shared screen/PDF result data. `AnalyticsService` prepares analytics independently of request handling.
- Analytics and report retrieval batch-load employee, attendance, approved-leave, holiday, department, and position context. The Phase 3 migration adds only the composite indexes used by the optimized date/status/scope queries.
- New and edited staff codes are Administrator-assigned, unique, and exactly six ASCII digits. An Administrator must replace a historical non-six-digit code before saving any other changes to that account; the application never silently generates or converts a live code. Run `php scripts/audit_staff_codes.php` for a read-only list of historical accounts requiring review.
- Administrators have a read-only, paginated `/admin/audit-logs` workspace. Important account, attendance, leave, master-data, security, and PDF-export events store redacted structured changes; critical administrative writes and their audit events commit in the same transaction where practical. Login telemetry and master-data follow-up events are best effort so audit availability does not incorrectly block an otherwise valid login.
- Demo accounts can be previewed with `php scripts/refresh-demo-accounts.php --dry-run` and refreshed transactionally with `php scripts/refresh-demo-accounts.php`. `OAAS_DEMO_EMAIL_DOMAIN` configures the demonstration-only domain and defaults to `silveroakhr.com`; `SAPAS_DEMO_EMAIL_DOMAIN` remains a legacy fallback alias.
- Demo addresses and phone numbers are fictional. Do not send email, SMS, or other real communication to demo contact data. The refresh preserves verified demo state without weakening production verification policy and generates a different policy-compliant temporary password for every account.
- Successful refresh credentials are written only to `storage/private/demo-credentials-YYYYMMDD-HHMMSS.txt`. That directory is ignored and excluded from release packages. Never copy its contents into source, documentation, logs, `public/`, or a release archive.
- `scripts/seed_phase2a_attendance_demo.php` is scenario-fixture setup, not a live identity migration. It resolves the legacy A-G scenario accounts under row locks, rejects email/staff-code collisions, and preserves their user IDs when applying the realistic presentation identities. Reruns leave existing attendance timestamps and effective employee-assignment rows untouched, adding only missing scenario rows; do not run it against live data solely to rename employees. The old destructive `scripts/cleanup_phase2c_final_demo.php --apply` mode is intentionally retired.
- Apply schema updates with `php migrations/run_migrations.php`; the migration runner is safe to run again after a successful application.

## Tailwind CSS Status

Views reference `/assets/css/tailwind.css`, which is served from `public/assets/css/tailwind.css` because the web root is `public/`. This file is generated by the Tailwind workflow and remains the canonical runtime stylesheet.

Current design-system source:

- `tailwind.config.js` defines content scanning for PHP views/controllers/language files, OAAS semantic color tokens, Myanmar-safe font fallback, the Phase 1 ink/indigo/plum/accent palette, explicit canvas/hero/card/nested/table surface tokens, layered shadow tiers, and the small `animate-fade-in` token.
- `assets/css/tailwind.css` defines Tailwind layers plus OAAS base typography, bilingual wrapping safeguards, focus states, typographic brand-block primitives, icon/action-affordance primitives, restrained chart primitives, and the enterprise office-theme material foundation used across the shared app shell, guest/login surface, tables, and high-traffic workspaces.
- `assets/js/main.js` provides the small progressive-enhancement layer for the sliding sidebar navigation rail and edit/delete button pointer highlights. The served mirror is `public/assets/js/main.js`; the interface remains fully usable without this decorative JavaScript.
- Phase 1 separated Tier 1 app canvas/shell, Tier 2 hero and summary surfaces, Tier 3 standard data cards, and Tier 4 nested stat/insight cards. Dashboard, report, and analytics surfaces have distinct but restrained material variants while dense tables and forms remain neutral and readable.
- Phase 2 applies that material foundation more deliberately to dashboard and workspace composition: admin command-center grouping, employee daily-status grouping, stronger KPI primary/secondary/attention states, clearer action hierarchy, and opening summary bands for analytics and reports.
- The analytics workspace now follows an office management reporting model: executive attendance KPIs, a recorded-workday composition ring, employee attendance ranking against a visible 90% review target, a prioritized exception/data-gap watchlist, a dedicated localized 100% attendance recognition panel, and the full drill-down table. The visuals use the selected month and filters, calculate average attendance from employees with attended or matured expected days, keep late-present days within present totals and eligible for 100% recognition, show approved leave separately from attendance records, and reserve recognition stars for the dedicated panel rather than the employee table.
- `package.json` and `package-lock.json` define the Tailwind dependency; `node_modules/` should not be committed or bundled.
- `npm run build:css` generates the minified runtime stylesheet at `public/assets/css/tailwind.css`.

## Known Limitations

- Tailwind now has a local build workflow, compact typographic `OAAS` branding, richer office-theme color/surface/shadow hierarchy, dashboard/workspace composition refinement, office-focused analytics visuals, a small line-icon/action-affordance system, redesigned admin/employee dashboards, clearer operational tables, polished module pages, and a refined icon-led login/guest shell; broad export workflow redesign, logo work, illustration work, and feature-specific workflow redesign remain intentionally deferred.
- The automated test suite is still intentionally small. Some checks are structural guards rather than full request-execution tests. It now includes guarded MySQL integration coverage and a seeded browser-flow foundation, but it does not yet automate full browser flows, PDF rendering, every controller request/response path, or every CRUD path.
- Login rate limiting is session-based and not backed by a centralized database or IP store.
- Email verification is intentionally limited to admin-created account activation. OAAS still has no public self-signup, password reset email workflow, email preferences, digests, or module-wide email notification platform.
- Employee expected-hours and overtime eligibility now drive shared attendance, analytics, report, and PDF overtime derivation. No salary, overtime rate, or payroll calculation is included.
- Deployment documentation is minimal.
- Leave Management V1 supports an optional private PDF/JPG/JPEG/PNG attachment up to 5 MB, readable only by an administrator or the leave owner. It does not include payroll, leave balances, half-day support, multi-level approval, admin-submitted leave, leave editing, or full reporting redesign.
- Reports V2 does not yet include scheduled delivery or email delivery. The dedicated Absence workspace uses explicit existing `absent` rows; analytics may still derive non-persistent missing-day context as described above.
- Notification Center V2 is in-app only. It does not yet include email, SMS, realtime push, user preferences, or digests.
- PDF export prefers a local Chrome/Edge/Chromium engine when available, which keeps large exports outside PHP's memory-intensive Dompdf layout path and correctly shapes Myanmar text. Running `composer install` provides the Dompdf fallback, which still requires a Myanmar-capable font.
- Bilingual support currently covers shared shell/foundation text, reusable UI vocabulary, the admin/employee dashboard labels, high-traffic operational module labels, controller-generated messages in the main flows, and the redesigned analytics page. Some deeper module-specific prose remains English until later localization phases.

## Recommended Next Milestones

1. Run automated browser flows against the seeded browser QA database in CI or on the Windows demonstration machine.
2. Finalize production deployment guidance for Apache/Nginx, HTTPS, backups, mail delivery, and scheduled maintenance.
3. Add optional overtime approval, break deduction, payroll-rate, or payroll-integration workflows only after policy requirements are approved.
4. Expand leave management with balances, half-day support, or multi-level approval only when required.
5. Expand notifications with preferences, email/SMS delivery, digests, or additional events when approved.
6. Add scheduled report delivery or further export formats only when a concrete reporting requirement exists.
