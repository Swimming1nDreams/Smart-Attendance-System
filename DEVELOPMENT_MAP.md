# Smart Attendance And Performance Analytic System Development Map

SAPAS is a PHP 8+ custom MVC application for attendance management and performance analytics. The project preserves the current lightweight architecture and avoids framework migration unless explicitly requested.

## Current Architecture

### Entry Point and Routing

- `public/index.php` bootstraps the app, prefers Composer autoloading when `vendor/autoload.php` exists, preserves the local `src/Autoloader.php` fallback, registers models and controllers, defines routes, dispatches requests, and uses a generic exception handler for startup/runtime failures. When used as the PHP built-in server router, it returns existing files under `public/` directly so generated CSS and other static assets are not sent through application routing.
- `src/Core/Router.php` is an exact-path router mapped by HTTP method and path.
- Most operations use query strings for identifiers, e.g. `/employees/edit?id=1`.
- There is no middleware pipeline, route grouping, or named-route layer.

### Core Layer

- `Application` loads configuration and manages the database connection.
- `Request` wraps server variables, query parameters, and body parameters.
- `Controller` handles view rendering, shared layout injection, and CSRF validation.
- `Localization` loads English/Myanmar PHP language files, resolves the active locale, provides fallback translation lookup, and builds language switch URLs.
- `SessionManager` manages sessions, authentication state, flash messages, CSRF tokens, expiry, login attempt tracking, and a per-request database-backed authentication validator supplied by `SessionSecurityService`.
- `src/Autoloader.php` remains the built-in fallback loader for `App\\` classes when Composer dependencies have not been installed yet.
- `tests/` contains a dependency-free PHP regression runner, structural checkpoint-protection tests, guarded MySQL-backed integration tests, and a seeded browser-flow QA foundation.
- `tailwind.config.js` and `assets/css/tailwind.css` provide the Tailwind build and SAPAS design-system source layer, including typographic brand-block, enterprise shell/sidebar/topbar primitives, semantic badge/button/table/filter/notification patterns, compact shared pagination, explicit canvas/card/nested/table surfaces, richer shadow tiers, icon/action-affordance, restrained chart primitives, and reduced-motion-safe interaction states. `views/partials/pagination.php` provides a first/last-aware selector with previous/next controls, adjacent-page context, and ellipses for distant ranges. Attendance, employees, employee/admin leave, audit logs, and report-detail pagination support server-validated 20, 50, or 100-row page sizes while preserving active filters. `assets/js/main.js`, mirrored to `public/assets/js/main.js`, progressively enhances the sidebar with a sliding navigation rail and limits fine-pointer button highlights to edit/delete actions without changing application behavior.

### Configuration

- `config/config.php` composes application, optional attendance-network, database, and mail configuration.
- `config/mail.php` provides safe mail defaults, loads optional local overrides from `config/mail.local.php`, then lets exported `MAIL_*` environment variables override those values.
- `config/mail.local.example.php` is the committed placeholder-only mail template. Developers copy it to ignored `config/mail.local.php` for Gmail, Mailtrap, or log-mode testing. Real SMTP credentials stay in that ignored file; optional `local_host` is only the SMTP `EHLO` hostname.
- `EmailVerificationMailer` rejects unsupported transports and records non-secret SMTP connection/delivery diagnostics. Log mode omits verification codes and message bodies. `AuthController` renders a bilingual delivery-failure state when sending fails instead of reporting a successful resend.
- SAPAS does not auto-load `.env` files; `.env.example` documents environment variable names for server setups that export them externally.
- Optional employee office-network enforcement is configured through `ATTENDANCE_OFFICE_NETWORK_ENABLED` and `ATTENDANCE_OFFICE_ALLOWED_IPS`. It is disabled by default and accepts exact IPv4/IPv6 addresses only.
- `scripts/package-release.php` creates a timestamped ZIP under ignored `releases/`, excluding local secrets, Git metadata, dependencies, backups, generated ZIPs, logs, and temporary/cache outputs.

### Controllers

- `AuthController` handles login, POST logout, the POST-only verification-code screen flow, and verification-code resend. The login view includes the integrated guest/system overview panel; no separate public `/about` page is registered.
- `DashboardController` handles admin and employee dashboards, read-only admin dashboard summary data, and access-denied flow.
- `DepartmentController` and `PositionController` handle admin master data CRUD. `HolidayController` keeps holiday mutations admin-only while allowing authenticated employees to read the holiday index.
- `EmployeeController` handles employee requests, delegates create/update validation and normalization to `EmployeeValidationService`, and delegates transactional account/profile writes to `AccountManagementService`, including deactivation, password reset, optional verification-code delivery, and scoped employment/work-rule fields.
- `AttendanceController` handles attendance history, check-in/check-out, admin attendance management, and approved-leave attendance conflict checks.
- `CompanyProfileController` preserves admin-only company profile edit/update compatibility; the old show route redirects into the Workforce Policy Center Organization Information section.
- `WorkScheduleController` handles the admin-only Workforce Policy Center foundation for reusable policy versions, weekly schedules, compact organization information, assignment management, overtime review, create, edit, update, activate, and deactivate flows.
- `AnalyticsController` handles admin analytics, including approved-leave context tracked separately from attendance rows.
- `LeaveController` handles employee leave submission, employee leave detail viewing, employee cancel-pending actions with ownership protection, admin leave review screens, and admin leave decision actions.
- `NotificationController` handles authenticated in-app notification listing and marking notifications read.
- `ReportsController` handles the admin-only Reports module foundation.

### Models

Models use PDO and prepared statements. There is no ORM.

