<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

class StreamSocketConnector implements SmtpConnectorInterface
{
    public function connect(string $host, int $port, int $timeout): mixed
    {
        $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);

        if ($stream === false) {
            throw new \RuntimeException("SMTP connection to {$host}:{$port} failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($stream, $timeout);

        return $stream;
    }
}
