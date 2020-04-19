<?php

namespace app\models;

use Crud;

class BannerModel extends Crud {

    protected $table = 'qianxing_banner';

    /**
     * 获取 banner
     * @return array
     * }
     */
    public function getBanner ()
    {
        $result = [
            [
                'img' => httpurl('/static/img/pic1.jpg'),
                'title' => ''
            ],
            [
                'img' => httpurl('/static/img/pic2.jpg'),
                'title' => ''
            ]
        ];
        return $result;
    }

}