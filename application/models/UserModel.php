<?php

namespace app\models;

use Crud;
use app\common\Gender;

class UserModel extends Crud {

    protected $table = 'pro_user';

    /**
     * 微信小程序登录
     * @return array
     */
    public function mpLogin (array $post)
    {
        $post['authcode'] = trim_space($post['authcode'], 0, 32);

        if (!$post['authcode']) {
            return error('授权码不能为空');
        }

        $bindingInfo = $this->getDb()
            ->table('pro_login_binding')
            ->field('user_id')
            ->where(['authcode' => $post['authcode']])
            ->limit(1)
            ->find();

        if ($bindingInfo) {
            // 已绑定授权码
            $userId = $bindingInfo['user_id'];
        } else {
            // 新注册用户，并绑定授权码
            if (!$userId = $this->getDb()->transaction(function ($db) use ($post) {
                if (!$id = $db->insert([
                    'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
                ], true)) {
                    return false;
                }
                if (!$this->getDb()->table('qianxing_user_count')->insert(['id' => $id])) {
                    return false;
                }
                if (!$this->getDb()->table('pro_login_binding')->insert([
                    'user_id' => $id,
                    'type' => 'mp',
                    'authcode' => $post['authcode'],
                    'openid' => $post['openid'],
                    'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
                ])) {
                    return false;
                }
                return $id;
            })) {
                return error('授权码注册失败');
            }
        }

        $userInfo = $this->getUserInfo($userId);
        if ($userInfo['status'] !== 1) {
            return error('账号已禁用，请联系管理员。');
        }

        // 登录用户
        $result = $this->setloginstatus($userId, uniqid(), ['clienttype' => 'mp']);
        if ($result['errorcode'] !== 0) {
            return $result;
        }
        $userInfo['token'] = $result['data']['token'];
        $userInfo['openid'] = $post['openid'];

        return success($userInfo);
    }

    /**
     * 修改用户手机
     * @return array
     */
    public function changePhone (int $user_id, array $post)
    {
        // 验证手机
        if (!validate_telephone($post['telephone'])) {
            return error('手机号为空或格式不正确！');
        }

        // 验证短信
        if (isset($post['msgcode'])) {
            if (!$this->checkSmsCode($post['telephone'], $post['msgcode'])) {
                return error('验证码错误或已过期！');
            }
        }

        // 判断该手机号是否管理员
        $adminInfo = (new AdminModel())->getUserInfo(['telephone' => $post['telephone']]);
        $updateParams = [
            'telephone'   => $post['telephone'],
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ];
        if ($adminInfo) {
            if ($adminInfo['status'] != 1) {
                return error('该手机号已禁用，请联系管理员。');
            }
            $updateParams = array_filter([
                'telephone'   => $adminInfo['telephone'],
                'full_name'   => $adminInfo['full_name'],
                'avatar'      => $adminInfo['avatar'],
                'gender'      => $adminInfo['gender'],
                'group_id'    => $adminInfo['group_id'],
                'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
            ]);
        }

        // 获取报案当事人信息
        $reportModel = new ReportModel();
        if ($reportInfo = $reportModel->getDerelictCase($post['telephone'])) {
            $updateParams = array_merge($updateParams, $reportInfo);
        }

        // 获取该手机号已注册的用户
        $userInfo = $this->find(['telephone' => $post['telephone'], 'id' => ['<>', $user_id]], 'id');

        if (!$userInfo) {
            // 手机号未注册过
            if (!$this->getDb()->where(['id' => $user_id])->update($updateParams)) {
                return error('手机号更新失败');
            }
        } else {
            // 手机号已被注册
            if (!$this->getDb()->transaction(function ($db) use ($post, $userInfo, $user_id, $updateParams) {
                // 更新占号用户手机号
                if (!$this->getDb()->where(['id' => $userInfo['id']])->update([
                    'telephone' => null,
                    'group_id' => 0,
                    'description' => '解绑手机号' . $post['telephone'] . '到用户' . $user_id, 
                    'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
                ])) {
                    return false;
                }
                // 更新当前用户手机号
                if (!$this->getDb()->where(['id' => $user_id])->update($updateParams)) {
                    return false;
                }
                return true;
            })) {
                return error('手机号解绑失败');
            }
        }

        // 关联报案当事人
        $reportModel->relationCase($user_id, $post['telephone']);

        return success($this->getUserInfo($user_id));
    }

    /**
     * 更改用户信息
     * @return array
     */
    public function saveUserInfo (int $user_id, array $post) 
    {
        $post['full_name'] = trim_space($post['full_name'], 0, 20);
        $post['allow_notice'] = $post['allow_notice'] ? 1 : 0;

        if (false === $this->getDb()->where(['id' => $user_id])->update([
            'full_name' => $post['full_name'],
            'allow_notice' => $post['allow_notice']
        ])) {
            return error('更新数据失败');
        }

        return success('ok');
    }

