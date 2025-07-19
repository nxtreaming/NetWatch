<?php
/**
 * 邮件发送调试工具
 * 用于诊断邮件发送问题
 */

require_once 'config.php';

// 处理AJAX请求
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'test_php_mail':
            // 测试PHP内置mail函数
            $to = SMTP_TO_EMAIL;
            $subject = 'NetWatch PHP mail() 测试 - ' . date('Y-m-d H:i:s');
            $message = 'This is a test email from NetWatch using PHP mail() function.';
            $headers = 'From: ' . SMTP_FROM_EMAIL . "\r\n" .
                      'Reply-To: ' . SMTP_FROM_EMAIL . "\r\n" .
                      'X-Mailer: PHP/' . phpversion();
            
            $result = mail($to, $subject, $message, $headers);
            
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'PHP mail() 发送成功' : 'PHP mail() 发送失败',
                'details' => [
                    'to' => $to,
                    'from' => SMTP_FROM_EMAIL,
                    'php_version' => phpversion(),
                    'mail_function' => function_exists('mail') ? 'available' : 'not available'
                ]
            ]);
            break;
            
        case 'check_config':
            // 检查邮件配置
            $config = [
                'SMTP_FROM_EMAIL' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'NOT_DEFINED',
                'SMTP_FROM_NAME' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'NOT_DEFINED',
                'SMTP_TO_EMAIL' => defined('SMTP_TO_EMAIL') ? SMTP_TO_EMAIL : 'NOT_DEFINED',
                'SMTP_HOST' => defined('SMTP_HOST') ? SMTP_HOST : 'NOT_DEFINED',
                'SMTP_PORT' => defined('SMTP_PORT') ? SMTP_PORT : 'NOT_DEFINED',
                'SMTP_USERNAME' => defined('SMTP_USERNAME') ? SMTP_USERNAME : 'NOT_DEFINED',
                'SMTP_PASSWORD' => defined('SMTP_PASSWORD') ? (empty(SMTP_PASSWORD) ? 'EMPTY' : 'SET') : 'NOT_DEFINED'
            ];
            
            echo json_encode([
                'success' => true,
                'config' => $config
            ]);
            break;
            
        case 'check_server':
            // 检查服务器邮件相关配置
            $serverInfo = [
                'php_version' => phpversion(),
                'mail_function' => function_exists('mail'),
                'sendmail_path' => ini_get('sendmail_path'),
                'smtp' => ini_get('SMTP'),
                'smtp_port' => ini_get('smtp_port'),
                'sendmail_from' => ini_get('sendmail_from'),
                'auto_prepend_file' => ini_get('auto_prepend_file'),
                'auto_append_file' => ini_get('auto_append_file'),
                'log_errors' => ini_get('log_errors'),
                'error_log' => ini_get('error_log')
            ];
            
            echo json_encode([
                'success' => true,
                'server_info' => $serverInfo
            ]);
            break;
            
        case 'test_simple_mailer':
            // 测试SimpleMailer类
            try {
                require_once 'mailer_simple.php';
                $mailer = new SimpleMailer();
                
                $subject = 'NetWatch SimpleMailer 测试 - ' . date('Y-m-d H:i:s');
                $body = 'This is a test email from NetWatch using SimpleMailer class.';
                
                $result = $mailer->sendMail($subject, $body, false); // 发送纯文本邮件
                
                echo json_encode([
                    'success' => $result,
                    'message' => $result ? 'SimpleMailer 发送成功' : 'SimpleMailer 发送失败'
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'SimpleMailer 异常: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'check_logs':
            // 检查错误日志
            $logFiles = [];
            $logPaths = [
                '/var/log/mail.log',
                '/var/log/maillog',
                '/var/log/messages',
                ini_get('error_log'),
                __DIR__ . '/logs/error.log'
            ];
            
            foreach ($logPaths as $path) {
                if ($path && file_exists($path) && is_readable($path)) {
                    $logFiles[] = [
                        'path' => $path,
                        'size' => filesize($path),
                        'modified' => date('Y-m-d H:i:s', filemtime($path)),
                        'readable' => true
                    ];
                } elseif ($path) {
                    $logFiles[] = [
                        'path' => $path,
                        'readable' => false,
                        'exists' => file_exists($path)
                    ];
                }
            }
            
            echo json_encode([
                'success' => true,
                'log_files' => $logFiles
            ]);
            break;
    }
    
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch - 邮件发送调试</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .content {
            padding: 30px;
        }
        
        .test-section {
            margin: 30px 0;
            padding: 20px;
            border: 1px solid #e9ecef;
            border-radius: 10px;
        }
        
        .test-section h3 {
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: transform 0.2s ease;
            margin: 10px 10px 10px 0;
        }
        
        .btn:hover {
            transform: translateY(-1px);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .result {
            margin-top: 15px;
            padding: 15px;
            border-radius: 8px;
            font-family: monospace;
            white-space: pre-wrap;
            font-size: 12px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .result.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .result.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .result.info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .config-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 12px;
        }
        
        .config-table th,
        .config-table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .config-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 NetWatch 邮件发送调试</h1>
            <p>诊断和解决邮件发送问题</p>
        </div>
        
        <div class="content">
            <div class="test-section">
                <h3>📋 检查邮件配置</h3>
                <p>检查config.php中的邮件相关配置是否正确。</p>
                <button class="btn" onclick="checkConfig()">检查配置</button>
                <div id="config-result"></div>
            </div>
            
            <div class="test-section">
                <h3>🖥️ 检查服务器配置</h3>
                <p>检查服务器的PHP邮件相关配置。</p>
                <button class="btn" onclick="checkServer()">检查服务器</button>
                <div id="server-result"></div>
            </div>
            
            <div class="test-section">
                <h3>📧 测试PHP mail()函数</h3>
                <p>直接使用PHP内置的mail()函数发送测试邮件。</p>
                <button class="btn" onclick="testPhpMail()">测试PHP mail()</button>
                <div id="php-mail-result"></div>
            </div>
            
            <div class="test-section">
                <h3>🔧 测试SimpleMailer类</h3>
                <p>测试NetWatch的SimpleMailer类发送邮件。</p>
                <button class="btn" onclick="testSimpleMailer()">测试SimpleMailer</button>
                <div id="simple-mailer-result"></div>
            </div>
            
            <div class="test-section">
                <h3>📄 检查日志文件</h3>
                <p>检查系统中可能的邮件相关日志文件。</p>
                <button class="btn" onclick="checkLogs()">检查日志</button>
                <div id="logs-result"></div>
            </div>
            
            <a href="test_email.php" class="back-link">← 返回邮件测试</a>
            <a href="index.php" class="back-link">← 返回主页</a>
        </div>
    </div>
    
    <script>
        async function makeRequest(action, buttonText, resultId) {
            const btn = event.target;
            const resultDiv = document.getElementById(resultId);
            
            btn.disabled = true;
            btn.textContent = '检查中...';
            resultDiv.innerHTML = '';
            
            try {
                const response = await fetch(`?action=${action}`);
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.innerHTML = `<div class="result success">${formatResult(data)}</div>`;
                } else {
                    resultDiv.innerHTML = `<div class="result error">❌ ${data.message || '操作失败'}</div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="result error">❌ 请求失败: ${error.message}</div>`;
            }
            
            btn.disabled = false;
            btn.textContent = buttonText;
        }
        
        function formatResult(data) {
            let result = '';
            
            if (data.message) {
                result += `✅ ${data.message}\n\n`;
            }
            
            if (data.config) {
                result += '配置信息:\n';
                for (const [key, value] of Object.entries(data.config)) {
                    result += `${key}: ${value}\n`;
                }
            }
            
            if (data.server_info) {
                result += '服务器信息:\n';
                for (const [key, value] of Object.entries(data.server_info)) {
                    result += `${key}: ${value}\n`;
                }
            }
            
            if (data.details) {
                result += '\n详细信息:\n';
                for (const [key, value] of Object.entries(data.details)) {
                    result += `${key}: ${value}\n`;
                }
            }
            
            if (data.log_files) {
                result += '日志文件:\n';
                data.log_files.forEach(log => {
                    result += `路径: ${log.path}\n`;
                    if (log.readable) {
                        result += `  大小: ${log.size} bytes\n`;
                        result += `  修改时间: ${log.modified}\n`;
                    } else {
                        result += `  状态: ${log.exists ? '存在但不可读' : '不存在'}\n`;
                    }
                    result += '\n';
                });
            }
            
            return result;
        }
        
        function checkConfig() {
            makeRequest('check_config', '检查配置', 'config-result');
        }
        
        function checkServer() {
            makeRequest('check_server', '检查服务器', 'server-result');
        }
        
        function testPhpMail() {
            makeRequest('test_php_mail', '测试PHP mail()', 'php-mail-result');
        }
        
        function testSimpleMailer() {
            makeRequest('test_simple_mailer', '测试SimpleMailer', 'simple-mailer-result');
        }
        
        function checkLogs() {
            makeRequest('check_logs', '检查日志', 'logs-result');
        }
    </script>
</body>
</html>
