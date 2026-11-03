<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use Throwable;

/**
 * Classifies Phase 4 persisted-vs-session drift without weakening semantic
 * parity checks. Only the reviewed historical patterns below are non-blocking.
 */
final class AttendanceSessionBackfillValidator
{
    /**
     * @param array<string, mixed> $persisted
     * @param array<string, mixed> $calculated
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $resolution
     * @param array<string, array{persisted:mixed,session:mixed}> $mismatches
     * @return array{
     *     reviewed_legacy_drift:array<string,array{persisted:mixed,session:mixed,rule:string}>,
     *     unexplained_persisted_drift:array<string,array{persisted:mixed,session:mixed}>
     * }
     */
    public function classifyPersistedDrift(
        array $persisted,
        array $calculated,
        array $plan,
        array $resolution,
        array $mismatches,
    ): array {
        $reviewed = [];
        $unexplained = [];
        $earlyDepartureContext = $this->reviewedEarlyDepartureContext($persisted, $calculated, $plan, $resolution);
        $outOfScheduleContext = $this->reviewedOutOfScheduleContext($persisted, $calculated);

        foreach ($mismatches as $field => $mismatch) {
            $rule = null;
            $persistedValue = $mismatch['persisted'] ?? null;
            $sessionValue = $mismatch['session'] ?? null;

            if ($field === 'is_late'
                && $this->isZero($persistedValue)
                && $this->isOne($sessionValue)
                && $this->isPositive($persisted['late_minutes'] ?? null)) {
                $rule = 'stale_is_late_false_with_positive_late_minutes';
            } elseif (in_array($field, ['early_overtime_minutes', 'after_hours_overtime_minutes'], true)
                && $this->isZero($persistedValue)
                && $this->isPositive($sessionValue)) {
                $rule = 'legacy_overtime_breakdown_zero';
            } elseif ($earlyDepartureContext
                && $field === 'is_early_departure'
                && $this->isOne($persistedValue)
                && $this->isZero($sessionValue)) {
                $rule = 'stale_early_departure_after_schedule_or_without_overlap';
            } elseif ($earlyDepartureContext
                && $field === 'early_departure_minutes'
                && $this->isPositive($persistedValue)
                && $this->isZero($sessionValue)) {
                $rule = 'stale_early_departure_after_schedule_or_without_overlap';
            } elseif ($earlyDepartureContext
                && in_array($field, ['early_departure_reason', 'early_departure_note'], true)
                && $this->isNonEmpty($persistedValue)
                && !$this->isNonEmpty($sessionValue)) {
                $rule = 'stale_early_departure_context';
            } elseif ($outOfScheduleContext
                && $field === 'attendance_state'
                && (string) $persistedValue === 'present'
                && (string) $sessionValue === 'out_of_schedule') {
                $rule = 'legacy_present_without_scheduled_overlap';
            }

            if ($rule === null) {
                $unexplained[$field] = $mismatch;
                continue;
            }

            $reviewed[$field] = array_replace($mismatch, ['rule' => $rule]);
        }

        return [
            'reviewed_legacy_drift' => $reviewed,
            'unexplained_persisted_drift' => $unexplained,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{persisted_mismatch_records:int,reviewed_legacy_drift_records:int,unexplained_persisted_drift_records:int}
     */
    public function countPersistedDrift(array $items): array
    {
        $counts = [
            'persisted_mismatch_records' => 0,
            'reviewed_legacy_drift_records' => 0,
            'unexplained_persisted_drift_records' => 0,
        ];

        foreach ($items as $item) {
            if (!empty($item['persisted_mismatches'])) {
                $counts['persisted_mismatch_records']++;
            }
            if (!empty($item['reviewed_legacy_drift'])) {
                $counts['reviewed_legacy_drift_records']++;
            }
            if (!empty($item['unexplained_persisted_drift'])) {
                $counts['unexplained_persisted_drift_records']++;
            }
        }

        return $counts;
    }

    /** @param array<string, int> $counts */
    public function isReadyForLiveBackfill(array $counts): bool
    {
        foreach ([
            'manual_review',
            'existing_session_conflict',
            'resolution_errors',
            'semantic_mismatch_records',
            'unexplained_persisted_drift_records',
        ] as $blockingCount) {
            if ((int) ($counts[$blockingCount] ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $persisted @param array<string, mixed> $calculated */
    private function reviewedOutOfScheduleContext(array $persisted, array $calculated): bool
    {
        return (string) ($persisted['attendance_state'] ?? '') === 'present'
            && $this->isOne($persisted['is_early_departure'] ?? null)
            && (string) ($calculated['attendance_state'] ?? '') === 'out_of_schedule'
            && $this->isZero($calculated['is_early_departure'] ?? null)
            && $this->isZero($calculated['early_departure_minutes'] ?? null)
            && $this->isZero($calculated['scheduled_overlap_minutes'] ?? null);
    }

    /**
     * @param array<string, mixed> $persisted
     * @param array<string, mixed> $calculated
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $resolution
     */
    private function reviewedEarlyDepartureContext(array $persisted, array $calculated, array $plan, array $resolution): bool
    {
        if (!$this->isOne($persisted['is_early_departure'] ?? null)
            || !$this->isZero($calculated['is_early_departure'] ?? null)
            || !$this->isZero($calculated['early_departure_minutes'] ?? null)) {
            return false;
        }

        return $this->atOrAfterScheduledEnd($plan['check_out_at'] ?? null, $resolution['scheduled_end_at'] ?? null)
            || $this->reviewedOutOfScheduleContext($persisted, $calculated);
    }

    private function atOrAfterScheduledEnd(mixed $checkOutAt, mixed $scheduledEndAt): bool
    {
        if (!$this->isNonEmpty($checkOutAt) || !$this->isNonEmpty($scheduledEndAt)) {
            return false;
        }

        try {
            return new DateTimeImmutable((string) $checkOutAt) >= new DateTimeImmutable((string) $scheduledEndAt);
        } catch (Throwable) {
            return false;
        }
    }

    private function isZero(mixed $value): bool
    {
        return $value === false || (is_numeric($value) && (float) $value === 0.0);
    }

    private function isOne(mixed $value): bool
    {
        return $value === true || (is_numeric($value) && (float) $value === 1.0);
    }

    private function isPositive(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0.0;
    }

    private function isNonEmpty(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
