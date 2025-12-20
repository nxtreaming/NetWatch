<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'database.php';
require_once 'includes/functions.php';

// 强制登录检查
Auth::requireLogin();

$db = new Database();

// 处理AJAX请求
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $name = $_POST['name'] ?? '';
            $proxyCount = (int)($_POST['proxy_count'] ?? 1);
            $expiryDays = (int)($_POST['expiry_days'] ?? 30);
            
            if (empty($name) || $proxyCount < 1 || $expiryDays < 1) {
                echo json_encode(['success' => false, 'message' => '参数无效']);
                exit;
            }
            
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
            $token = $db->createApiToken($name, $proxyCount, $expiresAt);
            
            if ($token) {
                echo json_encode(['success' => true, 'token' => $token, 'message' => 'Token创建成功']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Token创建失败']);
            }
            break;
            
        case 'refresh':
            $tokenId = (int)($_POST['token_id'] ?? 0);
            $expiryDays = (int)($_POST['expiry_days'] ?? 30);
            
            if ($tokenId < 1 || $expiryDays < 1) {
                echo json_encode(['success' => false, 'message' => '参数无效']);
                exit;
            }
            
            $newExpiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
            $result = $db->refreshToken($tokenId, $newExpiresAt);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Token有效期刷新成功']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Token刷新失败']);
            }
            break;
            
        case 'delete':
            $tokenId = (int)($_POST['token_id'] ?? 0);
            
            if ($tokenId < 1) {
                echo json_encode(['success' => false, 'message' => '参数无效']);
                exit;
            }
            
            $result = $db->deleteToken($tokenId);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Token删除成功']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Token删除失败']);
            }
            break;
            
        case 'reassign':
            $tokenId = (int)($_POST['token_id'] ?? 0);
            $proxyCount = (int)($_POST['proxy_count'] ?? 1);
            
            if ($tokenId < 1 || $proxyCount < 1) {
                echo json_encode(['success' => false, 'message' => '参数无效']);
                exit;
            }
            
            $result = $db->reassignTokenProxies($tokenId, $proxyCount);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => '代理重新分配成功']);
            } else {
                echo json_encode(['success' => false, 'message' => '代理重新分配失败']);
            }
            break;
            
        case 'list':
            $tokens = $db->getAllTokens();
            echo json_encode(['success' => true, 'tokens' => $tokens]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
    exit;
}

