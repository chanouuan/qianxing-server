<?php

namespace app\models;

use Crud;
use app\common\Gender;
use app\common\CommonStatus;

class AdminModel extends Crud {

    protected $table = 'admin_user';

    /**
     * 管理员登录
     * @param username 用户名
     * @param password 密码登录
     * @return array
     */
    public function login (array $post)
    {
        $post['username'] = trim_space($post['username']);
        if (!$post['username']) {
            return error('账号不能为空');
        }
        if (!$post['password']) {
            return error('密码不能为空');
        }

        // 检查错误登录次数
        if (!$this->checkLoginFail($post['username'])) {
            return error('密码错误次数过多，请稍后重新登录！');
        }

        // 登录不同方式
        $userInfo = $this->userLogin($post);
        if ($userInfo['errorcode'] !== 0) {
            return $userInfo;
        }
        $userInfo = $userInfo['data'];

        // 获取管理权限
        $permission = $this->getUserPermissions($userInfo['user_id']);

        // login 权限验证
        if ($post['role'] && empty(array_intersect($post['role'], $permission['role']))) {
            return error('职位权限不足');
        }
        if (empty(array_intersect($post['permission'] ? $post['permission'] : ['ANY', 'login'], $permission['permission']))) {
            return error('操作权限不足');
        }

        $opt = [];
        if (isset($post['clienttype'])) {
            $opt['clienttype'] = $post['clienttype'];
        }
        if (isset($post['clientapp'])) {
            $opt['clientapp'] = $post['clientapp'];
        }

        // 登录状态
        $result = (new UserModel())->setloginstatus($userInfo['user_id'], uniqid(), $opt, [
            implode('^', $permission['id'])
        ]);
        if ($result['errorcode'] !== 0) {
            return $result;
        }

        $userInfo['token'] = $result['data']['token'];
        $userInfo['permission'] = $permission['permission'];

        return success($userInfo);
    }

    /**
     * 管理员用户登录
     * @param $post
     * @return array
     */
    public function userLogin (array $post)
    {
        $condition = [
            'status' => 1,
        ];
        if (preg_match('/^\d+$/', $post['username'])) {
            if (!validate_telephone($post['username'])) {
                return error('手机号不正确');
            }
            $condition['telephone'] = $post['username'];
        } else {
            $condition['user_name'] = $post['username'];
        }

        // 获取用户
        if (!$userInfo = $this->find($condition, 'id,avatar,user_name,full_name,telephone,password')) {
            return error('用户名或密码错误');
        }

        // 密码验证
        if (!$this->passwordVerify($post['password'], $userInfo['password'])) {
            $count = $this->loginFail($post['username']);
            return error($count > 0 ? ('用户名或密码错误，您还可以登录 ' . $count . ' 次！') : '密码错误次数过多，15分钟后重新登录！');
        }

        return success([
            'user_id'   => $userInfo['id'],
            'avatar'    => httpurl($userInfo['avatar']),
            'nick_name'  => get_real_val($userInfo['full_name'], $userInfo['user_name'], $userInfo['telephone']),
            'telephone' => $userInfo['telephone']
        ]);
    }

    /**
     * 获取用户姓名
     * @return array
     */
    public function getAdminNames (array $id)
    {
        $id = array_filter(array_unique($id));
        if (!$id) {
            return [];
        }
        if (!$userList = $this->select(['id' => ['in', $id]], 'id,user_name,full_name,telephone')) {
            return [];
        }
        foreach ($userList as $k => $v) {
            $userList[$k]['nick_name'] = get_real_val($v['full_name'], $v['user_name'], $v['telephone']);
        }
        return array_column($userList, 'nick_name', 'id');
    }

