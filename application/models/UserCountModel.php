<?php

namespace app\models;

use Crud;
use app\library\LocationUtils;

class UserCountModel extends Crud {

    protected $table = 'qianxing_user_count';
    
    /**
     * 更新 report_count 字段
     * @return array
     */
    public function setReportCount ($op, $group_id, $user_id = null, $old_group_id = null, $old_user_id = null)
    {
        $groupModel = new GroupModel();
        $userModel = new UserModel();

        if ($op == 'new') {
            $users = $userModel->select(['group_id' => $group_id], 'id');
            if ($users) {
                $users = array_column($users, 'id');
                $this->updateSet($users, ['report_count' => ['report_count+1']]);
            }
        } else if ($op == 'old') {
            if ($group_id) {
                $users = $userModel->select(['group_id' => $group_id], 'id');
                if ($users) {
                    $users = array_column($users, 'id');
                    $this->updateSet($users, ['report_count' => ['report_count+1']]);
                }
            }
            if ($old_group_id) {
                $users = $userModel->select(['group_id' => $old_group_id], 'id');
                if ($users) {
                    $users = array_column($users, 'id');
                    $this->updateSet($users, ['report_count' => ['if(report_count>0,report_count-1,0)']]);
                }
            }
            if ($user_id) {
                $this->updateSet($user_id, ['report_count' => ['report_count+1']]);
            }
            if ($old_user_id) {
                $this->updateSet($old_user_id, ['report_count' => ['if(report_count>0,report_count-1,0)']]);
            }
        } else if ($op == 'accept') {
            $users = $userModel->select(['group_id' => $group_id, 'id' => ['<>', $user_id]], 'id');
            if ($users) {
                $users = array_column($users, 'id');
                $this->updateSet($users, ['report_count' => ['if(report_count>0,report_count-1,0)']]);
            }
        } else if ($op == 'complete') {
            $this->updateSet($user_id, ['report_count' => ['if(report_count>0,report_count-1,0)']]);
        }
    }

    /**
     * 获取用户信息数
     * @return array
     */
    public function loadInfo (int $user_id)
    {
        $info = $this->find(['id' => $user_id]);
        return success($info);
    }

    /**
     * 更新用户统计数
     * @return bool
     */
    public function updateSet ($user_id, array $data)
    {
        return $this->getDb()->where(['id' => is_array($user_id) ? ['in', $user_id] : $user_id])->update($data);
    }

    /**
     * 更新全市排名
     * @return mixed
     */
    public function updateCityRank (array $user_id, int $group_id)
    {
        $user_id = array_filter($user_id);
        $groupModel = new GroupModel();
        $userModel = new UserModel();
        // 获取全市用户
        $groups = $groupModel->find(['id' => $group_id], 'parent_id');
        $groups = $groupModel->select(['parent_id' => $groups['parent_id']], 'id');
        $groups = array_column($groups, 'id');
        $users = $userModel->select(['group_id' => ['in', $groups]], 'id');
        $users = array_column($users, 'id');
        // 排名
        $users = $this->select(['id' => ['in', $users]], 'id', '(case_count+illegal_count+latent_count) desc,patrol_km desc');
        $users = array_column($users, 'id');
        foreach ($user_id as $k => $v) {
            $rank = array_search($v, $users);
            $rank = false === $rank ? 0 : $rank + 1;
            $this->updateSet($v, ['city_rank' => ['city_rank+' . $rank]]);
        }
        unset($users);
    }
    
}