<?php

declare(strict_types=1);

namespace App\Presentation\Middleware;

use App\Domain\UuidGeneratorInterface;

/**
 * Resolves the correlation id of the request (CONTRATOS.md §7):
 * X-Request-Id, else the Root segment of X-Amzn-Trace-Id, else a fresh uuid v4.
 *
 * It is always echoed back in the X-Request-Id response header so a caller can quote it
 * in a support ticket and the whole request can be found in New Relic Logs.
 */
final class CorrelationIdMiddleware
{
    public const HEADER = 'X-Request-Id';

    public function __construct(
        private readonly UuidGeneratorInterface $uuidGenerator,
    ) {
    }

    /** @param array<string, mixed> $server the $_SERVER superglobal */
    public function resolve(array $server): string
    {
        $requestId = $server['HTTP_X_REQUEST_ID'] ?? null;

        if (is_string($requestId) && trim($requestId) !== '') {
            return trim($requestId);
        }

        $traceId = $server['HTTP_X_AMZN_TRACE_ID'] ?? null;

        if (is_string($traceId) && trim($traceId) !== '') {
            return $this->extractRoot(trim($traceId));
        }

        return $this->uuidGenerator->generate();
    }

    /**
     * Resolves the id and echoes it back in the response header.
     *
     * @param array<string, mixed> $server
     */
    public function apply(array $server): string
    {
        $correlationId = $this->resolve($server);

        if (!headers_sent()) {
            header(self::HEADER . ': ' . $correlationId);
        }

        return $correlationId;
    }

    /** X-Amzn-Trace-Id looks like "Root=1-63441c4a-abcdef012345;Parent=...;Sampled=1". */
    private function extractRoot(string $traceId): string
    {
        foreach (explode(';', $traceId) as $segment) {
            if (str_starts_with($segment, 'Root=')) {
                return substr($segment, 5);
            }
        }

        return $traceId;
    }
}
