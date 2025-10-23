<?php
/**
 * 代理流量监控页面
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../traffic_monitor.php';

// 强制要求登录
Auth::requireLogin();

// 处理登出请求
$action = $_GET['action'] ?? '';
if ($action === 'logout') {
    Auth::logout();
    header('Location: ../login.php?action=logout');
    exit;
}

$trafficMonitor = new TrafficMonitor();

// 获取实时流量数据
$realtimeData = $trafficMonitor->getRealtimeTraffic();

// 处理实时流量图表的日期查询
$snapshotDate = isset($_GET['snapshot_date']) ? $_GET['snapshot_date'] : null;
$todaySnapshots = [];
$isViewingToday = false; // 标识是否正在查看今日数据

if ($snapshotDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)) {
    // 获取指定日期的流量快照
    $todaySnapshots = $trafficMonitor->getSnapshotsByDate($snapshotDate);
    $isViewingToday = ($snapshotDate === date('Y-m-d'));
} else {
    // 默认获取今日流量快照数据用于图表
    $todaySnapshots = $trafficMonitor->getTodaySnapshots();
    $snapshotDate = date('Y-m-d'); // 设置为今天
    $isViewingToday = true;
}

// 处理每日统计的日期查询
$queryDate = isset($_GET['date']) ? $_GET['date'] : null;
$recentStats = [];

if ($queryDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $queryDate)) {
    // 如果指定了日期，获取该日期前后7天的数据
    $recentStats = $trafficMonitor->getStatsAroundDate($queryDate, 7, 7);
} else {
    // 默认显示最近30天
    $recentStats = $trafficMonitor->getRecentStats(30);
}

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
            color: white;
            margin-bottom: 20px;
        }
        
        .header-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1px;
        }
        
        .header-left {
            flex: 1;
        }
        
        .header h1 {
            font-size: 1.5em;
            margin-bottom: 1px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .header p {
            font-size: 1.0em;
            opacity: 0.9;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }
        
        .user-info span {
            color: white;
            font-weight: 600;
        }
        
        .nav-btn,
        .logout-btn {
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.3);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: background 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .nav-btn:hover,
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.4);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stats-grid2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 10px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
            margin-bottom: 20px;
        }
        
        .progress-section h2 {
            color: #333;
            margin-bottom: 10px;
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
        
        /* 图表滚动容器样式 */
        .chart-section > div[style*="overflow-x"] {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
        }
        
        .chart-section > div[style*="overflow-x"]::-webkit-scrollbar {
            height: 8px;
        }
        
        .chart-section > div[style*="overflow-x"]::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 4px;
        }
        
        .chart-section > div[style*="overflow-x"]::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }
        
        .chart-section > div[style*="overflow-x"]::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
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
            body {
                padding: 10px;
            }
            
            .header {
                margin-bottom: 20px;
            }
            
            .header-wrapper {
                flex-direction: column;
                align-items: center;
                gap: 15px;
            }
            
            .header-left {
                text-align: center;
                width: 100%;
            }
            
            .header h1 {
                font-size: 1.5em;
            }
            
            .header p {
                font-size: 0.9em;
            }
            
            .user-info {
                position: static;
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                align-items: center;
                gap: 8px;
                padding: 8px 12px;
                margin-top: 15px;
                font-size: 12px;
            }
            
            .user-info span {
                order: 1;
            }
            
            .nav-btn {
                order: 0;
                padding: 6px 12px;
                font-size: 12px;
            }
            
            .logout-btn {
                order: 2;
                padding: 6px 12px;
                font-size: 12px;
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
            
            .date-query-form form {
                flex-wrap: wrap;
                width: 100%;
            }
            
            .date-query-form label {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .date-query-form input[type="date"] {
                flex: 1;
                min-width: 120px;
            }
            
            .date-query-form button[type="submit"] {
                flex-shrink: 0;
                white-space: nowrap;
                padding: 8px 12px !important;
                font-size: 13px;
            }
            
            .date-query-form a {
                width: 100%;
                text-align: center;
                margin-top: 5px;
            }
            
            .date-query-form input[type="hidden"] {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-wrapper">
                <div class="header-left">
                    <h1>🌐 IP池流量监控</h1>
                    <p>更新时间<?php 
                        if ($realtimeData['updated_at']) {
                            // 将UTC时间转换为北京时间（UTC+8）
                            $utcTime = strtotime($realtimeData['updated_at']);
                            $beijingTime = $utcTime + (8 * 3600);
                            echo ' (' . date('m-d H:i:s', $beijingTime) . ')';
                        }
                    ?></p>
                </div>
                <div class="user-info">
                    <a href="../index.php" class="nav-btn">🏠 主页</a>
                    <span>👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <a href="?action=logout" class="logout-btn" onclick="return confirm('确定要退出登录吗？')">🚪 退出</a>
                </div>
            </div>
        </div>
        
        <div class="stats-grid">
            <?php if ($realtimeData['total_bandwidth'] > 0): ?>
            <div class="stat-card primary">
                <h3>总流量限制</h3>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($realtimeData['total_bandwidth']); ?></div>
                <div class="label">Total Limit</div>
            </div>
            <?php endif; ?>
            
            <div class="stat-card">
                <h3>累计使用</h3>
                <div class="value"><?php 
                    // 显示 RX + TX 的总流量
                    $totalTraffic = 0;
                    if (isset($realtimeData['rx_bytes']) && isset($realtimeData['tx_bytes'])) {
                        $totalTraffic = ($realtimeData['rx_bytes'] + $realtimeData['tx_bytes']) / (1024*1024*1024);
                    }
                    echo $trafficMonitor->formatBandwidth($totalTraffic);
                ?></div>
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
            <div class="stats-grid2" style="margin-top: 20px;">
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
        
        <div class="chart-section" style="margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h2 style="margin: 0;">📈 实时流量趋势图</h2>
                    <p style="color: #666; margin: 5px 0 0 0;">每5分钟更新一次，展示当日新增流量消耗情况（每天00:00从0开始计算）</p>
                </div>
                <div class="date-query-form">
                    <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                        <label for="snapshot-date" style="font-weight: 600; color: #555;">查询日期:</label>
                        <input type="date" 
                               id="snapshot-date" 
                               name="snapshot_date" 
                               value="<?php echo htmlspecialchars($snapshotDate); ?>"
                               max="<?php echo date('Y-m-d'); ?>"
                               style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                        <button type="submit" 
                                style="padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                            查询
                        </button>
                        <?php if ($snapshotDate !== date('Y-m-d')): ?>
                        <a href="?" 
                           style="padding: 8px 16px; background: #6c757d; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">
                            返回今日
                        </a>
                        <?php endif; ?>
                        <?php if ($queryDate): ?>
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($queryDate); ?>">
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <?php if ($snapshotDate !== date('Y-m-d')): ?>
            <div style="background: #e7f3ff; padding: 12px; border-radius: 6px; margin-bottom: 15px; color: #0066cc;">
                <strong>📅 查询结果:</strong> 显示 <?php echo $snapshotDate; ?> 的实时流量数据
            </div>
            <?php endif; ?>
            
            <?php if (!empty($todaySnapshots)): ?>
            <p style="color: #999; font-size: 13px; margin-bottom: 10px;">
                💡 提示：<?php echo $isViewingToday ? '图表显示最近4小时的实时流量数据' : '图表显示当日全天的流量数据'; ?>
            </p>
            <div style="position: relative; height: 400px;">
                <canvas id="trafficChart"></canvas>
            </div>
            <?php else: ?>
            <div style="background: #fff3cd; padding: 20px; border-radius: 6px; text-align: center; color: #856404;">
                <strong>⚠️ 暂无数据</strong><br>
                <?php echo $snapshotDate; ?> 没有流量快照数据
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($recentStats)): ?>
        <div class="chart-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h2 style="margin: 0;">📊 <?php echo $queryDate ? '日期范围流量统计' : '最近30天流量统计'; ?></h2>
                <div class="date-query-form">
                    <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                        <label for="query-date" style="font-weight: 600; color: #555;">查询日期:</label>
                        <input type="date" 
                               id="query-date" 
                               name="date" 
                               value="<?php echo $queryDate ? htmlspecialchars($queryDate) : ''; ?>"
                               style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                        <button type="submit" 
                                style="padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                            查询前后7天
                        </button>
                        <?php if ($queryDate): ?>
                        <a href="?<?php echo $snapshotDate !== date('Y-m-d') ? 'snapshot_date=' . urlencode($snapshotDate) : ''; ?>" 
                           style="padding: 8px 16px; background: #6c757d; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">
                            显示最近30天
                        </a>
                        <?php endif; ?>
                        <?php if ($snapshotDate !== date('Y-m-d')): ?>
                        <input type="hidden" name="snapshot_date" value="<?php echo htmlspecialchars($snapshotDate); ?>">
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <?php if ($queryDate): ?>
            <div style="background: #e7f3ff; padding: 12px; border-radius: 6px; margin-bottom: 15px; color: #0066cc;">
                <strong>📅 查询结果:</strong> 显示 <?php echo $queryDate; ?> 前后7天的流量数据
                <?php 
                $startDate = date('Y-m-d', strtotime($queryDate . ' -7 days'));
                $endDate = date('Y-m-d', strtotime($queryDate . ' +7 days'));
                echo "（{$startDate} 至 {$endDate}）";
                ?>
            </div>
            <?php endif; ?>
            
            <div class="chart-container">
                <table>
                    <thead>
                        <tr>
                            <th>日期</th>
                            <th>当日使用</th>
                            <th>已用流量</th>
                            <th>总流量</th>
                            <th>剩余流量</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentStats as $stat): ?>
                        <tr <?php if ($queryDate && $stat['usage_date'] === $queryDate) echo 'style="background: #fff3cd; font-weight: 600;"'; ?>>
                            <td><?php echo htmlspecialchars($stat['usage_date']); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($stat['daily_usage']); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($stat['used_bandwidth']); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($stat['total_bandwidth']); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($stat['remaining_bandwidth']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        
        <div class="auto-refresh">
            <span class="refresh-indicator"></span>
            页面每5分钟自动刷新一次
        </div>
    </div>
    
    <!-- Chart.js 库 -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <script>
        // 每5分钟自动刷新页面
        setTimeout(function() {
            location.reload();
        }, <?php echo defined('TRAFFIC_UPDATE_INTERVAL') ? TRAFFIC_UPDATE_INTERVAL * 1000 : 300000; ?>);
        
        // 创建流量趋势图
        <?php if (!empty($todaySnapshots)): ?>
        (function() {
            // 准备数据
            const snapshots = <?php echo json_encode($todaySnapshots); ?>;
            const isViewingToday = <?php echo $isViewingToday ? 'true' : 'false'; ?>;
            
            // 提取时间标签
            const labels = snapshots.map(s => s.snapshot_time.substring(0, 5)); // 只显示 HH:MM
            
            // 计算每5分钟的增量流量（相对于上一个数据点）
            const rxData = [];
            const txData = [];
            const totalData = [];
            
            for (let i = 0; i < snapshots.length; i++) {
                if (i === 0) {
                    // 第一个数据点，增量为0
                    rxData.push(0);
                    txData.push(0);
                    totalData.push(0);
                } else {
                    // 计算相对于上一个数据点的增量
                    const rxIncrement = (snapshots[i].rx_bytes - snapshots[i-1].rx_bytes) / (1024 * 1024);
                    const txIncrement = (snapshots[i].tx_bytes - snapshots[i-1].tx_bytes) / (1024 * 1024);
                    const totalIncrement = (snapshots[i].total_bytes - snapshots[i-1].total_bytes) / (1024 * 1024);
                    
                    rxData.push(rxIncrement.toFixed(2));
                    txData.push(txIncrement.toFixed(2));
                    totalData.push(totalIncrement.toFixed(2));
                }
            }
            
            // 获取canvas元素
            const ctx = document.getElementById('trafficChart');
            if (!ctx) return;
            
            // 根据是否查看今日决定显示的数据范围
            let displayLabels, displayData;
            if (isViewingToday) {
                // 查看今日：只显示最近4小时的数据（48个数据点）
                const pointsToShow = 48;
                const startIndex = Math.max(0, snapshots.length - pointsToShow);
                displayLabels = labels.slice(startIndex);
                displayData = totalData.slice(startIndex);
            } else {
                // 查看历史：显示全天数据
                displayLabels = labels;
                displayData = totalData;
            }
            
            // 创建图表
            new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: displayLabels,
                        datasets: [
                            {
                                label: '本时段流量',
                                data: displayData,
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 4,
                                pointHoverRadius: 6
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                displayColors: false,
                                titleFont: {
                                    size: 14,
                                    weight: 'bold'
                                },
                                bodyFont: {
                                    size: 13
                                },
                                callbacks: {
                                    title: function(context) {
                                        // 显示时间段
                                        const currentTime = context[0].label;
                                        const index = context[0].dataIndex;
                                        if (index === 0) {
                                            return currentTime + ' (起始点)';
                                        }
                                        // 计算上一个时间点
                                        const prevTime = context[0].chart.data.labels[index - 1];
                                        return prevTime + ' → ' + currentTime;
                                    },
                                    label: function(context) {
                                        const value = parseFloat(context.parsed.y).toFixed(2);
                                        if (context.dataIndex === 0) {
                                            return '本时段流量:0 MB (起始点)';
                                        } else {
                                            return '本时段流量:' + value + ' MB';
                                        }
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: '每5分钟增量流量 (MB)',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    }
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value + ' MB';
                                    },
                                    font: {
                                        size: 12
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: '时间',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    }
                                },
                                ticks: {
                                    font: {
                                        size: 11
                                    },
                                    maxRotation: 45,
                                    minRotation: 0
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
        })();
        <?php endif; ?>
    </script>
</body>
</html>
