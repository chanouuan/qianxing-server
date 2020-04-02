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
            if (!$properties = (new PropertyModel())->getProperties(['id' => ['in', array_column($post['items'], 'property_id')]])) {
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
            if (!$this->getDb()->where(['id' => $post['report_id'], 'status' => ReportStatus::ACCEPT])->update([
                'pay' => array_sum(array_column($items, 'total_money')), // 更新赔付金额
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

        if (!$reportInfo = $this->getDb()->table('qianxing_report_info')->field('site_photos')->where(['id' => $post['report_id']])->find()) {
            return error('案件未找到');
        }

        if ($_FILES['upfile']['error'] !== 0) {
            return error('上传失败');
        }

        $uploadfile = uploadfile($_FILES['upfile'], 'jpg,jpeg,png', 0, 0);
        if ($uploadfile['errorcode'] !== 0) {
            return $uploadfile;
        }
        $uploadfile = $uploadfile['data'];

        if ($post['report_field'] == 'site_photos') {
            // 现场图照
            $reportInfo['site_photos'] = $reportInfo['site_photos'] ? json_decode($reportInfo['site_photos'], true) : [['src' => ''],['src' => ''],['src' => ''],['src' => ''],['src' => '']];
            $reportInfo['site_photos'][$post['report_field_index']]['src'] = $uploadfile['url'];
            $update = [
                'site_photos' => json_encode($reportInfo['site_photos'])
            ];
        } else {
            $update = [
                $post['report_field'] => $uploadfile['url']
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
        if ($post['islaw']) {
            $condition['law_id'] = $user_id;
        } else {
            $condition['user_id'] = $user_id;
        }
        if (!is_null($post['status'])) {
            $condition['status'] = $post['status'];
        }

        if (!$result['list'] = $this->getDb()->field('id,location,address,user_mobile,pay,status,create_time')->where($condition)->order('id desc')->limit($result['limit'])->select()) {
            return success($result);
        }

        foreach ($result['list'] as $k => $v) {
            $result['lastpage'] = $v['id'];
            $result['list'][$k]['pay'] = round_dollar($v['pay']);
            $result['list'][$k]['status_str'] = ReportStatus::getMessage($v['status']);
        }

        return success($result);
    }

    /**
     * 审理案件
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
                'adcode'      => $userReport['adcode'],
                'location'    => $userReport['location'],
                'address'     => $userReport['address'],
                'user_mobile' => $userReport['user_mobile'],
                'law_id'      => $this->userInfo['id'],
                'report_time' => $userReport['create_time'],
                'status'      => ReportStatus::ACCEPT,
                'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
            ], true)) {
                return false;
            }
            if (!$this->getDb()->table('qianxing_report_info')->insert([
                'id'         => $report_id,
                'event_time' => $userReport['create_time']
            ])) {
                return false;
            }
            return $report_id;
        })) {
            return error('案件保存失败');
        }

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

        if (!$reportInfo = $this->find(['id' => $post['report_id']], 'id,adcode,location,address,user_id,user_mobile,colleague_id,stake_number,pay,status,create_time')) {
            return error('案件未找到');
        }

        $reportInfo['pay'] = round_dollar($reportInfo['pay']);

        $fields = null;
        if ($post['data_type'] == 'info') {
            // 报送信息
            $fields = 'event_time,weather,car_type,event_type,driver_state,car_state,traffic_state';
        } else if ($post['data_type'] == 'card') {
            // 当事人信息
            $fields = 'addr,full_name,idcard,gender,birthday,plate_num';
        }

        $reportInfo += $this->getDb()->field($fields)->table('qianxing_report_info')->where(['id' => $post['report_id']])->limit(1)->find();

        if ($post['data_type'] == 'all') {
            // 路产受损赔付清单
            $reportInfo['items'] = $this->getDb()->field('property_id,name,unit,price,amount,total_money')->table('qianxing_report_item')->where(['report_id' => $post['report_id']])->select();
            foreach ($reportInfo['items'] as $k => $v) {
                $reportInfo['items'][$k]['price'] = round_dollar($v['price']);
                $reportInfo['items'][$k]['total_money'] = round_dollar($v['total_money']);
            }
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

        return success($reportInfo);
    }

    /**
     * 案件处置
     * @return array
     */
    public function reloadReport (array $post)
    {
        $post['order_id'] = intval($post['order_id']);
        if (!$orderInfo = $this->find(['id' => $post['order_id']], 'id,user_id,law_id,status')) {
            return error('报案记录不存在');
        }
        if ($orderInfo['law_id'] != $user_id) {
            return error('你不是该案件的执法人');
        }
        if ($orderInfo['status'] != ReportStatus::ACCEPT) {
            return error('该案件尚未受理');
        }

        if ($post['data_source'] == 'info') {
            // 报送信息
            return $this->reportInfo($this->userInfo, $post);
        }

        return success('ok');
    }

    /**
     * 填写报送信息
     * @return array
     */
    public function reportInfo (array $post)
    {
        $post['report_id']     = intval($post['report_id']);
        $post['adcode']        = intval($post['adcode']);
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
                    'adcode'       => $post['adcode'],
                    'location'     => $post['location'],
                    'address'      => $post['address'],
                    'stake_number' => $post['stake_number'],
                    'colleague_id' => $post['colleague_id'],
                    'update_time'  => date('Y-m-d H:i:s', TIMESTAMP)
                ])) {
                    return false;
                }
                if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update([
                    'event_time'    => $post['event_time'],
                    'weather'       => $post['weather'],
                    'car_type'      => $post['car_type'],
                    'event_type'    => $post['event_type'],
                    'driver_state'  => $post['driver_state'],
                    'car_state'     => $post['car_state'],
                    'traffic_state' => $post['traffic_state']
                ])) {
                    return false;
                }
                return $post['report_id'];
            } else {
                // 新增案件
                if (!$report_id = $this->getDb()->insert([
                    'adcode'       => $post['adcode'],
                    'location'     => $post['location'],
                    'address'      => $post['address'],
                    'stake_number' => $post['stake_number'],
                    'colleague_id' => $post['colleague_id'],
                    'law_id'       => $this->userInfo['id'],
                    'status'       => ReportStatus::ACCEPT,
                    'report_time'  => date('Y-m-d H:i:s', TIMESTAMP),
                    'create_time'  => date('Y-m-d H:i:s', TIMESTAMP)
                ], true)) {
                    return false;
                }
                if (!$this->getDb()->table('qianxing_report_info')->insert([
                    'id'            => $report_id,
                    'event_time'    => $post['event_time'],
                    'weather'       => $post['weather'],
                    'car_type'      => $post['car_type'],
                    'event_type'    => $post['event_type'],
                    'driver_state'  => $post['driver_state'],
                    'car_state'     => $post['car_state'],
                    'traffic_state' => $post['traffic_state']
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
        if (false === ($userInfo = $userModel->getUserInfo(['telephone' => $post['telephone']], 'id'))) {
            return error('查询错误');
        }

        if (!$this->getDb()->transaction(function ($db) use ($post, $userInfo) {
            if (false === $this->getDb()->where(['id' => $post['report_id'], 'law_id' => $this->userInfo['id']])->update([
                'user_id' => $userInfo ? $userInfo['id'] : 0, // 当事人是否已有账号
                'user_mobile' => $post['telephone']
            ])) {
                return false;
            }
            if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update([
                'addr'      => $post['addr'],
                'full_name' => $post['full_name'],
                'plate_num' => $post['plate_num'],
                'idcard'    => $post['idcard'],
                'gender'    => $post['gender'],
                'birthday'  => $post['birthday']
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
     * 撤销报警
     * @return array
     */
    public function cancelReportEvent (int $user_id, array $post)
    {
        $post['order_id'] = intval($post['order_id']);

        if (!$orderInfo = $this->find(['id' => $post['order_id']], 'id,user_id,status')) {
            return error('报案记录不存在');
        }

        if ($orderInfo['user_id'] != $user_id) {
            return error('你不是报案人，不能取消该报案记录');
        }

        if ($orderInfo['status'] != ReportStatus::WAITING) {
            return error('当前报案状态不能撤销');
        }

        if (!$this->getDb()->where(['id' => $orderInfo['id'], 'status' => $orderInfo['status']])->update([
            'status'      => ReportStatus::CANCEL,
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('数据更新失败');
        }

        return success('ok');
    }

}