<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures;

class FileCache
{
    public function get(string $filename, callable $parsingFunction) {
        $cacheDirectory = getenv('DB_FIXTURES_CACHE_DIR');
        if ($cacheDirectory === false) {
            return $parsingFunction($filename);
        }

        $cacheKey  = hash('sha256', $filename . filemtime($filename));
        $folder    = $cacheDirectory . $cacheKey[0] . $cacheKey[1];
        $cacheFile = $folder . '/' . $cacheKey . '.php';
        if (file_exists($cacheFile)) {
            $fixtures = include $cacheFile;
        } else {
            $fixtures = $parsingFunction($filename);
            if (is_dir($folder) || mkdir($folder)) {
                file_put_contents($cacheFile, '<?php return ' . var_export($fixtures, true) . ';');
            } else {
                trigger_error('Unable to access fixtures cache: ' . $folder);
            }
        }

        return $fixtures;
    }
}
