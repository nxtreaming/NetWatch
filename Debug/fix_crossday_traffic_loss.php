<?php
/**
 * 修复跨日流量丢失问题
 * 
 * 策略：只在原 daily_usage 基础上加上丢失的跨日增量（昨天23:55→今天00:00），
 * 不改变其他计算方式。然后链式重算 used_bandwidth。
 * 
 * 用法：
 *   浏览器预览:  ?month=2&year=2026
 *   浏览器修复:  ?apply=1&month=2&year=2026
 *   浏览器回滚:  ?rollback=1&month=2&year=2026  （回滚上次错误修复）
 *   CLI预览:     php fix_crossday_traffic_loss.php --month 2
 *   CLI修复:     php fix_crossday_traffic_loss.php --apply
 *   CLI回滚:     php fix_crossday_traffic_loss.php --rollback
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

// 如果通过浏览器访问，需要登录
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../auth.php';
    Auth::requireLogin();
    header('Content-Type: text/plain; charset=utf-8');
}

$db = new Database();
$pdo = new PDO('sqlite:' . DB_PATH);

// 解析参数
$applyMode = false;
$rollbackMode = false;
$targetMonth = (int)date('m');
$targetYear = (int)date('Y');

if (php_sapi_name() === 'cli') {
    $applyMode = in_array('--apply', $argv ?? []);
    $rollbackMode = in_array('--rollback', $argv ?? []);
    $monthIdx = array_search('--month', $argv ?? []);
    if ($monthIdx !== false && isset($argv[$monthIdx + 1])) {
        $targetMonth = (int)$argv[$monthIdx + 1];
    }
} else {
    $applyMode = isset($_GET['apply']) && $_GET['apply'] === '1';
    $rollbackMode = isset($_GET['rollback']) && $_GET['rollback'] === '1';
    if (isset($_GET['month'])) {
        $targetMonth = (int)$_GET['month'];
    }
    if (isset($_GET['year'])) {
        $targetYear = (int)$_GET['year'];
    }
}

$monthStr = sprintf('%04d-%02d', $targetYear, $targetMonth);
$firstDay = "{$monthStr}-01";
$lastDay = date('Y-m-t', strtotime($firstDay));
$backupFile = __DIR__ . "/backup_traffic_stats_{$monthStr}.json";

// ========== 回滚模式 ==========
if ($rollbackMode) {
    echo "=== 回滚模式 ===\n\n";
    if (!file_exists($backupFile)) {
        echo "备份文件不存在: {$backupFile}\n";
        echo "无法回滚。\n";
        exit(1);
    }
    $backup = json_decode(file_get_contents($backupFile), true);
    if (empty($backup)) {
        echo "备份文件为空或格式错误\n";
        exit(1);
    }
    echo "找到 " . count($backup) . " 天的备份数据\n\n";
    foreach ($backup as $row) {
        $db->saveDailyTrafficStats(
            $row['usage_date'],
            floatval($row['total_bandwidth']),
            floatval($row['used_bandwidth']),
            floatval($row['remaining_bandwidth']),
            floatval($row['daily_usage'])
        );
        echo "已恢复: {$row['usage_date']} daily_usage=" . number_format(floatval($row['daily_usage']), 2) . 
             " used_bw=" . number_format(floatval($row['used_bandwidth']), 2) . "\n";
    }
    echo "\n回滚完成！数据已恢复到修复前的状态。\n";
    exit(0);
}

// ========== 修复模式 ==========
echo "=== 跨日流量丢失修复工具 ===\n";
echo "模式: " . ($applyMode ? "⚠️  实际修复" : "📋 预览模式") . "\n";
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

// 修复前先备份
if ($applyMode) {
    file_put_contents($backupFile, json_encode($allStats, JSON_PRETTY_PRINT));
    echo "已备份到: {$backupFile}\n\n";
}

// 逐天检查跨日增量是否丢失
echo str_pad("日期", 13) . 
     str_pad("原daily_usage", 16) . 
     str_pad("跨日增量", 12) .
     str_pad("新daily_usage", 16) . 
     str_pad("原used_bw", 14) .
     str_pad("新used_bw", 14) .
     "状态\n";
echo str_repeat("-", 97) . "\n";

$totalRecovered = 0;
$fixedDays = 0;
$cumulativeUsed = 0;

foreach ($allStats as $idx => $stat) {
    $date = $stat['usage_date'];
    $oldDailyUsage = floatval($stat['daily_usage']);
    $oldUsedBw = floatval($stat['used_bandwidth']);
    $totalBandwidth = floatval($stat['total_bandwidth']);
    
    $snapshots = $db->getTrafficSnapshotsByDate($date);
    $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
    $yesterdayLast = $db->getLastSnapshotOfDay($yesterday);
    $isFirstDayOfMonth = (date('d', strtotime($date)) === '01');
    
    // 计算该天的跨日增量
    $crossDayInc = 0;
    if (!$isFirstDayOfMonth && $yesterdayLast && !empty($snapshots) && $snapshots[0]['snapshot_time'] === '00:00:00') {
        $inc = ($snapshots[0]['total_bytes'] - $yesterdayLast['total_bytes']) / (1024*1024*1024);
        if ($inc > 0 && $inc < 50) {
            $crossDayInc = $inc;
        }
    }
    
    // 计算不含跨日的快照增量（00:05起累加）
    $snapshotOnlyUsage = 0;
    if (!empty($snapshots)) {
        for ($i = 1; $i < count($snapshots); $i++) {
            $inc = ($snapshots[$i]['total_bytes'] - $snapshots[$i-1]['total_bytes']) / (1024*1024*1024);
            $snapshotOnlyUsage += ($inc < 0) ? $snapshots[$i]['total_bytes'] / (1024*1024*1024) : $inc;
        }
    }
    
    // 判断原 daily_usage 是否已包含跨日增量
    // 如果 oldDailyUsage 接近 snapshotOnlyUsage（不含跨日），说明丢失了
    // 如果 oldDailyUsage 接近 snapshotOnlyUsage + crossDayInc（含跨日），说明正常
    $newDailyUsage = $oldDailyUsage; // 默认不变
    $addedInc = 0;
    $status = '';
    
    if ($crossDayInc > 0.01) {
        $diffWithout = abs($oldDailyUsage - $snapshotOnlyUsage);
        $diffWith = abs($oldDailyUsage - ($snapshotOnlyUsage + $crossDayInc));
        
        if ($diffWithout < $diffWith && $diffWithout < 1.0) {
            // oldDailyUsage 更接近不含跨日的值 → 丢失了跨日增量
            $newDailyUsage = $oldDailyUsage + $crossDayInc;
            $addedInc = $crossDayInc;
            $totalRecovered += $crossDayInc;
            $fixedDays++;
            $status = '⚠️  +' . number_format($crossDayInc, 2) . 'GB 找回';
        } elseif ($diffWith < 1.0) {
            $status = '✓ 已包含';
        } else {
            // 两者都不接近，保持原值不动
            $status = '— 保持原值';
        }
    } else {
        $status = '— 无跨日';
    }
    
    // 链式累计 used_bandwidth
    $cumulativeUsed += $newDailyUsage;
    $newUsedBw = $cumulativeUsed;
    
    echo str_pad($date, 13) . 
         str_pad(number_format($oldDailyUsage, 2) . " GB", 16) . 
         str_pad($crossDayInc > 0.01 ? number_format($crossDayInc, 2) . " GB" : "-", 12) .
         str_pad(number_format($newDailyUsage, 2) . " GB", 16) . 
         str_pad(number_format($oldUsedBw, 2) . " GB", 14) .
         str_pad(number_format($newUsedBw, 2) . " GB", 14) .
         $status . "\n";
    
    // 实际修复：只更新有变化的天（daily_usage 增加了跨日增量，或 used_bw 链式变化了）
    if ($applyMode && (abs($addedInc) >= 0.01 || abs($newUsedBw - $oldUsedBw) >= 0.01)) {
        $newRemaining = $totalBandwidth > 0 ? max(0, $totalBandwidth - $newUsedBw) : 0;
        $db->saveDailyTrafficStats(
            $date,
            $totalBandwidth,
            $newUsedBw,
            $newRemaining,
            $newDailyUsage
        );
    }
}

echo str_repeat("-", 97) . "\n\n";
echo "=== 汇总 ===\n";
echo "找回跨日流量: " . number_format($totalRecovered, 2) . " GB\n";
echo "受影响天数: {$fixedDays} 天\n";

if ($applyMode) {
    echo "\n✅ 修复已应用！\n";
    echo "如需回滚: ?rollback=1&month={$targetMonth}&year={$targetYear}\n";
} else {
    echo "\n📋 以上为预览，数据未修改。\n";
    if (php_sapi_name() === 'cli') {
        echo "执行修复: php " . basename(__FILE__) . " --apply --month {$targetMonth}\n";
    } else {
        echo "执行修复: ?apply=1&month={$targetMonth}&year={$targetYear}\n";
    }
}

echo "\n=== 完成 ===\n";
