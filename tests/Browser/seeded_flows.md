# SAPAS Seeded Browser Flow Checklist

This is a lightweight authenticated browser QA foundation, not a full browser automation framework.

1. Seed the browser QA database:

   ```bash
   php tests/browser_seed.php
   ```

2. Start SAPAS against the seeded browser database:

   ```bash
   DB_NAME=sapas_browser_test php -S 127.0.0.1:8080 -t public public/index.php
   ```

   On PowerShell:

   ```powershell
   $env:DB_NAME='sapas_browser_test'
   php -S 127.0.0.1:8080 -t public public/index.php
   ```

3. Use these seeded accounts:

   - Admin: `admin.integration@sapas.test` / `Password123!`
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

The seed helper resets only a database whose name contains `test`, and the default browser database is `sapas_browser_test`.
