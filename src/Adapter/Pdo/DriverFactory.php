<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures\Adapter\Pdo;

class DriverFactory
{
    private $drivers = [];

    public function create(\PDO $pdo): PdoDriver {
        $driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        switch ($driverName) {
            case 'mysql':
                return new Driver\MySQL($pdo);
            case 'sqlite':
                return new Driver\SQLite($pdo);
            default:
                throw new \InvalidArgumentException('Unsupported PDO driver: ' . $driver);
        }

        return $this->drivers[$driverName];
    }
}
