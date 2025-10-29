<?php
/**
 * 检查今日流量快照数据
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

$db = new Database();
$today = date('Y-m-d');

echo "=== 检查今日流量快照数据 ===\n";
echo "日期: $today\n\n";

// 获取今日所有快照
$snapshots = $db->getTrafficSnapshotsByDate($today);

echo "总共 " . count($snapshots) . " 条记录\n\n";

if (count($snapshots) > 0) {
    echo "前20条记录:\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-10s | %-20s | %-15s | %-15s\n", "时间", "记录时间", "RX (GB)", "TX (GB)");
    echo str_repeat("-", 80) . "\n";
    
    for ($i = 0; $i < min(20, count($snapshots)); $i++) {
        $s = $snapshots[$i];
        $rxGB = $s['rx_bytes'] / (1024*1024*1024);
        $txGB = $s['tx_bytes'] / (1024*1024*1024);
        printf("%-10s | %-20s | %13.2f | %13.2f\n", 
            $s['snapshot_time'], 
            $s['recorded_at'],
            $rxGB,
            $txGB
        );
    }
    
    echo "\n最后20条记录:\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-10s | %-20s | %-15s | %-15s\n", "时间", "记录时间", "RX (GB)", "TX (GB)");
    echo str_repeat("-", 80) . "\n";
    
    $start = max(0, count($snapshots) - 20);
    for ($i = $start; $i < count($snapshots); $i++) {
        $s = $snapshots[$i];
        $rxGB = $s['rx_bytes'] / (1024*1024*1024);
        $txGB = $s['tx_bytes'] / (1024*1024*1024);
        printf("%-10s | %-20s | %13.2f | %13.2f\n", 
            $s['snapshot_time'], 
            $s['recorded_at'],
            $rxGB,
            $txGB
        );
    }
    
    // 检查时间间隔
    echo "\n\n=== 时间间隔分析 ===\n";
    $intervals = [];
    for ($i = 1; $i < count($snapshots); $i++) {
        $prevTime = strtotime($today . ' ' . $snapshots[$i-1]['snapshot_time']);
        $currTime = strtotime($today . ' ' . $snapshots[$i]['snapshot_time']);
        $diff = ($currTime - $prevTime) / 60; // 分钟
        
        if (!isset($intervals[$diff])) {
            $intervals[$diff] = 0;
        }
        $intervals[$diff]++;
    }
    
    ksort($intervals);
    foreach ($intervals as $minutes => $count) {
        echo sprintf("%d 分钟间隔: %d 次\n", $minutes, $count);
    }
}
