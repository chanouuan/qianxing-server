<?php

namespace app\models;

use Crud;
use app\library\Idcard;
use app\common\Gender;
use app\common\GenerateCache;
use app\common\CommonStatus;
use app\common\PayWay;
use app\common\ReportStatus;
use app\common\Weather;
use app\common\CarType;
use app\common\EventType;
use app\common\DriverState;
use app\common\CarState;
use app\common\TrafficState;

class ReportModel extends Crud {

    protected $table = 'qianxing_report';
    protected $userInfo = null;

    public function __construct (int $user_id = 0)
    {
        if ($user_id) {
            $this->userInfo = (new UserModel())->checkUserInfo($user_id);
            if (!$this->userInfo['group_id']) {
                return error('你不是执法人员');
            }
        }
    }

    /**
     * 验证协同人员，获取案件信息
     * @return array
     */
    private function getMutiInfo (array $condition, $field)
    {
        if (!$this->userInfo) {
            return false;
        }
        $condition['group_id'] = $this->userInfo['group_id'];
        $condition['law_id'] = ['=', $this->userInfo['id'], 'and ('];
        $condition['colleague_id'] = ['=', $this->userInfo['id'], 'or', ')'];
        $condition['status'] = $condition['status'] ? $condition['status'] : ['in(' . ReportStatus::ACCEPT . ',' . ReportStatus::HANDLED . ')'];
        return $this->find($condition, $field);
    }

    /**
     * 恢复畅通
     * @return array
     */
    public function recoverPass (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['recover_time'] = $post['recover_time'] && strtotime($post['recover_time']) ? $post['recover_time'] : date('Y-m-d H:i:00', TIMESTAMP);
        
        $condition = [
            'id' => $post['report_id'],
            'recover_time' => null,
            'status' => ['in', [ReportStatus::ACCEPT, ReportStatus::HANDLED, ReportStatus::COMPLETE]]
        ];
        if (!$reportData = $this->getMutiInfo($condition, 'is_property')) {
            return error('未找到案件');
        }

        $data = [
            'recover_time' => $post['recover_time']
        ];

        if ($reportData['is_property'] == 0) {
            // 未路产受损
            $condition['total_money'] = 0;
            $condition['status'] = ReportStatus::ACCEPT;
            $data['status'] = ReportStatus::COMPLETE;
            $data['complete_time'] = date('Y-m-d H:i:s', TIMESTAMP);
        }

        if (false === $this->getDb()->where($condition)->update($data)) {
            return error('无效案件');
        }

        if ($reportData['is_property'] == 0) {
            // 未路产受损,恢复畅通后直接结案
            $this->reportCompleteCall($post['report_id']);
        }

        return success('ok');
    }

