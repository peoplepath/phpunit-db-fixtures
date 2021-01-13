<?php

namespace IW\PHPUnit\DbFixtures;

use Elasticsearch;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use ErrorException;
use MongoDB;
use PDO;
use stdClass;
use PHPUnit\Util\Test;
use Symfony\Component\Yaml\Yaml;

trait DbFixturesTrait
{
    private $backup = [];
    private $location;
    private $filenames = [];
    private $currentDatabase = [];
    private $fixturesCache = [];

    private static $loadedFixturesHash = [];
    private static $previousMode = [];

    /**
     * Returns connection for given name or a NULL if none found
     *
     * @return PDO|MongoDB\Database|Elasticsearch\Client|null
     */
    abstract protected function getConnection(string $name);

    /**
     * Loads any fixtures mentioned in annotations
     *
     * @return void
     *
     * @before
     */
    public function loadFixturesByAnnotations(): void {
        $annotations = Test::parseTestMethodAnnotations(
            static::class,
            $this->getName(false)
        );

        $fixtures = [];
        foreach ($annotations['method']['fixtures'] ?? [] as $fixture) {
            [$connectionName, $mode, $args] = \explode(' ', $fixture, 3) + [null, null, null];
            if (array_key_exists($connectionName, $fixtures)) {
                [$newMode, $newArgs] = $fixtures[$connectionName];
                $params = [$newMode, $newArgs.' '.$args];
            } else {
                $params = [$mode, $args];
            }

            $fixtures[$connectionName]  = $params;
        }

        foreach ($fixtures as $connectionName => [$mode, $args]) {
            $filenames = [];
            if ($args) {
                foreach (\explode(' ', $args) as $filename) {
                    $filenames[] = $this->resolveFilePath($connectionName, $filename);
                }
            }

            $fixturesHash = md5(implode('-', $filenames));
            if (isset(self::$loadedFixturesHash[$connectionName])
                && $fixturesHash === self::$loadedFixturesHash[$connectionName]
                && $mode === 'read-only'
                && self::$previousMode[$connectionName] === 'read-only'
            ) {
                self::$previousMode[$connectionName] = $mode;
                continue;
            }

            $this->loadFixtures($connectionName, ...$filenames);

            self::$loadedFixturesHash[$connectionName] = $fixturesHash;
            self::$previousMode[$connectionName]       = $mode;
        }

    }

    protected function loadFixtures(string $connectionName, string ...$filenames): void {
        if ($connection = $this->getConnection($connectionName)) {
            switch(true) {
                case $connection instanceof \PDO:
                    $this->loadFixturesPdo($connection, ...$filenames);
                    break;
                case $connection instanceof \MongoDB\Database:
                    $this->loadFixturesMongo($connection, ...$filenames);
                    break;
                case $connection instanceof \Elasticsearch\Client:
                    $this->loadFixturesElastic($connection, ...$filenames);
                    break;
                default:
                    throw new \InvalidArgumentException(
                        'No suport for connection of type: '.get_class($connection)
                    );
            }
        } else {
            throw new \InvalidArgumentException('Connection "' . $connectionName . '" not found');
        }
    }

    /**
     * Normalize fixtures, override for implementing own normalize strategy
     *
     * @param array $bdsData  Basic data set
     * @param array $testData Other fixtures
     *
     * @return array
     */
    protected function normalizeFixtures(array $bdsData, array $testData) : array {
        return array_merge_recursive($bdsData, $testData);
    }

    protected function resolveIndexPrefix() : string {
        return '';
    }

