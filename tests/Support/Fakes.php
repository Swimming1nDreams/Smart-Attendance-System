<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Application;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;

final class FakeApplication extends Application
{
    public function __construct()
    {
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

final class FakeAttendance extends Attendance
{
    public function __construct()
    {
    }
}

final class FakeHoliday extends Holiday
{
    /** @param list<string> $dates */
    public function __construct(private array $dates = [])
    {
    }

    public function existsByDate(string $date, ?int $excludeId = null): bool
    {
        return in_array($date, $this->dates, true);
    }
}

final class FakeEmployee extends Employee
{
    /** @param array<int, array<string, mixed>> $employees */
    public function __construct(private array $employees = [])
    {
    }

    public function findById(int $id): ?array
    {
        return $this->employees[$id] ?? null;
    }

    public function findAll(string $search = '', ?int $departmentId = null, ?int $positionId = null, ?string $status = null, int $page = 1, int $perPage = 20, ?string $role = null): array
    {
        return array_values($this->employees);
    }
}

final class FakeLeaveRequest extends LeaveRequest
{
    /** @param array<int, array<string, mixed>> $requests */
    public function __construct(private array $requests = [], private bool $approvedLeaveConflict = false)
    {
    }

    public function findById(int $id): ?array
    {
        return $this->requests[$id] ?? null;
    }

    public function existsApprovedLeaveForDate(int $userId, string $date): bool
    {
        return $this->approvedLeaveConflict;
    }

    public function findOverlappingRequests(int $userId, string $startDate, string $endDate, ?int $excludeId = null): array
    {
        return [];
    }
}

final class FakeUser extends User
{
    /** @param array<string, mixed>|null $user */
    public function __construct(private ?array $user, private bool $passwordValid = true)
    {
    }

    public function findByEmail(string $email): ?array
    {
        return $this->user;
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return $this->passwordValid;
    }
}
