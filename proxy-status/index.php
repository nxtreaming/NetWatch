<?php
/**
 * 公开的代理流量监控页面
 * 不需要登录即可访问
 */

require_once '../config.php';
require_once '../traffic_monitor.php';

$trafficMonitor = new TrafficMonitor();

// 获取实时流量数据
$realtimeData = $trafficMonitor->getRealtimeTraffic();

// 获取最近30天的统计数据
$recentStats = $trafficMonitor->getRecentStats(30);

// 如果没有数据，显示默认值
if (!$realtimeData) {
    $realtimeData = [
        'total_bandwidth' => 0,
        'used_bandwidth' => 0,
        'remaining_bandwidth' => 0,
        'usage_percentage' => 0,
        'updated_at' => null
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>代理流量监控 - NetWatch</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.15);
        }
        
        .stat-card h3 {
            color: #666;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 2em;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .stat-card .label {
            color: #999;
            font-size: 0.9em;
        }
        
        .stat-card.primary .value {
            color: #667eea;
        }
        
        .stat-card.success .value {
            color: #48bb78;
        }
        
        .stat-card.warning .value {
            color: #ed8936;
        }
        
        .stat-card.danger .value {
            color: #f56565;
        }
        
        .progress-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 40px;
        }
        
        .progress-section h2 {
            color: #333;
            margin-bottom: 20px;
        }
        
        .progress-bar-container {
            background: #e2e8f0;
            border-radius: 10px;
            height: 40px;
            overflow: hidden;
            position: relative;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .progress-bar.warning {
            background: linear-gradient(90deg, #ed8936 0%, #dd6b20 100%);
        }
        
        .progress-bar.danger {
            background: linear-gradient(90deg, #f56565 0%, #e53e3e 100%);
        }
        
        .chart-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .chart-section h2 {
            color: #333;
            margin-bottom: 20px;
        }
        
        .chart-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        table th {
            background: #f7fafc;
            color: #4a5568;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85em;
            letter-spacing: 0.5px;
        }
        
        table tr:hover {
            background: #f7fafc;
        }
        
        .update-time {
            text-align: center;
            color: white;
            margin-top: 30px;
            opacity: 0.8;
        }
        
        .auto-refresh {
            text-align: center;
            color: white;
            margin-top: 10px;
            font-size: 0.9em;
        }
        
        .refresh-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            background: #48bb78;
            border-radius: 50%;
            margin-right: 5px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 1.8em;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card .value {
                font-size: 1.5em;
            }
            
            .progress-section,
            .chart-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🌐 代理流量监控</h1>
            <p>实时流量使用情况和统计数据</p>
        </div>
        
        <div class="stats-grid">
            <?php if ($realtimeData['total_bandwidth'] > 0): ?>
            <div class="stat-card primary">
                <h3>总流量限制</h3>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($realtimeData['total_bandwidth']); ?></div>
                <div class="label">Total Limit</div>
            </div>
            <?php endif; ?>
            
            <div class="stat-card <?php 
                $percentage = $realtimeData['usage_percentage'];
                if ($percentage >= 90) echo 'danger';
                elseif ($percentage >= 75) echo 'warning';
                else echo 'success';
            ?>">
                <h3>累计使用</h3>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($realtimeData['used_bandwidth']); ?></div>
                <div class="label">Total Used (RX + TX)</div>
            </div>
            
            <?php if ($realtimeData['total_bandwidth'] > 0): ?>
            <div class="stat-card success">
                <h3>剩余流量</h3>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($realtimeData['remaining_bandwidth']); ?></div>
                <div class="label">Remaining</div>
            </div>
            
            <div class="stat-card <?php 
                if ($percentage >= 90) echo 'danger';
                elseif ($percentage >= 75) echo 'warning';
                else echo 'primary';
            ?>">
                <h3>使用率</h3>
                <div class="value"><?php echo $trafficMonitor->formatPercentage($realtimeData['usage_percentage']); ?></div>
                <div class="label">Usage Percentage</div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($realtimeData['total_bandwidth'] > 0): ?>
        <div class="progress-section">
            <h2>流量使用进度</h2>
            <div class="progress-bar-container">
                <div class="progress-bar <?php 
                    if ($percentage >= 90) echo 'danger';
                    elseif ($percentage >= 75) echo 'warning';
                ?>" style="width: <?php echo min($percentage, 100); ?>%">
                    <?php echo $trafficMonitor->formatPercentage($realtimeData['usage_percentage']); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (isset($realtimeData['rx_bytes']) && isset($realtimeData['tx_bytes'])): ?>
        <div class="progress-section">
            <h2>📊 流量详情</h2>
            <div class="stats-grid" style="margin-top: 20px;">
                <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h3 style="color: white; opacity: 0.9;">⬇️ 接收流量 (RX)</h3>
                    <div class="value" style="color: white;"><?php echo $trafficMonitor->formatBandwidth($realtimeData['rx_bytes'] / (1024*1024*1024)); ?></div>
                    <div class="label" style="color: white; opacity: 0.8;">Download</div>
                </div>
                
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <h3 style="color: white; opacity: 0.9;">⬆️ 发送流量 (TX)</h3>
                    <div class="value" style="color: white;"><?php echo $trafficMonitor->formatBandwidth($realtimeData['tx_bytes'] / (1024*1024*1024)); ?></div>
                    <div class="label" style="color: white; opacity: 0.8;">Upload</div>
                </div>
                
                <?php if (isset($realtimeData['port']) && $realtimeData['port'] > 0): ?>
                <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                    <h3 style="color: white; opacity: 0.9;">🔌 监控端口</h3>
                    <div class="value" style="color: white;"><?php echo $realtimeData['port']; ?></div>
                    <div class="label" style="color: white; opacity: 0.8;">Port Number</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($recentStats)): ?>
        <div class="chart-section">
            <h2>📊 最近30天流量统计</h2>
            <div class="chart-container">
                <table>
                    <thead>
                        <tr>
                            <th>日期</th>
                            <th>总流量</th>
                            <th>已用流量</th>
                            <th>剩余流量</th>
                            <th>当日使用</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentStats as $stat): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($stat['usage_date']); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($stat['total_bandwidth']); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($stat['used_bandwidth']); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($stat['remaining_bandwidth']); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($stat['daily_usage']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($realtimeData['updated_at']): ?>
        <div class="update-time">
            最后更新时间: <?php echo date('Y-m-d H:i:s', strtotime($realtimeData['updated_at'])); ?>
        </div>
        <?php endif; ?>
        
        <div class="auto-refresh">
            <span class="refresh-indicator"></span>
            页面每5分钟自动刷新一次
        </div>
    </div>
    
    <script>
        // 每5分钟自动刷新页面
        setTimeout(function() {
            location.reload();
        }, <?php echo defined('TRAFFIC_UPDATE_INTERVAL') ? TRAFFIC_UPDATE_INTERVAL * 1000 : 300000; ?>);
    </script>
</body>
</html>
