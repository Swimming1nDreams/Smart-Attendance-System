<?php

declare(strict_types=1);

namespace App\Services;

class EmailVerificationMailer
{
    private const PRODUCT_NAME = 'Office Attendance and Analytic System (OAAS)';
    private const DEFAULT_VERIFICATION_SUBJECT = 'Your Office Attendance and Analytic System verification code';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function sendVerificationEmail(string $recipientEmail, string $recipientName, string $verificationCode, int $expiryMinutes): bool
    {
        $subject = $this->verificationSubject();
        $body = $this->buildVerificationBody($recipientName, $verificationCode, $expiryMinutes);
        $transport = strtolower(trim((string) ($this->config['transport'] ?? 'log')));

        return match ($transport) {
            'smtp' => $this->sendViaSmtp($recipientEmail, $recipientName, $subject, $body),
            'mail' => $this->sendViaMail($recipientEmail, $subject, $body),
            'log' => $this->logEmail($recipientEmail, $subject),
            default => $this->fail('Unsupported mail transport: ' . ($transport !== '' ? $transport : '(empty)') . '.'),
        };
    }

    private function buildVerificationBody(string $recipientName, string $verificationCode, int $expiryMinutes): string
    {
        $name = trim($recipientName) !== '' ? trim($recipientName) : self::PRODUCT_NAME . ' user';

        return implode("\r\n", [
            'Hello ' . $name . ',',
            '',
            'An administrator created your ' . self::PRODUCT_NAME . ' account.',
            '',
            'Your verification code is: ' . $verificationCode,
            '',
            'This code expires in ' . $expiryMinutes . ' minutes.',
            'If you did not expect this account, contact your HR administrator.',
            '',
            self::PRODUCT_NAME,
        ]);
    }

    private function verificationSubject(): string
    {
        return (string) ($this->config['verification_subject'] ?? self::DEFAULT_VERIFICATION_SUBJECT);
    }

    private function sendViaMail(string $recipientEmail, string $subject, string $body): bool
    {
        $fromEmail = (string) ($this->config['from_email'] ?? 'no-reply@oaas.local');
        $fromName = (string) ($this->config['from_name'] ?? self::PRODUCT_NAME);
        $headers = [
            'From: ' . $this->formatAddress($fromEmail, $fromName),
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $sent = mail($recipientEmail, $subject, $body, implode("\r\n", $headers));

        if (!$sent) {
            return $this->fail('PHP mail transport rejected the verification message.');
        }

        return true;
    }

    private function sendViaSmtp(string $recipientEmail, string $recipientName, string $subject, string $body): bool
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) ($this->config['port'] ?? 587);
        $fromEmail = trim((string) ($this->config['from_email'] ?? 'no-reply@oaas.local'));
        $fromName = (string) ($this->config['from_name'] ?? self::PRODUCT_NAME);

        if ($host === '') {
            return $this->fail('SMTP host is not configured.');
        }

        if ($port < 1 || $port > 65535) {
            return $this->fail('SMTP port is invalid.');
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Verification recipient email is invalid.');
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('SMTP from address is invalid.');
        }

        $encryption = strtolower((string) ($this->config['encryption'] ?? 'tls'));
        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 15);

        if (!is_resource($socket)) {
            $reason = trim($errstr) !== '' ? trim($errstr) : 'unknown connection error';

            return $this->fail(sprintf('Unable to connect to SMTP server %s:%d: %s (%d).', $host, $port, $reason, $errno));
        }

        stream_set_timeout($socket, 15);

        try {
            $this->smtpExpect($socket, [220]);
            $this->smtpCommand($socket, 'EHLO ' . $this->smtpLocalHost(), [250]);

            if ($encryption === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Unable to start SMTP TLS.');
                }
                $this->smtpCommand($socket, 'EHLO ' . $this->smtpLocalHost(), [250]);
            }

            $username = (string) ($this->config['username'] ?? '');
            $password = (string) ($this->config['password'] ?? '');

            if ($username !== '' || $password !== '') {
                $this->smtpCommand($socket, 'AUTH LOGIN', [334]);
                $this->smtpCommand($socket, base64_encode($username), [334]);
                $this->smtpCommand($socket, base64_encode($password), [235]);
            }

            $this->smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->smtpCommand($socket, 'RCPT TO:<' . $recipientEmail . '>', [250, 251]);
            $this->smtpCommand($socket, 'DATA', [354]);

            $message = $this->buildSmtpMessage($recipientEmail, $recipientName, $fromEmail, $fromName, $subject, $body);
            fwrite($socket, $message . "\r\n.\r\n");
            $this->smtpExpect($socket, [250]);
            $this->smtpCommand($socket, 'QUIT', [221]);
            fclose($socket);

            return true;
        } catch (\Throwable $exception) {
            fclose($socket);

            return $this->fail('SMTP send failed: ' . $exception->getMessage());
        }
    }

    private function buildSmtpMessage(string $recipientEmail, string $recipientName, string $fromEmail, string $fromName, string $subject, string $body): string
    {
        $headers = [
            'From: ' . $this->formatAddress($fromEmail, $fromName),
            'To: ' . $this->formatAddress($recipientEmail, $recipientName),
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $body);
    }

    private function smtpCommand(mixed $socket, string $command, array $expectedCodes): void
    {
        fwrite($socket, $command . "\r\n");
        $this->smtpExpect($socket, $expectedCodes);
    }

    private function smtpExpect(mixed $socket, array $expectedCodes): void
    {
        $line = '';
        $response = '';

        do {
            $line = fgets($socket);
            if ($line === false) {
                throw new \RuntimeException('SMTP server closed the connection.');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($line, 0, 3);

        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException('Unexpected SMTP response: ' . trim($response));
        }
    }

    private function formatAddress(string $email, string $name): string
    {
        $email = trim($email);
        $name = trim($name);

        if ($name === '') {
            return $email;
        }

        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    private function smtpLocalHost(): string
    {
        $host = (string) ($this->config['local_host'] ?? 'localhost');

        return preg_replace('/[^A-Za-z0-9.-]/', '', $host) ?: 'localhost';
    }

    private function logEmail(string $recipientEmail, string $subject): bool
    {
        error_log(sprintf(
            "OAAS verification delivery dry run\nTo: %s\nSubject: %s\nMessage content omitted from logs.",
            $recipientEmail,
            $subject,
        ));

        return true;
    }

    private function fail(string $reason): bool
    {
        error_log('OAAS email verification delivery failed: ' . $reason);

        return false;
    }
}
