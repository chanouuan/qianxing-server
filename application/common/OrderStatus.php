<?php
/**
 * 订单状态
 */

namespace app\common;

class OrderStatus
{

    const NOPAY       = 0;
    const PAY         = 1;
    const PART_REFUND = 2;
    const FULL_REFUND = 3;

    static $message = [
        0 => '未收费',
        1 => '已收费',
        2 => '部分退费',
        3 => '全退费',
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
