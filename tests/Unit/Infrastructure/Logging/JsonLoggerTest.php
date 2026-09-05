<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;

/** Asserts the exact log line shape of CONTRATOS.md §7. */
class JsonLoggerTest extends TestCase
{
    /** @var resource */
    private $stream;

    private JsonLogger $logger;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        $this->stream = $stream;

        // Pinned clock: 2026-08-26T22:31:04.512Z
        $this->logger = new JsonLogger('oficina-api', 'prod', $this->stream, static fn (): float => 1787783464.512);
    }

    /** @return array<string, mixed> */
    private function lastLine(): array
    {
        rewind($this->stream);
        $content = (string)stream_get_contents($this->stream);
        $lines   = array_values(array_filter(explode("\n", $content)));
        $decoded = json_decode((string)end($lines), true);

        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testWritesOneJsonLinePerEvent(): void
    {
        $this->logger->info('request.completed');
        $this->logger->info('request.completed');

        rewind($this->stream);
        $content = (string)stream_get_contents($this->stream);

        $this->assertSame(2, substr_count($content, "\n"));
    }

    public function testContractFieldsAndOrder(): void
    {
        $this->logger->info('request.completed', [
            'correlation_id' => 'abc',
            'method'         => 'POST',
            'path'           => '/api/service-orders',
            'status'         => 201,
            'duration_ms'    => 42.7,
            'customer_id'    => 'cust-1',
            'role'           => 'admin',
        ]);

        $line = $this->lastLine();

        $this->assertSame([
            'timestamp', 'level', 'message', 'service', 'env',
            'correlation_id', 'method', 'path', 'status', 'duration_ms', 'customer_id', 'role',
        ], array_keys($line));

        $this->assertSame('2026-08-26T22:31:04.512Z', $line['timestamp']);
        $this->assertSame('info', $line['level']);
        $this->assertSame('request.completed', $line['message']);
        $this->assertSame('oficina-api', $line['service']);
        $this->assertSame('prod', $line['env']);
        $this->assertSame(201, $line['status']);
        $this->assertSame(42.7, $line['duration_ms']);
    }

    public function testSupportsEveryContractLevel(): void
    {
        foreach (JsonLogger::LEVELS as $level) {
            $this->logger->log($level, 'msg');
            $this->assertSame($level, $this->lastLine()['level']);
        }
    }

    public function testUnknownLevelFallsBackToInfo(): void
    {
        $this->logger->log('verbose', 'msg');

        $this->assertSame('info', $this->lastLine()['level']);
    }

    public function testErrorCarriesExceptionDetails(): void
    {
        $this->logger->error('request.failed', [
            'exception_class'   => \RuntimeException::class,
            'exception_message' => 'boom',
            'file'              => '/app/x.php',
            'line'              => 10,
        ]);

        $line = $this->lastLine();

        $this->assertSame('error', $line['level']);
        $this->assertSame('RuntimeException', $line['exception_class']);
        $this->assertSame('boom', $line['exception_message']);
    }

    public function testSlashesAndAccentsAreNotEscaped(): void
    {
        $this->logger->info('request.completed', ['path' => '/api/serviço']);

        rewind($this->stream);
        $this->assertStringContainsString('/api/serviço', (string)stream_get_contents($this->stream));
    }
}
