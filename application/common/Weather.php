<?php
/**
 * 天气
 */

namespace app\common;

class Weather
{

    static $message = [
        1 => '阴',
        2 => '晴',
        3 => '小雨',
        4 => '大雨',
        5 => '小雪',
        6 => '大雪',
        7 => '雾'
    ];

    public static function getKey ()
    {
        $result = [];
        foreach (self::$message as $k => $v) {
            $result[] = [
                'id' => $k,
                'name' => $v
            ];
        }
        return $result;
    }

    public static function format ($code)
    {
        return isset(self::$message[$code]) ? $code : null;
    }

    public static function getMessage ($code)
    {
        return isset(self::$message[$code]) ? self::$message[$code] : strval($code);
    }

}
