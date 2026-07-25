<?php
/**
 * ClawClaw.tech SMTP SSL 465 直发 + clawclaw.tech 6 区块 HTML 模板
 *
 * 从 mibao-license.php 抽离的通用邮件发送模块，支持任意产品。
 */

require_once __DIR__ . '/config.php';

/**
 * 发送激活码邮件
 */
function sendActivationEmail($toEmail, $activationCode, $orderId, $product) {
    $subject = $product['name'] . ' - 激活码：' . $activationCode;
    $html = buildEmailHtml($activationCode, $orderId, $product);
    $plainText = "感谢购买 {$product['name']}！\n\n"
               . "激活码：$activationCode\n"
               . "订单号：$orderId\n\n"
               . "激活方法：打开 App → 设置 → 我已有激活码 → 输入激活码\n\n"
               . "—— {$product['name']} · ClawClaw.tech";
    return smtpSend(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_USER, SMTP_FROM_NAME, $toEmail, $subject, $html, $plainText);
}

/**
 * SMTP SSL 发送（stream_socket_client + AUTH LOGIN）
 */
function smtpSend($host, $port, $user, $pass, $from, $fromName, $to, $subject, $htmlBody, $plainText = '') {
    $socket = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$socket) return false;

    if (!smtpRead($socket)) { fclose($socket); return false; }
    if (!smtpWrite($socket, "EHLO clawclaw.tech\r\n")) { fclose($socket); return false; }
    if (!smtpRead($socket)) { fclose($socket); return false; }
    if (!smtpWrite($socket, "AUTH LOGIN\r\n")) { fclose($socket); return false; }
    if (!smtpRead($socket, 334)) { fclose($socket); return false; }
    if (!smtpWrite($socket, base64_encode($user) . "\r\n")) { fclose($socket); return false; }
    if (!smtpRead($socket, 334)) { fclose($socket); return false; }
    if (!smtpWrite($socket, base64_encode($pass) . "\r\n")) { fclose($socket); return false; }
    if (!smtpRead($socket, 235)) { fclose($socket); return false; }
    if (!smtpWrite($socket, "MAIL FROM:<$from>\r\n")) { fclose($socket); return false; }
    if (!smtpRead($socket, 250)) { fclose($socket); return false; }
    if (!smtpWrite($socket, "RCPT TO:<$to>\r\n")) { fclose($socket); return false; }
    if (!smtpRead($socket, 250)) { fclose($socket); return false; }
    if (!smtpWrite($socket, "DATA\r\n")) { fclose($socket); return false; }
    if (!smtpRead($socket, 354)) { fclose($socket); return false; }

    $boundary = '----=_Part_' . md5(uniqid());
    $headers = [
        "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>",
        "To: <$to>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        "Date: " . date(DATE_RFC2822),
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    if ($plainText === '') $plainText = "感谢使用 ClawClaw.tech 服务。";
    $message .= $plainText . "\r\n\r\n";
    $message .= "--$boundary\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";
    $message .= "--$boundary--\r\n";

    if (!smtpWrite($socket, $message . ".\r\n")) { fclose($socket); return false; }
    if (!smtpRead($socket, 250)) { fclose($socket); return false; }

    smtpWrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
}

function smtpWrite($socket, $data) {
    return fwrite($socket, $data) !== false;
}

function smtpRead($socket, $expectedCode = null) {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 512);
        if ($line === false) {
            if ($response === '') return false;
            break;
        }
        $response .= $line;
        // SMTP 多行响应：最后一行第4位是空格（如 "250 OK"），中间行第4位是 "-"
        if (isset($line[3]) && $line[3] === ' ') break;
        if (rtrim($line) === '' && $response !== '') break;
    }
    if ($expectedCode !== null) {
        $lines = explode("\r\n", trim($response));
        $lastLine = end($lines);
        $code = intval(substr($lastLine, 0, 3));
        return $code === $expectedCode;
    }
    return $response !== '';
}

/**
 * clawclaw.tech HTML 邮件模板（6 区块结构）
 */
function buildEmailHtml($activationCode, $orderId, $product) {
    $time = date('Y-m-d H:i:s');
    $year = date('Y');
    $name = htmlspecialchars($product['name']);
    $desc = htmlspecialchars($product['desc'] ?? '');
    $code = htmlspecialchars($activationCode);
    $oid = htmlspecialchars($orderId);
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0A0A0B;font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei',sans-serif;-webkit-font-smoothing:antialiased;">
<div style="max-width:560px;margin:0 auto;padding:24px;">
<div style="background:#16161A;border:1px solid #27272A;border-radius:20px;overflow:hidden;position:relative;">
<div style="height:2px;background:linear-gradient(90deg,#6366F1,#22D3EE);"></div>
<div style="padding:32px 28px;">
<div style="display:inline-block;padding:6px 16px;border-radius:100px;background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.3);color:#22D3EE;font-size:12px;font-weight:600;margin-bottom:16px;">$name · 激活码</div>
<h1 style="font-size:24px;font-weight:800;letter-spacing:-1px;margin:0 0 8px 0;background:linear-gradient(135deg,#6366F1,#22D3EE);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">激活成功</h1>
<p style="color:#71717A;font-size:13px;margin:0 0 24px 0;">$desc</p>
<div style="margin-bottom:24px;">
<div style="margin:10px 0;padding:14px 18px;background:#1C1C21;border-radius:8px;border-left:3px solid #22D3EE;text-align:center;">
<span style="color:#71717A;font-size:12px;display:block;margin-bottom:6px;">您的激活码</span>
<span style="color:#22D3EE;font-size:22px;font-weight:700;font-family:monospace;letter-spacing:2px;">$code</span>
</div>
</div>
<div style="background:#1C1C21;border-radius:8px;padding:16px 18px;margin-bottom:24px;">
<div style="color:#71717A;font-size:12px;margin-bottom:8px;">订单信息</div>
<div style="color:#E4E4E7;font-size:13px;line-height:1.8;">
<div>订单号：<span style="font-family:monospace;color:#22D3EE;">$oid</span></div>
<div>购买时间：<span>$time</span></div>
</div>
</div>
<div style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);border-radius:8px;padding:16px 18px;margin-bottom:24px;">
<div style="color:#A5B4FC;font-size:13px;font-weight:600;margin-bottom:8px;">激活方法</div>
<ol style="color:#E4E4E7;font-size:13px;line-height:1.8;padding-left:18px;margin:0;">
<li>下载并打开 $name App</li>
<li>进入「设置」页</li>
<li>点击「我已有激活码」</li>
<li>输入上方激活码完成激活</li>
</ol>
</div>
<div style="text-align:center;padding-top:16px;border-top:1px solid #27272A;">
<div style="color:#E4E4E7;font-size:14px;font-weight:600;margin-bottom:4px;">ClawClaw.tech</div>
<div style="color:#71717A;font-size:11px;">端侧视觉智能平台 · 13 款产品</div>
<div style="color:#52525B;font-size:10px;margin-top:8px;">© $year ClawClaw.tech · All rights reserved</div>
</div>
</div>
</div>
</div>
</body>
</html>
HTML;
}
