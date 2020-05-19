<?php

namespace app\models;

use Crud;
use app\common\Gender;
use app\common\ReportStatus;
use app\common\Weather;
use app\common\CarType;
use app\common\EventType;
use app\common\DriverState;
use app\common\CarState;
use app\common\TrafficState;

class AdminReportModel extends Crud {

    protected $table = 'qianxing_report';
    protected $userInfo = null;

    public function __construct (int $user_id)
    {
        $this->userInfo = (new AdminModel())->checkAdminInfo($user_id);
    }

    /**
     * 生成卷宗
     * @return array
     */
    public function createArchive (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['archive_num'] = trim_space($post['archive_num'], 0, 20);

        if (!$post['archive_num']) {
            return error('卷宗号不能为空');
        }

        if (!$this->count(['id' => $post['report_id'], 'group_id' => $this->userInfo['group_id'], 'status' => ReportStatus::COMPLETE])) {
            return error('案件未找到');
        }

        if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update([
            'archive_num' => $post['archive_num']
        ])) {
            return error('数据更新失败');
        }

        // 删除卷宗文件
        (new WordModel())->removeDocFile($post['report_id'], 'allnote');

        return success('ok');
    }

    /**
     * 获取案件列表
     * @return array
     */
    public function getReportList (array $post)
    {
        $post['page_size'] = max(6, $post['page_size']);
        $post['law_name'] = trim_space($post['law_name']);
        $post['user_name'] = trim_space($post['user_name']);

        // 条件查询
        $condition = [
            'group_id' => $this->userInfo['group_id']
        ];

        // 搜索时间
        $post['start_time'] = strtotime($post['start_time']);
        $post['end_time'] = strtotime($post['end_time']);

        if ($post['start_time'] && $post['end_time'] && $post['start_time'] <= $post['end_time']) {
            $condition['create_time'] = ['between', [date('Y-m-d H:i:s', $post['start_time']), date('Y-m-d H:i:s', $post['end_time'])]];
        }

        // 搜索状态
        if (!is_null(ReportStatus::format($post['status']))) {
            $condition['status'] = $post['status'];
        }

        // 搜索当事人
        if ($post['user_name']) {
            $personCondition = [];
            if (preg_match('/^\d+$/', $post['user_name'])) {
                if (!validate_telephone($post['user_name'])) {
                    $personCondition['full_name'] = $post['user_name'];
                } else {
                    $personCondition['user_mobile'] = $post['user_name'];
                }
            } else {
                $personCondition['full_name'] = $post['user_name'];
            }
            $persons = $this->getDb()->table('qianxing_report_person')->field('report_id')->where($personCondition)->select();
            if (!$persons) {
                return success([
                    'total_count' => 0,
                    'page_size' => $post['page_size'],
                    'list' => []
                ]);
            }
            $condition['id'] = ['in(' . implode(',', array_column($persons, 'report_id')) . ')'];
        }

        // 搜索执法人员
        if ($post['law_name']) {
            $userCondition = [
                'group_id' => $condition['group_id']
            ];
            if (preg_match('/^\d+$/', $post['law_name'])) {
                if (!validate_telephone($post['law_name'])) {
                    $userCondition['full_name'] = $post['law_name'];
                } else {
                    $userCondition['telephone'] = $post['law_name'];
                }
            } else {
                $userCondition['full_name'] = $post['law_name'];
            }
            $data = (new UserModel())->find($userCondition, 'id');
            if (!$data) {
                return success([
                    'total_count' => 0,
                    'page_size' => $post['page_size'],
                    'list' => []
                ]);
            }
            $condition['law_id'] = $data['id'];
        }

        $count = $this->count($condition);
        $list  = [];
        if ($count > 0) {
            if (!$post['export']) {
                $pagesize = getPageParams($post['page'], $count, $post['page_size']);
            }
            $list = $this->select($condition, 'id,law_id,address,stake_number,pay,cash,total_money,create_time,is_load,recover_time,status', 'id desc', $pagesize['limitstr']);
            $userNames = (new UserModel())->getUserNames(array_column($list, 'law_id'));
            $ids = implode(',', array_column($list, 'id'));
            $infos = $this->getDb()->table('qianxing_report_info')->field('id,archive_num')->where(['id' => ['in(' . $ids . ')']])->select();
            $infos = array_column($infos, null, 'id');
            $persons = $this->getDb()->table('qianxing_report_person')->field('report_id,full_name,user_mobile,plate_num')->where(['report_id' => ['in(' . $ids . ')']])->select();
            $rs = [];
            foreach ($persons as $k => $v) {
                $rs[$v['report_id']][] = $v;
            }
            $persons = [];
            foreach ($rs as $k => $v) {
                $persons[$k]['full_name'] = implode(' ', array_filter(array_column($v, 'full_name')));
                $persons[$k]['user_mobile'] = implode(' ', array_filter(array_column($v, 'user_mobile')));
                $persons[$k]['plate_num'] = implode(' ', array_filter(array_column($v, 'plate_num')));
            }
            foreach ($list as $k => $v) {
                $list[$k]['pay'] = round_dollar($v['pay']);
                $list[$k]['cash'] = round_dollar($v['cash']);
                $list[$k]['total_money'] = round_dollar($v['total_money']);
                $list[$k]['law_name'] = $userNames[$v['law_id']];
                $list[$k]['status_str'] = ReportStatus::remark($v['status'], $v['recover_time']);
                $list[$k]['address'] = $v['stake_number'] ? str_replace(' ', '', substr($v['stake_number'], 1)) : $v['address'];
                $list[$k]['create_time'] = substr($v['create_time'], 0, 16);
                $list[$k]['full_name'] = strval($persons[$v['id']]['full_name']);
                $list[$k]['user_mobile'] = strval($persons[$v['id']]['user_mobile']);
                $list[$k]['plate_num'] = strval($persons[$v['id']]['plate_num']);
                $list[$k]['archive_num'] = $infos[$v['id']]['archive_num'];
            }
            unset($userNames, $infos, $rs, $persons);
        }

        // 导出
        if ($post['export']) {
            $input = [];
            foreach ($list as $k => $v) {
                $input[] = [
                    $v['id'], 
                    $v['full_name'], 
                    $v['user_mobile'], 
                    $v['address'], 
                    $v['plate_num'],
                    $v['pay'],
                    $v['cash'],
                    $v['total_money'],
                    $v['law_name'],
                    $v['create_time'],
                    $v['status_str']
                ];
            }
            export_csv_data('案件列表', '编号,当事人,手机号,事发地点,车牌号,已线上支付,已现金支付,应付金额,执法人员,受理时间,案件状态', $input);
        }

        return success([
            'total_count' => $count,
            'page_size' => $post['page_size'],
            'list' => $list
        ]);
    }

    /**
     * 获取案件详情
     * @return array
     */
    public function getReportDetail (array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        if (!$reportData = $this->find(['id' => $post['report_id'], 'group_id' => $this->userInfo['group_id']], 'id,group_id,location,address,user_id,user_mobile,law_id,colleague_id,stake_number,pay,cash,total_money,status,create_time,is_load,is_property,recover_time,complete_time')) {
            return error('案件未找到');
        }

        $reportData['pay'] = round_dollar($reportData['pay']);
        $reportData['cash'] = round_dollar($reportData['cash']);
        $reportData['total_money'] = round_dollar($reportData['total_money']);
        $reportData['status_str'] = ReportStatus::remark($reportData['status'], $reportData['recover_time']);
        $reportData['stake_number'] = str_replace(' ', '', substr($reportData['stake_number'], 1));

        $adminModel = new AdminModel();
        $userModel = new UserModel();
        $groupModel = new GroupModel();

        // 获取单位
        $groupInfo = $groupModel->find(['id' => $reportData['group_id']], 'name,way_name');
        $reportData['group_name'] = $groupInfo['name'];
        $reportData['way_name'] = $groupInfo['way_name'];

        // 获取勘验人和记录人的执法证号
        $lawNums = $adminModel->getLawNumByUser([$reportData['law_id'], $reportData['colleague_id']]);
        $reportData['law_lawnum'] = strval($lawNums[$reportData['law_id']]);
        $reportData['colleague_lawnum'] = strval($lawNums[$reportData['colleague_id']]);
        
        // 获取勘验人和记录人的姓名
        $userNames = $userModel->getUserNames([$reportData['law_id'], $reportData['colleague_id']]);
        $reportData['law_name'] = strval($userNames[$reportData['law_id']]);
        $reportData['colleague_name'] = strval($userNames[$reportData['colleague_id']]);
        
        // 路产受损赔付清单
        $reportData['items'] = $this->getDb()->field('id,name,unit,price,amount,total_money')->table('qianxing_report_item')->where(['report_id' => $reportData['id']])->select();
        foreach ($reportData['items'] as $k => $v) {
            $reportData['items'][$k]['price'] = round_dollar($v['price']);
            $reportData['items'][$k]['total_money'] = round_dollar($v['total_money']);
        }

        // 获取现金收款记录
        $reportData['cash_log'] = $this->getDb()->table('qianxing_report_cash_log')->field('id,user_name,amount,create_time')->where(['report_id' => $reportData['id']])->select();
        foreach ($reportData['cash_log'] as $k => $v) {
            $reportData['cash_log'][$k]['amount'] = round_dollar($v['amount']);
        }

        // 报送信息
        $reportData += $this->getDb()->table('qianxing_report_info')->where(['id' => $reportData['id']])->limit(1)->find();
        $reportData['weather'] = Weather::getMessage($reportData['weather']);
        $reportData['event_type'] = EventType::getMessage($reportData['event_type']);
        $reportData['driver_state'] = DriverState::getMessage($reportData['driver_state']);
        $reportData['car_state'] = CarState::getMessage($reportData['car_state']);
        $reportData['traffic_state'] = TrafficState::getMessage($reportData['traffic_state']);
        if ($reportData['site_photos']) {
            $reportData['site_photos'] = json_decode($reportData['site_photos'], true);
            foreach ($reportData['site_photos'] as $k => $v) {
                $reportData['site_photos'][$k]['src'] = httpurl($v['src']);
            }
        }

        // 当事人信息
        $reportData['persons'] = $this->getDb()->table('qianxing_report_person')->field('id,full_name,gender,birthday,addr,idcard,user_mobile,car_type,plate_num,money')->where(['report_id' => $reportData['id']])->order('id')->select();
        foreach ($reportData['persons'] as $k => $v) {
            $reportData['persons'][$k]['gender'] = Gender::getMessage($v['gender']);
            $reportData['persons'][$k]['car_type'] = CarType::getMessage($v['car_type']);
            $reportData['persons'][$k]['money'] = round_dollar($v['money']);
        }

        // 附件
        $reportData['attachment'] = (new AttachmentModel())->select(['report_id' => $reportData['id']], 'id,name,src', 'id');
        foreach ($reportData['attachment'] as $k => $v) {
            $reportData['attachment'][$k]['src'] = httpurl($v['src']);
        }
        
        return success($reportData);
    }

    /**
     * 发送赔偿通知书
     * @return array
     */
    public function reportFile (array $post)
    {
        return (new ReportModel())->reportFile($post, [
            'group_id' => $this->userInfo['group_id']
        ]);
    }

    /**
     * 代收现金
     * @return array
     */
    public function reportPayCash (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['money'] = intval(floatval($post['money']) * 100);

        // 金额有可能为0,但不能小于0
        if ($post['money'] < 0) {
            return error('金额不能小于0');
        }

        $condition = [
            'id' => $post['report_id'], 
            'status' => ReportStatus::HANDLED,
            'group_id' => $this->userInfo['group_id']
        ];

        if (!$reportData = $this->find($condition, 'id,law_id,pay,cash,total_money')) {
            return error('案件未找到');
        }

        // 效验金额
        $discost = $reportData['total_money'] - $reportData['pay'] - $reportData['cash'];
        if ($post['money'] > $discost) {
            return error('付款金额不能超过应付金额');
        }

        if (!$this->getDb()->where(['id' => $reportData['id']])->update([
            'status' => ['if(pay+cash+'.$post['money'].'=total_money,'.ReportStatus::COMPLETE.',status)'],
            'cash' => ['if(pay+cash+'.$post['money'].'>total_money,cash,cash+'.$post['money'].')'],
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP),
            'complete_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return error('更新数据失败');
        }

        if ($post['money'] > 0) {
            // 收款记录
            $this->getDb()->table('qianxing_report_cash_log')->insert([
                'report_id' => $reportData['id'],
                'group_id' => $this->userInfo['group_id'],
                'user_id' => $this->userInfo['id'],
                'user_name' => $this->userInfo['nick_name'],
                'amount' => $post['money'],
                'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
            ]);
        }

        // 更新统计
        if ($post['money'] === $discost) {
            (new ReportModel())->reportCompleteCall($post['report_id']);
        }

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