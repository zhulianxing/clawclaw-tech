<?php
/**
 * ClawClaw.tech 统一许可证服务器
 *
 * 支持 13 款产品的购买 + 激活码生成 + 双通道给码（页面显示 + 邮件发送）
 *
 * API：
 *   GET  ?action=info&product=<key>             产品信息
 *   POST ?action=create-order                   创建订单 {product, email, channel}
 *   GET  ?action=check-order&orderId=<id>       轮询订单状态
 *   GET  ?action=verify-code&code=<key>         验证激活码
 *   POST ?action=xunhu-notify                   虎皮椒回调（form-urlencoded）
 *   GET  ?action=generate-test&product=<key>    测试生成激活码（不发邮件）
 *
 * @author ClawClaw.tech
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pay-gateway.php';
require_once __DIR__ . '/smtp.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ── 路由 ──
$action = $_GET['action'] ?? 'info';

switch ($action) {
    case 'info':
        handleInfo();
        break;
    case 'create-order':
        handleCreateOrder();
        break;
    case 'check-order':
        handleCheckOrder();
        break;
    case 'verify-code':
        handleVerifyCode();
        break;
    case 'generate-test':
        handleGenerateTest();
        break;
    case 'xunhu-notify':
        handleXunhuNotify();
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action: ' . $action]);
}

// ════════════════════════════════════════════════════════════════
// info — 产品信息
// ════════════════════════════════════════════════════════════════
function handleInfo() {
    $productKey = $_GET['product'] ?? '';
    if ($productKey) {
        $p = getProduct($productKey);
        if (!$p) {
            echo json_encode(['error' => 'Unknown product: ' . $productKey]);
            return;
        }
        echo json_encode([
            'product'     => $productKey,
            'name'        => $p['name'],
            'price'       => $p['price'],
            'priceUsdt'   => $p['priceUsdt'],
            'prefix'      => $p['prefix'],
            'desc'        => $p['desc'],
            'apk'         => $p['apk'],
            'xunhuEnabled' => xunhuEnabled(),
        ]);
    } else {
        // 全部产品列表
        $list = [];
        foreach (PRODUCTS as $key => $p) {
            $list[] = [
                'key' => $key, 'name' => $p['name'], 'price' => $p['price'],
                'priceUsdt' => $p['priceUsdt'], 'prefix' => $p['prefix'],
            ];
        }
        echo json_encode([
            'products'     => $list,
            'xunhuEnabled' => xunhuEnabled(),
            'usdtWallet'   => USDT_WALLET,
        ]);
    }
}

// ════════════════════════════════════════════════════════════════
// create-order — 创建订单
// body: {product: <key>, email: <email>, channel: 'xunhu'|'usdt'}
// ════════════════════════════════════════════════════════════════
function handleCreateOrder() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $productKey = trim($input['product'] ?? '');
    $email = trim($input['email'] ?? '');
    $channel = trim($input['channel'] ?? 'usdt');

    $product = getProduct($productKey);
    if (!$product) {
        echo json_encode(['error' => 'Unknown product: ' . $productKey]);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => '邮箱地址无效']);
        return;
    }
    if ($channel === 'xunhu' && !xunhuEnabled()) {
        echo json_encode(['error' => '微信/支付宝通道未启用，请使用 USDT']);
        return;
    }

    $orderId = generateOrderId($product['prefix']);
    $now = time();
    $amountYuan = $channel === 'xunhu' ? $product['price'] : 0;
    $amountUsdt = $channel === 'usdt' ? $product['priceUsdt'] : 0;

    $orders = readOrders();
    $orders[$orderId] = [
        'id'           => $orderId,
        'product'      => $productKey,
        'productName'  => $product['name'],
        'prefix'       => $product['prefix'],
        'email'        => $email,
        'channel'      => $channel,
        'amountYuan'   => $amountYuan,
        'amount'       => $amountUsdt,  // USDT 金额（兼容旧逻辑）
        'status'       => 'pending',
        'created'      => $now,
        'createdMs'    => $now * 1000,
        'expires'      => $now + ORDER_EXPIRE_MINUTES * 60,
        'activationCode' => null,
        'paidAt'       => null,
        'txHash'       => null,
        'payUrl'       => null,
    ];
    writeOrders($orders);

    $resp = [
        'success'  => true,
        'orderId'  => $orderId,
        'product'  => $productKey,
        'name'     => $product['name'],
        'channel'  => $channel,
        'email'    => $email,
        'expiresIn' => ORDER_EXPIRE_MINUTES * 60,
    ];

    if ($channel === 'usdt') {
        // USDT：返回钱包地址 + QR 码
        $resp['amount'] = $amountUsdt;
        $resp['currency'] = 'USDT';
        $resp['network'] = 'TRC-20';
        $resp['wallet'] = USDT_WALLET;
        $resp['qrUrl'] = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode(USDT_WALLET);
    } else {
        // 虎皮椒：调起收银台
        try {
            $r = XunhuPay::createOrder($orderId, $amountYuan, $product['name'] . ' 激活码');
            $orders[$orderId]['payUrl'] = $r['payUrl'];
            writeOrders($orders);
            $resp['amount'] = $amountYuan;
            $resp['currency'] = 'CNY';
            $resp['payUrl'] = $r['payUrl'];
        } catch (Exception $e) {
            // 下单失败，删除订单
            unset($orders[$orderId]);
            writeOrders($orders);
            echo json_encode(['error' => '虎皮椒下单失败: ' . $e->getMessage()]);
            return;
        }
    }

    echo json_encode($resp);
}

// ════════════════════════════════════════════════════════════════
// check-order — 轮询订单状态
// ════════════════════════════════════════════════════════════════
function handleCheckOrder() {
    $orderId = $_GET['orderId'] ?? '';
    if (empty($orderId)) {
        echo json_encode(['error' => '缺少 orderId']);
        return;
    }

    $orders = readOrders();
    if (!isset($orders[$orderId])) {
        echo json_encode(['error' => '订单不存在或已过期']);
        return;
    }
    $order = $orders[$orderId];

    // 已支付：直接返回
    if ($order['status'] === 'paid') {
        echo json_encode([
            'success'       => true,
            'status'        => 'paid',
            'orderId'       => $orderId,
            'activationCode' => $order['activationCode'],
            'email'         => $order['email'],
            'emailSent'     => $order['emailSent'] ?? false,
            'productName'   => $order['productName'],
            'message'       => '支付成功！激活码已生成' . (!empty($order['emailSent']) ? '并发送到您的邮箱' : ''),
        ]);
        return;
    }

    // 过期
    if (time() > $order['expires']) {
        $orders[$orderId]['status'] = 'expired';
        writeOrders($orders);
        echo json_encode(['success' => false, 'status' => 'expired', 'message' => '订单已过期，请重新下单']);
        return;
    }

    // USDT：主动查 TronGrid API
    if ($order['channel'] === 'usdt') {
        $tx = ClawPayUSDT::findMatchingTransaction($order);
        if ($tx !== null) {
            markOrderPaid($orders, $orderId, $tx['transaction_id'] ?? '', $order);
            return;
        }
    }
    // 虎皮椒：依赖回调，此处不主动查（虎皮椒无主动查询接口）

    $remaining = $order['expires'] - time();
    echo json_encode([
        'success'         => false,
        'status'          => 'pending',
        'remainingSeconds' => max(0, $remaining),
        'message'         => '正在等待支付确认...',
    ]);
}

/**
 * 标记订单已支付 + 生成激活码 + 发送邮件
 */
