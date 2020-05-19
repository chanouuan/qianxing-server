<?php

namespace app\models;

use Crud;

class AttachmentModel extends Crud {

    protected $table = 'qianxing_report_attachment';

    /**
     * 上传案件附件
     * @return array
     */
    public function reportAttachment (int $user_id, array $post)
    {
        $post['report_id'] = intval($post['report_id']);

        if ($_FILES['upfile']['error'] !== 0) {
            return error('上传失败');
        }

        $uploadfile = uploadfile($_FILES['upfile'], 'jpg,jpeg,png', 0, 0);
        if ($uploadfile['errorcode'] !== 0) {
            return $uploadfile;
        }
        $uploadfile = $uploadfile['data'];
        $imgUrl = $uploadfile['thumburl'] ? $uploadfile['thumburl'] : $uploadfile['url'];
        $imgName = $this->encodeUTF8(substr($_FILES['upfile']['name'], 0, strrpos($_FILES['upfile']['name'], '.')));

        if (!$id = $this->getDb()->insert([
            'report_id' => $post['report_id'],
            'user_id' => $user_id,
            'name' => $imgName,
            'src' => $imgUrl,
            'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ], true)) {
            return error('数据添加失败');
        }

        return success([
            'id' => $id,
            'url' => httpurl($imgUrl),
            'name' => $imgName
        ]);
    }

    /**
     * 删除案件附件
     * @return array
     */
    public function rmReportAttachment (array $post)
    {
        $post['id'] = intval($post['id']);
        $post['report_id'] = intval($post['report_id']);

        if (!$id = $this->getDb()->where([
            'id' => $post['id'],
            'report_id' => $post['report_id']
            ])->delete()) {
            return error('附件删除失败');
        }

        return success('ok');
    }

    /**
     * 字符转码
     * @return string
     */
    private function encodeUTF8($text)
    {
        if (!$encode = mb_detect_encoding($text, array('UTF-8','GB2312','GBK','ASCII','BIG5'))) {
            return '';
        }
        if($encode != 'UTF-8') {
            return mb_convert_encoding($text, 'UTF-8', $encode);
        } else {
            return $text;
        }
    }

}