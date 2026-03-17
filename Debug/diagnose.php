<?php
/**
 * 快速诊断脚本
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../monitor.php';

// 检查登录状态
Auth::requireLogin();

$monitor = new NetworkMonitor();
$proxies = $monitor->getAllProxies();

$totalProxies = count($proxies);
$withAuth = 0;
$withoutAuth = 0;

foreach ($proxies as $proxy) {
    if (!empty($proxy['username']) && !empty($proxy['password'])) {
        $withAuth++;
    } else {
        $withoutAuth++;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统诊断 - NetWatch</title>
    <link rel="stylesheet" href="../includes/style-v2.css?v=<?php echo filemtime(__DIR__ . '/../includes/style-v2.css'); ?>">
    <style>
        .section {
            padding: 25px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: var(--color-panel);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-card .number {
            font-size: 28px;
            font-weight: bold;
            color: var(--color-primary);
        }
        
        .stat-card .label {
            font-size: 13px;
            color: var(--color-muted);
            margin-top: 5px;
        }
        
        .stat-card.success .number { color: var(--color-success); }
        .stat-card.danger .number { color: var(--color-danger); }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 14px;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--color-border);
        }
        
        .data-table th {
            background: var(--color-panel-light);
            color: var(--color-text);
            font-weight: 600;
        }
        
        .data-table tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        
        .status-ok { color: var(--color-success); }
        .status-error { color: var(--color-danger); }
        
        .alert {
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid;
        }
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.15);
            border-color: var(--color-warning);
        }
        
        .alert-warning h4 {
            color: var(--color-warning);
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .alert ol, .alert ul {
            margin: 10px 0 0 20px;
            padding: 0;
        }
        
        .alert li {
            margin-bottom: 8px;
        }
        
        .alert a {
            color: var(--color-primary);
        }
        
        .code-block {
            background: var(--color-panel);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 15px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            overflow-x: auto;
            color: var(--color-text);
            margin: 10px 0;
        }
        
        .code-inline {
            background: var(--color-panel-light);
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
        }
        
        .tips-list {
            margin: 15px 0;
            padding-left: 20px;
        }
        
        .tips-list li {
            margin-bottom: 10px;
            line-height: 1.6;
        }
        
        .sample-data {
            background: var(--color-panel);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 15px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 12px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
        
        @media (max-width: 768px) {
            .data-table {
                font-size: 12px;
            }
            
            .data-table th,
            .data-table td {
                padding: 8px 10px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>🔍 系统诊断</h1>
                    <p>NetWatch 诊断报告</p>
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
    
    <div class="container">
        <div class="nav-links">
            <a href="../index.php" class="nav-link">主页</a>
            <a href="view_debug_log.php" class="nav-link">调试日志</a>
        </div>
    </div>
    
    <div class="container">
        <!-- 统计摘要 -->
        <div class="section">
            <h2>📊 统计摘要</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?php echo $totalProxies; ?></div>
                    <div class="label">总代理数</div>
                </div>
                <div class="stat-card success">
                    <div class="number"><?php echo $withAuth; ?></div>
                    <div class="label">有认证信息</div>
                </div>
                <div class="stat-card danger">
                    <div class="number"><?php echo $withoutAuth; ?></div>
                    <div class="label">缺少认证</div>
                </div>
            </div>
        </div>
        
        <?php if ($withoutAuth > 0): ?>
        <!-- 问题警告 -->
        <div class="alert alert-warning">
            <h4>⚠️ 发现问题</h4>
            <p>有 <strong><?php echo $withoutAuth; ?></strong> 个代理缺少认证信息，这会导致407错误。</p>
            <p><strong>解决方案:</strong></p>
            <ol>
                <li>重新导入代理，确保格式为: <code class="code-inline">IP:端口:类型:用户名:密码</code></li>
                <li>或使用 <a href="fix_proxy_auth.php">认证修复工具</a> 手动添加认证信息</li>
            </ol>
        </div>
        <?php endif; ?>
        
        <!-- 代理认证状态 -->
        <div class="section">
            <h2>🔐 代理认证状态分析</h2>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>代理</th>
                            <th>类型</th>
                            <th>用户名</th>
                            <th>密码</th>
                            <th>认证状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proxies as $proxy): 
                            $hasUsername = !empty($proxy['username']);
                            $hasPassword = !empty($proxy['password']);
                            $hasAuth = $hasUsername && $hasPassword;
                        ?>
                        <tr>
                            <td><?php echo $proxy['id']; ?></td>
                            <td><?php echo htmlspecialchars($proxy['ip'] . ':' . $proxy['port']); ?></td>
                            <td><?php echo strtoupper($proxy['type']); ?></td>
                            <td><?php echo $hasUsername ? '<span class="status-ok">✓ ' . htmlspecialchars($proxy['username']) . '</span>' : '<span class="status-error">未设置</span>'; ?></td>
                            <td><?php echo $hasPassword ? '<span class="status-ok">✓ ***</span>' : '<span class="status-error">未设置</span>'; ?></td>
                            <td><?php echo $hasAuth ? '<span class="status-ok">✓ 完整</span>' : '<span class="status-error">✗ 缺失</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <form id="logout-form" method="POST" action="../index.php?action=logout" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        </form>

        <script>
            function submitLogout() {
                document.getElementById('logout-form').submit();
            }
        </script>
        
        <!-- 导入格式说明 -->
        <div class="section">
            <h2>📝 导入格式检查</h2>
            <p>正确的导入格式示例:</p>
            <div class="code-block">23.94.152.162:24122:http:Ack0107sAdmin:your_password
192.168.1.100:1080:socks5:username:password
10.0.0.1:8080:http:user:pass</div>
        </div>
        
        <!-- 测试建议 -->
        <div class="section">
            <h2>💡 测试建议</h2>
            <ol class="tips-list">
                <li>确认您导入的代理格式包含用户名和密码</li>
                <li>如果格式正确但仍然407错误，可能是认证信息不正确</li>
                <li>联系代理提供商确认正确的用户名和密码</li>
            </ol>
        </div>
        
        <?php if (!empty($proxies)): ?>
        <!-- 示例代理详情 -->
        <div class="section">
            <h2>🔎 示例代理详细信息</h2>
            <div class="sample-data"><?php print_r($proxies[0]); ?></div>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="../includes/js/core.js?v=<?php echo time(); ?>"></script>
</body>
</html>
