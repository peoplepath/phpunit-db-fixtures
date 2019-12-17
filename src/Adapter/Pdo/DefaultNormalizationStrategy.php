<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures\Adapter\Pdo;

use IW\PHPUnit\DbFixtures\NormalizationStrategy;

class DefaultNormalizationStrategy implements NormalizationStrategy
{
    /**
     * Normalize fixtures, override for implementing own normalize strategy
     *
     * @param array $bdsData  Basic data set
     * @param array $testData Other fixtures
     *
     * @return array
     */
    public function normalizeFixtures(array $bdsData, array $testData) : array {
        return array_merge_recursive($bdsData, $testData);
    }
}
