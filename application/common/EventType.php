<?php
/**
 * 事件类型
 */

namespace app\common;

class EventType
{

    static $message = [
        1 => '公路突发事件',
        2 => '自然灾害',
        3 => '交通建设工程安全事故',
        4 => '社会安全事件'
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
