<?php

namespace app\models;

use Crud;
use app\common\ReportStatus;

class UserReportModel extends Crud {

    protected $table = 'qianxing_user_report';

    /**
     * 采集用户报案信息
     * @return array
     */
    public function collectInfo (int $law_id, int $report_id)
    {
        if (!$userReport = $this->find(['id' => $report_id, 'status' => ReportStatus::WAITING], 'id,adcode,location,address,user_id,user_mobile,law_id,create_time')) {
            return error('报案信息未找到');
        }

        // 是否被其他人受理 
        if ($userReport['law_id'] > 0) {
            $lawName = current((new UserModel())->getUserNames([$userReport['law_id']]));
            return error('“' . $lawName . '”正在受理此案件');
        }

        // 锁定案件
        if (!$this->getDb()->where(['id' => $report_id, 'status' => ReportStatus::WAITING, 'law_id' => 0])->update(['law_id' => $law_id, 'status' => ReportStatus::ACCEPT, 'update_time' => date('Y-m-d H:i:s', TIMESTAMP)])) {
            return error('该案件已被其他人受理了');
        }
        
        return success($userReport);
    }

    /**
     * 获取用户报案记录
     * @return array
     */
    public function getUserReportEvents (array $post) 
    {
        $post['status']   = ReportStatus::format($post['status']);
        $post['lastpage'] = intval($post['lastpage']);

        $result = [
            'limit'    => 5,
            'lastpage' => '',
            'list'     => []
        ];

        $condition = [];
        if ($post['lastpage']) {
            $condition['id'] = ['<', $post['lastpage']];
        }
        if (!is_null($post['status'])) {
            $condition['status'] = $post['status'];
        }

        if (!$result['list'] = $this->getDb()->field('id,location,address,user_mobile,status,create_time')->where($condition)->order('id desc')->limit($result['limit'])->select()) {
            return success($result);
        }

        foreach ($result['list'] as $k => $v) {
            $result['lastpage'] = $v['id'];
            $result['list'][$k]['status_str'] = ReportStatus::getMessage($v['status']);
        }

        return success($result);
    }

    /**
     * 用户报案
     * @return array
     */
    public function reportEvent (int $user_id, array $post)
    {
        $userInfo = (new UserModel())->checkUserInfo($user_id);

        if (!$userInfo['telephone']) {
            return error('请先绑定手机号');
        }

        $post['adcode']   = intval($post['adcode']);
        $post['city']     = trim_space($post['city'], 0, 30);
        $post['district'] = trim_space($post['district'], 0, 30);
        $post['location'] = \app\library\LocationUtils::checkLocation($post['location']);
        $post['address']  = trim_space($post['address'], 0, 100);

        if (!$post['location'] || !$post['address']) {
            return error('请打开定位');
        }

        // 限制重复报案
        if ($this->count(['user_id' => $user_id, 'status' => ReportStatus::WAITING, 'create_time' => ['>', date('Y-m-d H:i:s', TIMESTAMP - 3600)]])) {
            // return error('您已报案，工作人员正在加紧审理案件，请耐心等候');
        }

        if (!$this->getDb()->insert([
            'adcode'      => $post['adcode'],
            'city'        => $post['city'],
            'district'    => $post['district'],
            'location'    => $post['location'],
            'address'     => $post['address'],
            'user_id'     => $user_id,
            'user_mobile' => $userInfo['telephone'],
            'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据保存失败');
        }

        // todo 推送消息

        return success('ok');
    }

}