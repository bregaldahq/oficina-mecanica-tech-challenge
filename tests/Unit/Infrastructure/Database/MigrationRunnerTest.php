<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database;

use App\Infrastructure\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/** Runner behaviour required by CONTRATOS.md §6 (SQLite stands in for MySQL here). */
class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $dir = sys_get_temp_dir() . '/migrations-' . uniqid();
        mkdir($dir);
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }

    private function runner(bool $skipDemo = false): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $skipDemo, static function (string $line): void {
        });
    }

    public function testAppliesFilesInLexicographicOrder(): void
    {
        $this->write('002_second.sql', 'INSERT INTO t (v) VALUES (2);');
        $this->write('001_first.sql', "CREATE TABLE t (v INTEGER);\nINSERT INTO t (v) VALUES (1);");

        $applied = $this->runner()->run($this->dir);

        $this->assertSame(['001_first.sql', '002_second.sql'], $applied);
        $this->assertSame([1, 2], array_map('intval', $this->query('SELECT v FROM t ORDER BY v')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testCreatesTheControlTableAndRecordsVersions(): void
    {
        $this->write('001_first.sql', 'CREATE TABLE t (v INTEGER);');

        $runner = $this->runner();
        $runner->run($this->dir);

        $this->assertSame(['001_first.sql'], $runner->appliedVersions());
    }

    public function testIsIdempotentOnASecondRun(): void
    {
        $this->write('001_first.sql', "CREATE TABLE t (v INTEGER);\nINSERT INTO t (v) VALUES (1);");

        $runner = $this->runner();
        $runner->run($this->dir);
        $second = $runner->run($this->dir);

        $this->assertSame([], $second);
        $this->assertSame('1', (string)$this->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testAppliesOnlyThePendingFileOnALaterRun(): void
    {
        $this->write('001_first.sql', 'CREATE TABLE t (v INTEGER);');
        $runner = $this->runner();
        $runner->run($this->dir);

        $this->write('002_second.sql', 'CREATE TABLE u (v INTEGER);');

        $this->assertSame(['002_second.sql'], $runner->run($this->dir));
    }

    public function testSkipsDemoFilesInProduction(): void
    {
        $this->write('001_first.sql', 'CREATE TABLE t (v INTEGER);');
        $this->write('003_seed_demo.sql', 'INSERT INTO t (v) VALUES (99);');

        $applied = $this->runner(skipDemo: true)->run($this->dir);

        $this->assertSame(['001_first.sql'], $applied);
        $this->assertSame('0', (string)$this->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testAppliesDemoFilesOutsideProduction(): void
    {
        $this->write('001_first.sql', 'CREATE TABLE t (v INTEGER);');
        $this->write('003_seed_demo.sql', 'INSERT INTO t (v) VALUES (99);');

        $this->assertSame(['001_first.sql', '003_seed_demo.sql'], $this->runner()->run($this->dir));
    }

    public function testIgnoresCommentOnlyStatements(): void
    {
        $this->write('001_first.sql', "-- migration 001\n-- creates t\nCREATE TABLE t (v INTEGER);\n\n-- done\n");

        $this->assertSame(['001_first.sql'], $this->runner()->run($this->dir));
    }

    public function testNonSqlFilesAreIgnored(): void
    {
        $this->write('001_first.sql', 'CREATE TABLE t (v INTEGER);');
        $this->write('README.md', 'nada aqui');

        $this->assertSame(['001_first.sql'], $this->runner()->run($this->dir));
    }

    public function testFailsWhenTheDirectoryDoesNotExist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runner()->run($this->dir . '/missing');
    }

    public function testAFailingMigrationIsNotRecordedAsApplied(): void
    {
        $this->write('001_broken.sql', 'CREATE TABLE t (v INTEGER);\nNOT VALID SQL;');

        $runner = $this->runner();

        try {
            $runner->run($this->dir);
            $this->fail('esperava exceção');
        } catch (\PDOException) {
            $this->assertSame([], $runner->appliedVersions());
        }
    }

    /** Runs a query and fails the test if the driver returns false. */
    private function query(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        $this->assertNotFalse($stmt, "Query failed: {$sql}");

        return $stmt;
    }
}
