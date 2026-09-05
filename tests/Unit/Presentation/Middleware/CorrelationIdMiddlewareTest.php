<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Middleware;

use App\Domain\UuidGeneratorInterface;
use App\Presentation\Middleware\CorrelationIdMiddleware;
use PHPUnit\Framework\TestCase;

class CorrelationIdMiddlewareTest extends TestCase
{
    private CorrelationIdMiddleware $middleware;

    protected function setUp(): void
    {
        $uuid = new class () implements UuidGeneratorInterface {
            public function generate(): string
            {
                return 'generated-uuid';
            }
        };

        $this->middleware = new CorrelationIdMiddleware($uuid);
    }

    public function testPrefersXRequestId(): void
    {
        $id = $this->middleware->resolve([
            'HTTP_X_REQUEST_ID'    => ' req-123 ',
            'HTTP_X_AMZN_TRACE_ID' => 'Root=1-abc;Parent=x',
        ]);

        $this->assertSame('req-123', $id);
    }

    public function testFallsBackToTheRootOfTheAmazonTraceId(): void
    {
        $id = $this->middleware->resolve(['HTTP_X_AMZN_TRACE_ID' => 'Root=1-63441c4a-abcdef;Parent=y;Sampled=1']);

        $this->assertSame('1-63441c4a-abcdef', $id);
    }

    public function testUsesTheWholeTraceIdWhenThereIsNoRootSegment(): void
    {
        $this->assertSame('opaque-trace', $this->middleware->resolve(['HTTP_X_AMZN_TRACE_ID' => 'opaque-trace']));
    }

    public function testGeneratesAUuidWhenNoHeaderIsPresent(): void
    {
        $this->assertSame('generated-uuid', $this->middleware->resolve([]));
    }

    public function testBlankHeadersAreIgnored(): void
    {
        $this->assertSame('generated-uuid', $this->middleware->resolve([
            'HTTP_X_REQUEST_ID'    => '  ',
            'HTTP_X_AMZN_TRACE_ID' => '',
        ]));
    }

    public function testApplyReturnsTheResolvedId(): void
    {
        $this->assertSame('req-9', $this->middleware->apply(['HTTP_X_REQUEST_ID' => 'req-9']));
    }
}
