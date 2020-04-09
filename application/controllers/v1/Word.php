<?php

namespace app\controllers;

use ActionPDO;
use app\models\WordModel;

class Word extends ActionPDO {

    public function _init ()
    {

    }

    /**
     * 高速公路路产勘验（检查）笔录
     * @return mixed
     */
    public function buildReportItem ()
    {
        $_POST['report_id'] = 3;
        $wordModel = new WordModel();
        if (!$data = $wordModel->getReportData(1, $_POST)) {
            return error('数据为空');
        }

        $templateSource = APPLICATION_PATH . '/public/static/word_template/kanyanbilu.docx';
        $templateSaveAs = $wordModel->getSavePath($_POST['report_id'], '_item');
        mkdirm(dirname(APPLICATION_PATH . '/public/' . $templateSaveAs));

        $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templateSource);

        $templateProcessor->setValues($data['values']);
        if ($data['images']) {
            $templateProcessor->setImageValue(array_keys($data['images']), $data['images']);
        }

        $templateProcessor->saveAs(APPLICATION_PATH . '/public/' . $templateSaveAs);

        return success(['url' => httpurl($templateSaveAs)]);
    }

    private function viewKeys ($data)
    {
        // print_r($templateProcessor->getVariables());
        $keys = array_keys($data);
        $keys = '${' . implode("}\r\n\${", $keys) . '}';
        exit($keys);
    }

}
