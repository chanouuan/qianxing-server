<?php

namespace app\controllers;

use ActionPDO;

/**
 * 后台服务端接口
 * @Version v1
 */
class Adminserver extends ActionPDO {

    public function _ratelimit ()
    {
        return [
            'login'          => ['interval' => 1000],
            'getUserProfile' => ['interval' => 1000],
            'reportFile'     => ['interval' => 1000],
            'reportPayCash'  => ['interval' => 1000]
        ];
    }

    public function _init()
    {
        if ($this->_G['user']) {
            // 获取权限
            $permissions = isset($this->_G['token'][4]) ? explode('^', $this->_G['token'][4]) : [];
            $permissions = \app\common\GenerateCache::mapPermissions($permissions);
            // 忽略列表
            $ignoreAccess = [
                'getUserProfile',
                'feedback'
            ];
            // 重命名
            $map = [
                'getReportList' => 'report',
                'getPeopleList' => 'people',
                'getRoleList' => 'people',
            ];
            // 权限值
            $action = isset($map[$this->_action]) ? $map[$this->_action] : $this->_action;
            // 权限验证
            if (!in_array($action, $ignoreAccess)) {
                if (empty(array_intersect(['ANY', $action], $permissions))) {
                    json(null,'权限不足', 100);
                }
            }
        }
    }

    /**
     * 登录
     * @param *username 手机号/账号
     * @param *password 密码
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":{
     *     "user_id":1,
     *     "avatar":"", //头像
     *     "telephone":"", //手机号
     *     "nick_name":"", //昵称
     *     "token":"", //登录凭证
     *     "permission":"" //权限
     * }}
     */
    public function login ()
    {
        return (new \app\models\AdminModel())->login([
            'username' => $_POST['username'],
            'password' => $_POST['password']
        ]);
    }

    /**
     * 获取登录用户信息
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":{}
     * }
     */
    public function getLoginProfile ()
    {
        $result = (new \app\models\AdminModel())->getLoginProfile($this->_G['user']['user_id']);
        if ($result['errorcode'] === 0) {
            $permissions = isset($this->_G['token'][4]) ? explode('^', $this->_G['token'][4]) : [];
            $permissions = \app\common\GenerateCache::mapPermissions($permissions);
            $result['data']['permission'] = $permissions;
            $s = strlen(implode('', $permissions));
            $result['data']['s'] = $s * 2 + $s % 10 + 127;
        }
        return $result;
    }

    /**
     * 首页统计
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "result":{}
     * }
     */
    public function indexCount ()
    {
        $userInfo = (new \app\models\AdminModel())->checkAdminInfo($this->_G['user']['user_id']);

        // 今日报警数
        $today_bj = \app\library\DB::getInstance()
            ->table('qianxing_report')
            ->where([
                'group_id' => $userInfo['group_id'],
                'create_time' => ['>="' . date('Y-m-d', TIMESTAMP) . '"']
            ])->count();

        // 累计案件数
        $total_bj = \app\library\DB::getInstance()
            ->table('qianxing_report')
            ->where([
                'group_id' => $userInfo['group_id']
            ])->count();

        // 今年案件处置数(按月)
        $data = \app\library\DB::getInstance()
            ->table('qianxing_report')
            ->field('status,left(create_time, 7) as date,count(*) as count')
            ->where([
                'group_id' => $userInfo['group_id'],
                'create_time' => ['>="' . date('Y-1-1', TIMESTAMP) . '"']
            ])
            ->group('status,date')
            ->select();
        $dataset = [];
        foreach ($data as $k => $v) {
            $dataset[$v['status']][$v['date']] = $v['count'];
        }
        $date = [];
        for ($i = 1; $i <= 12; $i ++) {
            $date[] = date('Y-' . ($i < 10 ? '0' . $i : $i), TIMESTAMP);
        }
        $line = [
            $date, []
        ];
        foreach ($line[0] as $k => $v) {
            $line[0][$k] = intval(substr($v, 5, 2)) . '月';
        }
        foreach ($dataset as $k => $v) {
            $set = [];
            foreach ($date as $vv) {
                $set[] = isset($v[$vv]) ? $v[$vv] : 0;
            }
            $line[1][\app\common\ReportStatus::getMessage($k)] = $set;
        }
        unset($data, $date, $dataset);
        return success([
            [$today_bj, 0, 0, $total_bj],
            $line
        ]);
    }

    /**
     * 获取案件列表
     * @login
     * @param page 当前页
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "result":{
     *     "total":1,
     *     "list":[{}]
     * }}
     */
    public function getReportList ()
    {
        return (new \app\models\AdminReportModel($this->_G['user']['user_id']))->getReportList($_POST);
    }

    /**
     * 获取案件详情
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "result":{
     * }}
     */
    public function getReportDetail ()
    {
        return (new \app\models\AdminReportModel($this->_G['user']['user_id']))->getReportDetail($_POST);
    }

    /**
     * 转发赔偿通知书
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function reportFile ()
    {
        return (new \app\models\AdminReportModel($this->_G['user']['user_id']))->reportFile($_POST);
    }

    /**
     * 代收现金
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function reportPayCash ()
    {
        return (new \app\models\AdminReportModel($this->_G['user']['user_id']))->reportPayCash($_POST);
    }

    /**
     * 生成卷宗
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function createArchive ()
    {
        return (new \app\models\AdminReportModel($this->_G['user']['user_id']))->createArchive($_POST);
    }

    /**
     * 获取人员列表
     * @login
     * @param page 当前页
     * @param name 名称
     * @param status 状态
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":{
     *     "total":1, //总条数
     *     "list":[]
     * }}
     */
    public function getPeopleList ()
    {
        return (new \app\models\AdminModel())->getPeopleList($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 添加人员
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function savePeople ()
    {
        return (new \app\models\AdminModel())->savePeople($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 获取人员信息
     * @param id
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function getPeopleInfo ()
    {
        return success((new \app\models\AdminModel())->getPeopleInfo(getgpc('id')));
    }

    /**
     * 获取人员角色
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function getPeopleRole ()
    {
        return success((new \app\models\AdminModel())->getPeopleRole($this->_G['user']['user_id']));
    }

    /**
     * 获取角色列表
     * @login
     * @param page 当前页
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":{
     *     "total":1, //总条数
     *     "list":[]
     * }}
     */
    public function getRoleList ()
    {
        return (new \app\models\AdminModel())->getRoleList($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 添加角色
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function saveRole ()
    {
        return (new \app\models\AdminModel())->saveRole($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 查看角色
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function viewRole ()
    {
        return (new \app\models\AdminModel())->viewRole(getgpc('id'));
    }

    /**
     * 查看权限
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function viewPermissions ()
    {
        return (new \app\models\AdminModel())->viewPermissions();
    }

    /**
     * 意见反馈
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function feedback ()
    {
        return (new \app\models\FeedbackModel())->feedback($this->_G['user']['user_id'], $_POST);
    }

}