    /**
     * 获取微信 openid
     * @return string
     */
    public function getWxOpenId (int $user_id, $type = 'mp')
    {
        return $this->getDb()->table('pro_login_binding')->field('openid')->where(['user_id' => $user_id, 'type' => $type])->limit(1)->count();
    }

    /**
     * 更新用户信息
     * @return array
     */
    public function updateUserInfo ($user_id, array $data)
    {
        return $this->getDb()->where(is_array($user_id) ? $user_id : ['id' => $user_id])->update($data);
    }

    /**
     * 获取用户其他信息
     * @return array
     */
    public function getUserProfile ($user_id)
    {
        $data = [];
        $lawNums = (new AdminModel())->getLawNumByUser([$user_id]);
        if ($lawNums) {
            $data['law_num'] = $lawNums[$user_id]; // 执法证号
        }
        return success($data);
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
        $field = $field ? $field : 'id,avatar,nick_name,full_name,idcard,telephone,group_id,allow_notice,status';
        if (!$userInfo = $this->find(is_array($user_id) ? $user_id : ['id' => $user_id], $field)) {
            return [];
        }
        if (isset($userInfo['avatar'])) {
            $userInfo['avatar'] = httpurl($userInfo['avatar']);
        }
        $userInfo['nick_name'] = get_real_val($userInfo['full_name'], $userInfo['nick_name'], $userInfo['telephone']);
        // 部门
        if ($userInfo['group_id']) {
            $groupInfo = (new GroupModel())->find(['id' => $userInfo['group_id']], 'name');
            $userInfo['group_name'] = $groupInfo['name'];
        }
        return $userInfo;
    }

    /**
     * 检查用户信息
     * @param $user_id
     * @return array
     */
    public function checkUserInfo (int $user_id)
    {
        if (!$userInfo = $this->find(['id' => $user_id], 'id,telephone,group_id,status')) {
            json(null, '用户不存在', -1);
        }
        if ($userInfo['status'] != 1) {
            json(null, '你已被禁用', -1);
        }
        return $userInfo;
    }

    /**
     * 获取同事
     * @return array
     */
    public function getColleague (int $user_id, int $group_id)
    {
        return $this->select(['group_id' => $group_id, 'id' => ['not in', [1, $user_id]], 'status' => 1], 'id,full_name as name');
    }

    /**
     * 获取用户姓名
     * @return array
     */
    public function getUserNames (array $id)
    {
        $id = array_filter(array_unique($id));
        if (!$id) {
            return [];
        }
        if (!$userList = $this->select(['id' => ['in', $id]], 'id,nick_name,full_name,telephone')) {
            return [];
        }
        foreach ($userList as $k => $v) {
            $userList[$k]['nick_name'] = get_real_val($v['full_name'], $v['nick_name'], $v['telephone']);
        }
        return array_column($userList, 'nick_name', 'id');
    }

    /**
     * 登录状态设置
     * @return array
     */
    public function setloginstatus ($user_id, $scode, array $opt = [], array $extra = [], $expire = 0)
    {
        if (!$user_id) {
            return error(null);
        }
        $update = [
            'user_id'     => $user_id,
            'scode'       => $scode,
            'clienttype'  => CLIENT_TYPE,
            'loginip'     => get_ip(),
            'online'      => 1,
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ];
        !empty($opt) && $update = array_merge($update, $opt);
        if (!$this->getDb()->table('__tablepre__session')->norepeat($update)) {
            return error(null);
        }
        $token = implode("\t", array_merge([$user_id, $scode, $update['clienttype'], ''], $extra));
        $token = \app\library\DataEncrypt::encode($token);
        // set_cookie('token', $token, $expire);
        return success([
            'token' => $token
        ]);
    }

