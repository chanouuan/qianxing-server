<?php

namespace app\models;

use Crud;
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

class WordModel extends Crud {

    /**
     * 获取 word 下载路径
     * @return string
     */
    public function getSavePath ($id, $suffix = '')
    {
        $id = intval($id);
        return 'docfile/' . ($id % 512) . '/' . md5($id . $suffix) . '.docx';
    }

    /**
     * 获取案件数据
     * @return array
     */
    public function getReportData (array $condition)
    {
        if (!$reportInfo = $this->getDb()->table('qianxing_report')->field('id,group_id,location,address,user_id,user_mobile,law_id,colleague_id,stake_number,pay,status,create_time')->where($condition)->limit(1)->find()) {
            return [];
        }

        $reportInfo['pay'] = round_dollar($reportInfo['pay']);

        // 获取单位
        $groupInfo = (new GroupModel())->find(['id' => $reportInfo['group_id']], 'name');
        $reportInfo['group_name'] = $groupInfo['name'];

        $reportInfo += $this->getDb()->table('qianxing_report_info')->where(['id' => $reportInfo['id']])->limit(1)->find();

        // 路产受损赔付清单
        $reportInfo['items'] = $this->getDb()->field('name,unit,price,amount,total_money')->table('qianxing_report_item')->where(['report_id' => $reportInfo['id']])->select();
        $reportInfo['items_content'] = $this->getBRline($reportInfo['items']);
        unset($reportInfo['items']);

        // 获取勘验人和记录人的执法证号
        $lawNums = (new AdminModel())->getLawNumByUser([$reportInfo['law_id'], $reportInfo['colleague_id']]);
        $reportInfo['law_lawnum'] = strval($lawNums[$reportInfo['law_id']]);
        $reportInfo['colleague_lawnum'] = strval($lawNums[$reportInfo['colleague_id']]);
        // 获取勘验人和记录人的姓名
        $userNames = (new UserModel())->getUserNames([$reportInfo['law_id'], $reportInfo['colleague_id']]);
        $reportInfo['law_name'] = strval($userNames[$reportInfo['law_id']]);
        $reportInfo['colleague_name'] = strval($userNames[$reportInfo['colleague_id']]);
        
        $reportInfo += $this->getSplitDate('event_time', $reportInfo['event_time']);
        $reportInfo += $this->getSplitDate('check_start_time', $reportInfo['check_start_time']);
        $reportInfo += $this->getSplitDate('check_end_time', $reportInfo['check_end_time']);
        $reportInfo += $this->getSplitDate('current_date', date('Y-m-d H:i:s', TIMESTAMP));
        $reportInfo += $this->getSplitCheckBox('involved_action', $reportInfo['involved_action'], ['a', 'b', 'c', 'c1', 'c2', 'c3', 'c4', 'd', 'e']);
        $reportInfo += $this->getSplitCheckBox('involved_action_type', $reportInfo['involved_action_type'], ['a', 'b', 'c']);
        unset($reportInfo['event_time'], $reportInfo['check_start_time'], $reportInfo['check_end_time'], $reportInfo['involved_action'], $reportInfo['involved_action_type']);

        $reportInfo['weather'] = Weather::getMessage($reportInfo['weather']);
        $reportInfo['car_type'] = CarType::getMessage($reportInfo['car_type']);

        $reportInfo['idcard_front'] = $this->getSplitLocalImage($reportInfo['idcard_front']);
        $reportInfo['idcard_behind'] = $this->getSplitLocalImage($reportInfo['idcard_behind']);
        $reportInfo['driver_license_front'] = $this->getSplitLocalImage($reportInfo['driver_license_front']);
        $reportInfo['driver_license_behind'] = $this->getSplitLocalImage($reportInfo['driver_license_behind']);
        $reportInfo['driving_license_front'] = $this->getSplitLocalImage($reportInfo['driving_license_front']);
        $reportInfo['driving_license_behind'] = $this->getSplitLocalImage($reportInfo['driving_license_behind']);
        $reportInfo['signature_checker'] = $this->getSplitLocalImage($reportInfo['signature_checker']);
        $reportInfo['signature_writer'] = $this->getSplitLocalImage($reportInfo['signature_writer']);
        $reportInfo['signature_agent'] = $this->getSplitLocalImage($reportInfo['signature_agent']);
        $reportInfo['signature_invitee'] = $this->getSplitLocalImage($reportInfo['signature_invitee']);
        $reportInfo += $this->getSplitPhoto($reportInfo['site_photos']);
        unset($reportInfo['site_photos']);
        
        // 分离出图片
        $images = [];
        foreach ($reportInfo as $k => $v) {
            if (is_array($v)) {
                $images[$k] = $v;
                unset($reportInfo[$k]);
            }
        }

        return [
            'values' => $reportInfo,
            'images' => $images
        ];
    }

    private function getSplitLocalImage ($path, array $options = [])
    {
        if (!$path) {
            return '';
        }
        $path = APPLICATION_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $path;
        if (!file_exists($path)) {
            return '';
        }
        return array_merge([
            'path' => $path,
            'height' => 22,
            'ratio' => true
        ], $options);
    }

    private function getSplitPhoto ($data)
    {
        if (!$data) {
            return [];
        }
        $result = [];
        $data = json_decode($data, true);
        foreach ($data as $k => $v) {
            $result['site_photos.' . $k] = $this->getSplitLocalImage($v['src'], [
                'width' => 275,
                'height' => 280,
                'ratio' => false
            ]);
        }
        return $result;
    }

    private function getBRline (array $data)
    {
        $result = [];
        foreach ($data as $k => $v) {
            $result[] = ($k + 1) . '. ' . $v['name'] . $v['amount'] . $v['unit'];
        }
        $result = implode('；', $result);
        $result = mb_str_split($result, 1);
        $result = array_pad($result, 100, ''); // 填充占位符
        return implode('', $result);
    }

    private function getSplitCheckBox ($lit, $data, array $target = [])
    {
        if (!$data) {
            return [];
        }
        $result = [];
        $data = json_decode($data, true);
        foreach ($target as $k => $v) {
            $result[$lit . '.' . $v] = $data[$v] ? '☑' : '☐';
        }
        return $result;
    }

    private function getSplitDate ($lit, $date)
    {
        $date = strtotime($date);
        return [
            $lit . '.year' => date('Y', $date),
            $lit . '.month' => date('n', $date),
            $lit . '.day' => date('j', $date),
            $lit . '.hour' => date('G', $date),
            $lit . '.minute' => intval(date('i', $date))
        ];
    }

}