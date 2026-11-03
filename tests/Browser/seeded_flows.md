# OAAS Seeded Browser Flow Checklist

This is a lightweight authenticated browser QA foundation, not a full browser automation framework.

1. Seed the browser QA database:

   ```bash
   OAAS_BROWSER_DB_NAME=oaas_browser_test php tests/browser_seed.php
   ```

   On PowerShell:

   ```powershell
   $env:OAAS_BROWSER_DB_NAME='oaas_browser_test'
   php tests/browser_seed.php
   ```

2. Start OAAS against the seeded browser database:

   ```bash
   DB_NAME=oaas_browser_test php -S 127.0.0.1:8080 -t public public/index.php
   ```

   On PowerShell:

   ```powershell
   $env:DB_NAME='oaas_browser_test'
   php -S 127.0.0.1:8080 -t public public/index.php
   ```

3. Use these seeded accounts:

   - Admin: `admin.integration@sapas.test` / `Password123!`
   - Department Head: `department.head.integration@sapas.test` / `Password123!`
   - Employee: `employee.integration@sapas.test` / `Password123!`
   - Inactive employee: `inactive.integration@sapas.test` / `Password123!`

4. High-value flows to verify:

   - Admin login reaches the admin dashboard.
   - Employee login reaches the employee dashboard.
   - Inactive employee login is blocked.
   - Admin can open Reports, Attendance Summary, Leave Requests, and Notifications.
   - Employee can open Attendance and Leave pages but cannot open admin report URLs.
   - Employee can open the seeded pending leave request and see only their own leave rows.
   - English/Myanmar switching keeps authenticated navigation usable.
   - My Profile shows account email as read-only with its verification status; no normal email-edit control is present.
   - The Employee Edit form shows existing email as identity information and displays the security-change consequence warning.
   - Selecting Department Head marks Department as required and explains the department-scoped authority rule.
   - My Profile shows the effective Workforce Policy summary without presenting compatibility expected hours as the current schedule.
   - A saved profile photo appears in My Profile and the topbar for Administrator, Department Head, and Employee accounts.

5. Password self-service smoke check on this disposable browser database:

   - Repeat the check for Administrator, Department Head, and Employee.
   - Sign in, open **My Profile**, and confirm the **Change Password** action is clearly visible.
   - Open the form and confirm its labels remain readable in both English and Myanmar.
   - Submit the current password, a policy-compliant new password, and matching confirmation.
   - Confirm OAAS returns to Login, the old password is rejected, and the new password signs in successfully.
   - Re-run `php tests/browser_seed.php` afterward if the original seeded credentials are needed for other flows.

The seed helper resets only a database whose name contains `test`. The `OAAS_BROWSER_DB_NAME` setting is preferred; `SAPAS_BROWSER_DB_NAME` and the unchanged `sapas_browser_test` fallback remain available for legacy compatibility. The seeded `@sapas.test` addresses are internal fixtures, not visible product branding.
