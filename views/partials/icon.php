<?php
$iconName = $iconName ?? 'circle';
$iconClass = $iconClass ?? 'sapas-icon';

$icons = [
    'analytics' => '<path d="M4 19V5" /><path d="M4 19h16" /><path d="m7 14 3-3 3 2 5-7" />',
    'arrow-right' => '<path d="M5 12h14" /><path d="m13 6 6 6-6 6" />',
    'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10 21h4" />',
    'briefcase' => '<path d="M10 6V5a2 2 0 0 1 2-2h0a2 2 0 0 1 2 2v1" /><path d="M4 7h16v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z" /><path d="M4 12h16" />',
    'building' => '<path d="M4 20h16" /><path d="M6 20V5a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v15" /><path d="M15 9h2a1 1 0 0 1 1 1v10" /><path d="M9 8h3" /><path d="M9 12h3" /><path d="M9 16h3" />',
    'calendar' => '<path d="M7 3v3" /><path d="M17 3v3" /><path d="M4 8h16" /><path d="M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z" />',
    'check' => '<path d="m5 12 4 4L19 6" />',
    'circle' => '<circle cx="12" cy="12" r="8" />',
    'clock' => '<circle cx="12" cy="12" r="8" /><path d="M12 8v5l3 2" />',
    'dashboard' => '<path d="M4 13h7V4H4Z" /><path d="M13 20h7V4h-7Z" /><path d="M4 20h7v-5H4Z" />',
    'file-text' => '<path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" /><path d="M14 3v5h5" /><path d="M9 13h6" /><path d="M9 17h6" />',
    'globe' => '<circle cx="12" cy="12" r="8" /><path d="M4 12h16" /><path d="M12 4a12 12 0 0 1 0 16" /><path d="M12 4a12 12 0 0 0 0 16" />',
    'inbox' => '<path d="M4 13 6.5 5h11L20 13" /><path d="M4 13h5l1 3h4l1-3h5v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z" />',
    'leave' => '<path d="M8 3h7l4 4v14H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" /><path d="M15 3v5h5" /><path d="m9 15 2 2 5-6" />',
    'lock' => '<rect x="5" y="10" width="14" height="10" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /><path d="M12 14v2" />',
    'log-out' => '<path d="M10 6H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h4" /><path d="M14 16l4-4-4-4" /><path d="M18 12H9" />',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" />',
    'menu' => '<path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h16" />',
    'plus' => '<path d="M12 5v14" /><path d="M5 12h14" />',
    'shield' => '<path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6Z" /><path d="m9 12 2 2 4-4" />',
    'timer' => '<path d="M10 2h4" /><path d="M12 14l3-3" /><circle cx="12" cy="14" r="7" />',
    'user' => '<circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" />',
    'users' => '<path d="M16 19a5 5 0 0 0-10 0" /><circle cx="11" cy="9" r="4" /><path d="M20 18a4 4 0 0 0-3-3.9" /><path d="M17 5.2a3 3 0 0 1 0 5.6" />',
    'warning' => '<path d="m12 3 9 16H3Z" /><path d="M12 9v4" /><path d="M12 17h.01" />',
    'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" /><circle cx="12" cy="12" r="2.5" />',
    'eye-off' => '<path d="m3 3 18 18" /><path d="M10.6 6.2A10.4 10.4 0 0 1 12 6c6.5 0 10 6 10 6a17 17 0 0 1-3 3.8" /><path d="M6.6 6.6C3.6 8.5 2 12 2 12s3.5 6 10 6a10 10 0 0 0 4.1-.8" />',
    'x' => '<path d="m6 6 12 12" /><path d="m18 6-12 12" />',
];

$iconSvg = $icons[(string) $iconName] ?? $icons['circle'];
?>
<svg
    class="<?= htmlspecialchars((string) $iconClass, ENT_QUOTES, 'UTF-8') ?>"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
>
    <?= $iconSvg ?>
</svg>
