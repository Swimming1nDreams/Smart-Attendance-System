# Workforce Policy Center and Attendance Policy Specification

Status: Workforce Policy Center foundation implemented for organization information, reusable policy versions, seven normalized weekday rows, company default assignment compatibility, effective schedule resolution, Attendance Policy V3 storage, centralized attendance/overtime calculation for new attendance writes, and final V3 read-side verification for dashboards, analytics, reports, PDF source data, and Excel source data.

This document defines the Workforce Policy Center foundation for company profile data, policy versions, schedule assignments, Attendance Policy V3, and Overtime Policy V3 in OAAS. The July 28, 2026 migrations preserve the current single-company PHP MVC architecture, existing routes, authentication, CSRF, audit logging, reports, PDF/Excel exports, and legacy attendance fields while adding authoritative policy-resolution fields.

New employee/admin attendance writes resolve the employee policy by business date and use centralized calculations for day type, status, lateness, regular minutes, overtime components, calculated overtime, and approved overtime. Historical attendance is diagnosed but not bulk rewritten.

Final verification notes:

- `WorkScheduleResolverService::resolveMany()` batch-loads the data required for employee/date policy resolution and avoids per-row assignment, schedule-day, holiday, attendance-policy, and overtime-policy lookups.
- A test-only `QueryCountingPDO` wrapper counts executed SQL statements in integration tests. The 500 employee/date resolver fixture asserts the hardened batch path remains within `<= 12` resolver SQL executions.
- `AttendanceReportContextService` is the shared attendance read context for report/export records. It prefers stored Attendance V3 fields, uses deterministic historical fallback only for legacy rows without V3 values, labels fallback rows internally, and never writes those fallback values during reads.
- Admin/employee dashboard and analytics schedule decisions should use resolved policy data when the resolver is available. Saturday and Sunday are labels only when the effective schedule says the day is non-working.
- Screen reports, PDF source arrays, and Excel source arrays must be generated from the same report services for identical filters. Rendered PDFs and workbooks still require target-machine visual inspection before release.
- Attendance record `2155` remains an explicit manual-review diagnostic row. Do not recalculate, mark absent, add times, delete, or exclude it without a confirmed real-world decision.

## Foundation Implemented

Implemented in this phase:

- `company_profiles`
- `work_schedule_templates`
- `work_schedule_days`
- Admin Workforce Policy Center list/create/edit/activate/deactivate pages
- Compact Organization Information section reusing company profile fields
- Company default schedule selection
- Company default policy assignment seeded for historical compatibility
- `attendance_policy_settings`
- `overtime_policy_settings`
- `work_schedule_assignments`
- Attendance V3 columns on `attendance_records`
- `WorkScheduleResolverService`
- `AttendanceCalculationService`
- Validation, transactions, CSRF, admin authorization, localization, and audit logging

Seeded default configuration:

- Company name: `Office Attendance and Analytic System`
- Short name: `OAAS`
- Timezone: `Asia/Yangon`
- Date format: `Y-m-d`
- Time format: `H:i`
- Default schedule: `OAAS Standard Office`
- Working days: Monday-Friday
- Non-working days: Saturday-Sunday
- Start/end: `09:00`-`17:00`
- Break minutes: `0`
- Grace minutes: `30`
- Expected minutes: `480`

Remaining in this broader rollout:

- Historical repair workflow beyond dry-run diagnostics
- Manual PDF/Excel inspection on the target Windows release machine for every attendance-related report family

## 1. Scope Decision

OAAS remains a single-company system. Do not introduce tenants, tenant IDs, or cross-company isolation rules.

The company may configure multiple reusable schedules and assign them by employee, department, or company default. Schedule resolution priority is:

1. Employee assignment
2. Department assignment
3. Company assignment
4. Compatibility fallback to the company profile default schedule

When no employee, department, or company assignment exists, use the company profile default schedule as a compatibility fallback. If the fallback is missing, block policy-dependent attendance writes and show an admin repair message rather than guessing Monday-Friday behavior.

## 2. Company Profile Module

Recommended admin route direction, following current router style:

- `GET /admin/company-profile`
- `POST /admin/company-profile`

Controller: `CompanyProfileController`

Model: `CompanyProfile`

View: `views/company_profile/edit.php`

Service: `CompanyProfileService` for validation, transaction safety, and audit logging.

Fields:

