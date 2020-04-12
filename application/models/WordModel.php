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
        if (!$reportData = $this->getDb()->table('qianxing_report')->where($condition)->limit(1)->find()) {
            return [];
        }

        $groupModel = new GroupModel();
        $adminModel = new AdminModel();
        $userModel = new UserModel();

        $reportData['pay'] = round_dollar($reportData['pay']);
        $reportData['dollar'] = conver_chinese_dollar($reportData['pay']);
        $reportData += $this->getSplitDate('handle_time', $reportData['handle_time']);
        $reportData += $this->getSplitDate('create_time', $reportData['create_time']);
        unset($reportData['handle_time'], $reportData['report_time'], $reportData['create_time'], $reportData['update_time']);

        // 获取单位
        $groupInfo = $groupModel->find(['id' => $reportData['group_id']], 'parent_id,name,address,phone,way_name');
        $reportData['group_name'] = $groupInfo['name'];
        $reportData['group_address'] = $groupInfo['address'];
        $reportData['group_phone'] = $groupInfo['phone'];
        $reportData['way_name'] = $groupInfo['way_name'];
        $groupInfo = $groupModel->find(['id' => $groupInfo['parent_id']], 'name');
        $reportData['group_root_name'] = $groupInfo['name'];

        $reportData += $this->getDb()->table('qianxing_report_info')->where(['id' => $reportData['id']])->limit(1)->find();

        $reportData['gender'] = Gender::getMessage($reportData['gender']);

        // 路产受损赔付清单
        $reportData['items'] = $this->getDb()->field('name,unit,price,amount,total_money')->table('qianxing_report_item')->where(['report_id' => $reportData['id']])->select();
        $reportData['items_content'] = $this->getBRline($reportData['items']);
        unset($reportData['items']);

        // 获取勘验人和记录人的执法证号
        $lawNums = $adminModel->getLawNumByUser([$reportData['law_id'], $reportData['colleague_id']]);
        $reportData['law_lawnum'] = strval($lawNums[$reportData['law_id']]);
        $reportData['colleague_lawnum'] = strval($lawNums[$reportData['colleague_id']]);
        // 获取勘验人和记录人的姓名
        $userNames = $userModel->getUserNames([$reportData['law_id'], $reportData['colleague_id']]);
        $reportData['law_name'] = strval($userNames[$reportData['law_id']]);
        $reportData['colleague_name'] = strval($userNames[$reportData['colleague_id']]);
        
        $reportData += $this->getSplitDate('event_time', $reportData['event_time']);
        $reportData += $this->getSplitDate('check_start_time', $reportData['check_start_time']);
        $reportData += $this->getSplitDate('check_end_time', $reportData['check_end_time']);
        $reportData += $this->getSplitDate('current_date', date('Y-m-d H:i:s', TIMESTAMP));
        $reportData += $this->getSplitCheckBox('involved_action', $reportData['involved_action'], ['a', 'b', 'c', 'c1', 'c2', 'c3', 'c4', 'd', 'e']);
        $reportData += $this->getSplitCheckBox('involved_action_type', $reportData['involved_action_type'], ['a', 'b', 'c']);
        $reportData += $this->getSplitCheckBoxIf($reportData);
        unset($reportData['event_time'], $reportData['check_start_time'], $reportData['check_end_time'], $reportData['involved_action'], $reportData['involved_action_type']);

        $reportData['weather'] = Weather::getMessage($reportData['weather']);
        $reportData['car_type'] = CarType::getMessage($reportData['car_type']);

        $reportData['idcard_front'] = $this->getSplitLocalImage($reportData['idcard_front']);
        $reportData['idcard_behind'] = $this->getSplitLocalImage($reportData['idcard_behind']);
        $reportData['driver_license_front'] = $this->getSplitLocalImage($reportData['driver_license_front']);
        $reportData['driver_license_behind'] = $this->getSplitLocalImage($reportData['driver_license_behind']);
        $reportData['driving_license_front'] = $this->getSplitLocalImage($reportData['driving_license_front']);
        $reportData['driving_license_behind'] = $this->getSplitLocalImage($reportData['driving_license_behind']);
        $reportData['signature_checker'] = $this->getSplitLocalImage($reportData['signature_checker']);
        $reportData['signature_writer'] = $this->getSplitLocalImage($reportData['signature_writer']);
        $reportData['signature_agent'] = $this->getSplitLocalImage($reportData['signature_agent']);
        $reportData['signature_invitee'] = $this->getSplitLocalImage($reportData['signature_invitee']);
        $reportData += $this->getSplitPhoto($reportData['site_photos']);
        unset($reportData['site_photos']);
        
        // 分离出图片
        $images = [];
        foreach ($reportData as $k => $v) {
            if (is_array($v)) {
                $images[$k] = $v;
                unset($reportData[$k]);
            }
        }

        // 获取赔付项目
        $items = $this->getDb()->table('qianxing_report_item')->field('name,unit,price,amount,total_money')->where(['report_id' => $reportData['id']])->select();
        $rows = [];
        if ($items) {
            $items = array_pad($items, 19, []); // 补齐行数
            foreach ($items as $k => $v) {
                $rows['items'][$k]['item.index'] = $k + 1;
                $rows['items'][$k]['item.name'] = isset($v['name']) ? $v['name'] : '';
                $rows['items'][$k]['item.unit'] = isset($v['unit']) ? $v['unit'] : '';
                $rows['items'][$k]['item.amount'] = isset($v['amount']) ? $v['amount'] : '';
                $rows['items'][$k]['item.price'] = isset($v['price']) ? round_dollar($v['price']) : '';
                $rows['items'][$k]['item.total_money'] = isset($v['total_money']) ? round_dollar($v['total_money']) : '';
            }
            unset($items);
        } else {
            $reportData['item.index'] = '';
            $reportData['item.name'] = '';
            $reportData['item.unit'] = '';
            $reportData['item.amount'] = '';
            $reportData['item.price'] = '';
            $reportData['item.total_money'] = '';
        }

        return [
            'values' => $reportData,
            'images' => $images,
            'rows' => $rows
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

    private function getSplitCheckBoxIf ($data)
    {
        return [
            'involved_action.a.a' => $data['involved_action.a'] == '☑' && $data['involved_action_type.a'] == '☑' ? '☑' : '☐',
            'involved_action.a.b' => $data['involved_action.a'] == '☑' && $data['involved_action_type.b'] == '☑' ? '☑' : '☐',
            'involved_action.a.c' => $data['involved_action.a'] == '☑' && $data['involved_action_type.c'] == '☑' ? '☑' : '☐',

            'involved_action.b.a' => $data['involved_action.b'] == '☑' && $data['involved_action_type.a'] == '☑' ? '☑' : '☐',
            'involved_action.b.b' => $data['involved_action.b'] == '☑' && $data['involved_action_type.b'] == '☑' ? '☑' : '☐',
            'involved_action.b.c' => $data['involved_action.b'] == '☑' && $data['involved_action_type.c'] == '☑' ? '☑' : '☐',

            'involved_action.c.a' => $data['involved_action.c'] == '☑' && $data['involved_action_type.a'] == '☑' ? '☑' : '☐',
            'involved_action.c.b' => $data['involved_action.c'] == '☑' && $data['involved_action_type.b'] == '☑' ? '☑' : '☐',
            'involved_action.c.c' => $data['involved_action.c'] == '☑' && $data['involved_action_type.c'] == '☑' ? '☑' : '☐',

            'involved_action.d.a' => $data['involved_action.d'] == '☑' && $data['involved_action_type.a'] == '☑' ? '☑' : '☐',
            'involved_action.d.b' => $data['involved_action.d'] == '☑' && $data['involved_action_type.b'] == '☑' ? '☑' : '☐',
            'involved_action.d.c' => $data['involved_action.d'] == '☑' && $data['involved_action_type.c'] == '☑' ? '☑' : '☐',

            'involved_action.e.a' => $data['involved_action.e'] == '☑' && $data['involved_action_type.a'] == '☑' ? '☑' : '☐',
            'involved_action.e.b' => $data['involved_action.e'] == '☑' && $data['involved_action_type.b'] == '☑' ? '☑' : '☐',
            'involved_action.e.c' => $data['involved_action.e'] == '☑' && $data['involved_action_type.c'] == '☑' ? '☑' : '☐'
        ];
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