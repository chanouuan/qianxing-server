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
     * word2pdf
     * @return string
     */
    private function word2pdf ($docfile_path, $replace = false)
    {
        if (PHP_OS !== 'Linux') {
            return httpurl($docfile_path);
        }
        $pdf_path = str_replace('.docx', '.pdf', $docfile_path);
        if (!$replace && file_exists(APPLICATION_PATH . '/public/' . $pdf_path)) {
            return httpurl($pdf_path);
        } 
        shell_exec('sudo /usr/bin/unoconv -f pdf ' . APPLICATION_PATH . '/public/' . $docfile_path);
        return file_exists(APPLICATION_PATH . '/public/' . $pdf_path) ? httpurl($pdf_path) : null;
    }

    /**
     * 生成 word
     * @return string
     */
    public function createNote ($file_name, array $condition, $output_format = 'docx', $replace = false)
    {
        $templateSource = APPLICATION_PATH . '/public/static/word_template/' . $file_name . '.docx';
        $templateSaveAs = $this->getSavePath($condition['id'], $file_name);
        
        if (!$replace && file_exists(APPLICATION_PATH . '/public/' . $templateSaveAs)) {
            $url = httpurl($templateSaveAs);
            if ($output_format == 'pdf') {
                if (!$url = $this->word2pdf($templateSaveAs)) {
                    return error('预览文书未生成，请重试！');
                }
            }
            return success([
                'url' => $url
            ]);
        }

        $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templateSource);

        $variables = $templateProcessor->getVariables();

        if (!$data = $this->getReportData($condition)) {
            return error('数据为空');
        }
        
        if ($data['values'] && array_intersect(array_keys($data['values']), $variables)) {
            $templateProcessor->setValues($data['values']);
        }
        if ($data['images'] && array_intersect(array_keys($data['images']), $variables)) {
            $templateProcessor->setImageValue(array_keys($data['images']), $data['images']);
        }
        if ($data['rows']['items'] && in_array('item.index', $variables)) {
            $templateProcessor->cloneRowAndSetValues('item.index', $data['rows']['items']);
        }

        mkdirm(dirname(APPLICATION_PATH . '/public/' . $templateSaveAs));
        $templateProcessor->saveAs(APPLICATION_PATH . '/public/' . $templateSaveAs);

        unset($data);
        if (!file_exists(APPLICATION_PATH . '/public/' . $templateSaveAs)) {
            return error('文书未生成，请重试！');
        }
        $url = httpurl($templateSaveAs);
        if ($output_format == 'pdf') {
            if (!$url = $this->word2pdf($templateSaveAs, true)) {
                return error('文书尚未生成，请重试！');
            }
        }
        return success([
            'url' => $url
        ]);
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

        $reportData['total_money'] = round_dollar($reportData['total_money']);
        $reportData['dollar'] = conver_chinese_dollar($reportData['total_money']);
        $reportData += $this->getSplitDate('handle_time', $reportData['handle_time']);
        $reportData += $this->getSplitDate('create_time', $reportData['create_time']);
        $reportData += $this->getSplitDate('complete_time', $reportData['complete_time']);
        unset($reportData['handle_time'], $reportData['report_time'], $reportData['create_time'], $reportData['complete_time'], $reportData['update_time']);

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
        $reportData['law_colleague_lawnum'] = implode('、', array_filter([$reportData['law_lawnum'], $reportData['colleague_lawnum']]));
        // 获取勘验人和记录人的姓名
        $userNames = $userModel->getUserNames([$reportData['law_id'], $reportData['colleague_id']]);
        $reportData['law_name'] = strval($userNames[$reportData['law_id']]);
        $reportData['colleague_name'] = strval($userNames[$reportData['colleague_id']]);
        $reportData['law_colleague_name'] = implode('、', array_filter([$reportData['law_name'], $reportData['colleague_name']]));

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

        $reportData['idcard_front'] = $this->getSplitLocalImage($reportData['idcard_front'], [
            'width' => 600,
            'height' => 'auto',
            'ratio' => false
        ]);
        $reportData['idcard_behind'] = $this->getSplitLocalImage($reportData['idcard_behind'], [
            'width' => 600,
            'height' => 'auto',
            'ratio' => false
        ]);
        $reportData['driver_license_front'] = $this->getSplitLocalImage($reportData['driver_license_front'], [
            'width' => 300,
            'height' => 'auto',
            'ratio' => false
        ]);
        $reportData['driver_license_behind'] = $this->getSplitLocalImage($reportData['driver_license_behind'], [
            'width' => 300,
            'height' => 'auto',
            'ratio' => false
        ]);
        $reportData['driving_license_front'] = $this->getSplitLocalImage($reportData['driving_license_front'], [
            'width' => 300,
            'height' => 'auto',
            'ratio' => false
        ]);
        $reportData['driving_license_behind'] = $this->getSplitLocalImage($reportData['driving_license_behind'], [
            'width' => 300,
            'height' => 'auto',
            'ratio' => false
        ]);
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

        $reportData['item_name'] = [];
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
                if ($v) {
                    $reportData['item_name'][] = ($k + 1) . '、' . $v['name'] . $v['amount'] . $v['unit'];
                }
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
        $reportData['item_name'] = implode('；', $reportData['item_name']);

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
        if ($options['height'] == 'auto') {
            $info = getimagesize($path);
            $options['width'] = !$options['width'] || ($options['width'] > $info[0]) ? $info[0] :  $options['width'];
            $ratio = $options['width'] / $info[0];
            $options['height'] = intval($info[1] * $ratio);
        }
        return array_merge([
            'path' => $path,
            'height' => 30,
            'ratio' => true
        ], $options);
    }

    private function getSplitPhoto ($data)
    {
        $data = $data ? json_decode($data, true) : [['src' => ''],['src' => ''],['src' => ''],['src' => ''],['src' => '']];
        $result = [];
        foreach ($data as $k => $v) {
            $result['site_photos.' . $k] = $this->getSplitLocalImage($v['src'], [
                'width' => 320,
                'height' => 'auto',
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
        // $result = array_pad($result, 100, ''); // 填充占位符
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