<?php
/**
 * 交通情况
 */

namespace app\common;

class TrafficState
{

    static $message = [
        1 => '待核实'
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