- `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
- `company_name` VARCHAR(150) NOT NULL
- `short_name` VARCHAR(50) NULL
- `company_email` VARCHAR(190) NULL
- `phone` VARCHAR(50) NULL
- `address` TEXT NULL
- `logo_path` VARCHAR(255) NULL
- `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Yangon'
- `date_format` VARCHAR(32) NOT NULL DEFAULT 'Y-m-d'
- `time_format` VARCHAR(32) NOT NULL DEFAULT 'H:i'
- `default_work_schedule_id` INT UNSIGNED NULL
- `default_language` VARCHAR(8) NULL
- `is_active` TINYINT(1) NOT NULL DEFAULT 1
- `created_by` INT UNSIGNED NULL
- `updated_by` INT UNSIGNED NULL
- `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
- `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

Rules:

- Only one active profile is allowed. Enforce with service validation plus a database uniqueness strategy such as a generated active marker if supported by the installed MySQL version.
- Company name is required.
- Timezone must be validated with `DateTimeZone::listIdentifiers()`.
- Default timezone remains `Asia/Yangon`.
- Logo upload is optional and must be security-reviewed: file size limit, MIME validation, image extension allowlist, randomized filename, storage outside executable paths where possible, no SVG unless sanitized.
- Updates require POST, CSRF validation, admin authorization, database transaction, validation, and audit logging.
- Do not store secrets in this table.

## 3. Work Schedule Templates

Schedules are reusable templates, not hardcoded office assumptions.

Examples:

- Standard Office: Monday-Friday, 09:00-17:00, 60-minute break
- Customer Support: Monday-Saturday, 08:00-16:00, 45-minute break
- Night Operations: Sunday-Thursday, 21:00-05:00 next day, 60-minute break

Use normalized day rows instead of comma-separated weekday strings. Normalized rows are easier to validate, index, audit, and query for effective historical dates in the current PDO/MySQL architecture.

`work_schedule_templates` fields:

- `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
- `name` VARCHAR(120) NOT NULL
- `description` TEXT NULL
- `timezone_mode` ENUM('company', 'custom') NOT NULL DEFAULT 'company'
- `timezone` VARCHAR(64) NULL
- `is_active` TINYINT(1) NOT NULL DEFAULT 1
- `is_default_company_schedule` TINYINT(1) NOT NULL DEFAULT 0
- `effective_from` DATE NOT NULL
- `effective_to` DATE NULL
- `created_by` INT UNSIGNED NULL
- `updated_by` INT UNSIGNED NULL
- `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
- `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

`work_schedule_days` fields:

- `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
- `schedule_template_id` INT UNSIGNED NOT NULL
- `day_of_week` TINYINT UNSIGNED NOT NULL, ISO 1 Monday through 7 Sunday
- `is_working_day` TINYINT(1) NOT NULL DEFAULT 0
- `scheduled_start_time` TIME NULL
- `scheduled_end_time` TIME NULL
- `is_overnight` TINYINT(1) NOT NULL DEFAULT 0
- `break_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0
- `grace_period_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0
- `expected_work_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0
- `overtime_eligible_override` ENUM('inherit', 'eligible', 'ineligible') NOT NULL DEFAULT 'inherit'
- `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
- `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

Indexes and constraints:

- Unique key on `(schedule_template_id, day_of_week)`.
- Foreign key from `work_schedule_days.schedule_template_id` to `work_schedule_templates.id`.
- Validate that working days have start/end times and positive expected minutes.
- Validate non-working days do not require start/end times.
- Validate break minutes do not exceed expected work minutes.
- Validate overnight schedules explicitly; do not infer every earlier Check Out as next day.

## 4. Schedule Assignments

Assignments are effective-dated and may target company, department, or employee.

`work_schedule_assignments` fields:

- `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
- `schedule_template_id` INT UNSIGNED NOT NULL
- `scope_type` ENUM('company', 'department', 'employee') NOT NULL
- `scope_id` INT UNSIGNED NULL
- `effective_from` DATE NOT NULL
- `effective_to` DATE NULL
- `is_active` TINYINT(1) NOT NULL DEFAULT 1
- `reason` VARCHAR(255) NULL
- `created_by` INT UNSIGNED NULL
- `updated_by` INT UNSIGNED NULL
- `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
- `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

Rules:

- Company assignment uses `scope_type = 'company'` and `scope_id IS NULL`.
- Department assignment references `departments.id`.
- Employee assignment references `users.id` where role is employee.
- Prevent overlapping active date ranges for the same scope. Enforce in `ScheduleAssignmentService` inside a transaction using `SELECT ... FOR UPDATE` over existing assignments for the target scope.
- Future assignments are allowed.
- Historical attendance resolves using the assignment effective on that historical date.
- Changing today's schedule must not silently recalculate previous months.

