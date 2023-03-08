<?php

namespace IW\PHPUnit\DbFixtures;

use MongoDB\Client;

final class UsageOfDbFixturesTraitTest extends \PHPUnit\Framework\TestCase
{
    use DbFixturesTrait;

    private $mysql;
    private $sqlite;

    protected function getConnections(): array {
        $mysqlServer = getenv('MYSQL_SERVER') !== false ? getenv('MYSQL_SERVER') : '127.0.0.1';
        $mysqlPort   = getenv('MYSQL_PORT') !== false ? getenv('MYSQL_PORT') : '33060';

        $mongoServer = getenv('MONGO_SERVER') !== false ? getenv('MONGO_SERVER') : '127.0.0.1';
        $mongoPort   = getenv('MONGO_PORT') !== false ? getenv('MONGO_PORT') : '27016';
        return [
            'mysql' => $this->mysql ?? $this->mysql = new \PDO(
                'mysql:host='.$mysqlServer.':'.$mysqlPort.';dbname=db',
                'root',
                '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
                ]
            ),
            'sqlite' => $this->sqlite ?? $this->sqlite = new \PDO(
                'sqlite:db.sqlite3',
                null,
                null,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
                ]
            ),
            'mongo' => $this->mongo ?? $this->mongo = (new Client(
                'mongodb://'.$mongoServer.':'.$mongoPort.'/db'
                ))->selectDatabase('db')
        ];
    }

    public function provideConnections(): \Generator {
        foreach ($this->getConnections() as $name => $connection) {
            if ($connection instanceof \PDO) {
                yield $name => [$connection];
            }
        }
    }

    /**
     * @fixtures sqlite read-only bds.yml
     * @fixtures mysql read-only bds.yml
     *
     * @dataProvider provideConnections
     */
    public function testBds(\PDO $connection): void {
        $stmt = $connection->query('SELECT * FROM demo');
        $demo = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $expected = [
            [
                'id'           => '1',
                'username'     => 'user1',
                'created'      => '2011-11-11 11:11:11',
                'random_bytes' => null,
            ],
            [
                'id'           => '2',
                'username'     => 'user2',
                'created'      => '2012-12-12 12:12:12',
                'random_bytes' => null,
            ],
        ];

        $this->assertSame($expected, $demo);
    }

    /**
     * @fixtures mongo read-only bds.json
     *
     */
    public function testBdsMongo(): void {
        $database        = $this->getConnections()['mongo'];
        $fieldCollection = $database->selectCollection('user');

        $expected = [
            [
                '_id' => [
                    '$oid' => '100000000000000000000001',
                ],
                '_created' => [
                    '$date' => [
                        '$numberLong' => '1490679017000',
                    ],
                ],
                'username' => 'bob',
            ],
            [
                '_id' => [
                    '$oid' => '100000000000000000000002',
                ],
                '_created' => [
                    '$date' => [
                        '$numberLong' => '1553751017000',
                    ],
                ],
                'username' => 'alice',
            ],
        ];

        $foundDocuments = json_decode(
            json_encode(
                iterator_to_array(
                    $fieldCollection->find()
                )
            ),
            true
        );

        $this->assertSame($expected, $foundDocuments);
    }

    /**
     * @fixtures sqlite read-only bds.yml fixtures.yml fixtures.yaml
     * @fixtures mysql read-only bds.yml fixtures.yml fixtures.yaml
     *
     * @dataProvider provideConnections
     */
    public function testLoadingFixtures(\PDO $connection): void {
        $stmt = $connection->query('SELECT username FROM demo');
        $demo = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $expected = [
            [
                'username' => 'user1',
            ],
            [
                'username' => 'user2',
            ],
                            [
                'username' => 'user3',
            ],
                            [
                'username' => 'user4',
            ],
        ];

        $this->assertSame($expected, $demo);
    }

    /**
     * @fixtures mongo read-only fixtures.json
     *
     */
    public function testLoadingFixturesMongo(): void {
        $database        = $this->getConnections()['mongo'];
        $fieldCollection = $database->selectCollection('field');

        $expected = [
            [
                '_id' => [
                    '$oid' => '700000000000000000000001',
                ],
                '_created' => [
                    '$date' => [
                        '$numberLong' => '1490679017000',
                    ],
                ],
                'name' => 'Name',
                'type' => 'string',
            ],
            [
                '_id' => [
                    '$oid' => '700000000000000000000002',
                ],
                '_created' => [
                    '$date' => [
                        '$numberLong' => '1553751017000',
                    ],
                ],
                'name' => 'Last name',
                'type' => 'string',
            ],
        ];

        $foundDocuments = json_decode(
            json_encode(
                iterator_to_array(
                    $fieldCollection->find()
                )
            ),
            true
        );

        $this->assertSame($expected, $foundDocuments);
    }

    public function testErrorInFixturesWithSqlite() {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/table demo has no column named usernames/');
        $this->loadFixtures(
            'sqlite',
            $this->getAbsolutePathToFixture('fixtures_with_error.yml')
        );
    }

    public function testErrorInFixturesWithMysql() {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/Unknown column \'usernames\' in \'field list\'/');
        $this->loadFixtures(
            'mysql',
            $this->getAbsolutePathToFixture('fixtures_with_error.yml')
        );
    }

    public function testErrorInFixturesWithMongo() {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Illegal fixtures/');
        $this->loadFixtures(
            'mongo',
            $this->getAbsolutePathToFixture('fixtures_with_error.json')
        );
    }

    /**
     * @dataProvider provideConnections
     */
    function testBinaryFixturesSqlite(\PDO $pdo) {
        $connectionName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $this->loadFixtures(
            $connectionName,
            $this->getAbsolutePathToFixture('fixtures_with_binary_data.yml')
        );

        $stmt = $pdo->query('SELECT HEX(random_bytes) as hex FROM demo');
        $demo = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $expected = [
            [
                'hex' => '13A8E7A00E84D88CCA63DA4855108F73657B5C069FB4077530162A98D7A4B236',
            ]
        ];
        $this->assertSame($expected, $demo);
    }

    private function getAbsolutePathToFixture(string $filename) {
        return \dirname((new \ReflectionClass($this))->getFileName()) . '/' . $filename;
    }
}