- `User` is used for authentication lookup and password verification.
- `Employee` manages the `users` table for employee records.
- `EmployeeProfile` manages profile data.
- `Department`, `Position`, and `Holiday` manage master data.
- `Attendance` manages `attendance_records` reads and legacy-compatible writes plus read-only dashboard/report summaries. Shared derived values come from `AttendancePolicyService`; transactional clock/create/edit writes use `AttendanceOperationService`, `WorkScheduleResolverService`, and `AttendanceCalculationService`.
- `CompanyProfile`, `WorkScheduleTemplate`, and `WorkScheduleDay` manage the configurable organization information, Workforce Policy Center policy versions, and normalized weekday rows used by policy resolution.
- `LeaveRequest` manages `leave_requests`, employee self-service queries, admin listing filters, admin decision transitions, approved-leave lookup helpers for attendance/reporting interaction, and a read-only recent-request feed for the admin dashboard.
- `Notification` manages database-backed in-app notifications and unread/read state.

### Security Services

- `PasswordPolicyService` centralizes the 10-character, letter, number, common-password, and confirmation rules without returning or logging submitted password values.
- `EmployeeValidationService` centralizes employee create/update field validation, email-domain and Myanmar-phone normalization, email/reference checks, and delegates password decisions to `PasswordPolicyService`.
- `AccountManagementService` coordinates transactional account/profile writes, locks the active-administrator set before access changes, prevents administrator self-lockout and final-admin removal, and triggers atomic session-version increments.
- `SessionSecurityService` resolves the current user from the database and rejects missing, inactive, invalid-role, role-mismatched, or session-version-mismatched sessions.
- `AttendanceNetworkAccessService` owns the optional config-only network decision for employee self Check In and Check Out. It compares canonical exact IPv4/IPv6 values from `REMOTE_ADDR`, ignores forwarded proxy headers, fails closed when an enabled allowlist is empty or invalid, and records denials as best-effort audit events.

### Correctness Services

- `DateValidationService` strictly validates and normalizes `Y-m-d`, `Y-m`, time, optional date filters, and ordered ranges.
- `AttendancePolicyService` remains the compatibility/display mapper and shared duration formatter. It prefers stored Attendance V3 fields when present and falls back to legacy calculations for older rows.
- `WorkScheduleResolverService` resolves employee, department, company, then compatibility-default assignments for the requested business date, with a request-local cache and batch helper for repeated report/dashboard resolution. It never hardcodes Saturday or Sunday as weekends; holidays override the resolved weekly day.
- `WorkScheduleResolverService::resolveMany()` batch-loads employee identities, effective company/department/employee assignments, schedule templates, all seven schedule-day rows, attendance/overtime policy settings, fallback company profile data, and holidays for the requested date span. `tests/Support/QueryCountingPDO.php` instruments guarded integration tests only; the 500-request fixture in `WorkforcePolicyCenterIntegrationTest` asserts a bounded resolver budget of `<= 12` SQL executions and catches regressions back to per-row policy lookups.
- `AttendanceCalculationService` calculates Attendance V3 state, late minutes, regular minutes, early departure, raw overtime components, calculated overtime, and approved overtime from the resolved policy.
- `AttendanceOperationService` owns transactional employee check-in/check-out and admin attendance create/edit. It locks affected employee/date/leave/policy state, preserves the unique employee/date constraint, derives server-owned fields through the resolver/calculator, and rolls back atomically.
- `CompanyProfileService` and `WorkScheduleService` validate and save organization information and reusable policy versions transactionally with audit logging. Schedule saves ensure attendance and overtime policy rows exist for the policy version.
- `WorkScheduleAssignmentService` validates effective-dated company, department, and employee policy assignments, rejects active overlaps for the same scope/target, supports ending assignments and removing employee overrides, and audit logs assignment changes.
- `OvertimeReviewService` lists calculated overtime records, records approve/reject/adjust/reset decisions, updates approved overtime/current approval status on the attendance row, preserves `overtime_decisions` history, audit logs decisions, and creates in-app notifications.
- `LeaveOperationService` owns transactional leave creation and decision/cancellation transitions. It locks employee/request state and rechecks overlap, attendance-conflict, ownership, and state predicates at the write boundary.
- `DatabaseOperationException` distinguishes persistence failures from valid empty results. The bootstrap logs safe operation metadata and returns localized generic UI errors without exposing SQL or credentials.

## Database

Main tables:

- `users`
- `departments`
- `positions`
- `holidays`
- `leave_requests`
- `employee_profiles`
- `attendance_records`
- `notifications`
- `company_profiles`
- `work_schedule_templates`
- `work_schedule_days`
- `attendance_policy_settings`
- `overtime_policy_settings`
- `work_schedule_assignments`
- `overtime_decisions`
- `migrations`

Important constraints:

- `users.email` is unique.
- `users.email_verified_at`, `users.email_verification_code_hash`, `users.email_verification_expires_at`, `users.email_verification_sent_at`, and `users.email_verification_attempts` support admin-created account verification. The six-digit code is stored only through `password_hash`; the legacy token column remains unused for non-destructive upgrade compatibility.
- `users.staff_code` is optional and unique. `employment_type`, `join_date`, `expected_daily_work_hours`, and `overtime_eligible` store the Add Employee employment/work-rule options with backward-compatible defaults.
- `users.session_version` is an unsigned counter with default `1`. Password, role, activation, and deactivation changes increment it atomically to invalidate older sessions.
- `users(role, status)` has a composite index used by the active-administrator locking query.
- `users.department_id` and `users.position_id` use foreign keys with `ON DELETE SET NULL`.
- `employee_profiles.user_id` is unique and cascades on user delete.
- `attendance_records` has a unique `(user_id, attendance_date)` key.
- `attendance_records` keeps legacy `attendance_date`, `clock_in_time`, `clock_out_time`, `status`, `late_minutes`, and `working_hours` fields for compatibility. Attendance V3 fields such as `business_date`, `day_type`, `attendance_state`, regular minutes, overtime components, approved overtime, resolved policy, resolved assignment, and `calculation_version` are authoritative for new centralized writes.
- `attendance_records.user_id` cascades on user delete.
- `company_profiles.default_work_schedule_id` references the selected reusable default schedule. SAPAS remains single-company, so existing business tables do not gain company IDs.
- `work_schedule_templates` stores reusable Workforce Policy Center policy-version metadata with effective dates, lifecycle state, version number, optional timezone override, active state, and actor references.
- `work_schedule_days` stores exactly one normalized Monday-through-Sunday row per policy version. Weekday representation is stable: `1` = Monday through `7` = Sunday.
- `attendance_policy_settings` and `overtime_policy_settings` store one settings row per policy version.
- `work_schedule_assignments` stores effective-dated company, department, and employee policy assignments. Resolution priority is employee, department, company, then the company profile default schedule only as a compatibility fallback.
- `overtime_decisions` stores overtime decision history while the current approval state remains on `attendance_records`.

