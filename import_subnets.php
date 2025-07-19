<?php
/**
 * 子网代理批量导入工具
 * 支持多个子网使用相同的端口/用户名/密码配置
 */

require_once 'config.php';
require_once 'monitor.php';

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
        
        $result = $monitor->importProxies($proxyList);
        $result['total_generated'] = $totalProxies;
        
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
    <title>子网代理导入 - NetWatch</title>
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
            max-width: 1000px;
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
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 15px;
            align-items: end;
        }
        
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            margin-right: 10px;
        }
        
        .btn:hover {
            background: #5a6fd8;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-add {
            background: #28a745;
            padding: 8px 16px;
            font-size: 12px;
        }
        
        .btn-add:hover {
            background: #218838;
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
        
        .subnet-item {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            background: #f8f9fa;
        }
        
        .subnet-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .subnet-title {
            font-weight: 600;
            color: #495057;
        }
        
        .ip-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .help-text {
            font-size: 13px;
            color: #666;
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
            background: #f8f9fa;
            border-radius: 8px;
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
        
        .example {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            font-size: 13px;
            margin-top: 10px;
            border-left: 4px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <h1>🌐 子网代理导入</h1>
            <p>批量导入多个子网的代理服务器配置</p>
        </div>
    </div>
    
    <div class="container">
        <div class="nav">
            <a href="index.php">← 返回监控面板</a>
            <a href="import.php">单个代理导入</a>
        </div>
        
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
                        <label for="username">用户名 (可选)</label>
                        <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="password">密码 (可选)</label>
                        <input type="password" name="password" id="password" value="<?php echo htmlspecialchars($_POST['password'] ?? ''); ?>">
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
            document.getElementById('subnetForm').reset();
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
