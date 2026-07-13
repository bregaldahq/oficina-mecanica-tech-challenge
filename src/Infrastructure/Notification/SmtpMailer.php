<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Domain\Notification\MailerInterface;

/**
 * Minimal SMTP client implemented over a raw socket (no external dependency),
 * mirroring the project's hand-rolled-crypto approach (see ADR-002). Supports the
 * common EHLO + AUTH LOGIN + MAIL/RCPT/DATA flow used by transactional providers.
 */
class SmtpMailer implements MailerInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly int $timeout = 10,
        private readonly ?SmtpConnectorInterface $connector = null,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $connector = $this->connector ?? new StreamSocketConnector();
        $stream    = $connector->connect($this->host, $this->port, $this->timeout);

        if (!is_resource($stream)) {
            throw new \RuntimeException('SMTP connector did not return a valid stream.');
        }

        try {
            $this->expect($stream, 220);
            $this->command($stream, 'EHLO ' . $this->clientName(), 250);

            if ($this->username !== '') {
                $this->command($stream, 'AUTH LOGIN', 334);
                $this->command($stream, base64_encode($this->username), 334);
                $this->command($stream, base64_encode($this->password), 235);
            }

            $this->command($stream, "MAIL FROM:<{$this->from}>", 250);
            $this->command($stream, "RCPT TO:<{$to}>", 250);
            $this->command($stream, 'DATA', 354);

            $this->write($stream, $this->buildMessage($to, $subject, $body) . "\r\n.");
            $this->expect($stream, 250);

            $this->command($stream, 'QUIT', 221);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function buildMessage(string $to, string $subject, string $body): string
    {
        $headers = [
            "From: {$this->from}",
            "To: {$to}",
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date('r'),
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->normalizeBody($body);
    }

    /**
     * Normalizes line endings to CRLF and applies SMTP dot-stuffing so a line that
     * begins with '.' is not interpreted as the end-of-data marker.
     */
    private function normalizeBody(string $body): string
    {
        $body  = str_replace(["\r\n", "\r", "\n"], "\n", $body);
        $lines = explode("\n", $body);
        $lines = array_map(static fn (string $line) => str_starts_with($line, '.') ? '.' . $line : $line, $lines);

        return implode("\r\n", $lines);
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }

    private function clientName(): string
    {
        return gethostname() ?: 'localhost';
    }

    /**
     * @param resource $stream
     */
    private function command($stream, string $line, int $expectedCode): void
    {
        $this->write($stream, $line);
        $this->expect($stream, $expectedCode);
    }

    /**
     * @param resource $stream
     */
    private function write($stream, string $line): void
    {
        if (fwrite($stream, $line . "\r\n") === false) {
            throw new \RuntimeException('Failed to write to the SMTP stream.');
        }
    }

    /**
     * @param resource $stream
     */
    private function expect($stream, int $expectedCode): void
    {
        $code = $this->readReplyCode($stream);

        if ($code !== $expectedCode) {
            throw new \RuntimeException("Unexpected SMTP reply: expected {$expectedCode}, got {$code}.");
        }
    }

    /**
     * Reads a (possibly multi-line) SMTP reply and returns its status code.
     *
     * @param resource $stream
     */
    private function readReplyCode($stream): int
    {
        do {
            $line = fgets($stream, 515);

            if ($line === false) {
                throw new \RuntimeException('SMTP connection closed unexpectedly.');
            }

            // A 4th character of '-' marks a continuation line; ' ' marks the last line.
            $isContinuation = isset($line[3]) && $line[3] === '-';
        } while ($isContinuation);

        return (int) substr($line, 0, 3);
    }
}
