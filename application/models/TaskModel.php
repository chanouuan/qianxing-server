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
            $this->cleanRatelimit();
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
            'create_time' => ['<', date('Y-m-d H:i:s', TIMESTAMP - 86400)]
        ];
        // 删除24小时后未受理的报案
        if (!$this->getDb()
            ->table('qianxing_user_report')
            ->where($condition)
            ->delete()) {
            return false;
        }
        return true;
    }

    /**
     * 清理限流记录
     * @return bool
     */
    protected function cleanRatelimit ()
    {
        return $this->getDb()
            ->table('pro_ratelimit')
            ->where(['time' => ['<', mktime(0, 0, 0, date('m', TIMESTAMP), date('d', TIMESTAMP), date('Y', TIMESTAMP))]])
            ->delete();
    }

}