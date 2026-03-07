<?php
/**
 * 邮件发送调试工具
 * 用于诊断邮件发送问题
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../includes/Config.php';
require_once '../logger.php';

// 检查登录状态
Auth::requireLogin();

echo "<h2>📧 邮件发送调试工具</h2>\n";

// 检查PHP mail函数
echo "<h3>1. PHP Mail函数检查</h3>\n";
if (function_exists('mail')) {
    echo "<p style='color: green;'>✅ PHP mail()函数可用</p>\n";
    
    // 测试基本mail函数
    if (isset($_GET['test_mail'])) {
        echo "<p>测试PHP mail()函数...</p>\n";
        $to = (string) config('mail.to', 'test@example.com');
        $subject = 'PHP mail()函数测试 - ' . date('Y-m-d H:i:s');
        $message = '这是通过PHP内置mail()函数发送的测试邮件。\n\n发送时间: ' . date('Y-m-d H:i:s');
        $headers = 'From: ' . (string) config('mail.from', 'test@example.com');
        
        $result = mail($to, $subject, $message, $headers);
        
        if ($result) {
            echo "<p style='color: green;'>✅ mail()函数返回true</p>\n";
        } else {
            echo "<p style='color: red;'>❌ mail()函数返回false</p>\n";
        }
    } else {
        echo "<p><a href='?test_mail=1'>点击测试PHP mail()函数</a></p>\n";
    }
} else {
    echo "<p style='color: red;'>❌ PHP mail()函数不可用</p>\n";
}

echo "<hr>\n";

// 检查SMTP配置
echo "<h3>2. SMTP配置检查</h3>\n";
$config = [
    'SMTP_HOST' => (string) config('mail.host', 'NOT_DEFINED'),
    'SMTP_PORT' => (string) config('mail.port', 'NOT_DEFINED'),
    'SMTP_USERNAME' => (string) config('mail.username', 'NOT_DEFINED'),
    'SMTP_PASSWORD' => config('mail.password_env', '') !== '' || config('mail.password_file', '') !== '' || config('mail.password', '') !== '' ? 'SET' : 'NOT_DEFINED',
    'SMTP_FROM_EMAIL' => (string) config('mail.from', 'NOT_DEFINED'),
    'SMTP_FROM_NAME' => (string) config('mail.from_name', 'NOT_DEFINED'),
    'SMTP_TO_EMAIL' => (string) config('mail.to', 'NOT_DEFINED')
];

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
foreach ($config as $key => $value) {
    $color = ($value === 'NOT_DEFINED' || $value === 'EMPTY') ? 'red' : 'green';
    echo "<tr><td><strong>$key</strong></td><td style='color: $color;'>$value</td></tr>\n";
}
echo "</table>\n";

echo "<hr>\n";

// 检查服务器邮件配置
echo "<h3>3. 服务器邮件配置</h3>\n";
$mailConfig = [
    'sendmail_path' => ini_get('sendmail_path'),
    'SMTP' => ini_get('SMTP'),
    'smtp_port' => ini_get('smtp_port'),
    'sendmail_from' => ini_get('sendmail_from')
];

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
foreach ($mailConfig as $key => $value) {
    $displayValue = empty($value) ? '(空)' : $value;
    echo "<tr><td><strong>$key</strong></td><td>$displayValue</td></tr>\n";
}
echo "</table>\n";

echo "<hr>\n";

// 检查PHPMailer
echo "<h3>4. PHPMailer检查</h3>\n";
if (file_exists('vendor/autoload.php')) {
    echo "<p style='color: green;'>✅ vendor/autoload.php存在</p>\n";
    
    try {
        require_once 'vendor/autoload.php';
        
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            echo "<p style='color: green;'>✅ PHPMailer类可用</p>\n";
            
            // 测试PHPMailer
            if (isset($_GET['test_phpmailer'])) {
                echo "<p>测试PHPMailer...</p>\n";
                
                use PHPMailer\PHPMailer\PHPMailer;
                use PHPMailer\PHPMailer\SMTP;
                use PHPMailer\PHPMailer\Exception;
                
                $mail = new PHPMailer(true);
                
                try {
                    $smtpHost = (string) config('mail.host', '');
                    $smtpUsername = (string) config('mail.username', '');
                    $smtpPort = (int) config('mail.port', 587);
                    $smtpFrom = (string) config('mail.from', '');
                    $smtpFromName = (string) config('mail.from_name', 'NetWatch');
                    $smtpTo = (string) config('mail.to', '');
                    $smtpPassword = (string) config('mail.password', '');

                    // 服务器设置
                    $mail->isSMTP();
                    $mail->Host = $smtpHost;
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtpUsername;
                    $mail->Password = $smtpPassword;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = $smtpPort;
                    $mail->CharSet = 'UTF-8';
                    
                    // 发件人
                    $mail->setFrom($smtpFrom, $smtpFromName);
                    
                    // 收件人
                    $mail->addAddress($smtpTo);
                    
                    // 内容
                    $mail->isHTML(true);
                    $mail->Subject = 'PHPMailer调试测试 - ' . date('Y-m-d H:i:s');
                    $mail->Body = '<h2>PHPMailer测试邮件</h2>
<p>这是通过PHPMailer发送的调试测试邮件。</p>
<p><strong>发送时间:</strong> ' . date('Y-m-d H:i:s') . '</p>
<p>如果您收到这封邮件，说明PHPMailer配置正确。</p>';
                    
                    $mail->send();
                    echo "<p style='color: green;'>✅ PHPMailer邮件发送成功</p>\n";
                    
                } catch (Exception $e) {
                    echo "<p style='color: red;'>❌ PHPMailer邮件发送失败: " . htmlspecialchars($mail->ErrorInfo) . "</p>\n";
                }
            } else {
                echo "<p><a href='?test_phpmailer=1'>点击测试PHPMailer</a></p>\n";
            }
            
        } else {
            echo "<p style='color: red;'>❌ PHPMailer类不可用</p>\n";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ 加载PHPMailer时出错: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
} else {
    echo "<p style='color: red;'>❌ vendor/autoload.php不存在，PHPMailer未安装</p>\n";
}

echo "<hr>\n";

// 测试SimpleMailer
echo "<h3>5. SimpleMailer测试</h3>\n";
if (file_exists('mailer_simple.php')) {
    echo "<p style='color: green;'>✅ mailer_simple.php存在</p>\n";
    
    if (isset($_GET['test_simple'])) {
        echo "<p>测试SimpleMailer...</p>\n";
        
        try {
            require_once 'mailer_simple.php';
            $simpleMailer = new SimpleMailer();
            
            $subject = 'SimpleMailer调试测试 - ' . date('Y-m-d H:i:s');
            $body = '<h2>SimpleMailer测试邮件</h2>
<p>这是通过SimpleMailer发送的调试测试邮件。</p>
<p><strong>发送时间:</strong> ' . date('Y-m-d H:i:s') . '</p>
<p>SimpleMailer使用PHP内置的mail()函数发送邮件。</p>';
            
            $result = $simpleMailer->sendMail($subject, $body, true);
            
            if ($result) {
                echo "<p style='color: green;'>✅ SimpleMailer邮件发送成功</p>\n";
            } else {
                echo "<p style='color: red;'>❌ SimpleMailer邮件发送失败</p>\n";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ SimpleMailer测试异常: " . htmlspecialchars($e->getMessage()) . "</p>\n";
        }
    } else {
        echo "<p><a href='?test_simple=1'>点击测试SimpleMailer</a></p>\n";
    }
} else {
    echo "<p style='color: red;'>❌ mailer_simple.php不存在</p>\n";
}

echo "<hr>\n";

// 检查日志
echo "<h3>6. 日志检查</h3>\n";
$logPaths = [
    LOG_PATH . 'netwatch_' . date('Y-m-d') . '.log',
    './logs/netwatch_' . date('Y-m-d') . '.log',
    'logs/netwatch_' . date('Y-m-d') . '.log'
];

$foundLog = false;
foreach ($logPaths as $logPath) {
    if (file_exists($logPath)) {
        echo "<p style='color: green;'>✅ 找到日志文件: $logPath</p>\n";
        
        $logContent = file_get_contents($logPath);
        if (!empty($logContent)) {
            $lines = explode("\n", $logContent);
            $recentLines = array_slice($lines, -20); // 最后20行
            
            echo "<h4>最近的日志记录:</h4>\n";
            echo "<div style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 300px; overflow-y: auto;'>\n";
            echo "<pre>" . htmlspecialchars(implode("\n", $recentLines)) . "</pre>\n";
            echo "</div>\n";
        } else {
            echo "<p>日志文件为空</p>\n";
        }
        
        $foundLog = true;
        break;
    }
}

if (!$foundLog) {
    echo "<p style='color: orange;'>⚠️ 未找到日志文件</p>\n";
    echo "<p>尝试的路径:</p>\n";
    echo "<ul>\n";
    foreach ($logPaths as $path) {
        echo "<li>" . htmlspecialchars($path) . "</li>\n";
    }
    echo "</ul>\n";
}

echo "<hr>\n";

// 网络连接测试
echo "<h3>7. 网络连接测试</h3>\n";
if (config('mail.host', '') !== '' && config('mail.port', '') !== '') {
    $smtpHost = (string) config('mail.host', '');
    $smtpPort = (int) config('mail.port', 587);
    echo "<p>测试到 " . $smtpHost . ":" . $smtpPort . " 的连接...</p>\n";
    
    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 10);
    if ($socket) {
        echo "<p style='color: green;'>✅ 成功连接到SMTP服务器</p>\n";
        
        $response = fgets($socket, 512);
        echo "<p>服务器响应: " . htmlspecialchars(trim($response)) . "</p>\n";
        fclose($socket);
    } else {
        echo "<p style='color: red;'>❌ 无法连接到SMTP服务器</p>\n";
        echo "<p>错误: $errstr ($errno)</p>\n";
        echo "<p>可能的原因:</p>\n";
        echo "<ul>\n";
        echo "<li>防火墙阻止了连接</li>\n";
        echo "<li>SMTP服务器地址或端口错误</li>\n";
        echo "<li>网络连接问题</li>\n";
        echo "</ul>\n";
    }
} else {
    echo "<p style='color: orange;'>⚠️ SMTP_HOST或SMTP_PORT未配置，跳过网络测试</p>\n";
}

echo "<hr>\n";
echo "<p><a href='test_email.php'>邮件测试页面</a> | <a href='index.php'>返回主页</a></p>\n";
?>
