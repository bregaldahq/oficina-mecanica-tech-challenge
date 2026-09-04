<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Event;

use App\Domain\Event\ServiceOrderCreatedEvent;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Repository\ServiceOrderStatusHistoryRepositoryInterface;
use App\Domain\UuidGeneratorInterface;
use App\Infrastructure\Context\RequestContext;
use App\Infrastructure\Event\InMemoryEventDispatcher;
use App\Infrastructure\Event\Subscriber\StatusHistorySubscriber;
use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;

/** In-memory double of the status history repository. */
final class FakeStatusHistoryRepository implements ServiceOrderStatusHistoryRepositoryInterface
{
    public bool $shouldFail = false;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function append(
        string $id,
        string $serviceOrderId,
        ?string $fromStatus,
        string $toStatus,
        \DateTimeImmutable $changedAt,
        ?string $changedBy,
    ): void {
        if ($this->shouldFail) {
            throw new \RuntimeException('banco fora');
        }

        $this->rows[] = [
            'id'               => $id,
            'service_order_id' => $serviceOrderId,
            'from_status'      => $fromStatus,
            'to_status'        => $toStatus,
            'changed_at'       => $changedAt,
            'changed_by'       => $changedBy,
        ];
    }

    public function findLastChangedAtBefore(string $serviceOrderId, \DateTimeImmutable $before): ?\DateTimeImmutable
    {
        return null;
    }
}

/** Writes to service_order_status_history (CONTRATOS.md §6, ajuste 3). */
class StatusHistorySubscriberTest extends TestCase
{
    private FakeStatusHistoryRepository $repository;
    private RequestContext $context;
    private UuidGeneratorInterface $uuid;

    protected function setUp(): void
    {
        $this->repository = new FakeStatusHistoryRepository();

        $this->uuid = new class implements UuidGeneratorInterface {
            public function generate(): string
            {
                return 'hist-uuid';
            }
        };

        $this->context = new RequestContext();
        $this->context->setClaims(['sub' => 'admin', 'role' => 'admin']);
    }

    private function subscriber(?JsonLogger $logger = null): StatusHistorySubscriber
    {
        return new StatusHistorySubscriber($this->repository, $this->uuid, $this->context, $logger);
    }

    public function testCreationIsRecordedAsTheFirstTransition(): void
    {
        $event = new ServiceOrderCreatedEvent('order-1', 'cust-1', 'veh-1');

        $this->subscriber()->onCreated($event);

        $this->assertCount(1, $this->repository->rows);
        $this->assertSame('hist-uuid', $this->repository->rows[0]['id']);
        $this->assertSame('order-1', $this->repository->rows[0]['service_order_id']);
        $this->assertNull($this->repository->rows[0]['from_status']);
        $this->assertSame('RECEIVED', $this->repository->rows[0]['to_status']);
        $this->assertSame($event->occurredAt(), $this->repository->rows[0]['changed_at']);
    }

    public function testStatusChangeIsRecordedWithBothStatuses(): void
    {
        $this->subscriber()->onStatusChanged(
            new ServiceOrderStatusChangedEvent('order-1', 'DIAGNOSIS', 'AWAITING_APPROVAL')
        );

        $this->assertSame('DIAGNOSIS', $this->repository->rows[0]['from_status']);
        $this->assertSame('AWAITING_APPROVAL', $this->repository->rows[0]['to_status']);
    }

    public function testChangedByComesFromTheAuthenticatedSubject(): void
    {
        $this->subscriber()->onStatusChanged(
            new ServiceOrderStatusChangedEvent('order-1', 'RECEIVED', 'DIAGNOSIS')
        );

        $this->assertSame('admin', $this->repository->rows[0]['changed_by']);
    }

    public function testChangedByIsNullOnUnauthenticatedFlows(): void
    {
        $this->context = new RequestContext();

        $this->subscriber()->onStatusChanged(
            new ServiceOrderStatusChangedEvent('order-1', 'AWAITING_APPROVAL', 'EXECUTING')
        );

        $this->assertNull($this->repository->rows[0]['changed_by']);
    }

    public function testWriteFailureIsLoggedAndSwallowed(): void
    {
        $this->repository->shouldFail = true;

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        $logger = new JsonLogger('oficina-api', 'test', $stream);

        $this->subscriber($logger)->onStatusChanged(
            new ServiceOrderStatusChangedEvent('order-1', 'RECEIVED', 'DIAGNOSIS')
        );

        rewind($stream);
        $line = json_decode(trim((string)stream_get_contents($stream)), true);

        $this->assertIsArray($line);
        $this->assertSame('error', $line['level']);
        $this->assertSame('status_history.write_failed', $line['message']);
        $this->assertSame('RuntimeException', $line['exception_class']);
        $this->assertSame([], $this->repository->rows);
    }

    public function testWorksWiredIntoTheInMemoryDispatcher(): void
    {
        $subscriber = $this->subscriber();
        $dispatcher = new InMemoryEventDispatcher();

        $dispatcher->subscribe(
            ServiceOrderCreatedEvent::class,
            static fn (ServiceOrderCreatedEvent $e) => $subscriber->onCreated($e),
        );
        $dispatcher->subscribe(
            ServiceOrderStatusChangedEvent::class,
            static fn (ServiceOrderStatusChangedEvent $e) => $subscriber->onStatusChanged($e),
        );

        $dispatcher->dispatchAll([
            new ServiceOrderCreatedEvent('order-1', 'cust-1', 'veh-1'),
            new ServiceOrderStatusChangedEvent('order-1', 'RECEIVED', 'DIAGNOSIS'),
        ]);

        $this->assertCount(2, $this->repository->rows);
        $this->assertSame('RECEIVED', $this->repository->rows[0]['to_status']);
        $this->assertSame('DIAGNOSIS', $this->repository->rows[1]['to_status']);
    }
}
