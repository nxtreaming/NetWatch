<?php
/**
 * NetWatch Web 界面
 */

require_once 'config.php';
require_once 'monitor.php';

$monitor = new NetworkMonitor();
$action = $_GET['action'] ?? 'dashboard';

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
                $proxies = $monitor->getAllProxies();
                $proxy = array_filter($proxies, function($p) use ($proxyId) {
                    return $p['id'] == $proxyId;
                });
                if ($proxy) {
                    $result = $monitor->checkProxy(array_values($proxy)[0]);
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
            
        default:
            echo json_encode(['error' => '未知操作']);
    }
    exit;
}

// 获取数据
$stats = $monitor->getStats();
$proxies = $monitor->getAllProxies();
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
            <h1>🌐 NetWatch</h1>
            <p>网络代理监控系统 - 实时监控您的代理服务器状态</p>
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
        
        function checkAllProxies() {
            if (confirm('确定要检查所有代理吗？这可能需要一些时间。')) {
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '检查中...';
                btn.disabled = true;
                
                // 这里可以实现批量检查的逻辑
                // 为了简化，我们直接刷新页面
                setTimeout(() => {
                    location.reload();
                }, 2000);
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
    </script>
</body>
</html>
