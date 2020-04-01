<?php
/**
 * 车型
 */

namespace app\common;

class CarType
{

    static $message = [
        1 => '小型车',
        2 => '中型车',
        3 => '大型车'
    ];

    public static function format ($code)
    {
        return isset(self::$message[$code]) ? $code : null;
    }

    public static function getMessage ($code)
    {
        return isset(self::$message[$code]) ? self::$message[$code] : $code;
    }

}