## Migrations

Migration runner:

```bash
php migrations/run_migrations.php
```

Current migrations:

- `2026_06_30_000000_create_schema_v2.sql`
- `2026_06_30_000001_add_employee_soft_delete_support.sql`
- `2026_06_30_000002_create_attendance_records.sql`
- `2026_06_30_000003_add_working_hours_to_attendance_records.sql`
- `2026_07_01_000000_create_leave_requests.sql`
- `2026_07_02_000000_create_notifications.sql`
- `2026_07_09_000000_add_email_verification_to_users.sql`
- `2026_07_13_000000_add_verification_codes_and_employee_options.sql`
- `2026_07_22_000000_add_session_version_to_users.sql`
- `2026_07_28_000000_add_company_schedule_foundation.sql`
- `2026_07_28_010000_add_workforce_policy_center.sql`

The soft-delete support migration is intentionally a no-op because the schema migration already creates the required fields. The `working_hours` migration is idempotent and checks `INFORMATION_SCHEMA` before adding the column.

## Workforce Policy Center

The user-facing Workforce Policy Center is intentionally simpler than the underlying versioned policy model. Administrators see four primary workspaces: Overview, Work Rules, Assignments, and Overtime Review. Policy History and Organization Information are secondary Advanced links. Work-rule changes are made by creating a future draft policy from the current, blank, or existing policy, editing schedule/attendance/overtime rules, and scheduling that draft for a future effective date.

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

The foundation seeds one company profile, one active default policy version, attendance/overtime settings for that version, and one company assignment effective from `1970-01-01`:

- Company: `Smart Attendance And Performance Analytic System`
- Short name: `Smart Attendance`
- Timezone: `Asia/Yangon`
- Date/time format: `Y-m-d`, `H:i`
- Schedule: `SAPAS Standard Office`
- Working days: Monday-Friday
- Non-working days: Saturday-Sunday
- Time: `09:00`-`17:00`
- Break minutes: `0`
- Grace minutes: `30`
- Expected minutes: `480`

Validation covers required company name, valid email, IANA timezone, date/time format allowlists, active default schedule, seven unique weekday rows, at least one working day, required working-day times, explicit overnight schedules, effective-date ordering, positive expected minutes, break start/end pairing, expected-minute consistency, and reasonable break/grace/expected-minute limits. Company profile and policy-version writes are admin-only, POST/CSRF protected, transactional, and audit logged.

New attendance writes resolve the applicable policy by employee and business date, then calculate day type, status, lateness, regular minutes, early departure, overtime components, calculated overtime, and approved overtime through centralized services. Admins can maintain effective-dated policy assignments and review calculated overtime decisions without changing payroll or attendance schema. Historical attendance rows are diagnosed by `scripts/diagnose_attendance_consistency.php`; they are not bulk-rewritten automatically.

## Development Sample Data

- `seeds/seed_demo_data.php` provides a repeatable local workforce seed for realistic UI review, screenshots, analytics, and report testing.
- The script retains the `SAPAS_ALLOW_DEMO_SEED=1` safety gate, uses the non-deliverable `@sapas.local` domain, and migrates legacy `@demo.sapas.local` seed accounts in place.
- Re-running the script refreshes only the local-domain seeded workforce attendance, leave, notification, profile, and master-data context. It removes only explicitly listed QA placeholder identities and does not create real-email employee accounts.
- Seeded attendance covers June 1 through July 21, 2026 with present, late, absent, holiday, weekend, overtime, and approved-leave-safe data.

## Leave Management V1

Current employee self-service leave capabilities:

- `GET /leave` lists the logged-in employee's leave requests.
- `GET /leave/create` shows the request form for future leave.
- `GET /leave/show?id=:id` shows leave details for the logged-in employee's own request only.
- `POST /leave/cancel` cancels only the logged-in employee's own request when it is still pending.
- `POST /leave/create` validates and creates new leave requests with `pending` status.
- Employee leave list filters support status, leave type, and leave date range with pagination preserving filters.
- Employees cannot view or cancel another employee's leave request by changing IDs or request parameters.
- Leave requests cannot be edited by employees. They must cancel and resubmit if they need to change a pending request.
- `views/leave/index.php`, `views/leave/form.php`, and `views/leave/show.php` provide the current employee Leave Management V1 screens.

Current admin leave capabilities:

- `GET /admin/leave` lists all leave requests for admins.
- `GET /admin/leave/show?id=:id` shows full leave request context for admins.
- `POST /admin/leave/approve` approves a pending request after active-employee and overlap revalidation.
- `POST /admin/leave/reject` rejects a pending request with a required review note.
- `POST /admin/leave/cancel` cancels a pending or approved request.
- Admin list filters support status, leave type, employee name/email search, and leave date range with pagination preserving filters.
- `views/leave/admin_index.php` and `views/leave/admin_show.php` provide the admin Leave Management V1 screens.
- Admins cannot edit leave or submit leave on behalf of employees.

Current valid leave status transitions:

- `pending` to `approved` by admin approval.
- `pending` to `rejected` by admin rejection with note.
- `pending` to `cancelled` by employee self-cancel or admin cancellation.
- `approved` to `cancelled` by admin cancellation.

Current scope includes employee self-service, admin review and decisions, narrow attendance/reporting interaction for approved leave, Notifications V1 hooks for high-value leave events, and PDF/Excel output through Reports/Export V1. Leave-specific extensions such as balances, attachments, half-day support, multi-level approval, admin-submitted leave, leave editing, and dedicated leave-only export workflows are not implemented yet.

## Notifications V1

