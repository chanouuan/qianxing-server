<?php

namespace app\controllers;

use ActionPDO;

/**
 * 小程序服务端接口
 * @Version v1
 */
class Miniprogramserver extends ActionPDO {

    public function _ratelimit ()
    {
        return [
            'upload'              => ['interval' => 1000],
            'login'               => ['interval' => 1000],
            'changePhone'         => ['interval' => 1000],
            'sendSms'             => ['interval' => 1000, 'rule' => '5|10|20'],
            'saveUserInfo'        => ['interval' => 200],
            'getDistrictGroup'    => ['interval' => 1000],
            'reportEvent'         => ['interval' => 1000],
            'getUserReportEvents' => ['interval' => 200],
            'getReportEvents'     => ['interval' => 200],
            'acceptReport'        => ['interval' => 1000],
            'getReportDetail'     => ['interval' => 200],
            'reportInfo'          => ['interval' => 1000],
            'cardInfo'            => ['interval' => 1000],
            'searchPropertyItems' => ['interval' => 200],
            'reportItem'          => ['interval' => 1000],
            'reportFile'          => ['interval' => 1000],
            'cancelReport'        => ['interval' => 1000],
            'deleteReport'        => ['interval' => 1000],
            'getPropertyPayItems' => ['interval' => 1000],
            'createPay'           => ['interval' => 1000],
            'payQuery'            => ['interval' => 1000],
            'getGroupBook'        => ['interval' => 200],
            'trunReport'          => ['interval' => 1000],
            'trunUserReport'      => ['interval' => 1000],
            'getUserCount'        => ['interval' => 1000]
        ];
    }

    public function _init()
    {

    }

    /**
     * 首页加载
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function loadData ()
    {
        $banner = (new \app\models\BannerModel())->getBanner();
        $usercount = (new \app\models\UserCountModel())->loadInfo($this->_G['user']['user_id'], 'report_count');
        return success([
            'banner' => $banner,
            'report_count' => $usercount['report_count']
        ]);
    }

    /**
     * 上传文件
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function upload () 
    {
        return (new \app\models\ReportModel($this->_G['user']['user_id']))->upload($_POST);
    }

    /**
     * 小程序登录
     * @param *code 小程序登录凭证
     * @return array
     * {
     * "errorcode":0, // 错误码 0成功 -1失败
     * "message":"", //错误消息
     * "data":{
     *     "id":1, //用户ID
     *     "telephone":"", //手机号
     *     "avatar":"", //头像地址
     *     "nickname":"", //昵称
     *     "gender":1, //性别 0未知 1男 2女
     *     "token":"", //登录凭证
     * }}
     */
    public function login ()
    {
        if (CLIENT_TYPE != 'wx') {
            return error('请检查客户端版本');
        }

        $code = getgpc('code');
        if (empty($code)) {
            return error('请填写小程序登录凭证');
        }

        $wxConfig = getSysConfig('qianxing', 'wx');
        $jssdk = new \app\library\JSSDK($wxConfig['appid'], $wxConfig['appsecret']);
        $reponse = $jssdk->wXBizDataCrypt([
            'code' => $code
        ]);
        if ($reponse['errorcode'] !== 0) {
            return $reponse;
        }
        $reponse = $reponse['data'];

        return (new \app\models\UserModel())->mpLogin(array_merge($_POST, $reponse));
    }