function markOrderPaid(&$orders, $orderId, $txHash, $order) {
    $product = getProduct($order['product']);
    $activationCode = generateLicenseKey($order['prefix']);
    $orders[$orderId]['status'] = 'paid';
    $orders[$orderId]['activationCode'] = $activationCode;
    $orders[$orderId]['paidAt'] = time();
    $orders[$orderId]['txHash'] = $txHash;

    // 发送邮件
    $emailSent = false;
    if ($product) {
        $emailSent = sendActivationEmail($order['email'], $activationCode, $orderId, $product);
    }
    $orders[$orderId]['emailSent'] = $emailSent;
    writeOrders($orders);

    echo json_encode([
        'success'       => true,
        'status'        => 'paid',
        'orderId'       => $orderId,
        'activationCode' => $activationCode,
        'email'         => $order['email'],
        'emailSent'     => $emailSent,
        'txHash'        => $txHash,
        'productName'   => $order['productName'],
        'message'       => '支付成功！激活码已生成' . ($emailSent ? '并发送到您的邮箱' : '（邮件发送失败，请妥善保存）'),
    ]);
}

// ════════════════════════════════════════════════════════════════
// xunhu-notify — 虎皮椒异步回调
// ════════════════════════════════════════════════════════════════
function handleXunhuNotify() {
    $body = $_POST;
    $r = XunhuPay::verifyNotify($body);
    if (!$r['ok']) {
        echo 'fail';
        return;
    }
    if ($r['paid']) {
        $orderId = $r['tradeOrderId'];
        $orders = readOrders();
        if (isset($orders[$orderId]) && $orders[$orderId]['status'] !== 'paid') {
            $order = $orders[$orderId];
            // 标记支付（虎皮椒回调时 txHash 留空）
            $product = getProduct($order['product']);
            $activationCode = generateLicenseKey($order['prefix']);
            $orders[$orderId]['status'] = 'paid';
            $orders[$orderId]['activationCode'] = $activationCode;
            $orders[$orderId]['paidAt'] = time();
            $orders[$orderId]['txHash'] = 'xunhu-' . ($body['transaction_id'] ?? '');
            $emailSent = $product ? sendActivationEmail($order['email'], $activationCode, $orderId, $product) : false;
            $orders[$orderId]['emailSent'] = $emailSent;
            writeOrders($orders);
        }
    }
    echo 'success';
}

