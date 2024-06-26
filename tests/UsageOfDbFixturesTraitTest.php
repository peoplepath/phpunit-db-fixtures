<?php

namespace IW\PHPUnit\DbFixtures;

use Elasticsearch;
use IW\PHPUnit\DbFixtures\Fixtures;
use MongoDB;
use MongoDB\Client;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;

final class UsageOfDbFixturesTraitTest extends \PHPUnit\Framework\TestCase
{
    use DbFixturesTrait;

    private $mongo;
    private $mysql;
    private $sqlite;

    protected function getConnection(string $connectionName): PDO|MongoDB\Database|Elasticsearch\Client {
        return match ($connectionName) {
            'mysql' => $this->mysql ?? $this->mysql = new \PDO(
                'mysql:host=127.0.0.1:33060;dbname=db',
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
                'mongodb://127.0.0.1:27016/db'
                ))->selectDatabase('db')
        };
    }

    public function provideConnections(): \Generator {
        foreach (['mysql', 'sqlite'] as $connectionName) {
            yield $connectionName => [$this->getConnection($connectionName)];
        }
    }

    /**
     * @fixtures sqlite read-only bds.yml
     * @fixtures mysql read-only bds.yml
     *
     * @testWith ["mysql"]
     *           ["sqlite"]
     */
    public function testBdsWithAnnotations(string $label): void {
        $this->testBds($label);
    }

    #[Fixtures('mysql', 'read-only', 'bds.yml')]
    #[Fixtures('sqlite', 'read-only', 'bds.yml')]
    #[TestWith(['mysql'])]
    #[TestWith(['sqlite'])]
    public function testBds(string $connectionName): void {
        $connection = $this->getConnection($connectionName);

        $stmt = $connection->query('SELECT * FROM demo');
        $demo = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $expected = [
            [
                'id'           => 1,
                'username'     => 'user1',
                'created'      => '2011-11-11 11:11:11',
                'random_bytes' => null,
            ],
            [
                'id'           => 2,
                'username'     => 'user2',
                'created'      => '2012-12-12 12:12:12',
                'random_bytes' => null,
            ],
        ];

        $this->assertSame($expected, $demo);
    }

    #[Fixtures('mongo', 'read-only', 'bds.json')]
    public function testBdsMongo(): void {
        $database        = $this->getConnection('mongo');
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

    #[Fixtures('mysql', 'read-only', 'bds.yml', 'fixtures.yml', 'fixtures.yaml')]
    #[Fixtures('sqlite', 'read-only', 'bds.yml', 'fixtures.yml', 'fixtures.yaml')]
    #[TestWith(['mysql'])]
    #[TestWith(['sqlite'])]
    public function testLoadingFixtures(string $connectionName): void {
        $connection = $this->getConnection($connectionName);

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

    #[Fixtures('mongo', 'read-only', 'fixtures.json')]
    public function testLoadingFixturesMongo(): void {
        $database        = $this->getConnection('mongo');
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
     * @testWith ["mysql"]
     *           ["sqlite"]
     */
    function testBinaryFixturesSqlite(string $connectionName) {
        $pdo = $this->getConnection($connectionName);

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
