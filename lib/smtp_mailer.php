<?php
declare(strict_types=1);

final class SmtpMailer
{
    private string $host;
    private int $port;
    private string $secure;
    private int $timeoutSeconds;
    private $socket = null;

    public function __construct(string $host, int $port, string $secure = 'tls', int $timeoutSeconds = 12)
    {
        $this->host = $host;
        $this->port = $port;
        $this->secure = $secure; // 'tls' (STARTTLS), 'ssl' (implicit), 'none'
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function send(
        string $username,
        string $password,
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $htmlBody,
        string $textBody = ''
    ): void {
        $this->connect();
        try {
            $this->expectCode([220]);

            $this->command('EHLO localhost');
            $ehlo = $this->readMultiline();

            $supportsStartTls = stripos($ehlo, 'STARTTLS') !== false;
            if ($this->secure === 'tls') {
                if (!$supportsStartTls) {
                    throw new RuntimeException('SMTP server does not support STARTTLS.');
                }
                $this->command('STARTTLS');
                $this->expectCode([220]);

                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Failed to negotiate TLS with SMTP server.');
                }

                $this->command('EHLO localhost');
                $this->readMultiline();
            }

            if ($username !== '') {
                $this->command('AUTH LOGIN');
                $this->expectCode([334]);
                $this->command(base64_encode($username));
                $this->expectCode([334]);
                $this->command(base64_encode($password));
                $this->expectCode([235]);
            }

            $this->command('MAIL FROM:<' . $this->sanitizeEmail($fromEmail) . '>');
            $this->expectCode([250]);

            $this->command('RCPT TO:<' . $this->sanitizeEmail($toEmail) . '>');
            $this->expectCode([250, 251]);

            $this->command('DATA');
            $this->expectCode([354]);

            $mime = $this->buildMimeMessage(
                $fromEmail,
                $fromName,
                $toEmail,
                $subject,
                $htmlBody,
                $textBody
            );

            $this->writeData($mime);
            $this->expectCode([250]);

            $this->command('QUIT');
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $transport = $this->secure === 'ssl' ? 'ssl://' : 'tcp://';
        $address = $transport . $this->host . ':' . $this->port;

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, $this->timeoutSeconds);
        $this->socket = $socket;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private function command(string $line): void
    {
        $this->write($line . "\r\n");
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP socket is not connected.');
        }

        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Failed to write to SMTP socket.');
            }
            $written += $n;
        }
    }

    private function readLine(): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP socket is not connected.');
        }

        $line = fgets($this->socket);
        if ($line === false) {
            throw new RuntimeException('Failed to read from SMTP socket.');
        }

        return (string) $line;
    }

    private function readMultiline(): string
    {
        $all = '';
        while (true) {
            $line = $this->readLine();
            $all .= $line;
            if (preg_match('/^\\d{3} /', $line) === 1) {
                break;
            }
        }
        return $all;
    }

    private function expectCode(array $allowedCodes): void
    {
        $line = $this->readLine();
        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $allowedCodes, true)) {
            $message = trim($line);
            throw new RuntimeException('SMTP error: ' . $message);
        }
    }

    private function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        $email = str_replace(["\r", "\n", '<', '>'], '', $email);
        return $email;
    }

    private function headerEncode(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], '', $value));
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\\x00-\\x1F\\x7F-\\xFF]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }

    private function buildMimeMessage(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $htmlBody,
        string $textBody
    ): string {
        $fromEmail = $this->sanitizeEmail($fromEmail);
        $toEmail = $this->sanitizeEmail($toEmail);

        $safeFromName = $this->headerEncode($fromName);
        $safeSubject = $this->headerEncode($subject);

        $fromHeader = $safeFromName !== ''
            ? $safeFromName . ' <' . $fromEmail . '>'
            : $fromEmail;

        $boundary = 'b1_' . bin2hex(random_bytes(12));
        $altBoundary = 'b2_' . bin2hex(random_bytes(12));

        $headers = [];
        $headers[] = 'From: ' . $fromHeader;
        $headers[] = 'To: <' . $toEmail . '>';
        $headers[] = 'Subject: ' . $safeSubject;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';

        $body = '';
        $body .= '--' . $altBoundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= ($textBody !== '' ? $textBody : strip_tags($htmlBody)) . "\r\n\r\n";

        $body .= '--' . $altBoundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";

        $body .= '--' . $altBoundary . "--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function writeData(string $data): void
    {
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $lines = explode("\n", $data);

        foreach ($lines as $line) {
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
            $this->write($line . "\r\n");
        }

        $this->write(".\r\n");
    }
}

