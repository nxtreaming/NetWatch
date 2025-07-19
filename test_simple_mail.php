<?php
/**
 * 简单的邮件测试脚本
 * 用于直接测试SimpleMailer类
 */

require_once 'config.php';
require_once 'logger.php';
require_once 'mailer_simple.php';

echo "<h2>SimpleMailer 测试</h2>\n";

try {
    echo "<p>初始化SimpleMailer...</p>\n";
    $mailer = new SimpleMailer();
    echo "<p>✅ SimpleMailer初始化成功</p>\n";
    
    echo "<p>准备发送测试邮件...</p>\n";
    
    $subject = 'NetWatch 简单测试 - ' . date('Y-m-d H:i:s');
    $body = '<h2>测试邮件</h2><p>这是一封简单的测试邮件。</p><p>发送时间: ' . date('Y-m-d H:i:s') . '</p>';
    
    echo "<p>邮件主题: " . htmlspecialchars($subject) . "</p>\n";
    echo "<p>收件人: " . SMTP_TO_EMAIL . "</p>\n";
    echo "<p>发件人: " . SMTP_FROM_EMAIL . "</p>\n";
    
    $result = $mailer->sendMail($subject, $body, true);
    
    if ($result) {
        echo "<p style='color: green;'>✅ 邮件发送成功！</p>\n";
    } else {
        echo "<p style='color: red;'>❌ 邮件发送失败</p>\n";
        
        // 检查日志文件
        $logPath = LOG_PATH . 'netwatch_' . date('Y-m-d') . '.log';
        echo "<p>检查日志文件: $logPath</p>\n";
        
        if (file_exists($logPath)) {
            echo "<p>日志文件存在，最近的日志内容:</p>\n";
            $logContent = file_get_contents($logPath);
            $lines = explode("\n", $logContent);
            $recentLines = array_slice($lines, -10);
            echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
            echo htmlspecialchars(implode("\n", $recentLines));
            echo "</pre>\n";
        } else {
            echo "<p>日志文件不存在</p>\n";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ 异常: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>\n";
}

echo "<hr>\n";
echo "<h3>配置检查</h3>\n";
echo "<p>SMTP_TO_EMAIL: " . (defined('SMTP_TO_EMAIL') ? SMTP_TO_EMAIL : 'NOT_DEFINED') . "</p>\n";
echo "<p>SMTP_FROM_EMAIL: " . (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'NOT_DEFINED') . "</p>\n";
echo "<p>SMTP_FROM_NAME: " . (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'NOT_DEFINED') . "</p>\n";
echo "<p>LOG_PATH: " . (defined('LOG_PATH') ? LOG_PATH : 'NOT_DEFINED') . "</p>\n";
echo "<p>LOG_LEVEL: " . (defined('LOG_LEVEL') ? LOG_LEVEL : 'NOT_DEFINED') . "</p>\n";

echo "<hr>\n";
echo "<h3>PHP mail() 函数测试</h3>\n";

if (function_exists('mail')) {
    echo "<p>✅ mail() 函数可用</p>\n";
    
    $to = SMTP_TO_EMAIL;
    $subject = 'NetWatch PHP mail() 测试 - ' . date('Y-m-d H:i:s');
    $message = 'This is a direct PHP mail() test.';
    $headers = 'From: ' . SMTP_FROM_EMAIL . "\r\n" .
              'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
              'X-Mailer: PHP/' . phpversion();
    
    echo "<p>尝试使用PHP mail()函数发送邮件...</p>\n";
    $result = mail($to, $subject, $message, $headers);
    
    if ($result) {
        echo "<p style='color: green;'>✅ PHP mail() 发送成功</p>\n";
    } else {
        echo "<p style='color: red;'>❌ PHP mail() 发送失败</p>\n";
        
        // 获取最后的错误
        $lastError = error_get_last();
        if ($lastError) {
            echo "<p>最后的PHP错误:</p>\n";
            echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
            echo htmlspecialchars(print_r($lastError, true));
            echo "</pre>\n";
        }
    }
} else {
    echo "<p style='color: red;'>❌ mail() 函数不可用</p>\n";
}

echo "<hr>\n";
echo "<p><a href='test_email.php'>返回邮件测试页面</a></p>\n";
echo "<p><a href='index.php'>返回主页</a></p>\n";
?>