Current notification capabilities:

- `GET /notifications` lists the authenticated user's recent in-app notifications.
- `POST /notifications/read` marks one notification read for the authenticated user only.
- `POST /notifications/read-all` marks all unread notifications read for the authenticated user.
- The app topbar shows an unread notification count for authenticated users.
- The topbar notification control and admin-dashboard notification detail control submit through `POST /notifications/read-all` before redirecting to the notification list, keeping badge state consistent across both entry points.
- Notification rows store translation keys and links rather than fixed display prose, so English/Myanmar labels are resolved at render time.
- Leave hooks create notifications for admin leave review after employee submission, and employee notifications after admin approval, rejection, or cancellation.
- Overtime hooks create admin notifications for pending overtime review and employee notifications after overtime approval, rejection, adjustment, or reset-to-pending decisions.

Current notification exclusions:

- No email, SMS, realtime push, websockets, queues, digests, user preferences, advanced filtering, or mass notification workflow.

Phase 4 attendance/reporting interaction completed:

- `LeaveRequest::existsApprovedLeaveForDate()` checks whether an employee has approved leave covering a specific date.
- `LeaveRequest::findApprovedRequestsForEmployeeInRange()` returns approved leave overlapping a date range for reporting context.
- Employee check-in is blocked on approved leave days before any attendance record is created or modified.
- Admin attendance create/edit rejects `present` or `absent` records that conflict with approved leave.
- Only `approved` leave affects attendance interaction; `pending`, `rejected`, and `cancelled` leave do not.
- Approved leave remains stored only in `leave_requests`; no leave rows are generated in `attendance_records`.
- Analytics shows approved leave working days as separate context without changing attendance rows.

Phase 3 UX refinements completed:

- Employee and admin leave lists use consistent filters, active filter indicators, pagination summaries, status badges, and empty states.
- Leave type labels are consistently human-readable.
- Employee and admin detail pages show request timelines using submitted, reviewed, rejected, and cancelled metadata when present.
- Leave forms and action controls use clearer labels and validation helper text.

## Authentication and Authorization

Current protections:

- Password verification uses `password_verify`.
- Password creation/reset uses `password_hash`.
- All current employee creation, edit-password, and administrator reset workflows use `PasswordPolicyService` and password confirmation.
- Inactive users cannot log in.
- Unverified users cannot establish an authenticated session after valid password verification; the controller stores a pending verification email in the session and renders the dedicated code screen without creating public signup.
- Admin-created users start unverified. Codes are six numeric digits, stored only as password hashes, expire after 15 minutes, allow five incorrect attempts, and can be resent from the code or employee-detail screen with a 10-minute cooldown. Successful verification clears all code state.
- Authenticated employees whose accounts become inactive are signed out before redirecting to `/login`.
- Session IDs are regenerated after successful login.
- Session idle timeout and max lifetime are enforced.
- Login stores the current database `session_version`; every protected request revalidates account existence, active status, role, and version before authorization continues.
- Password reset, role change, activation, and deactivation increment `session_version` atomically, so stale sessions are rejected on the next protected request.
- An administrator cannot deactivate their own account or remove their own administrator role. The final active administrator cannot be deactivated or downgraded; the active-admin set is rechecked under row locks inside the transaction.
- Employee user/profile creation and update writes are atomic. Failed account rules or persistence failures roll back the complete operation.
- Admin-only routes call `requireRole('admin')`.
- Employee check-in/check-out calls `requireRole('employee')`.
- General attendance list calls `requireAuthentication`.
- Employee attendance queries ignore URL `employee_id` and force the logged-in user ID.

## CSRF and Sessions

- `Controller::renderView` injects a CSRF token into views.
- POST handlers validate the CSRF token before writes.
- CSRF failures redirect to safe internal routes instead of raw referrer headers.
- Flash messages are stored in session.
- Login attempt tracking is session-based.

## Attendance Rules

- Shift start: `09:00:00`.
- Grace period end: `09:30:00`.
- Before or at grace period: `late_minutes = 0`.
- After grace period: late minutes are calculated from shift start.
- Status values: `present`, `absent`, `holiday`, `weekend`.
- Holiday dates are read from `holidays`.
- Weekend is Saturday and Sunday.
- Employees may check in on holidays and weekends; those records retain `holiday` or `weekend` status.
- Inactive employees cannot check in or out.
- Employees cannot check in on dates covered by approved leave.
- Admin attendance create/edit rejects `present` or `absent` records for dates covered by approved leave.
- Approved leave never creates or updates `attendance_records`.
- Admin attendance edit can persist employee/date/status/times/remarks.
- Duplicate attendance for the same employee/date is blocked.
- Expected daily hours are normalized to integer minutes. Missing, non-numeric, below-0.5-hour, or above-24-hour values fall back to 480 minutes; valid employee-specific values are preserved.
- Completed duration is derived from check-out minus check-in. Reversed times are rejected; incomplete records have no completed duration or overtime.
- Overtime V2 is derived, not stored. On normal workdays, eligible overtime is the completed time before `09:00` plus completed time after `09:00 + expected_work_minutes`. On weekends and company holidays, every completed work minute is special-day overtime. Approved leave and overtime-ineligible employees receive zero overtime. Payroll rates, salary calculations, breaks, approval workflows, and overtime request workflows are intentionally not implemented.
- All attendance, analytics, dashboard, report, and PDF read paths use the same policy service and duration formatter (`8h`, `8h 15m`, `45m`, `<1m`, or an em dash when incomplete). Attendance Summary totals completed durations as integer minutes and passes one locale-formatted total to both the screen and PDF.
- Employee check-in/check-out and admin attendance create/edit delegate saved-status derivation to `AttendanceOperationService` and `AttendancePolicyService`, so weekend and company-holiday work keeps its special-day status and weekday late minutes are not assigned to those records.
- When office-network enforcement is enabled, employee Check In and Check Out must pass `AttendanceNetworkAccessService` before `AttendanceOperationService` is called; admin attendance corrections remain unrestricted.
- `scripts/diagnose_attendance_consistency.php` is a read-only diagnostic for historical rows with contradictory status/time combinations; it reports manual-review candidates instead of performing a bulk repair.

