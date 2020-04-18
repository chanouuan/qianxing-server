<?php

namespace app\controllers;

use ActionPDO;

class Word extends ActionPDO {

    public function _ratelimit ()
    {
        return [
            'paynote'  => ['interval' => 2000],
            'itemnote' => ['interval' => 2000]
        ];
    }

    public function _init ()
    {

    }

    /**
     * 赔偿通知书
     * @login
     * @return mixed
     */
    public function paynote ()
    {
        $condition = [
            'id' => intval($_POST['report_id']),
            'status' => ['>', \app\common\ReportStatus::ACCEPT]
        ];
        if ($this->_G['user']['clienttype'] == 'mp') {
            $condition['user_id'] = $this->_G['user']['user_id'];
        } else {
            $userInfo = (new \app\models\AdminModel())->checkAdminInfo($this->_G['user']['user_id']);
            $condition['group_id'] = $userInfo['group_id'];
        }
        return (new \app\models\WordModel())->createNote($this->_action, $condition, 'pdf');
    }

    /**
     * 勘验笔录
     * @login
     * @return mixed
     */
    public function itemnote ()
    {
        $userInfo = (new \app\models\AdminModel())->checkAdminInfo($this->_G['user']['user_id']);
        $condition = [
            'id' => intval($_POST['report_id']),
            'group_id' => $userInfo['group_id']
        ];
        return (new \app\models\WordModel())->createNote($this->_action, $condition, 'pdf', true);
    }

    /**
     * 卷宗
     * @login
     * @return mixed
     */
    public function allnote ()
    {
        $userInfo = (new \app\models\AdminModel())->checkAdminInfo($this->_G['user']['user_id']);
        $condition = [
            'id' => intval($_POST['report_id']),
            'group_id' => $userInfo['group_id'],
            'status' => \app\common\ReportStatus::COMPLETE
        ];
        return (new \app\models\WordModel())->createNote($this->_action, $condition);
    }

    private function viewKeys ($data)
    {
        // print_r($templateProcessor->getVariables());
        $result = [];
        foreach ($data as $k => $v) {
            foreach ($v as $kk => $vv) {
                $result[] = '${' . $kk . '} = ' . (is_array($vv) ? json_encode($vv) : $vv);
            }
            $result[] = '';
        }
        $result = implode("\r\n", $result);
        exit($result);
    }

}