// 获取所有tokens
$tokens = $db->getAllTokens();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Token 管理 - NetWatch</title>
    <link rel="stylesheet" href="includes/style-v2.css?v=<?php echo time(); ?>">
    <style>
        .section {
            margin-bottom: 30px;
            background: var(--color-panel);
            border-radius: 8px;
            padding: 25px;
            border: 1px solid var(--color-border);
        }
        
        .section h3 {
            margin-top: 0;
            margin-bottom: 20px;
            color: var(--color-primary);
            font-size: 18px;
            font-weight: 600;
        }
        
        .create-token-form {
            background: rgba(255, 255, 255, 0.05);
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--color-border);
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            align-items: end;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--color-text);
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            font-size: 14px;
            background: var(--color-panel-light);
            color: var(--color-text);
        }
        
        .table-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--color-border);
        }
        
        .token-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .token-table th,
        .token-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--color-border);
            color: var(--color-text);
        }
        
        .token-table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--color-primary);
        }
        
        .token-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }
        
        .token-value {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            background: rgba(255, 255, 255, 0.1);
            color: var(--color-primary);
            padding: 4px 8px;
            border-radius: 4px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-valid {
            background: rgba(16, 185, 129, 0.2);
            color: var(--color-success);
            border: 1px solid var(--color-success);
        }
        
        .status-expired {
            background: rgba(239, 68, 68, 0.2);
            color: var(--color-danger);
            border: 1px solid var(--color-danger);
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary { background: var(--color-primary); color: white; }
        .btn-warning { background: var(--color-warning); color: #212529; }
        .btn-danger { background: var(--color-danger); color: white; }
        .btn-success { background: var(--color-success); color: white; }
        
        .btn-small:hover {
            opacity: 0.8;
        }
        
        @media (max-width: 768px) {
            .section h3 {
                font-size: 18px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .form-group {
                width: 100%;
            }
            
            .form-group input,
            .form-group select {
                width: 100%;
                padding: 12px;
                font-size: 16px; /* 防止iOS缩放 */
                border: 2px solid #ddd;
                border-radius: 6px;
            }
            
            .form-group label {
                font-size: 14px;
                margin-bottom: 8px;
                display: block;
            }
            
            .btn.btn-success {
                width: 100%;
                padding: 15px;
                font-size: 16px;
                margin-top: 10px;
            }
            
            /* 移动端表格优化 */
            .token-table {
                font-size: 11px;
                min-width: 100%;
            }
            
            .token-table th,
            .token-table td {
                padding: 8px 4px;
                vertical-align: top;
            }
            
            /* 隐藏部分列以节省空间 */
            .token-table th:nth-child(2), /* Token值 */
            .token-table td:nth-child(2),
            .token-table th:nth-child(3), /* 代理数量 */
            .token-table td:nth-child(3),
            .token-table th:nth-child(5), /* 状态 */
            .token-table td:nth-child(5),
            .token-table th:nth-child(6), /* 创建时间 */
            .token-table td:nth-child(6) {
                display: none;
            }
            
            /* 调整剩余列的宽度 */
            .token-table th:nth-child(1), /* 名称 */
            .token-table td:nth-child(1) {
                width: 25%;
                max-width: 80px;
                word-break: break-word;
            }
            
            .token-table th:nth-child(4), /* 已分配 */
            .token-table td:nth-child(4) {
                width: 15%;
                text-align: center;
            }

            .token-table th:nth-child(7), /* 过期时间 */
            .token-table td:nth-child(7) {
                width: 35%;
                text-align: center;
            }

            .token-table th:nth-child(8), /* 操作 */
            .token-table td:nth-child(8) {
                width: 25%;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 3px;
            }
            
            .btn-small {
                padding: 6px 8px;
                font-size: 11px;
                text-align: center;
                white-space: nowrap;
            }
            
            .status-badge {
                font-size: 10px;
                padding: 3px 6px;
            }
            
            /* 表格容器水平滚动 */
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }
        
        /* Token显示模态框 */
        .token-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.7);
        }
        
        .token-modal-content {
            background: var(--color-panel);
            margin: 10% auto;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            border: 1px solid var(--color-border);
        }
        
        .token-modal h3 {
            color: var(--color-success);
            margin-bottom: 20px;
            text-align: center;
        }
        
        .token-modal p {
            color: var(--color-text);
            margin-bottom: 15px;
            text-align: center;
        }
        
        .token-display {
            background: var(--color-panel-light);
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 14px;
            word-break: break-all;
            margin: 20px 0;
            border: 2px solid var(--color-success);
            text-align: center;
            color: var(--color-primary);
        }
        
        .token-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .btn-copy-token {
            background: var(--color-primary);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-copy-token:hover {
            opacity: 0.8;
        }
        
        .btn-close-modal {
            background: var(--color-muted);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-close-modal:hover {
            opacity: 0.8;
        }
        
        .copy-success {
            color: var(--color-success);
            font-size: 12px;
            margin-top: 10px;
            text-align: center;
            display: none;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>🔑 Token 管理</h1>
                    <p>Token授权管理</p>
                </div>
                <?php if (Auth::isLoginEnabled()): ?>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-row">
                            <div class="username">👤 <?php echo htmlspecialchars(Auth::getCurrentUser()); ?></div>
                            <a href="#" class="logout-btn" onclick="event.preventDefault(); showCustomConfirm('确定要退出登录吗？', () => window.location.href='index.php?action=logout'); return false;">退出</a>
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
            <a href="api_demo.php" class="nav-link">API示例</a>
        </div>
    </div>

    <div class="container">
        <!-- 创建Token表单 -->
        <div class="section">
            <h3>创建新Token</h3>
            <div class="create-token-form">
                <form id="create-token-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="token-name">Token名称</label>
                            <input type="text" id="token-name" name="name" placeholder="例如：客户A的代理授权" required>
                        </div>
                        <div class="form-group">
                            <label for="proxy-count">代理数量</label>
                            <input type="number" id="proxy-count" name="proxy_count" min="1" max="1000" value="10" required>
                        </div>
                        <div class="form-group">
                            <label for="expiry-days">有效期（天）</label>
                            <select id="expiry-days" name="expiry_days">
                                <option value="7">7天</option>
                                <option value="30" selected>30天</option>
                                <option value="90">90天</option>
                                <option value="365">1年</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-success">创建Token</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

            <!-- Token列表 -->
            <div class="section">
                <h3>现有Token列表</h3>
                <div class="table-container">
                    <table class="token-table">
                        <thead>
                            <tr>
                                <th>名称</th>
                                <th>Token值</th>
                                <th>代理数量</th>
                                <th>已分配</th>
                                <th>状态</th>
                                <th>创建时间</th>
                                <th>过期时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="token-list">
                            <?php foreach ($tokens as $token): ?>
                            <tr data-token-id="<?php echo $token['id']; ?>">
                                <td><?php echo htmlspecialchars($token['name']); ?></td>
                                <td>
                                    <div class="token-value" title="<?php echo htmlspecialchars($token['token']); ?>">
                                        <?php echo htmlspecialchars(substr($token['token'], 0, 16) . '...'); ?>
                                    </div>
                                </td>
                                <td><?php echo $token['proxy_count']; ?></td>
                                <td><?php echo $token['assigned_count']; ?></td>
                                <td>
                                    <span class="status-badge <?php echo $token['is_valid'] ? 'status-valid' : 'status-expired'; ?>">
                                        <?php echo $token['is_valid'] ? '有效' : '已过期'; ?>
                                    </span>
                                </td>
                                <td><?php echo formatTime($token['created_at']); ?></td>
                                <td><?php echo formatTime($token['expires_at']); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-small btn-primary" onclick="copyToken('<?php echo htmlspecialchars($token['token']); ?>')">复制</button>
                                        <button class="btn-small btn-warning" onclick="refreshToken(<?php echo $token['id']; ?>)">刷新</button>
                                        <button class="btn-small btn-success" onclick="reassignProxies(<?php echo $token['id']; ?>, <?php echo $token['proxy_count']; ?>)">重分配</button>
                                        <button class="btn-small btn-danger" onclick="deleteToken(<?php echo $token['id']; ?>)">删除</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Token显示模态框 -->
    <div id="token-modal" class="token-modal">
        <div class="token-modal-content">
            <h3>🎉 Token创建成功！</h3>
            <p>请妥善保存以下Token，它不会再次显示：</p>
            <div class="token-display" id="token-display-text">
                <!-- Token值将在这里显示 -->
            </div>
            <div class="copy-success" id="copy-success">✅ Token已复制到剪贴板</div>
            <div class="token-actions">
                <button class="btn-copy-token" onclick="copyTokenFromModal()">📋 复制Token</button>
                <button class="btn-close-modal" onclick="closeTokenModal()">关闭</button>
            </div>
        </div>
    </div>

    <script>
        // 创建Token
        document.getElementById('create-token-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch('?ajax=1&action=create', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showTokenModal(result.token);
                } else {
                    alert('创建失败：' + result.message);
                }
            } catch (error) {
                alert('操作失败：' + error.message);
            }
        });

        // 复制Token
        function copyToken(token) {
            navigator.clipboard.writeText(token).then(function() {
                alert('Token已复制到剪贴板');
            }, function() {
                // 降级方案
                const textArea = document.createElement('textarea');
                textArea.value = token;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('Token已复制到剪贴板');
            });
        }

        // 刷新Token有效期
        async function refreshToken(tokenId) {
            const days = prompt('请输入新的有效期（天数）：', '30');
            if (!days || isNaN(days) || days < 1) return;
            
            const formData = new FormData();
            formData.append('token_id', tokenId);
            formData.append('expiry_days', days);
            
            try {
                const response = await fetch('?ajax=1&action=refresh', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(result.message);
                    location.reload();
                } else {
                    alert('操作失败：' + result.message);
                }
            } catch (error) {
                alert('操作失败：' + error.message);
            }
        }

        // 重新分配代理
        async function reassignProxies(tokenId, currentCount) {
            const count = prompt('请输入新的代理数量：', currentCount);
            if (!count || isNaN(count) || count < 1) return;
            
            if (!confirm('确定要重新分配代理吗？这将替换当前分配的所有代理。')) return;
            
            const formData = new FormData();
            formData.append('token_id', tokenId);
            formData.append('proxy_count', count);
            
            try {
                const response = await fetch('?ajax=1&action=reassign', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(result.message);
                    location.reload();
                } else {
                    alert('操作失败：' + result.message);
                }
            } catch (error) {
                alert('操作失败：' + error.message);
            }
        }

        // 删除Token
        async function deleteToken(tokenId) {
            if (!confirm('确定要删除这个Token吗？此操作不可撤销。')) return;
            
            const formData = new FormData();
            formData.append('token_id', tokenId);
            
            try {
                const response = await fetch('?ajax=1&action=delete', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(result.message);
                    location.reload();
                } else {
                    alert('操作失败：' + result.message);
                }
            } catch (error) {
                alert('操作失败：' + error.message);
            }
        }

        // 显示Token模态框
        let currentToken = '';
        function showTokenModal(token) {
            currentToken = token;
            document.getElementById('token-display-text').textContent = token;
            document.getElementById('token-modal').style.display = 'block';
            document.getElementById('copy-success').style.display = 'none';
        }

        // 关闭Token模态框
        function closeTokenModal() {
            document.getElementById('token-modal').style.display = 'none';
            location.reload(); // 刷新页面显示新创建的Token
        }

        // 从模态框复制Token
        function copyTokenFromModal() {
            if (!currentToken) return;
            
            navigator.clipboard.writeText(currentToken).then(function() {
                document.getElementById('copy-success').style.display = 'block';
                setTimeout(() => {
                    document.getElementById('copy-success').style.display = 'none';
                }, 3000);
            }, function() {
                // 降级方案
                const textArea = document.createElement('textarea');
                textArea.value = currentToken;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                document.getElementById('copy-success').style.display = 'block';
                setTimeout(() => {
                    document.getElementById('copy-success').style.display = 'none';
                }, 3000);
            });
        }

        // 点击模态框背景关闭
        document.getElementById('token-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTokenModal();
            }
        });

        // ESC键关闭模态框
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('token-modal').style.display === 'block') {
                closeTokenModal();
            }
        });
    </script>
    <!-- 新模块化JS -->
    <script src="includes/js/core.js?v=<?php echo time(); ?>"></script>
    <script src="includes/js/ui.js?v=<?php echo time(); ?>"></script>
    <script src="includes/utils.js?v=<?php echo time(); ?>"></script>
</body>
</html>