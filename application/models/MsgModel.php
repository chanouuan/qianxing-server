<?php

namespace app\models;

use Crud;
use app\library\AliSmsHelper;

class MsgModel extends Crud {

    /**
     * 发送报案通知短信
     * xxxx年xx月xx日 xx时xx分，手机号为xxx的用户在xx地址发生xx事故，请及时联系司机并前往处置。
     * @return array
     */
    public function sendReportEventSms (int $group_id, array $templete_params)
    {
        // 可优化按指定职位
        if (!$telephone = (new AdminModel())->select(['group_id' => $group_id], 'telephone')) {
            return error('未找到接收人');
        }
        if (!$telephone = array_filter(array_column($telephone, 'telephone'))) {
            return error('未找到接收人');
        }
        $params = [
            'date' => date('Y年m月d日 H时i分', TIMESTAMP),
            'phone' => $templete_params['user_mobile'],
            'address' => $templete_params['address'],
            'type' => \app\common\ReportType::getMessage($templete_params['report_type'])
        ];
        $params = array_fill(0, count($telephone), $params);
        return (new AliSmsHelper())->sendBatchSms('黔中行', 'SMS_188556034', $telephone, $params);
    }

    /**
     * 路政人员受理案件
     * xxxx年xx月xx日 xx时xx分，开始受理手机号为xxx的当事人案件。
     * @return array
     */
    public function sendReportAcceptSms ($user_phone, $param_phone)
    {
        $params = [
            'date' => date('Y年m月d日 H时i分', TIMESTAMP),
            'phone' => $param_phone
        ];
        return (new AliSmsHelper())->sendSms('黔中行', 'SMS_188570794', $user_phone, $params);
    }

    /**
     * 用户司机接收赔偿通知书短信
     * xxxx年xx月xx日 xx时xx分，xxx驾驶机动车xxxx发生交通事故，造成高速公路路产损坏，请你在7天内到xxxx执法大队进行处理，详情可以关注微信公众号“平安遵义高速”查看。处理机关:xxxx大队，电话:xxxx
     * @return array
     */
    public function sendReportPaySms ($user_phone, int $group_id, int $report_id)
    {
        $groupInfo = (new GroupModel())->find(['id' => $group_id], 'name,phone');
        $reportInfo = $this->getDb()->table('qianxing_report_info')->field('full_name,plate_num')->where(['id' => $report_id])->find();
        $params = [
            'date' => date('Y年m月d日 H时i分', TIMESTAMP),
            'name' => $reportInfo['full_name'],
            'car' => $reportInfo['plate_num'],
            'group' => $groupInfo['name'],
            'phone' => $groupInfo['phone']
        ];
        return (new AliSmsHelper())->sendSms('黔中行', 'SMS_188551111', $user_phone, $params);
    }

    /**
     * 案件结案时通知执法人员
     * xxxx年xx月xx日 xx时xx分，案件XXXXXXXX(黔遵高路龙坪赔[2020]9号)，已结案。
     * @return array
     */
    public function sendReportCompleteSms (int $law_id, int $report_id)
    {
        $userInfo = (new UserModel())->find(['id' => $law_id], 'telephone,group_id');
        $groupInfo = (new GroupModel())->find(['id' => $userInfo['group_id']], 'way_name');
        $reportInfo = $this->getDb()->table('qianxing_report_info')->field('archive_num')->where(['id' => $report_id])->find();
        $params = [
            'date' => date('Y年m月d日 H时i分', TIMESTAMP),
            'name' => $groupInfo['way_name'],
            'num' => '[' . date('Y', TIMESTAMP) . ']' . $reportInfo['archive_num']
        ];
        return (new AliSmsHelper())->sendSms('黔中行', 'SMS_188565950', $userInfo['telephone'], $params);
    }

    /**
     * 发送验证码
     * @return array
     */
    public function sendCode ($phone, $code)
    {
        return (new AliSmsHelper())->sendSms('黔中行', 'SMS_133971610', $phone, ['code' => $code]);
    }

}