<?php
/**
 * 代理导入工具
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'monitor.php';

// 检查登录状态
Auth::requireLogin();

$monitor = new NetworkMonitor();

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = null;
    $error = null;
    
    try {
        if (isset($_POST['import_text']) && !empty($_POST['import_text'])) {
            // 从文本导入
            $lines = explode("\n", trim($_POST['import_text']));
            $proxyList = [];
            
            foreach ($lines as $lineNum => $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === '#') {
                    continue;
                }
                
                $parts = explode(':', $line);
                if (count($parts) < 3) {
                    continue;
                }
                
                $proxyList[] = [
                    'ip' => $parts[0],
                    'port' => (int)$parts[1],
                    'type' => $parts[2],
                    'username' => $parts[3] ?? null,
                    'password' => $parts[4] ?? null
                ];
            }
            
            $result = $monitor->importProxies($proxyList, 'add');
            
        } elseif (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            // 从文件导入
            $tempFile = $_FILES['import_file']['tmp_name'];
            $result = $monitor->importFromFile($tempFile);
            
        } else {
            $error = '请提供要导入的代理数据';
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>代理导入 - NetWatch</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            color: #333;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .nav {
            margin: 20px 0;
        }
        
        .nav a {
            color: #667eea;
            text-decoration: none;
            margin-right: 20px;
            font-weight: 500;
        }
        
        .nav a:hover {
            text-decoration: underline;
        }
        
        .section {
            background: white;
            margin: 20px 0;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        
        .section h2 {
            margin-bottom: 20px;
            color: #333;
            font-size: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .form-group textarea {
            height: 200px;
            resize: vertical;
            font-family: 'Courier New', monospace;
        }
        
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #5a6fd8;
        }
        
        .btn-secondary {
            background: #6c757d;
            margin-left: 10px;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }
        
        .example {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            border-left: 4px solid #667eea;
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
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .error-list {
            max-height: 200px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .error-item {
            padding: 5px 0;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .error-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <h1>📥 代理导入</h1>
            <p>批量导入代理服务器配置</p>
        </div>
    </div>
    
    <div class="container">
        <div class="nav">
            <a href="index.php">← 返回监控面板</a>
        </div>
        
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
                <div class="form-group">
                    <label for="import_text">从文本导入</label>
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
                
                <div class="form-group">
                    <label for="import_file">或从文件导入</label>
                    <input type="file" name="import_file" id="import_file" accept=".txt,.csv">
                    <div class="help-text">
                        支持 .txt 和 .csv 文件，格式与上面相同
                    </div>
                </div>
                
                <button type="submit" class="btn">开始导入</button>
                <button type="button" class="btn btn-secondary" onclick="clearForm()">清空表单</button>
            </form>
        </div>
        
        <div class="section">
            <h2>导入说明</h2>
            <ul style="line-height: 1.6; margin-left: 20px;">
                <li><strong>支持的代理类型:</strong> socks5, http</li>
                <li><strong>格式要求:</strong> 每行一个代理，使用冒号分隔各个字段</li>
                <li><strong>必需字段:</strong> IP地址、端口、类型</li>
                <li><strong>可选字段:</strong> 用户名、密码（用于需要认证的代理）</li>
                <li><strong>注释支持:</strong> 以 # 开头的行会被忽略</li>
                <li><strong>空行处理:</strong> 空行会被自动跳过</li>
                <li><strong>错误处理:</strong> 格式错误的行会被跳过，但不会影响其他行的导入</li>
            </ul>
        </div>
    </div>
    
    <script>
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
</body>
</html>
