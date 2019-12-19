<?php

namespace IW\PHPUnit\DbFixtures\Adapter;

use IW\PHPUnit\DbFixtures\Adapter\Pdo\PdoDriver;
use IW\PHPUnit\DbFixtures\Cache;
use IW\PHPUnit\DbFixtures\FileCache;
use IW\PHPUnit\DbFixtures\NormalizationStrategy;
use IW\PHPUnit\DbFixtures\Adapter\Pdo\DriverFactory;
use Symfony\Component\Yaml\Yaml;

class Pdo
{
    /** @var Cache */
    private $cache;

    /** @var NormalizationStrategy */
    private $normalizationStrategy;

    /** @var DriverFactory */
    private $driverFactory;

    /** @var FileCache */
    private $fileCache;

    public function __construct(
        Cache $cache,
        NormalizationStrategy $normalizationStrategy,
        DriverFactory $driverFactory,
        FileCache $fileCache
    ) {
        $this->cache                 = $cache;
        $this->normalizationStrategy = $normalizationStrategy;
        $this->driverFactory         = $driverFactory;
        $this->fileCache             = $fileCache;
    }

    public function loadFixtures($connection, string ...$filenames) : void {
        $data        = [];
        $pdoAdapter  = $this->driverFactory->create($connection);
        $bdsFilename = null;

        // First file acts as a basic data set
        if (count($filenames) > 1) {
            $bdsFilename = array_shift($filenames);
        }

        foreach ($filenames as $filename) {
            $data = array_merge_recursive($data, $this->loadFile($filename));
        }

        if ($bdsFilename !== null) {
            $data = $this->normalizationStrategy->normalizeFixtures($this->loadFile($bdsFilename), $data);
        }

        $sqls = [$pdoAdapter->disableForeignKeys()];

        $pdoAdapter->cleanTables($sqls);

        foreach ($data as $table => $rows) {
            $this->buildSql($connection, $table, $rows, $sqls);
        }

        $sqls[] = $pdoAdapter->enableForeignKeys();

        $this->executeSqls($connection, $sqls);
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

    private function loadFile(string $filename): array {
        switch ($extension = \pathinfo($filename, \PATHINFO_EXTENSION)) {
            case 'yaml':
            case 'yml':
                if ($this->cache->has($filename) === false) {
                    $yaml = $this->fileCache->get(
                        $filename,
                        static function ($filename) {
                            return Yaml::parse(file_get_contents($filename));
                        }
                    );
                    $this->cache->set($filename, $yaml);
                }

                return $this->cache->get($filename);
            default:
                throw new \InvalidArgumentException('Unsupported extension "' . $extension . '"');
        }
    }

    private function buildSql(\PDO $pdo, string $table, array $rows, array &$sqls): void {
        $adapter = $this->driverFactory->create($pdo);
        $columns = [];
        foreach ($rows as $row) {
            $columns = array_merge($columns, array_keys($row));
        }

        $columns = array_unique($columns);

        $values = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($columns as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null) {
                    $val = $row[$column];
                    // pack binary string
                    if (is_string($val) && preg_match('/[^\x20-\x7E\t\r\n]/', $val)) {
                        $vals[] = $adapter->quoteBinary($val);
                    } else {
                        $vals[] = $pdo->quote($val);
                    }
                } else {
                    $vals[] = 'NULL';
                }
            }

            $values[] = '(' . implode(',', $vals) . ')';
        }

        foreach ($columns as &$column) {
            $column = '`'.$column.'`';
        }

        $sqls[] = \sprintf(
            'INSERT INTO `%s` (%s) VALUES %s;',
            $table,
            implode(',', $columns),
            implode(',', $values)
        );
    }
}
