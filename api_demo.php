<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'database.php';
require_once 'includes/functions.php';

// 强制登录检查
Auth::requireLogin();

$db = new Database();
$db->initializeSchema();
$tokens = $db->getAllTokens();

$baseUrl = $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API 使用示例 - NetWatch</title>
    <link rel="stylesheet" href="includes/style-v2.css?v=<?php echo filemtime(__DIR__ . '/includes/style-v2.css'); ?>">
    <style nonce="<?php echo htmlspecialchars(netwatch_get_csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
        /* 使用全局section样式，只定义页面特有的样式 */
        .section {
            padding: 25px;
        }
        
        .section h3 {
            border-bottom: 2px solid var(--color-primary);
            padding-bottom: 10px;
            margin-top: 0;
        }
        
        .endpoint-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--color-border);
            border-radius: 4px;
            padding: 15px;
            margin: 10px 0;
        }
        
        .endpoint-box strong {
            color: var(--color-text);
        }
        
        .endpoint-url {
            font-family: 'Courier New', monospace;
            background: rgba(255, 255, 255, 0.1);
            color: var(--color-primary);
            padding: 8px 12px;
            border-radius: 4px;
            margin: 8px 0;
            word-break: break-all;
        }
        
        .code-block {
            background: #1a202c;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 10px 0;
            border: 1px solid var(--color-border);
        }
        
        .test-form {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            border: 1px solid var(--color-border);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--color-text);
        }
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            background: var(--color-panel-light);
            color: var(--color-text);
        }
        
        .response-area {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--color-border);
            padding: 15px;
            border-radius: 4px;
            min-height: 100px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
            color: var(--color-text);
        }
        
        .btn-test {
            background: var(--color-success);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-test:hover {
            background: #0ea572;
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-success { background: var(--color-success); }
        .status-error { background: var(--color-danger); }
        .status-warning { background: var(--color-warning); }
        
        .format-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .format-tab {
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--color-border);
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            color: var(--color-text);
        }
        
        .format-tab.active {
            background: var(--color-primary);
            color: white;
            border-color: var(--color-primary);
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>📖 API 使用示例</h1>
                    <p>API接口文档与测试</p>
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
            <a href="token_manager.php" class="nav-link">Token管理</a>
        </div>
    </div>

    <div class="container">
        <!-- API概述 -->
        <div class="section">
            <h3>📖 API 概述</h3>
            <p>NetWatch提供RESTful API接口，允许通过Token授权获取代理服务器信息。API支持多种格式输出，适用于各种应用场景。</p>
            
            <div class="endpoint-box">
                <strong>基础URL:</strong>
                <div class="endpoint-url"><?php echo $baseUrl; ?>/api.php</div>
            </div>
        </div>

        <!-- 认证方式 -->
        <div class="section">
            <h3>🔐 认证方式</h3>
            <p>API推荐使用 Authorization 请求头；POST token 仅兼容旧客户端且通常默认关闭：</p>
            
            <div class="endpoint-box">
                <strong>1. POST参数（兼容模式，可关闭）:</strong>
                <div class="code-block">curl -X POST -d "token=YOUR_TOKEN" "<?php echo $baseUrl; ?>/api.php?action=proxies"</div>
            </div>
            
            <div class="endpoint-box">
                <strong>2. Authorization头（推荐）:</strong>
                <div class="code-block">curl -H "Authorization: Bearer YOUR_TOKEN" "<?php echo $baseUrl; ?>/api.php?action=proxies"</div>
            </div>
        </div>

        <!-- 在线测试工具 -->
        <div class="section">
            <h3>🧪 测试工具</h3>
            
            <div class="test-form">
                <div class="form-group">
                    <label for="test-token">选择Token:</label>
                    <select id="test-token">
                        <option value="">请选择一个Token</option>
                        <?php foreach ($tokens as $token): ?>
                            <?php if ($token['is_valid']): ?>
                                <option value="<?php echo htmlspecialchars($token['token']); ?>">
                                    <?php echo htmlspecialchars($token['name']); ?> (<?php echo $token['proxy_count']; ?>个代理)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>API端点:</label>
                    <div class="format-tabs">
                        <button class="format-tab active" data-action="proxies">获取代理列表</button>
                        <button class="format-tab" data-action="info">Token信息</button>
                        <button class="format-tab" data-action="status">状态统计</button>
                        <button class="format-tab" data-action="help">帮助信息</button>
                    </div>
                </div>
                
                <div class="form-group" id="format-group">
                    <label>输出格式:</label>
                    <div class="format-tabs">
                        <button class="format-tab active" data-format="json">JSON</button>
                        <button class="format-tab" data-format="txt">代理URL</button>
                        <button class="format-tab" data-format="list">简单列表</button>
                    </div>
                </div>
                
                <button class="btn-test" onclick="testApi()">🚀 测试API</button>
            </div>
            
            <div class="form-group">
                <label>请求URL:</label>
                <div class="endpoint-url" id="request-url">选择Token和操作后显示（Token 将通过 Authorization 头发送）</div>
            </div>
            
            <div class="form-group">
                <label>响应结果:</label>
                <div class="response-area" id="response-area">点击"测试API"按钮查看响应结果</div>
            </div>
        </div>

        <!-- 代码示例 -->
        <div class="section">
            <h3>💻 代码示例</h3>
            
            <h4>PHP示例:</h4>
            <div class="code-block">
<?php echo htmlspecialchars('<?php
$token = "YOUR_TOKEN_HERE";
$url = "' . $baseUrl . '/api.php?action=proxies";
$opts = [
    "http" => [
        "method" => "GET",
        "header" => "Authorization: Bearer {$token}\r\n"
    ]
];

$response = file_get_contents($url, false, stream_context_create($opts));
$data = json_decode($response, true);

if ($data["success"]) {
    foreach ($data["data"]["proxies"] as $proxy) {
        echo $proxy["type"] . "://" . $proxy["host"] . ":" . $proxy["port"] . "\n";
    }
} else {
    echo "错误: " . $data["error"];
}
?>'); ?>
            </div>
            
            <h4>Python示例:</h4>
            <div class="code-block">
import requests
import json

token = "YOUR_TOKEN_HERE"
url = "<?php echo $baseUrl; ?>/api.php"

# 推荐方式: Authorization头
headers = {"Authorization": f"Bearer {token}"}
response = requests.get(url, params={"action": "proxies"}, headers=headers)

if response.status_code == 200:
    data = response.json()
    if data["success"]:
        for proxy in data["data"]["proxies"]:
            print(f"{proxy['type']}://{proxy['host']}:{proxy['port']}")
    else:
        print(f"错误: {data['error']}")
else:
    print(f"HTTP错误: {response.status_code}")
            </div>
            
            <h4>JavaScript示例:</h4>
            <div class="code-block">
const token = "YOUR_TOKEN_HERE";
const url = "<?php echo $baseUrl; ?>/api.php";

// 使用fetch API + Authorization头
fetch(`${url}?action=proxies`, {
    headers: {
        "Authorization": `Bearer ${token}`
    }
})
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            data.data.proxies.forEach(proxy => {
                console.log(`${proxy.type}://${proxy.host}:${proxy.port}`);
            });
        } else {
            console.error("错误:", data.error);
        }
    })
    .catch(error => console.error("请求失败:", error));
            </div>
            
            <h4>curl示例:</h4>
            <div class="code-block">