    private function loadFixturesMongo($connection, string ...$filenames) {
        //walk trough each collection and remove all documents in it
        foreach ($connection->listCollections() as $collection) {
            // drop all with exception of system
            if (0 !== mb_strpos($collection->getName(), 'system.')) {
                $connection->dropCollection($collection->getName());
            }
        }

        foreach ($filenames as $filename) {
            $testData = $this->parseJsonp($filename);
            if (strpos($filename, '.meta') !== false) {
                foreach ($testData as $collectionName => $config) {
                    if (isset($config['indexes'])) {
                        foreach ($config['indexes'] as $index) {
                            $keys = $index['key'];
                            unset($index['key']);
                            $connection->selectCollection($collectionName)->createIndex($keys, $index);
                        }
                    }
                }
            } else {
                foreach ($testData as $collectionName => $documents) {
                    foreach ($documents as $document) {
                        $connection->selectCollection($collectionName)->insertOne($document);
                    }
                }
            }
        }
    }

    private function parseJsonp($filename) {
        $cache = $this->getCache();
        if ($cache->has($filename) === false) {
            $testData = $this->cacheParsedFiles(
                $filename,
                static function ($filename) {
                    if (!is_array($testData = Json::decode(
                        file_get_contents($filename), true))
                    ) {
                        throw new \InvalidArgumentException(
                            sprintf(
                                'Illegal fixtures %s' . PHP_EOL . 'json_decode: %s',
                                $filename,
                                json_last_error_msg()
                            )
                        );
                    }

                    //transform data into JSONP format which can handle advanced types (eg. MongoId, MongoDate, etc.)
                    Json::jsonToJsonp($testData);

                    return $testData;
                }
            );

            $cache->set($filename, $testData);
        }

        return $cache->get($filename);
    }

    private function loadFixturesPdo($connection, string ...$filenames) {
        $data = [];

        $bdsFilename = null;
        // First file acts as a basic data set
        if (count($filenames) > 1) {
            $bdsFilename = array_shift($filenames);
        }

        foreach ($filenames as $filename) {
            $data = array_merge_recursive($data, $this->loadFile($filename));
        }

        if ($bdsFilename !== null) {
            $data = $this->normalizeFixtures($this->loadFile($bdsFilename), $data);
        }

        $sqls = [$this->disableForeignKeys($connection)];

        $this->cleanTables($connection, $sqls);

        foreach ($data as $table => $rows) {
            $this->buildSql($connection, $table, $rows, $sqls);
        }

        $sqls[] = $this->enableForeignKeys($connection);

        $this->executeSqls($connection, $sqls);
    }

    private function loadFixturesElastic($connection, string ...$filenames) {
        foreach ($filenames as $filename) {
            $fixtures = $this->loadFile($filename);
            foreach ($fixtures as $indexName => $data) {
                $this->cleanIndex($connection, $this->resolveIndexPrefix() . $indexName);
                $body = [];
                foreach ($data as $doc) {
                    $body[] =  [
                        'index' => [
                            '_index' => $this->resolveIndexPrefix() . $indexName,
                            '_id'    => $doc['id']
                        ]
                    ];
                    $body[] = $doc;
                }

                if (!empty($body)) {
                    $connection->bulk(
                        [
                            'refresh' => 'wait_for',
                            'body'    => $body
                        ]
                    );
                }
            }
        }
    }


    private function cleanTables(\PDO $pdo, &$sqls) : void {
        switch ($driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $this->cleanTablesMySQL($pdo, $sqls);
                break;
            case 'sqlite':
                $this->cleanTablesSQLite($pdo, $sqls);
        }
    }

