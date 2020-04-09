<?php

namespace app\models;

use Crud;
use app\common\TradeSource;
use app\common\PayWay;

class TradeModel extends Crud {

    protected $table = 'pro_trade';

    /**
     * 更新交易单
     * @return bool
     */
    public function updateTrade ($trade_id, array $data)
    {
        return $this->getDb()->where(is_array($trade_id) ? $trade_id : ['id' => $trade_id])->update($data);
    }

    /**
     * 生成交易单
     * @return array
     */
    public function createPay (int $user_id, array $post)
    {
        $post['order_id'] = intval($post['order_id']);
        $post['source'] = TradeSource::format($post['source']);
        $post['payway'] = PayWay::format($post['payway']);

        if (!$post['order_id'] || !$post['source'] || !$post['payway']) {
            return error('参数错误');
        }

        // 订单号
        $orderCode = $this->generateOrderCode($user_id);

        // 防止重复下单
        if ($lastTradeInfo = $this->find(['user_id' => $user_id, 'status' => 0, 'source' => $post['source'], 'order_id' => $post['order_id']], 'id,payway,create_time')) {
            if (!$lastTradeInfo['payway'] || $lastTradeInfo['payway'] == $post['payway']) {
                if (strtotime($lastTradeInfo['create_time']) < TIMESTAMP - 600) {
                    // 更新订单号
                    $this->getDb()->where(['id' => $lastTradeInfo['id']])->update([
                        'order_code' => $orderCode,
                        'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
                    ]);
                }
                return success([
                    'trade_id' => $lastTradeInfo['id']
                ]);
            }
        }

        $result = TradeSource::getInstanceModel($post['source'])->createPay($user_id, $post);
        if ($result['errorcode'] !== 0) {
            return $result;
        }
        $result = $result['data'];

        // 生成交易单
        if (!$tradeId = $this->getDb()->insert([
            'source' => $post['source'],
            'user_id' => $user_id,
            'order_id' => $post['order_id'],
            'pay' => $result['pay'],
            'money' => $result['money'],
            'order_code' => $orderCode,
            'create_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ],true)) {
            return error('交易单保存失败');
        }

        if ($result['pay'] === 0) {
            // 无支付金额
            $result = $this->paySuccess($post['payway'], $orderCode);
            if ($result['errorcode'] !== 0) {
                return $result;
            }
        }

        return success([
            'trade_id' => $tradeId
        ]);
    }

    /**
     * 查询支付是否成功
     * @return array
     */
    public function payQuery (int $user_id, array $post)
    {
        $post['trade_id'] = intval($post['trade_id']);
        if (!$tradeInfo = $this->find(['id' => $post['trade_id'], 'user_id' => $user_id], 'id,pay,payway,mchid,order_code,status')) {
            return error('交易单未找到');
        }

        if ($tradeInfo['status'] == 1) {
            return success([
                'pay_result' => 'SUCCESS'
            ]);
        } elseif ($tradeInfo['status'] != 0) {
            return success(['msg' => '不是待支付订单']);
        }

        if (!$tradeInfo['payway']) {
            return success(['msg' => '还未支付']);
        }

        // 查询订单
        $className = '\\app\\controllers\\' . ucfirst($tradeInfo['payway']);
        $referer = new $className();
        $referer->_module = $tradeInfo['payway'];
        $referer->_init();
        $result = call_user_func([$referer, 'query']);

        if ($result['errorcode'] !== 0) {
            return success(['msg' => $result['message']]);
        }
        $result = $result['data'];
        if ($result['pay_success'] !== 'SUCCESS') {
            return success(['msg' => $result['trade_status']]);
        }
        if ($result['mchid'] != $tradeInfo['mchid'] || $result['total_fee'] != $tradeInfo['pay']) {
            return success(['msg' => '支付验证失败']);
        }

        // 支付成功
        return $this->paySuccess($tradeInfo['payway'], $result['out_trade_no'], $result['trade_no'], $result['mchid'], $result['trade_type'], $result['trade_status'], $result['total_fee']);
    }

    /**
     * 支付成功回调
     * @param string $payway 支付方式
     * @param string $out_trade_no 商户订单号
     * @param string $trade_no 第三方支付订单号
     * @param string $mchid 商户ID
     * @param string $trade_type 支付类型
     * @param string $trade_status 支付状态
     * @param string $total_fee 支付金额
     * @return array
     */
    public function paySuccess ($payway, $out_trade_no, $trade_no = '', $mchid = '', $trade_type = '', $trade_status = '', $total_fee = 0)
    {
        if (!$tradeInfo = $this->find(['order_code' => $out_trade_no, 'status' => 0])) {
            return error($out_trade_no . '交易单未找到');
        }

        // 效验支付金额
        if ($tradeInfo['pay'] != $total_fee) {
            return error('支付金额效验失败');
        }

        $tradeParam = [
            'payway' => strtolower($payway),
            'status' => 1,
            'trade_no' => $trade_no,
            'mchid' => $mchid,
            'trade_type' => $trade_type,
            'trade_status' => $trade_status,
            'pay_time' => date('Y-m-d H:i:s', TIMESTAMP)
        ];

        $tradeInfo = array_merge($tradeInfo, $tradeParam);
        $model = TradeSource::getInstanceModel($tradeInfo['source']);

        if (!$this->getDb()->transaction(function ($db) use ($tradeInfo, $tradeParam, $model) {
            if (!$this->getDb()->where(['id' => $tradeInfo['id'], 'status' => 0])->update($tradeParam)) {
                return false;
            }
            return $model->paySuccess($tradeInfo);
        })) {
            return error('更新支付状态失败');
        }

        $model->payComplete($tradeInfo);

        unset($tradeInfo);
        return success([
            'pay_result' => 'SUCCESS'
        ]);
    }

    /**
     * 生成单号(16位)
     * @return string
     */
    protected function generateOrderCode (int $user_id)
    {
        $code[] = date('Ymd', TIMESTAMP);
        $code[] = (rand() % 10) . (rand() % 10) . (rand() % 10) . (rand() % 10);
        $code[] = str_pad(substr($user_id, -4), 4, '0', STR_PAD_LEFT);
        return implode('', $code);
    }

}