<?php
/**
 * 清空代理列表工具
 */

// 开启错误显示用于调试
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

try {
    require_once 'config.php';
    require_once 'auth.php';
    require_once 'monitor.php';
    require_once 'includes/functions.php';
    if (file_exists(__DIR__ . '/includes/AuditLogger.php')) {
        require_once __DIR__ . '/includes/AuditLogger.php';
    }
    
    // 检查登录状态
    Auth::requireLogin();
} catch (Exception $e) {
    die("<h2>加载文件失败</h2><p>错误: " . htmlspecialchars($e->getMessage()) . "</p>");
}

$error = null;
$success = null;
$clearExecuted = false;

try {
    $monitor = new NetworkMonitor();
    
    // 获取当前代理数量
    $proxies = $monitor->getAllProxies();
    $totalProxies = count($proxies);
} catch (Exception $e) {
    die("<h2>初始化失败</h2><p>错误: " . htmlspecialchars($e->getMessage()) . "</p><p>请检查数据库连接和文件权限</p>");
}

// 处理清空请求
if ($_POST && isset($_POST['confirm_clear']) && $_POST['confirm_clear'] === 'yes') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Auth::validateCsrfToken($csrfToken)) {
        $error = 'CSRF验证失败，请刷新页面后重试';
        if (class_exists('AuditLogger')) {
            AuditLogger::log('clear_proxies_csrf_failed', 'proxy');
        }
    } else {
        try {
            $db = new Database();
            $db->initializeSchema();
            $db->clearAllData();
            $clearExecuted = true;
            $success = "已成功删除 $totalProxies 个代理及相关数据";

            if (class_exists('AuditLogger')) {
                AuditLogger::log('clear_proxies', 'proxy', null, [
                    'deleted' => $totalProxies
                ]);
            }
            
            // 刷新代理列表
            $proxies = $monitor->getAllProxies();
            $totalProxies = count($proxies);
        } catch (Exception $e) {
            $error = $e->getMessage();
            if (class_exists('AuditLogger')) {
                AuditLogger::log('clear_proxies_failed', 'proxy', null, [
                    'error' => $error
                ]);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>清空代理列表 - NetWatch</title>
    <link rel="stylesheet" href="includes/style-v2.css?v=<?php echo filemtime(__DIR__ . '/includes/style-v2.css'); ?>">
    <style nonce="<?php echo htmlspecialchars(netwatch_get_csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
        .section {
            padding: 25px;
        }
        
        .section h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: var(--color-primary);
            font-size: 18px;
            font-weight: 600;
        }
        
        .alert {
            padding: 20px 25px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid;
        }
        
        .alert h3 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.15);
            border-color: var(--color-warning);
            color: var(--color-text);
        }
        
        .alert-warning h3 {
            color: var(--color-warning);
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border-color: var(--color-success);
            color: var(--color-text);
        }
        
        .alert-success h3 {
            color: var(--color-success);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border-color: var(--color-danger);
            color: var(--color-text);
        }
        
        .alert-error h3 {
            color: var(--color-danger);
        }
        
        .alert-info {
            background: rgba(59, 130, 246, 0.15);
            border-color: var(--color-primary);
            color: var(--color-text);
        }
        
        .alert-info h3 {
            color: var(--color-primary);
        }
        
        .alert ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .alert li {
            margin-bottom: 5px;
        }
        
        .table-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--color-border);
            margin: 20px 0;
        }
        
        .proxy-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .proxy-table th,
        .proxy-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--color-border);
            color: var(--color-text);
        }
        
        .proxy-table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--color-primary);
        }
        
        .proxy-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }
        
        .proxy-table .more-row {
            text-align: center;
            font-style: italic;
            color: var(--color-muted);
        }
        
        .confirm-form {
            background: rgba(255, 255, 255, 0.05);
            padding: 25px;
            border-radius: 8px;
            border: 1px solid var(--color-border);
            margin-top: 20px;
        }
        
        .confirm-form p {
            margin-bottom: 15px;
            color: var(--color-text);
        }
        
        .confirm-form label {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--color-text);
            cursor: pointer;
            margin-bottom: 20px;
        }
        
        .confirm-form input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.3);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(239, 68, 68, 0.4);
        }
        
        .btn-danger:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .other-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--color-border);
        }
        
        .other-actions h3 {
            color: var(--color-text);
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .action-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .action-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            color: var(--color-text);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .action-link:hover {
            background: rgba(59, 130, 246, 0.15);
            border-color: var(--color-primary);
            color: var(--color-primary);
        }
        
        .status-online { color: var(--color-success); }
        .status-offline { color: var(--color-danger); }
        .status-unknown { color: var(--color-warning); }
        
        @media (max-width: 768px) {
            .section {
                padding: 15px;
            }
            
            .proxy-table th,
            .proxy-table td {
                padding: 8px;
                font-size: 13px;
            }
            
            .action-links {
                flex-direction: column;
            }
            
            .action-link {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>🗑️ 清空代理列表</h1>
                    <p>危险操作 - 请谨慎使用</p>
                </div>
                <?php if (Auth::isLoginEnabled()): ?>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-row">
                            <div class="username">👤 <?php echo htmlspecialchars(Auth::getCurrentUser()); ?></div>
                            <button type="button" class="logout-btn" onclick="showCustomConfirm('确定要退出登录吗？', () => submitLogout()); return false;">退出</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 导航链接 -->
    <div class="container">
        <div class="nav-links">
            <a href="index.php" class="nav-link">主页</a>
            <a href="import.php" class="nav-link">代理导入</a>
        </div>
    </div>
    
    <div class="container">
        <div class="section">
            <?php if ($error): ?>
            <div class="alert alert-error">
                <h3>❌ 操作失败</h3>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <h3>✅ 清空完成</h3>
                <p><?php echo htmlspecialchars($success); ?></p>
                <p style="margin-top: 15px;">
                    <a href="import.php" class="btn" style="display: inline-block;">立即导入新代理</a>
                </p>
            </div>
            <?php endif; ?>
            
            <?php if (!$clearExecuted): ?>
            <div class="alert alert-warning">
                <h3>⚠️ 警告</h3>
                <p>此操作将<strong>永久删除</strong>所有代理数据，包括：</p>
                <ul>
                    <li>所有代理配置信息</li>
                    <li>历史检查日志</li>
                    <li>警报记录</li>
                </ul>
                <p><strong>当前代理数量：<?php echo $totalProxies; ?> 个</strong></p>
            </div>
            <?php endif; ?>
            
            <?php if ($totalProxies > 0 && !$clearExecuted): ?>
            <h2>当前代理列表预览</h2>
            <div class="table-container">
                <table class="proxy-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>代理地址</th>
                            <th>类型</th>
                            <th>用户名</th>
                            <th>状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $displayCount = min(10, $totalProxies);
                        for ($i = 0; $i < $displayCount; $i++): 
                            $proxy = $proxies[$i];
                            $statusClass = 'status-' . $proxy['status'];
                        ?>
                        <tr>
                            <td><?php echo $proxy['id']; ?></td>
                            <td><?php echo htmlspecialchars($proxy['ip'] . ':' . $proxy['port']); ?></td>
                            <td><?php echo strtoupper($proxy['type']); ?></td>
                            <td><?php echo htmlspecialchars($proxy['username'] ?: '未设置'); ?></td>
                            <td class="<?php echo $statusClass; ?>"><?php echo $proxy['status']; ?></td>
                        </tr>
                        <?php endfor; ?>
                        <?php if ($totalProxies > 10): ?>
                        <tr>
                            <td colspan="5" class="more-row">... 还有 <?php echo ($totalProxies - 10); ?> 个代理</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <h2>确认清空</h2>
            <form method="post" onsubmit="return confirmClear()">
                <div class="confirm-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::getCsrfToken()); ?>">
                    <p><strong>请确认您要清空所有代理数据：</strong></p>
                    <label>
                        <input type="checkbox" name="confirm_clear" value="yes" required>
                        我确认要删除所有 <strong><?php echo $totalProxies; ?></strong> 个代理及相关数据
                    </label>
                    <button type="submit" class="btn-danger">🗑️ 确认清空所有代理</button>
                </div>
            </form>
            
            <?php elseif ($totalProxies == 0 && !$clearExecuted): ?>
            <div class="alert alert-info">
                <h3>ℹ️ 代理列表已为空</h3>
                <p>当前没有任何代理数据</p>
                <p style="margin-top: 15px;">
                    <a href="import.php" class="btn" style="display: inline-block;">点击这里导入代理</a>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="other-actions">
                <h3>其他操作</h3>
                <div class="action-links">
                    <a href="index.php" class="action-link">🏠 返回监控面板</a>
                    <a href="import.php" class="action-link">📥 导入新代理</a>
                    <a href="Debug/diagnose.php" class="action-link">🔧 系统诊断</a>
                </div>
            </div>
        </div>
    </div>
    
    <form id="logout-form" method="POST" action="index.php?action=logout" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    </form>

    <script nonce="<?php echo htmlspecialchars(netwatch_get_csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
    function submitLogout() {
        document.getElementById('logout-form').submit();
    }

    function confirmClear() {
        return confirm('⚠️ 最后确认：您确定要删除所有代理数据吗？此操作无法撤销！');
    }
    </script>
</body>
</html>
