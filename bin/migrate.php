#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

echo "Iniciando migração do banco de dados...\n";

try {
    $host   = $_ENV['DB_HOST']     ?? 'db';
    $port   = $_ENV['DB_PORT']     ?? '3306';
    $user   = $_ENV['DB_USERNAME'] ?? 'oficina_user';
    $pass   = $_ENV['DB_PASSWORD'] ?? 'secret123';

    // Connect without dbname to allow CREATE DATABASE
    $pdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $schemaFile = __DIR__ . '/../src/Infrastructure/Database/schema.sql';

    if (!file_exists($schemaFile)) {
        echo "ERRO: Arquivo schema.sql não encontrado em {$schemaFile}\n";
        exit(1);
    }

    $sql = file_get_contents($schemaFile);

    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => !empty($s)
    );

    foreach ($statements as $statement) {
        $pdo->exec($statement);
        echo ".";
    }

    echo "\nMigração concluída com sucesso!\n";
} catch (\Throwable $e) {
    echo "\nERRO: " . $e->getMessage() . "\n";
    exit(1);
}