## Analytics

Analytics currently reports active employee attendance metrics for a selected month:

- total employees included
- average attendance
- total late arrivals
- total absent days
- total approved leave working days as separate context
- total working hours
- total derived overtime minutes
- per-employee present/absent/approved-leave/late/hour/overtime metrics

Analytics detail links pass `employee_id`, `from`, and `to` to the attendance page.
The analytics screen is presentation-focused and bilingual-ready: it groups filters, monthly KPI cards, attendance/leave/exception context, empty states, and employee drill-down rows without changing the underlying calculations. Employee ranking and manager-review lists load the full active workforce in the selected scope and use bounded, keyboard-focusable vertical scroll regions.

## Reports/Export V1

Current reports foundation:

- `GET /admin/reports` shows the admin-only Reports index.
- `GET /admin/reports/attendance-summary` shows the admin-only Attendance Summary report.
- `GET /admin/reports/attendance-summary/pdf` exports the filtered Attendance Summary report as PDF.
- `GET /admin/reports/attendance-summary/excel` exports the filtered Attendance Summary report as a multi-sheet `.xlsx` workbook.
- `GET /admin/reports/employee-attendance-detail` shows the admin-only Employee Attendance Detail report.
- `GET /admin/reports/employee-attendance-detail/pdf` exports the filtered Employee Attendance Detail report as PDF.
- `GET /admin/reports/employee-attendance-detail/excel` exports employee summary, attendance records, and approved-leave context as `.xlsx` worksheets.
- `GET /admin/reports/leave-requests` shows the admin-only Leave Request report.
- `GET /admin/reports/leave-requests/pdf` exports the filtered Leave Request report as PDF.
- `GET /admin/reports/leave-requests/excel` exports the full filtered Leave Request dataset as `.xlsx`.
- `GET /admin/reports/late-absent-exceptions` shows the admin-only Late/Absent Exception report.
- `GET /admin/reports/late-absent-exceptions/pdf` exports the filtered Late/Absent Exception report as PDF.
- `GET /admin/reports/late-absent-exceptions/excel` exports the full filtered exception dataset as `.xlsx`.
- `views/reports/index.php` lists the four approved V1 report workspaces: Attendance Summary, Employee Attendance Detail, Leave Request Report, and Late/Absent Exception Report.
- `views/reports/attendance_summary.php` provides GET filters for date range, department, and employee, plus totals for total records, attended (including late), late, absent, localized working duration, and derived overtime minutes.
- `views/reports/employee_attendance_detail.php` requires an employee filter, supports optional date range filters, lists attendance rows with derived overtime minutes, and shows approved leave context separately from attendance records.
- `views/reports/leave_requests.php` supports GET filters for leave date range, employee, V1 status, and V1 leave type, with status/type badges, active filter context, pagination, note previews, and empty states.
- `views/reports/late_absent_exceptions.php` supports GET filters for date range, department, employee, and exception type, using existing late minutes and explicit absent attendance statuses.
- `composer.json` declares Dompdf plus required PHP extensions (`pdo`, `pdo_mysql`, and `mbstring`) and keeps PSR-4 autoload configuration for `App\\`.
- `src/Services/ReportPdfExportService.php` renders simple localized PDF documents with title, generated timestamp, active filters, summary tables, and report rows. Myanmar-locale exports prefer local Chrome/Edge/Chromium using configured path, environment variables, platform-standard locations (including macOS), then `PATH`, before falling back to Dompdf.
- `src/Services/ReportExcelExportService.php` creates standards-compliant Office Open XML workbooks without a spreadsheet dependency or `ZipArchive`. It uses localized worksheet titles, frozen table headers, active-filter metadata, typed dates/times/durations, practical column widths, and inline strings so user-provided values cannot become formulas.
- PDF and Excel export routes are admin-only through `ReportsController::ensureAdmin()`, preserve active GET filters, export the full filtered dataset instead of the current page, and redirect with localized flash errors instead of generating empty files.
- The app continues working without `vendor/` because `public/index.php` conditionally loads `vendor/autoload.php` and still registers the local autoloader; PDF export shows a localized error until `composer install` is run.
- Excel export remains available without `vendor/`. Scheduled reports, email delivery, and generated absence calculations for missing attendance rows are not implemented yet.
- Approved leave remains separate from `attendance_records`.

## Frontend Status

