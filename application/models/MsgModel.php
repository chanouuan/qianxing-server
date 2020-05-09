<?php

namespace app\models;

use Crud;
use app\library\AliSmsHelper;

class MsgModel extends Crud {

    /**
     * 发送报案通知短信
     * ${date}，手机号为${phone}的用户在${addr}发生${type}，请及时联系司机并前往处置
     * @return array
     */
    public function sendReportEventSms (int $group_id, array $templete_params)
    {
        // 按指定职位 外勤
        $adminUsers = $this->getDb()
            ->table('admin_roles role inner join admin_role_user user on user.role_id = role.id')
            ->field('user.user_id')
            ->where(['role.name' => ['in', ['外勤', '管理员']], 'role.group_id' => $group_id, 'role.is_sys' => 1])
            ->select();
        if (!$adminUsers) {
            return false;
        }
        if (!$telephone = (new AdminModel())->select(['group_id' => $group_id, 'status' => 1, 'id' => ['in', array_column($adminUsers, 'user_id')]], 'telephone')) {
            return error('未找到接收人');
        }
        if (!$telephone = array_filter(array_column($telephone, 'telephone'))) {
            return error('未找到接收人');
        }
        // 报警通知开关
        $enableTel = (new UserModel())->select(['allow_notice' => 0, 'telephone' => ['in', $telephone]], 'telephone');
        if ($enableTel) {
            $enableTel = array_column($enableTel, 'telephone');
            // 去掉关闭通知的用户
            $telephone = array_diff($telephone, $enableTel);
        }
        if (!$telephone) {
            return error('没有接收人');
        }
        $params = [
            'date' => date('Y年m月d日 H时i分', TIMESTAMP),
            'phone' => $templete_params['user_mobile'],
            'addr' => $templete_params['address'],
            'type' => \app\common\ReportType::getMessage($templete_params['report_type'])
        ];
        return (new AliSmsHelper())->sendSms('遵义高速公路管理处', 'SMS_189760235', $telephone, $params);
    }

    /**
     * 通知报案人已受理案件
     * ${date}，${group}已受理报案。请开启危险报警闪烁灯，夜间还需开启示轮廓灯，请在车后方（白天150米外，夜间250米外）放置警示牌，人员请撤离到护栏外，等待救援。联系电话：${phone}
     * @return array
     */
    public function sendUserAcceptSms ($user_phone, $group_id)
    {
        if (!$user_phone) {
            return error('用户手机为空');
        }
        $groupInfo = (new GroupModel())->find(['id' => $group_id], 'name,phone');
        $params = [
            'date' => date('Y年m月d日 H时i分', TIMESTAMP),
            'group' => $groupInfo['name'],
            'phone' => $groupInfo['phone']
        ];
        return (new AliSmsHelper())->sendSms('遵义高速公路管理处', 'SMS_189760224', $user_phone, $params);
    }

    /**
     * 用户司机接收赔偿通知书短信
     * ${date}，${name}驾驶机动车${car}发生交通事故的【赔（补）偿通知书】已发送至微信小程序“黔中行”，请你前往查看并在7天内到${group}进行处理。联系电话：${phone}
     * @return array
     */
    public function sendReportPaySms ($user_phone, int $group_id, int $report_id)
    {
        if (!$user_phone) {
            return error('用户手机为空');
        }
        $groupInfo = (new GroupModel())->find(['id' => $group_id], 'name,phone');
        $reportInfo = $this->getDb()->table('qianxing_report_info')->field('full_name,plate_num')->where(['id' => $report_id])->find();
        $params = [
            'date' => date('Y年m月d日 H时i分', TIMESTAMP),
            'name' => $reportInfo['full_name'],
            'car' => $reportInfo['plate_num'],
            'group' => $groupInfo['name'],
            'phone' => $groupInfo['phone']
        ];
        return (new AliSmsHelper())->sendSms('遵义高速公路管理处', 'SMS_189760232', $user_phone, $params);
    }

    /**
     * 发送验证码
     * @return array
     */
    public function sendCode ($phone, $code)
    {
        return (new AliSmsHelper())->sendSms('遵义高速公路管理处', 'SMS_189760222', $phone, ['code' => $code]);
    }

}