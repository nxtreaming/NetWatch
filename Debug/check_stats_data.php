<?php
/**
 * 检查流量统计数据
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../database.php';

// 强制要求登录
Auth::requireLogin();

$db = new Database();

echo "=== 流量统计数据检查 ===\n\n";

// 获取最近3天的数据
$stats = $db->getRecentTrafficStats(3);

echo "最近3天的统计数据:\n\n";
echo str_pad("日期", 12) . 
     str_pad("当日使用(GB)", 15) . 
     str_pad("已用流量(GB)", 15) . 
     str_pad("总流量(GB)", 15) . 
     str_pad("剩余流量(GB)", 15) . "\n";
echo str_repeat("-", 70) . "\n";

foreach ($stats as $stat) {
    echo str_pad($stat['usage_date'], 12) . 
         str_pad(number_format($stat['daily_usage'], 2), 15) . 
         str_pad(number_format($stat['used_bandwidth'], 2), 15) . 
         str_pad(number_format($stat['total_bandwidth'], 2), 15) . 
         str_pad(number_format($stat['remaining_bandwidth'], 2), 15) . "\n";
}

echo "\n=== 分析 ===\n\n";

if (count($stats) >= 2) {
    $today = $stats[0];
    $yesterday = $stats[1];
    
    echo "今天 ({$today['usage_date']}):\n";
    echo "  当日使用: " . number_format($today['daily_usage'], 2) . " GB\n";
    echo "  已用流量: " . number_format($today['used_bandwidth'], 2) . " GB\n\n";
    
    echo "昨天 ({$yesterday['usage_date']}):\n";
    echo "  当日使用: " . number_format($yesterday['daily_usage'], 2) . " GB\n";
    echo "  已用流量: " . number_format($yesterday['used_bandwidth'], 2) . " GB\n\n";
    
    $diff = $today['used_bandwidth'] - $yesterday['used_bandwidth'];
    echo "已用流量差值: " . number_format($diff, 2) . " GB\n";
    
    if ($diff < 0) {
        echo "⚠️  检测到流量重置（差值为负）\n";
        echo "    昨天结束时累计: " . number_format($yesterday['used_bandwidth'], 2) . " GB\n";
        echo "    今天当前累计: " . number_format($today['used_bandwidth'], 2) . " GB\n";
    }
}

echo "\n=== 检查完成 ===\n";