## 5. Central Schedule Resolver

Create `WorkScheduleResolverService`.

Input:

- Employee user ID
- Attendance date

Output DTO or associative array:

- `schedule_template_id`
- `assignment_source`: employee, department, company
- `timezone`
- `day_type`: working_day, non_working_day, holiday
- `is_scheduled_working_day`
- `scheduled_start_at` as local datetime or null
- `scheduled_end_at` as local datetime or null
- `is_overnight`
- `break_minutes`
- `grace_period_minutes`
- `expected_regular_minutes`
- `overtime_eligible`
- `policy_metadata`

Controllers and views must not decide whether Saturday or Sunday is a weekend. In V3, "Weekend" means a non-working day in the resolved schedule. A Sunday may be a working day for one schedule and a non-working day for another.

## 6. Holiday Priority

Precedence:

1. Company holiday
2. Resolved weekly working schedule

When a date is a holiday:

- Day type is Holiday.
- Weekday lateness does not apply.
- Holiday work keeps Holiday classification.
- Holiday overtime follows the active overtime policy.
- Approved leave interaction must be explicit:
  - If leave was approved before a holiday was added, reports should show both facts.
  - Attendance writes on holidays should not be blocked solely because of a holiday.
  - Leave balance and leave-day counting changes require a separate approved policy decision.

Conflicting records:

- Do not bulk rewrite historical records automatically.
- Provide diagnostics that classify old status conflicts and require manual approval before repair.
- Repairs must be transactional and audit logged with before/after values.

## 7. Attendance Classification V3

The current single `status` column should remain during migration. V3 separates concepts to prevent contradictions.

Day type:

- Working Day
- Non-working Day
- Holiday

Attendance state:

- Present
- Absent
- Incomplete
- Approved Leave
- No Record, calculated for reporting gaps only

Flags:

- Late
- Early Departure
- Missing Check Out
- Worked Before Schedule
- Worked After Schedule
- Manual Override
- Overnight Attendance

Storage strategy:

- Store day type, attendance state, and durable manual flags on attendance records after migration approval.
- Calculate volatile report-only flags from raw times and the resolved schedule when possible.
- Keep the existing `status` column as a backward-compatible display/export field during the transition.
- Add a mapper from V3 values back to existing display statuses for old views and exports until all reports are upgraded.

## 8. Worked-Time Model

Use datetime intervals, not only total duration.

For each attendance record calculate:

- Actual worked interval
- Regular scheduled overlap
- Early worked interval
- After-hours worked interval
- Non-working-day worked interval
- Holiday worked interval
- Break deduction
- Late minutes
- Early-departure minutes
- Total actual minutes

Regular minutes:

```text
regular minutes = actual worked interval intersecting scheduled work interval - applicable break deduction
```

Break deduction rules:

- Deduct break once at most.
- Do not deduct a break if the worked interval is shorter than the configured minimum for break deduction.
- Holiday and non-working-day break treatment comes from Overtime Policy V3.

## 9. Overtime Policy V3

Recommended fields for `overtime_policies`:

- `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
- `name` VARCHAR(120) NOT NULL
- `description` TEXT NULL
- `is_active` TINYINT(1) NOT NULL DEFAULT 1
- `effective_from` DATE NOT NULL
- `effective_to` DATE NULL
- `early_work_eligible` TINYINT(1) NOT NULL DEFAULT 0
- `after_hours_work_eligible` TINYINT(1) NOT NULL DEFAULT 1
- `non_working_day_work_eligible` TINYINT(1) NOT NULL DEFAULT 1
- `holiday_work_eligible` TINYINT(1) NOT NULL DEFAULT 1
- `approval_required` TINYINT(1) NOT NULL DEFAULT 0
- `minimum_overtime_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0
- `rounding_mode` ENUM('exact', 'down', 'up', 'nearest') NOT NULL DEFAULT 'exact'
- `rounding_interval_minutes` SMALLINT UNSIGNED NULL
- `break_treatment` ENUM('deduct_once', 'no_deduction', 'schedule_rule') NOT NULL DEFAULT 'schedule_rule'
- `employee_eligibility_required` TINYINT(1) NOT NULL DEFAULT 1
- `manual_override_allowed` TINYINT(1) NOT NULL DEFAULT 0
- `maximum_overtime_minutes_per_day` SMALLINT UNSIGNED NULL
- `overtime_reason_required` TINYINT(1) NOT NULL DEFAULT 0
- `created_by` INT UNSIGNED NULL
- `updated_by` INT UNSIGNED NULL
- timestamps

