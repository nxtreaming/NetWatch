<?php
/**
 * 简化版邮件测试工具 - 用于调试
 */

// 开启错误显示
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
require_once '../auth.php';
require_once '../database.php';
require_once '../monitor.php';
require_once '../mailer.php';

// 检查登录状态
Auth::requireLogin();

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $result = ['success' => false, 'message' => '', 'debug' => []];

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Auth::validateCsrfToken($csrfToken)) {
        echo json_encode([
            'success' => false,
            'message' => 'CSRF验证失败，请刷新页面后重试',
            'debug' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $result['debug'][] = "开始处理操作: $action";
        
        switch ($action) {
            case 'test_basic':
                $result['debug'][] = "创建Mailer实例";
                $mailer = new Mailer();
                
                $subject = 'NetWatch 简单测试邮件 - ' . date('Y-m-d H:i:s');
                $body = '<h2>简单测试邮件</h2><p>发送时间: ' . date('Y-m-d H:i:s') . '</p>';
                
                $result['debug'][] = "准备发送邮件";
                if ($mailer->sendMail($subject, $body, true)) {
                    $result['success'] = true;
                    $result['message'] = '测试邮件发送成功！';
                } else {
                    $result['message'] = '测试邮件发送失败';
                }
                break;
                
            case 'check_failed_proxies':
                $result['debug'][] = "创建Database实例";
                $database = new Database();
                
                $result['debug'][] = "调用getFailedProxies方法";
                $failedProxies = $database->getFailedProxies();
                
                $result['debug'][] = "故障代理数量: " . count($failedProxies);
                
                if (!empty($failedProxies)) {
                    $result['debug'][] = "创建Mailer实例";
                    $mailer = new Mailer();
                    
                    $result['debug'][] = "发送故障通知";
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
        
    } catch (Exception $e) {
        $result['message'] = '操作异常: ' . $e->getMessage();
        $result['debug'][] = '异常详情已隐藏，请查看服务端日志';
    } catch (Error $e) {
        $result['message'] = 'PHP错误: ' . $e->getMessage();
        $result['debug'][] = '错误详情已隐藏，请查看服务端日志';
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch 简化邮件测试</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .test-button { 
            background: #4CAF50; color: white; padding: 10px 20px; 
            border: none; border-radius: 4px; cursor: pointer; margin: 10px;
        }
        .test-button:disabled { background: #cccccc; cursor: not-allowed; }
        .result { 
            margin: 10px 0; padding: 10px; border-radius: 4px; 
            white-space: pre-wrap; font-family: monospace;
        }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .debug { background: #e2e3e5; color: #383d41; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 NetWatch 简化邮件测试</h1>
        
        <button class="test-button" onclick="runTest('test_basic')">发送基础测试邮件</button>
        <button class="test-button" onclick="runTest('check_failed_proxies')">检查故障代理</button>
        
        <div id="result"></div>
        <div id="debug" class="debug"></div>
        
        <p><a href="index.php">返回主页</a></p>
    </div>

    <script>
        const csrfToken = <?php echo json_encode(Auth::getCsrfToken(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        function runTest(action) {
            const buttons = document.querySelectorAll('.test-button');
            const resultDiv = document.getElementById('result');
            const debugDiv = document.getElementById('debug');
            
            // 禁用所有按钮
            buttons.forEach(btn => btn.disabled = true);
            
            resultDiv.textContent = '正在处理...';
            resultDiv.className = 'result';
            debugDiv.textContent = '';
            
            fetch('test_email_simple.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=${encodeURIComponent(action)}&csrf_token=${encodeURIComponent(csrfToken)}`
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                return response.text();
            })
            .then(text => {
                console.log('Response text:', text);
                try {
                    const data = JSON.parse(text);
                    
                    resultDiv.textContent = data.message;
                    resultDiv.className = `result ${data.success ? 'success' : 'error'}`;
                    
                    if (data.debug && data.debug.length > 0) {
                        debugDiv.textContent = '调试信息:\n' + data.debug.join('\n');
                    }
                } catch (e) {
                    resultDiv.textContent = 'JSON解析失败，原始响应:\n' + text;
                    resultDiv.className = 'result error';
                }
            })
            .catch(error => {
                resultDiv.textContent = '请求失败: ' + error.message;
                resultDiv.className = 'result error';
            })
            .finally(() => {
                // 重新启用按钮
                buttons.forEach(btn => btn.disabled = false);
            });
        }
    </script>
</body>
</html>
