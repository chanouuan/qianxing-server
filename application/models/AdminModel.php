<?php

namespace app\models;

use Crud;
use app\common\Gender;

class AdminModel extends Crud {

    protected $table = 'admin_user';

    /**
     * 获取执法证号-根据 user_id
     * @return array
     */
    public function getLawNumByUser (array $user_id)
    {
        if (!$user_id = array_unique(array_filter($user_id))) {
            return [];
        }
        // 获取用户手机号
        if (!$userInfo = (new UserModel())->select(['id' => ['in', $user_id]], 'id,telephone')) {
            return [];
        }
        $userInfo = array_column($userInfo, 'telephone', 'id');
        if (!$adminInfo = $this->select(['telephone' => ['in', $userInfo]], 'telephone,law_num')) {
            return [];
        }
        // 获取管理员执法证号，通过手机号关联
        $adminInfo = array_column($adminInfo, 'law_num', 'telephone');
        foreach ($userInfo as $k => $v) {
            $userInfo[$k] = isset($adminInfo[$v]) ? $adminInfo[$v] : '';
        }
        unset($adminInfo);
        return $userInfo;
    }

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