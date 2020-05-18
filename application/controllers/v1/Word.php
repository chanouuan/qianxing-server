<?php

namespace app\controllers;

use ActionPDO;

class Word extends ActionPDO {

    public function _ratelimit ()
    {
        return [
            'paynote'  => ['interval' => 5000],
            'itemnote' => ['interval' => 5000],
            'allnote'  => ['interval' => 5000]
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
            // 用户端
            if (!\app\library\DB::getInstance()->table('qianxing_report_person')->field('id')->where(['report_id' => $condition['id'], 'user_id' => $this->_G['user']['user_id']])->find()) {
                return error('案件不存在');
            }
            $condition = [
                'report' => $condition,
                'person' => [
                    'user_id' => $this->_G['user']['user_id']
                ]
            ];
            // 当事人的赔偿通知书不同
            $fileName = [$this->_action . $this->_G['user']['user_id'], $this->_action];
        } else {
            $userInfo = (new \app\models\AdminModel())->checkAdminInfo($this->_G['user']['user_id']);
            $condition['group_id'] = $userInfo['group_id'];
            $fileName = $this->_action;
        }
        return (new \app\models\WordModel())->createNote($fileName, $condition, 'pdf');
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
        $condition = [
            'id' => intval($_POST['report_id']),
            'status' => \app\common\ReportStatus::COMPLETE
        ];
        if ($this->_G['user']['clienttype'] == 'mp') {
            $condition['law_id'] = $this->_G['user']['user_id'];
        } else {
            $userInfo = (new \app\models\AdminModel())->checkAdminInfo($this->_G['user']['user_id']);
            $condition['group_id'] = $userInfo['group_id'];
        }
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
