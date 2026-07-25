<?php
/**
 * ClawClaw.tech PHP 版支付网关
 *
 * 从 shared-modules/pay-gateway (Node.js) 移植：
 *   - XunhuPay：虎皮椒微信/支付宝 WAP 收银台（MD5 签名+回调验签）
 *   - ClawPayUSDT：USDT TRC-20 收款（TronGrid API 主动查证，回调不可信）
 *
 * @author ClawClaw.tech
 */

require_once __DIR__ . '/config.php';

// ════════════════════════════════════════════════════════════════
// XunhuPay — 虎皮椒（微信/支付宝 WAP）
// 签名：参数 key ASCII 升序拼 k=v&...，末尾追加 appKey，MD5 小写
// ════════════════════════════════════════════════════════════════
class XunhuPay {

    /**
     * 虎皮椒签名（参数 key ASCII 升序拼成 k=v&...，末尾追加 appKey，MD5 小写）
     */
    public static function sign($params, $appKey) {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null || $k === 'hash') continue;
            $filtered[$k] = $v;
        }
        ksort($filtered);
        $str = implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($filtered), $filtered)) . $appKey;
        return md5($str);
    }

    /**
     * 创建支付订单
     * @return ['tradeOrderId'=>..., 'payUrl'=>...] 失败抛 Exception
     */
    public static function createOrder($tradeOrderId, $amountYuan, $title) {
        if (!xunhuEnabled()) {
            throw new Exception('虎皮椒未配置：请在 license/config.php 填入 XUNHU_APPID/XUNHU_APPKEY');
        }
        $params = [
            'version'        => '1.1',
            'appid'          => XUNHU_APPID,
            'trade_order_id' => $tradeOrderId,
            'total_fee'      => number_format((float)$amountYuan, 2, '.', ''),
            'title'          => $title ?: '订单支付',
            'time'           => (string)time(),
            'notify_url'     => XUNHU_NOTIFY_URL,
            'return_url'     => XUNHU_RETURN_URL,
            'nonce'          => bin2hex(random_bytes(16)),
            'type'           => 'WAP',
            'wap_url'        => XUNHU_WAP_URL,
            'wap_name'       => XUNHU_WAP_NAME,
        ];
        $params['hash'] = self::sign($params, XUNHU_APPKEY);

        $resp = self::httpPost(XUNHU_API_URL, http_build_query($params));
        $data = json_decode($resp, true);
        if (!isset($data['errcode']) || $data['errcode'] !== 0 || empty($data['url'])) {
            throw new Exception('xunhupay create failed: ' . ($data['errmsg'] ?? json_encode($data)));
        }
        return ['tradeOrderId' => $tradeOrderId, 'payUrl' => $data['url']];
    }

    /**
     * 回调验签
     * @return ['ok'=>true,'paid'=>bool,'tradeOrderId'=>str,'raw'=>arr] 或 ['ok'=>false]
     */
    public static function verifyNotify($body) {
        if (empty($body['hash'])) return ['ok' => false];
        if ($body['hash'] !== self::sign($body, XUNHU_APPKEY)) return ['ok' => false];
        return [
            'ok'           => true,
            'paid'         => ($body['status'] ?? '') === 'OD',
            'tradeOrderId' => $body['trade_order_id'] ?? '',
            'raw'          => $body,
        ];
    }

    private static function httpPost($url, $body) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp;
    }
}

// ════════════════════════════════════════════════════════════════
// ClawPayUSDT — USDT TRC-20 收款（TronGrid API 主动查证）
// 安全要点：回调不可信，一律向 TronGrid 查证后才确认
// ════════════════════════════════════════════════════════════════
class ClawPayUSDT {

    /**
     * 查找匹配的 USDT TRC-20 转账
     * @param array $order 订单（含 amount / createdMs / txHash 字段）
     * @return array|null 匹配的交易（含 transaction_id, from, value, block_timestamp）或 null
     */
    public static function findMatchingTransaction($order) {
        $wallet = USDT_WALLET;
        $amount = (float)$order['amount'];
        $createdMs = (int)$order['createdMs'];
        $minTimestamp = $createdMs - 30000;  // 允许 30 秒前创建（区块确认延迟）

        $url = TRONGRID_API . "/v1/accounts/{$wallet}/transactions/trc20"
             . "?limit=20&order_by=block_timestamp,desc&only_confirmed=true&only_to=true";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode != 200 || !$resp) return null;
        $data = json_decode($resp, true);
        if (!isset($data['data']) || !is_array($data['data'])) return null;

        $expectedValue = (int)($amount * 1000000);  // USDT 6 位小数
        $usedTxIds = self::getUsedTxIds();

        foreach ($data['data'] as $tx) {
            if (($tx['to'] ?? '') !== $wallet) continue;
            $tokenInfo = $tx['token_info'] ?? [];
            if (($tokenInfo['symbol'] ?? '') !== 'USDT') continue;
            $value = intval($tx['value'] ?? 0);
            if (abs($value - $expectedValue) > 500000) continue;  // ±0.5 USDT 容差
            $blockTs = intval($tx['block_timestamp'] ?? 0);
            if ($blockTs < $minTimestamp) continue;
            $txId = $tx['transaction_id'] ?? '';
            if (in_array($txId, $usedTxIds, true)) continue;  // 已被其他订单使用
            return $tx;
        }
        return null;
    }

    /**
     * 获取已被订单使用的交易哈希列表（防重复使用）
     */
    private static function getUsedTxIds() {
        if (!file_exists(ORDERS_FILE)) return [];
        $orders = json_decode(file_get_contents(ORDERS_FILE), true) ?: [];
        $txIds = [];
        foreach ($orders as $order) {
            if (!empty($order['txHash'])) $txIds[] = $order['txHash'];
        }
        return $txIds;
    }
}
