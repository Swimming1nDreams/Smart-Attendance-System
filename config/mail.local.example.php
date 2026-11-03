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
    'from_email' => 'no-reply@oaas.local',
    'from_name' => 'Office Attendance and Analytic System (OAAS)',
    'verification_subject' => 'Your Office Attendance and Analytic System verification code',

    // Hostname used by SMTP EHLO. Do not include a port.
    'local_host' => 'localhost',
];
