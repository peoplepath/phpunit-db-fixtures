# phpunit-db-fixtures
Simple DB fixtures loading, replacement for phpunit/dbunit

## Usage
```php
use IW\PHPUnit\DbFixtures\DbFixturesTrait;

final class MyTest extends TestCase
{
  use DbFixturesTrait;

  // returns connections to your DB, implementation is up to you, a singleton should be returned probably
  protected function getConnection(string $name) {
    switch ($name) {
      case 'mysql': return new \PDO(...);
      case 'elastic': return new Elasticsearch\Client(...);
      default: return null;
    }
  }

  /**
   * @fixtures mysql read-only fixtures.yml
   */
  public function testWithFixtures() {
    // before test data from fixtures.yml will be loaded into mysql
  }
}
```
