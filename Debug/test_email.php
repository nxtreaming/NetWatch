<?php
/**
 * 邮件发送功能测试工具
 */

require_once 'config.php';
require_once 'database.php';
require_once 'monitor.php';

// 使用PHPMailer发送邮件
require_once 'mailer.php';
$mailer = new Mailer();
$mailerType = 'PHPMailer (SMTP)';

// 如果PHPMailer不可用，会在mailer.php中抛出异常

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $result = ['success' => false, 'message' => ''];
    
    try {
        switch ($action) {
            case 'test_basic':
                $subject = 'NetWatch 测试邮件 - ' . date('Y-m-d H:i:s');
                $body = '<h2>邮件发送测试</h2>
<p>这是一封测试邮件，用于验证NetWatch系统的邮件发送功能。</p>
<p><strong>发送时间:</strong> ' . date('Y-m-d H:i:s') . '</p>
<p><strong>邮件发送器:</strong> ' . $mailerType . '</p>
<p>如果您收到这封邮件，说明邮件发送功能正常工作。</p>';
                
                if ($mailer->sendMail($subject, $body, true)) {
                    $result['success'] = true;
                    $result['message'] = '测试邮件发送成功！请检查您的邮箱。';
                } else {
                    $result['message'] = '测试邮件发送失败。请检查SMTP配置和日志。';
                }
                break;
                
            case 'test_proxy_alert':
                // 模拟代理故障数据
                $failedProxies = [
                    [
                        'ip' => '192.168.1.100',
                        'port' => 8080,
                        'type' => 'http',
                        'failure_count' => 3,
                        'last_check' => date('Y-m-d H:i:s'),
                        'response_time' => 0
                    ],
                    [
                        'ip' => '192.168.1.101',
                        'port' => 1080,
                        'type' => 'socks5',
                        'failure_count' => 5,
                        'last_check' => date('Y-m-d H:i:s'),
                        'response_time' => 0
                    ]
                ];
                
                if ($mailer->sendProxyAlert($failedProxies)) {
                    $result['success'] = true;
                    $result['message'] = '代理故障通知发送成功！';
                } else {
                    $result['message'] = '代理故障通知发送失败。';
                }
                break;
                
            case 'test_status_report':
                // 模拟系统状态数据
                $stats = [
                    'total' => 150,
                    'online' => 142,
                    'offline' => 6,
                    'unknown' => 2,
                    'avg_response_time' => 245.67
                ];
                
                if ($mailer->sendStatusReport($stats)) {
                    $result['success'] = true;
                    $result['message'] = '系统状态报告发送成功！';
                } else {
                    $result['message'] = '系统状态报告发送失败。';
                }
                break;
                
            case 'check_failed_proxies':
                // 检查实际的故障代理
                $database = new Database();
                $monitor = new Monitor($database);
                
                $failedProxies = $database->getFailedProxies();
                
                if (!empty($failedProxies)) {
                    if ($mailer->sendProxyAlert($failedProxies)) {
                        $result['success'] = true;
                        $result['message'] = '发现 ' . count($failedProxies) . ' 个故障代理，通知邮件已发送！';
                    } else {
                        $result['message'] = '发现故障代理，但邮件发送失败。';
                    }
                } else {
                    $result['success'] = true;
                    $result['message'] = '没有发现故障代理，系统运行正常。';
                }
                break;
                
            default:
                $result['message'] = '未知操作';
        }
        
        // 获取最新的日志信息
        $logPaths = [
            LOG_PATH . 'netwatch_' . date('Y-m-d') . '.log',
            './logs/netwatch_' . date('Y-m-d') . '.log',
            'logs/netwatch_' . date('Y-m-d') . '.log'
        ];
        
        $logContent = '';
        foreach ($logPaths as $logPath) {
            if (file_exists($logPath)) {
                $content = file_get_contents($logPath);
                $lines = explode("\n", $content);
                $recentLines = array_slice($lines, -10); // 最后10行
                $logContent = implode("\n", $recentLines);
                break;
            }
        }
        
        $result['log'] = $logContent;
        
    } catch (Exception $e) {
        $result['message'] = '操作异常: ' . $e->getMessage();
        $result['debug'][] = '异常详情: ' . $e->getTraceAsString();
    } catch (Error $e) {
        $result['message'] = 'PHP错误: ' . $e->getMessage();
        $result['debug'][] = '错误详情: ' . $e->getTraceAsString();
    }
    
    // 清理输出缓冲区，防止意外输出
    ob_clean();
    
    // 输出JSON，支持中文
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 加载配置用于页面显示
require_once 'config.php';
$mailerType = 'PHPMailer (SMTP)';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch 邮件测试</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        .config-info {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #2196F3;
        }
        .test-section {
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #fafafa;
        }
        .test-button {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
            font-size: 14px;
        }
        .test-button:hover {
            background: #45a049;
        }
        .test-button:disabled {
            background: #cccccc;
            cursor: not-allowed;
        }
        .result {
            margin-top: 15px;
            padding: 10px;
            border-radius: 4px;
            display: none;
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
        .log-section {
            margin-top: 20px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
        }
        .log-header {
            background: #e9ecef;
            padding: 10px;
            font-weight: bold;
            border-bottom: 1px solid #dee2e6;
        }
        .log-content {
            padding: 10px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        .loading {
            display: none;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📧 NetWatch 邮件发送测试</h1>
            <p>测试和验证邮件发送功能</p>
        </div>

        <div class="config-info">
            <h3>📋 当前配置信息</h3>
            <p><strong>邮件发送器:</strong> <?php echo $mailerType; ?></p>
            <p><strong>SMTP服务器:</strong> <?php echo defined('SMTP_HOST') ? SMTP_HOST : '未配置'; ?>:<?php echo defined('SMTP_PORT') ? SMTP_PORT : '未配置'; ?></p>
            <p><strong>发件人:</strong> <?php echo defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '未配置'; ?></p>
            <p><strong>收件人:</strong> <?php echo defined('SMTP_TO_EMAIL') ? SMTP_TO_EMAIL : '未配置'; ?></p>
        </div>

        <div class="test-section">
            <h3>🧪 基础邮件测试</h3>
            <p>发送一封简单的测试邮件，验证基本的邮件发送功能。</p>
            <button class="test-button" onclick="runTest('test_basic')">发送测试邮件</button>
            <div class="loading" id="loading-test_basic">正在发送邮件...</div>
            <div class="result" id="result-test_basic"></div>
        </div>

        <div class="test-section">
            <h3>🚨 代理故障通知测试</h3>
            <p>发送模拟的代理故障通知邮件，测试故障报警功能。</p>
            <button class="test-button" onclick="runTest('test_proxy_alert')">发送故障通知</button>
            <div class="loading" id="loading-test_proxy_alert">正在发送通知...</div>
            <div class="result" id="result-test_proxy_alert"></div>
        </div>

        <div class="test-section">
            <h3>📊 系统状态报告测试</h3>
            <p>发送模拟的系统状态报告邮件，测试定期报告功能。</p>
            <button class="test-button" onclick="runTest('test_status_report')">发送状态报告</button>
            <div class="loading" id="loading-test_status_report">正在发送报告...</div>
            <div class="result" id="result-test_status_report"></div>
        </div>

        <div class="test-section">
            <h3>🔍 检查实际故障代理</h3>
            <p>检查数据库中的实际故障代理，并发送通知邮件。</p>
            <button class="test-button" onclick="runTest('check_failed_proxies')">检查并通知故障代理</button>
            <div class="loading" id="loading-check_failed_proxies">正在检查故障代理...</div>
            <div class="result" id="result-check_failed_proxies"></div>
        </div>

        <div class="log-section">
            <div class="log-header">📝 最新日志</div>
            <div class="log-content" id="log-content">点击任意测试按钮查看日志...</div>
        </div>

        <div style="margin-top: 30px; text-align: center;">
            <a href="index.php" style="color: #666; text-decoration: none;">← 返回主页</a>
        </div>
    </div>

    <script>
        function runTest(action) {
            const button = document.querySelector(`button[onclick="runTest('${action}')"]`);
            const loading = document.getElementById(`loading-${action}`);
            const result = document.getElementById(`result-${action}`);
            const logContent = document.getElementById('log-content');
            
            // 禁用按钮，显示加载状态
            button.disabled = true;
            loading.style.display = 'block';
            result.style.display = 'none';
            
            // 发送AJAX请求
            fetch('test_email.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=${action}`
            })
            .then(response => response.json())
            .then(data => {
                // 隐藏加载状态，启用按钮
                loading.style.display = 'none';
                button.disabled = false;
                
                // 显示结果
                result.style.display = 'block';
                result.className = `result ${data.success ? 'success' : 'error'}`;
                result.textContent = data.message;
                
                // 更新日志
                if (data.log) {
                    logContent.textContent = data.log;
                } else {
                    logContent.textContent = '没有找到日志文件或日志为空';
                }
            })
            .catch(error => {
                loading.style.display = 'none';
                button.disabled = false;
                
                result.style.display = 'block';
                result.className = 'result error';
                result.textContent = '请求失败: ' + error.message;
            });
        }
    </script>
</body>
</html>
