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
     * 删除文件
     * @return bool
     */
    public function removeDocFile ($id, $suffix = '')
    {
        $path = $this->getSavePath($id, $suffix);
        return unlink(APPLICATION_PATH . '/public/' . $path);
    }

    /**
     * word2pdf
     * @return string
     */
    private function word2pdf ($docfile_path, $replace = false)
    {
        $pdf_path = str_replace('.docx', '.pdf', $docfile_path);
        if (!$replace && file_exists(APPLICATION_PATH . '/public/' . $pdf_path)) {
            return httpurl($pdf_path);
        }
        if (PHP_OS !== 'Linux') {
            return httpurl($docfile_path);
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
        if (!$reportData = $this->getDb()->table('qianxing_report')->field('id,group_id')->where(isset($condition['report']) ? $condition['report'] : $condition)->limit(1)->find()) {
            return error('案件未找到');
        }

        // 保存路径
        $templateSaveAs = $this->getSavePath($reportData['id'], is_array($file_name) && isset($file_name[0]) ? $file_name[0] : $file_name);
        
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

        // 模板路径
        $temp_name = is_array($file_name) && isset($file_name[1]) ? $file_name[1] : $file_name;
        $templateSource = APPLICATION_PATH . '/public/static/word_template/' . $temp_name . $reportData['group_id'] . '.docx';
        if (!file_exists($templateSource)) {
            $templateSource = APPLICATION_PATH . '/public/static/word_template/' . $temp_name . '.docx';
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
        if (!$reportData = $this->getDb()->table('qianxing_report')->where(isset($condition['report']) ? $condition['report'] : $condition)->limit(1)->find()) {
            return [];
        }

        $groupModel = new GroupModel();
        $adminModel = new AdminModel();
        $userModel = new UserModel();

        // 报送信息
        $reportData += $this->getDb()->table('qianxing_report_info')->where(['id' => $reportData['id']])->limit(1)->find();
        // 单位
        $groupInfo = $groupModel->find(['id' => $reportData['group_id']], 'parent_id,name,address,phone,way_name');
        $groupRootInfo = $groupModel->find(['id' => $groupInfo['parent_id']], 'name');
        // 当事人
        $personCondition = [
            'report_id' => $reportData['id']
        ];
        if (isset($condition['person'])) {
            $personCondition += $condition['person'];
        }
        $persons = $this->getDb()->table('qianxing_report_person')->where($personCondition)->order('id')->select();
        foreach ($persons as $k => $v) {
            $persons[$k]['gender'] = Gender::getMessage($v['gender']);      
            $persons[$k]['car_type'] = CarType::getMessage($v['car_type']);
            $persons[$k]['money'] = round_dollar($v['money']);
        }
        // 路产列表
        $items = $this->getDb()->field('name,unit,price,amount,total_money')->table('qianxing_report_item')->where(['report_id' => $reportData['id']])->select();
        foreach ($items as $k => $v) {
            $items[$k]['price'] = round_dollar($v['price']);
            $items[$k]['total_money'] = round_dollar($v['total_money']);
        }

        // ====== 格式化数据 ======

        // 案件信息
        $reportData['stake_number'] = str_replace(' ', '', substr($reportData['stake_number'], 1));
        $reportData['total_money'] = round_dollar($reportData['total_money']);
        $reportData['dollar'] = conver_chinese_dollar($reportData['total_money']);
        $reportData['weather'] = Weather::getMessage($reportData['weather']);

        // 单位信息
        $reportData['group_name'] = $groupInfo['name'];
        $reportData['group_address'] = $groupInfo['address'];
        $reportData['group_phone'] = $groupInfo['phone'];
        $reportData['way_name'] = $groupInfo['way_name'];
        $reportData['group_root_name'] = $groupRootInfo['name'];

        // 时间
        $reportData += $this->getSplitDate('handle_time', $reportData['handle_time']);
        $reportData += $this->getSplitDate('create_time', $reportData['create_time']);
        $reportData += $this->getSplitDate('complete_time', $reportData['complete_time']);
        $reportData += $this->getSplitDate('event_time', $reportData['event_time']);
        $reportData += $this->getSplitDate('check_start_time', $reportData['check_start_time']);
        $reportData += $this->getSplitDate('check_end_time', $reportData['check_end_time']);
        $reportData += $this->getSplitDate('checker_time', $reportData['checker_time']);
        $reportData += $this->getSplitDate('agent_time', $reportData['agent_time']);
        unset(
            $reportData['handle_time'], 
            $reportData['report_time'], 
            $reportData['create_time'], 
            $reportData['complete_time'], 
            $reportData['update_time'],
            $reportData['event_time'],
            $reportData['check_start_time'],
            $reportData['check_end_time'],
            $reportData['checker_time'],
            $reportData['agent_time']
        );

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

        // 涉案行为
        $reportData += $this->getSplitCheckBox('involved_action', $reportData['involved_action'], ['a', 'b', 'c', 'c1', 'c2', 'c3', 'c4', 'd', 'e']);
        $reportData += $this->getSplitCheckBox('involved_action_type', $reportData['involved_action_type'], ['a', 'b', 'c']);
        $reportData += $this->getSplitCheckBoxIf($reportData);
        $reportData['involved_name'] = [
            $reportData['involved_action_type.a'] == $this->checked(true) ? '损坏' : null,
            $reportData['involved_action_type.b'] == $this->checked(true) ? '污染' : null,
            $reportData['involved_action_type.c'] == $this->checked(true) ? '占用' : null
        ];
        $reportData['involved_name'] = array_filter($reportData['involved_name']);
        $reportData['involved_name'] = $reportData['involved_name'] ? $reportData['involved_name'] : ['损坏'];
        $reportData['involved_name'] = implode('、', $reportData['involved_name']);
        unset(
            $reportData['involved_action'], 
            $reportData['involved_action_type']
        );

        // 路产信息
        $reportData['items_content'] = $this->getBRline($items);

        // 当事人信息（先实现单个当事人的卷宗，后期做多当事人）
        $reportData['plate_num_count'] = 1;
        $reportData['user_mobile'] = $persons[0]['user_mobile'];
        $reportData['addr'] = $persons[0]['addr'];
        $reportData['full_name'] = $persons[0]['full_name'];
        $reportData['idcard'] = $persons[0]['idcard'];
        $reportData['gender'] = $persons[0]['gender'];
        $reportData['birthday'] = $persons[0]['birthday'];
        $reportData['company_name'] = $persons[0]['company_name'];
        $reportData['legal_name'] = $persons[0]['legal_name'];
        $reportData['company_addr'] = $persons[0]['company_addr'];
        $reportData['plate_num'] = $persons[0]['plate_num'];
        $reportData['car_type'] = $persons[0]['car_type'];
        $reportData['idcard_front'] = $persons[0]['idcard_front'];
        $reportData['idcard_behind'] = $persons[0]['idcard_behind'];
        $reportData['driver_license_front'] = $persons[0]['driver_license_front'];
        $reportData['driver_license_behind'] = $persons[0]['driver_license_behind'];
        $reportData['driving_license_front'] = $persons[0]['driving_license_front'];
        $reportData['driving_license_behind'] = $persons[0]['driving_license_behind'];
        $reportData['signature_agent'] = $persons[0]['signature_agent'];
        $reportData['signature_invitee'] = $persons[0]['signature_invitee'];
        $reportData['invitee_mobile'] = $persons[0]['invitee_mobile'];
        $reportData['merge_address'] = $reportData['company_addr'] ? $reportData['company_addr'] : $reportData['addr']; // 单位地址或家庭住址
        $reportData['person_name'] = $reportData['company_name'] ? $reportData['company_name'] : $reportData['full_name']; // 单位名称或公民姓名

        // 图片数据
        $reportData['idcard_front'] = $this->getSplitLocalImage($reportData['idcard_front'], ['width' => 600, 'height' => 'auto', 'ratio' => false]);
        $reportData['idcard_behind'] = $this->getSplitLocalImage($reportData['idcard_behind'], ['width' => 600, 'height' => 'auto', 'ratio' => false]);
        $reportData['driver_license_front'] = $this->getSplitLocalImage($reportData['driver_license_front'], ['width' => 300, 'height' => 'auto', 'ratio' => false]);
        $reportData['driver_license_behind'] = $this->getSplitLocalImage($reportData['driver_license_behind'], ['width' => 300, 'height' => 'auto', 'ratio' => false]);
        $reportData['driving_license_front'] = $this->getSplitLocalImage($reportData['driving_license_front'], ['width' => 300, 'height' => 'auto', 'ratio' => false]);
        $reportData['driving_license_behind'] = $this->getSplitLocalImage($reportData['driving_license_behind'], ['width' => 300, 'height' => 'auto', 'ratio' => false]);
        $reportData['signature_checker'] = $this->getSplitLocalImage($reportData['signature_checker']);
        $reportData['signature_writer'] = $this->getSplitLocalImage($reportData['signature_writer']);
        $reportData['signature_agent'] = $this->getSplitLocalImage($reportData['signature_agent']);
        $reportData['signature_invitee'] = $this->getSplitLocalImage($reportData['signature_invitee']);
        $reportData += $this->getSplitPhoto($reportData['site_photos']);
        unset($reportData['site_photos']);

        // 勾选勘验证据
        $reportData += $this->checkBoxDataItem($reportData);
        
        // 赔付清单列表项
        $rows = [];
        if ($items) {
            $items = array_pad($items, 19, []); // 补齐行数
            foreach ($items as $k => $v) {
                $rows['items'][$k]['item.index'] = $k + 1;
                $rows['items'][$k]['item.name'] = isset($v['name']) ? $v['name'] : '';
                $rows['items'][$k]['item.unit'] = isset($v['unit']) ? $v['unit'] : '';
                $rows['items'][$k]['item.amount'] = isset($v['amount']) ? $v['amount'] : '';
                $rows['items'][$k]['item.price'] = isset($v['price']) ? $v['price'] : '';
                $rows['items'][$k]['item.total_money'] = isset($v['total_money']) ? $v['total_money'] : '';
            }
        } else {
            $reportData['item.index'] = '';
            $reportData['item.name'] = '';
            $reportData['item.unit'] = '';
            $reportData['item.amount'] = '';
            $reportData['item.price'] = '';
            $reportData['item.total_money'] = '';
        }

        unset($persons, $items);

        // 分离出图片
        $images = [];
        foreach ($reportData as $k => $v) {
            if (is_array($v)) {
                $images[$k] = $v;
                unset($reportData[$k]);
            }
        }

        // 无内容要打斜杠
        foreach ($reportData as $k => $v) {
            if ($v === null || $v === '') {
                $reportData[$k] = '/';
            }
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
            if (!isset($result['site_photos_exists']) && $result['site_photos.' . $k]) {
                $result['site_photos_exists'] = 1;
            }
        }
        return $result;
    }

    private function getBRline (array $data)
    {
        $result = [];
        foreach ($data as $k => $v) {
            $result[] = ($k + 1) . '. ' . $v['name'] . $v['amount'] . $v['unit'];
        }
        return implode('；', $result);
        // $result = mb_str_split($result, 1);
        // $result = array_pad($result, 100, ''); // 填充占位符
        // return implode('', $result);
    }

    private function checkBoxDataItem ($data)
    {
        // 勾选证据

        // 当事人身份证
        $result['dataitem.idcard'] = $this->checked($data['idcard_front'] || $data['idcard_behind']);
        // 驾驶证
        $result['dataitem.driver'] = $this->checked($data['driver_license_front'] || $data['driver_license_behind']);
        // 行驶证
        $result['dataitem.driving'] = $this->checked($data['driving_license_front'] || $data['driving_license_behind']);
        // 现场照片
        $result['dataitem.site'] = $this->checked($data['site_photos_exists']);

        return $result;
    }

    private function getSplitCheckBoxIf ($data)
    {
        // 勾选事故发生行为
        return [
            'involved_action.a.a' => $this->checked($data['involved_action.a'] == $this->checked(true) && $data['involved_action_type.a'] == $this->checked(true)),
            'involved_action.a.b' => $this->checked($data['involved_action.a'] == $this->checked(true) && $data['involved_action_type.b'] == $this->checked(true)),
            'involved_action.a.c' => $this->checked($data['involved_action.a'] == $this->checked(true) && $data['involved_action_type.c'] == $this->checked(true)),

            'involved_action.b.a' => $this->checked($data['involved_action.b'] == $this->checked(true) && $data['involved_action_type.a'] == $this->checked(true)),
            'involved_action.b.b' => $this->checked($data['involved_action.b'] == $this->checked(true) && $data['involved_action_type.b'] == $this->checked(true)),
            'involved_action.b.c' => $this->checked($data['involved_action.b'] == $this->checked(true) && $data['involved_action_type.c'] == $this->checked(true)),

            'involved_action.c.a' => $this->checked($data['involved_action.c'] == $this->checked(true) && $data['involved_action_type.a'] == $this->checked(true)),
            'involved_action.c.b' => $this->checked($data['involved_action.c'] == $this->checked(true) && $data['involved_action_type.b'] == $this->checked(true)),
            'involved_action.c.c' => $this->checked($data['involved_action.c'] == $this->checked(true) && $data['involved_action_type.c'] == $this->checked(true)),

            'involved_action.d.a' => $this->checked($data['involved_action.d'] == $this->checked(true) && $data['involved_action_type.a'] == $this->checked(true)),
            'involved_action.d.b' => $this->checked($data['involved_action.d'] == $this->checked(true) && $data['involved_action_type.b'] == $this->checked(true)),
            'involved_action.d.c' => $this->checked($data['involved_action.d'] == $this->checked(true) && $data['involved_action_type.c'] == $this->checked(true)),

            'involved_action.e.a' => $this->checked($data['involved_action.e'] == $this->checked(true) && $data['involved_action_type.a'] == $this->checked(true)),
            'involved_action.e.b' => $this->checked($data['involved_action.e'] == $this->checked(true) && $data['involved_action_type.b'] == $this->checked(true)),
            'involved_action.e.c' => $this->checked($data['involved_action.e'] == $this->checked(true) && $data['involved_action_type.c'] == $this->checked(true))
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
            $result[$lit . '.' . $v] = $this->checked($data[$v]);
        }
        return $result;
    }

    private function checked ($value)
    {
        // ☑ ☐ □
        return $value ? '☑' : '□';
    }

    private function getSplitDate ($lit, $date)
    {
        $date = strtotime($date);
        return [
            $lit . '.year' => $date ? date('Y', $date) : '',
            $lit . '.month' => $date ? date('n', $date) : '',
            $lit . '.day' => $date ? date('j', $date) : '',
            $lit . '.hour' => $date ? date('G', $date) : '',
            $lit . '.minute' => $date ? intval(date('i', $date)) : ''
        ];
    }

}