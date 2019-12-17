<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures\Adapter;

use IW\PHPUnit\DbFixtures\Adapter\Mongo\Json;
use IW\PHPUnit\DbFixtures\Cache;

class Mongo
{
    private $cache;

    public function __construct(Cache $cache) {
        $this->cache = $cache;
    }

    public function loadFixtures($connection, string ...$filenames) : void {
        $this->removeAllDocuments($connection);

        foreach ($filenames as $filename) {
            $testData = $this->parseJsonp($filename);
            if (strpos($filename, '.meta') !== false) {
                $this->createIndexes($connection, $testData);
            } else {
                $this->insertDocuments($connection, $testData);
            }
        }
    }

    private function removeAllDocuments($connection) : void {
        // remove all documents in it
        foreach ($connection->listCollections() as $collection) {
            // ignore system collection
            if (0 !== mb_strpos($collection->getName(), 'system.')) {
                $connection->dropCollection($collection->getName());
            }
        }
    }

    private function createIndexes($connection, $testData) : void {
        foreach ($testData as $collectionName => $config) {
            if (isset($config['indexes'])) {
                foreach ($config['indexes'] as $index) {
                    $keys = $index['key'];
                    unset($index['key']);
                    $connection->selectCollection($collectionName)->createIndex($keys, $index);
                }
            }
        }
    }

    private function insertDocuments($connection, $testData) : void {
        foreach ($testData as $collectionName => $documents) {
            foreach ($documents as $document) {
                $connection->selectCollection($collectionName)->insertOne($document);
            }
        }
    }

    private function parseJsonp($filename) : array {
        if ($this->cache->has($filename) === false) {
            if (!is_array($testData = Json::decode(
                file_get_contents($filename), true))
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Illegal fixtures %s' . PHP_EOL . 'json_decode: %s',
                        $filename,
                        json_last_error_msg()
                    )
                );
            }

            //transform data into JSONP format which can handle advanced types (eg. MongoId, MongoDate, etc.)
            Json::jsonToJsonp($testData);
            $this->cache->set($filename, $testData);
        }

        return $this->cache->get($filename);
    }
}
