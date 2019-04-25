# phpunit-db-fixtures
Simple DB fixtures loading, replacement for phpunit/dbunit

## Usage
```php
use IW\PHPUnit\DbFixtures\DbFixturesTrait;

final class MyTest extends TestCase
{
  use DbFixturesTrait;

  // returns connections to your DB, implementation is up to you, a singleton should be returned probably
  protected function getConnections(): array {
    return [
      // key is name of DB, use it for distinction between multiple DBs
      'mysql' => new \PDO(...),
      'elastic' => new Elasticsearch\Client(...),
    ];
  }
  
  /**
   * @fixtures mysql fixtures.yml
   */
  public function testWithFixtures() {
    // before test data from fixtures.yml will be loaded into mysql
  }
}
```
