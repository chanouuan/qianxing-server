<?php

namespace app\models;

use Crud;
use app\common\Gender;

class AdminModel extends Crud {

    protected $table = 'admin_user';

    /**
     * 获取用户信息
     * @return array
     */
    public function getUserInfo ($user_id, $field = null)
    {
        if (!$user_id) {
            return [];
        }
        $field = $field ? $field : 'id,avatar,user_name,full_name,telephone,title,group_id,status';
        if (!$userInfo = $this->find(is_array($user_id) ? $user_id : ['id' => $user_id], $field)) {
            return [];
        }
        if (isset($userInfo['avatar'])) {
            $userInfo['avatar'] = httpurl($userInfo['avatar']);
        }
        if (isset($userInfo['user_name'])) {
            $userInfo['nick_name'] = get_real_val($userInfo['full_name'], $userInfo['user_name'], $userInfo['telephone']);
        }
        return $userInfo;
    }

}