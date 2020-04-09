<?php

namespace app\controllers;

use ActionPDO;
use PhpOffice\PhpWord\Element\Field;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\SimpleType\TblWidth;

class Index extends ActionPDO {

    public function _init ()
    {
        \DebugLog::_debug(false);
        header('Access-Control-Allow-Origin: *'); // 允许任意域名发起的跨域请求
        header('Access-Control-Allow-Headers: X-Requested-With,X_Requested_With');
        if (!isset($_SERVER['PHP_AUTH_USER']) ||
            !isset($_SERVER['PHP_AUTH_PW']) ||
            $_SERVER['PHP_AUTH_USER'] != 'admin' ||
            $_SERVER['PHP_AUTH_PW'] != '12345678') {
            header('HTTP/1.1 401 Unauthorized');
            http_response_code(401);
            header('WWW-Authenticate: Basic realm="Administrator Secret"');
            exit('Administrator Secret!');
        }
    }

    public function test ()
    {
        $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor('./header-footer.docx');

$title = new TextRun();
$title->addText('This title has been set ', array('bold' => true, 'italic' => true, 'color' => 'blue'));
$title->addText('☑', array('size' => 30, 'bold' => true, 'italic' => true, 'color' => 'red', 'underline' => 'single'));
$templateProcessor->setComplexBlock('title', $title);

$inline = new TextRun();
$inline->addCheckBox('chkBox1', 'Checkbox 1', array('italic' => true, 'color' => '#ff00ff', 'backgroundColor' => '#ffffff') , array('italic' => true, 'color' => '#ff00ff', 'backgroundColor' => '#ffffff'));
//$inline->addFormField('checkbox', array('italic' => true, 'color' => '#ff00ff', 'backgroundColor' => '#ffffff'))->setDefault(true);
($templateProcessor->setComplexValue('inline', $inline));

$imagePath = './_earth.jpg';
$variablesReplace = array(
    'bbb'       => $imagePath,
    'documentContent'   => array(
        'path' => $imagePath,
        'positioning'=>'absolute', 'pos' => 'absolute', 'position' => 'absolute', 'width' => 50, 'height' => 50),
    //'footerValue'       => array('path' => $imagePath, 'width' => 100, 'height' => 50, 'ratio' => false),
);
$templateProcessor->setImageValue(array_keys($variablesReplace), $variablesReplace);


$templateProcessor->saveAs('1.doc');

$phpWord = \PhpOffice\PhpWord\IOFactory::load('1.doc');

$section = $phpWord->addSection();
$section->addImage(
    $imagePath ,
    array(
        'width'            => \PhpOffice\PhpWord\Shared\Converter::cmToPixel(3),
        'height'           => \PhpOffice\PhpWord\Shared\Converter::cmToPixel(3),
        'positioning'      => \PhpOffice\PhpWord\Style\Image::POSITION_ABSOLUTE,
        'posHorizontal'    => \PhpOffice\PhpWord\Style\Image::POSITION_HORIZONTAL_RIGHT,
        'posHorizontalRel' => \PhpOffice\PhpWord\Style\Image::POSITION_RELATIVE_TO_PAGE,
        'posVerticalRel'   => \PhpOffice\PhpWord\Style\Image::POSITION_RELATIVE_TO_PAGE,
        'marginLeft'       => \PhpOffice\PhpWord\Shared\Converter::cmToPixel(15.5),
        'marginTop'        => \PhpOffice\PhpWord\Shared\Converter::cmToPixel(1.55),
    )
);

$objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$objWriter->save('helloWorld.docx');

    }

    public function test1 ()
    {
        $phpWord = new \PhpOffice\PhpWord\PhpWord();

        // Begin code
        $section = $phpWord->addSection();
        $header = $section->addHeader();
        $header->addWatermark('./_earth.jpg', array('marginTop' => 200, 'marginLeft' => 55));
        $section->addText('The header reference to the current section includes a watermark image.');
        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save('helloWorld.docx');
    }

    public function test2 () 
    {
        $zip = new \app\library\clsTbsZip();

        // Open the first document
        $zip->Open('2.docx');
        $content1 = $zip->FileRead('word/document.xml');
        $zip->Close();

        // Extract the content of the first document
        $p = strpos($content1, '<w:body');
        if ($p===false) exit("Tag <w:body> not found in document 1.");
        $p = strpos($content1, '>', $p);
        $content1 = substr($content1, $p+1);
        $p = strpos($content1, '</w:body>');
        if ($p===false) exit("Tag </w:body> not found in document 1.");
        // 添加换号符
        $content1 = '<w:p>
                        <w:r>
                            <w:br w:type="page"/>
                            <w:lastRenderedPageBreak/>
                        </w:r>
                    </w:p>' . substr($content1, 0, $p);
        //$content1 = substr($content1, 0, $p);

        // Insert into the second document
        $zip->Open('2.docx');
        $content2 = $zip->FileRead('word/document.xml');
        $p = strpos($content2, '</w:body>');
        if ($p===false) exit("Tag </w:body> not found in document 2.");
        $content2 = substr_replace($content2, $content1, $p, 0);
        $zip->FileReplace('word/document.xml', $content2, TBSZIP_STRING);

        // Save the merge into a third file
        $zip->Flush(TBSZIP_FILE, 'merge.docx');

        $zip->Close();
    }

    public function index () 
    {
        phpinfo();
    }

    public function logger ()
    {
        $path = trim_space(ltrim($_GET['path'], '/'));
        $path = ltrim(str_replace('.', '', $path), '/');
        $path = $path ? $path : (date('Ym') . '/' . date('Ymd') . '_debug');
        $path = APPLICATION_PATH . '/log/' . $path . '.log';
        if ($_GET['dir']) {
            $list = get_list_dir(APPLICATION_PATH . '/log');
            if (count($list) > 30) {
                $list = array_slice($list, count($list) - 30);
            }
            foreach ($list as $k => $v) {
                $list[$k] =  '<a href="' . (APPLICATION_URL . '/index/logger?path=' . str_replace(APPLICATION_PATH . '/log/', '', substr($v, 0, -4)) . '&dir=1') . '">' . str_replace(APPLICATION_PATH . '/log', '', $v) . '</a> ' . byte_convert(filesize($v)) . ' <a href="' . APPLICATION_URL . '/index/logger?path=' . str_replace([APPLICATION_PATH . '/log', '.log'], '', $v) . '&dir=1&clear=1">DEL</a>';
            }
        }
        if ($_GET['clear']) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        ?>
        <!doctype html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title></title>
            <meta name="viewport" content="width=device-width,user-scalable=yes, minimum-scale=1, initial-scale=1"/>
        </head>
        <body>
            <pre><?=implode("\n",$list)?></pre>
            <pre><?=file_exists($path)?file_get_contents($path):'404'?></pre>
        </body>
        </html>
        <?php
        exit(0);
    }

}
