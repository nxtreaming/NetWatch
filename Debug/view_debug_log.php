<?php
/**
 * 调试日志查看器
 * 用于查看移动端AJAX请求的调试信息
 */

require_once 'auth.php';

// 检查登录状态
Auth::requireLogin();

$logFile = 'debug_ajax_mobile.log';
$logs = [];

if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    $lines = explode("\n", trim($content));
    
    foreach ($lines as $line) {
        if (!empty($line)) {
            $logData = json_decode($line, true);
            if ($logData) {
                $logs[] = $logData;
            }
        }
    }
    
    // 按时间倒序排列
    $logs = array_reverse($logs);
}

// 处理清除日志请求
if (isset($_POST['clear_log'])) {
    if (file_exists($logFile)) {
        unlink($logFile);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>调试日志查看器 - NetWatch</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        
        .controls {
            padding: 20px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }
        
        .btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 10px;
        }
        
        .btn:hover {
            background: #45a049;
        }
        
        .btn-danger {
            background: #f44336;
        }
        
        .btn-danger:hover {
            background: #d32f2f;
        }
        
        .log-container {
            padding: 20px;
        }
        
        .log-entry {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .log-timestamp {
            font-weight: bold;
            color: #666;
            margin-bottom: 10px;
        }
        
        .log-field {
            margin: 5px 0;
        }
        
        .log-field strong {
            display: inline-block;
            width: 150px;
            color: #333;
        }
        
        .log-field code {
            background: #e8e8e8;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            word-break: break-all;
        }
        
        .no-logs {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 40px;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .log-field strong {
                width: 100px;
                font-size: 12px;
            }
            
            .log-field code {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 移动端AJAX调试日志</h1>
            <p>记录移动端浏览器错误处理AJAX请求的情况</p>
        </div>
        
        <div class="controls">
            <button class="btn" onclick="location.reload()">🔄 刷新日志</button>
            <button class="btn" onclick="window.open('test_mobile.html', '_blank')">🧪 打开测试页面</button>
            <button class="btn" onclick="window.open('index.php', '_blank')">🏠 返回主页</button>
            
            <?php if (!empty($logs)): ?>
            <form method="post" style="display: inline;">
                <button type="submit" name="clear_log" class="btn btn-danger" onclick="return confirm('确定要清除所有日志吗？')">🗑️ 清除日志</button>
            </form>
            <?php endif; ?>
        </div>
        
        <div class="log-container">
            <?php if (empty($logs)): ?>
                <div class="no-logs">
                    📝 暂无调试日志<br>
                    <small>当移动端浏览器错误地发送AJAX请求时，相关信息会记录在这里</small>
                </div>
            <?php else: ?>
                <p><strong>共找到 <?php echo count($logs); ?> 条调试记录：</strong></p>
                
                <?php foreach ($logs as $log): ?>
                <div class="log-entry">
                    <div class="log-timestamp">⏰ <?php echo htmlspecialchars($log['timestamp']); ?></div>
                    
                    <div class="log-field">
                        <strong>操作类型：</strong>
                        <code><?php echo htmlspecialchars($log['action'] ?? 'unknown'); ?></code>
                    </div>
                    
                    <div class="log-field">
                        <strong>请求方法：</strong>
                        <code><?php echo htmlspecialchars($log['request_method'] ?? 'unknown'); ?></code>
                    </div>
                    
                    <div class="log-field">
                        <strong>用户代理：</strong>
                        <code><?php echo htmlspecialchars($log['user_agent'] ?? 'unknown'); ?></code>
                    </div>
                    
                    <div class="log-field">
                        <strong>来源页面：</strong>
                        <code><?php echo htmlspecialchars($log['referer'] ?? 'none'); ?></code>
                    </div>
                    
                    <div class="log-field">
                        <strong>Accept头：</strong>
                        <code><?php echo htmlspecialchars($log['accept'] ?? 'none'); ?></code>
                    </div>
                    
                    <div class="log-field">
                        <strong>X-Requested-With：</strong>
                        <code><?php echo htmlspecialchars($log['x_requested_with'] ?? 'none'); ?></code>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
