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
                'img' => httpurl('/static/img/pic14.jpg'),
                'title' => ''
            ]
        ];
        return $result;
    }

    /**
     * 获取公告
     * @return array
     */
    public function getMsgNotice ()
    {
        $result = [
            'id' => 1,
            'title' => '今天开始，遵义这条高速又要施工了，以下路段和收费站不能通行！',
            'url' => 'https://mp.weixin.qq.com/s/uplE-rF-D89qe0f-RmfWHQ'
        ];
        return $result;
    }

    /**
     * 获取资讯
     * @return array
     */
    public function getMsgInfo ()
    {
        $result = [
            'id' => 1,
            'title' => '关注丨高速公路收费政策解读来了',
            'url' => 'https://mp.weixin.qq.com/s/ILSe7sYrPpESb0_7iE1ghw'
        ];
        return $result;
    }

}