<?php

namespace app\library;

class DataEncrypt {

    /**
     * 生成每次请求的sign
     * @return string
     */
    public static function setSign(array $data)
    {
        if (!isset($data['time'])) {
            $data['time'] = MICROTIME;
        }
        if (!isset($data['nonce_str'])) {
            $data['nonce_str'] = str_shuffle('abc0123456789');
        }

        // 加密秘钥
        $app_secret = strval(getSysConfig('app_secret'));

        // 去掉签名
        unset($data['sig']);

        // 按key排序
        ksort($data);
        // 拼接参数值与密钥，做md5加密
        $data['sig'] = md5(implode('', $data) . $app_secret);

        return $data;
    }

    /**
     * 检查sign
     * @return boolen
     */
    public static function checkSign(array $data)
    {
        // 参数校验
        if (empty($data)) {
            return success([]);
        }

        // 验签
        $sig = $data['sig'];
        if (empty($sig) || empty($data['time']) || empty($data['nonce_str'])) {
            return error(null, \StatusCodes::getMessage(\StatusCodes::SIG_ERROR), \StatusCodes::SIG_ERROR);
        }
        $data = self::setSign($data);
        if ($sig != $data['sig']) {
            return error(null, \StatusCodes::getMessage(\StatusCodes::SIG_ERROR), \StatusCodes::SIG_ERROR);
        }

        // 时间效验
        $auth_expire_time = getSysConfig('auth_expire_time');
        if ($auth_expire_time && abs(TIMESTAMP - $data['time']) > $auth_expire_time) {
            return error(null, \StatusCodes::getMessage(\StatusCodes::SIG_EXPIRE), \StatusCodes::SIG_EXPIRE);
        }

        return success('ok');
    }

    /**
     * 加密数据
     * @return string
     */
    public static function encode (string $input, string $version = '')
    {
        $key = getSysConfig('app_secret');
        $key = $key ? $key : '#1234567890#';
        $key = md5($key);
        $version = 'authcode' . ($version ? $version : 'v2');
        return self::urlsafe_b64encode(self::{$version}('ENCODE', $input, $key));
    }

    /**
     * 解密数据
     * @return string
     */
    public static function decode (string $input, string $version = '')
    {
        $key = getSysConfig('app_secret');
        $key = $key ? $key : '#1234567890#';
        $key = md5($key);
        $version = 'authcode' . ($version ? $version : 'v2');
        return self::{$version}('DECODE', self::urlsafe_b64decode($input), $key);
    }

    /**
     * 加解密 v2
     * @return string
     */
    protected static function authcodev2 ($operation, $string, $key)
    {
        if ($operation === 'DECODE') {
            // 解密
            $sub = substr($string, 0, 16);
            $text = substr($string, 16);
            if ($sub !== substr(md5($text . $key), 8, 16)) {
                return '';
            }
            return $text;
        }
        return substr(md5($string . $key), 8, 16) . $string;
    }

    /**
     * 加解密 v1
     * @return string
     */
    protected static function authcodev1 ($operation, $string, $key)
    {
        if ($operation == 'DECODE') {
            $slen = strlen($string);
            $klen = strlen($key);
            $plain = '';
            for ($i = 0; $i < $slen; $i = $i + $klen) {
                $plain .= $key ^ substr($string, $i, $klen);
            }
            $plain = explode('!', $plain);
            return strlen($plain[0]) == $plain[1] ? $plain[0] : null;
        }
        $slen = strlen($string);
        $string .= '!' . $slen;
        $slen = strlen($string);
        $klen = strlen($key);
        $cipher = '';
        for ($i = 0; $i < $slen; $i = $i + $klen) {
            $cipher .= substr($string, $i, $klen) ^ $key;
        }
        return $cipher;
    }

    /**
     * base64url
     * @return string
     */
    protected static function urlsafe_b64encode ($text)
    {
        return substr(str_shuffle('ABCDEFG'), 0, 1) . strtr(base64_encode($text), [
            '+' => '-',
            '/' => '_',
            '=' => '!'
        ]);
    }
    
    /**
     * base64url
     * @return string
     */
    protected static function urlsafe_b64decode ($text)
    {
        return base64_decode(strtr(substr($text, 1), [
            '-' => '+',
            '_' => '/',
            '!' => '='
        ]));
    }

}
