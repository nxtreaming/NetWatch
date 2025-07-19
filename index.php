<?php
/**
 * NetWatch Web 界面
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'monitor.php';

// 检查登录状态
Auth::requireLogin();

$monitor = new NetworkMonitor();
$action = $_GET['action'] ?? 'dashboard';

// 处理登出请求
if ($action === 'logout') {
    Auth::logout();
    header('Location: login.php?action=logout');
    exit;
}

// 处理AJAX请求
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'stats':
            echo json_encode($monitor->getStats());
            break;
            
        case 'check':
            $proxyId = $_GET['proxy_id'] ?? null;
            if ($proxyId) {
                $proxy = $monitor->getProxyById($proxyId);
                if ($proxy) {
                    $result = $monitor->checkProxy($proxy);
                    echo json_encode($result);
                } else {
                    echo json_encode(['error' => '代理不存在']);
                }
            } else {
                echo json_encode(['error' => '缺少代理ID']);
            }
            break;
            
        case 'logs':
            $logs = $monitor->getRecentLogs(50);
            echo json_encode($logs);
            break;
            
        case 'checkAll':
            try {
                $results = $monitor->checkAllProxies();
                
                // 检查是否有需要发送警报的代理
                $failedProxies = $monitor->getFailedProxies();
                $emailSent = false;
                
                if (!empty($failedProxies)) {
                    try {
                        // 初始化邮件发送器
                        if (file_exists('vendor/autoload.php')) {
                            require_once 'mailer.php';
                            $mailer = new Mailer();
                        } else {
                            require_once 'mailer_simple.php';
                            $mailer = new SimpleMailer();
                        }
                        
                        $mailer->sendProxyAlert($failedProxies);
                        $emailSent = true;
                        
                        // 记录警报
                        foreach ($failedProxies as $proxy) {
                            $monitor->addAlert(
                                $proxy['id'],
                                'proxy_failure',
                                "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                            );
                        }
                    } catch (Exception $mailError) {
                        error_log('发送邮件失败: ' . $mailError->getMessage());
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => '所有代理检查完成',
                    'results' => $results,
                    'failed_proxies' => count($failedProxies),
                    'email_sent' => $emailSent
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '检查失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'getProxyCount':
            try {
                $count = $monitor->getProxyCount();
                echo json_encode([
                    'success' => true,
                    'count' => $count
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '获取代理数量失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'checkBatch':
            try {
                $offset = intval($_GET['offset'] ?? 0);
                $limit = intval($_GET['limit'] ?? 10);
                $results = $monitor->checkProxyBatch($offset, $limit);
                echo json_encode([
                    'success' => true,
                    'results' => $results
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '批量检查失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'checkFailedProxies':
            try {
                // 检查是否有需要发送警报的代理
                $failedProxies = $monitor->getFailedProxies();
                $emailSent = false;
                
                if (!empty($failedProxies)) {
                    try {
                        // 初始化邮件发送器
                        if (file_exists('vendor/autoload.php')) {
                            require_once 'mailer.php';
                            $mailer = new Mailer();
                        } else {
                            require_once 'mailer_simple.php';
                            $mailer = new SimpleMailer();
                        }
                        
                        $mailer->sendProxyAlert($failedProxies);
                        $emailSent = true;
                        
                        // 记录警报
                        foreach ($failedProxies as $proxy) {
                            $monitor->addAlert(
                                $proxy['id'],
                                'proxy_failure',
                                "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                            );
                        }
                    } catch (Exception $mailError) {
                        error_log('发送邮件失败: ' . $mailError->getMessage());
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'failed_proxies' => count($failedProxies),
                    'email_sent' => $emailSent
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '检查失败代理失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'sessionCheck':
            try {
                if (!Auth::isLoggedIn()) {
                    echo json_encode(['valid' => false]);
                } else {
                    echo json_encode(['valid' => true]);
                }
            } catch (Exception $e) {
                echo json_encode(['valid' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['error' => '未知操作']);
    }
    exit;
}

// 获取数据
$stats = $monitor->getStats();
$proxies = $monitor->getAllProxiesSafe(); // 使用安全版本，不包含敏感信息
$recentLogs = $monitor->getRecentLogs(20);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch - 网络监控系统</title>
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
            max-width: 1200px;
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
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left {
            flex: 1;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-info {
            text-align: right;
            font-size: 12px;
        }
        
        .username {
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .session-time {
            opacity: 0.8;
        }
        
        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 12px;
            transition: background 0.3s ease;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .online { color: #4CAF50; }
        .offline { color: #f44336; }
        .unknown { color: #ff9800; }
        .total { color: #2196F3; }
        
        .section {
            background: white;
            margin: 20px 0;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .section-header {
            background: #f8f9fa;
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #5a6fd8;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .status-online {
            background: #d4edda;
            color: #155724;
        }
        
        .status-offline {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-unknown {
            background: #fff3cd;
            color: #856404;
        }
        
        .refresh-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #667eea;
            color: white;
            border: none;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            transition: all 0.2s;
        }
        
        .refresh-btn:hover {
            background: #5a6fd8;
            transform: scale(1.1);
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .log-entry {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        
        .log-entry:last-child {
            border-bottom: none;
        }
        
        .log-time {
            color: #666;
            margin-right: 10px;
        }
        
        .log-status {
            margin-right: 10px;
            font-weight: bold;
        }
        
        .log-online { color: #4CAF50; }
        .log-offline { color: #f44336; }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .container {
                padding: 0 10px;
            }
            
            table {
                font-size: 14px;
            }
            
            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>🌐 NetWatch</h1>
                    <p>网络代理监控系统 - 实时监控您的代理服务器状态</p>
                </div>
                <?php if (Auth::isLoginEnabled()): ?>
                <div class="header-right">
                    <div class="user-info">
                        <div class="username">👤 <?php echo htmlspecialchars(Auth::getCurrentUser()); ?></div>
                        <div class="session-time">登录时间：<?php echo date('Y-m-d H:i:s', Auth::getLoginTime()); ?></div>
                    </div>
                    <a href="?action=logout" class="logout-btn" onclick="return confirm('确定要退出登录吗？')">退出登录</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- 统计信息 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number total"><?php echo $stats['total']; ?></div>
                <div class="stat-label">总代理数</div>
            </div>
            <div class="stat-card">
                <div class="stat-number online"><?php echo $stats['online']; ?></div>
                <div class="stat-label">在线</div>
            </div>
            <div class="stat-card">
                <div class="stat-number offline"><?php echo $stats['offline']; ?></div>
                <div class="stat-label">离线</div>
            </div>
            <div class="stat-card">
                <div class="stat-number unknown"><?php echo $stats['unknown']; ?></div>
                <div class="stat-label">未知</div>
            </div>
            <div class="stat-card">
                <div class="stat-number total"><?php echo number_format($stats['avg_response_time'], 0); ?>ms</div>
                <div class="stat-label">平均响应时间</div>
            </div>
        </div>
        
        <!-- 代理列表 -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">代理服务器列表</h2>
                <button class="btn" onclick="checkAllProxies()">检查所有代理</button>
            </div>
            <div class="table-container">
                <table id="proxies-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>地址</th>
                            <th>类型</th>
                            <th>状态</th>
                            <th>响应时间</th>
                            <th>失败次数</th>
                            <th>最后检查</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proxies as $proxy): ?>
                        <tr>
                            <td><?php echo $proxy['id']; ?></td>
                            <td><?php echo htmlspecialchars($proxy['ip'] . ':' . $proxy['port']); ?></td>
                            <td><?php echo strtoupper($proxy['type']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $proxy['status']; ?>">
                                    <?php echo $proxy['status']; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($proxy['response_time'], 2); ?>ms</td>
                            <td><?php echo $proxy['failure_count']; ?></td>
                            <td><?php echo $proxy['last_check'] ? date('m-d H:i', strtotime($proxy['last_check'])) : 'N/A'; ?></td>
                            <td>
                                <button class="btn btn-small" onclick="checkProxy(<?php echo $proxy['id']; ?>)">检查</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 最近日志 -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">最近检查日志</h2>
                <button class="btn" onclick="refreshLogs()">刷新日志</button>
            </div>
            <div id="logs-container">
                <?php foreach ($recentLogs as $log): ?>
                <div class="log-entry">
                    <span class="log-time"><?php echo date('m-d H:i:s', strtotime($log['checked_at'])); ?></span>
                    <span class="log-status log-<?php echo $log['status']; ?>"><?php echo strtoupper($log['status']); ?></span>
                    <span><?php echo htmlspecialchars($log['ip'] . ':' . $log['port']); ?></span>
                    <span>(<?php echo number_format($log['response_time'], 2); ?>ms)</span>
                    <?php if ($log['error_message']): ?>
                    <span style="color: #f44336;"> - <?php echo htmlspecialchars($log['error_message']); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <button class="refresh-btn" onclick="refreshAll()" title="刷新所有数据">
        🔄
    </button>
    
    <script>
        // 自动刷新
        setInterval(refreshStats, 30000); // 30秒刷新统计
        setInterval(refreshLogs, 60000);  // 60秒刷新日志
        
        function refreshStats() {
            fetch('?ajax=1&action=stats')
                .then(response => response.json())
                .then(data => {
                    document.querySelector('.stats-grid .stat-card:nth-child(1) .stat-number').textContent = data.total;
                    document.querySelector('.stats-grid .stat-card:nth-child(2) .stat-number').textContent = data.online;
                    document.querySelector('.stats-grid .stat-card:nth-child(3) .stat-number').textContent = data.offline;
                    document.querySelector('.stats-grid .stat-card:nth-child(4) .stat-number').textContent = data.unknown;
                    document.querySelector('.stats-grid .stat-card:nth-child(5) .stat-number').textContent = Math.round(data.avg_response_time) + 'ms';
                })
                .catch(error => console.error('刷新统计失败:', error));
        }
        
        function refreshLogs() {
            fetch('?ajax=1&action=logs')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('logs-container');
                    container.innerHTML = '';
                    
                    data.forEach(log => {
                        const div = document.createElement('div');
                        div.className = 'log-entry';
                        
                        const time = new Date(log.checked_at).toLocaleString('zh-CN', {
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit'
                        });
                        
                        let html = `
                            <span class="log-time">${time}</span>
                            <span class="log-status log-${log.status}">${log.status.toUpperCase()}</span>
                            <span>${log.ip}:${log.port}</span>
                            <span>(${parseFloat(log.response_time).toFixed(2)}ms)</span>
                        `;
                        
                        if (log.error_message) {
                            html += `<span style="color: #f44336;"> - ${log.error_message}</span>`;
                        }
                        
                        div.innerHTML = html;
                        container.appendChild(div);
                    });
                })
                .catch(error => console.error('刷新日志失败:', error));
        }
        
        function checkProxy(proxyId) {
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = '检查中...';
            btn.disabled = true;
            
            fetch(`?ajax=1&action=check&proxy_id=${proxyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('检查失败: ' + data.error);
                    } else {
                        // 刷新页面以显示最新状态
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('检查代理失败:', error);
                    alert('检查失败，请稍后重试');
                })
                .finally(() => {
                    btn.textContent = originalText;
                    btn.disabled = false;
                });
        }
        
        async function checkAllProxies() {
            if (confirm('确定要检查所有代理吗？这可能需要一些时间。')) {
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '检查中...';
                btn.disabled = true;
                
                // 创建进度显示界面
                const progressDiv = document.createElement('div');
                progressDiv.id = 'check-progress';
                progressDiv.style.cssText = `
                    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                    background: white; padding: 30px; border-radius: 15px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.3); z-index: 1000;
                    text-align: center; min-width: 400px; max-width: 500px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                `;
                
                progressDiv.innerHTML = `
                    <h3 style="margin: 0 0 20px 0; color: #333;">🔍 正在检查所有代理</h3>
                    <div id="progress-info" style="margin-bottom: 20px; color: #666;">正在获取代理列表...</div>
                    <div style="background: #f0f0f0; border-radius: 10px; height: 20px; margin: 20px 0; overflow: hidden;">
                        <div id="progress-bar" style="background: linear-gradient(90deg, #4CAF50, #45a049); height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 10px;"></div>
                    </div>
                    <div id="progress-stats" style="font-size: 14px; color: #888;">准备开始...</div>
                    <button id="cancel-check" style="margin-top: 15px; padding: 8px 16px; background: #f44336; color: white; border: none; border-radius: 5px; cursor: pointer;">取消检查</button>
                `;
                
                document.body.appendChild(progressDiv);
                
                let cancelled = false;
                document.getElementById('cancel-check').onclick = () => {
                    cancelled = true;
                    document.body.removeChild(progressDiv);
                    btn.textContent = originalText;
                    btn.disabled = false;
                };
                
                try {
                    // 首先获取代理总数
                    const countResponse = await fetch('?ajax=1&action=getProxyCount');
                    const countData = await countResponse.json();
                    
                    if (!countData.success) {
                        throw new Error(countData.error || '获取代理数量失败');
                    }
                    
                    const totalProxies = countData.count;
                    if (totalProxies === 0) {
                        alert('没有找到代理数据，请先导入代理。');
                        document.body.removeChild(progressDiv);
                        btn.textContent = originalText;
                        btn.disabled = false;
                        return;
                    }
                    
                    // 更新进度信息
                    document.getElementById('progress-info').textContent = `找到 ${totalProxies} 个代理，开始检查...`;
                    
                    // 分批检查代理
                    const batchSize = 10; // 每批检查10个代理
                    let checkedCount = 0;
                    let onlineCount = 0;
                    let offlineCount = 0;
                    
                    for (let offset = 0; offset < totalProxies && !cancelled; offset += batchSize) {
                        const batchResponse = await fetch(`?ajax=1&action=checkBatch&offset=${offset}&limit=${batchSize}`);
                        const batchData = await batchResponse.json();
                        
                        if (!batchData.success) {
                            throw new Error(batchData.error || '批量检查失败');
                        }
                        
                        // 更新统计
                        checkedCount += batchData.results.length;
                        onlineCount += batchData.results.filter(r => r.status === 'online').length;
                        offlineCount += batchData.results.filter(r => r.status === 'offline').length;
                        
                        // 更新进度条
                        const progress = (checkedCount / totalProxies) * 100;
                        document.getElementById('progress-bar').style.width = progress + '%';
                        
                        // 更新进度信息
                        document.getElementById('progress-info').textContent = 
                            `正在检查第 ${Math.min(offset + batchSize, totalProxies)} / ${totalProxies} 个代理...`;
                        
                        // 更新统计信息
                        document.getElementById('progress-stats').textContent = 
                            `已检查: ${checkedCount} | 在线: ${onlineCount} | 离线: ${offlineCount}`;
                        
                        // 添加小延迟，让用户能看到进度
                        await new Promise(resolve => setTimeout(resolve, 200));
                    }
                    
                    if (!cancelled) {
                        // 检查是否有失败的代理需要发送邮件
                        try {
                            const alertResponse = await fetch('?ajax=1&action=checkFailedProxies');
                            const alertData = await alertResponse.json();
                            
                            let alertMessage = '';
                            if (alertData.success && alertData.failed_proxies > 0) {
                                alertMessage = alertData.email_sent ? 
                                    `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理，已发送邮件通知！` :
                                    `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理。`;
                            }
                            
                            document.body.removeChild(progressDiv);
                            
                            alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个${alertMessage}\n\n页面将自动刷新显示最新状态`);
                            
                        } catch (alertError) {
                            document.body.removeChild(progressDiv);
                            alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个\n\n页面将自动刷新显示最新状态`);
                        }
                        
                        // 刷新页面显示最新状态
                        location.reload();
                    }
                    
                } catch (error) {
                    if (!cancelled) {
                        document.body.removeChild(progressDiv);
                        console.error('检查所有代理失败:', error);
                        alert('❌ 检查失败: ' + error.message);
                    }
                } finally {
                    if (!cancelled) {
                        btn.textContent = originalText;
                        btn.disabled = false;
                    }
                }
            }
        }
        
        function refreshAll() {
            const btn = document.querySelector('.refresh-btn');
            btn.style.transform = 'rotate(360deg)';
            
            refreshStats();
            refreshLogs();
            
            setTimeout(() => {
                btn.style.transform = 'rotate(0deg)';
            }, 500);
        }
        
        // 会话管理
        <?php if (Auth::isLoginEnabled()): ?>
        function checkSession() {
            fetch('?ajax=1&action=sessionCheck')
                .then(response => response.json())
                .then(data => {
                    if (!data.valid) {
                        alert('会话已过期，请重新登录');
                        window.location.href = 'login.php';
                    }
                })
                .catch(error => {
                    console.error('会话检查失败:', error);
                });
        }
        
        // 每5分钟检查一次会话状态
        setInterval(checkSession, 5 * 60 * 1000);
        
        // 页面加载时检查一次
        checkSession();
        <?php endif; ?>
    </script>
</body>
</html>
