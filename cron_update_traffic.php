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

// 只调用一次 API 获取数据
echo "正在从 API 获取流量数据...\n";
$apiData = $trafficMonitor->fetchTrafficData();

if ($apiData === false) {
    echo "✗ API 数据获取失败，任务终止\n";
    exit(1);
}

echo "✓ API 数据获取成功\n";
echo "  - 端口: " . ($apiData['port'] ?? 'N/A') . "\n";
echo "  - RX: " . number_format(($apiData['rx'] ?? 0) / (1024*1024*1024), 2) . " GB\n";
echo "  - TX: " . number_format(($apiData['tx'] ?? 0) / (1024*1024*1024), 2) . " GB\n";
echo "  - 总计: " . number_format((($apiData['rx'] ?? 0) + ($apiData['tx'] ?? 0)) / (1024*1024*1024), 2) . " GB\n";

echo "\n";

// 使用同一份数据更新实时流量表
echo "正在更新实时流量数据...\n";
$realtimeResult = $trafficMonitor->updateRealtimeTrafficWithData($apiData);

if ($realtimeResult) {
    echo "✓ 实时流量数据更新成功\n";
} else {
    echo "✗ 实时流量数据更新失败\n";
}

echo "\n";

// 使用同一份数据更新每日统计表
echo "正在更新每日流量统计...\n";
$dailyResult = $trafficMonitor->updateDailyStatsWithData($apiData);

if ($dailyResult) {
    echo "✓ 每日流量统计更新成功\n";
} else {
    echo "✗ 每日流量统计更新失败\n";
}

echo "\n=== 流量数据更新任务完成 ===\n";
