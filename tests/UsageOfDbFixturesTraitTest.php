<?php

namespace IW\PHPUnit\DbFixtures;

final class UsageOfDbFixturesTraitTest extends \PHPUnit\Framework\TestCase
{
    use DbFixturesTrait;

    private $mysql;
    private $sqlite;

    protected function getConnections(): array {
        return [
            'mysql' => $this->mysql ?? $this->mysql = new \PDO('mysql:host=127.0.0.1;dbname=db', 'root', ''),
            'sqlite' => $this->sqlite ?? $this->sqlite = new \PDO('sqlite:db.sqlite3'),
        ];
    }

    public function provideConnections(): \Generator {
        foreach ($this->getConnections() as $name => $connection) {
            yield $name => [$connection];
        }
    }

    /**
     * @fixtures sqlite
     * @fixtures mysql
     *
     * @dataProvider provideConnections
     */
    public function testBds(\PDO $connection): void {
        $stmt = $connection->query('SELECT * FROM demo');
        $demo = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $expected = [
            [
                'id'       => '1',
                'username' => 'user1',
                'created'  => '2011-11-11 11:11:11',
            ],
            [
                'id'       => '2',
                'username' => 'user2',
                'created'  => '2012-12-12 12:12:12',
            ],
        ];

        $this->assertSame($expected, $demo);
    }

    /**
     * @fixtures sqlite fixtures.yml fixtures.yaml
     * @fixtures mysql fixtures.yml fixtures.yaml
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

}
