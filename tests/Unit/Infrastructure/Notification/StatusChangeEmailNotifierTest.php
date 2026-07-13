<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Notification\MailerInterface;
use App\Infrastructure\Notification\StatusChangeEmailNotifier;
use PHPUnit\Framework\TestCase;

class StatusChangeEmailNotifierTest extends TestCase
{
    public function testSendsEmailDescribingTheStatusChange(): void
    {
        $mailer = new class () implements MailerInterface {
            /** @var array<int, array{to: string, subject: string, body: string}> */
            public array $sent = [];

            public function send(string $to, string $subject, string $body): void
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
            }
        };

        $notifier = new StatusChangeEmailNotifier($mailer, 'ops@oficina.test');
        $notifier->handle(new ServiceOrderStatusChangedEvent('order-1', 'RECEIVED', 'DIAGNOSIS'));

        $this->assertCount(1, $mailer->sent);
        $this->assertSame('ops@oficina.test', $mailer->sent[0]['to']);
        $this->assertStringContainsString('order-1', $mailer->sent[0]['subject']);
        $this->assertStringContainsString('DIAGNOSIS', $mailer->sent[0]['subject']);
        $this->assertStringContainsString('RECEIVED', $mailer->sent[0]['body']);
        $this->assertStringContainsString('DIAGNOSIS', $mailer->sent[0]['body']);
    }

    public function testDoesNotSendWhenRecipientIsEmpty(): void
    {
        $mailer = new class () implements MailerInterface {
            public int $calls = 0;

            public function send(string $to, string $subject, string $body): void
            {
                $this->calls++;
            }
        };

        $notifier = new StatusChangeEmailNotifier($mailer, '');
        $notifier->handle(new ServiceOrderStatusChangedEvent('order-1', 'RECEIVED', 'DIAGNOSIS'));

        $this->assertSame(0, $mailer->calls);
    }

    public function testSwallowsMailerFailuresSoTheFlowIsNeverBroken(): void
    {
        $mailer = new class () implements MailerInterface {
            public function send(string $to, string $subject, string $body): void
            {
                throw new \RuntimeException('smtp unavailable');
            }
        };

        $notifier = new StatusChangeEmailNotifier($mailer, 'ops@oficina.test');
        $notifier->handle(new ServiceOrderStatusChangedEvent('order-1', 'RECEIVED', 'DIAGNOSIS'));

        $this->expectNotToPerformAssertions();
    }
}