- Views are built with Tailwind utility classes.
- Shared `app` and `guest` layouts are used.
- The login screen uses a compact split guest layout with a focused sign-in panel and a restrained three-item product overview. It preserves language switching, accessible email/password fields, short-screen responsiveness, and existing authentication behavior. SAPAS does not register a separate public `/about` page, public signup, demo accounts, or new authentication behavior.
- Views reference `/assets/css/tailwind.css`, served from the canonical runtime file `public/assets/css/tailwind.css`. This file is now generated from `assets/css/tailwind.css` with `npm run build:css`.
- Tailwind tooling is restored from `package.json` and `package-lock.json` with `npm install`; `node_modules/` is ignored and should not be bundled.
- The Tailwind design foundation includes SAPAS semantic color tokens, richer neutral/supporting accents, Myanmar-safe font fallback, bilingual wrapping safeguards, focus states, typographic brand-block primitives, icon/action-affordance primitives, restrained SVG/chart primitives, layered shell/card/filter/table/dashboard/report material primitives, compact notification rows, semantic status badges, semantic gradient/glass button treatments, tactile interaction states, pointer-aware highlights, and the refined shared app shell.
- Visible branding no longer uses the short name, a logo mark, or the oversized full official name as the main shell title. `views/partials/brand.php` now provides a reusable text-led identity block with "Smart Attendance" and the supporting subtitle "Performance & Workforce Analytics" for the sidebar and guest/login shell, with a compact title-only mobile/topbar variant.
- `views/partials/icon.php` provides the lightweight server-rendered line-icon set used for shared shell navigation, dashboard KPI/shortcut cues, login fields, guest capability cards, report cards, notifications, and empty states.
- Shared shell refinement is applied in `views/layouts/app.php`, `views/partials/sidebar.php`, `views/partials/topbar.php`, `views/partials/page_header.php`, and `views/partials/flash.php`: richer navy office shell, visible signed-in identity card, stronger sidebar/nav states, structured topbar controls, icon-supported page-header hierarchy, and refined flash states.
- The Phase 1 visual-material pass adds a richer shell canvas, darker sidebar contrast, stronger hero/card tiers, deeper but restrained shadows, and clearer separation between Tier 1 canvas/shell, Tier 2 hero/summary zones, Tier 3 data cards, and Tier 4 nested insight/stat cards without changing routes, logic, workflows, or charts.
- The Phase 2 composition pass applies that foundation more deliberately to the admin operations command center, employee daily-status workspace, KPI grouping, action hierarchy, analytics opening summary, and reports opening/index composition without changing routes, logic, filters, exports, workflows, or charts.
- The analytics workspace now uses an office management reporting model derived from the current filtered scope: executive KPI cards, recorded-workday composition, employee attendance ranking against a visible 90% review target, prioritized exception/data-gap review, and a table-first employee drill-down. Average attendance includes only employees with present/absent records; the ranking includes every active employee in scope and labels missing data as `No records` rather than `0%`. Missing records also remain visible as manager follow-up. Late-present days remain part of present totals, while approved leave remains separate from attendance records.
- Reports and analytics now have restrained foundation, composition, and insight-chart variants so those workspaces can feel distinct while preserving current routes, permissions, filters, export/PDF behavior, and attendance/leave workflows.
- The guest/login shell, dashboards, reports workspace, analytics workspace, and high-traffic empty states use the shared office-theme material surfaces directly. Login fields use the shared icon set, while dense tables, filters, and forms remain restrained and readable. On the admin dashboard, Department Attendance is limited to a compact three-row preview with a View All action so it matches the existing Attendance Overview card without stretching the chart. The Audit Logs main filters omit the developer-oriented Entity ID field. Logo work, illustration work, and workflow redesign remain intentionally deferred.
- Dashboard refinement is applied in `views/dashboard/admin.php` and `views/dashboard/employee.php`: stronger hero/status areas, KPI grouping, an admin attendance-overview SVG line chart, department attendance, recent leave requests, notification panels, priority/watchlist sections, action emphasis, and refined recent-activity presentation without changing routes or attendance/leave decision logic.
- Analytics and reports refinement is applied in `views/analytics/index.php` and `views/reports/*.php`: clearer filter panels, active-scope chips, office-focused KPI and comparison visuals, prioritized attendance exceptions, report table panels, empty states, and export action presentation without changing filters, routes, permissions, or PDF behavior.
- Operational module-page polish is applied in attendance, leave, employees, and master-data views: shared filter/table panels, form controls, detail cards, action groups, badges, empty states, and pagination styling without changing routes, queries, permissions, or workflows.
- Attendance history now accepts only `per_page=20`, `50`, or `100`, defaults invalid values to 20, preserves the selected size with search/date/employee filters, and resets to page 1 when the page-size selector changes. The shared paginator always exposes the first and last page around the current-page window.
- Trusted date/time consistency is configured centrally: PHP defaults to `Asia/Yangon` before application/database construction, MySQL sessions use `+06:30`, and official attendance Check In/Check Out values continue to be generated on the server rather than accepted from the browser clock. Windows automatic time synchronization remains a deployment-machine requirement.
- Phase 4 Lite consolidates the shared shell into role-aware navigation groups and a responsive drawer. `views/layouts/app.php` owns the skip target and drawer backdrop; `views/partials/sidebar.php` owns grouped navigation; `views/partials/topbar.php` owns the drawer trigger and page context; `assets/js/main.js` supplies drawer, password-visibility, flash-dismissal, and login-loading enhancement.
- Micro Update 27 hardens responsive accessibility and localization: the mobile drawer is removed from the focus order while closed and traps focus while open, horizontally overflowing data tables become keyboard-focusable labeled regions, mobile page/report actions remain compact instead of expanding unnecessarily, the Access Denied workspace uses the shared localized app shell, and the remaining hardcoded department action is translated in English and Myanmar.
- `assets/css/tailwind.css` remains the editable design source and `public/assets/css/tailwind.css` its generated runtime output. Phase 4 Lite changes presentation only: no routes, controller/model/service boundaries, permissions, filters, attendance calculations, or PDF data preparation were changed.

## Localization Foundation

- Supported locales are `en` and `my`.
- `public/index.php` creates `src/Core/Localization.php` after session startup and applies optional `?lang=en` or `?lang=my` query selection.
- The selected language is persisted in session and defaults to English.
- `Controller::renderView` injects `locale`, `htmlLang`, `languageOptions`, `languageSwitchUrl`, and `t` into views.
- `views/layouts/app.php` and `views/layouts/guest.php` use dynamic `<html lang>`.
- `views/partials/topbar.php` provides the authenticated language switcher.
- `views/partials/language_switcher.php` provides the shared compact language control used by both the authenticated topbar and `views/layouts/guest.php`.
- Current translated scope includes shared shell/foundation text, login/auth surface labels, common actions, common field/filter labels, role labels, account/attendance/leave status display labels, leave type display labels, shared report/export labels, high-traffic controller validation/flash messages, and selected auth/security flash messages.
- `views/dashboard/admin.php` and `views/dashboard/employee.php` now use dashboard-specific English/Myanmar labels and the current dashboard UI direction.
- Attendance, leave, reports, employee, master-data, and analytics views now use bilingual-ready labels for high-traffic headings, filters, badges/status display, table headings, empty states, and primary actions.
- Analytics now uses the current UI direction for filters, KPI presentation, context cards, empty states, drill-down rows, and localized month/date display. Route slug translation, database enum translation, and user-entered content translation are not implemented in this phase.

## Dashboard UI Direction

