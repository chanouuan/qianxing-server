<?php

namespace app\models;

use Crud;
use app\library\LocationUtils;

class GroupModel extends Crud {

    protected $table = 'admin_group';

    /**
     * 事发地与单位的距离（km）
     * @return array
     * }
     */
    public function getDistance (int $id, $location)
    {
        if (!$location) {
            return 0;
        }
        $groupData = $this->find(['id' => $id], 'location');
        if (!$groupData['location']) {
            return 0;
        }
        return round(LocationUtils::getDistance($location, $groupData['location']) / 1000);
    }

    /**
     * 移交部门人员
     * @return array
     * }
     */
    public function getGroupBook (int $user_id, array $post)
    {
        $post['level'] = intval($post['level']);
        $post['column'] = intval($post['column']);
        $post['value'] = intval($post['value']);
        $result = [];

        if ($post['level'] == 1) {
            // 只看本部门的
            $userModel = new UserModel();
            $userInfo = $userModel->checkUserInfo($user_id);
            $result[0] = $userModel->select(['group_id' => $userInfo['group_id'], 'status' => 1], 'id,full_name as name');
            return success($result);
        }
        
        if ($post['value'] == 0) {
            // 首次加载
            $result[0] = $this->select(['level' => 2], 'id,district as name', 'sort desc');
            $post['value'] = $result[0][0]['id'];
        }

        if ($post['column'] == 0) {
            // 获取单位
            $result[1] = $this->select(['parent_id' => $post['value'], 'level' => 3], 'id,concat(district,name) as name', 'sort desc');
            $post['value'] = $result[1][0]['id'];
        }

        if ($post['column'] == 1 || $post['level'] == 3) {
            // 获取人员
            $result[2] = (new UserModel())->select(['group_id' => $post['value'], 'status' => 1], 'id,full_name as name');
        }
        return success($result);
    }

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

        if (!$list = $this->select(['ad_info' => ['like', '%' . $post['district'] . '%']], 'id,name,route_points')) {
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
                        if ($points[$v['id']] <= 10) {
                            break;
                        }
                    }
                    if (isset($points[$v['id']]) && $points[$v['id']] <= 10) {
                        break;
                    }
                }
                unset($list[$k]['route_points']);
            } else {
                unset($list[$k]);
            }
        };
        $points = array_values($points);
        $list = array_values($list);
        // 去掉距离过大的
        foreach ($points as $k => $v) {
            if ($v > 1000) {
                unset($points[$k]);
                unset($list[$k]);
            }
        }
        // 排序距离
        if ($list) {
            array_multisort($points, SORT_ASC, SORT_NUMERIC, $list);
        }
        return success($list);
    }

}