    /**
     * 获取用户所有权限
     * @return array
     */
    public function getUserPermissions ($user_id)
    {
        // 获取用户角色
        $roles = $this->getDb()->table('admin_role_user')->field('role_id')->where(['user_id' => $user_id])->select();
        if (empty($roles)) {
            return [];
        }
        $roles = array_column($roles, 'role_id');

        // 获取权限
        if (!$permissions = $this->getDb()
            ->table('admin_permission_role permission_role inner join admin_permissions permissions on permissions.id = permission_role.permission_id')
            ->field('permissions.id,permissions.name')
            ->where(['permission_role.role_id' => ['in', $roles]])
            ->select()) {
            return [];
        }

        return [
            'role' => $roles,
            'id' => array_column($permissions, 'id'),
            'permission' => array_column($permissions, 'name')
        ];
    }

    /**
     * 获取执法证号-根据 user_id
     * @return array
     */
    public function getLawNumByUser (array $user_id)
    {
        if (!$user_id = array_unique(array_filter($user_id))) {
            return [];
        }
        // 获取用户手机号
        if (!$userInfo = (new UserModel())->select(['id' => ['in', $user_id]], 'id,telephone')) {
            return [];
        }
        $userInfo = array_column($userInfo, 'telephone', 'id');
        if (!$adminInfo = $this->select(['telephone' => ['in', $userInfo]], 'telephone,law_num')) {
            return [];
        }
        // 获取管理员执法证号，通过手机号关联
        $adminInfo = array_column($adminInfo, 'law_num', 'telephone');
        foreach ($userInfo as $k => $v) {
            $userInfo[$k] = isset($adminInfo[$v]) ? $adminInfo[$v] : '';
        }
        unset($adminInfo);
        return $userInfo;
    }

    /**
     * 获取用户信息
     * @return array
     */
    public function getUserInfo ($user_id, $field = null)
    {
        if (!$user_id) {
            return [];
        }
        $field = $field ? $field : 'id,avatar,user_name,full_name,telephone,title,group_id,status';
        if (!$userInfo = $this->find(is_array($user_id) ? $user_id : ['id' => $user_id], $field)) {
            return [];
        }
        if (isset($userInfo['avatar'])) {
            $userInfo['avatar'] = httpurl($userInfo['avatar']);
        }
        $userInfo['nick_name'] = get_real_val($userInfo['full_name'], $userInfo['user_name'], $userInfo['telephone']);
        return $userInfo;
    }

    /**
     * 获取用户登录信息
     * @return array
     */
    public function getLoginProfile (int $user_id)
    {
        $userInfo = $this->checkAdminInfo($user_id);

        // 获取单位信息
        $userInfo['group_info'] = (new GroupModel())->find(['id' => $userInfo['group_id']], 'id,name');

        return success($userInfo);
    }

    /**
     * 检查用户信息
     * @param $user_id
     * @return array
     */
    public function checkAdminInfo (int $user_id)
    {
        if (!$userInfo = $this->getUserInfo($user_id)) {
            json(null, '用户不存在', -1);
        }
        if ($userInfo['status'] != 1) {
            json(null, '你已被禁用', -1);
        }
        if (!$userInfo['group_id']) {
            json(null, '你未绑定单位', -1);
        }
        return $userInfo;
    }

    /**
     * 获取角色列表
     * @return array
     */
    public function getRoleList ($user_id, array $post)
    {
        $post['page_size'] = max(6, $post['page_size']);
        $post['name'] = trim_space($post['name']);

        // 用户获取
        $userInfo = $this->checkAdminInfo($user_id);

        $condition = [
            'group_id' => $userInfo['group_id']
        ];
        if ($post['name']) {
           $condition['name'] = $post['name'];
        }
        if (!is_null(CommonStatus::format($post['status']))) {
            $condition['status'] = $post['status'];
        }

        $count = $this->getDb()->table('admin_roles')->where($condition)->count();
        if ($count > 0) {
            $pagesize = getPageParams($post['page'], $count, $post['page_size']);
            $list = $this->getDb()->field('id,name,description,status')->table('admin_roles')->where($condition)->order('id desc')->limit($pagesize['limitstr'])->select();
        }

        return success([
            'total_count' => $count,
            'page_size' => $post['page_size'],
            'list' => $list ? $list : []
        ]);
    }

