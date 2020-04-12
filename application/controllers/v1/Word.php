<?php

namespace app\controllers;

use ActionPDO;
use app\models\WordModel;

class Word extends ActionPDO {

    public function _ratelimit ()
    {
        return [
            'paynote'  => ['interval' => 2000],
            'itemnote' => ['interval' => 2000]
        ];
    }

    public function _init ()
    {

    }

    /**
     * 高速公路路政赔（补）偿通知书
     * @login
     * @return mixed
     */
    public function paynote ()
    {
        $wordModel = new WordModel();
        $condition = [
            'id' => intval($_POST['report_id']),
            'user_id' => $this->_G['user']['user_id'],
            'status' => ['>', \app\common\ReportStatus::ACCEPT]
        ];
        if (!$data = $wordModel->getReportData($condition)) {
            return error('数据为空');
        }

        $templateSource = APPLICATION_PATH . '/public/static/word_template/' . $this->_action . '.docx';
        $templateSaveAs = $wordModel->getSavePath($_POST['report_id'], $this->_action);
        
        if (file_exists(APPLICATION_PATH . '/public/' . $templateSaveAs)) {
            return success(['url' => httpurl($templateSaveAs)]);
        }

        $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templateSource);

        $templateProcessor->setValues($data['values']);
        if ($data['images']) {
            $data['images']['signature_agent']['height'] = 40;
            $templateProcessor->setImageValue(array_keys($data['images']), $data['images']);
        }
        if ($data['rows']['items']) {
            $templateProcessor->cloneRowAndSetValues('item.index', $data['rows']['items']);
        }

        mkdirm(dirname(APPLICATION_PATH . '/public/' . $templateSaveAs));
        $templateProcessor->saveAs(APPLICATION_PATH . '/public/' . $templateSaveAs);

        return success(['url' => httpurl($templateSaveAs)]);
    }

    /**
     * 高速公路路产勘验（检查）笔录
     * @return mixed
     */
    public function itemnote ()
    {
        $wordModel = new WordModel();
        $condition = [
            'id' => intval($_POST['report_id']),
            'user_id' => $this->_G['user']['user_id'],
            'status' => ['>', \app\common\ReportStatus::ACCEPT]
        ];
        if (!$data = $wordModel->getReportData($condition)) {
            return error('数据为空');
        }

        $templateSource = APPLICATION_PATH . '/public/static/word_template/' . $this->_action . '.docx';
        $templateSaveAs = $wordModel->getSavePath($_POST['report_id'], $this->_action);
        
        if (file_exists(APPLICATION_PATH . '/public/' . $templateSaveAs)) {
            return success(['url' => httpurl($templateSaveAs)]);
        }

        $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templateSource);

        $templateProcessor->setValues($data['values']);
        if ($data['images']) {
            $templateProcessor->setImageValue(array_keys($data['images']), $data['images']);
        }

        mkdirm(dirname(APPLICATION_PATH . '/public/' . $templateSaveAs));
        $templateProcessor->saveAs(APPLICATION_PATH . '/public/' . $templateSaveAs);

        return success(['url' => httpurl($templateSaveAs)]);
    }

    private function viewKeys ($data)
    {
        // print_r($templateProcessor->getVariables());
        $result = [];
        foreach ($data as $k => $v) {
            $result[] = '${' . $k . '} = ' . (is_array($v) ? json_encode($v) : $v);
        }
        $result = implode("\r\n", $result);
        exit($result);
    }

}
