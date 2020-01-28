<?php declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures\Adapter\Mongo;

use DateTime;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;

class Json
{
    /**
     * Decodes given JSON string into object or array
     *
     * @param string $jsonString string with JSON to decode
     * @param bool   $toArray    TRUE returns array, FALSE return object
     *
     * @return array|object
     */
    public static function decode($jsonString, $toArray=false) {
        $json = '';
        foreach (explode("\n", $jsonString) as $line) {
            // remove inline comments ( // some comment)
            // test whether comment starts at the beginning of the line or there can be spaces and after them must be
            // the slashes (//)
            if (preg_match('#^ *//.*#', $line)) {
                continue;
            }

            $json .= $line;
        }

        return json_decode($json, $toArray);
    }

    /**
     * Transforms given array (strict JSON) into array (JSONP)
     *
     * Example:
     * //from
     * Array(
     *     [_id] => Array([$oid] => "<id>")
     * )
     * //into
     * Array(
     *     [_id] => new ObjectId("<id>")
     * )
     *
     * @param mixed $json array structure with strict JSON
     *
     * @return string
     *
     * @see http://docs.mongodb.org/manual/reference/mongodb-extended-json/
     */
    public static function jsonToJsonp(&$json) {
        if (is_array($json)) {
            foreach ($json as &$attr) {
                switch (true) {
                    case (isset($attr['$oid'])):
                        $attr = new ObjectId($attr['$oid']);
                        break;
                    case (isset($attr['$date'])):
                        $attr = new UTCDateTime(new DateTime($attr['$date']));
                        break;
                    case (isset($attr['$regex'])):
                        $opts = isset($attr['$options']) ? $attr['$options'] : '';
                        $attr = new Regex($attr['$regex'], $opts);
                        break;
                    case (is_array($attr)):
                        self::jsonToJsonp($attr);
                        break;
                }
            }
        }

        return $json;
    }
}