// ════════════════════════════════════════════════════════════════
// verify-code — 验证激活码
// ════════════════════════════════════════════════════════════════
function handleVerifyCode() {
    $code = trim(strtoupper($_GET['code'] ?? ''));
    echo json_encode(['valid' => isValidLicenseKey($code), 'code' => $code]);
}

// ════════════════════════════════════════════════════════════════
// generate-test — 测试生成激活码（不发邮件）
// ════════════════════════════════════════════════════════════════
function handleGenerateTest() {
    $productKey = $_GET['product'] ?? 'voice-memo';
    $product = getProduct($productKey);
    $prefix = $product ? $product['prefix'] : 'CC';
    $code = generateLicenseKey($prefix);
    echo json_encode(['code' => $code, 'valid' => isValidLicenseKey($code), 'product' => $productKey]);
}

// ════════════════════════════════════════════════════════════════
// 激活码算法（与各 App 端 LicenseManager 完全一致）
// 格式：{PREFIX}-XXXX-XXXX-XXXX
// 字符集：ABCDEFGHJKLMNPQRSTUVWXYZ23456789（去除 I/O/0/1）
// 校验：前缀 + 前两段字符 ASCII 之和 mod 97 == 第三段数字（补零至4位）
// ════════════════════════════════════════════════════════════════
function generateLicenseKey($prefix) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $sum = 0;
    foreach (str_split($prefix) as $c) $sum += ord($c);

    $part1 = '';
    for ($i = 0; $i < 4; $i++) {
        $c = $chars[random_int(0, strlen($chars) - 1)];
        $part1 .= $c;
        $sum += ord($c);
    }
    $part2 = '';
    for ($i = 0; $i < 4; $i++) {
        $c = $chars[random_int(0, strlen($chars) - 1)];
        $part2 .= $c;
        $sum += ord($c);
    }
    $checksum = $sum % 97;
    return $prefix . '-' . $part1 . '-' . $part2 . '-' . str_pad($checksum, 4, '0', STR_PAD_LEFT);
}

function isValidLicenseKey($key) {
    $key = trim(strtoupper($key));
    $parts = explode('-', $key);
    if (count($parts) !== 4) return false;
    $prefix = $parts[0];
    // 前缀必须是 2 位字母（产品前缀表里的）
    $validPrefixes = array_column(PRODUCTS, 'prefix');
    if (!in_array($prefix, $validPrefixes, true)) return false;
    for ($i = 1; $i <= 3; $i++) {
        if (strlen($parts[$i]) !== 4) return false;
        if (!ctype_alnum($parts[$i])) return false;
    }
    if (!ctype_digit($parts[3])) return false;
    $sum = 0;
    foreach (str_split($parts[0] . $parts[1] . $parts[2]) as $c) $sum += ord($c);
    $expected = $sum % 97;
    $actual = intval($parts[3]);
    return $expected === $actual;
}

// ════════════════════════════════════════════════════════════════
// 订单存储（JSON 文件）
// ════════════════════════════════════════════════════════════════
function readOrders() {
    if (!file_exists(ORDERS_FILE)) return [];
    $data = json_decode(file_get_contents(ORDERS_FILE), true);
    return is_array($data) ? $data : [];
}

function writeOrders($orders) {
    // 清理 7 天前的订单（防止文件膨胀）
    $cutoff = time() - 7 * 86400;
    $orders = array_filter($orders, fn($o) => ($o['created'] ?? 0) > $cutoff);
    file_put_contents(ORDERS_FILE, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function generateOrderId($prefix) {
    return $prefix . date('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
}