Default decisions:

- Early work is calculated separately.
- After-hours work is calculated separately.
- Early work does not cancel an early departure.
- Non-working-day work is fully eligible when the employee is overtime-eligible.
- Holiday work is fully eligible when the employee is overtime-eligible.
- Exact minutes are the default.
- Overtime approval is optional and configurable.
- Calculated overtime and approved overtime are separate values.
- Break deduction is applied once.
- Grace period affects lateness only, not overtime.
- Overnight attendance is explicitly supported through full datetimes.
- No payroll rate calculation is included.

Rounding modes:

- exact minutes
- round down to interval
- round up to interval
- nearest interval

Supported intervals: 5, 10, 15, and 30 minutes. Do not silently select a rounding rule; admin UI must show the active decision.

## 10. Overtime Components

Store auditable component totals when an attendance record is finalized or admin-edited:

- `early_overtime_minutes`
- `after_hours_overtime_minutes`
- `non_working_day_overtime_minutes`
- `holiday_overtime_minutes`
- `total_calculated_overtime_minutes`
- `approved_overtime_minutes`
- `rejected_overtime_minutes`
- `pending_approval_overtime_minutes`

Derive display durations from stored minutes. Keep approval status and calculated minutes separate so reports can distinguish worked overtime from approved overtime.

## 11. Overnight Attendance

For schedules such as 21:00-05:00 next day:

- Build scheduled start as attendance date plus scheduled start time in the resolved timezone.
- Build scheduled end as the same date plus end time, then add one day when `is_overnight = 1`.
- Check Out date must be explicit or derived only after validation by `AttendancePolicyService`.
- Set a maximum allowed attendance duration, for example 18 hours by default, configurable in policy.
- If Check Out time is earlier than Check In, do not automatically assume next day unless the resolved schedule allows overnight attendance or the admin explicitly marks the record as overnight.
- Attribute the reporting date to the scheduled start date unless a report explicitly groups by actual Check Out date.
- Holiday attribution must be defined for intervals crossing midnight: the scheduled start date controls day type by default, with interval-split reporting available later.

## 12. Admin Policy UI

Administration pages:

- Company Profile
- Work Schedule Templates
- Schedule Template Form
- Department Schedule Assignment
- Employee Schedule Assignment
- Attendance Policy
- Overtime Policy

UI requirements:

- Compact sections
- Clear required fields
- Helpful examples
- Effective-date controls
- Preview of the weekly schedule
- Validation summaries
- Normal-size secondary buttons
- Clear Save action
- English and Myanmar support

Do not make the UI resemble a large HR suite. Keep it operational, compact, and consistent with existing OAAS admin forms.

## 13. Database Migration Plan

Proposed order:

1. Add `company_profiles`.
2. Add `work_schedule_templates`.
3. Add `work_schedule_days`.
4. Add `work_schedule_assignments`.
5. Add `overtime_policies`.
6. Add optional V3 columns to `attendance_records` after services are ready.
7. Seed one company profile and one default schedule equivalent to the current policy.
8. Backfill only compatibility defaults, not recalculated historical attendance.

Indexes:

- `company_profiles`: active lookup and optional active uniqueness strategy.
- `work_schedule_templates`: active/effective date lookup.
- `work_schedule_days`: unique `(schedule_template_id, day_of_week)`.
- `work_schedule_assignments`: `(scope_type, scope_id, effective_from, effective_to, is_active)`.
- `overtime_policies`: active/effective date lookup.
- Future attendance V3 columns: `(attendance_date, day_type, attendance_state, user_id)`.

Foreign keys:

- Created/updated user references use `ON DELETE SET NULL`.
- Schedule day/template references use `ON DELETE CASCADE`.
- Assignment/template references should be restricted while active assignment rows exist.

Overlap protection:

- Use service-level transaction and row locks for the same scope.
- Reject ranges where `new_from <= existing_to OR existing_to IS NULL` and `new_to IS NULL OR new_to >= existing_from`.
- Add database indexes to make this check fast; MySQL does not provide a portable exclusion constraint for date ranges.

Rollback:

- Roll back in reverse order.
- Do not drop added attendance compatibility columns until exports and views no longer depend on them.
- Export affected policy and assignment rows before rollback in production.

