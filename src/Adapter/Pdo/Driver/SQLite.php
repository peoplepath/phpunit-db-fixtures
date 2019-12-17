<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures\Adapter\Pdo\Driver;

use IW\PHPUnit\DbFixtures\Adapter\Pdo\PdoDriver;

class SQLite implements PdoDriver
{
    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function cleanTables(&$sqls) : void {
        $tables = $this->pdo->query(
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

    public function disableForeignKeys() : string {
        return 'PRAGMA foreign_keys = OFF;';
    }

    public function enableForeignKeys() : string {
        return 'PRAGMA foreign_keys = ON;';
    }

    public function quoteBinary(string $value) : string {
        return sprintf("X'%s'", bin2hex($value));
    }
}
