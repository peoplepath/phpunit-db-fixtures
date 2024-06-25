<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures;

use PHPUnit\Framework\TestCase;

final class FixturesTest extends TestCase
{
    public function testCreate() : void {
        $fixtures = new Fixtures('mysql', 'write', 'foo.yaml', 'bar.json');

        $this->assertSame('mysql', $fixtures->label);
        $this->assertSame('write', $fixtures->mode);
        $this->assertSame(['foo.yaml', 'bar.json'], $fixtures->files);
    }
}
