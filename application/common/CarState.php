<?php
/**
 * 车辆情况
 */

namespace app\common;

class CarState
{

    static $message = [
        1 => '单车事故',
        2 => '两车追尾',
        3 => '三车追尾',
        4 => '多车追尾'
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
