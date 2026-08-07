<?php

declare(strict_types=1);

test('master-data lists and forms use the compact presentation', function (): void {
    $departmentIndex = file_get_contents(project_path('views/masterdata/departments/index.php'));
    $departmentForm = file_get_contents(project_path('views/masterdata/departments/form.php'));
    $positionIndex = file_get_contents(project_path('views/masterdata/positions/index.php'));
    $positionForm = file_get_contents(project_path('views/masterdata/positions/form.php'));
    $holidayIndex = file_get_contents(project_path('views/masterdata/holidays/index.php'));
    $holidayForm = file_get_contents(project_path('views/masterdata/holidays/form.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    foreach ([$departmentIndex, $departmentForm, $positionIndex, $positionForm, $holidayIndex, $holidayForm, $css] as $content) {
        assert_true(is_string($content), 'Master-data presentation files should be readable.');
    }

    assert_contains('sapas-department-table', $departmentIndex);
    assert_contains('sapas-position-table', $positionIndex);
    assert_contains('sapas-holiday-admin-table', $holidayIndex);
    assert_contains('sapas-data-table-scroll', $departmentIndex);
    assert_contains('sapas-data-table-scroll', $positionIndex);
    assert_contains('sapas-data-table-scroll', $holidayIndex);
    assert_contains('masterdata.holidays.status_upcoming', $holidayIndex);
    assert_contains('sapas-masterdata-form-card', $departmentForm);
    assert_contains('sapas-masterdata-form-card', $positionForm);
    assert_contains('sapas-masterdata-form-card', $holidayForm);
    assert_contains('.sapas-masterdata-actions', $css);
    assert_contains('.sapas-masterdata-form-footer', $css);
});
