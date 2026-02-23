<?php
/**
 * 代理流量监控页面
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../traffic_monitor.php';
require_once __DIR__ . '/partials/banner.php';
require_once __DIR__ . '/includes/helpers.php';

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

// 调试：显示快照数据
if (isset($_GET['debug']) && !empty($todaySnapshots)) {
    echo "<pre style='background: #f5f5f5; padding: 20px; margin: 20px; border: 1px solid #ddd; color: #333;'>";
    echo "=== 流量快照数据调试信息 ===\n";
    echo "查询日期: $snapshotDate\n";
    echo "总记录数: " . count($todaySnapshots) . "\n\n";
    
    // 显示最新一条快照（最后一条）
    $lastSnapshot = end($todaySnapshots);
    reset($todaySnapshots);
    if ($lastSnapshot) {
        $lastRxGB = $lastSnapshot['rx_bytes'] / (1024*1024*1024);
        $lastTxGB = $lastSnapshot['tx_bytes'] / (1024*1024*1024);
        $lastTotalGB = $lastRxGB + $lastTxGB;
        echo "【最新快照】时间: {$lastSnapshot['snapshot_time']}, RX: " . number_format($lastRxGB, 2) . " GB, TX: " . number_format($lastTxGB, 2) . " GB, 总计: " . number_format($lastTotalGB, 2) . " GB\n\n";
    }
    
    // 显示第一条快照
    $firstSnapshot = $todaySnapshots[0];
    if ($firstSnapshot) {
        $firstRxGB = $firstSnapshot['rx_bytes'] / (1024*1024*1024);
        $firstTxGB = $firstSnapshot['tx_bytes'] / (1024*1024*1024);
        $firstTotalGB = $firstRxGB + $firstTxGB;
        echo "【首条快照】时间: {$firstSnapshot['snapshot_time']}, RX: " . number_format($firstRxGB, 2) . " GB, TX: " . number_format($firstTxGB, 2) . " GB, 总计: " . number_format($firstTotalGB, 2) . " GB\n";
        
        // 计算当日增量
        if ($lastSnapshot) {
            $dayUsageGB = $lastTotalGB - $firstTotalGB;
            echo "【当日增量】(最新 - 首条) = " . number_format($dayUsageGB, 2) . " GB\n\n";
        }
    }
    
    echo "前10条记录:\n";
    echo str_pad("索引", 6) . str_pad("时间", 12) . str_pad("RX (GB)", 15) . str_pad("TX (GB)", 15) . str_pad("总计 (GB)", 15) . "\n";
    echo str_repeat("-", 75) . "\n";
    for ($i = 0; $i < min(10, count($todaySnapshots)); $i++) {
        $s = $todaySnapshots[$i];
        $rxGB = $s['rx_bytes'] / (1024*1024*1024);
        $txGB = $s['tx_bytes'] / (1024*1024*1024);
        $totalGB = $rxGB + $txGB;
        echo str_pad($i, 6) . str_pad($s['snapshot_time'], 12) . str_pad(number_format($rxGB, 2), 15) . str_pad(number_format($txGB, 2), 15) . str_pad(number_format($totalGB, 2), 15) . "\n";
    }
    echo "</pre>";
}

// 处理每日统计的日期查询（使用 helper 进行未来日期夹取）
$queryDate = clampToToday($_GET['date'] ?? null);
$recentStats = [];

if ($queryDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $queryDate)) {
    // 如果指定了日期，获取该日期前后7天的数据
    $recentStats = $trafficMonitor->getStatsAroundDate($queryDate, 7, 7);
} else {
    // 默认显示最近32天
    $recentStats = $trafficMonitor->getRecentStats(32);
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

// 统一计算展示上下文（单一入口，避免顶部/表格/RX-TX 口径漂移）
$displayContext = $trafficMonitor->buildProxyStatusDisplayContext($realtimeData);
$monthlyContext = $displayContext['monthly_context'];
$totalTrafficRaw = $monthlyContext['total_traffic_raw'];
$totalTraffic = $monthlyContext['total_traffic'];
$monthlyRxBytes = $monthlyContext['monthly_rx_bytes'];
$monthlyTxBytes = $monthlyContext['monthly_tx_bytes'];
$prevMonthLastSnapshot = $monthlyContext['prev_month_last_snapshot'];

// 统一计算今日展示上下文（与 API 共用，避免重复修修补补）
$todayContext = $displayContext['today_context'];
$todayDailyUsage = $todayContext['today_daily_usage'];
$todayUsedBandwidth = $todayContext['today_used_bandwidth'];
$todayDailyUsageForDisplay = $todayContext['today_daily_usage_for_display'];
$todayStr = date('Y-m-d');

$displayMonthlyUsed = $displayContext['display_monthly_used'];
$displayMonthlyRxBytes = $displayContext['display_monthly_rx_bytes'];
$displayMonthlyTxBytes = $displayContext['display_monthly_tx_bytes'];

// 如果搜索结果包含今日，用实时计算的数据替换
foreach ($recentStats as &$stat) {
    if ($stat['usage_date'] === $todayStr) {
        $stat['daily_usage'] = $todayDailyUsageForDisplay;
        $stat['used_bandwidth'] = $todayUsedBandwidth;
        break;
    }
}
unset($stat);

// 定义百分比变量供后续使用
$displayRemainingBandwidth = $realtimeData['remaining_bandwidth'];
$percentage = $realtimeData['usage_percentage'];
if (($realtimeData['total_bandwidth'] ?? 0) > 0) {
    $displayRemainingBandwidth = max(0, $realtimeData['total_bandwidth'] - $displayMonthlyUsed);
    $percentage = ($displayMonthlyUsed / $realtimeData['total_bandwidth']) * 100;
}
// 抽取常用变量，避免重复判断
$hasQuota = ($realtimeData['total_bandwidth'] ?? 0) > 0;
$usageClass = ($percentage >= 90) ? 'danger' : (($percentage >= 75) ? 'warning' : 'primary');
?>
<?php require_once __DIR__ . '/partials/header.php'; ?>
    
    <div class="container">
        <div class="stats-grid">
            <?php if ($hasQuota): ?>
            <div class="stat-card primary">
                <h2>总流量限制</h2>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($realtimeData['total_bandwidth']); ?></div>
                <div class="label">Total Limit</div>
            </div>
            <?php endif; ?>
            
            <div class="stat-card">
                <h2>当月使用流量</h2>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($displayMonthlyUsed); ?></div>
                <div class="label">Total Used</div>
            </div>
            
            <?php if ($hasQuota): ?>
            <div class="stat-card success">
                <h2>剩余流量</h2>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($displayRemainingBandwidth); ?></div>
                <div class="label">Remaining</div>
            </div>
            
            <div class="stat-card <?php echo $usageClass; ?>">
                <h2>使用率</h2>
                <div class="value"><?php echo $trafficMonitor->formatPercentage($percentage); ?></div>
                <div class="label">Usage Percentage</div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($hasQuota): ?>
        <div class="progress-section">
            <h2>流量使用进度</h2>
            <div class="progress-bar-container">
                <div class="progress-bar <?php echo $usageClass; ?>" style="width: <?php echo min($percentage, 100); ?>%">
                    <?php echo $trafficMonitor->formatPercentage($percentage); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (isset($realtimeData['rx_bytes']) && isset($realtimeData['tx_bytes'])): ?>
        <div class="progress-section">
            <h2>📊 流量详情</h2>
            <div class="stats-grid2 mt-20">
                <div class="stat-card gradient-purple">
                    <h3>⬇️ 接收流量 (RX)</h3>
                    <div class="value"><?php echo $trafficMonitor->formatBandwidth($displayMonthlyRxBytes / (1024*1024*1024)); ?></div>
                </div>
                
                <div class="stat-card gradient-pink">
                    <h3>⬆️ 发送流量 (TX)</h3>
                    <div class="value"><?php echo $trafficMonitor->formatBandwidth($displayMonthlyTxBytes / (1024*1024*1024)); ?></div>
                </div>
                
                <?php if (isset($realtimeData['port']) && $realtimeData['port'] > 0): ?>
                <div class="stat-card gradient-blue">
                    <h3>🔌 监听端口</h3>
                    <div class="value"><?php echo $realtimeData['port']; ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="chart-section mb-20">
            <div class="toolbar-row">
                <div>
                    <h2 class="m-0">📈 实时流量图</h2>
                </div>
                <div class="date-query-form">
                    <form id="snapshot-date-form" method="GET" class="form-inline">
                        <label for="snapshot-date" class="form-label">查询日期:</label>
                        <input type="date" 
                               id="snapshot-date" 
                               name="snapshot_date" 
                               value="<?php echo htmlspecialchars($snapshotDate); ?>"
                               max="<?php echo date('Y-m-d'); ?>"
                               class="form-input">
                        <button type="submit" class="btn btn-primary">
                            查询
                        </button>
                        <button type="button" 
                                id="snapshot-back-today"
                                onclick="resetSnapshotToToday()"
                                class="btn btn-secondary <?php echo $snapshotDate === date('Y-m-d') ? 'hidden' : ''; ?>">
                            返回今日
                        </button>
                        <?php if ($queryDate): ?>
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($queryDate); ?>">
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <?php if ($snapshotDate !== date('Y-m-d')): ?>
            <?php renderInfoBanner('<strong>📅 查询结果:</strong> 显示 ' . htmlspecialchars($snapshotDate, ENT_QUOTES, 'UTF-8') . ' 日流量数据', 'snapshot-info'); ?>
            <?php endif; ?>
            
            <?php if (!empty($todaySnapshots)): ?>
            <p id="snapshot-tip" class="tip-text">
                💡 提示：<?php echo $isViewingToday ? '显示当日从00:00开始的流量数据' : '显示当日全天流量数据'; ?>
            </p>
            <div class="chart-canvas-box">
                <canvas id="trafficChart"></canvas>
            </div>
            <p id="chart-interval-sum" class="chart-sum-text">当前图上采样点增量总和：--</p>
            <?php else: ?>
            <?php renderWarningBanner('<strong>⚠️ 暂无数据</strong><br>' . htmlspecialchars($snapshotDate, ENT_QUOTES, 'UTF-8') . ' 没有流量快照数据'); ?>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($recentStats)): ?>
        <div class="chart-section">
            <div class="toolbar-row">
                <h2 class="m-0">📊 <?php echo $queryDate ? '日期范围流量统计' : '最近32天流量统计'; ?></h2>
                <div class="date-query-form">
                    <form id="query-date-form" method="GET" class="form-inline">
                        <label for="query-date" class="form-label">查询日期:</label>
                        <input type="date" 
                               id="query-date" 
                               name="date" 
                               value="<?php echo $queryDate ? htmlspecialchars($queryDate) : date('Y-m-d'); ?>"
                               max="<?php echo date('Y-m-d'); ?>"
                               class="form-input">
                        <button type="submit" class="btn btn-primary">
                            查询前后7天
                        </button>
                        <button type="button" 
                                id="query-back-recent"
                                onclick="resetQueryToRecent()"
                                class="btn btn-secondary <?php echo !$queryDate ? 'hidden' : ''; ?>">
                            显示最近32天
                        </button>
                        <?php if ($snapshotDate !== date('Y-m-d')): ?>
                        <input type="hidden" name="snapshot_date" value="<?php echo htmlspecialchars($snapshotDate); ?>">
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <?php if ($queryDate): ?>
            <?php 
                $startDate = date('Y-m-d', strtotime($queryDate . ' -7 days'));
                $endDate = date('Y-m-d', strtotime($queryDate . ' +7 days'));
                $statsHtml = '<strong>📅 查询结果:</strong> 显示 ' . htmlspecialchars($queryDate, ENT_QUOTES, 'UTF-8') . ' 前后7天的流量数据（' . $startDate . ' 至 ' . $endDate . '）';
                renderInfoBanner($statsHtml, 'stats-info');
            ?>
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
                        <?php 
                        foreach ($recentStats as $stat): 
                            $currentDate = $stat['usage_date'];
                            $isToday = ($currentDate === $todayStr);
                            
                            // 今日用实时计算值，其他用数据库值
                            $calculatedDailyUsage = $isToday ? $todayDailyUsageForDisplay : (isset($stat['daily_usage']) ? $stat['daily_usage'] : $stat['used_bandwidth']);
                            $displayUsedBandwidth = $isToday ? $todayUsedBandwidth : $stat['used_bandwidth'];
                            
                            $displayTotalBandwidth = $stat['total_bandwidth'];
                            $displayRemainingBandwidth = $stat['remaining_bandwidth'];
                        ?>
                        <tr <?php if ($queryDate && $stat['usage_date'] === $queryDate) echo 'class="row-highlight"'; ?>>
                            <td><?php echo htmlspecialchars($stat['usage_date']); ?><?php if ($isToday) echo ' <span class="dot-green">●</span>'; ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($calculatedDailyUsage); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($displayUsedBandwidth); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($displayTotalBandwidth); ?></td>
                            <td><?php echo $trafficMonitor->formatBandwidth($displayRemainingBandwidth); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
<?php require_once __DIR__ . '/partials/footer.php'; ?>
