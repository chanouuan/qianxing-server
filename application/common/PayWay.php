<?php
/**
 * 支付方式
 */

namespace app\common;

class PayWay
{

    const WXPAYJS  = 'wxpayjs';
    const ALIPAYJS = 'alipayjs';

    static $message = [
        'wxpayjs'  => '微信',
        'alipayjs' => '支付宝'
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
