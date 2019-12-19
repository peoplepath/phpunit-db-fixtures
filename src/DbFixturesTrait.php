<?php

namespace IW\PHPUnit\DbFixtures;

use IW\PHPUnit\DbFixtures\Adapter\Pdo\DefaultNormalizationStrategy;
use IW\PHPUnit\DbFixtures\Adapter\Pdo\DriverFactory;

trait DbFixturesTrait
{
    private $location;
    private $filenames = [];
    private static $loadedFixturesHash = [];
    private static $previousMode = [];
    private static $READ_ONLY = 'read-only';
    private static $WRITE     = 'write';

    private $adapters = [];

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
        foreach ($this->extractFixturesDefinitions() as $connectionName => [$mode, $args]) {
            $filenames = [];
            if ($args) {
                foreach (\explode(' ', $args) as $filename) {
                    $filenames[] = $this->resolveFilePath($connectionName, $filename);
                }
            }

            $fixturesHash = md5(implode('-', $filenames));
            if ($mode === self::$READ_ONLY
                && isset(self::$loadedFixturesHash[$connectionName])
                && $fixturesHash === self::$loadedFixturesHash[$connectionName]
                && self::$previousMode[$connectionName] === self::$READ_ONLY
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
        if ($connection = $this->getConnections()[$connectionName] ?? null) {
            $adapter = $this->getConnectionAdapter($connection);
            $adapter->loadFixtures($connection, ...$filenames);
        } else {
            throw new \InvalidArgumentException('Connection "' . $connectionName . '" not found');
        }
    }

    protected function getNormalizationStrategy() : NormalizationStrategy {
        return new DefaultNormalizationStrategy();
    }

    private function getConnectionAdapter($connection) {
        $connectionName = get_class($connection);
        if (!isset($this->adapters[$connectionName])) {
            switch (true) {
                case $connection instanceof \PDO:
                    $this->adapters[$connectionName] = new Adapter\Pdo(
                        Cache::getInstance(),
                        $this->getNormalizationStrategy(),
                        new DriverFactory(),
                        new FileCache()
                    );
                    break;
                case $connection instanceof \MongoDB\Database:
                    $this->adapters[$connectionName] = new Adapter\Mongo(Cache::getInstance(), new FileCache());
                    break;
                default:
                    throw new \InvalidArgumentException(
                        'No support for connection of type: '.get_class($connection)
                    );
            }
        }

        return $this->adapters[$connectionName];
    }

    private function extractFixturesDefinitions() : array {
        $annotations = $this->getAnnotations();

        $fixtures = [];
        foreach ($annotations['method']['fixtures'] ?? [] as $fixture) {
            [$connectionName, $mode, $args] = \explode(' ', $fixture, 3) + [null, null, null];

            if (!in_array($mode, [self::$READ_ONLY, self::$WRITE], true)) {
                throw new \InvalidArgumentException(
                    'No support for read mode: '.$mode
                );
            }

            if (array_key_exists($connectionName, $fixtures)) {
                [$newMode, $newArgs] = $fixtures[$connectionName];
                $params = [$newMode, $newArgs.' '.$args];
            } else {
                $params = [$mode, $args];
            }

            $fixtures[$connectionName]  = $params;
        }

        return $fixtures;
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
}
