<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures;

interface NormalizationStrategy
{
    /**
     * Normalize fixtures, use it for implementing own normalize strategy
     *
     * @param array $bdsData  Basic data set
     * @param array $testData Other fixtures
     *
     * @return array
     */
    public function normalizeFixtures(array $bdsData, array $testData) : array;
}
