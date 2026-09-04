#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Config\EnvLoader;
use App\Infrastructure\Database\MigrationRunner;

EnvLoader::loadFile(__DIR__ . '/../.env');

$host     = $_ENV['DB_HOST']         ?? 'db';
$port     = $_ENV['DB_PORT']         ?? '3306';
$dbname   = $_ENV['DB_DATABASE']     ?? 'oficina_mecanica';
$user     = $_ENV['DB_USERNAME']     ?? 'oficina_user';
$pass     = $_ENV['DB_PASSWORD']     ?? 'secret123';
$appEnv   = $_ENV['APP_ENV']         ?? 'local';
$pathEnv  = $_ENV['MIGRATIONS_PATH'] ?? __DIR__ . '/../migrations';

echo "Aplicando migrations de {$pathEnv} (APP_ENV={$appEnv})...\n";

try {
    // The database itself is created by the infrastructure repository, never by the runner.
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        (string)$user,
        (string)$pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $runner  = new MigrationRunner($pdo, skipDemo: $appEnv === 'production');
    $applied = $runner->run((string)$pathEnv);

    echo 'Migração concluída. Aplicadas nesta execução: ' . count($applied) . "\n";
} catch (\Throwable $e) {
    echo 'ERRO: ' . $e->getMessage() . "\n";
    exit(1);
}