    /**
     * 修改用户手机号
     * @login
     * @param code 小程序登录凭证
     * @param encryptedData 手机号加密数据
     * @param iv 加密算法的初始向量
     * @param telephone 手机号
     * @param msgcode 短信验证码
     * @return array
     * {
     * "errorcode":0, // 错误码 0成功 -1失败
     * "message":"", //错误消息
     * "data":{}
     * }
     */
    public function changePhone ()
    {
        if ($_POST['code']) {
            // 微信授权登录，获取手机号
            $wxConfig = getSysConfig('qianxing', 'wx');
            $jssdk = new \app\library\JSSDK($wxConfig['appid'], $wxConfig['appsecret']);
            $reponse = $jssdk->wXBizDataCrypt([
                'code' => $_POST['code'],
                'getPhoneNumber' => [
                    'encryptedData' => $_POST['encryptedData'],
                    'iv' => $_POST['iv']
                ]
            ]);
            if ($reponse['errorcode'] !== 0) {
                return $reponse;
            }
            $reponse = $reponse['data'];
            $_POST['telephone'] = $reponse['telephone'];
        } else {
            // 手机短信登录
            $_POST['msgcode'] = strval($_POST['msgcode']);
        }

        return (new \app\models\UserModel())->changePhone($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 获取用户信息数
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function getUserCount ()
    {
        return success((new \app\models\UserCountModel())->loadInfo($this->_G['user']['user_id']));
    }
    
    /**
     * 更改用户信息
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function saveUserInfo () 
    {
        return (new \app\models\UserModel())->saveUserInfo($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 发送短信验证码
     * @param *telephone 手机号
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function sendSms () 
    {
        return (new \app\models\UserModel())->sendSmsCode($_POST);
    }
    
    /**
     * 获取区域执法单位
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function getDistrictGroup ()
    {
        return (new \app\models\GroupModel())->getDistrictGroup($_POST);
    }

    /**
     * 事故报警
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function reportEvent ()
    {
        return (new \app\models\UserReportModel())->reportEvent($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 获取用户报案记录
     * @login
     * @param lastpage 分页参数
     * @return array
     * {
     * "errorcode":0, //错误码 0成功 -1失败
     * "message":"",
     * "data":{
     *     "limit":10, //每页最大显示数
     *     "lastpage":"", //分页参数
     *     "list":[{
     *      }]
     * }}
     */
    public function getUserReportEvents () 
    {
        $_POST['status'] = 0; // 只获取未受理
        return (new \app\models\UserReportModel())->getUserReportEvents($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 获取案件记录
     * @login
     * @param lastpage 分页参数
     * @return array
     * {
     * "errorcode":0, //错误码 0成功 -1失败
     * "message":"",
     * "data":{
     *     "limit":10, //每页最大显示数
     *     "lastpage":"", //分页参数
     *     "list":[{
     *      }]
     * }}
     */
    public function getReportEvents () 
    {
        return (new \app\models\ReportModel())->getReportEvents($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 获取案件信息
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function getReportDetail ()
    {
        return (new \app\models\ReportModel($this->_G['user']['user_id']))->getReportDetail($_POST);
    }

    /**
     * 审理案件
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function acceptReport ()
    {
        return (new \app\models\ReportModel($this->_G['user']['user_id']))->acceptReport($_POST);
    }

    /**
     * 填写报送信息
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function reportInfo ()
    {
        return (new \app\models\ReportModel($this->_G['user']['user_id']))->reportInfo($_POST);
    }

    /**
     * 获取同事
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function getColleague ()
    {
        return (new \app\models\UserModel())->getColleague($this->_G['user']['user_id']);
    }

    /**
     * 保存当事人信息
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function cardInfo ()
    {
        return (new \app\models\ReportModel($this->_G['user']['user_id']))->cardInfo($_POST);
    }

    /**
     * 搜索路产赔损项目
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function searchPropertyItems ()
    {
        return (new \app\models\PropertyModel())->search($_POST);
    }

    /**
     * 保存勘验笔录
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function reportItem ()
    {
        return (new \app\models\ReportModel($this->_G['user']['user_id']))->reportItem($_POST);
    }

    /**
     * 转发赔偿通知书
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function reportFile ()
    {
        return (new \app\models\ReportModel($this->_G['user']['user_id']))->reportFile($_POST);
    }

    /**
     * 撤销案件
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function cancelReport ()
    {
        return (new \app\models\ReportModel($this->_G['user']['user_id']))->cancelReport($_POST);
    }

    /**
     * 删除报案
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function deleteReport ()
    {
        return (new \app\models\UserReportModel())->deleteReport($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 获取赔偿清单
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function getPropertyPayItems ()
    {
        return (new \app\models\ReportModel())->getPropertyPayItems($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 生成交易单
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":{
     *      "trade_id":1
     * }}
     */
    public function createPay ()
    {
        return (new \app\models\TradeModel())->createPay($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 查询支付
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":{}
     * }
     */
    public function payQuery ()
    {
        return (new \app\models\TradeModel())->payQuery($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 移交部门人员
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":{}
     * }
     */
    public function getGroupBook ()
    {
        return (new \app\models\GroupModel())->getGroupBook($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 移交报案
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":{}
     * }
     */
    public function trunUserReport ()
    {
        return (new \app\models\UserReportModel())->trunUserReport($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 移交案件
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":{}
     * }
     */
    public function trunReport ()
    {
        return (new \app\models\ReportModel($this->_G['user']['user_id']))->trunReport($_POST);
    }
    

}
