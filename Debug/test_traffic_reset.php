<?php
/**
 * 测试流量重置场景下的当日使用量计算
 * 模拟在00:00:01重置流量的情况
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../database.php';
require_once '../traffic_monitor.php';
require_once '../logger.php';

// 强制要求登录
Auth::requireLogin();

echo "=== 流量重置场景测试 ===\n\n";

$db = new Database();
$monitor = new TrafficMonitor();

// 获取今天和昨天的日期
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));

echo "今天: $today\n";
echo "昨天: $yesterday\n\n";

// 1. 检查昨天最后一个快照
echo "--- 1. 检查昨天最后一个快照 ---\n";
$yesterdayLastSnapshot = $db->getLastSnapshotOfDay($yesterday);

if ($yesterdayLastSnapshot) {
    echo "昨天最后快照:\n";
    echo "  时间: {$yesterdayLastSnapshot['snapshot_time']}\n";
    echo "  Total: " . number_format($yesterdayLastSnapshot['total_bytes'] / (1024*1024*1024), 2) . " GB\n";
} else {
    echo "❌ 没有昨天的快照数据\n";
}

echo "\n--- 2. 检查今天的快照数据 ---\n";
$todaySnapshots = $db->getTrafficSnapshotsByDate($today);

if (empty($todaySnapshots)) {
    echo "❌ 今天没有快照数据\n";
    exit;
}

echo "今天共有 " . count($todaySnapshots) . " 条快照\n\n";

// 显示前5条快照
echo "前5条快照:\n";
echo str_pad("时间", 10) . str_pad("Total (GB)", 15) . str_pad("增量 (MB)", 15) . str_pad("说明", 30) . "\n";
echo str_repeat("-", 70) . "\n";

$prevTotal = $yesterdayLastSnapshot ? $yesterdayLastSnapshot['total_bytes'] : 0;
$prevLabel = $yesterdayLastSnapshot ? "昨天{$yesterdayLastSnapshot['snapshot_time']}" : "起始";

for ($i = 0; $i < min(5, count($todaySnapshots)); $i++) {
    $s = $todaySnapshots[$i];
    $totalGB = $s['total_bytes'] / (1024*1024*1024);
    
    // 计算增量
    $increment = ($s['total_bytes'] - $prevTotal) / (1024 * 1024);
    
    // 判断是否发生重置
    $note = '';
    if ($increment < 0) {
        $note = '⚠️ 流量重置';
    } elseif ($i === 0 && $yesterdayLastSnapshot) {
        $note = '✅ 跨日流量';
    }
    
    echo str_pad($s['snapshot_time'], 10) . 
         str_pad(number_format($totalGB, 2), 15) . 
         str_pad(number_format($increment, 2), 15) . 
         str_pad($note, 30) . "\n";
    
    $prevTotal = $s['total_bytes'];
}

echo "\n--- 3. 使用快照增量计算当日使用量 ---\n";

// 使用反射调用私有方法进行测试
$reflection = new ReflectionClass($monitor);
$method = $reflection->getMethod('calculateDailyUsageFromSnapshots');
$method->setAccessible(true);

$dailyUsageFromSnapshots = $method->invoke($monitor, $today);

if ($dailyUsageFromSnapshots !== false) {
    echo "✅ 快照增量计算结果: " . number_format($dailyUsageFromSnapshots, 2) . " GB\n";
} else {
    echo "❌ 快照数据不足，无法计算\n";
}

echo "\n--- 4. 传统方法计算（今日-昨日）---\n";

$todayStats = $db->getDailyTrafficStats($today);
$yesterdayStats = $db->getDailyTrafficStats($yesterday);

if ($todayStats && $yesterdayStats) {
    $traditionalDaily = $todayStats['used_bandwidth'] - $yesterdayStats['used_bandwidth'];
    
    echo "今日累计: " . number_format($todayStats['used_bandwidth'], 2) . " GB\n";
    echo "昨日累计: " . number_format($yesterdayStats['used_bandwidth'], 2) . " GB\n";
    echo "传统计算: " . number_format($traditionalDaily, 2) . " GB\n";
    
    if ($traditionalDaily < 0) {
        echo "⚠️  传统方法检测到流量重置（结果为负）\n";
        echo "    如果直接使用今日累计: " . number_format($todayStats['used_bandwidth'], 2) . " GB\n";
        
        if ($dailyUsageFromSnapshots !== false) {
            $lost = $dailyUsageFromSnapshots - $todayStats['used_bandwidth'];
            if ($lost > 0) {
                echo "    ❌ 丢失流量: " . number_format($lost, 2) . " GB (跨日流量)\n";
            }
        }
    }
} else {
    echo "⚠️  缺少统计数据\n";
}

echo "\n--- 5. 对比结果 ---\n";

if ($dailyUsageFromSnapshots !== false && $todayStats && $yesterdayStats) {
    echo "快照增量法: " . number_format($dailyUsageFromSnapshots, 2) . " GB ✅ (准确)\n";
    
    if ($traditionalDaily >= 0) {
        echo "传统方法:   " . number_format($traditionalDaily, 2) . " GB\n";
        $diff = abs($dailyUsageFromSnapshots - $traditionalDaily);
        if ($diff < 0.01) {
            echo "✅ 两种方法结果一致（无流量重置）\n";
        } else {
            echo "⚠️  结果差异: " . number_format($diff, 2) . " GB\n";
        }
    } else {
        echo "传统方法:   " . number_format($traditionalDaily, 2) . " GB (负数，检测到重置)\n";
        echo "    如使用今日累计: " . number_format($todayStats['used_bandwidth'], 2) . " GB\n";
        $lost = $dailyUsageFromSnapshots - $todayStats['used_bandwidth'];
        echo "    ❌ 会丢失: " . number_format($lost, 2) . " GB\n";
    }
}

echo "\n=== 测试完成 ===\n";
