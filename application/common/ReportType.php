<?php
/**
 * 报警类型
 */

namespace app\common;

class ReportType
{

    static $message = [
        1 => '交通事故',
        2 => '车辆事故'
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
