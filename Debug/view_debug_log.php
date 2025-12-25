<?php
/**
 * 调试日志查看器
 * 用于查看移动端AJAX请求的调试信息
 */

require_once '../auth.php';

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
    <link rel="stylesheet" href="../includes/style-v2.css?v=<?php echo time(); ?>">
    <style>
        .section {
            padding: 25px;
        }
        
        .controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .btn-refresh {
            background: var(--color-primary);
        }
        
        .btn-refresh:hover {
            background: #2563eb;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }
        
        .log-stats {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: var(--color-panel);
            border: 1px solid var(--color-border);
            border-radius: 8px;
        }
        
        .log-stats .count {
            font-size: 24px;
            font-weight: bold;
            color: var(--color-primary);
        }
        
        .log-stats .label {
            color: var(--color-muted);
        }
        
        .log-entry {
            background: var(--color-panel);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .log-timestamp {
            font-weight: bold;
            color: var(--color-primary);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--color-border);
        }
        
        .log-field {
            display: flex;
            margin: 8px 0;
            align-items: flex-start;
        }
        
        .log-field strong {
            flex-shrink: 0;
            width: 150px;
            color: var(--color-muted);
            font-weight: 500;
        }
        
        .log-field code {
            background: var(--color-panel-light);
            padding: 4px 8px;
            border-radius: 4px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            word-break: break-all;
            color: var(--color-text);
        }
        
        .no-logs {
            text-align: center;
            color: var(--color-muted);
            padding: 60px 20px;
            background: var(--color-panel);
            border: 1px solid var(--color-border);
            border-radius: 8px;
        }
        
        .no-logs .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .no-logs p {
            margin: 10px 0;
        }
        
        .no-logs small {
            color: var(--color-muted);
            font-size: 13px;
        }
        
        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
            }
            
            .controls .btn {
                width: 100%;
                text-align: center;
            }
            
            .log-field {
                flex-direction: column;
            }
            
            .log-field strong {
                width: 100%;
                margin-bottom: 5px;
                font-size: 12px;
            }
            
            .log-field code {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>� 调试日志查看器</h1>
                    <p>移动端AJAX请求调试信息</p>
                </div>
                <?php if (Auth::isLoginEnabled()): ?>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-row">
                            <div class="username">👤 <?php echo htmlspecialchars(Auth::getCurrentUser()); ?></div>
                            <a href="#" class="logout-btn" onclick="event.preventDefault(); if(confirm('确定要退出登录吗？')) window.location.href='../index.php?action=logout'; return false;">退出</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="nav-links">
            <a href="../index.php" class="nav-link">主页</a>
            <a href="diagnose.php" class="nav-link">系统诊断</a>
        </div>
    </div>
    
    <div class="container">
        <div class="section">
            <div class="controls">
                <button class="btn btn-refresh" onclick="location.reload()">🔄 刷新日志</button>
                <a href="../index.php" class="btn btn-secondary">🏠 返回主页</a>
                <?php if (!empty($logs)): ?>
                <form method="post" style="display: inline;">
                    <button type="submit" name="clear_log" class="btn btn-danger" onclick="return confirm('确定要清除所有日志吗？')">🗑️ 清除日志</button>
                </form>
                <?php endif; ?>
            </div>
            
            <?php if (empty($logs)): ?>
            <div class="no-logs">
                <div class="icon">📝</div>
                <p><strong>暂无调试日志</strong></p>
                <small>当移动端浏览器错误地发送AJAX请求时，相关信息会记录在这里</small>
            </div>
            <?php else: ?>
            <div class="log-stats">
                <span class="count"><?php echo count($logs); ?></span>
                <span class="label">条调试记录</span>
            </div>
            
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
    
    <script src="../includes/js/core.js?v=<?php echo time(); ?>"></script>
</body>
</html>
