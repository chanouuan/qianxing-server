<?php
/**
 * 缓存读取
 */

namespace app\common;

use app\library\DB;

class GenerateCache
{

    /**
     * 权限映射
     * @param $permissions 权限id
     * @return array
     */
    public static function mapPermissions (array $permissions)
    {
        if (empty($permissions)) {
            return [];
        }
        $list = self::getPermissions();
        foreach ($permissions as $k => $v) {
            $permissions[$k] = isset($list[$v]) ? $list[$v] : null;
        }
        return array_values(array_filter($permissions));
    }

    /**
     * 获取所有权限
     * @return array
     */
    public static function getPermissions ()
    {
        if (false === F('permissions')) {
            $list = DB::getInstance()
                ->table('admin_permissions')
                ->field('id,name')
                ->select();
            $list = array_column($list, 'name', 'id');
            F('permissions', $list);
            return $list;
        }
        return F('permissions');
    }

}
