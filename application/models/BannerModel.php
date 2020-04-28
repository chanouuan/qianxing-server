<?php

namespace app\models;

use Crud;

class BannerModel extends Crud {

    protected $table = 'qianxing_banner';

    /**
     * 获取 banner
     * @return array
     */
    public function getBanner ()
    {
        $result = [
            [
                'img' => httpurl('/static/img/pic12.jpg'),
                'title' => ''
            ]
        ];
        return $result;
    }

}