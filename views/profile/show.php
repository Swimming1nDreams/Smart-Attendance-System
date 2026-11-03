<?php
$t = $t ?? static fn (string $key): string => $key;
$employee = is_array($employee ?? null) ? $employee : [];
$profile = is_array($profile ?? null) ? $profile : [];
$formData = is_array($formData ?? null) ? $formData : [];
$errors = is_array($errors ?? null) ? $errors : [];
$hasPhoto = (bool) ($hasPhoto ?? false);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$display = static function (mixed $value) use ($t): string {
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : $t('profile.not_provided');
};
$name = trim((string) ($employee['name'] ?? ''));
$positionName = trim((string) ($employee['position_name'] ?? ''));
$departmentName = trim((string) ($employee['department_name'] ?? ''));
$status = (string) ($employee['status'] ?? 'inactive');
$employmentType = str_replace('-', '_', (string) ($employee['employment_type'] ?? 'full-time'));
$isEmailVerified = ($employee['email_verified_at'] ?? null) !== null;
$resolvedWorkPolicy = is_array($resolvedWorkPolicy ?? null) ? $resolvedWorkPolicy : ['available' => false];
?>
<div class="space-y-6">
    <section class="sapas-workspace-card overflow-hidden">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-col items-center gap-5 text-center sm:flex-row sm:text-left">
                <?php
                $avatarName = $name;
                $avatarPhotoUrl = (string) ($profilePhotoUrl ?? '');
                $avatarClass = 'h-28 w-28 border-4 border-white shadow-lg ring-1 ring-slate-200';
                $avatarInitialsClass = 'text-3xl';
                $avatarAlt = sprintf($t('profile.avatar_alt'), $name);
                $avatarFallbackLabel = sprintf($t('profile.avatar_fallback'), $name);
                require __DIR__ . '/../partials/avatar.php';
                unset($avatarName, $avatarPhotoUrl, $avatarClass, $avatarInitialsClass, $avatarAlt, $avatarFallbackLabel);
                ?>

                <div>
                    <p class="text-sm font-semibold uppercase tracking-wider text-sky-700"><?= $escape($t('profile.employee_profile')) ?></p>
                    <h2 class="mt-1 text-3xl font-semibold text-slate-950"><?= $escape($display($name)) ?></h2>
                    <?php if ($positionName !== '' || $departmentName !== ''): ?>
                        <div class="mt-3 flex flex-wrap justify-center gap-2 sm:justify-start" data-profile-organization-badges>
                            <?php if ($positionName !== ''): ?>
                                <span
                                    class="sapas-badge sapas-badge-info"
                                    data-profile-position-badge
                                    aria-label="<?= $escape($t('common.labels.position') . ': ' . $positionName) ?>"
                                    title="<?= $escape($t('common.labels.position')) ?>"
                                ><?= $escape($positionName) ?></span>
                            <?php endif; ?>
                            <?php if ($departmentName !== ''): ?>
                                <span
                                    class="sapas-badge sapas-badge-neutral"
                                    data-profile-department-badge
                                    aria-label="<?= $escape($t('common.labels.department') . ': ' . $departmentName) ?>"
                                    title="<?= $escape($t('common.labels.department')) ?>"
                                ><?= $escape($departmentName) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mt-3 flex flex-wrap justify-center gap-2 sm:justify-start">
                        <span class="sapas-badge bg-slate-100 text-slate-700"><?= $escape($t('profile.staff_code')) ?>: <?= $escape($display($employee['staff_code'] ?? null)) ?></span>
                        <span class="sapas-badge <?= $status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' ?>"><?= $escape($t('statuses.account.' . $status)) ?></span>
                    </div>
                </div>
            </div>

            <a href="#edit-profile" class="sapas-btn sapas-btn-primary sapas-btn-spotlight"><?= $escape($t('profile.edit_contact')) ?></a>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="sapas-workspace-card">
            <h2 class="text-xl font-semibold text-slate-950"><?= $escape($t('profile.personal_information')) ?></h2>
            <p class="mt-1 text-sm text-slate-500"><?= $escape($t('profile.personal_information_help')) ?></p>
            <dl class="mt-6 space-y-5">
                <div>
                    <dt class="text-sm font-medium text-slate-500"><?= $escape($t('profile.email')) ?></dt>
                    <dd class="mt-1 break-words text-base font-medium text-slate-900"><?= $escape($display($employee['email'] ?? null)) ?></dd>
                    <dd class="mt-2">
                        <span class="<?= $isEmailVerified ? 'sapas-badge sapas-badge-success' : 'sapas-badge sapas-badge-warning' ?>">
                            <?= $escape($isEmailVerified ? $t('profile.email_verified') : $t('profile.email_verification_required')) ?>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500"><?= $escape($t('profile.phone')) ?></dt>
                    <dd class="mt-1 text-base font-medium text-slate-900"><?= $escape($display($profile['phone'] ?? null)) ?></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500"><?= $escape($t('profile.address')) ?></dt>
                    <dd class="mt-1 whitespace-pre-line text-base font-medium text-slate-900"><?= $escape($display($profile['address'] ?? null)) ?></dd>
                </div>
            </dl>
        </section>

        <section class="sapas-workspace-card">
            <h2 class="text-xl font-semibold text-slate-950"><?= $escape($t('profile.employment_information')) ?></h2>
            <p class="mt-1 text-sm text-slate-500"><?= $escape($t('profile.read_only_help')) ?></p>
            <dl class="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-1">
                <div>
                    <dt class="text-sm font-medium text-slate-500"><?= $escape($t('profile.role')) ?></dt>
                    <dd class="mt-1 text-base font-medium text-slate-900"><?= $escape($t('roles.' . ($employee['role'] ?? 'employee'))) ?></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500"><?= $escape($t('profile.employment_type')) ?></dt>
                    <dd class="mt-1 text-base font-medium text-slate-900"><?= $escape($t('employees_module.employment_types.' . $employmentType)) ?></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500"><?= $escape($t('profile.join_date')) ?></dt>
                    <dd class="mt-1 text-base font-medium text-slate-900"><?= $escape($display($employee['join_date'] ?? null)) ?></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500"><?= $escape($t('profile.account_status')) ?></dt>
                    <dd class="mt-1 text-base font-medium text-slate-900"><?= $escape($t('statuses.account.' . $status)) ?></dd>
                </div>
            </dl>
        </section>

        <section class="sapas-workspace-card" data-effective-work-policy>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950"><?= $escape($t('profile.effective_work_policy')) ?></h2>
                    <p class="mt-1 text-sm text-slate-500"><?= $escape($t('profile.effective_work_policy_help')) ?></p>
                </div>
                <?php if (in_array((string) ($employee['role'] ?? ''), ['employee', 'department_head'], true)): ?>
                    <a href="/my-work-policy" class="sapas-btn sapas-btn-secondary shrink-0"><?= $escape($t('profile.view_work_policy')) ?></a>
                <?php endif; ?>
            </div>

            <?php if (!empty($resolvedWorkPolicy['available'])): ?>
                <?php $todayPolicy = is_array($resolvedWorkPolicy['today'] ?? null) ? $resolvedWorkPolicy['today'] : []; ?>
                <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <dt class="text-sm font-medium text-slate-500"><?= $escape($t('work_policy.current_policy')) ?></dt>
                        <dd class="mt-1 font-semibold text-slate-900"><?= $escape($resolvedWorkPolicy['name'] ?? $t('common.not_available')) ?></dd>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <dt class="text-sm font-medium text-slate-500"><?= $escape($t('work_policy.assignment_source')) ?></dt>
                        <dd class="mt-1 font-semibold text-slate-900"><?= $escape($resolvedWorkPolicy['source_label'] ?? $t('common.not_available')) ?></dd>
                        <?php if (!empty($resolvedWorkPolicy['source_context'])): ?><p class="mt-1 text-sm text-slate-600"><?= $escape($resolvedWorkPolicy['source_context']) ?></p><?php endif; ?>
                    </div>
                    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                        <dt class="text-sm font-medium text-sky-700"><?= $escape($t('work_policy.today_schedule')) ?></dt>
                        <dd class="mt-1 font-semibold text-slate-900"><?= $escape($todayPolicy['schedule_label'] ?? $t('common.not_available')) ?></dd>
                    </div>
                    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                        <dt class="text-sm font-medium text-sky-700"><?= $escape($t('work_policy.expected_work')) ?></dt>
                        <dd class="mt-1 font-semibold text-slate-900"><?= $escape($todayPolicy['expected_work_label'] ?? $t('common.not_available')) ?></dd>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <dt class="text-sm font-medium text-slate-500"><?= $escape($t('work_policy.grace_period')) ?></dt>
                        <dd class="mt-1 font-semibold text-slate-900"><?= $escape($todayPolicy['grace_label'] ?? $t('common.none')) ?></dd>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <dt class="text-sm font-medium text-slate-500"><?= $escape($t('work_policy.overtime_eligible')) ?></dt>
                        <dd class="mt-1 font-semibold text-slate-900"><?= $escape((int) ($employee['overtime_eligible'] ?? 0) === 1 ? $t('work_policy.eligible') : $t('work_policy.not_eligible')) ?></dd>
                    </div>
                </dl>
            <?php else: ?>
                <p class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600"><?= $escape($t('profile.effective_work_policy_unavailable')) ?></p>
            <?php endif; ?>
        </section>
    </div>

    <section class="sapas-workspace-card" data-profile-security>
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-slate-950"><?= $escape($t('profile.account_security')) ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= $escape($t('profile.account_security_help')) ?></p>
            </div>
            <a href="/profile/password" class="sapas-btn sapas-btn-primary shrink-0">
                <?= $escape($t('profile.change_password')) ?>
            </a>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-5">
        <section class="sapas-workspace-card lg:col-span-2">
            <h2 class="text-xl font-semibold text-slate-950"><?= $escape($t('profile.profile_photo')) ?></h2>
            <p class="mt-1 text-sm text-slate-500"><?= $escape($t('profile.photo_help')) ?></p>

            <form method="post" action="/profile/photo" enctype="multipart/form-data" class="mt-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token ?? '') ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= \App\Services\ProfilePhotoStorageService::MAX_BYTES ?>">
                <div>
                    <label for="profile-photo" class="block text-sm font-medium text-slate-700"><?= $escape($t('profile.choose_photo')) ?></label>
                    <input id="profile-photo" type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-sky-50 file:px-4 file:py-2 file:font-semibold file:text-sky-700 hover:file:bg-sky-100" required>
                </div>
                <button type="submit" class="sapas-btn sapas-btn-primary"><?= $escape($hasPhoto ? $t('profile.change_photo') : $t('profile.upload_photo')) ?></button>
            </form>

            <?php if ($hasPhoto): ?>
                <form method="post" action="/profile/photo/remove" class="mt-3">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token ?? '') ?>">
                    <button type="submit" class="sapas-btn sapas-btn-danger-quiet"><?= $escape($t('profile.remove_photo')) ?></button>
                </form>
            <?php endif; ?>
        </section>

        <section id="edit-profile" class="sapas-workspace-card scroll-mt-6 lg:col-span-3">
            <h2 class="text-xl font-semibold text-slate-950"><?= $escape($t('profile.edit_contact')) ?></h2>
            <p class="mt-1 text-sm text-slate-500"><?= $escape($t('profile.edit_contact_help')) ?></p>

            <?php if ($errors !== []): ?>
                <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-semibold"><?= $escape($t('profile.correct_errors')) ?></p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <?php foreach ($errors as $error): ?>
                            <li><?= $escape($t('validation.' . (string) $error)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="/profile/update" class="mt-6 space-y-5">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token ?? '') ?>">
                <div>
                    <label for="profile-phone" class="block text-sm font-medium text-slate-700"><?= $escape($t('profile.phone')) ?></label>
                    <input id="profile-phone" type="tel" name="phone" value="<?= $escape($formData['phone'] ?? '') ?>" maxlength="50" autocomplete="tel" class="mt-2 sapas-form-control" placeholder="09xxxxxxxxx">
                    <p class="mt-1 text-sm text-slate-500"><?= $escape($t('profile.phone_help')) ?></p>
                </div>
                <div>
                    <label for="profile-address" class="block text-sm font-medium text-slate-700"><?= $escape($t('profile.address')) ?></label>
                    <textarea id="profile-address" name="address" rows="5" maxlength="1000" autocomplete="street-address" class="mt-2 sapas-form-control"><?= $escape($formData['address'] ?? '') ?></textarea>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="sapas-btn sapas-btn-success"><?= $escape($t('profile.save_contact')) ?></button>
                </div>
            </form>
        </section>
    </div>
</div>