    /**
     * 移交案件
     * @return array
     */
    public function trunReport (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['target_id'] = intval($post['target_id']); // 执法人员

        // 已是自己
        if ($this->userInfo['id'] == $post['target_id']) {
            return success('ok');
        }

        // 同部门
        if (!$targetUser = (new UserModel())->find(['id' => $post['target_id'], 'group_id' => $this->userInfo['group_id']], 'telephone')) {
            return error('该移交人不在同部门');
        }

        if (!$reportData = $this->find(['id' => $post['report_id'], 'is_load' => 0, 'group_id' => $this->userInfo['group_id'], 'status' => ReportStatus::ACCEPT], 'law_id,user_mobile')) {
            return error('案件信息未找到');
        }

        if (!$this->getDb()->where(['id' => $post['report_id'], 'status' => ReportStatus::ACCEPT])->update([
            'law_id' => $post['target_id'],
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据更新失败');
        }

        // 更新统计数
        (new UserCountModel())->setReportCount($post['target_id'], $reportData['law_id']);

        // todo 通知移交人
        (new MsgModel())->sendReportAcceptSms($targetUser['telephone'], $reportData['user_mobile']);

        return success('ok');
    }

    /**
     * 生成交易单的回调函数
     * @return array
     */
    public function createPay (int $user_id, array $post)
    {
        if (!$reportData = $this->find(['id' => $post['order_id'], 'user_id' => $user_id, 'status' => ReportStatus::HANDLED], 'id,total_money,pay,cash')) {
            return error('案件未找到');
        }

        return success([
            'pay' => $reportData['total_money'] - $reportData['pay'] - $reportData['cash'],
            'money' => $reportData['total_money']
        ]);
    }

    /**
     * 支付成功的回调函数
     * @return bool
     */
    public function paySuccess (array $trade)
    {
        if (!$this->getDb()->where(['id' => $trade['order_id'], 'status' => ReportStatus::HANDLED])->update([
            'status' => ['if(pay+cash+'.$trade['pay'].'=total_money,'.ReportStatus::COMPLETE.',status)'],
            'pay' => ['if(pay+cash+'.$trade['pay'].'>total_money,pay,pay+'.$trade['pay'].')'],
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP),
            'complete_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return false;
        }
        return true;
    }

    /**
     * 支付完成的回调函数
     * @return mixed
     */
    public function payComplete (array $trade)
    {
        if (!$reportData = $this->find(['id' => $post['order_id']], 'id,user_id,law_id,status')) {
            return false;
        }

        // todo 通知路政执法人员
    }

    /**
     * 案件处置完成通知
     * @return bool
     */
    public function reportCompleteCall ($report_id)
    {
        if (!$reportData = $this->find(['id' => $report_id, 'status' => ReportStatus::COMPLETE], 'id,group_id,law_id,user_mobile,colleague_id,location,user_id,is_property')) {
            return false;
        }

        // 更新统计数
        $userCountModel = new UserCountModel();
        $userCountModel->updateSet([$reportData['law_id'], $reportData['colleague_id']], [
            'case_count' => ['case_count+1'],
            'patrol_km' => ['patrol_km+' . (new GroupModel())->getDistance($reportData['group_id'], $reportData['location'])]
        ]);
        $userCountModel->updateCityRank([$reportData['law_id'], $reportData['colleague_id']], $reportData['group_id']);
        $userCountModel->setReportCount(null, array_filter([$reportData['law_id'], $reportData['colleague_id']]));

        // 推送通知给路政
        if ($reportData['is_property']) {
            // 有路产受损
            (new MsgModel())->sendReportCompleteSms([$reportData['law_id'], $reportData['colleague_id']], $reportData['id'], $reportData['group_id']);
        }
        
        return true;
    }

    /**
     * 获取赔偿清单
     * @return array
     */
    public function getPropertyPayItems (int $user_id, array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        if (!$reportData = $this->find(['id' => $post['report_id'], 'user_id' => $user_id], 'id,total_money,pay,cash')) {
            return error('案件未找到');
        }

        if (!$list = $this->getDb()->field('id,name,unit,amount,total_money')->table('qianxing_report_item')->where(['report_id' => $reportData['id']])->select()) {
            return success([
                'items' => [],
                'total_money' => round_dollar($reportData['total_money']),
                'pay' => round_dollar($reportData['pay']),
                'cash' => round_dollar($reportData['cash'])
            ]);
        }

        foreach ($list as $k => $v) {
            $list[$k]['total_money'] = round_dollar($v['total_money']);
        }

        return success([
            'items' => $list,
            'total_money' => round_dollar($reportData['total_money']),
            'pay' => round_dollar($reportData['pay']),
            'cash' => round_dollar($reportData['cash'])
        ]);
    }

    /**
     * 转发赔偿通知书
     * @return array
     */
    public function reportFile (array $post, array $adminCondition = [])
    {
        $post['report_id'] = intval($post['report_id']);
        $post['archive_num'] = trim_space($post['archive_num'], 0, 20); // 卷宗号

        $condition = [
            'id' => $post['report_id'],
            'is_load' => 1, // 已处置完
            'is_property' => 1, // 有路产损失
            'status' => ['in', [ReportStatus::ACCEPT, ReportStatus::HANDLED]]
        ];
        if ($adminCondition) {
            $condition += $adminCondition;
        }

        if (!$reportData = $this->find($condition, 'id,group_id,user_mobile,total_money')) {
            return error('案件未找到');
        }

        if (!$this->getDb()->where(['id' => $reportData['id'], 'status' => ['in', [ReportStatus::ACCEPT, ReportStatus::HANDLED]]])->update([
            'status' => ReportStatus::HANDLED,
            'handle_time' => date('Y-m-d H:i:s', TIMESTAMP),
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据保存失败');
        }

        if ($post['archive_num']) {
            // 填写卷宗号
            $this->getDb()->table('qianxing_report_info')
                 ->where(['id' => $reportData['id']])
                 ->update(['archive_num' => $post['archive_num']]);
        }
        
        // 删除赔偿通知书
        (new WordModel())->removeDocFile($reportData['id'], 'paynote');

        // todo 通知用户
        if ($reportData['total_money'] > 0) {
            (new MsgModel())->sendReportPaySms($reportData['user_mobile'], $reportData['group_id'], $reportData['id']);
        }
        
        return success('ok');
    }

    /**
     * 保存勘验笔录
     * @return array
     */
    public function reportItem (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['items'] = $post['items'] ? array_slice(json_decode(htmlspecialchars_decode($post['items']), true), 0, 100) : [];
        $post['involved_action'] = $post['involved_action'] ? array_slice(json_decode(htmlspecialchars_decode($post['involved_action']), true), 0, 10) : [];
        $post['involved_action_type'] = $post['involved_action_type'] ? array_slice(json_decode(htmlspecialchars_decode($post['involved_action_type']), true), 0, 3) : [];
        $post['involved_build_project'] = trim_space($post['involved_build_project'], 0, 200);
        $post['involved_act'] = trim_space($post['involved_act'], 0, 200);
        $post['extra_info'] = trim_space($post['extra_info'], 0, 200);

        if (!$this->getMutiInfo(['id' => $post['report_id']], 'id')) {
            return error('案件未找到');
        }

        // 检查赔付清单
        foreach ($post['items'] as $k => $v) {
            $post['items'][$k]['property_id'] = intval($v['property_id']);
            $post['items'][$k]['name'] = trim_space($v['name'], 0, 50);
            $post['items'][$k]['price'] = intval(floatval($v['price']) * 100); // 转成分
            $post['items'][$k]['amount'] = intval($v['amount']);
            $post['items'][$k]['unit'] = trim_space($v['unit'], 0, 20);
            if (!$post['items'][$k]['property_id'] || !$post['items'][$k]['name'] || $post['items'][$k]['price'] < 0 || $post['items'][$k]['amount'] <= 0) {
                unset($post['items'][$k]);
            }
        }

        $items = [];
        if ($post['items']) {
            foreach ($post['items'] as $k => $v) {
                $items[] = [
                    'report_id' => $post['report_id'],
                    'property_id' => $v['property_id'],
                    'unit' => $v['unit'],
                    'name' => $v['name'],
                    'price' => $v['price'],
                    'amount' => $v['amount'],
                    'total_money' => $v['price'] * $v['amount']
                ];
            }
        }

        // 总金额
        $total_money = array_sum(array_column($items, 'total_money'));

        if ($total_money > 15000000) {
            return error('总金额最高不超过15万元');
        }

        if (!$this->getDb()->transaction(function ($db) use ($post, $items, $total_money) {
            // 更新赔付金额
            if (!$this->getDb()->where(['id' => $post['report_id'], 'status' => ['in', [ReportStatus::ACCEPT, ReportStatus::HANDLED]]])->update([
                'total_money' => $total_money,
                'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
            ])) {
                return false;
            }
            if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update([
                'involved_action' => json_encode($post['involved_action']),
                'involved_action_type' => json_encode($post['involved_action_type']),
                'involved_build_project' => $post['involved_build_project'],
                'involved_act' => $post['involved_act'],
                'extra_info' => $post['extra_info']
            ])) {
                return false;
            }
            // 更新赔付清单
            if (false === $this->getDb()->table('qianxing_report_item')->where(['report_id' => $post['report_id']])->delete()) {
                return false;
            }
            if ($items) {
                if (!$this->getDb()->table('qianxing_report_item')->insert($items)) {
                    return false;
                }
            }
            return true;
        })) {
            return error('保存数据失败');
        }

        return success('ok');
    }

    /**
     * 获取未关联当事人的案件信息
     * @return array
     */
    public function getDerelictCase ($telephone)
    {
        $reportData = $this->find(['user_id' => 0, 'user_mobile' => $telephone], 'id', 'id desc');
        if ($reportData) {
            $reportInfo = $this->getDb()->table('qianxing_report_info')->field('full_name,idcard')->where(['id' => $reportData['id']])->find();
            return array_filter($reportInfo);
        }
        return [];
    }

    /**
     * 关联当事人
     * @return array
     */
    public function relationCase ($user_id, $telephone)
    {
        return $this->getDb()->where(['user_id' => 0, 'user_mobile' => $telephone])->update(['user_id' => $user_id]);
    }

    /**
     * 上传文件
     * @return array
     */
    public function upload (array $post) 
    {
        $post['report_id'] = intval($post['report_id']);
        $post['report_field'] = trim_space($post['report_field'], 0, 22);
        $post['report_field_index'] = intval($post['report_field_index']);

        // 图片识别信息
        $post['addr'] = trim_space($post['addr'], 0, 50); // 住址
        $post['name'] = trim_space($post['name'], 0, 20); // 姓名
        $post['idcard'] = Idcard::check_id($post['idcard']) ? $post['idcard'] : null; // 身份证号
        if ($post['idcard']) {
            $post['gender'] = Idcard::parseidcard_getsex($post['idcard']);
            $post['birthday'] = Idcard::parseidcard_getbirth($post['idcard']);
        }
        $post['plate_num'] = check_car_license($post['plate_num']) ? $post['plate_num'] : null; // 车牌号
        $post['car_type'] = CarType::getCode($post['car_type']); // 行驶证的车辆类型

        if (!$post['report_field']) {
            return error('保存信息为空');
        }

        if (!$reportData = $this->getMutiInfo(['id' => $post['report_id']], 'is_load')) {
            return error('案件未找到');
        }

        if (!$reportInfo = $this->getDb()->table('qianxing_report_info')->field('site_photos')->where(['id' => $post['report_id']])->find()) {
            return error('案件信息未找到');
        }

        if ($_FILES['upfile']['error'] !== 0) {
            return error('上传失败');
        }

        // 签名图片要旋转 90 度
        $rotate = in_array($post['report_field'], [
            'signature_checker',
            'signature_writer',
            'signature_agent',
            'signature_invitee'
        ]) ? 90 : 0;

        $uploadfile = uploadfile($_FILES['upfile'], 'jpg,jpeg,png', $rotate > 0 ? 0 : 900, $rotate > 0 ? 0 : 900, $rotate);
        if ($uploadfile['errorcode'] !== 0) {
            return $uploadfile;
        }
        $uploadfile = $uploadfile['data'];

        if ($post['report_field'] == 'site_photos') {
            // 现场图照
            $reportInfo['site_photos'] = $reportInfo['site_photos'] ? json_decode($reportInfo['site_photos'], true) : [['src' => ''],['src' => ''],['src' => ''],['src' => ''],['src' => '']];
            $reportInfo['site_photos'][$post['report_field_index']]['src'] = $uploadfile['thumburl'] ? $uploadfile['thumburl'] : $uploadfile['url'];
            $update = [
                'site_photos' => json_encode($reportInfo['site_photos'])
            ];
        } else {
            $update = [
                $post['report_field'] => $uploadfile['thumburl'] ? $uploadfile['thumburl'] : $uploadfile['url']
            ];
        }

        // 个人信息维护
        $update['addr']      = $post['addr'];
        $update['full_name'] = $post['name'];
        $update['idcard']    = $post['idcard'];
        $update['gender']    = $post['gender'];
        $update['birthday']  = $post['birthday'];
        $update['plate_num'] = $post['plate_num'];
        $update['car_type']  = $post['car_type'];
        $update = array_filter($update);

        // 勘验人签字时间
        if ($post['report_field'] === 'signature_checker') {
            $update['checker_time'] = date('Y-m-d H:i:s', TIMESTAMP);
        }
        // 当事人签字时间
        if ($post['report_field'] === 'signature_agent') {
            $update['agent_time'] = date('Y-m-d H:i:s', TIMESTAMP);
        }

        if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update($update)) {
            return error('图片保存失败');
        }

        // 当签字后就可以认定案件已处置完成
        if (!$reportData['is_load']) {
            if (in_array($post['report_field'], [
                'signature_checker',
                'signature_writer',
                'signature_agent',
                'signature_invitee'
            ])) {
                $this->getDb()->where(['id' => $post['report_id']])->update([
                    'is_load' => 1
                ]);
            }
        }

        return success([ 'url' => httpurl($uploadfile['url'])]);
    }

    /**
     * 获取案件记录
     * @return array
     */
    public function getReportEvents (int $user_id, array $post) 
    {
        $post['islaw'] = $post['islaw'] ? 1 : 0;
        $post['group_id'] = intval($post['group_id']);
        $post['status'] = intval($post['status']);
        $post['lastpage'] = intval($post['lastpage']);

        $result = [
            'limit' => 5,
            'lastpage' => '',
            'list' => [],
            'count' => []
        ];

        if (!$post['lastpage']) {
            $result['count'] = (new UserReportModel())->getEventsCount($post['group_id'], $post['islaw'] ? $user_id : null, $post['islaw'] ? null : $user_id);
        }

        // 搜索案件状态
        $status = [
            0 => [
                0 => ['in', [2, 3]], // 全部案件
                1 => 3, // 已完成
            ],
            1 => [
                1 => 1, // 审理中
                2 => ['in', [2, 3]], // 已完成
            ]
        ];

        if ($post['islaw']) {
            // 受理人与协同人员都显示
            $condition = [
                'group_id' => $post['group_id'],
                'law_id' => ['=', $user_id, 'and ('],
                'colleague_id' => ['=', $user_id, 'or', ')'],
            ];
        } else {
            $condition = [
                'user_id' => $user_id
            ];
        }
        if ($post['lastpage']) {
            $condition['id'] = ['<', $post['lastpage']];
        }
        if (isset($status[$post['islaw']][$post['status']])) {
            $condition['status'] = $status[$post['islaw']][$post['status']];
        } else {
            return success($result);
        }

        if (!$result['list'] = $this->getDb()->field('id,group_id,location,address,user_mobile,stake_number,total_money,status,is_load,is_property,complete_time,recover_time,create_time')->where($condition)->order('id desc')->limit($result['limit'])->select()) {
            return success($result);
        }

        if ($post['islaw']) {
            // 管理端
            if ($post['status'] == 2) {
                // 已完成
                $infos = $this->getDb()->table('qianxing_report_info')->field('id,full_name,plate_num')->where(['id' => ['in', array_column($result['list'], 'id')]])->select();
                $infos = array_column($infos, null, 'id');
                foreach ($result['list'] as $k => $v) {
                    $result['list'][$k]['full_name'] = $infos[$v['id']]['full_name'];
                    $result['list'][$k]['plate_num'] = $infos[$v['id']]['plate_num'];
                }
                unset($infos);
            }
        } else {
            // 用户端
            $infos = $this->getDb()->table('qianxing_report_info')->field('id,event_time,full_name,plate_num')->where(['id' => ['in', array_column($result['list'], 'id')]])->select();
            $infos = array_column($infos, null, 'id');
            $groups = (new GroupModel())->select(['id' => ['in', array_column($result['list'], 'group_id')]], 'id,name');
            $groups = array_column($groups, 'name', 'id');
            foreach ($result['list'] as $k => $v) {
                $result['list'][$k]['event_time'] = $infos[$v['id']]['event_time'];
                $result['list'][$k]['full_name'] = $infos[$v['id']]['full_name'];
                $result['list'][$k]['plate_num'] = $infos[$v['id']]['plate_num'];
                $result['list'][$k]['group_name'] = $groups[$v['group_id']];
            }
            unset($infos, $groups);
        }

        foreach ($result['list'] as $k => $v) {
            $result['lastpage'] = $v['id'];
            $result['list'][$k]['stake_number'] = str_replace(' ', '', $v['stake_number']);
            $result['list'][$k]['total_money'] = round_dollar($v['total_money']);
            $result['list'][$k]['status_str'] = ReportStatus::remark($v['status'], $v['recover_time']);
        }

        return success($result);
    }

    /**
     * 受理案件
     * @return array
     */
    public function acceptReport (array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        // 获取用户报案
        $userReport = (new UserReportModel())->collectInfo($this->userInfo['id'], $post['report_id']);
        if ($userReport['errorcode'] !== 0) {
            return $userReport;
        }
        $userReport = $userReport['data'];

        // 新增案件
        if (!$report_id = $this->getDb()->transaction(function ($db) use ($userReport) {
            if (!$report_id = $this->getDb()->insert([
                'group_id' => $this->userInfo['group_id'],
                'location' => $userReport['location'],
                'address' => $userReport['address'],
                'user_mobile' => $userReport['user_mobile'],
                'law_id' => $this->userInfo['id'],
                'report_time' => $userReport['create_time'],
                'status' => ReportStatus::ACCEPT,
                'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
            ], true)) {
                return false;
            }
            if (!$this->getDb()->table('qianxing_report_info')->insert([
                'id' => $report_id,
                'event_time' => $userReport['create_time']
            ])) {
                return false;
            }
            if (false === $this->getDb()->table('qianxing_user_report')->where(['id' => $userReport['id']])->update([
                'report_id' => $report_id
            ])) {
                return false;
            }
            return $report_id;
        })) {
            return error('案件保存失败');
        }

        // 更新统计数
        (new UserCountModel())->setReportCount($this->userInfo['id']);

        // 推送通知
        $msgModel = new MsgModel();
        $msgModel->sendReportAcceptSms($this->userInfo['telephone'], $userReport['user_mobile']);
        $msgModel->sendUserAcceptSms($userReport['user_mobile'], $this->userInfo['group_id']);

        return success(['report_id' => $report_id]);
    }

    /**
     * 获取案件详情
     * @return array
     */
    public function getReportDetail (array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        $reportData = [
            'is_property' => 1 // 默认有路产损失
        ];
        if ($post['data_type'] == 'info') {
            $reportData['car_type_list'] = \app\common\CarType::getKey();
            $reportData['weather_list'] = \app\common\Weather::getKey();
            $reportData['event_type_list'] = \app\common\EventType::getKey();
            $reportData['driver_state_list'] = \app\common\DriverState::getKey();
            $reportData['car_state_list'] = \app\common\CarState::getKey();
            $reportData['traffic_state_list'] = \app\common\TrafficState::getKey();
            // 获取协同人员
            $reportData['colleague_list'] = (new UserModel())->getColleague($this->userInfo['id'], $this->userInfo['group_id']);
            // 获取辅助定位路线
            $reportData['way_line'] = (new GroupModel())->count(['id' => $this->userInfo['group_id']], 'way_line');
        } else if ($post['data_type'] == 'card') {
            $reportData['car_type_list'] = \app\common\CarType::getKey();
        }

        if (!$post['report_id']) {
            return success($reportData);
        }

        if (!$data = $this->getMutiInfo(['id' => $post['report_id']], 'id,group_id,location,address,user_id,user_mobile,law_id,colleague_id,stake_number,total_money,is_property,is_load,status,create_time')) {
            return error('案件未找到');
        }
        $reportData = array_merge($reportData, $data);
        unset($data);

        $reportData['total_money'] = round_dollar($reportData['total_money']);

        if ($post['data_type'] == 'all' || $post['data_type'] == 'paper') {
            // 路产受损赔付清单
            $reportData['items'] = $this->getDb()->field('property_id,name,unit,price,amount,total_money')->table('qianxing_report_item')->where(['report_id' => $post['report_id']])->select();
            foreach ($reportData['items'] as $k => $v) {
                $reportData['items'][$k]['price'] = round_dollar($v['price']);
                $reportData['items'][$k]['total_money'] = round_dollar($v['total_money']);
            }
        }

        $fields = null;
        if ($post['data_type'] == 'info') {
            // 报送信息
            $fields = 'check_start_time,event_time,weather,car_type,event_type,driver_state,car_state,traffic_state,pass_time';
        } else if ($post['data_type'] == 'card') {
            // 当事人信息
            $fields = 'addr,full_name,idcard,gender,birthday,plate_num,car_type';
        } else if ($post['data_type'] == 'paper') {
            // 勘验笔录信息
            $fields = 'check_start_time,check_end_time,event_time,weather,car_type,full_name,plate_num,involved_action,involved_build_project,involved_act,involved_action_type,extra_info,signature_checker,signature_writer,signature_agent,signature_invitee,invitee_mobile,checker_time,agent_time,idcard_front,idcard_behind,driver_license_front,driver_license_behind,driving_license_front,driving_license_behind,site_photos';
        }
        
        $reportData += $this->getDb()->field($fields)->table('qianxing_report_info')->where(['id' => $post['report_id']])->limit(1)->find();

        if ($post['data_type'] == 'paper') {
            $reportData['weather'] = Weather::getMessage($reportData['weather']);
            $reportData['car_type'] = CarType::getMessage($reportData['car_type']);
            // 获取勘验人和记录人的执法证号
            $lawNums = (new AdminModel())->getLawNumByUser([$reportData['law_id'], $reportData['colleague_id']]);
            $reportData['law_lawnum'] = strval($lawNums[$reportData['law_id']]);
            $reportData['colleague_lawnum'] = strval($lawNums[$reportData['colleague_id']]);
            // 获取卷宗号
            $reportData['way_name'] = (new GroupModel())->count(['id' => $reportData['group_id']], 'way_name');
            $reportData['stake_number'] = str_replace(' ', '', $reportData['stake_number']);
            // 勾选证据
            // 当事人身份证
            $reportData['data_idcard'] = $reportData['idcard_front'] || $reportData['idcard_behind'];
            // 驾驶证
            $reportData['data_driver'] = $reportData['driver_license_front'] || $reportData['driver_license_behind'];
            // 行驶证
            $reportData['data_driving'] = $reportData['driving_license_front'] || $reportData['driving_license_behind'];
            // 现场照片
            $reportData['site_photos'] = json_decode($reportData['site_photos'], true);
            foreach ($reportData['site_photos'] as $k => $v) {
                if ($v['src']) {
                    $reportData['data_site'] = true;
                    break;
                }
            }
            unset($reportData['idcard_front'], $reportData['idcard_behind'], $reportData['driver_license_front'], $reportData['driver_license_behind'], $reportData['driving_license_front'], $reportData['driving_license_behind'], $reportData['site_photos']);
            // 车辆数
            $reportData['plate_num_count'] = $reportData['plate_num'] ? count(explode(',', $reportData['plate_num'])) : 0;
        }

        if (isset($reportData['idcard_front'])) {
            $reportData['idcard_front'] = httpurl($reportData['idcard_front']);
        }
        if (isset($reportData['idcard_behind'])) {
            $reportData['idcard_behind'] = httpurl($reportData['idcard_behind']);
        }
        if (isset($reportData['driver_license_front'])) {
            $reportData['driver_license_front'] = httpurl($reportData['driver_license_front']);
        }
        if (isset($reportData['driver_license_behind'])) {
            $reportData['driver_license_behind'] = httpurl($reportData['driver_license_behind']);
        }
        if (isset($reportData['driving_license_front'])) {
            $reportData['driving_license_front'] = httpurl($reportData['driving_license_front']);
        }
        if (isset($reportData['driving_license_behind'])) {
            $reportData['driving_license_behind'] = httpurl($reportData['driving_license_behind']);
        }
        if ($reportData['site_photos']) {
            $reportData['site_photos'] = json_decode($reportData['site_photos'], true);
            foreach ($reportData['site_photos'] as $k => $v) {
                $reportData['site_photos'][$k]['src'] = httpurl($v['src']);
            }
        }
        if (isset($reportData['involved_action'])) {
            $reportData['involved_action'] = $reportData['involved_action'] ? json_decode($reportData['involved_action'], true) : [];
        }
        if (isset($reportData['involved_action_type'])) {
            $reportData['involved_action_type'] = $reportData['involved_action_type'] ? json_decode($reportData['involved_action_type'], true) : [];
        }
        if (isset($reportData['signature_checker'])) {
            $reportData['signature_checker'] = httpurl($reportData['signature_checker']);
        }
        if (isset($reportData['signature_writer'])) {
            $reportData['signature_writer'] = httpurl($reportData['signature_writer']);
        }
        if (isset($reportData['signature_agent'])) {
            $reportData['signature_agent'] = httpurl($reportData['signature_agent']);
        }
        if (isset($reportData['signature_invitee'])) {
            $reportData['signature_invitee'] = httpurl($reportData['signature_invitee']);
        }

        return success($reportData);
    }

    /**
     * 保存案件信息
     * @return array
     */
    public function saveReportInfo (array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        $data = [];
        $data['invitee_mobile'] = $post['invitee_mobile']; // 邀请人手机

        if ($data['invitee_mobile'] && !validate_telephone($data['invitee_mobile'])) {
            return error('被邀请人手机号格式错误');
        }

        $data = array_filter($data);

        if ($data) {
            if (!$this->getMutiInfo(['id' => $post['report_id']], 'id')) {
                return error('案件未找到');
            }
            if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update($data)) {
                return error('保存数据失败');
            }
        }

        return success('ok');
    }

    /**
     * 填写报送信息
     * @return array
     */
    public function reportInfo (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['location'] = \app\library\LocationUtils::checkLocation($post['location']);
        $post['address'] = trim_space($post['address'], 0, 100);
        $post['colleague_id'] = intval($post['colleague_id']); 
        $post['stake_number'] = trim($post['stake_number']);
        $post['event_time'] = strtotime($post['event_time']) ? $post['event_time'] : null;
        $post['weather'] = intval(Weather::format($post['weather']));
        $post['car_type'] = intval(CarType::format($post['car_type']));
        $post['event_type'] = intval(EventType::format($post['event_type']));
        $post['driver_state'] = intval(DriverState::format($post['driver_state']));
        $post['car_state'] = intval(CarState::format($post['car_state']));
        $post['traffic_state'] = intval(TrafficState::format($post['traffic_state']));
        $post['pass_time'] = intval($post['pass_time']);
        $post['check_start_time'] = strtotime($post['check_start_time']);
        $post['check_start_time'] = $post['check_start_time'] ? $post['check_start_time'] : TIMESTAMP;
        $post['is_property'] = $post['is_property'] ? 1 : 0;

        if (!$post['location'] || !$post['address']) {
            return error('请定位位置');
        }
        if (!$post['stake_number']) {
            return error('请输入桩号');
        }

        if ($post['report_id']) {
            if (!$reportData = $this->getMutiInfo(['id' => $post['report_id']], 'id,colleague_id')) {
                return error('案件未找到');
            }
            // 当前操作者是受理人还是协同人
            if ($reportData['colleague_id'] == $this->userInfo['id']) {
                // 协同人操作不能修改协同人员项
                $post['colleague_id'] = $this->userInfo['id'];
            } else {
                if ($post['colleague_id'] && $post['colleague_id'] == $this->userInfo['id']) {
                    return error('不能添加自己为协同人员');
                }
            }
        } else {
            if ($post['colleague_id'] && $post['colleague_id'] == $this->userInfo['id']) {
                return error('不能添加自己为协同人员');
            }
        }

        if (!$report_id = $this->getDb()->transaction(function ($db) use ($post) {
            if ($post['report_id']) {
                // 更新案件
                if (false === $this->getDb()->where(['id' => $post['report_id'], 'status' => ['in', [ReportStatus::ACCEPT, ReportStatus::HANDLED]]])->update([
                    'location' => $post['location'],
                    'address' => $post['address'],
                    'stake_number' => $post['stake_number'],
                    'colleague_id' => $post['colleague_id'],
                    'is_property' => $post['is_property'],
                    'is_load' => $post['is_property'] ? ['is_load'] : 1,
                    'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
                ])) {
                    return false;
                }
                if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update([
                    'event_time' => $post['event_time'],
                    'weather' => $post['weather'],
                    'car_type' => $post['car_type'],
                    'event_type' => $post['event_type'],
                    'driver_state' => $post['driver_state'],
                    'car_state' => $post['car_state'],
                    'traffic_state' => $post['traffic_state'],
                    'pass_time' => $post['pass_time'],
                    'check_start_time' => date('Y-m-d H:i:00', $post['check_start_time']),
                    'check_end_time' => date('Y-m-d H:i:00', $post['check_start_time'] + 600)
                ])) {
                    return false;
                }
                return $post['report_id'];
            } else {
                // 新增案件
                if (!$report_id = $this->getDb()->insert([
                    'group_id' => $this->userInfo['group_id'],
                    'location' => $post['location'],
                    'address' => $post['address'],
                    'stake_number' => $post['stake_number'],
                    'colleague_id' => $post['colleague_id'],
                    'is_property' => $post['is_property'],
                    'is_load' => $post['is_property'] ? 0 : 1,
                    'law_id' => $this->userInfo['id'],
                    'status' => ReportStatus::ACCEPT,
                    'report_time' => date('Y-m-d H:i:s', TIMESTAMP),
                    'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
                ], true)) {
                    return false;
                }
                if (!$this->getDb()->table('qianxing_report_info')->insert([
                    'id' => $report_id,
                    'event_time' => $post['event_time'],
                    'weather' => $post['weather'],
                    'car_type' => $post['car_type'],
                    'event_type' => $post['event_type'],
                    'driver_state' => $post['driver_state'],
                    'car_state' => $post['car_state'],
                    'traffic_state' => $post['traffic_state'],
                    'pass_time' => $post['pass_time'],
                    'check_start_time' => date('Y-m-d H:i:00', $post['check_start_time']),
                    'check_end_time' => date('Y-m-d H:i:00', $post['check_start_time'] + 600)
                ])) {
                    return false;
                }
                return $report_id;
            }
        })) {
            return error('案件保存失败');
        }

        $userCount = new UserCountModel();
        if (!$post['report_id']) {
            // 更新统计数
            $update = [
                $this->userInfo['id']
            ];
            if ($post['colleague_id']) {
                $update[] = $post['colleague_id'];
            }
            $userCount->setReportCount($update);
        } else {
            // 更新统计数
            if ($reportData['colleague_id']) {
                if ($reportData['colleague_id'] != $post['colleague_id']) {
                    $userCount->setReportCount($post['colleague_id'], $reportData['colleague_id']);
                }
            } else {
                $userCount->setReportCount($post['colleague_id']);
            }
        }

        // 无路产损失
        if (!$post['is_property']) {
            // 不进行勘验，确认恢复畅通后，直接结案
            return success('ok');
        }
        
        return success(['report_id' => $report_id]);
    }

    /**
     * 保存当事人信息
     * @return array
     */
    public function cardInfo (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['addr'] = trim_space($post['addr'], 0, 50);
        $post['full_name'] = trim_space($post['full_name'], 0, 20);
        $post['car_type'] = CarType::format($post['car_type']);
        $post['plate_num'] = get_short_array($post['plate_num'], ',', 220);

        if ($post['plate_num']) {
            $post['plate_num'] = array_unique(array_filter($post['plate_num']));
            foreach ($post['plate_num'] as $k => $v) {
                if (!check_car_license($v)) {
                    return error('车牌号“' . $v . '”格式不正确');
                }
            }
            $post['plate_num'] = implode(',', $post['plate_num']);
        } else {
            $post['plate_num'] = '';
        }
        if (!$post['plate_num']) {
            return error('车牌号不能为空');
        }
        if ($post['idcard'] && !Idcard::check_id($post['idcard'])) {
            return error('身份证号格式不正确');
        }
        if ($post['idcard']) {
            $post['gender']   = Idcard::parseidcard_getsex($post['idcard']);
            $post['birthday'] = Idcard::parseidcard_getbirth($post['idcard']);
        } else {
            $post['gender']   = Gender::format($post['gender']);
            $post['birthday'] = strtotime($post['birthday']) ? $post['birthday'] : null;
        }
        
        if (!validate_telephone($post['telephone'])) {
            return error('手机号格式错误');
        }

        if (!$this->getMutiInfo(['id' => $post['report_id']], 'id')) {
            return error('案件未找到');
        }

        // 重新关联当事人，当事人通过 user_mobile 才能获取到订单
        $userModel = new UserModel();
        $userInfo = $userModel->find(['telephone' => $post['telephone']], 'id,group_id');

        if (!$this->getDb()->transaction(function ($db) use ($post, $userInfo) {
            if (false === $this->getDb()->where(['id' => $post['report_id']])->update([
                'user_id' => $userInfo ? $userInfo['id'] : 0, // 当事人是否已有账号
                'user_mobile' => $post['telephone']
            ])) {
                return false;
            }
            if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update([
                'addr' => $post['addr'],
                'full_name' => $post['full_name'],
                'plate_num' => $post['plate_num'],
                'car_type' => $post['car_type'],
                'idcard' => $post['idcard'],
                'gender' => $post['gender'],
                'birthday' => $post['birthday']
            ])) {
                return false;
            }
            return true;
        })) {
            return error('保存信息失败');
        }

        if ($userInfo) {
            // 更新当事人账号信息
            $data = [
                'idcard' => $post['idcard']
            ];
            if (!$userInfo['group_id']) {
                // 不修改路政员的姓名
                $data['full_name'] = $post['full_name'];
            }
            $userModel->updateUserInfo($userInfo['id'], $data);
        }

        return success('ok');
    }

    /**
     * 撤销案件
     * @return array
     */
    public function cancelReport (array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        if (!$reportData = $this->getMutiInfo(['id' => $post['report_id']], 'law_id,colleague_id')) {
            return error('案件未找到');
        }

        if (!$this->getDb()->where(['id' => $post['report_id'], 'status' => ['in', [ReportStatus::ACCEPT, ReportStatus::HANDLED]]])->update([
            'status' => ReportStatus::CANCEL,
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据更新失败');
        }

        // 更新统计数
        (new UserCountModel())->setReportCount(null, array_filter([$reportData['law_id'], $reportData['colleague_id']]));

        return success('ok');
    }

}