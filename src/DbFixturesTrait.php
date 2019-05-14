<?php

namespace IW\PHPUnit\DbFixtures;

use Symfony\Component\Yaml\Yaml;

trait DbFixturesTrait
{
    private $backup = [];
    private $location;
    private $filenames = [];

    /**
     * Returns an array of DB connections to use
     *
     * @return array
     */
    abstract protected function getConnections(): array;

    /**
     * Loads any fixtures mentioned in annotations
     *
     * @return void
     *
     * @before
     */
    public function loadFixturesByAnnotations(): void {
        if ($fixtures = $this->getAnnotations()['method']['fixtures'] ?? []) {
            $connections = $this->getConnections();

            foreach ($fixtures as $fixture) {
                [$connectionName, $args] = \explode(' ', $fixture, 2) + [null, null];

                $filenames = [];
                if ($bdsFilename = getenv('DB_FIXTURES_BDS_' . $connectionName)) {
                    $filenames[] = $bdsFilename;
                }

                if ($args) {
                    foreach (\explode(' ', $args) as $filename) {
                        $filenames[] = $filename;
                    }
                }

                $this->loadFixtures($connectionName, ...$filenames);
            }
        }
    }

    protected function loadFixtures(string $connectionName, string ...$filenames): void {
        if ($connection = $this->getConnections()[$connectionName] ?? null) {
            $data = [];

            foreach ($filenames as $filename) {
                $data = array_merge_recursive($data, $this->loadFile($filename));
            }

            $sqls = [$this->disableForeignKeys($connection)];

            foreach ($data as $table => $rows) {
                $this->buildSql($connection, $table, $rows, $sqls);
            }

            $sqls[] = $this->enableForeignKeys($connection);

            $this->executeSqls($connection, $sqls);

            // do {
            //     if ($stmt->errorCode() !== '00000') {
            //         $this->throwPDOException($stmt);
            //     }
            // } while ($stmt->nextRowset());


            // $connection->exec($sql);
        } else {
            throw new \InvalidArgumentException('Connection "' . $connectionName . '" not found');
        }
    }

    private function executeSqls(\PDO $pdo, array $sqls): void {
        if (!$pdo->beginTransaction()) {
            $this->throwPDOException($pdo, 'BEGIN TRANSACTION');
        }

        try {
            foreach ($sqls as $sql) {
                if ($pdo->exec($sql) === false) {
                    $this->throwPDOException($pdo, $sql);
                }
            }
        } catch (\Throwable $exception) {
            $pdo->rollback();
            throw $exception;
        }

        if (!$pdo->commit()) {
            $this->throwPDOException($pdo, 'COMMIT');
        }
    }

    private function throwPDOException($pdo, $sql): void {
        [, $code, $message] = $pdo->errorInfo();
        throw new \PDOException($message . PHP_EOL . $sql, $code);
    }

    private function resolveFilePath(string $filename): string {
        if (file_exists($filename)) {
            return $filename;
        }

        if (empty($this->location)) {
            $this->location = \dirname((new \ReflectionClass($this))->getFileName());
        }

        if (file_exists($filepath = $this->location . '/' . $filename)) {
            return $filepath;
        }

        throw new \InvalidArgumentException('Fixtures "' . $filename . '" not found');
    }

    private function loadFile(string $filename): array {
        $filename = $this->resolveFilePath($filename);

        switch ($extension = \pathinfo($filename, \PATHINFO_EXTENSION)) {
            case 'yaml':
            case 'yml':
                return Yaml::parse(file_get_contents($filename));
            default:
                throw new \InvalidArgumentException('Unsupported extension "' . $extension . '"');
        }
    }

    private function disableForeignKeys(\PDO $pdo): string {
        switch ($driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                return 'SET foreign_key_checks = 0;';
            case 'sqlite':
                return 'PRAGMA foreign_keys = OFF;';
        }

        throw new \InvalidArgumentException('Unsupported PDO driver: ' . $driver);
    }

    private function enableForeignKeys(\PDO $pdo): string {
        switch ($driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                return 'SET foreign_key_checks = 1;';
            case 'sqlite':
                return 'PRAGMA foreign_keys = ON;';
        }

        throw new \InvalidArgumentException('Unsupported PDO driver: ' . $driver);
    }

    private function buildSql(\PDO $pdo, string $table, array $rows, array &$sqls): void {
        switch ($driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $sqls[] = \sprintf('TRUNCATE TABLE `%s`;', $table);
                break;
            case 'sqlite':
                $sqls[] = \sprintf('DELETE FROM `%s`;UPDATE SQLITE_SEQUENCE SET seq = 0 WHERE name = "%s";', $table, $table);
                break;
            default:
                throw new \InvalidArgumentException('Unsupported PDO driver: ' . $driver);
        }

        $columns = [];
        foreach ($rows as $row) {
            $columns = array_merge($columns, array_keys($row));
        }

        $columns = array_unique($columns);

        $values = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($columns as $column) {
                if ($val = $row[$column] ?? null) {
                    // pack binary string
                    if (is_string($val) && preg_match('/[^\x20-\x7E\t\r\n]/', $val)) {
                        $vals[] = $this->quoteBinary($pdo, $val);
                    } else {
                        $vals[] = $pdo->quote($val);
                    }
                } else {
                    $vals[] = 'NULL';
                }
            }

            $values[] = '(' . implode(',', $vals) . ')';
        }

        $sqls[] = \sprintf('INSERT INTO `%s` (%s) VALUES %s;', $table, implode(',', $columns), implode(',', $values));
    }

    private function quoteBinary(\PDO $pdo, string $value): string {
        switch ($driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                return sprintf("UNHEX('%s')", bin2hex($value));
            case 'sqlite':
                return sprintf("X'%s'", bin2hex($value));
        }

        throw new \InvalidArgumentException('Unsupported PDO driver: ' . $driver);
    }

}
