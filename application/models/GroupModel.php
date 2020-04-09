<?php

namespace app\models;

use Crud;
use app\library\LocationUtils;

class GroupModel extends Crud {

    protected $table = 'admin_group';

    /**
     * 获取区域执法单位
     * @return array
     */
    public function getDistrictGroup (array $post)
    {
        $post['adcode']   = intval($post['adcode']);
        $post['district'] = trim_space($post['district'], 0, 30);
        $post['location'] = explode(',', LocationUtils::checkLocation($post['location']));

        if (!$post['district'] || !$post['location']) {
            return success([]);
        }

        if (!$list = $this->select(['district' => $post['district']], 'id,name,route_points')) {
            return success([]);
        }

        $points = [];
        foreach ($list as $k => $v) {
            if ($v['route_points']) {
                $v['route_points'] = json_decode($v['route_points'], true);
                foreach ($v['route_points'] as $kk => $vv) {
                    foreach ($vv['points'] as $kkk => $vvv) {
                        // 获取距离最近
                        $distance = LocationUtils::getDistance($vvv, $post['location']);
                        if (isset($points[$v['id']])) {
                            if ($points[$v['id']] > $distance) {
                                $points[$v['id']] = $distance;
                            }
                        } else {
                            $points[$v['id']] = $distance;
                        }
                    }
                }
                unset($list[$k]['route_points']);
            } else {
                unset($list[$k]);
            }
        };
        // 排序距离
        array_multisort($points, SORT_ASC, SORT_NUMERIC,  $list);
        
        return success($list);
    }

}