## 14. Historical Data Strategy

Historical records created under old hardcoded rules must remain traceable.

Required workflow:

1. Dry-run diagnostic identifies contradictions and old-rule assumptions.
2. Classify affected records by type: special-day mismatch, present without times, absent with times, invalid duration, policy missing.
3. Resolve schedules by effective date.
4. Require manual approval before any update.
5. Repair in transactions through operation services.
6. Audit each repaired record with before/after values.
7. Export before/after CSV or SQL snapshot.
8. Keep rollback support through backup and repair logs.

Do not automatically recalculate historical records when schedules or policies change.

## 15. Reporting Impact

Future updates will be required for:

- Admin dashboard
- Employee dashboard
- Analytics
- Attendance list
- Attendance details
- Attendance Summary Report
- Employee Attendance Detail
- Late/Absent Exceptions
- PDF exports
- Excel exports
- Notifications
- Audit Logs

Reports should eventually expose:

- Resolved schedule
- Day type
- Regular minutes
- Overtime components
- Approved overtime
- Late minutes
- Early-departure minutes

Use the stored Attendance V3 fields where present and keep legacy fields as the compatibility surface for existing report, PDF, and Excel exports until every view is migrated.

## 16. Audit Requirements

Policy changes must record:

- Actor
- Timestamp
- Source IP
- User agent when available
- Entity type
- Entity ID
- Action
- Previous values
- New values
- Effective date
- Reason when required

Sensitive values must not be logged.

Trusted proxy rule:

- Preserve `REMOTE_ADDR` as the original immediate connection IP.
- Do not trust `X-Forwarded-For`, `Forwarded`, or `X-Real-IP` by default.
- Only evaluate forwarded headers when `REMOTE_ADDR` matches an explicitly configured trusted proxy allowlist.
- Store both immediate connection IP and selected client IP only after an approved migration.

## 17. Security Requirements

- Admin-only authorization
- CSRF protection on every write
- Strict server-side validation
- Transaction safety
- Safe logo upload controls
- Valid IANA timezone handling
- IP validation
- Trusted proxy allowlist
- No arbitrary serialized PHP objects
- Output escaping
- No raw exception details in production
- Rate-limit consideration for sensitive policy changes

## 18. Test Plan

Schedule resolution:

- Company default
- Department override
- Employee override
- Effective-date transitions
- Overlapping-assignment rejection

Working days:

- Monday-Friday company
- Monday-Saturday company
- Sunday-Thursday company
- Employee with different schedule
- Changed schedule effective next month

Attendance:

- Normal present
- Late
- Early departure
- Missing Check Out
- Non-working-day work
- Holiday work
- Approved leave
- Overnight schedule
- Check Out after midnight

Overtime:

- Early only
- After-hours only
- Early plus early departure
- Non-working-day work
- Holiday work
- Break deduction
- Minimum threshold
- Every rounding mode
- Ineligible employee
- Approval required
- Approved/rejected overtime

Regression:

- Existing authentication
- Leave
- Notifications
- Reports
- PDF
- Excel
- Audit Logs
- Pagination
- Localization

## 19. Final UAT Evidence

The final acceptance pass on 2026-07-29 kept the policy architecture and database schema unchanged.

- Current active policy remained `OAAS Standard Office` in the presentation layer.
- Test-only policy versions from the earlier UAT pass were cancelled or archived and cannot activate.
- Overtime approval was tested with an isolated temporary employee policy assignment for after-hours, holiday, non-working-day, and below-minimum overtime cases.
- `OvertimeReviewService` now rejects stale terminal decisions, preventing duplicate two-tab approval/rejection submissions while preserving approve, adjust, reset, and reject workflows.
- Query-count diagnostic: 500 resolver requests, legacy path 4890 queries, batch path 9 queries.
- Attendance diagnostic after cleanup still reports only attendance record `2155`; that record was not repaired or changed.
- Temporary UAT employee, attendance, policy, assignment, holiday, notification, and decision rows were removed after testing; audit logs were retained.

## 20. Remaining Decisions

- Whether logo upload belongs in the first Company Profile release.
- Whether a company can have only one active overtime policy or effective-dated policy assignments by department/employee.
- Whether leave balance should exclude company holidays automatically.
- Maximum allowed overnight attendance duration.
- Whether public IP display should say "Public/Remote Source" or show only the raw IP for privacy-sensitive deployments.
- Whether overtime approval is enabled in V3 or deferred after calculated overtime components.
