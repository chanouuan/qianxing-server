<?php
/**
 * 交通情况
 */

namespace app\common;

class TrafficState
{

    static $message = [
        1 => '通行正常',
        2 => '半幅通行',
        3 => '半幅双向通行',
        4 => '交通中断'
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
