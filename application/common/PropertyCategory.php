<?php
/**
 * 路产赔付项目类型
 */

namespace app\common;

class PropertyCategory
{


    static $message = [
        1 => '利用、占用公路、公路用地及设施',
        2 => '污染公路、留用地及设施',
        3 => '公路及附属设施',
        4 => '交通标志、标线',
        5 => '征费设施',
        6 => '绿化',
        7 => '挖掘、侵占公路、路产赔偿',
        8 => '波型防撞护栏',
        9 => '征费、通讯、监控安全设施',
        10 => '未列项目'
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
