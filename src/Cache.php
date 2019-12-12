<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures;

class Cache
{
    private static $instance;
    private $data = [];

    private function __construct() {}

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function has($key) {
        return array_key_exists($key, $this->data);
    }

    public function get($key) {
        return $this->data[$key];
    }

    public function set($key, $data) {
        $this->data[$key] = $data;
    }
}
