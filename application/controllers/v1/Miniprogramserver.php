<?php

namespace app\controllers;

use ActionPDO;
use app\models\UserModel;
use app\models\ReportModel;
use app\models\UserReportModel;
use app\models\PropertyModel;

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
            'reportEvent'         => ['interval' => 1000],
            'getUserReportEvents' => ['interval' => 200],
            'getReportEvents'     => ['interval' => 200],
            'acceptReport'        => ['interval' => 1000],
            'getReportDetail'     => ['interval' => 200],
            'reportInfo'          => ['interval' => 1000],
            'reloadReport'        => ['interval' => 1000],
            'cardInfo'            => ['interval' => 1000],
            'searchPropertyItems' => ['interval' => 200],
            'reportItem'          => ['interval' => 1000]
        ];
    }

    public function _init()
    {
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
        return (new ReportModel($this->_G['user']['user_id']))->upload($_POST);
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

        return (new UserModel())->mpLogin(array_merge($_POST, $reponse));
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

        return (new UserModel())->changePhone($this->_G['user']['user_id'], $_POST);
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
        return (new UserModel())->sendSmsCode($_POST);
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
        return (new UserReportModel())->reportEvent($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 获取用户报案记录
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
        return (new UserReportModel())->getUserReportEvents($_POST);
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
        return (new ReportModel())->getReportEvents($this->_G['user']['user_id'], $_POST);
    }

    /**
     * 获取案件信息(管理端)
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
        return (new ReportModel($this->_G['user']['user_id']))->getReportDetail($_POST);
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
        return (new ReportModel($this->_G['user']['user_id']))->acceptReport($_POST);
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
        return (new ReportModel($this->_G['user']['user_id']))->reportInfo($_POST);
    }

    /**
     * 案件处置
     * @login
     * @return array
     * {
     * "errorcode":0,
     * "message":"",
     * "data":[]
     * }
     */
    public function reloadReport ()
    {
        return (new ReportModel($this->_G['user']['user_id']))->reloadReport($_POST);
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
        return (new UserModel())->getColleague($this->_G['user']['user_id']);
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
        return (new ReportModel($this->_G['user']['user_id']))->cardInfo($_POST);
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
        return (new PropertyModel())->search($_POST);
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
        return (new ReportModel($this->_G['user']['user_id']))->reportItem($_POST);
    }

}
