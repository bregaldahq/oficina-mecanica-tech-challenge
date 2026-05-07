<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use PDOException;

class PdoConnection
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host   = $_ENV['DB_HOST']     ?? 'db';
            $port   = $_ENV['DB_PORT']     ?? '3306';
            $dbname = $_ENV['DB_DATABASE'] ?? 'oficina_mecanica';
            $user   = $_ENV['DB_USERNAME'] ?? 'oficina_user';
            $pass   = $_ENV['DB_PASSWORD'] ?? 'secret123';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    // ATTR_EMULATE_PREPARES = false: forces native prepared statements in MySQL,
                    // preventing SQL injection by ensuring values are never concatenated into queries.
                    PDO::ATTR_EMULATE_PREPARES => false,
                    // ERRMODE_EXCEPTION: PDO throws PDOException on errors instead of returning false,
                    // ensuring no silent failure goes unhandled.
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (PDOException $e) {
                throw new \RuntimeException("Falha na conexão com o banco de dados: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    public static function setInstance(PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