    /**
     * 查看角色
     * @return array
     */
    public function viewRole ($id)
    {
        $id = intval($id);

        if ($id === 1) {
            return error('不能查看该角色');
        }

        if (!$roleInfo = $this->getDb()->field('id,name,description,status')->table('admin_roles')->where(['id' => $id])->find()) {
            return error('该角色不存在');
        }

        // 获取角色权限
        $rolePermission = $this->getDb()->field('permission_id')->table('admin_permission_role')->where(['role_id' => $id])->select();
        $rolePermission = array_column($rolePermission, 'permission_id');
        $roleInfo['permission'] = $rolePermission;

        return success($roleInfo);
    }

    /**
     * 查看权限
     * @return array
     */
    public function viewPermissions ()
    {
        $permissions = $this->getDb()->table('admin_permissions')->field('id,description')->where(['id' => ['>1']])->select();
        return success($permissions);
    }

    /**
     * 添加角色
     * @return array
     */
    public function saveRole ($user_id, array $post)
    {
        $userInfo = $this->checkAdminInfo($user_id);

        $post['id'] = intval($post['id']);
        $post['status'] = $post['status'] ? 1 : 0;
        $post['permission'] = get_short_array($post['permission']);

        // 去掉 ANY 权限
        foreach ($post['permission'] as $k => $v) {
            if ($v === 1) {
                unset($post['permission'][$k]);
            }
        }
        $post['permission'] = array_values($post['permission']);

        $data = [];
        $data['group_id'] = $userInfo['group_id'];
        $data['name'] = trim_space($post['name'], 0, 20);
        $data['description'] = trim_space($post['description'], 0, 50);

        if (!$data['group_id']) {
            return error('单位不能为空');
        }
        if (!$data['name']) {
            return error('角色名称不能为空');
        }
        if (!$post['permission']) {
            return error('角色权限不能为空');
        }

        // 新增 or 编辑
        if ($post['id']) {
            $data['status'] = $post['status'];
            $data['update_time'] = date('Y-m-d H:i:s', TIMESTAMP);
            if (!$this->getDb()->table('admin_roles')->where(['id' => $post['id']])->update($data)) {
                return error('角色保存失败');
            }
        } else {
            $data['create_time'] = date('Y-m-d H:i:s', TIMESTAMP);
            if (!$post['id'] = $this->getDb()->table('admin_roles')->insert($data, true)) {
                return error('角色添加失败');
            }
        }

        // 添加角色权限
        $rolePermission = $this->getDb()->field('permission_id')->table('admin_permission_role')->where(['role_id' => $post['id']])->select();
        $rolePermission = $rolePermission ? array_column($rolePermission, 'permission_id') : [];
        $curd  = array_curd($rolePermission, $post['permission']);
        if ($curd['add']) {
            $this->getDb()->table('admin_permission_role')->insert([
                'role_id' => array_fill(0, count($curd['add']), $post['id']),
                'permission_id' => $curd['add']
            ]);
        }
        if ($curd['delete']) {
            $this->getDb()->table('admin_permission_role')->where([
                'role_id' => $post['id'],
                'permission_id' => ['in', $curd['delete']]
            ])->delete();
        }

        return success('ok');
    }

    /**
     * 获取人员角色
     * @return array
     */
    public function getPeopleRole ($user_id)
    {
        if (!$info = $this->getUserInfo($user_id)) {
            return [];
        }
        if (!$info['group_id']) {
            return [];
        }
        return $this->getDb()->table('admin_roles')->field('id,name')->where(['status' => 1, 'group_id' => $info['group_id']])->select();
    }

    /**
     * 获取人员信息
     * @return array
     */
    public function getPeopleInfo ($id)
    {
        $id = intval($id);
        if (!$info = $this->getDb()->field('id,avatar,user_name,full_name,telephone,gender,title,status')->where(['id' => $id])->limit(1)->find()) {
            return [];
        }
        // 获取角色
        $roles = $this->getDb()->table('admin_role_user')->field('role_id')->where(['user_id' => $id])->select();
        $roles = $roles ? array_column($roles, 'role_id') :[];
        $info['role_id'] = $roles;
        $info['avatar']  = httpurl($info['avatar']);
        return $info;
    }

