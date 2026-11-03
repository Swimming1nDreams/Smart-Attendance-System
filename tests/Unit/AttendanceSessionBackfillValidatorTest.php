<?php

declare(strict_types=1);

use App\Services\AttendanceSessionBackfillValidator;

function classify_backfill_drift(
    array $persisted,
    array $calculated,
    array $mismatches,
    array $plan = [],
    array $resolution = [],
): array {
    return (new AttendanceSessionBackfillValidator())->classifyPersistedDrift(
        $persisted,
        $calculated,
        $plan,
        $resolution,
        $mismatches,
    );
}

test('Phase 4 validator reviews stale is_late only when persisted late minutes prove the legacy flag is stale', function (): void {
    $reviewed = classify_backfill_drift(
        ['is_late' => false, 'late_minutes' => 43],
        ['is_late' => true],
        ['is_late' => ['persisted' => false, 'session' => true]],
    );

    assert_true(isset($reviewed['reviewed_legacy_drift']['is_late']));
    assert_same([], $reviewed['unexplained_persisted_drift']);

    $unexplained = classify_backfill_drift(
        ['is_late' => 0, 'late_minutes' => 0],
        ['is_late' => 1],
        ['is_late' => ['persisted' => 0, 'session' => 1]],
    );
    assert_true(isset($unexplained['unexplained_persisted_drift']['is_late']));
});

test('Phase 4 validator reviews only zero-to-positive legacy overtime breakdown drift', function (): void {
    $classification = classify_backfill_drift(
        ['early_overtime_minutes' => 0, 'after_hours_overtime_minutes' => '0'],
        ['early_overtime_minutes' => 42, 'after_hours_overtime_minutes' => 30],
        [
            'early_overtime_minutes' => ['persisted' => 0, 'session' => 42],
            'after_hours_overtime_minutes' => ['persisted' => '0', 'session' => 30],
        ],
    );

    assert_same(2, count($classification['reviewed_legacy_drift']));
    assert_same([], $classification['unexplained_persisted_drift']);

    $negative = classify_backfill_drift(
        ['after_hours_overtime_minutes' => 0],
        ['after_hours_overtime_minutes' => -5],
        ['after_hours_overtime_minutes' => ['persisted' => 0, 'session' => -5]],
    );
    assert_true(isset($negative['unexplained_persisted_drift']['after_hours_overtime_minutes']));
});

test('Phase 4 validator reviews schedule-proven stale Early Departure fields without attendance ID allowlists', function (): void {
    $classification = classify_backfill_drift(
        [
            'is_early_departure' => 1,
            'early_departure_minutes' => 42,
            'early_departure_reason' => 'personal_emergency',
        ],
        ['is_early_departure' => 0, 'early_departure_minutes' => 0],
        [
            'is_early_departure' => ['persisted' => 1, 'session' => 0],
            'early_departure_minutes' => ['persisted' => 42, 'session' => 0],
            'early_departure_reason' => ['persisted' => 'personal_emergency', 'session' => null],
        ],
        ['check_out_at' => '2026-08-12 17:00:00'],
        ['scheduled_end_at' => '2026-08-12 17:00:00'],
    );

    assert_same(3, count($classification['reviewed_legacy_drift']));
    assert_same([], $classification['unexplained_persisted_drift']);
});

test('Phase 4 validator keeps arbitrary persisted mismatches blocking', function (): void {
    $classification = classify_backfill_drift(
        ['late_minutes' => 7],
        ['late_minutes' => 12],
        ['late_minutes' => ['persisted' => 7, 'session' => 12]],
    );

    assert_same([], $classification['reviewed_legacy_drift']);
    assert_true(isset($classification['unexplained_persisted_drift']['late_minutes']));
});

test('Phase 4 validator reports reviewed and unexplained drift record counts separately', function (): void {
    $counts = (new AttendanceSessionBackfillValidator())->countPersistedDrift([
        [
            'persisted_mismatches' => ['is_late' => true],
            'reviewed_legacy_drift' => ['is_late' => true],
            'unexplained_persisted_drift' => [],
        ],
        [
            'persisted_mismatches' => ['late_minutes' => true],
            'reviewed_legacy_drift' => [],
            'unexplained_persisted_drift' => ['late_minutes' => true],
        ],
    ]);

    assert_same(2, $counts['persisted_mismatch_records']);
    assert_same(1, $counts['reviewed_legacy_drift_records']);
    assert_same(1, $counts['unexplained_persisted_drift_records']);
});

test('Phase 4 live readiness allows reviewed legacy drift but never semantic or other blocking issues', function (): void {
    $validator = new AttendanceSessionBackfillValidator();
    $clear = [
        'manual_review' => 0,
        'existing_session_conflict' => 0,
        'resolution_errors' => 0,
        'semantic_mismatch_records' => 0,
        'persisted_mismatch_records' => 3,
        'reviewed_legacy_drift_records' => 3,
        'unexplained_persisted_drift_records' => 0,
    ];

    assert_true($validator->isReadyForLiveBackfill($clear));

    $semantic = array_replace($clear, ['semantic_mismatch_records' => 1]);
    assert_false($validator->isReadyForLiveBackfill($semantic));

    $unexplained = array_replace($clear, ['unexplained_persisted_drift_records' => 1]);
    assert_false($validator->isReadyForLiveBackfill($unexplained));
});
