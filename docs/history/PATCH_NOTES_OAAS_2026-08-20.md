# OAAS Correction Patch — 2026-08-20

Project title: **Office Attendance and Analytic System (OAAS)**

## Fixed
- Employee Role filter now filters the database query and count, not only the UI.
- Department Heads see only employee accounts in their own department on the Employees list.
- Department Head Employees page is view-only: Admin-only Add/Edit/Deactivate actions are hidden.
- Attendance permissions are split between team viewing and Admin management; Department Heads no longer see Admin-only Add/Edit Attendance actions.
- Attendance, Leave, and Absence filters use the backend-resolved department/employee scope instead of trusting raw query-string state.
- Department Head department filters are visibly locked to their own department.
- Changing an Admin department filter clears the previous employee selection before reloading.
- Invalid employee/department combinations are normalized safely in backend list controllers.
- Absence page was cleaned into a dedicated absence-only UI with its own English/Myanmar labels and no copied Attendance add action.
- Department Head sidebar now shows Employees and Department Absence; Employee sidebar shows My Absences.
- OAAS branding updated in runtime language strings, mail identity, export identity, report filenames, package naming, demo-account heading, and startup messages.
- Legacy stored SAPAS/OAPAS default organization/policy names are presented as OAAS without rewriting database data.

## Intentionally Not Changed
- Six-digit manual Staff Code requirement (future phase).
- Advanced Leave Management / leave balance / leave attachments (future phase).
- Policy one-day-before notifications (future phase).
- Attendance exception approval workflow (future phase).
- Existing stored database policy identifiers are not destructively renamed.

## Verification
- Full PHP syntax lint: **0 syntax errors**.
- Targeted regression/unit checks: **35 passed, 0 failed, 1 skipped**.
- The skipped test is MySQL-backed Absence integration because this container does not provide the `pdo_mysql` driver.

## 2026-08-20 Absence Route Hotfix
- Fixed `/absence` returning the generic "Service temporarily unavailable" page.
- Root cause: `AbsenceController::index()` called four helper methods (`queryScalar`, `dateFilterErrors`, `clampPage`, and `ensureActiveEmployee`) that existed only as private methods in `AttendanceController` and were not defined or inherited by `AbsenceController`.
- Added equivalent private helpers directly to `AbsenceController` so the route can execute normally without changing attendance/report/analytics behavior.
- Verified PHP syntax and method availability via reflection.
