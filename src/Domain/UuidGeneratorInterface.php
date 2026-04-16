<?php

declare(strict_types=1);

namespace App\Domain;

interface UuidGeneratorInterface
{
    public function generate(): string;
}
