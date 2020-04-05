<?php
/**
 * 受伤情况
 */

namespace app\common;

class DriverState
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
        return isset(self::$message[$code]) ? self::$message[$code] : strval($code);
    }

}
