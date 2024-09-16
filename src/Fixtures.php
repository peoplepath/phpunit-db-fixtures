<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Fixtures
{
    public array $files;

    public function __construct(public string $label, public string $mode, string ...$files) {
        assert(!empty($label), 'No label given');
        assert(in_array($mode, ['read-only', 'write']), 'Mode must be either "read-only" or "write"');
        assert(!empty($files), 'No fixture files given');

        foreach ($files as $file) {
            assert(!empty($file), 'Empty fixture file given');
        }

        $this->files = $files;
    }

    public function mergeWith(Fixtures $fixtures) : Fixtures {
        assert($fixtures->label === $this->label, 'Cannot merge fixtures with different labels');

        return new Fixtures(
            $this->label,
            in_array('write', [$fixtures->mode, $this->mode]) ? 'write' : 'read-only',
            ...array_values(array_unique(array_merge($fixtures->files, $this->files))),
        );
    }
}
