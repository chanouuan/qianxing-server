<?php
/**
 * 交易单来源
 */

namespace app\common;

class TradeSource
{

    const REPORT = 1;

    static $message = [
        1 => '报案'
    ];

    /**
     * 获取支付用途
     * @return string
     */
    public static function getUseName ($code)
    {
        if ($code == self::REPORT) {
            return '高速公路路产损坏赔偿金';
        }
        return '';
    }

    /**
     * 获取来源实例
     * @return mixed
     */
    public static function getInstanceModel ($code)
    {
        if ($code == self::REPORT) {
            return new \app\models\ReportModel();
        }
        return null;
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
