<?php
/**
 * 测试代理检测重试机制
 */

require_once '../auth.php';
Auth::requireLogin();

require_once '../config.php';
require_once '../database.php';
require_once '../monitor.php';

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重试机制测试 - NetWatch</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
        }
        .test-section {
            margin: 20px 0;
            padding: 20px;
            background: #f9f9f9;
            border-left: 4px solid #2196F3;
        }
        .result {
            margin: 10px 0;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .log-entry {
            padding: 5px 10px;
            margin: 5px 0;
            background: white;
            border-left: 3px solid #666;
            font-size: 14px;
        }
        .log-entry.retry {
            border-left-color: #ff9800;
            background: #fff3e0;
        }
        .log-entry.warning {
            border-left-color: #f44336;
            background: #ffebee;
        }
        .log-entry.success {
            border-left-color: #4CAF50;
            background: #e8f5e9;
        }
        button {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px;
        }
        button:hover {
            background: #45a049;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #ddd;
            text-align: center;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #2196F3;
        }
        .stat-label {
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 代理检测重试机制测试</h1>
        
        <div class="test-section">
            <h2>功能说明</h2>
            <p>此测试页面用于验证代理检测的重试机制是否正常工作。</p>
            <ul>
                <li><strong>重试逻辑</strong>：当第一次检测失败时，系统会自动进行第二次检测</li>
                <li><strong>延迟时间</strong>：两次检测之间间隔0.1秒</li>
                <li><strong>适用范围</strong>：并行检测和快速检测模式</li>
                <li><strong>判定标准</strong>：只有两次检测都失败，才判定为真正离线</li>
            </ul>
        </div>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'test_retry') {
                echo '<div class="test-section">';
                echo '<h2>📊 测试结果</h2>';
                
                $db = new Database();
                $monitor = new NetworkMonitor();
                
                // 获取前10个代理进行测试
                $proxies = $db->getProxiesBatch(0, 10);
                
                if (empty($proxies)) {
                    echo '<div class="result error">❌ 没有找到代理数据</div>';
                } else {
                    echo '<div class="stats">';
                    echo '<div class="stat-card"><div class="stat-value">' . count($proxies) . '</div><div class="stat-label">测试代理数</div></div>';
                    echo '</div>';
                    
                    $retryCount = 0;
                    $successCount = 0;
                    $failCount = 0;
                    
                    // 读取日志文件以检测重试
                    $logFile = __DIR__ . '/../logs/app.log';
                    $logsBefore = file_exists($logFile) ? file_get_contents($logFile) : '';
                    
                    foreach ($proxies as $proxy) {
                        echo '<div class="result info">';
                        echo '<strong>测试代理：</strong>' . htmlspecialchars($proxy['ip'] . ':' . $proxy['port']) . '<br>';
                        
                        // 使用启用重试的快速检测
                        $startTime = microtime(true);
                        $result = $monitor->checkProxyFast($proxy, true);
                        $duration = round((microtime(true) - $startTime) * 1000, 2);
                        
                        echo '<strong>检测结果：</strong>' . ($result['status'] === 'online' ? '✅ 在线' : '❌ 离线') . '<br>';
                        echo '<strong>响应时间：</strong>' . round($result['response_time'], 2) . 'ms<br>';
                        echo '<strong>总耗时：</strong>' . $duration . 'ms<br>';
                        
                        if ($result['error_message']) {
                            echo '<strong>错误信息：</strong>' . htmlspecialchars($result['error_message']) . '<br>';
                        }
                        
                        if ($result['status'] === 'online') {
                            $successCount++;
                        } else {
                            $failCount++;
                        }
                        
                        echo '</div>';
                    }
                    
                    // 读取新增的日志
                    $logsAfter = file_exists($logFile) ? file_get_contents($logFile) : '';
                    $newLogs = substr($logsAfter, strlen($logsBefore));
                    
                    // 分析日志中的重试记录
                    echo '<div class="test-section">';
                    echo '<h3>📝 检测日志分析</h3>';
                    
                    $logLines = explode("\n", $newLogs);
                    $retryDetected = false;
                    
                    foreach ($logLines as $line) {
                        if (empty(trim($line))) continue;
                        
                        $cssClass = 'log-entry';
                        if (strpos($line, '进行第二次检测') !== false) {
                            $cssClass .= ' retry';
                            $retryDetected = true;
                            $retryCount++;
                        } elseif (strpos($line, '失败') !== false || strpos($line, '异常') !== false) {
                            $cssClass .= ' warning';
                        } elseif (strpos($line, '成功') !== false) {
                            $cssClass .= ' success';
                        }
                        
                        echo '<div class="' . $cssClass . '">' . htmlspecialchars($line) . '</div>';
                    }
                    
                    echo '</div>';
                    
                    // 显示统计结果
                    echo '<div class="stats">';
                    echo '<div class="stat-card"><div class="stat-value" style="color: #4CAF50;">' . $successCount . '</div><div class="stat-label">在线代理</div></div>';
                    echo '<div class="stat-card"><div class="stat-value" style="color: #f44336;">' . $failCount . '</div><div class="stat-label">离线代理</div></div>';
                    echo '<div class="stat-card"><div class="stat-value" style="color: #ff9800;">' . $retryCount . '</div><div class="stat-label">触发重试次数</div></div>';
                    echo '</div>';
                    
                    if ($retryDetected) {
                        echo '<div class="result success">✅ 重试机制正常工作！检测到 ' . $retryCount . ' 次重试操作</div>';
                    } else {
                        echo '<div class="result info">ℹ️ 本次测试未触发重试（所有代理第一次检测都成功或都失败）</div>';
                    }
                }
                
                echo '</div>';
            }
        }
        ?>

        <div class="test-section">
            <h2>🧪 开始测试</h2>
            <form method="POST">
                <input type="hidden" name="action" value="test_retry">
                <button type="submit">运行重试机制测试</button>
            </form>
            <p style="color: #666; margin-top: 10px;">
                <small>测试将检查前10个代理，并分析是否触发了重试机制</small>
            </p>
        </div>

        <div class="test-section">
            <h2>📖 技术说明</h2>
            <h3>重试机制实现细节：</h3>
            <ul>
                <li><strong>触发条件</strong>：第一次检测返回失败状态或抛出异常</li>
                <li><strong>重试延迟</strong>：0.1秒（100毫秒）</li>
                <li><strong>重试次数</strong>：最多1次（共2次检测机会）</li>
                <li><strong>日志标记</strong>：第二次检测的日志会标注"(第二次检测)"</li>
                <li><strong>数据库更新</strong>：只有最终结果会写入数据库</li>
            </ul>
            
            <h3>适用场景：</h3>
            <ul>
                <li>✅ 并行检测（默认启用重试）</li>
                <li>✅ 快速检测（可选启用重试）</li>
                <li>❌ 逐个检测（不启用重试，保持原有行为）</li>
            </ul>
        </div>

        <div style="margin-top: 20px; text-align: center;">
            <a href="../index.php" style="color: #2196F3; text-decoration: none;">← 返回主页</a>
        </div>
    </div>
</body>
</html>
