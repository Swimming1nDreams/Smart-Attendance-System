<?php
$badgeStatus = (string) ($badgeStatus ?? '');
$badgeType = (string) ($badgeType ?? 'neutral');
$badgeClass = 'sapas-badge sapas-badge-neutral';

if ($badgeType === 'leave') {
    $badgeClass = match ($badgeStatus) {
        'pending' => 'sapas-badge sapas-badge-warning',
        'approved' => 'sapas-badge sapas-badge-success',
        'rejected' => 'sapas-badge sapas-badge-danger',
        default => 'sapas-badge sapas-badge-neutral',
    };
} elseif ($badgeType === 'attendance') {
    $badgeClass = match ($badgeStatus) {
        'present' => 'sapas-badge sapas-badge-success',
        'absent' => 'sapas-badge sapas-badge-danger',
        'holiday' => 'sapas-badge sapas-badge-info',
        'weekend' => 'sapas-badge sapas-badge-neutral',
        default => 'sapas-badge sapas-badge-neutral',
    };
} elseif ($badgeType === 'exception') {
    $badgeClass = $badgeStatus === 'absent'
        ? 'sapas-badge sapas-badge-danger'
        : 'sapas-badge sapas-badge-warning';
} elseif ($badgeType === 'account') {
    $badgeClass = $badgeStatus === 'active'
        ? 'sapas-badge sapas-badge-success'
        : 'sapas-badge sapas-badge-danger';
}

echo $badgeClass;
