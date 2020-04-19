<?php
/**
 * 受伤情况
 */

namespace app\common;

class DriverState
{

    static $message = [
        1 => '无人员受伤',
        2 => '有人员受伤'
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
