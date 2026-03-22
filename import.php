<?php
/**
 * 代理导入工具
 */

require_once 'config.php';
require_once 'includes/Config.php';
ensure_valid_config('web');

require_once 'auth.php';
require_once 'monitor.php';
require_once 'includes/Validator.php';
require_once 'includes/functions.php';
if (file_exists(__DIR__ . '/includes/AuditLogger.php')) {
    require_once __DIR__ . '/includes/AuditLogger.php';
}

// 检查登录状态
Auth::requireLogin();

$monitor = new NetworkMonitor();

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = null;
    $error = null;
    
    try {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Auth::validateCsrfToken($csrfToken)) {
            throw new Exception('CSRF验证失败，请刷新页面后重试');
        }

        if (isset($_POST['import_text']) && !empty($_POST['import_text'])) {
            // 从文本导入
            $importSource = 'text';
            $lines = explode("\n", trim($_POST['import_text']));
            $maxLines = defined('MAX_IMPORT_LINES') ? (int)MAX_IMPORT_LINES : 50000;
            if (count($lines) > $maxLines) {
                throw new Exception('导入内容过大，请分批导入');
            }
            $proxyList = [];
            $maxProxies = defined('MAX_IMPORT_PROXIES') ? (int)MAX_IMPORT_PROXIES : 50000;
            
            foreach ($lines as $lineNum => $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === '#') {
                    continue;
                }
                
                $parts = explode(':', $line);
                if (count($parts) < 3) {
                    continue;
                }

                $ip = trim($parts[0]);
                $port = (int)trim($parts[1]);
                $type = strtolower(trim($parts[2]));
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }
                if ($port < 1 || $port > 65535) {
                    continue;
                }
                if (!Validator::proxyType($type)) {
                    continue;
                }

                if (count($proxyList) >= $maxProxies) {
                    throw new Exception('导入代理数量过大，请分批导入');
                }
                
                $proxyList[] = [
                    'ip' => $ip,
                    'port' => $port,
                    'type' => $type,
                    'username' => isset($parts[3]) ? (trim($parts[3]) !== '' ? trim($parts[3]) : null) : null,
                    'password' => isset($parts[4]) ? (trim($parts[4]) !== '' ? trim($parts[4]) : null) : null
                ];
            }

            if (empty($proxyList)) {
                throw new Exception('未识别到有效的代理配置');
            }
            
            $result = $monitor->importProxies($proxyList, 'add');

            if ($result && class_exists('AuditLogger')) {
                AuditLogger::log('proxy_import', 'proxy', null, [
                    'source' => $importSource,
                    'total_parsed' => count($proxyList),
                    'imported' => $result['imported'] ?? null,
                    'skipped' => $result['skipped'] ?? null,
                    'errors' => isset($result['errors']) ? count($result['errors']) : null
                ]);
            }
            
        } elseif (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            // 从文件导入
            $importSource = 'file';
            $maxFileBytes = defined('MAX_IMPORT_FILE_BYTES') ? (int)MAX_IMPORT_FILE_BYTES : (10 * 1024 * 1024);
            if (!empty($_FILES['import_file']['size']) && (int)$_FILES['import_file']['size'] > $maxFileBytes) {
                throw new Exception('导入文件过大，请分批导入');
            }
            $tempFile = $_FILES['import_file']['tmp_name'];
            $result = $monitor->importFromFile($tempFile);

            if ($result && class_exists('AuditLogger')) {
                AuditLogger::log('proxy_import', 'proxy', null, [
                    'source' => $importSource,
                    'file_size' => (int)($_FILES['import_file']['size'] ?? 0),
                    'imported' => $result['imported'] ?? null,
                    'skipped' => $result['skipped'] ?? null,
                    'errors' => isset($result['errors']) ? count($result['errors']) : null
                ]);
            }
            
        } else {
            $error = '请提供要导入的代理数据';
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        if (class_exists('AuditLogger')) {
            AuditLogger::log('proxy_import_failed', 'proxy', null, [
                'error' => $error
            ]);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>代理导入 - NetWatch</title>
    <link rel="stylesheet" href="includes/style-v2.css?v=<?php echo filemtime(__DIR__ . '/includes/style-v2.css'); ?>">
    <style nonce="<?php echo htmlspecialchars(netwatch_get_csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
        /* 页面特有样式 */
        .section {
            background: var(--color-panel);
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid var(--color-border);
        }
        
        .section h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: var(--color-primary);
            font-size: 18px;
            font-weight: 600;
        }
        
        .section ul {
            margin: 0;
            padding-left: 20px;
            color: var(--color-text);
            line-height: 1.6;
        }
        
        .section li {
            margin-bottom: 10px;
        }
        
        .section li:last-child {
            margin-bottom: 0;
        }
        
        .import-option .form-group {
            margin-bottom: 0;
        }
        
        .import-option .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 500;
            color: var(--color-text);
        }
        
        .form-group textarea {
            width: 100%;
            height: 200px;
            resize: vertical;
            font-family: 'Courier New', monospace;
            display: block;
            background: var(--color-panel-light);
            color: var(--color-text);
            border: 1px solid var(--color-border);
            padding: 12px;
            border-radius: 4px;
        }
        
        .form-group input[type="file"] {
            width: 100%;
            display: block;
            padding: 12px;
            background: var(--color-panel-light);
            color: var(--color-text);
            border: 1px solid var(--color-border);
            border-radius: 4px;
            cursor: pointer;
        }
        
        .form-group input[type="file"]::file-selector-button {
            padding: 8px 16px;
            background: var(--color-primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            margin-right: 12px;
            transition: all 0.3s ease;
        }
        
        .form-group input[type="file"]::file-selector-button:hover {
            background: var(--color-primary-dark);
            transform: translateY(-1px);
        }
        
        .import-options {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .import-option {
            background: var(--color-panel);
            padding: 25px;
            border-radius: 8px;
            border: 1px solid var(--color-border);
        }
        
        .import-option h3 {
            font-size: 16px;
            margin-bottom: 15px;
            color: var(--color-primary);
            font-weight: 600;
        }
        
        .alert {
            padding: 20px 25px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid;
        }
        
        .alert h3, .alert h4 {
            margin-top: 0;
            margin-bottom: 15px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-color: var(--color-success);
            color: var(--color-text);
        }
        
        .alert-success h3 {
            color: var(--color-success);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border-color: var(--color-danger);
            color: var(--color-text);
        }
        
        .alert-error strong {
            color: var(--color-danger);
        }
        
        .help-text {
            font-size: 13px;
            color: var(--color-muted);
            margin-top: 8px;
            line-height: 1.5;
        }
        
        .import-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            color: var(--color-muted);
            padding: 10px 0;
            position: relative;
        }
        
        .import-divider::before,
        .import-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--color-border);
        }
        
        .import-divider::before {
            margin-right: 15px;
        }
        
        .import-divider::after {
            margin-left: 15px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            padding-top: 20px;
            border-top: 1px solid var(--color-border);
        }
        
        .example {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            border-left: 4px solid var(--color-primary);
            color: var(--color-text);
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: var(--color-panel);
            border-radius: 8px;
            border: 1px solid var(--color-border);
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: var(--color-primary);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--color-muted);
            margin-top: 5px;
        }
        
        .error-list {
            max-height: 200px;
            overflow-y: auto;
            background: rgba(255, 255, 255, 0.05);
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            border: 1px solid var(--color-border);
        }
        
        .error-item {
            padding: 5px 0;
            border-bottom: 1px solid var(--color-border);
            font-size: 13px;
            color: var(--color-text);
        }
        
        .error-item:last-child {
            border-bottom: none;
        }
        
        @media (max-width: 768px) {
            .import-options {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .import-divider {
                padding-top: 0;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>📥 代理导入</h1>
                    <p>导入代理服务器</p>
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
            <a href="import_subnets.php" class="nav-link">子网导入</a>
        </div>
    </div>
    
    <div class="container">
        
        <?php if (isset($result)): ?>
        <div class="alert alert-success">
            <h3>导入完成</h3>
            <div class="stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo $result['imported']; ?></div>
                    <div class="stat-label">成功导入</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo count($result['errors']); ?></div>
                    <div class="stat-label">导入失败</div>
                </div>
            </div>
            
            <?php if (!empty($result['errors'])): ?>
            <h4>错误详情:</h4>
            <div class="error-list">
                <?php foreach ($result['errors'] as $error): ?>
                <div class="error-item"><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
        <div class="alert alert-error">
            <strong>导入失败:</strong> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>导入代理配置</h2>
            
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::getCsrfToken()); ?>">
                <div class="import-options">
                    <div class="import-option">
                        <h3>选项 1: 从文本导入</h3>
                        <div class="form-group">
                            <label for="import_text">粘贴代理配置</label>
                            <textarea name="import_text" id="import_text" placeholder="请输入代理配置，每行一个..."></textarea>
                            <div class="help-text">
                                格式: IP:端口:类型:用户名:密码 (用户名和密码可选)
                            </div>
                            <div class="example">
示例:<br>
192.168.1.100:1080:socks5<br>
192.168.1.101:8080:http:username:password<br>
10.0.0.1:1080:socks5:user:pass<br>
# 这是注释行，会被忽略
                            </div>
                        </div>
                    </div>
                    
                    <div class="import-divider">或</div>
                    
                    <div class="import-option">
                        <h3>选项 2: 从文件导入</h3>
                        <div class="form-group">
                            <label for="import_file">选择文件</label>
                            <input type="file" name="import_file" id="import_file" accept=".txt,.csv">
                            <div class="help-text">
                                支持 .txt 和 .csv 文件，格式与上面相同
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn">开始导入</button>
                    <button type="button" class="btn btn-secondary" onclick="clearForm()">清空表单</button>
                </div>
            </form>
        </div>
        
        <div class="section">
            <h2>导入说明</h2>
            <ul>
                <li><strong>支持的代理类型:</strong> http, https, socks4, socks5</li>
                <li><strong>格式要求:</strong> 每行一个代理，使用冒号分隔各个字段</li>
                <li><strong>必需字段:</strong> IP地址、端口、类型</li>
                <li><strong>可选字段:</strong> 用户名、密码（用于需要认证的代理）</li>
                <li><strong>注释支持:</strong> 以 # 开头的行会被忽略</li>
                <li><strong>空行处理:</strong> 空行会被自动跳过</li>
                <li><strong>错误处理:</strong> 格式错误的行会被跳过，但不会影响其他行的导入</li>
            </ul>
        </div>
    </div>

    <form id="logout-form" method="POST" action="index.php?action=logout" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    </form>
    
    <script nonce="<?php echo htmlspecialchars(netwatch_get_csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
        function submitLogout() {
            document.getElementById('logout-form').submit();
        }

        function clearForm() {
            document.getElementById('import_text').value = '';
            document.getElementById('import_file').value = '';
        }
        
        // 文件选择时清空文本框
        document.getElementById('import_file').addEventListener('change', function() {
            if (this.files.length > 0) {
                document.getElementById('import_text').value = '';
            }
        });
        
        // 文本输入时清空文件选择
        document.getElementById('import_text').addEventListener('input', function() {
            if (this.value.trim()) {
                document.getElementById('import_file').value = '';
            }
        });
    </script>
    <!-- 新模块化JS -->
    <script src="includes/js/core.js?v=<?php echo filemtime(__DIR__ . '/includes/js/core.js'); ?>"></script>
    <script src="includes/js/ui.js?v=<?php echo filemtime(__DIR__ . '/includes/js/ui.js'); ?>"></script>
    <script src="includes/utils.js?v=<?php echo filemtime(__DIR__ . '/includes/utils.js'); ?>"></script>
</body>
</html>
