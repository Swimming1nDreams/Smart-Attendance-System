# OAAS Policy Notification, Absence, and Staff Code Update

## Delivered behavior

- Active users in a policy assignment's company, department, or employee scope receive an in-app reminder at midnight one day before the assignment becomes effective. Assignments created after that reminder time make the reminder available immediately.
- Future reminders are hidden from lists, unread counts, and read actions until `available_at`. Updating or ending an assignment resynchronizes or removes its undelivered reminders inside the assignment transaction.
- Attendance lists and the employee today card do not display stored `absent` records. The `/absence` page remains the dedicated stored-absence view.
- Administrators must enter a unique staff code containing exactly six ASCII digits. Runtime `SAP-####` sequence allocation has been removed. Historical codes remain readable and can be replaced during an Administrator edit.

## Deployment

Run the migration command after deploying the updated source:

```bash
php migrations/run_migrations.php
```

The new migration is `migrations/2026_08_20_000000_add_scheduled_policy_notifications.sql`.
The existing leave-reason migration is now idempotent for databases where `reason_category` was added before its migration-history row, preventing duplicate-column failure during upgrades.

## Verification coverage

- Scheduled notification visibility, scope recipients, assignment rescheduling, and cancellation.
- Required six-digit staff-code validation and Administrator form wiring.
- Attendance/Absence read-surface separation.
- English/Myanmar policy-reminder and staff-code messages.
