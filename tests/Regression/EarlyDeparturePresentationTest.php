<?php

declare(strict_types=1);

test('early departure checkout and review surfaces are localized and server-authoritative', function (): void {
    $partial = file_get_contents(project_path('views/partials/early_departure_checkout.php'));
    $attendance = file_get_contents(project_path('views/attendance/index.php'));
    $dashboard = file_get_contents(project_path('views/dashboard/employee.php'));
    $controller = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    $service = file_get_contents(project_path('src/Services/AttendanceOperationService.php'));
    $analytics = file_get_contents(project_path('views/analytics/index.php'));
    $policy = file_get_contents(project_path('views/work_schedules/index.php'));

    assert_contains('data-early-checkout-modal', $partial);
    assert_contains('name="early_departure_reason"', $partial);
    assert_contains('name="early_departure_note"', $partial);
    assert_contains('maxlength="500"', $partial);
    assert_not_contains('name="early_departure_reason" class="mt-2 sapas-form-control" required', $partial);
    assert_contains('if (reason) reason.required = true;', $partial);
    assert_contains('reason.required = false;', $partial);
    assert_contains("reason.value = '';", $partial);
    assert_contains('note.required = false;', $partial);
    assert_contains("note.value = '';", $partial);
    assert_contains("reason?.value === 'other_authorized'", $partial);
    assert_contains("if (form.dataset.confirmed === 'true') return;", $partial);
    assert_contains('close(false, false);', $partial);
    assert_contains('form.requestSubmit(trigger);', $partial);
    assert_contains('data-scheduled-end-epoch-ms', $partial);
    assert_contains('data-early-departure-grace-minutes', $partial);
    assert_contains('const freshEarlyMinutes = () => {', $partial);
    assert_contains('scheduledEndEpochMs - Date.now()', $partial);
    assert_contains('if (earlyMinutesNow <= 0) return;', $partial);
    assert_contains('syncMessage(minutes);', $partial);
    assert_contains('previewClockOut', $controller);
    assert_contains('validatedEarlyDepartureContext', $service);
    assert_not_contains('name="clock_out_time"', $partial);
    assert_not_contains('name="early_departure_minutes"', $partial);
    assert_not_contains('name="scheduled_end_at"', $partial);
    assert_contains('attendance.early_departure', $attendance);
    assert_contains('attendance.early_departure', $dashboard);
    assert_contains('early_departure_count', $analytics);
    assert_contains('early_departure_grace_minutes', $policy);
    assert_contains('attendance.out_of_schedule_work', $attendance);
    assert_contains('attendance.overtime_candidate_pending', $attendance);
    assert_contains('attendance.out_of_schedule_work', $dashboard);
    assert_contains('attendance.approved_overtime', $dashboard);

    foreach ([project_path('lang/en.php'), project_path('lang/my.php')] as $languageFile) {
        $dictionary = require $languageFile;
        $title = trim((string) ($dictionary['attendance']['early_checkout']['title'] ?? ''));
        assert_true($title !== '' && $title !== 'attendance.early_checkout.title');
        foreach (\App\Services\AttendanceOperationService::EARLY_DEPARTURE_REASONS as $code) {
            assert_true(trim((string) ($dictionary['attendance']['early_departure_reasons'][$code] ?? '')) !== '');
        }
        assert_true(trim((string) ($dictionary['workforce_policy']['early_departure_grace'] ?? '')) !== '');
    }
});
