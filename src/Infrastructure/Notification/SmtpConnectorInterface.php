<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

interface SmtpConnectorInterface
{
    /**
     * Opens a transport connection to the SMTP server.
     *
     * @return resource a readable/writable stream
     */
    public function connect(string $host, int $port, int $timeout): mixed;
}
