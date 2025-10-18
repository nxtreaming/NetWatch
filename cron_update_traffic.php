<?php
/**
 * 定时任务：更新流量数据
 * 建议每5分钟执行一次
 * 
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/traffic_monitor.php';

echo "=== 流量数据更新任务开始 ===\n";
echo "执行时间: " . date('Y-m-d H:i:s') . "\n\n";

$trafficMonitor = new TrafficMonitor();

// 更新实时流量数据
echo "正在更新实时流量数据...\n";
$realtimeResult = $trafficMonitor->updateRealtimeTraffic();

if ($realtimeResult) {
    echo "✓ 实时流量数据更新成功\n";
    
    // 显示当前数据
    $data = $trafficMonitor->getRealtimeTraffic();
    if ($data) {
        echo "  - 总流量: " . $trafficMonitor->formatBandwidth($data['total_bandwidth']) . "\n";
        echo "  - 已用流量: " . $trafficMonitor->formatBandwidth($data['used_bandwidth']) . "\n";
        echo "  - 剩余流量: " . $trafficMonitor->formatBandwidth($data['remaining_bandwidth']) . "\n";
        echo "  - 使用率: " . $trafficMonitor->formatPercentage($data['usage_percentage']) . "\n";
    }
} else {
    echo "✗ 实时流量数据更新失败\n";
}

echo "\n";

// 更新每日统计（每天执行一次即可，但多次执行不会有问题）
echo "正在更新每日流量统计...\n";
$dailyResult = $trafficMonitor->updateDailyStats();

if ($dailyResult) {
    echo "✓ 每日流量统计更新成功\n";
} else {
    echo "✗ 每日流量统计更新失败\n";
}

echo "\n=== 流量数据更新任务完成 ===\n";
