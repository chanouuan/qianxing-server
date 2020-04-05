<?php
/**
 * 事故报案状态
 */

namespace app\common;

class ReportStatus
{

    const CANCEL    = -3;
    const REFUNDING = -2;
    const REFUND    = -1;
    const WAITING   = 0;
    const ACCEPT    = 1;
    const HANDLE    = 2;

    static $message = [
        -3 => '已撤销',
        -2 => '退费中',
        -1 => '已退费',
         0 => '待受理',
         1 => '已受理',
         2 => '已处置'
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
