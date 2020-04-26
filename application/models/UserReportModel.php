<?php

namespace app\models;

use Crud;
use app\common\ReportStatus;
use app\common\ReportType;

class UserReportModel extends Crud {

    protected $table = 'qianxing_user_report';

    /**
     * 移交报案
     * @return array
     */
    public function trunUserReport (int $user_id, array $post)
    {
        $userInfo = (new UserModel())->checkUserInfo($user_id);

        $post['report_id'] = intval($post['report_id']);
        $post['target_id'] = intval($post['target_id']); // 单位

        // 已是自己单位
        if ($userInfo['group_id'] == $post['target_id']) {
            return success('ok');
        }

        // 效验单位
        if (!(new GroupModel())->count(['id' => $post['target_id'], 'level' => 3])) {
            return error('该单位未找到');
        }

        if (!$userReport = $this->find(['id' => $post['report_id'], 'group_id' => $userInfo['group_id'], 'status' => ReportStatus::WAITING], 'user_mobile,address,report_type')) {
            return error('报案信息未找到');
        }

        if (!$this->getDb()->where(['id' => $post['report_id'], 'status' => ReportStatus::WAITING])->update([
            'group_id' => $post['target_id'],
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据更新失败');
        }

        // todo 通知单位
        (new MsgModel())->sendReportEventSms($userInfo['group_id'], $userReport);

        return success('ok');
    }

    /**
     * 采集用户报案信息
     * @return array
     */
    public function collectInfo (int $law_id, int $report_id)
    {
        if (!$userReport = $this->find(['id' => $report_id, 'status' => ReportStatus::WAITING], 'id,adcode,location,address,user_id,user_mobile,law_id,report_type,create_time')) {
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
    public function getUserReportEvents (int $user_id, array $post) 
    {
        $userInfo = (new UserModel())->checkUserInfo($user_id);

        $post['status']   = ReportStatus::format($post['status']);
        $post['lastpage'] = intval($post['lastpage']);

        $result = [
            'limit'    => 5,
            'lastpage' => '',
            'list'     => []
        ];

        $condition = [
            'group_id' => $userInfo['group_id'] // 只获取本单位
        ];
        if ($post['lastpage']) {
            $condition['id'] = ['<', $post['lastpage']];
        }
        if (!is_null($post['status'])) {
            $condition['status'] = $post['status'];
        }

        if (!$result['list'] = $this->getDb()->field('id,location,address,user_mobile,report_type,status,create_time')->where($condition)->order('id desc')->limit($result['limit'])->select()) {
            return success($result);
        }

        foreach ($result['list'] as $k => $v) {
            $result['lastpage'] = $v['id'];
            $result['list'][$k]['report_type'] = ReportType::getMessage($v['report_type']);
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

        $post['group_id'] = intval($post['group_id']);
        $post['report_type'] = ReportType::format($post['report_type']);
        $post['adcode'] = intval($post['adcode']);
        $post['city'] = trim_space($post['city'], 0, 30);
        $post['district'] = trim_space($post['district'], 0, 30);
        $post['location'] = \app\library\LocationUtils::checkLocation($post['location']);
        $post['address'] = trim_space($post['address'], 0, 100);

        if (!$post['group_id']) {
            return error('请选择执法单位');
        }
        if (!$post['location'] || !$post['address']) {
            return error('请打开定位');
        }

        // 执法单位区域
        if (!$groupInfo = (new GroupModel())->find(['id' => $post['group_id']], 'id,name,phone')) {
            return error('未找到该单位');
        }

        // 限制重复报案
        if ($this->count(['user_id' => $user_id, 'status' => ReportStatus::WAITING, 'create_time' => ['>', date('Y-m-d H:i:s', TIMESTAMP - 3600)]])) {
            return error('您已报案，工作人员正在加紧审理案件，请耐心等候');
        }

        // 防止一个地点多人报案
        if ($locations = $this->select(['group_id' => $post['group_id'], 'status' => ReportStatus::WAITING, 'create_time' => ['>', date('Y-m-d H:i:s', TIMESTAMP - 3600)]], 'location')) {
            foreach ($locations as $k => $v) {
                if (\app\library\LocationUtils::getDistance($v['location'], $post['location']) < 50) {
                    return error('有其他人已报案，工作人员正在加紧审理，请耐心等候');
                }
            }
            unset($locations);
        }

        if (!$this->getDb()->insert([
            'adcode' => $post['adcode'],
            'city' => $post['city'],
            'district' => $post['district'],
            'location' => $post['location'],
            'address' => $post['address'],
            'group_id' => $post['group_id'],
            'report_type' => $post['report_type'],
            'user_id' => $user_id,
            'user_mobile' => $userInfo['telephone'],
            'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据保存失败');
        }

        // todo 推送消息
        (new MsgModel())->sendReportEventSms($post['group_id'], [
            'user_mobile' => $userInfo['telephone'],
            'address' => $post['address'],
            'report_type' => $post['report_type']
        ]);

        return success($groupInfo);
    }

    /**
     * 删除报案
     * @return array
     */
    public function deleteReport (int $user_id, array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        if (!$this->getDb()->where(['id' => $post['report_id'], 'status' => ReportStatus::WAITING])->delete()) {
            return error('数据更新失败');
        }

        return success('ok');
    }

}