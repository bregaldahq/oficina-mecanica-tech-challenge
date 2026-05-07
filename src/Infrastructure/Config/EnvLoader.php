<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

class EnvLoader
{
    /** @param string[] $required */
    public static function require(array $required): void
    {
        $missing = array_filter($required, fn (string $key) => empty($_ENV[$key]));

        if ($missing !== []) {
            throw new \RuntimeException(
                'Variáveis de ambiente obrigatórias não configuradas: ' . implode(', ', $missing)
            );
        }
    }

    public static function loadFile(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value]    = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}