    /**
     * 获取人员列表
     * @return array
     */
    public function getPeopleList ($user_id, array $post)
    {
        $post['page_size'] = max(6, $post['page_size']);
        $post['name'] = trim_space($post['name']);

        // 用户获取
        $userInfo = $this->checkAdminInfo($user_id);

        $condition = [
            'group_id' => $userInfo['group_id']
        ];
        if (!is_null(CommonStatus::format($post['status']))) {
            $condition['status'] = $post['status'];
        }
        if ($post['title']) {
            $condition['title'] = $post['title'];
        }
        if ($post['name']) {
           if (preg_match('/^\d+$/', $post['name'])) {
                if (!validate_telephone($post['name'])) {
                    $condition['user_name'] = $post['name'];
                } else {
                    $condition['telephone'] = $post['name'];
                }
            } else {
                $condition['user_name'] = $post['name'];
            }
        }

        $count = $this->count($condition);
        if ($count > 0) {
            $pagesize = getPageParams($post['page'], $count, $post['page_size']);
            $list = $this->select($condition, 'id,user_name,telephone,full_name,gender,title,status', 'id desc', $pagesize['limitstr']);
            if ($list) {
                $roles = $this->getRoleByUser(array_column($list, 'id'));
                foreach ($list as $k => $v) {
                    // 角色
                    $list[$k]['roles'] = isset($roles[$v['id']]) ? implode(',', $roles[$v['id']]) : '无';
                }
                unset($roles);
            }
        }

        return success([
            'total_count' => $count,
            'page_size' => $post['page_size'],
            'list' => $list ? $list : []
        ]);
    }

    /**
     * 根据用户获取角色
     * @param $user_id 用户ID
     * @return array
     */
    public function getRoleByUser (array $user_id)
    {
        if (empty($user_id)) {
            return [];
        }

        if (empty($roles = $this->getDb()
            ->table('admin_role_user role_user inner join admin_roles role on role.id = role_user.role_id')
            ->field('role_user.user_id,role_user.role_id,role.name')
            ->where(['role_user.user_id' => ['in', $user_id]])
            ->select())) {
            return [];
        }

        $list = [];
        foreach ($roles as $k => $v) {
            $list[$v['user_id']][$v['role_id']] = $v['name'];
        }

        unset($roles);
        return $list;
    }

