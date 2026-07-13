<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\SmtpConnectorInterface;
use App\Infrastructure\Notification\SmtpMailer;
use PHPUnit\Framework\TestCase;

class SmtpMailerTest extends TestCase
{
    /**
     * Drives the real SMTP client against an in-process socket pair: one end is
     * handed to the mailer, the other is pre-loaded with the server replies (so a
     * single-threaded test never blocks) and then read back to assert the protocol.
     */
    public function testSendsAuthenticatedSmtpConversation(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;

        foreach ([
            "220 smtp.test ESMTP ready\r\n",
            "250-smtp.test\r\n250 AUTH LOGIN\r\n",
            "334 VXNlcm5hbWU6\r\n",
            "334 UGFzc3dvcmQ6\r\n",
            "235 2.7.0 Authentication successful\r\n",
            "250 2.1.0 Sender OK\r\n",
            "250 2.1.5 Recipient OK\r\n",
            "354 End data with <CR><LF>.<CR><LF>\r\n",
            "250 2.0.0 Queued\r\n",
            "221 2.0.0 Bye\r\n",
        ] as $reply) {
            fwrite($server, $reply);
        }

        $mailer = new SmtpMailer('smtp.test', 587, 'user@oficina.test', 's3cret', 'no-reply@oficina.test', 5, $this->connectorReturning($client));
        $mailer->send('ops@oficina.test', 'Status atualizado', "OS order-1: RECEIVED -> DIAGNOSIS");

        stream_set_blocking($server, false);
        $sent = (string) stream_get_contents($server);

        $this->assertStringContainsString("EHLO ", $sent);
        $this->assertStringContainsString("AUTH LOGIN\r\n", $sent);
        $this->assertStringContainsString(base64_encode('user@oficina.test') . "\r\n", $sent);
        $this->assertStringContainsString(base64_encode('s3cret') . "\r\n", $sent);
        $this->assertStringContainsString("MAIL FROM:<no-reply@oficina.test>\r\n", $sent);
        $this->assertStringContainsString("RCPT TO:<ops@oficina.test>\r\n", $sent);
        $this->assertStringContainsString("DATA\r\n", $sent);
        $this->assertStringContainsString("Subject: Status atualizado\r\n", $sent);
        $this->assertStringContainsString("OS order-1: RECEIVED -> DIAGNOSIS", $sent);
        $this->assertStringContainsString("\r\n.\r\n", $sent);
        $this->assertStringContainsString("QUIT\r\n", $sent);
    }

    public function testSkipsAuthWhenNoCredentials(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;

        foreach ([
            "220 smtp.test ESMTP ready\r\n",
            "250 smtp.test\r\n",
            "250 2.1.0 Sender OK\r\n",
            "250 2.1.5 Recipient OK\r\n",
            "354 Go ahead\r\n",
            "250 2.0.0 Queued\r\n",
            "221 2.0.0 Bye\r\n",
        ] as $reply) {
            fwrite($server, $reply);
        }

        $mailer = new SmtpMailer('smtp.test', 25, '', '', 'no-reply@oficina.test', 5, $this->connectorReturning($client));
        $mailer->send('ops@oficina.test', 'Hi', 'body');

        stream_set_blocking($server, false);
        $sent = (string) stream_get_contents($server);

        $this->assertStringNotContainsString('AUTH LOGIN', $sent);
        $this->assertStringContainsString("MAIL FROM:<no-reply@oficina.test>\r\n", $sent);
    }

    public function testThrowsOnUnexpectedReplyCode(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        fwrite($server, "554 Service unavailable\r\n");

        $mailer = new SmtpMailer('smtp.test', 25, '', '', 'no-reply@oficina.test', 5, $this->connectorReturning($client));

        $this->expectException(\RuntimeException::class);
        $mailer->send('ops@oficina.test', 'Hi', 'body');
    }

    private function connectorReturning(mixed $stream): SmtpConnectorInterface
    {
        return new class ($stream) implements SmtpConnectorInterface {
            public function __construct(private mixed $stream)
            {
            }

            public function connect(string $host, int $port, int $timeout): mixed
            {
                return $this->stream;
            }
        };
    }
}