- The admin dashboard is organized as an operations command center with today's operation date, KPI cards, an SVG attendance trend chart, department attendance, recent leave requests, notifications, and priority watchlists.
- The employee dashboard is organized as a personal daily-status workspace with today's status, leave context, primary clock action, monthly summary, and recent attendance history.
- Dashboard redesign preserves check-in/check-out routes, attendance links, employee links, analytics links, report links, permissions, and approved-leave behavior. The only backend additions are read-only view-model queries for dashboard presentation.

## Known Technical Debt

- Deep feature-specific redesign is intentionally deferred; the current Tailwind phases establish the build workflow, reusable design-system foundation, compact typographic branding, richer office-theme surface/card/shadow hierarchy, dashboard/workspace composition hierarchy, office-focused analytics visuals, line-icon/action-affordance polish, compact split-login refinement, cleaner operational tables, refined analytics/reports workspaces, and polished operational module pages. Image-heavy illustration rollout, scheduled export delivery, and deeper domain-specific report requirements remain later phases.
- The automated regression suite exists but remains intentionally small; some checks are structural source-inspection guards rather than full request-execution tests. It now includes guarded MySQL integration coverage and a seeded browser-flow QA foundation, but it does not yet automate full browser flows, PDF rendering, every controller request/response path, or every CRUD path.
- Login rate limiting is session-based rather than centralized.
- Email verification is intentionally scoped to admin-created account activation. Public self-registration, password reset by email, email notification preferences, digests, and module-wide email delivery are deferred.
- Deployment documentation and seeded admin setup guidance remain minimal.
- Leave management includes employee self-service, admin review and decisions, narrow approved-leave attendance/reporting interaction, Reports V1 PDF/Excel output, and Notifications V1 hooks for selected leave events; balances, attachments, half-day support, delivery-channel notifications, and broader workflow automation are not implemented.
- Bilingual support currently covers the shared shell/foundation, reusable UI vocabulary, dashboard labels, high-traffic operational module labels, controller-generated messages in the main flows, report PDF labels/messages, and analytics page labels. Some deeper module-specific guidance text remains English until later localization phases.

## Testing Checklist

## Phase 3 Architecture Map

- `src/Services/ReportFilterService.php` validates and normalizes report, analytics, audit, date, pagination, search, and export filename inputs without broadening invalid requests.
- `src/Services/AttendanceSummaryReportService.php`, `EmployeeAttendanceReportService.php`, `LeaveReportService.php`, and `AttendanceExceptionReportService.php` coordinate report queries and return normalized screen/PDF data. `AttendanceReportContextService.php` batch-enriches attendance with holiday, approved-leave, duration, lateness, and overtime context.
- `AttendanceReportContextService.php` is the current authoritative attendance read context for reports and exports. Stored Attendance V3 fields remain preferred for finalized records; deterministic legacy fallback values are marked internally and are not persisted during report generation. Dashboard and analytics paths pass through the resolver/read context where schedule-derived day type matters.
- `src/Services/AnalyticsService.php` loads the employee scope, attendance, approved leave, and holidays in bounded batch queries, then applies `AttendancePolicyService` in memory. `AnalyticsController` only authorizes, validates input, invokes the service, and renders.
- `src/Models/Employee.php`, `Attendance.php`, `LeaveRequest.php`, and `Holiday.php` contain the Phase 3 joins and range/batch retrieval methods. Migration `2026_07_22_010000_add_phase3_audit_staff_and_indexes.sql` documents the supporting composite indexes.
- `src/Services/AuditLogService.php` records redacted events. `src/Models/AuditLog.php`, `src/Controllers/AuditLogController.php`, and `views/audit_logs/` implement the admin-only GET routes `/admin/audit-logs` and `/admin/audit-logs/show`; no application update/delete route exists.
- Account, attendance, and leave operation services write critical audit records inside their existing database transactions. Successful admin-login and selected master-data telemetry are explicitly best effort.
- Staff-code creation flow: lock the singleton `staff_code_sequences` row, reserve the next number, insert the user with `SAP-####`, record the audit event, and commit. Normal employee edits reuse the stored code.
- `src/Services/DemoAccountRefreshService.php` produces name-based email/fictional contact/password plans, verifies preservation signatures, updates all accounts in one transaction, and writes a private credential artifact. `scripts/refresh-demo-accounts.php` exposes dry-run and apply modes.
- Phase 3 coverage is in `tests/Integration/Phase3IntegrationTest.php` and `tests/Regression/Phase3ArchitectureTest.php`, alongside updated report-duration and schema fixtures.

Phase 3 index evidence:

| Query | Existing useful index | Phase 3 index | Expected benefit |
| --- | --- | --- | --- |
| Attendance Summary date/status scope | `idx_attendance_date`, `uq_attendance_user_date` | `idx_attendance_date_status_user (attendance_date, status, user_id)` | Range-filter report rows by date/status and retain the joined user ID in the index. |
| Approved leave batch overlap | Single-column status/date keys and `idx_leave_requests_user_range` | `idx_leave_status_user_range (status, user_id, start_date, end_date)` | Resolve approved leave overlap for the whole employee scope without a query per employee. |
| Department analytics scope | Single-column department/role/status keys | `idx_users_department_role_status (department_id, role, status)` | Narrow active employee scope by the actual combined filters before joining department/position display data. |
| Audit timeline/filtering | None before Phase 3 | created-at, actor/created-at, entity, and action indexes | Keep the immutable admin audit list and its common filters paginated as the log grows. |

Initial automated coverage now exists in `tests/` and can be run with:

```bash
php tests/run.php
```

Current automated checks include execution-level tests for inactive-login blocking, session invalidation basics, CSRF token validation, language switching and fallback behavior, strict date/month/range validation, attendance grace boundaries, employee-specific expected minutes, exact duration/formatting, schedule-boundary and special-day overtime, transactional attendance rollback/duplicate/clock-out guards, transactional leave overlap/state/attendance-conflict rollback, controlled database failure handling, cross-platform PDF browser discovery, real seeded admin/employee login, report query filters, and notification read ownership.

