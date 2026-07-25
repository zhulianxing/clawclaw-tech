<?php
/**
 * ClawClaw.tech License System — 全局配置
 *
 * 13 款产品定价表 + 虎皮椒凭据 + USDT 钱包 + SMTP 配置
 * 虎皮椒 appid/appKey 留空时自动禁用微信/支付宝通道，仅启用 USDT。
 */

// ── 虎皮椒（微信/支付宝 WAP 收银台）──
// 凭据来源：tiantian-poker-tournament/server/payment-svc/index.js
const XUNHU_APPID    = '201906182246';
const XUNHU_APPKEY   = '5995adfd45da21ea5a70c086df023c22';
const XUNHU_API_URL  = 'https://api.xunhupay.com/payment/do.html';
const XUNHU_NOTIFY_URL = 'https://clawclaw.tech/license/license-server.php?action=xunhu-notify';
const XUNHU_RETURN_URL = '';  // 支付完成跳转地址，空则不跳
const XUNHU_WAP_URL  = 'https://clawclaw.tech';
const XUNHU_WAP_NAME = 'ClawClaw.tech';

// ── USDT TRC-20 收款 ──
const USDT_WALLET      = 'TUMhg1CqhmZda3ZnX3kRX2LVLZYLLPapZq';
const USDT_CONTRACT    = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
const TRONGRID_API     = 'https://api.trongrid.io';

// ── SMTP（腾讯企业邮箱 SSL 465 直发）──
const SMTP_HOST  = 'smtp.exmail.qq.com';
const SMTP_PORT  = 465;
const SMTP_USER  = 'sms@139.me';
const SMTP_PASS  = 'Mancy919';
const SMTP_FROM_NAME = 'ClawClaw.tech';

// ── 订单存储 ──
const ORDERS_FILE = __DIR__ . '/orders.json';
const ORDER_EXPIRE_MINUTES = 60;

// ── 产品定价表（13 款 + 密探）──
// 字段：name 产品名 / price 元 / priceUsdt USDT / prefix 激活码前缀 / apk 下载路径 / desc 简介
const PRODUCTS = [
    'vip-reception'    => ['name' => 'VIP迎宾',        'price' => 198, 'priceUsdt' => 28, 'prefix' => 'VR', 'apk' => '/apk/vip-reception.apk',       'desc' => 'VIP客户到店自动识别，店员第一时间迎接'],
    'missing-person'   => ['name' => '走失守护',        'price' => 99,  'priceUsdt' => 14, 'prefix' => 'MP', 'apk' => '/apk/missing-person.apk',       'desc' => '走失老人/儿童智能搜寻，社区摄像头值守'],
    'shop-security'    => ['name' => '商铺防损',        'price' => 128, 'priceUsdt' => 18, 'prefix' => 'SS', 'apk' => '/apk/shop-security.apk',        'desc' => '黑名单人员到店即时预警，商铺防盗防损'],
    'attendance-clock' => ['name' => '刷脸考勤',        'price' => 298, 'priceUsdt' => 42, 'prefix' => 'AC', 'apk' => '/apk/attendance-clock.apk',     'desc' => '离线刷脸考勤，无需联网，打卡记录自动统计'],
    'visitor-count'    => ['name' => '客流统计',        'price' => 198, 'priceUsdt' => 28, 'prefix' => 'VC', 'apk' => '/apk/visitor-count.apk',       'desc' => '展会门店实时客流计数，智能去重与时段分析'],
    'golf-ranger'      => ['name' => '高尔夫测距',      'price' => 168, 'priceUsdt' => 24, 'prefix' => 'GR', 'apk' => '/apk/golf-ranger.apk',         'desc' => '拍照自动识别旗杆，秒知距离，内置选杆建议'],
    'fall-detect'      => ['name' => '跌倒守护',        'price' => 268, 'priceUsdt' => 38, 'prefix' => 'FD', 'apk' => '/apk/fall-detect.apk',         'desc' => 'AI跌倒检测，自动短信告警，老人安全监护'],
    'elder-care'       => ['name' => '安巢 AnNest',     'price' => 268, 'priceUsdt' => 38, 'prefix' => 'AN', 'apk' => '/apk/elderguard.apk',          'desc' => '跌倒检测+语音求救+静止告警，三重守护老人'],
    'pedestrian-capture' => ['name' => '单点抓拍',      'price' => 128, 'priceUsdt' => 18, 'prefix' => 'PC', 'apk' => '/apk/pedestrian-capture.apk',  'desc' => '单点行人抓拍识别，社区安防'],
    'dog-home'         => ['name' => '狗狗在家 Pro',    'price' => 68,  'priceUsdt' => 10, 'prefix' => 'DH', 'apk' => '/apk/dog-home.apk',            'desc' => 'AI狂吠识别+焦躁感知+自动安抚，24h守护分离焦虑'],
    'phone-nas'        => ['name' => '旧机NAS',         'price' => 35,  'priceUsdt' => 5,  'prefix' => 'PN', 'apk' => '/apk/phone-nas.apk',           'desc' => '旧手机变NAS服务器，Web文件管理+WebDAV挂载'],
    'smart-bridge'     => ['name' => 'Smart Bridge Pro','price' => 499, 'priceUsdt' => 70, 'prefix' => 'SB', 'apk' => '/apk/smart-bridge.apk',        'desc' => '6节点SSH桥接 · Android/macOS/Windows 全平台'],
    'plate-recognizer' => ['name' => '危险车辆预警',    'price' => 35,  'priceUsdt' => 5,  'prefix' => 'PR', 'apk' => '/apk/plate-recognizer.apk',    'desc' => 'Android车牌OCR识别，指定车牌实时搜索告警'],
    'voice-memo'       => ['name' => '密探 MiBao',      'price' => 99,  'priceUsdt' => 14, 'prefix' => 'VM', 'apk' => '/apk/voice-memo.apk',          'desc' => '远程录音转文字 · 邮件回传 · Whisper 本地转录'],
];

/**
 * 根据产品 key 获取配置
 */
function getProduct($key) {
    return PRODUCTS[$key] ?? null;
}

/**
 * 是否启用虎皮椒通道
 */
function xunhuEnabled() {
    return XUNHU_APPID !== '' && XUNHU_APPKEY !== '';
}
