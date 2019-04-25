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
    public function loadFixtures(): void {
        if ($fixtures = $this->getAnnotations()['method']['fixtures'] ?? []) {
            $connections = $this->getConnections();

            foreach ($fixtures as $fixture) {
                [$connectionName, $args] = \explode(' ', $fixture, 2) + [null, null];

                if ($connection = $connections[$connectionName] ?? null) {
                    if ($bdsFilename = getenv('DB_FIXTURES_BDS_' . $connectionName)) {
                        $data = $this->loadFile($bdsFilename);
                    } else {
                        $data = [];
                    }

                    if ($args) {
                        $filenames = \explode(' ', $args);
                        foreach ($filenames as $filename) {
                            $data = array_merge_recursive($data, $this->loadFile($filename));
                        }
                    }

                    $sql = '';
                    foreach ($data as $table => $rows) {
                        $sql .= $this->buildSql($connection, $table, $rows) . PHP_EOL;
                    }

                    $connection->exec($sql);
                } else {
                    throw new \InvalidArgumentException('Connection "' . $connectionName . '" not found');
                }
            }
        }
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

    private function buildSql(\PDO $pdo, string $table, array $rows): string {
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
                    $vals[] = $pdo->quote($val);
                } else {
                    $vals[] = 'NULL';
                }
            }

            $values[] = '(' . implode(',', $vals) . ')';
        }

        $insert = \sprintf('INSERT INTO `%s` (%s) VALUES %s;', $table, implode(',', $columns), implode(',', $values));

        switch ($driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $truncate = \sprintf('TRUNCATE TABLE `%s`;', $table);
                break;
            case 'sqlite':
                $truncate = \sprintf('DELETE FROM `%s`;UPDATE SQLITE_SEQUENCE SET seq = 0 WHERE name = "%s";', $table, $table);
                break;
            default:
                throw new \InvalidArgumentException('Unsupported PDO driver: ' . $driver);
        }

        return $truncate . $insert;
    }

}