    /**
     * 添加人员
     * @return array
     */
    public function savePeople (int $user_id, array $post)
    {
        $userInfo = $this->checkAdminInfo($user_id);

        $post['id'] = intval($post['id']);
        $post['status'] = CommonStatus::format($post['status']);
        $post['role_id'] = get_short_array($post['role_id']);

        $data = [];
        $data['group_id'] = $userInfo['group_id'];
        $data['user_name'] = trim_space($post['user_name'], 0, 20);
        $data['password'] = trim_space($post['password'], 0, 32);
        $data['gender'] = Gender::format($post['gender']);
        $data['full_name'] = trim_space($post['full_name'], 0, 20);
        $data['title'] = trim_space($post['title'], 0, 20);

        if (!$data['group_id']) {
            return error('单位不能为空');
        }
        if (!$data['user_name']) {
            return error('登录账号不能为空');
        } else {
            if (preg_match('/^\d+$/', $post['user_name'])) {
                return error('登录账号不能全数字');
            }
        }
        if (!$post['id'] && !$data['password']) {
            return error('登录密码不能为空');
        }
        if (!validate_telephone($data['telephone'])) {
            return error('手机号格式不正确');
        }
        if (!$post['role_id']) {
            return error('角色不能为空');
        }

        // 密码 hash
        if ($data['password']) {
            if (strlen($data['password']) < 6) {
                return error('密码长度至少 6 位');
            }
            $data['password'] = $this->hashPassword(md5($data['password']));
        } else {
            unset($data['password']);
        }

        // 重复效验
        $condition = [
            'user_name' => $data['user_name']
        ];
        if ($post['id']) {
             $condition['id'] = ['<>', $post['id']];
        }
        if ($this->count($condition)) {
            return error('该登录账号已存在');
        }
        $condition = [
            'telephone' => $data['telephone']
        ];
        if ($post['id']) {
             $condition['id'] = ['<>', $post['id']];
        }
        if ($this->count($condition)) {
            return error('该手机号已存在');
        }

        // 角色效验
        $roles = $this->getDb()->table('admin_roles')->where(['status' => 1, 'id' => ['in', $post['role_id']]])->count();
        if (count($post['role_id']) !== $roles) {
            return error('角色效验失败');
        }

        // 新增 or 编辑
        if ($post['id']) {
            $data['status'] = $post['status'];
            $data['update_time'] = date('Y-m-d H:i:s', TIMESTAMP);
            if (!$this->getDb()->where(['id' => $post['id']])->update($data)) {
                return error('该用户已存在！');
            }
        } else {
            $data['status'] = 1;
            $data['create_time'] = date('Y-m-d H:i:s', TIMESTAMP);
            if (!$post['id'] = $this->getDb()->insert($data, true)) {
                return error('请勿添加重复的用户！');
            }
        }

        // 更新用户信息
        (new UserModel())->updateUserInfo(['telephone' => $data['telephone']], [
            'full_name' => $data['full_name'],
            'gender' => $data['gender'],
            'group_id' => $data['status'] == 1 ? $data['group_id'] : 0
        ]);

        // 添加权限
        $roles = $this->getDb()->table('admin_role_user')->field('role_id')->where(['user_id' => $post['id']])->select();
        $roles = $roles ? array_column($roles, 'role_id') :[];
        $curd  = array_curd($roles, $post['role_id']);
        if ($curd['add']) {
            $this->getDb()->table('admin_role_user')->insert([
                'user_id' => array_fill(0, count($curd['add']), $post['id']),
                'role_id' => $curd['add']
            ]);
        }
        if ($curd['delete']) {
            $this->getDb()->table('admin_role_user')->where([
                'user_id' => $post['id'],
                'role_id' => ['in', $curd['delete']]
            ])->delete();
        }
        
        return success('ok');
    }

    /**
     * 记录登录错误次数
     * @param $account
     * @return int
     */
    public function loginFail ($account)
    {
        $faileInfo = $this->getDb()
            ->table('pro_failedlogin')
            ->field('id,login_count,update_time')
            ->where(['account' => $account])
            ->limit(1)
            ->find();
        $count = 1;
        if ($faileInfo) {
            $count = ($faileInfo['update_time'] + 900 > TIMESTAMP) ? $faileInfo['login_count'] + 1 : 1;
            $this->getDb()
                ->table('pro_failedlogin')
                ->where(['id' => $faileInfo['id'], 'update_time' => $faileInfo['update_time']])
                ->update([
                    'login_count' => $count,
                    'update_time' => TIMESTAMP
                ]);
        } else {
            $this->getDb()
                ->table('pro_failedlogin')
                ->insert([
                    'login_count' => 1,
                    'update_time' => TIMESTAMP,
                    'account'     => $account
                ]);
        }
        $count = 10 - $count;
        return $count < 0 ? 0 : $count;
    }

    /**
     * 检查错误登录次数
     * @param $account
     * @return bool
     */
    public function checkLoginFail ($account)
    {
        return ($account && $this->getDb()
            ->table('pro_failedlogin')
            ->where(['account' => $account, 'login_count' => ['>', 9], 'update_time' => ['>', TIMESTAMP - 900]])
            ->count() ? false : true);
    }

    /**
     * hash密码
     * @param $pwd
     * @return string
     */
    public function hashPassword ($pwd)
    {
        return password_hash($pwd, PASSWORD_BCRYPT);
    }

    /**
     * 密码hash验证
     * @param $pwd 密码明文
     * @param $hash hash密码
     * @return bool
     */
    public function passwordVerify ($pwd, $hash)
    {
        return password_verify($pwd, $hash);
    }

}