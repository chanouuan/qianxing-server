<?php

namespace app\models;

use Crud;
use app\common\Gender;

class PropertyModel extends Crud {

    protected $table = 'qianxing_property';

    /**
     * 搜索路产赔损项目
     * @return array
     */
    public function search (array $post)
    {
        $post['name'] = trim_space($post['name'], 0, 30);

        if (!$post['name']) {
            return success([]);
        }

        $condition = [
            'name' => ['like', '%' . $post['name'] . '%']
        ];

        if (!$list = $this->select($condition, 'id as property_id,name,unit,price', null, 6)) {
            return success([]);
        }

        foreach ($list as $k => $v) {
            $list[$k]['price'] = round_dollar($v['price']);
        }

        return success($list);
    }

}