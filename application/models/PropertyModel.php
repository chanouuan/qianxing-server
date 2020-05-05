<?php

namespace app\models;

use Crud;
use app\common\Gender;
use app\common\PropertyCategory;

class PropertyModel extends Crud {

    protected $table = 'qianxing_property';

    /**
     * 获取所有路产赔损项目
     * @return array
     */
    public function getAllItems ()
    {
        if (!$list = $this->select([], 'category,name,unit,price', 'category')) {
            return success([]);
        }
        $res = [];
        foreach ($list as $k => $v) {
            $res[PropertyCategory::getMessage($v['category'])][] = [
                'name' => $v['name'],
                'unit' => $v['unit'],
                'price' => round_dollar($v['price'])
            ];
        }
        unset($list);
        return success($res);    
    }

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