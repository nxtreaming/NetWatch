<?php
/**
 * 修复跨日流量丢失问题
 * 
 * 根因：updateYesterdayCrossDayTraffic 回溯的跨日增量被后续 cron 覆盖，
 * 导致每天丢失约 3~5 GB 的跨日流量（23:55→00:00）。
 * 
 * 本脚本使用快照数据重新计算每天的 daily_usage 和 used_bandwidth。
 * 
 * 用法：
 *   php fix_crossday_traffic_loss.php              # 预览模式（不修改数据）
 *   php fix_crossday_traffic_loss.php --apply       # 实际修复
 *   php fix_crossday_traffic_loss.php --month 2     # 指定月份（默认当月）
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
$targetMonth = (int)date('m');
$targetYear = (int)date('Y');

if (php_sapi_name() === 'cli') {
    $applyMode = in_array('--apply', $argv ?? []);
    $monthIdx = array_search('--month', $argv ?? []);
    if ($monthIdx !== false && isset($argv[$monthIdx + 1])) {
        $targetMonth = (int)$argv[$monthIdx + 1];
    }
} else {
    $applyMode = isset($_GET['apply']) && $_GET['apply'] === '1';
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
$today = date('Y-m-d');

echo "=== 跨日流量丢失修复工具 ===\n";
echo "模式: " . ($applyMode ? "⚠️  实际修复" : "📋 预览模式（加 --apply 参数执行修复）") . "\n";
echo "目标月份: {$monthStr}\n";
echo "日期范围: {$firstDay} ~ {$lastDay}\n\n";

// 获取该月所有有统计数据的日期
$stmt = $pdo->prepare("SELECT * FROM traffic_stats WHERE usage_date >= ? AND usage_date <= ? ORDER BY usage_date ASC");
$stmt->execute([$firstDay, $lastDay]);
$allStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($allStats)) {
    echo "❌ 该月没有统计数据\n";
    exit(0);
}

echo "找到 " . count($allStats) . " 天的统计数据\n\n";

// 逐天重新计算
$totalLostGB = 0;
$fixedDays = 0;
$cumulativeUsed = 0; // 当月累计

echo str_pad("日期", 12) . 
     str_pad("原daily_usage", 16) . 
     str_pad("新daily_usage", 16) . 
     str_pad("差值(GB)", 12) .
     str_pad("原used_bw", 14) .
     str_pad("新used_bw", 14) .
     "状态\n";
echo str_repeat("-", 96) . "\n";

foreach ($allStats as $idx => $stat) {
    $date = $stat['usage_date'];
    $oldDailyUsage = floatval($stat['daily_usage']);
    $oldUsedBw = floatval($stat['used_bandwidth']);
    $totalBandwidth = floatval($stat['total_bandwidth']);
    
    // 获取当天所有快照
    $snapshots = $db->getTrafficSnapshotsByDate($date);
    
    if (empty($snapshots)) {
        echo str_pad($date, 12) . "无快照数据，跳过\n";
        // 保持原值用于累计
        $cumulativeUsed += $oldDailyUsage;
        continue;
    }
    
    // 获取前一天最后快照
    $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
    $yesterdayLastSnapshot = $db->getLastSnapshotOfDay($yesterday);
    
    // 重新计算 daily_usage（使用修复后的逻辑，包含跨日增量）
    $newDailyUsage = 0;
    $isFirstDayOfMonth = (date('d', strtotime($date)) === '01');
    
    if ($isFirstDayOfMonth) {
        // 每月1日：只计算当天快照间的增量
        for ($i = 1; $i < count($snapshots); $i++) {
            $inc = ($snapshots[$i]['total_bytes'] - $snapshots[$i-1]['total_bytes']) / (1024*1024*1024);
            if ($inc < 0) {
                $newDailyUsage += $snapshots[$i]['total_bytes'] / (1024*1024*1024);
            } else {
                $newDailyUsage += $inc;
            }
        }
    } elseif ($yesterdayLastSnapshot && !empty($snapshots)) {
        $hasMidnight = $snapshots[0]['snapshot_time'] === '00:00:00';
        
        if ($hasMidnight) {
            // 包含跨日增量（昨天最后快照 → 今天 00:00）
            $crossDayInc = ($snapshots[0]['total_bytes'] - $yesterdayLastSnapshot['total_bytes']) / (1024*1024*1024);
            if ($crossDayInc > 0 && $crossDayInc < 50) {
                $newDailyUsage += $crossDayInc;
            } elseif ($crossDayInc < 0) {
                $newDailyUsage += $snapshots[0]['total_bytes'] / (1024*1024*1024);
            }
            // 当天快照间增量
            for ($i = 1; $i < count($snapshots); $i++) {
                $inc = ($snapshots[$i]['total_bytes'] - $snapshots[$i-1]['total_bytes']) / (1024*1024*1024);
                if ($inc < 0) {
                    $newDailyUsage += $snapshots[$i]['total_bytes'] / (1024*1024*1024);
                } else {
                    $newDailyUsage += $inc;
                }
            }
        } else {
            // 无 00:00 快照，从昨天最后快照开始
            $firstInc = ($snapshots[0]['total_bytes'] - $yesterdayLastSnapshot['total_bytes']) / (1024*1024*1024);
            if ($firstInc < 0) {
                $newDailyUsage += $snapshots[0]['total_bytes'] / (1024*1024*1024);
            } else {
                $newDailyUsage += $firstInc;
            }
            for ($i = 1; $i < count($snapshots); $i++) {
                $inc = ($snapshots[$i]['total_bytes'] - $snapshots[$i-1]['total_bytes']) / (1024*1024*1024);
                if ($inc < 0) {
                    $newDailyUsage += $snapshots[$i]['total_bytes'] / (1024*1024*1024);
                } else {
                    $newDailyUsage += $inc;
                }
            }
        }
    } else {
        // 无前一天数据
        if (count($snapshots) > 1) {
            for ($i = 1; $i < count($snapshots); $i++) {
                $inc = ($snapshots[$i]['total_bytes'] - $snapshots[$i-1]['total_bytes']) / (1024*1024*1024);
                if ($inc < 0) {
                    $newDailyUsage += $snapshots[$i]['total_bytes'] / (1024*1024*1024);
                } else {
                    $newDailyUsage += $inc;
                }
            }
        } else {
            $newDailyUsage = $snapshots[0]['total_bytes'] / (1024*1024*1024);
        }
    }
    
    // 计算当月累计 used_bandwidth
    $cumulativeUsed += $newDailyUsage;
    $newUsedBw = $cumulativeUsed;
    
    // 计算差值
    $dailyDiff = $newDailyUsage - $oldDailyUsage;
    $totalLostGB += $dailyDiff;
    
    $status = '';
    if (abs($dailyDiff) < 0.01) {
        $status = '✓ 无变化';
    } else if ($dailyDiff > 0) {
        $status = '⚠️  +' . number_format($dailyDiff, 2) . 'GB 丢失已找回';
        $fixedDays++;
    } else {
        $status = '📉 ' . number_format($dailyDiff, 2) . 'GB';
    }
    
    echo str_pad($date, 12) . 
         str_pad(number_format($oldDailyUsage, 2), 16) . 
         str_pad(number_format($newDailyUsage, 2), 16) . 
         str_pad(sprintf("%+.2f", $dailyDiff), 12) .
         str_pad(number_format($oldUsedBw, 2), 14) .
         str_pad(number_format($newUsedBw, 2), 14) .
         $status . "\n";
    
    // 实际修复
    if ($applyMode && abs($dailyDiff) >= 0.01) {
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

echo str_repeat("-", 96) . "\n\n";
echo "=== 汇总 ===\n";
echo "总丢失流量: " . number_format($totalLostGB, 2) . " GB\n";
echo "受影响天数: {$fixedDays} 天\n";

if ($applyMode) {
    echo "\n✅ 修复已应用！所有受影响日期的 daily_usage 和 used_bandwidth 已更新。\n";
} else {
    echo "\n📋 以上为预览，数据未修改。\n";
    if (php_sapi_name() === 'cli') {
        echo "执行修复请运行: php " . basename(__FILE__) . " --apply\n";
    } else {
        echo "执行修复请访问: ?apply=1" . ($targetMonth != (int)date('m') ? "&month={$targetMonth}" : "") . "\n";
    }
}

echo "\n=== 完成 ===\n";