    /**
     * 登出
     * @return bool
     */
    public function logout ($user_id, $clienttype = null)
    {
        if (!$this->getDb()->table('__tablepre__session')->where([
            'user_id'    => $user_id,
            'clienttype' => get_real_val($clienttype, CLIENT_TYPE)
        ])->update([
            'scode'       => 0,
            'online'      => 0,
            'update_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ])) {
            return false;
        }
        set_cookie('token', null);
        return true;
    }

    /**
     * 验证图片验证码
     * @return bool
     */
    public function checkImgCode ($code, $keep = true)
    {
        if (!preg_match("/^[0-9]{4,6}$/", $code)) {
            return false;
        }
        $name = sprintf("%u", ip2long(get_ip()));
        if ($keep) {
            return $this->getDb()->table('__tablepre__smscode')->where(['tel' => $name, 'code' => $code])->count();
        }
        return $this->getDb()->table('__tablepre__smscode')->where(['tel' => $name, 'code' => $code])->delete();
    }

    /**
     * 验证短信验证码
     * @return bool
     */
    public function checkSmsCode ($telephone, $code)
    {
        if (!preg_match("/^1[0-9]{10}$/", $telephone) || !preg_match("/^[0-9]{4,6}$/", $code)) {
            return false;
        }
        // 处理逻辑为同一个验证码5分钟内可以验证通过10次
        if (!$result = $this->getDb()
            ->field('id, code, errorcount, sendtime')
            ->table('__tablepre__smscode')
            ->where('tel = ?')
            ->bindValue($telephone)
            ->find()) {
            return false;
        }
        if ($result['errorcount'] <= 10) {
            // 累计次数
            if (!$this->getDb()
                ->table('__tablepre__smscode')
                ->where(['id' => $result['id'], 'errorcount' => $result['errorcount']])->update(['errorcount' => ['errorcount+1']])) {
                return false;
            }
        }
        return $result['code'] == $code
            && $result['errorcount'] <= 10
            && $result['sendtime'] > (TIMESTAMP - 300);
    }

    /**
     * 重置短信验证码
     * @return bool
     */
    public function resetSmsCode ($telephone)
    {
        return $this->getDb()->table('__tablepre__smscode')->where(['tel' => $telephone])->delete();
    }

    /**
     * 保存图片验证码
     * @return array
     */
    public function saveImgCode ($code)
    {
        $name = sprintf("%u", ip2long(get_ip()));
        $result = $this->getDb()->table('__tablepre__smscode')->field('id')->where(['tel' => $name])->find();
        if (!$result) {
            if (!$this->getDb()->table('__tablepre__smscode')->insert([
                'tel' => $name, 'code' => $code
            ])) {
                return error('error');
            }
        } else {
            if (false === $this->getDb()->table('__tablepre__smscode')->where(['tel' => $name])->update([
                'code' => $code
            ])) {
                return error('error');
            }
        }
        return success('ok');
    }

    /**
     * 发送短信验证码
     * @return array
     */
    public function sendSmsCode ($post)
    {
        if (!validate_telephone($post['telephone'])) {
            return error('手机号为空或格式错误');
        }

        // 验证码长度
        $len = isset($post['len']) && $post['len'] ? $post['len'] : 6;
        $len = $len >= 4 && $len <= 6 ? $len : 6;
        $arr = range(1, $len);
        foreach ($arr as $k => $v) {
            $arr[$k] = (rand() % 10);
        }
        $code = implode('', $arr);

        $resultSms = $this->getDb()
            ->table('__tablepre__smscode')
            ->field('id,sendtime,hour_fc,day_fc')
            ->where('tel = ?')
            ->bindValue($post['telephone'])
            ->find();

        if (!$resultSms) {
            $resultSms = [
                'tel' => $post['telephone']
            ];
            if (!$resultSms['id'] = $this->getDb()->table('__tablepre__smscode')->insert(['tel' => $post['telephone']], true)) {
                return error('发送失败');
            }
        }

        $params = [
            'code' => $code,
            'errorcount' => 0,
            'sendtime' => TIMESTAMP,
            'hour_fc' => 1,
            'day_fc' => 1
        ];

        if ($resultSms['sendtime']) {
            // 限制发送频率
            if ($resultSms['sendtime'] + 10 > TIMESTAMP) {
                return error('验证码已发送,请稍后再试');
            }
            if (date('YmdH', $resultSms['sendtime']) == date('YmdH', TIMESTAMP)) {
                // 触发时级流控
                if ($resultSms['hour_fc'] >= getSysConfig('hour_fc')) {
                    return error('本时段发送次数已达上限');
                }
                $params['hour_fc'] = ['hour_fc+1'];
            }
            if (date('Ymd', $resultSms['sendtime']) == date('Ymd', TIMESTAMP)) {
                // 触发天级流控
                if ($resultSms['day_fc'] >= getSysConfig('day_fc')) {
                    return error('今日发送次数已达上限');
                }
                $params['day_fc'] = ['day_fc+1'];
            }
        }

        if (!$this->getDb()->table('__tablepre__smscode')->where(['id = ' . $resultSms['id'], 'hour_fc <= ' . getSysConfig('hour_fc'), 'day_fc <= ' . getSysConfig('day_fc')])->update($params)) {
            return error('发送失败');
        }

        // 发送短信
        return (new MsgModel())->sendCode($post['telephone'], $code);
    }

}
