<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures\Adapter\Pdo;

interface PdoDriver
{
    public function cleanTables(&$sqls) : void;
    public function disableForeignKeys() : string;
    public function enableForeignKeys() : string;
    public function quoteBinary(string $value) : string;
}
