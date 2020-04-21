<?php

namespace app\models;

use Crud;

class TaskModel extends Crud {

    /**
     * 任务计划
     * @return array
     */
    public function crond ($timer)
    {
        // 每天 0 点执行
        if (false !== strpos($timer, '0h')) {
            $this->clearUserReport();
        }
        // 每天 1 点执行
        if (false !== strpos($timer, '1h')) {
        }
        // 每天 2 点执行
        if (false !== strpos($timer, '2h')) {
        }
        // 每 300 秒执行
        if (false !== strpos($timer, '300s')) {
        }
        // 每 600 秒执行
        if (false !== strpos($timer, '600s')) {
        }
        // 每 3600 秒执行
        if (false !== strpos($timer, '3600s')) {
        }
        return success(date('Y-m-d H:i:s', TIMESTAMP));
    }

    /**
     * 清理用户报案
     * @return bool
     */
    public function clearUserReport ()
    {
        $condition = [
            'status' => \app\common\ReportStatus::WAITING,
            'create_time' => ['<', date('Y-m-d H:i:s', TIMESTAMP - 86400)]
        ];
        $list = $this->getDb()
            ->table('qianxing_user_report')
            ->field('group_id,count(*) as count')
            ->where($condition)
            ->group('group_id')
            ->select();
        if (!$list) {
            return false;
        }

        // 删除24小时后未受理的报案
        if (!$this->getDb()
            ->table('qianxing_user_report')
            ->where($condition)
            ->delete()) {
            return false;
        }

        // 更新统计数
        $userCountModel = new UserCountModel();
        $userModel = new UserModel();
        foreach ($list as $k => $v) {
            $users = $userModel->select(['group_id' => $v['group_id']], 'id');
            if ($users) {
                $users = array_column($users, 'id');
                $userCountModel->updateSet($users, ['report_count' => ['if(report_count>' . $v['count'] . ',report_count-' . $v['count'] . ',0)']]);
            }
        }

        return true;
    }

}