# 使用Authorization头
curl -H "Authorization: Bearer YOUR_TOKEN" "<?php echo $baseUrl; ?>/api.php?action=proxies"

# 文本格式
curl -H "Authorization: Bearer YOUR_TOKEN" "<?php echo $baseUrl; ?>/api.php?action=proxies&format=txt"

# POST参数
# 仅在服务端启用 API_ALLOW_POST_TOKEN 时可用
curl -X POST -d "token=YOUR_TOKEN" "<?php echo $baseUrl; ?>/api.php?action=proxies"
            </div>
        </div>

        <!-- API端点详情 -->
        <div class="section">
            <h3>📋 API端点详情</h3>
            
            <div class="endpoint-box">
                <h4>1. 获取代理列表</h4>
                <div class="endpoint-url">GET /api.php?action=proxies[&format=FORMAT]</div>
                <p><strong>参数:</strong></p>
                <ul>
                    <li><code>Authorization</code> 请求头（推荐）: <code>Bearer TOKEN</code></li>
                    <li><code>token</code> POST参数: 兼容支持（可能默认关闭）</li>
                    <li><code>format</code> (可选): json(默认) | txt | list</li>
                </ul>
                <p><strong>返回:</strong> 授权的代理服务器列表</p>
            </div>
            
            <div class="endpoint-box">
                <h4>2. 获取Token信息</h4>
                <div class="endpoint-url">GET /api.php?action=info</div>
                <p><strong>认证:</strong> <code>Authorization: Bearer TOKEN</code> 或 POST 参数 <code>token</code></p>
                <p><strong>返回:</strong> Token的基本信息和统计数据</p>
            </div>
            
            <div class="endpoint-box">
                <h4>3. 获取状态统计</h4>
                <div class="endpoint-url">GET /api.php?action=status[&proxy_id=ID]</div>
                <p><strong>参数:</strong></p>
                <ul>
                    <li><code>Authorization</code> 请求头（推荐）: <code>Bearer TOKEN</code></li>
                    <li><code>token</code> POST参数: 兼容支持（可能默认关闭）</li>
                    <li><code>proxy_id</code> (可选): 特定代理的ID</li>
                </ul>
                <p><strong>返回:</strong> 代理状态统计信息</p>
            </div>
            
            <div class="endpoint-box">
                <h4>4. 帮助信息</h4>
                <div class="endpoint-url">GET /api.php?action=help</div>
                <p><strong>返回:</strong> API使用说明和端点列表</p>
            </div>
        </div>
    </div>

    <script nonce="<?php echo htmlspecialchars(netwatch_get_csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
        let currentAction = 'proxies';
        let currentFormat = 'json';
        
        // 切换操作
        document.querySelectorAll('[data-action]').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('[data-action]').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                currentAction = this.dataset.action;
                
                // 根据操作显示/隐藏格式选择
                const formatGroup = document.getElementById('format-group');
                if (currentAction === 'proxies') {
                    formatGroup.style.display = 'block';
                } else {
                    formatGroup.style.display = 'none';
                }
                
                updateRequestUrl();
            });
        });
        
        // 切换格式
        document.querySelectorAll('[data-format]').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('[data-format]').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                currentFormat = this.dataset.format;
                updateRequestUrl();
            });
        });
        
        // Token选择变化
        document.getElementById('test-token').addEventListener('change', updateRequestUrl);
        
        // 更新请求URL显示
        function updateRequestUrl() {
            const token = document.getElementById('test-token').value;
            const baseUrl = '<?php echo $baseUrl; ?>/api.php';
            
            if (!token) {
                document.getElementById('request-url').textContent = '请先选择Token';
                return;
            }
            
            let url = `${baseUrl}?action=${currentAction}`;
            if (currentAction === 'proxies' && currentFormat !== 'json') {
                url += `&format=${currentFormat}`;
            }
            
            document.getElementById('request-url').textContent = `${url} (Authorization: Bearer <selected-token>)`;
        }
        
        // 测试API
        async function testApi() {
            const token = document.getElementById('test-token').value;
            const responseArea = document.getElementById('response-area');
            
            if (!token) {
                responseArea.textContent = '请先选择一个Token';
                return;
            }
            
            responseArea.textContent = '正在请求...';
            
            try {
                let url = `api.php?action=${currentAction}`;
                if (currentAction === 'proxies' && currentFormat !== 'json') {
                    url += `&format=${currentFormat}`;
                }
                
                const response = await fetch(url, {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                const contentType = response.headers.get('content-type');
                
                let result;
                if (contentType && contentType.includes('application/json')) {
                    result = await response.json();
                    responseArea.textContent = JSON.stringify(result, null, 2);
                } else {
                    result = await response.text();
                    responseArea.textContent = result;
                }
                
                // 添加状态指示器
                const statusIndicator = response.ok ? 
                    '<span class="status-indicator status-success"></span>请求成功\n\n' :
                    '<span class="status-indicator status-error"></span>请求失败\n\n';
                
                responseArea.innerHTML = statusIndicator + responseArea.textContent;
                
            } catch (error) {
                responseArea.innerHTML = '<span class="status-indicator status-error"></span>请求失败\n\n' + error.message;
            }
        }
        
        // 初始化
        updateRequestUrl();
    </script>
    <form id="logout-form" method="POST" action="index.php?action=logout" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    </form>
    <script nonce="<?php echo htmlspecialchars(netwatch_get_csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
        function submitLogout() {
            document.getElementById('logout-form').submit();
        }
    </script>
    <!-- 新模块化JS -->
    <script src="includes/js/core.js?v=<?php echo time(); ?>"></script>
    <script src="includes/js/ui.js?v=<?php echo time(); ?>"></script>
    <script src="includes/utils.js?v=<?php echo time(); ?>"></script>
</body>
</html>
