<?php

namespace app\library;

class Idcard {

    /**
     * 获取身份证生日
     */
    public static function parseidcard_getbirth ($idcard)
    {
        $birthday = strlen($idcard) == 15 ? ('19' . substr($idcard, 6, 6)) : substr($idcard, 6, 8);
        return substr($birthday, 0, 4) . '-' . substr($birthday, 4, 2) . '-' . substr($birthday, 6, 2);
    }

    /**
     * 获取省份证性别 男1 女2
     */
    public static function parseidcard_getsex ($idcard)
    {
        if (strlen($idcard) == 15) {
            $idcard = self::idcard_15to18($idcard);
        }
        $sexint = (int) substr($idcard, 16, 1);
        return $sexint % 2 === 0 ? 2 : 1;
    }

    /**
     * 身份证验证
     * @param $idcard
     * @return boolean
     */
    public static function check_id ($idcard)
    {
        if (!$idcard) {
            return false;
        }
        if (strlen($idcard) == 15 || strlen($idcard) == 18) {
            if (strlen($idcard) == 15) {
                $idcard = self::idcard_15to18($idcard);
            }
            if (self::idcard_checksum18($idcard)) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    protected static function idcard_checksum18 ($idcard)
    {
        if (strlen($idcard) != 18) {
            return false;
        }
        $idcard_base = substr($idcard, 0, 17);
        if (self::idcard_verify_number($idcard_base) != strtoupper(substr($idcard, 17, 1))) {
            return false;
        } else {
            return true;
        }
    }

    protected static function idcard_15to18 ($idcard)
    {
        if (strlen($idcard) != 15) {
            return false;
        } else {
            if (array_search(substr($idcard, 12, 3), array(
                    '996', '997', '998', '999'
                )) !== false) {
                $idcard = substr($idcard, 0, 6) . '18' . substr($idcard, 6, 9);
            } else {
                $idcard = substr($idcard, 0, 6) . '19' . substr($idcard, 6, 9);
            }
        }
        $idcard = $idcard . self::idcard_verify_number($idcard);
        return $idcard;
    }

    protected static function idcard_verify_number ($idcard_base)
    {
        if (strlen($idcard_base) != 17) {
            return false;
        }
        $factor = array(
            7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2
        );
        $verify_number_list = array(
            '1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'
        );
        $checksum = 0;
        for ($i = 0; $i < strlen($idcard_base); $i++) {
            $checksum += substr($idcard_base, $i, 1) * $factor[$i];
        }
        $mod = $checksum % 11;
        $verify_number = $verify_number_list[$mod];
        return $verify_number;
    }

}
