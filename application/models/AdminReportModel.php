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
            if (preg_match('/^\d+$/', $post['user_name'])) {
                if (!validate_telephone($post['user_name'])) {
                    $condition['full_name'] = $post['user_name'];
                } else {
                    $condition['user_mobile'] = $post['user_name'];
                }
            } else {
                $condition['full_name'] = $post['user_name'];
            }
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
            if (!$data = (new AdminModel())->find($userCondition, 'id')) {
                return success([
                    'total_count' => 0,
                    'page_size' => $post['page_size'],
                    'list' => []
                ]);
            }
            $condition['law_id'] = $data['id'];
        }

        $count = $this->getDb()
                      ->table('qianxing_report report left join qianxing_report_info info on info.id = report.id')
                      ->where($condition)
                      ->count();
        $list  = [];
        if ($count > 0) {
            if (!$post['export']) {
                $pagesize = getPageParams($post['page'], $count, $post['page_size']);
            }
            $list = $this->getDb()
                         ->table('qianxing_report report left join qianxing_report_info info on info.id = report.id')
                         ->field('report.id,law_id,user_mobile,address,stake_number,pay,cash,total_money,create_time,status,full_name,plate_num,archive_num')
                         ->where($condition)
                         ->order('report.id desc')
                         ->limit($pagesize['limitstr'])
                         ->select();
            $userNames = (new AdminModel())->getAdminNames(array_column($list, 'law_id'));
            foreach ($list as $k => $v) {
                $list[$k]['pay'] = round_dollar($v['pay']);
                $list[$k]['cash'] = round_dollar($v['cash']);
                $list[$k]['total_money'] = round_dollar($v['total_money']);
                $list[$k]['law_name'] = $userNames[$v['law_id']];
                $list[$k]['status_str'] = ReportStatus::getMessage($v['status']);
                $list[$k]['address'] = $v['stake_number'] ? $v['stake_number'] : $v['address'];
                $list[$k]['create_time'] = substr($v['create_time'], 0, 16);
            }
            unset($userNames);
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

        if (!$reportData = $this->find(['id' => $post['report_id'], 'group_id' => $this->userInfo['group_id']], 'id,group_id,location,address,user_id,user_mobile,law_id,colleague_id,stake_number,pay,cash,total_money,status,create_time')) {
            return error('案件未找到');
        }

        $reportData['pay'] = round_dollar($reportData['pay']);
        $reportData['cash'] = round_dollar($reportData['cash']);
        $reportData['total_money'] = round_dollar($reportData['total_money']);
        $reportData['status_str'] = ReportStatus::getMessage($reportData['status']);

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

        $reportData += $this->getDb()->table('qianxing_report_info')->where(['id' => $reportData['id']])->limit(1)->find();
        $reportData['gender'] = Gender::getMessage($reportData['gender']);
        $reportData['weather'] = Weather::getMessage($reportData['weather']);
        $reportData['car_type'] = CarType::getMessage($reportData['car_type']);
        $reportData['event_type'] = EventType::getMessage($reportData['event_type']);
        $reportData['driver_state'] = DriverState::getMessage($reportData['driver_state']);
        $reportData['car_state'] = CarState::getMessage($reportData['car_state']);
        $reportData['traffic_state'] = TrafficState::getMessage($reportData['traffic_state']);

        return success($reportData);
    }

    /**
     * 转发赔偿通知书
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

        if ($post['money'] <= 0) {
            return error('金额不能为空');
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

        if ($post['money'] === $discost) {
            // 推送通知
            (new MsgModel())->sendReportCompleteSms($reportData['law_id'], $reportData['id']);
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