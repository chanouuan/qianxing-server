<?php

namespace app\models;

use Crud;
use app\library\LocationUtils;
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
        if (!(new UserModel())->count(['id' => $post['target_id'], 'group_id' => $this->userInfo['group_id']])) {
            return error('该移交人不在同部门');
        }

        if (!$this->count(['id' => $post['report_id'], 'group_id' => $this->userInfo['group_id'], 'status' => ReportStatus::ACCEPT])) {
            return error('案件信息未找到');
        }

        if (!$this->getDb()->where(['id' => $post['report_id'], 'status' => ReportStatus::ACCEPT])->update([
            'law_id' => $post['target_id'],
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据更新失败');
        }

        // 更新统计数
        (new UserCountModel())->setReportCount('old', null, $post['target_id'], null, $this->userInfo['id']);

        // todo 通知移交人

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

        $condition = [
            'id' => $post['report_id'], 
            'status' => ReportStatus::ACCEPT
        ];
        if (empty($adminCondition)) {
            $condition['law_id'] = $this->userInfo['id'];
        } else {
            $condition += $adminCondition;
        }

        if (!$reportData = $this->find($condition, 'id,group_id,law_id,colleague_id,location,user_id')) {
            return error('案件未找到');
        }

        if (!$this->getDb()->where(['id' => $post['report_id'], 'status' => ReportStatus::ACCEPT])->update([
            'status' => ReportStatus::HANDLED,
            'handle_time' => date('Y-m-d H:i:s', TIMESTAMP),
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据保存失败');
        }

        // 更新统计数
        $userCountModel = new UserCountModel();
        $userCountModel->updateSet([$reportData['law_id'], $reportData['colleague_id']], [
            'case_count' => ['case_count+1'],
            'patrol_km' => ['patrol_km+' . (new GroupModel())->getDistance($reportData['group_id'], $reportData['location'])]
        ]);
        $userCountModel->updateCityRank([$reportData['law_id'], $reportData['colleague_id']], $reportData['group_id']);
        $userCountModel->setReportCount('complete', null, $reportData['law_id']);

        // todo 通知用户
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

        if (!$reportInfo = $this->find(['id' => $post['report_id'], 'law_id' => $this->userInfo['id'], 'status' => ReportStatus::ACCEPT], 'id')) {
            return error('案件未找到');
        }

        // 检查赔付清单
        foreach ($post['items'] as $k => $v) {
            $post['items'][$k]['property_id'] = intval($v['property_id']);
            $post['items'][$k]['price'] = intval(floatval($v['price']) * 100); // 转成分
            $post['items'][$k]['amount'] = intval($v['amount']);
            if (!$post['items'][$k]['property_id'] || $post['items'][$k]['price'] < 0 || $post['items'][$k]['amount'] <= 0) {
                unset($post['items'][$k]);
            }
        }

        $items = [];
        if ($post['items']) {
            if (!$properties = (new PropertyModel())->select(['id' => ['in', array_column($post['items'], 'property_id')]], 'id,category,name,unit')) {
                return error('获取路产项目失败');
            }
            $properties = array_column($properties, null, 'id');
            foreach ($post['items'] as $k => $v) {
                if (isset($properties[$v['property_id']])) {
                    $items[] = [
                        'report_id' => $post['report_id'],
                        'property_id' => $v['property_id'],
                        'category' => $properties[$v['property_id']]['category'],
                        'name' => $properties[$v['property_id']]['name'],
                        'unit' => $properties[$v['property_id']]['unit'],
                        'price' => $v['price'],
                        'amount' => $v['amount'],
                        'total_money' => $v['price'] * $v['amount']
                    ];
                }
            }
        }

        if (!$this->getDb()->transaction(function ($db) use ($post, $items) {
            // 更新赔付金额
            if (!$this->getDb()->where(['id' => $post['report_id'], 'status' => ReportStatus::ACCEPT])->update([
                'total_money' => array_sum(array_column($items, 'total_money')),
                'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
            ])) {
                return false;
            }
            if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update([
                'involved_action' => json_encode($post['involved_action']),
                'involved_action_type' => json_encode($post['involved_action_type']),
                'involved_build_project' => $post['involved_build_project'],
                'involved_act' => $post['involved_act'],
                'extra_info' => $post['extra_info'],
                'check_start_time' => date('Y-m-d H:i:s', TIMESTAMP - 600),
                'check_end_time' => date('Y-m-d H:i:s', TIMESTAMP)
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
            return error('保持数据失败');
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
            return $this->getDb()->table('qianxing_report_info')->field('full_name,idcard,gender')->where(['id' => $reportData['id']])->find();
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
    public function upload ($post) 
    {
        $post['report_id']          = intval($post['report_id']);
        $post['report_field']       = trim_space($post['report_field'], 0, 22);
        $post['report_field_index'] = intval($post['report_field_index']);

        // 图片识别信息
        $post['addr']   = trim_space($post['addr'], 0, 50); // 住址
        $post['name']   = trim_space($post['name'], 0, 20); // 姓名
        $post['idcard'] = Idcard::check_id($post['idcard']) ? $post['idcard'] : null; // 身份证号
        if ($post['idcard']) {
            $post['gender']   = Idcard::parseidcard_getsex($post['idcard']);
            $post['birthday'] = Idcard::parseidcard_getbirth($post['idcard']);
        }
        $post['plate_num'] = check_car_license($post['plate_num']) ? $post['plate_num'] : null; // 车牌号

        if (!$post['report_field']) {
            return error('保存信息为空');
        }

        if (!$reportInfo = $this->getDb()->table('qianxing_report_info')->field('site_photos')->where(['id' => $post['report_id']])->find()) {
            return error('案件未找到');
        }

        if ($_FILES['upfile']['error'] !== 0) {
            return error('上传失败');
        }

        // 签名图片要旋转 90 度
        $rotate = (
            $post['report_field'] === 'signature_checker' || 
            $post['report_field'] === 'signature_writer' || 
            $post['report_field'] === 'signature_agent' || 
            $post['report_field'] === 'signature_invitee'
            ) ? 90 : 0;

        $uploadfile = uploadfile($_FILES['upfile'], 'jpg,jpeg,png', $rotate ? 0 : 800, 0, $rotate);
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
        $update = array_filter($update);

        if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update($update)) {
            return error('图片保存失败');
        }

        return success([ url => httpurl($uploadfile['url'])]);
    }

    /**
     * 获取案件记录
     * @return array
     */
    public function getReportEvents (int $user_id, array $post) 
    {
        $post['islaw'] = $post['islaw'] ? 1 : 0;
        $post['status'] = intval($post['status']);
        $post['lastpage'] = intval($post['lastpage']);

        $result = [
            'limit' => 5,
            'lastpage' => '',
            'list' => []
        ];

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

        $condition = [
            $post['islaw'] ? 'law_id' : 'user_id' => $user_id
        ];
        if ($post['lastpage']) {
            $condition['id'] = ['<', $post['lastpage']];
        }
        if (isset($status[$post['islaw']][$post['status']])) {
            $condition['status'] = $status[$post['islaw']][$post['status']];
        } else {
            return success($result);
        }

        if (!$result['list'] = $this->getDb()->field('id,group_id,location,address,user_mobile,stake_number,total_money,status,create_time')->where($condition)->order('id desc')->limit($result['limit'])->select()) {
            return success($result);
        }

        if (!$post['islaw']) {
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
            $result['list'][$k]['total_money'] = round_dollar($v['total_money']);
            $result['list'][$k]['status_str'] = ReportStatus::getMessage($v['status']);
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
                'check_start_time' => date('Y-m-d H:i:s', TIMESTAMP - 600),
                'check_end_time' => date('Y-m-d H:i:s', TIMESTAMP),
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
        (new UserCountModel())->setReportCount('accept', $this->userInfo['group_id'], $this->userInfo['id']);

        return success(['report_id' => $report_id]);
    }

    /**
     * 获取案件详情
     * @return array
     */
    public function getReportDetail (array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        if (!$post['report_id']) {
            return success([]);
        }

        if (!$reportInfo = $this->find(['id' => $post['report_id']], 'id,group_id,location,address,user_id,user_mobile,law_id,colleague_id,stake_number,total_money,status,create_time')) {
            return error('案件未找到');
        }

        $reportInfo['total_money'] = round_dollar($reportInfo['total_money']);

        if ($post['data_type'] == 'all' || $post['data_type'] == 'paper') {
            // 路产受损赔付清单
            $reportInfo['items'] = $this->getDb()->field('property_id,name,unit,price,amount,total_money')->table('qianxing_report_item')->where(['report_id' => $post['report_id']])->select();
            foreach ($reportInfo['items'] as $k => $v) {
                $reportInfo['items'][$k]['price'] = round_dollar($v['price']);
                $reportInfo['items'][$k]['total_money'] = round_dollar($v['total_money']);
            }
        }

        $fields = null;
        if ($post['data_type'] == 'info') {
            // 报送信息
            $fields = 'event_time,weather,car_type,event_type,driver_state,car_state,traffic_state';
        } else if ($post['data_type'] == 'card') {
            // 当事人信息
            $fields = 'addr,full_name,idcard,gender,birthday,plate_num';
        } else if ($post['data_type'] == 'paper') {
            // 勘验笔录信息
            $fields = 'check_start_time,check_end_time,event_time,weather,car_type,full_name,plate_num,involved_action,involved_build_project,involved_act,involved_action_type,extra_info,signature_checker,signature_writer,signature_agent,signature_invitee';
        }

        $reportInfo += $this->getDb()->field($fields)->table('qianxing_report_info')->where(['id' => $post['report_id']])->limit(1)->find();

        if ($post['data_type'] == 'paper') {
            $reportInfo['weather'] = Weather::getMessage($reportInfo['weather']);
            $reportInfo['car_type'] = CarType::getMessage($reportInfo['car_type']);
            // 获取勘验人和记录人的执法证号
            $lawNums = (new AdminModel())->getLawNumByUser([$reportInfo['law_id'], $reportInfo['colleague_id']]);
            $reportInfo['law_lawnum'] = strval($lawNums[$reportInfo['law_id']]);
            $reportInfo['colleague_lawnum'] = strval($lawNums[$reportInfo['colleague_id']]);
        }

        if (isset($reportInfo['idcard_front'])) {
            $reportInfo['idcard_front'] = httpurl($reportInfo['idcard_front']);
        }
        if (isset($reportInfo['idcard_behind'])) {
            $reportInfo['idcard_behind'] = httpurl($reportInfo['idcard_behind']);
        }
        if (isset($reportInfo['driver_license_front'])) {
            $reportInfo['driver_license_front'] = httpurl($reportInfo['driver_license_front']);
        }
        if (isset($reportInfo['driver_license_behind'])) {
            $reportInfo['driver_license_behind'] = httpurl($reportInfo['driver_license_behind']);
        }
        if (isset($reportInfo['driving_license_front'])) {
            $reportInfo['driving_license_front'] = httpurl($reportInfo['driving_license_front']);
        }
        if (isset($reportInfo['driving_license_behind'])) {
            $reportInfo['driving_license_behind'] = httpurl($reportInfo['driving_license_behind']);
        }
        if ($reportInfo['site_photos']) {
            $reportInfo['site_photos'] = json_decode($reportInfo['site_photos'], true);
            foreach ($reportInfo['site_photos'] as $k => $v) {
                $reportInfo['site_photos'][$k]['src'] = httpurl($v['src']);
            }
        }
        if ($reportInfo['extra_photos']) {
            $reportInfo['extra_photos'] = json_decode($reportInfo['extra_photos'], true);
            foreach ($reportInfo['extra_photos'] as $k => $v) {
                $reportInfo['extra_photos'][$k]['src'] = httpurl($v['src']);
            }
        }
        if (isset($reportInfo['involved_action'])) {
            $reportInfo['involved_action'] = $reportInfo['involved_action'] ? json_decode($reportInfo['involved_action'], true) : [];
        }
        if (isset($reportInfo['involved_action_type'])) {
            $reportInfo['involved_action_type'] = $reportInfo['involved_action_type'] ? json_decode($reportInfo['involved_action_type'], true) : [];
        }
        if (isset($reportInfo['signature_checker'])) {
            $reportInfo['signature_checker'] = httpurl($reportInfo['signature_checker']);
        }
        if (isset($reportInfo['signature_writer'])) {
            $reportInfo['signature_writer'] = httpurl($reportInfo['signature_writer']);
        }
        if (isset($reportInfo['signature_agent'])) {
            $reportInfo['signature_agent'] = httpurl($reportInfo['signature_agent']);
        }
        if (isset($reportInfo['signature_invitee'])) {
            $reportInfo['signature_invitee'] = httpurl($reportInfo['signature_invitee']);
        }

        return success($reportInfo);
    }

    /**
     * 填写报送信息
     * @return array
     */
    public function reportInfo (array $post)
    {
        $post['report_id']     = intval($post['report_id']);
        $post['location']      = LocationUtils::checkLocation($post['location']);
        $post['address']       = trim_space($post['address'], 0, 100);
        $post['colleague_id']  = intval($post['colleague_id']); 
        $post['stake_number']  = trim_space($post['stake_number'], 0, 100);
        $post['event_time']    = strtotime($post['event_time']) ? $post['event_time'] : null;
        $post['weather']       = Weather::format($post['weather']);
        $post['car_type']      = CarType::format($post['car_type']);
        $post['event_type']    = EventType::format($post['event_type']);
        $post['driver_state']  = DriverState::format($post['driver_state']);
        $post['car_state']     = CarState::format($post['car_state']);
        $post['traffic_state'] = TrafficState::format($post['traffic_state']);

        if (!$post['location'] || !$post['address']) {
            return error('请打开定位');
        }
        if (!$post['stake_number']) {
            return error('请输入桩号');
        }

        if ($post['report_id']) {
            if (!$this->count(['id' => $post['report_id'], 'status' => ReportStatus::ACCEPT, 'law_id' => $this->userInfo['id']])) {
                return error('案件未找到');
            }
        }

        if (!$report_id = $this->getDb()->transaction(function ($db) use ($post) {
            if ($post['report_id']) {
                // 更新案件
                if (false === $this->getDb()->where(['id' => $post['report_id'], 'status' => ReportStatus::ACCEPT])->update([
                    'location' => $post['location'],
                    'address' => $post['address'],
                    'stake_number' => $post['stake_number'],
                    'colleague_id' => $post['colleague_id'],
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
                    'traffic_state' => $post['traffic_state']
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
                    'check_start_time' => date('Y-m-d H:i:s', TIMESTAMP - 600),
                    'check_end_time' => date('Y-m-d H:i:s', TIMESTAMP)
                ])) {
                    return false;
                }
                return $report_id;
            }
        })) {
            return error('案件保存失败');
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
        $post['addr']      = trim_space($post['addr'], 0, 50);
        $post['full_name'] = trim_space($post['full_name'], 0, 20);
        
        if ($post['plate_num'] && !check_car_license($post['plate_num'])) {
            return error('车牌号格式不正确');
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

        // 重新关联当事人，当事人通过 user_mobile 才能获取到订单
        $userModel = new UserModel();
        $userInfo = $userModel->find(['telephone' => $post['telephone']], 'id');

        if (!$this->getDb()->transaction(function ($db) use ($post, $userInfo) {
            if (false === $this->getDb()->where(['id' => $post['report_id'], 'law_id' => $this->userInfo['id']])->update([
                'user_id' => $userInfo ? $userInfo['id'] : 0, // 当事人是否已有账号
                'user_mobile' => $post['telephone']
            ])) {
                return false;
            }
            if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update([
                'addr' => $post['addr'],
                'full_name' => $post['full_name'],
                'plate_num' => $post['plate_num'],
                'idcard' => $post['idcard'],
                'gender' => $post['gender'],
                'birthday' => $post['birthday'],
                'check_start_time' => date('Y-m-d H:i:s', TIMESTAMP - 600),
                'check_end_time' => date('Y-m-d H:i:s', TIMESTAMP)
            ])) {
                return false;
            }
            return true;
        })) {
            return error('保存信息失败');
        }

        if ($userInfo) {
            // 更新当事人账号信息
            $userModel->updateUserInfo($userInfo['id'], [
                'full_name' => $post['full_name'],
                'idcard'    => $post['idcard'],
                'gender'    => $post['gender'],
            ]);
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

        if (!$reportInfo = $this->find(['id' => $post['report_id'], 'law_id' => $this->userInfo['id'], 'status' => ReportStatus::ACCEPT], 'id')) {
            return error('案件未找到');
        }

        if (!$this->getDb()->where(['id' => $post['report_id']])->update([
            'status' => ReportStatus::CANCEL,
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据更新失败');
        }

        // 更新统计数
        (new UserCountModel())->setReportCount('complete', null, $this->userInfo['id']);

        return success('ok');
    }

    /**
     * 发送小程序订阅消息
     * @return array
     */
    public function sendSubscribeMessage ($user_id, $template_name, $page, array $value)
    {
        if (!$openid = (new UserModel())->getWxOpenId($user_id, 'mp')) {
            return error('openid为空');
        }
        $wxConfig = getSysConfig('qianxing', 'wx');
        if (!isset($wxConfig['template_id'][$template_name]) ||
            !$wxConfig['template_id'][$template_name]['id']  ||
            !$wxConfig['template_id'][$template_name]['data']) {
            return error('模板消息参数为空');
        }
        $jssdk = new \app\library\JSSDK($wxConfig['appid'], $wxConfig['appsecret']);
        $data = $wxConfig['template_id'][$template_name]['data'];
        foreach ($data as $k => $v) {
            $data[$k]['value'] = template_replace($v['value'], $value);
        }
        return $jssdk->sendMiniprogramSubscribeMessage([
            'openid' => $openid,
            'template_id' => $wxConfig['template_id'][$template_name]['id'],
            'page' => $page,
            'data' => $data
        ]);
    }

}