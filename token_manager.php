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
        .token-manager {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section h3 {
            margin-top: 0;
            margin-bottom: 20px;
            padding: 0 20px;
        }
        
        .create-token-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 0 20px 30px 20px;
        }
        
        .create-token-form h3 {
            margin-top: 0;
            margin-bottom: 20px;
            padding: 0;
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
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .table-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin: 0 20px;
        }
        
        .token-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .token-table th,
        .token-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .token-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .token-value {
            font-family: monospace;
            font-size: 12px;
            background: #f1f3f4;
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
            background: #d4edda;
            color: #155724;
        }
        
        .status-expired {
            background: #f8d7da;
            color: #721c24;
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
        
        .btn-primary { background: #007bff; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-success { background: #28a745; color: white; }
        
        .btn-small:hover {
            opacity: 0.8;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 10px;
            }
            
            .token-table {
                font-size: 12px;
            }
            
            .token-value {
                max-width: 120px;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 4px;
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
            background-color: rgba(0,0,0,0.5);
        }
        
        .token-modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .token-modal h3 {
            color: #28a745;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .token-display {
            background: #f8f9fa;
            border: 2px solid #28a745;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
            font-family: monospace;
            font-size: 14px;
            word-break: break-all;
            position: relative;
        }
        
        .token-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .btn-copy-token {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-copy-token:hover {
            background: #0056b3;
        }
        
        .btn-close-modal {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-close-modal:hover {
            background: #545b62;
        }
        
        .copy-success {
            color: #28a745;
            font-size: 12px;
            margin-top: 10px;
            text-align: center;
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔑 API Token 管理</h1>
            <div class="header-right">
                <a href="api_demo.php" class="btn btn-primary">API示例</a>
                <a href="index.php" class="btn btn-secondary">返回主页</a>
            </div>
        </header>

        <div class="token-manager">
            <!-- 创建Token表单 -->
            <div class="create-token-form">
                <h3>创建新的API Token</h3>
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
</body>
</html>
