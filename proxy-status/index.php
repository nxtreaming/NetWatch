<?php
/**
 * 代理流量监控页面
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../traffic_monitor.php';
require_once __DIR__ . '/partials/banner.php';

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

// 调试：显示前10条数据（仅在有debug参数时显示）
if (isset($_GET['debug']) && !empty($todaySnapshots)) {
    echo "<pre style='background: #f5f5f5; padding: 20px; margin: 20px; border: 1px solid #ddd;'>";
    echo "=== 流量快照数据调试信息 ===\n";
    echo "日期: $snapshotDate\n";
    echo "总记录数: " . count($todaySnapshots) . "\n\n";
    echo "前10条记录:\n";
    echo str_pad("索引", 6) . str_pad("时间", 12) . str_pad("RX (GB)", 15) . str_pad("TX (GB)", 15) . "\n";
    echo str_repeat("-", 60) . "\n";
    for ($i = 0; $i < min(10, count($todaySnapshots)); $i++) {
        $s = $todaySnapshots[$i];
        $rxGB = $s['rx_bytes'] / (1024*1024*1024);
        $txGB = $s['tx_bytes'] / (1024*1024*1024);
        echo str_pad($i, 6) . str_pad($s['snapshot_time'], 12) . str_pad(number_format($rxGB, 2), 15) . str_pad(number_format($txGB, 2), 15) . "\n";
    }
    echo "</pre>";
}

// 处理每日统计的日期查询
$queryDate = isset($_GET['date']) ? $_GET['date'] : null;
$recentStats = [];

// 规范化：禁止未来日期（如果传入未来，则回退为今天）
if ($queryDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $queryDate) && $queryDate > date('Y-m-d')) {
    $queryDate = date('Y-m-d');
}

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

// 计算总流量（统一计算逻辑，避免重复）
$totalTraffic = 0;
if (isset($realtimeData['rx_bytes']) && isset($realtimeData['tx_bytes']) && 
    ($realtimeData['rx_bytes'] > 0 || $realtimeData['tx_bytes'] > 0)) {
    // 优先使用原始字节数计算
    $rxBytes = floatval($realtimeData['rx_bytes']);
    $txBytes = floatval($realtimeData['tx_bytes']);
    $totalTraffic = ($rxBytes + $txBytes) / (1024*1024*1024);
} elseif (isset($realtimeData['used_bandwidth'])) {
    // 备选：使用已计算的 used_bandwidth
    $totalTraffic = floatval($realtimeData['used_bandwidth']);
}

// 定义百分比变量供后续使用
$percentage = $realtimeData['usage_percentage'];
// 抽取常用变量，避免重复判断
$hasQuota = ($realtimeData['total_bandwidth'] ?? 0) > 0;
$usageClass = ($percentage >= 90) ? 'danger' : (($percentage >= 75) ? 'warning' : 'primary');
?>
<?php require_once __DIR__ . '/partials/header.php'; ?>
    
    <div class="container">
        <div class="stats-grid">
            <?php if ($hasQuota): ?>
            <div class="stat-card primary">
                <h3>总流量限制</h3>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($realtimeData['total_bandwidth']); ?></div>
                <div class="label">Total Limit</div>
            </div>
            <?php endif; ?>
            
            <div class="stat-card">
                <h3>流量累计使用</h3>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($totalTraffic); ?></div>
                <div class="label">Total Used</div>
            </div>
            
            <?php if ($hasQuota): ?>
            <div class="stat-card success">
                <h3>剩余流量</h3>
                <div class="value"><?php echo $trafficMonitor->formatBandwidth($realtimeData['remaining_bandwidth']); ?></div>
                <div class="label">Remaining</div>
            </div>
            
            <div class="stat-card <?php echo $usageClass; ?>">
                <h3>使用率</h3>
                <div class="value"><?php echo $trafficMonitor->formatPercentage($realtimeData['usage_percentage']); ?></div>
                <div class="label">Usage Percentage</div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($hasQuota): ?>
        <div class="progress-section">
            <h2>流量使用进度</h2>
            <div class="progress-bar-container">
                <div class="progress-bar <?php echo $usageClass; ?>" style="width: <?php echo min($percentage, 100); ?>%">
                    <?php echo $trafficMonitor->formatPercentage($realtimeData['usage_percentage']); ?>
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
                    <div class="value"><?php echo $trafficMonitor->formatBandwidth($realtimeData['rx_bytes'] / (1024*1024*1024)); ?></div>
                    <div class="label">Download</div>
                </div>
                
                <div class="stat-card gradient-pink">
                    <h3>⬆️ 发送流量 (TX)</h3>
                    <div class="value"><?php echo $trafficMonitor->formatBandwidth($realtimeData['tx_bytes'] / (1024*1024*1024)); ?></div>
                    <div class="label">Upload</div>
                </div>
                
                <?php if (isset($realtimeData['port']) && $realtimeData['port'] > 0): ?>
                <div class="stat-card gradient-blue">
                    <h3>🔌 监控端口</h3>
                    <div class="value"><?php echo $realtimeData['port']; ?></div>
                    <div class="label">Port Number</div>
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
                        // 先将数据按日期建立索引，方便查找前一天的数据
                        $statsByDate = [];
                        foreach ($recentStats as $s) {
                            $statsByDate[$s['usage_date']] = $s;
                        }
                        
                        // 获取今日日期，用于判断是否显示实时数据
                        $today = date('Y-m-d');
                        
                        foreach ($recentStats as $stat): 
                            $currentDate = $stat['usage_date'];
                            $isToday = ($currentDate === $today);
                            
                            // 如果是今日数据，使用实时数据；否则使用历史快照
                            if ($isToday) {
                                // 今日数据：使用页面顶部已计算的总流量
                                $displayUsedBandwidth = $totalTraffic;
                                $displayTotalBandwidth = $realtimeData['total_bandwidth'];
                                $displayRemainingBandwidth = $realtimeData['remaining_bandwidth'];
                            } else {
                                // 历史数据：使用数据库快照
                                $displayUsedBandwidth = $stat['used_bandwidth'];
                                $displayTotalBandwidth = $stat['total_bandwidth'];
                                $displayRemainingBandwidth = $stat['remaining_bandwidth'];
                            }
                            
                            // 计算当日使用量：优先使用数据库中已计算好的 daily_usage
                            // 数据库的 daily_usage 已经通过快照增量正确计算，包含了流量重置的处理
                            if (!$isToday && isset($stat['daily_usage']) && $stat['daily_usage'] > 0) {
                                // 历史数据：直接使用数据库中的 daily_usage（已通过快照增量计算）
                                $calculatedDailyUsage = $stat['daily_usage'];
                            } else {
                                // 今日数据或没有 daily_usage：使用传统计算方法
                                $previousDate = date('Y-m-d', strtotime($currentDate . ' -1 day'));
                                
                                // 查找前一天的数据
                                if (isset($statsByDate[$previousDate])) {
                                    // 有前一天的数据，计算当日增量
                                    $previousDayUsed = $statsByDate[$previousDate]['used_bandwidth'];
                                    $calculatedDailyUsage = $displayUsedBandwidth - $previousDayUsed;
                                    
                                    // 如果计算结果为负（流量重置），使用当天的累计值
                                    if ($calculatedDailyUsage < 0) {
                                        $calculatedDailyUsage = $displayUsedBandwidth;
                                    }
                                } else {
                                    // 没有前一天的数据，使用当天累计值
                                    $calculatedDailyUsage = $displayUsedBandwidth;
                                }
                            }
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
