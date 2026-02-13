<?php
/**
 * 诊断跨日流量丢失问题 — 整月检测
 * 
 * 用法：
 *   ?month=2&year=2026    指定月份（默认当月）
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../database.php';

Auth::requireLogin();

header('Content-Type: text/plain; charset=utf-8');

$db = new Database();
$pdo = new PDO('sqlite:' . DB_PATH);

$targetMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$targetYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$monthStr = sprintf('%04d-%02d', $targetYear, $targetMonth);
$firstDay = "{$monthStr}-01";
$lastDay = date('Y-m-t', strtotime($firstDay));
$today = date('Y-m-d');

echo "=== 跨日流量丢失诊断（整月） ===\n";
echo "目标月份: {$monthStr}\n";
echo "日期范围: {$firstDay} ~ {$lastDay}\n\n";

// 获取该月所有统计数据
$stmt = $pdo->prepare("SELECT * FROM traffic_stats WHERE usage_date >= ? AND usage_date <= ? ORDER BY usage_date ASC");
$stmt->execute([$firstDay, $lastDay]);
$allStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($allStats)) {
    echo "该月没有统计数据\n";
    exit(0);
}

echo "找到 " . count($allStats) . " 天的统计数据\n\n";

// ========== 1. 每日统计总览 ==========
echo "=== 1. 每日统计总览 ===\n\n";
echo str_pad("日期", 13) .
     str_pad("daily_usage", 14) .
     str_pad("used_bw", 14) .
     str_pad("快照数", 8) .
     str_pad("有00:00", 9) .
     str_pad("跨日增量", 12) .
     str_pad("DB中是否包含跨日", 20) .
     "\n";
echo str_repeat("-", 90) . "\n";

$totalCrossDayLost = 0;
$daysWithLoss = 0;

foreach ($allStats as $stat) {
    $date = $stat['usage_date'];
    $dailyUsage = floatval($stat['daily_usage']);
    $usedBw = floatval($stat['used_bandwidth']);
    
    $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
    $snapshots = $db->getTrafficSnapshotsByDate($date);
    $yesterdayLast = $db->getLastSnapshotOfDay($yesterday);
    $snapshotCount = count($snapshots);
    
    $hasMidnight = (!empty($snapshots) && $snapshots[0]['snapshot_time'] === '00:00:00') ? '是' : '否';
    
    // 计算跨日增量
    $crossDayInc = '-';
    $crossDayIncValue = 0;
    if ($yesterdayLast && !empty($snapshots) && $snapshots[0]['snapshot_time'] === '00:00:00') {
        $crossDayIncValue = ($snapshots[0]['total_bytes'] - $yesterdayLast['total_bytes']) / (1024*1024*1024);
        if ($crossDayIncValue > 0 && $crossDayIncValue < 50) {
            $crossDayInc = sprintf("%+.2f GB", $crossDayIncValue);
        } elseif ($crossDayIncValue < 0) {
            $crossDayInc = "重置";
            $crossDayIncValue = 0;
        } else {
            $crossDayInc = sprintf("%.2f GB", $crossDayIncValue);
        }
    }
    
    // 检测 DB 中的 daily_usage 是否已包含跨日增量
    // 方法：用快照重算不含跨日的值，与 DB 值比较
    $usageWithoutCrossDay = 0;
    if (!empty($snapshots)) {
        for ($i = 1; $i < count($snapshots); $i++) {
            $inc = ($snapshots[$i]['total_bytes'] - $snapshots[$i-1]['total_bytes']) / (1024*1024*1024);
            if ($inc < 0) {
                $usageWithoutCrossDay += $snapshots[$i]['total_bytes'] / (1024*1024*1024);
            } else {
                $usageWithoutCrossDay += $inc;
            }
        }
    }
    
    $dbIncludesCrossDay = '—';
    if ($crossDayIncValue > 0.01) {
        $diffWithCross = abs($dailyUsage - ($usageWithoutCrossDay + $crossDayIncValue));
        $diffWithout = abs($dailyUsage - $usageWithoutCrossDay);
        if ($diffWithCross < $diffWithout && $diffWithCross < 0.1) {
            $dbIncludesCrossDay = '✓ 已包含';
        } elseif ($diffWithout < 0.1) {
            $dbIncludesCrossDay = '✗ 未包含 (-' . number_format($crossDayIncValue, 2) . 'GB)';
            $totalCrossDayLost += $crossDayIncValue;
            $daysWithLoss++;
        } else {
            $dbIncludesCrossDay = '? 不确定';
        }
    }
    
    echo str_pad($date, 13) .
         str_pad(number_format($dailyUsage, 2) . " GB", 14) .
         str_pad(number_format($usedBw, 2) . " GB", 14) .
         str_pad($snapshotCount, 8) .
         str_pad($hasMidnight, 9) .
         str_pad($crossDayInc, 12) .
         $dbIncludesCrossDay .
         "\n";
}

echo str_repeat("-", 90) . "\n\n";

// ========== 2. 一致性检查 ==========
echo "=== 2. 一致性检查 (used_bw = 前日used_bw + daily_usage) ===\n\n";

$inconsistentDays = 0;
for ($i = 1; $i < count($allStats); $i++) {
    $prev = $allStats[$i - 1];
    $curr = $allStats[$i];
    
    $expected = floatval($prev['used_bandwidth']) + floatval($curr['daily_usage']);
    $actual = floatval($curr['used_bandwidth']);
    $diff = $actual - $expected;
    
    if (abs($diff) > 0.1) {
        echo "{$curr['usage_date']}: 期望=" . number_format($expected, 2) .
             " 实际=" . number_format($actual, 2) .
             " 差值=" . sprintf("%+.2f", $diff) . " GB ⚠️\n";
        $inconsistentDays++;
    }
}

if ($inconsistentDays === 0) {
    echo "所有日期一致性检查通过 ✓\n";
} else {
    echo "\n{$inconsistentDays} 天存在不一致\n";
}

// ========== 3. 汇总 ==========
echo "\n=== 3. 汇总 ===\n\n";
echo "本月跨日流量丢失总计: " . number_format($totalCrossDayLost, 2) . " GB\n";
echo "受影响天数: {$daysWithLoss} 天\n";

if ($totalCrossDayLost > 0.1) {
    echo "\n提示: 运行 fix_crossday_traffic_loss.php 可修复历史数据\n";
    echo "  预览: fix_crossday_traffic_loss.php?month={$targetMonth}&year={$targetYear}\n";
    echo "  修复: fix_crossday_traffic_loss.php?apply=1&month={$targetMonth}&year={$targetYear}\n";
}

echo "\n=== 诊断完成 ===\n";
