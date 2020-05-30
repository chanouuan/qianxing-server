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
     * 保存案件当事人
     * @return array
     */
    public function saveReportPerson (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['person_id'] = intval($post['person_id']); // 有值为删除，否则为新增

        if (!$this->getMutiInfo(['id' => $post['report_id']])) {
            return error('案件未找到');
        }

        if ($post['person_id']) {
            if (!$this->getDb()->table('qianxing_report_person')->where(['id' => $post['person_id'], 'report_id' => $post['report_id']])->delete()) {
                return error('删除失败');
            }
        } else {
            if (!$post['person_id'] = $this->getDb()->table('qianxing_report_person')->insert(['report_id' => $post['report_id']], true)) {
                return error('新增失败');
            }
        }

        return success([
            'person_id' => $post['person_id']
        ]);
    }

    /**
     * 验证协同人员，获取案件信息
     * @return array
     */
    private function getMutiInfo (array $condition, $field = 'id')
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
     * 发送赔偿通知书
     * @return array
     */
    public function reportFile (array $post, array $adminCondition = [])
    {
        $post['report_id'] = intval($post['report_id']);
        $post['archive_num'] = trim_space($post['archive_num'], 0, 20); // 卷宗号
        $post['ratio'] = $post['ratio'] ? array_column($post['ratio'], 'ratio', 'id') : []; // 责任认定赔偿比例

        $condition = [
            'id' => $post['report_id'],
            'is_load' => ['>0'], // 已处置完
            'is_property' => 1, // 有路产损失
            'status' => ['in', [ReportStatus::ACCEPT, ReportStatus::HANDLED]]
        ];
        if ($adminCondition) {
            $condition += $adminCondition;
        }

        if (!$reportData = $this->find($condition, 'id,group_id,total_money')) {
            return error('案件未找到');
        }

        // 责任认定赔偿比例
        $persons = $this->getDb()->table('qianxing_report_person')->field('id,user_mobile,full_name,plate_num')->where(['report_id' => $reportData['id']])->select();
        foreach ($persons as $k => $v) {
            $persons[$k]['ratio'] = isset($post['ratio'][$v['id']]) ? floatval($post['ratio'][$v['id']]) : 0;
            $persons[$k]['money'] = bcmul($reportData['total_money'], bcdiv($persons[$k]['ratio'], 100, 4));
        }
        if (100 != array_sum(array_column($persons, 'ratio'))) {
            return error('总赔偿比例应为100%');
        }

        if (!$this->getDb()->transaction(function ($db) use ($reportData, $persons) {
            if (!$this->getDb()->where(['id' => $reportData['id'], 'status' => ['in', [ReportStatus::ACCEPT, ReportStatus::HANDLED]]])->update([
                'status' => ReportStatus::HANDLED,
                'handle_time' => date('Y-m-d H:i:s', TIMESTAMP),
                'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
            ])) {
                return false;
            }
            foreach ($persons as $k => $v) {
                if (false === $this->getDb()->table('qianxing_report_person')->where(['id' => $v['id']])->update([
                    'money' => $v['money']
                ])) {
                    return false;
                }
            }
            return true;
        })) {
            return error('数据保存失败');
        }

        // 填写卷宗号
        if ($post['archive_num']) {
            $this->getDb()->table('qianxing_report_info')->where(['id' => $reportData['id']])->update(['archive_num' => $post['archive_num']]);
        }
        
        // 删除赔偿通知书
        (new WordModel())->removeDocFile($reportData['id'], 'paynote');

        // todo 通知用户
        if ($reportData['total_money'] > 0) {
            (new MsgModel())->sendReportPaySms($persons, $reportData['group_id']);
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
        $post['items'] = is_array($post['items']) ? $post['items'] : [];
        $post['involved_action'] = is_array($post['involved_action']) ? $post['involved_action'] : [];
        $post['involved_action_type'] = is_array($post['involved_action_type']) ? $post['involved_action_type'] : [];
        $post['involved_build_project'] = trim_space($post['involved_build_project'], 0, 200);
        $post['involved_act'] = trim_space($post['involved_act'], 0, 200);
        $post['extra_info'] = trim_space($post['extra_info'], 0, 200);

        if (!$this->getMutiInfo(['id' => $post['report_id']])) {
            return error('案件未找到');
        }

        // 检查赔付清单
        foreach ($post['items'] as $k => $v) {
            $post['items'][$k]['property_id'] = intval($v['property_id']);
            $post['items'][$k]['name'] = trim_space($v['name'], 0, 50, '');
            $post['items'][$k]['price'] = intval(floatval($v['price']) * 100); // 转成分
            $post['items'][$k]['amount'] = round(floatval($v['amount']), 1); // 保留小数点后 1 位
            $post['items'][$k]['unit'] = trim_space($v['unit'], 0, 20, '');
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
                    'total_money' => bcmul($v['price'], $v['amount'])
                ];
            }
        }

        // 总金额
        $total_money = array_sum(array_column($items, 'total_money'));

        if ($total_money > 30000000) {
            return error('总金额最高不超过30万元');
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
        $reportPerson = $this->getDb()
            ->table('qianxing_report_person')
            ->field('full_name,idcard')
            ->where(['user_id' => 0, 'user_mobile' => $telephone])
            ->order('id desc')
            ->limit(1)
            ->find();
        return $reportPerson ? array_filter($reportPerson) : [];
    }

    /**
     * 关联当事人
     * @return array
     */
    public function relationCase ($user_id, $telephone)
    {
        return $this->getDb()->table('qianxing_report_person')->where(['user_id' => 0, 'user_mobile' => $telephone])->update(['user_id' => $user_id]);
    }

    /**
     * 更新现场图照
     * @return array
     */
    public function saveSitePhoto (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['remove'] = $post['remove'] ? 1 : 0;
        $post['index'] = intval($post['index']);
        $post['name'] = trim_space($post['name'], 0, 20, '');

        if (!$reportData = $this->getMutiInfo(['id' => $post['report_id']])) {
            return error('案件未找到');
        }

        if (!$reportInfo = $this->getDb()->table('qianxing_report_info')->field('site_photos')->where(['id' => $post['report_id']])->find()) {
            return error('案件信息未找到');
        }

        $site_photos = json_decode($reportInfo['site_photos'], true);
        if (!isset($site_photos[$post['index']])) {
            return error('现场图照不存在');
        }
        if (!$site_photos[$post['index']]['src']) {
            return error('请先拍照');
        }

        if ($post['remove']) {
            // 删除
            if ($post['index'] <= 4) {
                return error('不能删除预定图照');
            }
            $delFile = APPLICATION_PATH . '/public/' . $site_photos[$post['index']]['src'];
            unset($site_photos[$post['index']]);
            $site_photos = array_values($site_photos);
        } else {
            // 修改名称
            $site_photos[$post['index']]['name'] = $post['name'];
        }

        if (false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update([
            'site_photos' => json_unicode_encode($site_photos)
        ])) {
            return error('图片更新失败');
        }

        if ($delFile) {
            // 删除图片
            unlink($delFile);
        }
        return success('ok');
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

        if ($_FILES['upfile']['error'] !== 0) {
            return error('上传失败');
        }

        if (!$reportData = $this->getMutiInfo(['id' => $post['report_id']], 'id,is_load,colleague_id')) {
            return error('案件未找到');
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
        $imgUrl = $uploadfile['thumburl'] ? $uploadfile['thumburl'] : $uploadfile['url'];

        $updateInfo = [];
        $updatePerson = [];
        if ($post['report_field'] == 'site_photos') {
            // 现场图照
            if (!$reportInfo = $this->getDb()->table('qianxing_report_info')->field('site_photos')->where(['id' => $post['report_id']])->find()) {
                return error('案件信息未找到');
            }
            $reportInfo['site_photos'] = $reportInfo['site_photos'] ? json_decode($reportInfo['site_photos'], true) : [['src' => ''],['src' => ''],['src' => ''],['src' => ''],['src' => '']];
            $post['report_field_index'] = isset($reportInfo['site_photos'][$post['report_field_index']]) ? $post['report_field_index'] : count($reportInfo['site_photos']);
            $reportInfo['site_photos'][$post['report_field_index']]['src'] = $imgUrl;
            $updateInfo['site_photos'] = json_unicode_encode($reportInfo['site_photos']);
        } else {
            // 其他图照
            if (in_array($post['report_field'], [
                'signature_checker',
                'signature_writer',
            ])) {
                $updateInfo[$post['report_field']] = $imgUrl;
            } else {
                $updatePerson[$post['report_field']] = $imgUrl;
            }
        }

        // 更新当事人信息
        $updatePerson['addr'] = $post['addr'];
        $updatePerson['full_name'] = $post['name'];
        $updatePerson['idcard'] = $post['idcard'];
        $updatePerson['gender'] = $post['gender'];
        $updatePerson['birthday'] = $post['birthday'];
        $updatePerson['plate_num'] = $post['plate_num'];
        $updatePerson['car_type'] = $post['car_type'];
        $updatePerson = array_filter($updatePerson);

        // 勘验人签字时间
        if ($post['report_field'] === 'signature_checker') {
            $updateInfo['checker_time'] = date('Y-m-d H:i:s', TIMESTAMP);
        }
        // 当事人签字时间
        if ($post['report_field'] === 'signature_agent') {
            $updateInfo['agent_time'] = date('Y-m-d H:i:s', TIMESTAMP);
        }

        if (!$this->getDb()->transaction(function ($db) use ($post, $updateInfo, $updatePerson) {
            // 更新报案信息
            if ($updateInfo && false === $this->getDb()->table('qianxing_report_info')->where(['id' => $post['report_id']])->update($updateInfo)) {
                return false;
            }
            // 更新当事人信息
            if ($updatePerson && false === $this->getDb()->table('qianxing_report_person')->where(['id' => $post['report_field_index'], 'report_id' => $post['report_id']])->update($updatePerson)) {
                return false;
            }
            return true;
        })) {
            return error('图片保存失败');
        }

        // 当签字后就可以认定案件已处置完成
        if (in_array($post['report_field'], [
            'signature_checker',
            'signature_writer',
            'signature_agent',
            'signature_invitee'
        ])) {
            if (in_array($post['report_field'], [
                'signature_agent',
                'signature_invitee'
            ])) {
                $signature = '2'; // 当事人签字
            } else {
                $signature = '1'; // 路政签字
                // 两个执法人员签字完了，才可以进入下一步操作
                if ($reportData['colleague_id']) {
                    $reportInfo = $this->getDb()->table('qianxing_report_info')->field('signature_checker,signature_writer')->where(['id' => $post['report_id']])->find();
                    if ($reportInfo['signature_checker'] && $reportInfo['signature_writer']) {
                        $signature = '1';
                    } else {
                        $signature = '';
                    }
                }
            }
            if ($signature && false === strpos(strval($reportData['is_load']), $signature)) {
                $this->getDb()->where(['id' => $post['report_id']])->update([
                    'is_load' => intval(strval($reportData['is_load']) . $signature)
                ]);
            }
        }

        return success([
            'url' => httpurl($imgUrl)
        ]);
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
                1 => 3 // 已完成
            ],
            1 => [
                1 => 1, // 审理中
                2 => ['in', [2, 3]] // 已完成
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
            if (!$persons = $this->getDb()->table('qianxing_report_person')->field('report_id,full_name,plate_num,money')->where(['user_id' => $user_id])->select()) {
                return success($result);
            }
            $persons = array_column($persons, null, 'report_id');
            $condition = [
                'id' => ['in(' . implode(',', array_keys($persons)) . ')']
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

        if (!$post['islaw']) {
            // 用户端
            $infos = $this->getDb()->table('qianxing_report_info')->field('id,event_time')->where(['id' => ['in(' . implode(',', array_column($result['list'], 'id')) . ')']])->select();
            $infos = array_column($infos, null, 'id');
            $groups = (new GroupModel())->select(['id' => ['in(' . implode(',', array_column($result['list'], 'group_id')) . ')']], 'id,name');
            $groups = array_column($groups, 'name', 'id');
            foreach ($result['list'] as $k => $v) {
                $result['list'][$k]['event_time'] = $infos[$v['id']]['event_time'];
                $result['list'][$k]['full_name'] = $persons[$v['id']]['full_name'];
                $result['list'][$k]['plate_num'] = $persons[$v['id']]['plate_num'];
                $result['list'][$k]['money'] = round_dollar($persons[$v['id']]['money']);
                $result['list'][$k]['group_name'] = $groups[$v['group_id']];
            }
            unset($persons, $infos, $groups);
        }

        foreach ($result['list'] as $k => $v) {
            $result['lastpage'] = $v['id'];
            $result['list'][$k]['load2'] = false !== strpos($v['is_load'], '2') ? 1 : 0; // 当事人是否已签字
            $result['list'][$k]['stake_number'] = str_replace(' ', '', substr($v['stake_number'], 1));
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

        if (!$report_id = $this->getDb()->transaction(function ($db) use ($userReport) {
            // 新增案件
            if (!$report_id = $this->getDb()->insert([
                'group_id' => $this->userInfo['group_id'],
                'location' => $userReport['location'],
                'address' => $userReport['address'],
                'user_mobile' => $userReport['user_mobile'], // 报案人电话
                'law_id' => $this->userInfo['id'],
                'report_time' => $userReport['create_time'],
                'status' => ReportStatus::ACCEPT,
                'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
            ], true)) {
                return false;
            }
            // 新增报送信息
            if (!$this->getDb()->table('qianxing_report_info')->insert([
                'id' => $report_id,
                'event_time' => $userReport['create_time']
            ])) {
                return false;
            }
            // 新增当事人信息
            if (!$this->getDb()->table('qianxing_report_person')->insert([
                'report_id' => $report_id,
                'user_mobile' => $userReport['user_mobile'] // 当事人电话
            ])) {
                return false;
            }
            // 关联用户报案
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
        (new MsgModel())->sendUserAcceptSms($userReport['user_mobile'], $this->userInfo['group_id']);

        return success([
            'report_id' => $report_id
        ]);
    }

    /**
     * 获取案件详情
     * @return array
     */
    public function getReportDetail (array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        $reportData = [];
        if ($post['data_type'] == 'info') {
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
            $reportData['is_property'] = 1; // 默认有路产损失
            return success($reportData);
        }

        // 获取案件
        $condition = [
            'id' => $post['report_id']
        ];
        if ($post['data_type'] == 'all') {
            $field = 'id,total_money';
        } else if ($post['data_type'] == 'info') {
            $field = 'id,group_id,location,address,user_id,user_mobile,law_id,colleague_id,stake_number,total_money,is_property,is_load,status,create_time';
        } else if ($post['data_type'] == 'card') {
            $field = 'id';
        } else if ($post['data_type'] == 'paper') {
            $field = 'id,group_id,stake_number,law_id,colleague_id';
        } else if ($post['data_type'] == 'show') {
            $condition['status'] = ['in', [ReportStatus::HANDLED, ReportStatus::COMPLETE]];
            $field = 'id,address,stake_number,law_id,colleague_id,total_money,is_property,complete_time,recover_time,create_time';
        }
        if (!$reportData += $this->getMutiInfo($condition, $field)) {
            return error('案件未找到');
        }

        if (isset($reportData['total_money'])) {
            $reportData['total_money'] = round_dollar($reportData['total_money']);
        }
        
        if ($post['data_type'] == 'all') {
            // 现场处置信息
            $reportData += $this->getDb()->field('site_photos,involved_action,involved_build_project,involved_act,involved_action_type,extra_info')->table('qianxing_report_info')->where(['id' => $post['report_id']])->limit(1)->find();
            $reportData['persons'] = $this->getDb()->field('id,idcard_front,idcard_behind,driver_license_front,driver_license_behind,driving_license_front,driving_license_behind,full_name,money')->table('qianxing_report_person')->where(['report_id' => $post['report_id']])->order('id')->select();
            foreach ($reportData['persons'] as $k => $v) {
                $reportData['persons'][$k]['idcard_front'] = httpurl($v['idcard_front']);
                $reportData['persons'][$k]['idcard_behind'] = httpurl($v['idcard_behind']);
                $reportData['persons'][$k]['driver_license_front'] = httpurl($v['driver_license_front']);
                $reportData['persons'][$k]['driver_license_behind'] = httpurl($v['driver_license_behind']);
                $reportData['persons'][$k]['driving_license_front'] = httpurl($v['driving_license_front']);
                $reportData['persons'][$k]['driving_license_behind'] = httpurl($v['driving_license_behind']);
                $reportData['persons'][$k]['money'] = round_dollar($reportData['money']);
            }
        } else if ($post['data_type'] == 'info') {
            // 报送信息
            $reportData += $this->getDb()->field('check_start_time,event_time,weather,event_type,driver_state,car_state,traffic_state,pass_time')->table('qianxing_report_info')->where(['id' => $post['report_id']])->limit(1)->find();
        } else if ($post['data_type'] == 'card') {
            // 当事人信息
            $reportData['persons'] = $this->getDb()->field('id,user_mobile,addr,full_name,idcard,gender,birthday,company_name,legal_name,company_addr,plate_num,car_type')->table('qianxing_report_person')->where(['report_id' => $post['report_id']])->order('id')->select();
        } else if ($post['data_type'] == 'paper') {
            // 勘验笔录表信息
            $reportData += $this->getDb()->field('check_start_time,check_end_time,event_time,weather,involved_action,involved_build_project,involved_act,involved_action_type,extra_info,signature_checker,signature_writer,checker_time,agent_time,archive_num,site_photos')->table('qianxing_report_info')->where(['id' => $post['report_id']])->limit(1)->find();
            $reportData['stake_number'] = str_replace(' ', '', substr($reportData['stake_number'], 1));
            // 天气
            $reportData['weather'] = Weather::getMessage($reportData['weather']);
            // 勘验人和记录人的执法证号
            $lawNums = (new AdminModel())->getLawNumByUser([$reportData['law_id'], $reportData['colleague_id']]);
            $reportData['law_lawnum'] = strval($lawNums[$reportData['law_id']]);
            $reportData['colleague_lawnum'] = strval($lawNums[$reportData['colleague_id']]);
            // 卷宗号
            $reportData['way_name'] = (new GroupModel())->count(['id' => $reportData['group_id']], 'way_name');
            // 当事人列表
            $reportData['persons'] = $this->getDb()->field('id,user_mobile,full_name,company_name,plate_num,car_type,signature_agent,signature_invitee,invitee_mobile,money,idcard_front,idcard_behind,driver_license_front,driver_license_behind,driving_license_front,driving_license_behind')->table('qianxing_report_person')->where(['report_id' => $post['report_id']])->order('id')->select();
            // 车辆数
            $reportData['plate_num_count'] = count($reportData['persons']);
            // 勾选证据
            // 当事人身份证
            $reportData['data_idcard'] = 0;
            // 驾驶证
            $reportData['data_driver'] = 0;
            // 行驶证
            $reportData['data_driving'] = 0;
            // 现场照片
            $reportData['data_site'] = 0;
            $reportData['site_photos'] = json_decode($reportData['site_photos'], true);
            foreach ($reportData['site_photos'] as $k => $v) {
                if ($v['src']) {
                    $reportData['data_site'] = 1;
                    break;
                }
            }
            unset($reportData['site_photos']);
            foreach ($reportData['persons'] as $k => $v) {
                $reportData['persons'][$k]['car_type'] = CarType::getMessage($v['car_type']);
                $reportData['persons'][$k]['signature_agent'] = httpurl($v['signature_agent']);
                $reportData['persons'][$k]['signature_invitee'] = httpurl($v['signature_invitee']);
                $reportData['persons'][$k]['money'] = round_dollar($reportData['money']);
                // 勾选证据
                if ($reportData['data_idcard'] == 0 && ($v['idcard_front'] || $v['idcard_behind'])) {
                    $reportData['data_idcard'] = 1;
                }
                if ($reportData['data_driver'] == 0 && ($v['driver_license_front'] || $v['driver_license_behind'])) {
                    $reportData['data_driver'] = 1;
                }
                if ($reportData['data_driving'] == 0 && ($v['driving_license_front'] || $v['driving_license_behind'])) {
                    $reportData['data_driving'] = 1;
                }
                unset(
                    $reportData['persons'][$k]['idcard_front'],
                    $reportData['persons'][$k]['idcard_behind'],
                    $reportData['persons'][$k]['driver_license_front'],
                    $reportData['persons'][$k]['driver_license_behind'],
                    $reportData['persons'][$k]['driving_license_front'],
                    $reportData['persons'][$k]['driving_license_behind']
                );
            }
        } else if ($post['data_type'] == 'show') {
            // 展示案件信息
            $reportData += $this->getDb()->field('check_start_time,event_time,weather,event_type,driver_state,car_state,traffic_state,pass_time')->table('qianxing_report_info')->where(['id' => $post['report_id']])->limit(1)->find();
            $reportData['stake_number'] = str_replace(' ', '', substr($reportData['stake_number'], 1));
            $reportData['weather'] = Weather::getMessage($reportData['weather']);
            $reportData['event_type'] = EventType::getMessage($reportData['event_type']);
            $reportData['driver_state'] = DriverState::getMessage($reportData['driver_state']);
            $reportData['car_state'] = CarState::getMessage($reportData['car_state']);
            $reportData['traffic_state'] = TrafficState::getMessage($reportData['traffic_state']);
            // 获取勘验人和记录人的姓名
            $userNames = (new UserModel())->getUserNames([$reportData['law_id'], $reportData['colleague_id']]);
            $reportData['law_name'] = strval($userNames[$reportData['law_id']]);
            $reportData['colleague_name'] = strval($userNames[$reportData['colleague_id']]);
            // 当事人列表
            $reportData['persons'] = $this->getDb()->field('id,user_mobile,full_name,addr,company_name,legal_name,company_addr,plate_num,car_type,idcard,money')->table('qianxing_report_person')->where(['report_id' => $post['report_id']])->order('id')->select();
            foreach ($reportData['persons'] as $k => $v) {
                $reportData['persons'][$k]['car_type'] = CarType::getMessage($v['car_type']);
                $reportData['persons'][$k]['money'] = round_dollar($v['money']);
            }
        }

        if ($post['data_type'] == 'all' || $post['data_type'] == 'paper' || $post['data_type'] == 'show') {
            // 路产受损赔付清单
            $reportData['items'] = $this->getDb()->field('property_id,name,unit,price,amount,total_money')->table('qianxing_report_item')->where(['report_id' => $post['report_id']])->select();
            foreach ($reportData['items'] as $k => $v) {
                $reportData['items'][$k]['price'] = round_dollar($v['price']);
                $reportData['items'][$k]['total_money'] = round_dollar($v['total_money']);
            }
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

        return success($reportData);
    }

    /**
     * 保存案件信息
     * @return array
     */
    public function saveReportInfo (array $post)
    {
        $post['report_id'] = intval($post['report_id']);
        $post['person_id'] = intval($post['person_id']);

        $data = [];
        $data['invitee_mobile'] = $post['invitee_mobile']; // 邀请人手机

        if ($data['invitee_mobile'] && !validate_telephone($data['invitee_mobile'])) {
            return error('被邀请人手机号格式错误');
        }

        $data = array_filter($data);

        if ($data) {
            if (!$this->getMutiInfo(['id' => $post['report_id']])) {
                return error('案件未找到');
            }
            if (false === $this->getDb()->table('qianxing_report_person')->where(['id' => $post['person_id'], 'report_id' => $post['report_id']])->update($data)) {
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
        if (!$post['stake_number'] || strlen($post['stake_number']) < 2) {
            return error('请输入定位地点');
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
                if (!$this->getDb()->table('qianxing_report_person')->insert([
                    'report_id' => $report_id
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

        if (empty($post['data'])) {
            return error('数据为空');
        }

        $data = [];
        foreach ($post['data'] as $k => $v) {
            $data[$k]['report_id'] = $post['report_id'];
            $data[$k]['id'] = intval($v['id']);
            $data[$k]['company_name'] = trim_space($v['company_name'], 0, 20);
            $data[$k]['legal_name'] = trim_space($v['legal_name'], 0, 20);
            $data[$k]['company_addr'] = trim_space($v['company_addr'], 0, 50);
            $data[$k]['addr'] = trim_space($v['addr'], 0, 50);
            $data[$k]['full_name'] = trim_space($v['full_name'], 0, 20);
            $data[$k]['car_type'] = CarType::format($v['car_type']);
            $data[$k]['plate_num'] = array_unique(array_filter($v['plate_num']));
            $data[$k]['idcard'] = $v['idcard'];
            $data[$k]['gender'] = Gender::format($v['gender']);
            $data[$k]['birthday'] = strtotime($v['birthday']) ? $v['birthday'] : null;
            $data[$k]['user_mobile'] = $v['user_mobile'];
            if (!$data[$k]['id']) {
                return error('参数错误');
            }
            if (!$data[$k]['plate_num']) {
                return error('第' + ($k + 1) + '个当事人,车牌号为空');
            }
            foreach ($data[$k]['plate_num'] as $kk => $vv) {
                if (!check_car_license($vv)) {
                    return error('第' + ($k + 1) + '个当事人,车牌号“' . $vv . '”格式不正确');
                }
            }
            $data[$k]['plate_num'] = implode(',', $data[$k]['plate_num']);
            if ($data[$k]['idcard'] && !Idcard::check_id($data[$k]['idcard'])) {
                return error('第' + ($k + 1) + '个当事人,身份证号格式不正确');
            }
            if ($data[$k]['idcard']) {
                $data[$k]['gender'] = Idcard::parseidcard_getsex($data[$k]['idcard']);
                $data[$k]['birthday'] = Idcard::parseidcard_getbirth($data[$k]['idcard']);
            }
            if (!validate_telephone($data[$k]['user_mobile'])) {
                return error('第' + ($k + 1) + '个当事人,手机号格式错误');
            }
        }

        // 手机号不能重复
        if (count(array_column($data, 'id', 'user_mobile')) !== count($data)) {
            return error('当事人手机号不能重复，请检查后重新输入');
        }

        if (!$this->getMutiInfo(['id' => $post['report_id']])) {
            return error('案件未找到');
        }

        // 重新关联当事人，当事人通过 user_mobile 才能获取到订单
        $userModel = new UserModel();
        $users = $userModel->select(['telephone' => ['in', array_column($data, 'user_mobile')]], 'id,telephone,group_id');
        $users = array_column($users, null, 'telephone');
        foreach ($data as $k => $v) {
            $data[$k]['user_id'] = isset($users[$v['user_mobile']]) ? $users[$v['user_mobile']]['id'] : 0;
        }

        // 批量更新当事人信息
        if (!$this->getDb()->transaction(function ($db) use ($data) {
            foreach ($data as $k => $v) {
                if (false === $this->getDb()->table('qianxing_report_person')->where(['id' => $v['id'], 'report_id' => $v['report_id']])->update($v)) {
                    return false;
                }
            }
            return true;
        })) {
            return error('保存信息失败');
        }

        // 更新当事人账号信息
        foreach ($data as $k => $v) {
            if ($v['user_id']) {
                $param = [
                    'idcard' => $v['idcard']
                ];
                if (!$users[$v['user_mobile']]['group_id']) {
                    // 不修改路政员的姓名
                    $param['full_name'] = $v['full_name'];
                }
                $userModel->updateUserInfo($v['user_id'], $param);
            }
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