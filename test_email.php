<?php
/**
 * 邮件发送功能测试工具
 * 用于测试NetWatch系统的邮件发送功能
 */

require_once 'config.php';
require_once 'database.php';
require_once 'monitor.php';

// 检查是否有PHPMailer
$hasPhpMailer = file_exists('vendor/autoload.php');

if ($hasPhpMailer) {
    require_once 'mailer.php';
    $mailer = new Mailer();
    $mailerType = 'PHPMailer';
} else {
    require_once 'mailer_simple.php';
    $mailer = new SimpleMailer();
    $mailerType = 'SimpleMailer (PHP内置mail函数)';
}

// 处理AJAX请求（在任何HTML输出之前）
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['action']) {
            case 'test_basic':
                $subject = 'NetWatch 测试邮件 - ' . date('Y-m-d H:i:s');
                
                // 使用简单的HTML格式，避免格式问题
                $body = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>NetWatch 测试邮件</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background-color: #4CAF50; color: white; padding: 15px; border-radius: 5px; }
        .content { margin: 20px 0; line-height: 1.6; }
        .footer { margin-top: 30px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h2>✅ NetWatch 测试邮件</h2>
        <p>这是一封测试邮件，用于验证邮件发送功能</p>
    </div>
    
    <div class="content">
        <p><strong>发送时间:</strong> ' . date('Y-m-d H:i:s') . '</p>
        <p><strong>邮件发送器:</strong> ' . htmlspecialchars($mailerType) . '</p>
        <p><strong>系统状态:</strong> 正常运行</p>
        
        <p>如果您收到这封邮件，说明NetWatch系统的邮件发送功能工作正常。</p>
    </div>
    
    <div class="footer">
        <p>此邮件由 NetWatch 监控系统自动发送</p>
    </div>
</body>
</html>';
                
                // 添加更详细的错误处理
                try {
                    $result = $mailer->sendMail($subject, $body, true);
                    
                    if ($result) {
                        echo json_encode([
                            'success' => true,
                            'message' => "✅ 测试邮件发送成功！\n\n发送到: " . SMTP_TO_EMAIL . "\n发送器: " . $mailerType . "\n发送时间: " . date('Y-m-d H:i:s')
                        ]);
                    } else {
                        // 检查日志文件获取更详细的错误信息
                        $logPath = __DIR__ . '/logs/error.log';
                        $errorDetails = '';
                        if (file_exists($logPath)) {
                            $logContent = file_get_contents($logPath);
                            $lines = explode("\n", $logContent);
                            $recentLines = array_slice($lines, -10); // 获取最后10行
                            $errorDetails = "\n\n最近的日志信息:\n" . implode("\n", $recentLines);
                        }
                        
                        echo json_encode([
                            'success' => false,
                            'error' => '邮件发送失败。SimpleMailer.sendMail() 返回 false。' . $errorDetails
                        ]);
                    }
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'SimpleMailer 异常: ' . $e->getMessage() . "\n\n堆栈跟踪:\n" . $e->getTraceAsString()
                    ]);
                }
                break;
                
            case 'test_failure':
                // 创建模拟的失败代理数据
                $mockFailedProxies = [
                    [
                        'id' => 1,
                        'ip' => '192.168.1.100',
                        'port' => 1080,
                        'type' => 'socks5',
                        'failure_count' => 5,
                        'last_check' => date('Y-m-d H:i:s'),
                        'response_time' => 0
                    ],
                    [
                        'id' => 2,
                        'ip' => '10.0.0.50',
                        'port' => 8080,
                        'type' => 'http',
                        'failure_count' => 3,
                        'last_check' => date('Y-m-d H:i:s'),
                        'response_time' => 0
                    ]
                ];
                
                $result = $mailer->sendProxyAlert($mockFailedProxies);
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message' => "故障通知邮件已发送到: " . SMTP_TO_EMAIL . "\n模拟了 " . count($mockFailedProxies) . " 个失败代理"
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => '故障通知邮件发送失败，请检查邮件配置'
                    ]);
                }
                break;
                
            case 'test_status':
                // 获取真实的系统统计数据
                $monitor = new NetworkMonitor();
                $stats = $monitor->getStats();
                
                $result = $mailer->sendStatusReport($stats);
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message' => "状态报告邮件已发送到: " . SMTP_TO_EMAIL . "\n包含当前系统统计数据"
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => '状态报告邮件发送失败，请检查邮件配置'
                    ]);
                }
                break;
                
            case 'check_failed':
                $monitor = new NetworkMonitor();
                $failedProxies = $monitor->getFailedProxies();
                $alertProxies = [];
                
                // 筛选出达到阈值的代理
                foreach ($failedProxies as $proxy) {
                    if ($proxy['failure_count'] >= ALERT_THRESHOLD) {
                        $alertProxies[] = $proxy;
                    }
                }
                
                $emailSent = false;
                if (!empty($alertProxies)) {
                    $emailSent = $mailer->sendProxyAlert($alertProxies);
                }
                
                echo json_encode([
                    'success' => true,
                    'failed_count' => count($failedProxies),
                    'alert_count' => count($alertProxies),
                    'email_sent' => $emailSent
                ]);
                break;
                
            default:
                echo json_encode([
                    'success' => false,
                    'error' => '未知的操作'
                ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch - 邮件功能测试</title>
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
            max-width: 800px;
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
        
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .info-box h3 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .info-box p {
            color: #666;
            line-height: 1.6;
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
        }
        
        .config-table th,
        .config-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .config-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .status-ok { color: #28a745; }
        .status-warning { color: #ffc107; }
        .status-error { color: #dc3545; }
        
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
            <h1>📧 NetWatch 邮件功能测试</h1>
            <p>测试和诊断邮件发送功能</p>
        </div>
        
        <div class="content">
            <!-- 系统信息 -->
            <div class="info-box">
                <h3>📋 系统信息</h3>
                <table class="config-table">
                    <tr>
                        <th>邮件发送器</th>
                        <td><?php echo $mailerType; ?></td>
                    </tr>
                    <tr>
                        <th>PHP版本</th>
                        <td><?php echo PHP_VERSION; ?></td>
                    </tr>
                    <tr>
                        <th>mail()函数</th>
                        <td class="<?php echo function_exists('mail') ? 'status-ok' : 'status-error'; ?>">
                            <?php echo function_exists('mail') ? '✅ 可用' : '❌ 不可用'; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>当前时间</th>
                        <td><?php echo date('Y-m-d H:i:s'); ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- 邮件配置检查 -->
            <div class="info-box">
                <h3>⚙️ 邮件配置检查</h3>
                <table class="config-table">
                    <tr>
                        <th>发件人邮箱</th>
                        <td><?php echo defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '❌ 未配置'; ?></td>
                    </tr>
                    <tr>
                        <th>发件人名称</th>
                        <td><?php echo defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '❌ 未配置'; ?></td>
                    </tr>
                    <tr>
                        <th>收件人邮箱</th>
                        <td><?php echo defined('SMTP_TO_EMAIL') ? SMTP_TO_EMAIL : '❌ 未配置'; ?></td>
                    </tr>
                    <?php if ($hasPhpMailer): ?>
                    <tr>
                        <th>SMTP服务器</th>
                        <td><?php echo defined('SMTP_HOST') ? SMTP_HOST : '❌ 未配置'; ?></td>
                    </tr>
                    <tr>
                        <th>SMTP端口</th>
                        <td><?php echo defined('SMTP_PORT') ? SMTP_PORT : '❌ 未配置'; ?></td>
                    </tr>
                    <tr>
                        <th>SMTP用户名</th>
                        <td><?php echo defined('SMTP_USERNAME') ? SMTP_USERNAME : '❌ 未配置'; ?></td>
                    </tr>
                    <tr>
                        <th>SMTP密码</th>
                        <td><?php echo defined('SMTP_PASSWORD') && !empty(SMTP_PASSWORD) ? '✅ 已配置' : '❌ 未配置'; ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <!-- 测试功能 -->
            <div class="test-section">
                <h3>🧪 测试邮件发送</h3>
                <p>发送一封测试邮件，验证邮件发送功能是否正常工作。</p>
                <button class="btn" onclick="testBasicEmail()">发送测试邮件</button>
                <div id="test-basic-result"></div>
            </div>
            
            <div class="test-section">
                <h3>🚨 测试故障通知</h3>
                <p>模拟代理故障，发送故障通知邮件。</p>
                <button class="btn" onclick="testFailureAlert()">发送故障通知</button>
                <div id="test-failure-result"></div>
            </div>
            
            <div class="test-section">
                <h3>📊 测试状态报告</h3>
                <p>发送系统状态报告邮件。</p>
                <button class="btn" onclick="testStatusReport()">发送状态报告</button>
                <div id="test-status-result"></div>
            </div>
            
            <div class="test-section">
                <h3>🔍 检查失败代理</h3>
                <p>检查当前系统中的失败代理，如果有达到阈值的失败代理会自动发送邮件。</p>
                <button class="btn" onclick="checkFailedProxies()">检查失败代理</button>
                <div id="check-failed-result"></div>
            </div>
            
            <a href="index.php" class="back-link">← 返回主页</a>
        </div>
    </div>
    
    <script>
        async function testBasicEmail() {
            const btn = event.target;
            const resultDiv = document.getElementById('test-basic-result');
            
            btn.disabled = true;
            btn.textContent = '发送中...';
            resultDiv.innerHTML = '';
            
            try {
                const response = await fetch('?action=test_basic');
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.innerHTML = `<div class="result success">✅ 测试邮件发送成功！\n\n详情：\n${data.message}</div>`;
                } else {
                    resultDiv.innerHTML = `<div class="result error">❌ 测试邮件发送失败\n\n错误：\n${data.error}</div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="result error">❌ 请求失败\n\n错误：\n${error.message}</div>`;
            }
            
            btn.disabled = false;
            btn.textContent = '发送测试邮件';
        }
        
        async function testFailureAlert() {
            const btn = event.target;
            const resultDiv = document.getElementById('test-failure-result');
            
            btn.disabled = true;
            btn.textContent = '发送中...';
            resultDiv.innerHTML = '';
            
            try {
                const response = await fetch('?action=test_failure');
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.innerHTML = `<div class="result success">✅ 故障通知邮件发送成功！\n\n详情：\n${data.message}</div>`;
                } else {
                    resultDiv.innerHTML = `<div class="result error">❌ 故障通知邮件发送失败\n\n错误：\n${data.error}</div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="result error">❌ 请求失败\n\n错误：\n${error.message}</div>`;
            }
            
            btn.disabled = false;
            btn.textContent = '发送故障通知';
        }
        
        async function testStatusReport() {
            const btn = event.target;
            const resultDiv = document.getElementById('test-status-result');
            
            btn.disabled = true;
            btn.textContent = '发送中...';
            resultDiv.innerHTML = '';
            
            try {
                const response = await fetch('?action=test_status');
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.innerHTML = `<div class="result success">✅ 状态报告邮件发送成功！\n\n详情：\n${data.message}</div>`;
                } else {
                    resultDiv.innerHTML = `<div class="result error">❌ 状态报告邮件发送失败\n\n错误：\n${data.error}</div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="result error">❌ 请求失败\n\n错误：\n${error.message}</div>`;
            }
            
            btn.disabled = false;
            btn.textContent = '发送状态报告';
        }
        
        async function checkFailedProxies() {
            const btn = event.target;
            const resultDiv = document.getElementById('check-failed-result');
            
            btn.disabled = true;
            btn.textContent = '检查中...';
            resultDiv.innerHTML = '';
            
            try {
                const response = await fetch('?action=check_failed');
                const data = await response.json();
                
                if (data.success) {
                    let message = `检查完成！\n\n失败代理数量：${data.failed_count}\n达到阈值的代理：${data.alert_count}`;
                    if (data.email_sent) {
                        message += `\n邮件通知：✅ 已发送`;
                    } else {
                        message += `\n邮件通知：ℹ️ 无需发送（没有达到阈值的失败代理）`;
                    }
                    
                    resultDiv.innerHTML = `<div class="result info">${message}</div>`;
                } else {
                    resultDiv.innerHTML = `<div class="result error">❌ 检查失败\n\n错误：\n${data.error}</div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="result error">❌ 请求失败\n\n错误：\n${error.message}</div>`;
            }
            
            btn.disabled = false;
            btn.textContent = '检查失败代理';
        }
    </script>
</body>
</html>
