<?php
$avatarName = trim((string) ($avatarName ?? ''));
$avatarPhotoUrl = trim((string) ($avatarPhotoUrl ?? ''));
$avatarClass = trim((string) ($avatarClass ?? 'h-10 w-10'));
$avatarInitialsClass = trim((string) ($avatarInitialsClass ?? 'text-sm'));
$avatarAlt = (string) ($avatarAlt ?? $avatarName);
$avatarFallbackLabel = (string) ($avatarFallbackLabel ?? $avatarAlt);

$avatarNameParts = preg_split('/\s+/u', $avatarName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$avatarInitials = '';
foreach (array_slice($avatarNameParts, 0, 2) as $avatarNamePart) {
    $avatarInitials .= mb_strtoupper(mb_substr($avatarNamePart, 0, 1));
}
$avatarInitials = $avatarInitials !== '' ? $avatarInitials : 'E';
?>
<span class="relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-sky-50 text-sky-700 <?= htmlspecialchars($avatarClass, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($avatarPhotoUrl !== ''): ?>
        <img src="<?= htmlspecialchars($avatarPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($avatarAlt, ENT_QUOTES, 'UTF-8') ?>" class="h-full w-full object-cover">
    <?php else: ?>
        <span class="font-bold leading-none <?= htmlspecialchars($avatarInitialsClass, ENT_QUOTES, 'UTF-8') ?>" role="img" aria-label="<?= htmlspecialchars($avatarFallbackLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($avatarInitials, ENT_QUOTES, 'UTF-8') ?></span>
    <?php endif; ?>
</span>
