<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Event;

use App\Domain\Event\ServiceOrderCreatedEvent;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Repository\ServiceOrderStatusHistoryRepositoryInterface;
use App\Infrastructure\Context\RequestContext;
use App\Infrastructure\Event\Subscriber\NewRelicSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Custom events of CONTRATOS.md §7. */
class NewRelicSubscriberTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $recorded = [];

    private MockObject&ServiceOrderStatusHistoryRepositoryInterface $history;
    private RequestContext $context;

    protected function setUp(): void
    {
        $this->recorded = [];
        $this->history  = $this->createMock(ServiceOrderStatusHistoryRepositoryInterface::class);
        $this->context  = new RequestContext();
        $this->context->setCorrelationId('corr-1');
    }

    private function subscriber(): NewRelicSubscriber
    {
        $recorder = function (string $name, array $attributes): void {
            $this->recorded[] = [$name, $attributes];
        };

        return new NewRelicSubscriber($this->context, $this->history, 'prod', $recorder);
    }

    public function testServiceOrderCreatedCarriesTheContractAttributes(): void
    {
        $this->subscriber()->onCreated(new ServiceOrderCreatedEvent('order-1', 'cust-1', 'veh-1'));

        $this->assertCount(1, $this->recorded);
        [$name, $attributes] = $this->recorded[0];

        $this->assertSame('ServiceOrderCreated', $name);
        $this->assertSame(
            ['orderId', 'customerId', 'vehicleId', 'correlationId', 'env'],
            array_keys($attributes)
        );
        $this->assertSame('order-1', $attributes['orderId']);
        $this->assertSame('cust-1', $attributes['customerId']);
        $this->assertSame('veh-1', $attributes['vehicleId']);
        $this->assertSame('corr-1', $attributes['correlationId']);
        $this->assertSame('prod', $attributes['env']);
    }

    public function testStatusChangedCarriesTheContractAttributes(): void
    {
        $this->history->method('findLastChangedAtBefore')->willReturn(null);

        $this->subscriber()->onStatusChanged(
            new ServiceOrderStatusChangedEvent('order-1', 'RECEIVED', 'DIAGNOSIS')
        );

        [$name, $attributes] = $this->recorded[0];

        $this->assertSame('ServiceOrderStatusChanged', $name);
        $this->assertSame(
            ['orderId', 'fromStatus', 'toStatus', 'durationSeconds', 'totalAmount', 'correlationId', 'env'],
            array_keys($attributes)
        );
        $this->assertSame('RECEIVED', $attributes['fromStatus']);
        $this->assertSame('DIAGNOSIS', $attributes['toStatus']);
        $this->assertNull($attributes['durationSeconds'], 'sem transição anterior, duração é desconhecida');
    }

    public function testDurationIsMeasuredFromThePreviousTransition(): void
    {
        $event = new ServiceOrderStatusChangedEvent('order-1', 'DIAGNOSIS', 'AWAITING_APPROVAL');

        $this->history->expects($this->once())
            ->method('findLastChangedAtBefore')
            ->with('order-1', $event->occurredAt())
            ->willReturn($event->occurredAt()->modify('-90 seconds'));

        $this->subscriber()->onStatusChanged($event);

        $this->assertSame(90, $this->recorded[0][1]['durationSeconds']);
    }

    public function testDurationIsNullWhenTheHistoryLookupFails(): void
    {
        $this->history->method('findLastChangedAtBefore')
            ->willThrowException(new \RuntimeException('banco fora'));

        $this->subscriber()->onStatusChanged(
            new ServiceOrderStatusChangedEvent('order-1', 'RECEIVED', 'DIAGNOSIS')
        );

        // A failure to compute the metric must not break the business operation.
        $this->assertNull($this->recorded[0][1]['durationSeconds']);
    }

    public function testDefaultRecorderIsASilentNoOpWithoutTheExtension(): void
    {
        if (function_exists('newrelic_record_custom_event')) {
            $this->markTestSkipped('extensão New Relic presente neste ambiente');
        }

        $recorder = NewRelicSubscriber::defaultRecorder();

        $recorder('ServiceOrderCreated', ['orderId' => 'order-1']);

        $this->assertFalse(function_exists('newrelic_record_custom_event'));
    }

    public function testCorrelationIdIsNullWhenTheContextHasNone(): void
    {
        $this->context = new RequestContext();

        $this->subscriber()->onCreated(new ServiceOrderCreatedEvent('order-1', 'cust-1', 'veh-1'));

        $this->assertNull($this->recorded[0][1]['correlationId']);
    }
}
