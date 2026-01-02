<?php
/**
 * 修复流量统计数据
 * 确保 used_bandwidth = 昨日 used_bandwidth + 今日 daily_usage
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

// 设置时区
date_default_timezone_set('Asia/Shanghai');

$db = new Database();

// 要修复的日期（从这一天开始往后修复）
$startDate = '2026-01-02';
$endDate = date('Y-m-d');

echo "=== 流量数据修复工具 ===\n\n";

// 获取起始日期前一天的数据作为基准
$prevDate = date('Y-m-d', strtotime($startDate . ' -1 day'));
$prevData = $db->getDailyTrafficStats($prevDate);

if (!$prevData) {
    die("错误：找不到 {$prevDate} 的数据作为基准\n");
}

echo "基准日期: {$prevDate}\n";
echo "基准 used_bandwidth: {$prevData['used_bandwidth']} GB\n\n";

$currentUsedBandwidth = floatval($prevData['used_bandwidth']);
$currentDate = $startDate;

while ($currentDate <= $endDate) {
    $stats = $db->getDailyTrafficStats($currentDate);
    
    if ($stats) {
        $dailyUsage = floatval($stats['daily_usage']);
        $oldUsedBandwidth = floatval($stats['used_bandwidth']);
        $newUsedBandwidth = $currentUsedBandwidth + $dailyUsage;
        
        if (abs($oldUsedBandwidth - $newUsedBandwidth) > 0.01) {
            echo "{$currentDate}: used_bandwidth {$oldUsedBandwidth} GB → {$newUsedBandwidth} GB (daily_usage: {$dailyUsage} GB)\n";
            
            // 更新数据库
            $db->saveDailyTrafficStats(
                $currentDate,
                $stats['total_bandwidth'],
                $newUsedBandwidth,
                $stats['remaining_bandwidth'],
                $dailyUsage
            );
            
            echo "  ✓ 已更新\n";
        } else {
            echo "{$currentDate}: 数据正确，无需修复\n";
        }
        
        $currentUsedBandwidth = $newUsedBandwidth;
    } else {
        echo "{$currentDate}: 无数据，跳过\n";
    }
    
    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
}

echo "\n=== 修复完成 ===\n";