    private function cleanIndex($connection, $indexName): void {
        try {
            do {
                $result = $connection->deleteByQuery(
                    [
                        'index' => $indexName,
                        'body'  => [
                            'query' => [
                                'match_all' => new stdClass(),
                            ]
                        ],
                        'refresh'   => true,
                        'conflicts' => 'proceed', // set to do not interrupt when conflict occurs
                    ]
                );
                if (!empty($result['failures'])) {
                    throw new ErrorException('Error occurred while running deleteByQuery.');
                }
            } while ($result['version_conflicts'] > 0);
        } catch (Missing404Exception $e) {
            // do noting
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

    private function resolveFilePath(string $connectionName, string $filename): string {
        if ($includePaths = getenv('DB_FIXTURES_INCLUDE_PATHS_' .$connectionName)) {
            $includePaths = explode(':', $includePaths);
            foreach ($includePaths as $includePath) {
                if (file_exists($includePath.$filename)) {
                    return $includePath.$filename;
                }
            }
        }

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

    private function getCache() {
        return Cache::getInstance();
    }

    private function loadFile(string $filename): array {
        switch ($extension = \pathinfo($filename, \PATHINFO_EXTENSION)) {
            case 'yaml':
            case 'yml':
                $cache = $this->getCache();
                if ($cache->has($filename) === false) {
                    $yaml = $this->cacheParsedFiles(
                        $filename,
                        static function ($filename) {
                            return Yaml::parse(file_get_contents($filename));
                        }
                    );
                    $cache->set($filename, $yaml);
                }

                return $cache->get($filename);
            default:
                throw new \InvalidArgumentException('Unsupported extension "' . $extension . '"');
        }
    }

    private function cacheParsedFiles($filename, $parsingFunction) {
        $cacheDirectory = getenv('DB_FIXTURES_CACHE_DIR');

        if ($cacheDirectory === false) {
            return $parsingFunction($filename);
        }

        $cacheKey  = hash('sha256', $filename . filemtime($filename));
        $folder    = $cacheDirectory . $cacheKey[0] . $cacheKey[1];
        $cacheFile = $folder . '/' . $cacheKey . '.php';

        if (file_exists($cacheFile)) {
            $fixtures = include $cacheFile;
        } else {
            $fixtures = $parsingFunction($filename);

            if (is_dir($folder) || mkdir($folder)) {
                file_put_contents($cacheFile, '<?php return ' . var_export($fixtures, true) . ';');
            } else {
                trigger_error('Unable to access fixtures cache: ' . $folder);
            }
        }

        return $fixtures;
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

    private function quoteBinary(\PDO $pdo, string $value): string {
        switch ($driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                return sprintf("UNHEX('%s')", bin2hex($value));
            case 'sqlite':
                return sprintf("X'%s'", bin2hex($value));
        }

        throw new \InvalidArgumentException('Unsupported PDO driver: ' . $driver);
    }

    /**************** Adapters specific stuff  *******************/

    protected function isTableEmptyMySQL(array $row) : bool {
        return ($row['AUTO_INCREMENT'] > 1 || $row['TABLE_ROWS'] != 0);
    }

    private function cleanTablesMySQL(\PDO $pdo, &$sqls) : void {
        $query = 'SELECT TABLE_NAME,TABLE_ROWS,AUTO_INCREMENT FROM `information_schema`.`tables` WHERE table_schema=?';

        $stmt  = $pdo->prepare($query);
        $stmt->execute(
            [
                $this->getDatabaseMySQL($pdo)
            ]
        );

        while ($row = $stmt->fetch()) {
            if ($this->isTableEmptyMySQL($row)) {
                $sqls[] = \sprintf('TRUNCATE TABLE `%s`;', $row['TABLE_NAME']);
            }
        }
    }

    private function getDatabaseMySQL(\PDO $pdo) : string {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if (!array_key_exists($driver, $this->currentDatabase)) {
            $databaseName = $pdo->query('select database()')->fetchColumn();
            $this->currentDatabase[$driver] = $databaseName;
        }

        return $this->currentDatabase[$driver];
    }

    private function cleanTablesSQLite(\PDO $pdo, &$sqls) : void {
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND tbl_name<>'sqlite_sequence'"
        )->fetchAll();
        foreach ($tables as ['name' => $tableName]) {
            $sqls[] = \sprintf(
                'DELETE FROM `%s`;UPDATE SQLITE_SEQUENCE SET seq = 0 WHERE name = "%s";',
                $tableName,
                $tableName
            );
        }
    }
}
