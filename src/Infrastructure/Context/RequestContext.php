<?php

declare(strict_types=1);

namespace App\Infrastructure\Context;

/**
 * Per-request ambient data (correlation id and authenticated subject) shared between the
 * HTTP layer and the event subscribers, which have no other way to reach it.
 * One instance per request, built in the composition root — no globals.
 */
final class RequestContext
{
    private ?string $correlationId = null;
    private ?string $actor         = null;
    private ?string $role          = null;

    public function setCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /** @param array<string, mixed> $claims validated JWT claims */
    public function setClaims(array $claims): void
    {
        $sub  = $claims['sub']  ?? null;
        $role = $claims['role'] ?? null;

        $this->actor = is_string($sub) ? $sub : null;
        $this->role  = is_string($role) ? $role : null;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }
}
