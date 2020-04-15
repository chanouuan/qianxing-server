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
                'img' => httpurl('/static/img/banner-2.jpg'),
                'title' => '加强疫情防控工作指导'
            ]
        ];
        return $result;
    }

}