<?php
/**
 * 天气
 */

namespace app\common;

class Weather
{

    static $message = [
        1 => '晴',
        2 => '阴',
        3 => '雨',
        4 => '雪',
        5 => '雾'
    ];

    public static function format ($code)
    {
        return isset(self::$message[$code]) ? $code : null;
    }

    public static function getMessage ($code)
    {
        return isset(self::$message[$code]) ? self::$message[$code] : strval($code);
    }

}