Current structural guards inspect source/route registration for CSRF safe redirect fallback, POST-only logout registration, inactive-employee session invalidation usage, neutral leave cancellation reason storage, report route prefixes, absence of employee report routes, and report controller admin-authorization calls.

Database-backed integration tests create/reset only a guarded test database whose name contains `test`; the default is `sapas_test`. Their schema builder is an intentionally maintained minimal mirror rather than a migration runner, so it must be updated alongside relevant production migrations/schema changes. Seeded browser QA can be prepared with `php tests/browser_seed.php`, which creates `sapas_browser_test` and documents the authenticated flows in `tests/Browser/seeded_flows.md`.

Regression tests should cover:

- active admin login
- active employee login
- inactive user login block
- unverified user login block
- mail config default log mode
- mail config SMTP environment override wiring
- six-digit verification code generation and password-hashed storage
- Smart Attendance verification-code email subject/body branding without links
- valid code verifies the account; wrong, expired, reused, or over-attempt codes do not
- resend code rotation, cooldown behavior, and attempt reset
- Add Employee send-code option and employment/work-rule defaults
- inactive authenticated employee session invalidation
- CSRF failure safe redirect behavior
- generic failed-login error
- login rate limiting
- English/Myanmar language switching through `?lang=...`
- session persistence for selected language
- shared shell labels and dynamic `<html lang>` for both app and guest layouts
- login/auth form labels and selected auth validation/flash messages in both supported languages
- admin dashboard labels and actions in both supported languages
- employee dashboard labels, status display labels, and clock action labels in both supported languages
- admin attendance filters by employee and date range
- admin attendance create/edit
- duplicate employee/date prevention
- employee-only attendance scoping
- holiday/weekend check-in and special-day overtime recording
- approved-leave check-in blocking without creating attendance rows
- admin attendance present/absent conflict blocking for approved leave
- inactive employee check-in/check-out prevention
- analytics detail links
- analytics approved-leave context
- admin reports index access control
- attendance summary report filters and totals
- attendance summary PDF export filter fidelity and empty-export blocking
- attendance summary Excel export filter fidelity, full-dataset output, summary worksheet, and empty-export blocking
- employee attendance detail report with approved leave context
- employee attendance detail PDF export filter fidelity and approved-leave context
- employee attendance detail Excel export with summary, attendance, and approved-leave worksheets
- leave request report status/type/date filters
- leave request PDF export status/type/date filter fidelity
- leave request Excel export status/type/date filter fidelity and full-dataset output
- late/absent exception report filters and existing-row logic
- late/absent exception PDF export filter fidelity and existing-row logic
- late/absent exception Excel export filter fidelity and full-dataset output
- reports sidebar visibility for admins
- employee leave details access control
- employee cancel-pending success and failure cases
- employee cannot view or cancel another employee's leave
- admin leave list filters
- admin leave details access
- employee leave list filters and pagination preservation
- leave status badges and empty states
- leave detail timelines for pending, approved, rejected, and cancelled requests
- admin approve pending success and failure cases
- admin reject pending with required note
- admin cancel pending or approved leave
- admin cannot cancel rejected or cancelled leave
- employee cannot access admin leave routes
- leave decisions do not create or modify attendance records
- migration runner first and second runs
- PHP syntax checks

## Final Stabilization and Release Checkpoint

Status: Completed for the current source checkpoint.

The stabilization sequence now includes:

- compact Reports and dashboard card layouts
- shared shell, notification, logout, and login refinements
- employee holiday display and official Check In/Check Out terminology
- trusted `Asia/Yangon` server/database time configuration
- schedule-boundary, weekend, and holiday overtime behavior
- PDF and formatted Excel export for all four reports
- cleaner attendance, analytics, leave, audit, employee, master-data, notification, and report-detail tables
- shared first/last-page pagination with 20/50/100 page-size selection on large workspaces
- responsive drawer focus management, focusable overflow tables, and synchronized English/Myanmar keys
- served JavaScript synchronized with the editable source
- release packaging hardened to exclude local secrets, temporary uploads, generated reports, sessions, backups, private credentials, logs, dependencies, Git data, and older archives

Final validation expectations:

- lint all PHP files outside dependencies
- validate both JavaScript copies and keep them identical
- rebuild the runtime Tailwind stylesheet
- run `php tests/run.php` with `pdo_mysql`, `mbstring`, and `zip` enabled
- execute seeded admin/employee browser flows against a dedicated test database
- inspect one PDF and one Excel workbook from each report family
- create the release with `php scripts/package-release.php` and inspect its entries before distribution

Final UAT on 2026-07-29:

- Baseline and final attendance diagnostics reported only unresolved record `2155`; it remains unchanged and requires a real-world attendance decision before repair.
- Previous UAT policy records `#2`, `#3`, and `#4` were cancelled/archived through lifecycle services and no UAT policy remains scheduled to activate.
- Controlled overtime browser testing covered pending creation, approve, reason-required reject, above-calculated adjustment rejection, valid adjustment, reset to pending, notifications, audit logs, and stale two-tab approval protection.
- Temporary overtime fixture rows, employee, assignment, holiday, notifications, and decisions were removed after evidence capture; audit logs were preserved.
- Query-count evidence is available with `php scripts/measure_policy_resolver_queries.php` and currently reports 500 requests, 4890 legacy queries, and 9 batch queries.
- Browser localization checks found no raw Workforce Policy Center translation keys after the final Myanmar label pass. Some older shared chrome/table headings still remain English and are tracked as localization limitations.

## Recommended Next Milestones

1. Automate seeded browser-flow execution in CI or on the Windows demonstration machine.
2. Finalize production deployment guidance for Apache/Nginx, HTTPS, backups, mail delivery, and maintenance.
3. Add overtime approval, break deduction, payroll rates, or payroll integration only after policy requirements are approved.
4. Expand leave management with balances, attachments, half-day support, or multi-level approval only when required.
5. Expand notifications with preferences, delivery channels, digests, or additional events when approved.
6. Add scheduled report delivery or further export formats only when a concrete reporting requirement exists.
