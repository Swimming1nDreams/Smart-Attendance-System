<?php

declare(strict_types=1);

return [
    // Keep "log" for local dry runs. Change to "smtp" only after replacing
    // every placeholder below in the ignored config/mail.local.php copy.
    'transport' => 'log',
    // 'transport' => 'smtp',
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => 'your-smtp-username',
    'password' => 'change-me',
    'encryption' => 'tls',
    'from_email' => 'no-reply@sapas.local',
    'from_name' => 'Smart Attendance',
    'verification_subject' => 'Verify your Smart Attendance account email',

    // Hostname used by SMTP EHLO. Do not include a port.
    'local_host' => 'localhost',
];
