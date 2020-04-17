<?php
/**
 * 车型
 */

namespace app\common;

class CarType
{

    static $message = [
        1 => '小型轿车',
        2 => '小型普通客车',
        3 => '中型普通客车',
        4 => '大型普通客车',
        5 => '重型普通货车',
        6 => '重型厢式货车',
        7 => '重型自卸货车',
        8 => '中型封闭货车',
        9 => '中型集装厢车',
        10 => '中型自卸货车',
        11 => '轻型普通货车',
        12 => '轻型厢式货车',
        13 => '轻型自卸货车',
        14 => '微型普通货车',
        15 => '微型厢式货车',
        16 => '微型自卸货车',
        17 => '重型普通半挂车',
        18 => '中型普通半挂车',
        19 => '轻型普通半挂车',
        20 => '重型普通全挂车',
        21 => '中型普通全挂车',
        22 => '小型专项作业车',
        23 => '中型专项作业车',
        24 => '大型专项作业车',
        25 => '其它'
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

    public static function getCode ($name)
    {
        $list = array_flip(self::$message);
        return isset($list[$name]) ? $list[$name] : null;
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
