<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures\Adapter\Pdo\Driver;

use IW\PHPUnit\DbFixtures\Adapter\Pdo\PdoDriver;

class MySQL implements PdoDriver
{
    private $currentDatabase;

    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function cleanTables( &$sqls): void {
        $query = 'SELECT TABLE_NAME,TABLE_ROWS,AUTO_INCREMENT FROM `information_schema`.`tables` WHERE table_schema=?';

        $stmt  = $this->pdo->prepare($query);
        $stmt->execute([$this->getCurrentDatabase()]);

        while ($row = $stmt->fetch()) {
            if ($this->isTableEmpty($row)) {
                $sqls[] = \sprintf('TRUNCATE TABLE `%s`;', $row['TABLE_NAME']);
            }
        }
    }

    public function disableForeignKeys(): string {
        return 'SET foreign_key_checks = 0;';
    }

    public function enableForeignKeys(): string {
        return 'SET foreign_key_checks = 1;';
    }

    public function quoteBinary(string $value): string {
        return sprintf("UNHEX('%s')", bin2hex($value));
    }

    private function getCurrentDatabase() : string {
        if ($this->currentDatabase === null) {
            $databaseName = $this->pdo->query('select database()')->fetchColumn();
            $this->currentDatabase = $databaseName;
        }

        return $this->currentDatabase;
    }

    private function isTableEmpty(array $row) : bool {
        return ($row['AUTO_INCREMENT'] > 1 || $row['TABLE_ROWS'] != 0);
    }
}
