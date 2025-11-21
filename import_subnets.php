<?php
/**
 * 子网代理批量导入工具
 * 支持多个子网使用相同的端口/用户名/密码配置
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'monitor.php';
require_once 'includes/functions.php';

// 检查登录状态
Auth::requireLogin();

$monitor = new NetworkMonitor();

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = null;
    $error = null;
    
    try {
        // 获取公共配置
        $port = (int)$_POST['port'];
        $type = $_POST['type'];
        $username = trim($_POST['username']) ?: null;
        $password = trim($_POST['password']) ?: null;
        $importMode = $_POST['import_mode'] ?? 'skip';
        
        if (!$port || !in_array($type, ['socks5', 'http'])) {
            throw new Exception('请填写有效的端口和代理类型');
        }
        
        $proxyList = [];
        $totalProxies = 0;
        
        // 处理每个子网
        for ($i = 1; $i <= 20; $i++) {
            $startIp = trim($_POST["start_ip_$i"] ?? '');
            $endIp = trim($_POST["end_ip_$i"] ?? '');
            
            if (empty($startIp) || empty($endIp)) {
                continue;
            }
            
            // 验证IP格式
            if (!filter_var($startIp, FILTER_VALIDATE_IP) || !filter_var($endIp, FILTER_VALIDATE_IP)) {
                throw new Exception("子网 $i: IP地址格式无效");
            }
            
            // 生成IP范围内的所有代理
            $startLong = ip2long($startIp);
            $endLong = ip2long($endIp);
            
            if ($startLong > $endLong) {
                throw new Exception("子网 $i: 开始IP不能大于结束IP");
            }
            
            for ($ipLong = $startLong; $ipLong <= $endLong; $ipLong++) {
                $ip = long2ip($ipLong);
                $proxyList[] = [
                    'ip' => $ip,
                    'port' => $port,
                    'type' => $type,
                    'username' => $username,
                    'password' => $password
                ];
                $totalProxies++;
            }
        }
        
        if (empty($proxyList)) {
            throw new Exception('请至少配置一个子网');
        }
        
        $result = $monitor->importProxies($proxyList, $importMode);
        $result['total_generated'] = $totalProxies;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>子网代理导入 - NetWatch</title>
    <link rel="stylesheet" href="includes/style-v2.css?v=<?php echo time(); ?>">
    <style>
        /* 页面特有样式 - 表单和子网配置 */
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row .form-group {
            margin-bottom: 0;
        }
        
        .form-row .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--color-text);
        }
        
        .form-row .form-group input,
        .form-row .form-group select {
            width: 100%;
        }
        
        .btn-add {
            background: var(--color-success);
            padding: 8px 16px;
            font-size: 12px;
        }
        
        .btn-add:hover {
            background: #0ea572;
        }
        
        .subnet-item {
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            background: var(--color-panel);
        }
        
        .subnet-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .subnet-title {
            font-weight: 600;
            color: var(--color-text);
        }
        
        .ip-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .help-text {
            font-size: 13px;
            color: var(--color-muted);
            margin-top: 5px;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
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
        
        .example {
            background: var(--color-panel);
            padding: 10px;
            border-radius: 5px;
            font-size: 13px;
            margin-top: 10px;
            border-left: 4px solid var(--color-primary);
            color: var(--color-text);
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>🌐 子网代理导入</h1>
                    <p>批量导入子网的代理服务器配置</p>
                </div>
                <?php if (Auth::isLoginEnabled()): ?>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-row">
                            <div class="username">👤 <?php echo htmlspecialchars(Auth::getCurrentUser()); ?></div>
                            <a href="index.php?action=logout" class="logout-btn" onclick="return confirm('确定要退出登录吗？')">退出</a>
                        </div>
                        <div class="session-time">登录时间：<?php 
                            $loginTime = Auth::getLoginTime();
                            echo $loginTime ? date('m-d H:i', $loginTime) : 'N/A';
                        ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 导航链接 -->
    <div class="container">
        <div class="nav-links">
            <a href="index.php" class="nav-link">🏠 主页</a>
            <a href="import.php" class="nav-link">📥 代理导入</a>
            <a href="import_subnets.php" class="nav-link active">🌐 子网导入</a>
            <a href="token_manager.php" class="nav-link">🔑 Token管理</a>
            <a href="api_demo.php" class="nav-link">📖 API示例</a>
            <a href="proxy-status/" class="nav-link">📊 流量监控</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($result)): ?>
        <div class="alert alert-success">
            <h3>导入完成</h3>
            <div class="stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo $result['total_generated']; ?></div>
                    <div class="stat-label">生成代理</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $result['imported']; ?></div>
                    <div class="stat-label">成功导入</div>
                </div>
                <?php if (isset($result['skipped']) && $result['skipped'] > 0): ?>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $result['skipped']; ?></div>
                    <div class="stat-label">跳过重复</div>
                </div>
                <?php endif; ?>
                <div class="stat-item">
                    <div class="stat-number"><?php echo count($result['errors']); ?></div>
                    <div class="stat-label">导入失败</div>
                </div>
            </div>
            
            <?php if (!empty($result['errors'])): ?>
            <h4>错误详情:</h4>
            <div style="max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 10px;">
                <?php foreach ($result['errors'] as $error): ?>
                <div style="padding: 5px 0; border-bottom: 1px solid #e9ecef; font-size: 13px;"><?php echo htmlspecialchars($error); ?></div>
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
        
        <form method="post" id="subnetForm">
            <div class="section">
                <h2>公共配置</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="port">端口</label>
                        <input type="number" name="port" id="port" value="<?php echo $_POST['port'] ?? '1080'; ?>" required min="1" max="65535">
                    </div>
                    <div class="form-group">
                        <label for="type">代理类型</label>
                        <select name="type" id="type" required>
                            <option value="socks5" <?php echo ($_POST['type'] ?? '') === 'socks5' ? 'selected' : ''; ?>>SOCKS5</option>
                            <option value="http" <?php echo ($_POST['type'] ?? '') === 'http' ? 'selected' : ''; ?>>HTTP</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="username">用户名</label>
                        <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="password">密码</label>
                        <input type="password" name="password" id="password" value="<?php echo htmlspecialchars($_POST['password'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="import_mode">导入模式</label>
                    <select name="import_mode" id="import_mode" required>
                        <option value="skip" <?php echo ($_POST['import_mode'] ?? 'skip') === 'skip' ? 'selected' : ''; ?>>跳过模式 - 跳过已存在的代理 (推荐)</option>
                        <option value="add" <?php echo ($_POST['import_mode'] ?? '') === 'add' ? 'selected' : ''; ?>>新增模式 - 添加到现有代理列表</option>
                    </select>
                    <div class="help-text">
                        新增模式：所有代理都会被添加，可能产生重复记录<br>
                        跳过模式：如果相同IP:端口已存在，则跳过不导入
                    </div>
                </div>
                <div class="help-text">
                    所有子网将使用相同的端口、类型、用户名和密码配置
                </div>
            </div>
            
            <div class="section">
                <h2>子网配置</h2>
                <div id="subnets-container">
                    <?php
                    // 默认显示9个子网配置输入框，支持任意子网大小（/27、/28、/29等）
                    // 只需设置开始和结束IP，系统会自动生成范围内所有代理配置
                    
                    for ($i = 1; $i <= 9; $i++):
                        $startIp = $_POST["start_ip_$i"] ?? '';
                        $endIp = $_POST["end_ip_$i"] ?? '';
                    ?>
                    <div class="subnet-item">
                        <div class="subnet-header">
                            <div class="subnet-title">子网 <?php echo $i; ?></div>
                        </div>
                        <div class="ip-inputs">
                            <div class="form-group">
                                <label for="start_ip_<?php echo $i; ?>">开始IP</label>
                                <input type="text" name="start_ip_<?php echo $i; ?>" id="start_ip_<?php echo $i; ?>" 
                                       value="<?php echo htmlspecialchars($startIp); ?>" 
                                       placeholder="如: 1.2.3.2">
                            </div>
                            <div class="form-group">
                                <label for="end_ip_<?php echo $i; ?>">结束IP</label>
                                <input type="text" name="end_ip_<?php echo $i; ?>" id="end_ip_<?php echo $i; ?>" 
                                       value="<?php echo htmlspecialchars($endIp); ?>" 
                                       placeholder="如: 1.2.3.30">
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                
                <button type="button" class="btn btn-add" onclick="addSubnet()">+ 添加新子网</button>
            </div>
            
            <div class="section">
                <button type="submit" class="btn">开始导入</button>
                <button type="button" class="btn btn-secondary" onclick="clearForm()">清空表单</button>
                <button type="button" class="btn btn-secondary" onclick="previewProxies()">预览代理数量</button>
            </div>
        </form>
        
        <div class="section">
            <h2>使用说明</h2>
            <ul style="line-height: 1.6; margin-left: 20px;">
                <li><strong>公共配置:</strong> 所有子网使用相同的端口、类型、用户名和密码</li>
                <li><strong>灵活的子网支持:</strong> 支持任意子网大小（/27、/28、/29等）和任意IP范围</li>
                <li><strong>IP范围配置:</strong> 只需设置开始和结束IP，系统自动生成范围内所有代理</li>
                <li><strong>公网/内网兼容:</strong> 支持公网IP和内网IP，不限制网络类型</li>
                <li><strong>动态扩展:</strong> 默认9个子网输入框，可添加更多</li>
                <li><strong>预览功能:</strong> 导入前可以预览将生成的代理数量</li>
            </ul>
            
            <div class="example">
                <strong>子网示例:</strong><br>
                • <strong>/27子网:</strong> 32个地址，可用IP范围如 x.x.x.2-30（29个）<br>
                • <strong>/28子网:</strong> 16个地址，可用IP范围如 x.x.x.2-14（13个）<br>
                • <strong>/29子网:</strong> 8个地址，可用IP范围如 x.x.x.2-6（5个）<br>
                • <strong>自定义范围:</strong> 也可以不按子网划分，直接指定任意IP范围<br><br>
                <strong>实际场景示例:</strong><br>
                • <strong>场景1:</strong> 8个/27子网，每个29个IP = 232个代理<br>
                • <strong>场景2:</strong> 16个/28子网，每个13个IP = 208个代理<br>
                • <strong>场景3:</strong> 混合子网大小，根据实际部署灵活配置<br>
                使用此工具，只需配置一次公共信息，然后设置各子网IP范围即可批量导入。
            </div>
        </div>
    </div>
    
    <script>
        let subnetCount = 9;
        
        function addSubnet() {
            subnetCount++;
            const container = document.getElementById('subnets-container');
            const subnetHtml = `
                <div class="subnet-item">
                    <div class="subnet-header">
                        <div class="subnet-title">子网 ${subnetCount}</div>
                    </div>
                    <div class="ip-inputs">
                        <div class="form-group">
                            <label for="start_ip_${subnetCount}">开始IP</label>
                            <input type="text" name="start_ip_${subnetCount}" id="start_ip_${subnetCount}" 
                                   placeholder="如: x.x.x.2">
                        </div>
                        <div class="form-group">
                            <label for="end_ip_${subnetCount}">结束IP</label>
                            <input type="text" name="end_ip_${subnetCount}" id="end_ip_${subnetCount}" 
                                   placeholder="如: x.x.x.30">
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', subnetHtml);
        }
        
        function clearForm() {
            // 重置表单基本字段
            document.getElementById('port').value = '1080';
            document.getElementById('type').value = 'socks5';
            document.getElementById('username').value = '';
            document.getElementById('password').value = '';
            document.getElementById('import_mode').value = 'skip';
            
            // 清空所有子网输入框（包括原始的9个和动态添加的）
            for (let i = 1; i <= subnetCount; i++) {
                const startInput = document.getElementById(`start_ip_${i}`);
                const endInput = document.getElementById(`end_ip_${i}`);
                if (startInput) startInput.value = '';
                if (endInput) endInput.value = '';
            }
            
            // 重置子网计数器到初始状态
            subnetCount = 9;
            
            // 移除动态添加的子网输入框
            const container = document.getElementById('subnets-container');
            const subnetItems = container.querySelectorAll('.subnet-item');
            // 保留前9个，删除其余的
            for (let i = 9; i < subnetItems.length; i++) {
                subnetItems[i].remove();
            }
        }
        
        function previewProxies() {
            let totalProxies = 0;
            let validSubnets = 0;
            
            for (let i = 1; i <= subnetCount; i++) {
                const startIp = document.getElementById(`start_ip_${i}`)?.value.trim();
                const endIp = document.getElementById(`end_ip_${i}`)?.value.trim();
                
                if (startIp && endIp) {
                    try {
                        const startLong = ipToLong(startIp);
                        const endLong = ipToLong(endIp);
                        
                        if (startLong <= endLong) {
                            const count = endLong - startLong + 1;
                            totalProxies += count;
                            validSubnets++;
                        }
                    } catch (e) {
                        // 忽略无效IP
                    }
                }
            }
            
            alert(`预览结果:\n有效子网: ${validSubnets} 个\n将生成代理: ${totalProxies} 个`);
        }
        
        function ipToLong(ip) {
            const parts = ip.split('.');
            if (parts.length !== 4) throw new Error('Invalid IP');
            
            return (parseInt(parts[0]) << 24) + 
                   (parseInt(parts[1]) << 16) + 
                   (parseInt(parts[2]) << 8) + 
                   parseInt(parts[3]);
        }
    </script>
</body>
</html>