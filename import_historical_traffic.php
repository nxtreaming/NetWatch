<?php
/**
 * 导入历史流量数据
 * 从JSON数据中提取pack_size并保存到数据库
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// JSON数据
$jsonData = '{
    "code": 200,
    "msg": "success",
    "data": [
        {"date": "2025-10-01", "date_short": "10-01", "pack_size": 21.11},
        {"date": "2025-10-02", "date_short": "10-02", "pack_size": 9.37},
        {"date": "2025-10-03", "date_short": "10-03", "pack_size": 4.66},
        {"date": "2025-10-04", "date_short": "10-04", "pack_size": 12.11},
        {"date": "2025-10-05", "date_short": "10-05", "pack_size": 53.07},
        {"date": "2025-10-06", "date_short": "10-06", "pack_size": 57.94},
        {"date": "2025-10-07", "date_short": "10-07", "pack_size": 70.09},
        {"date": "2025-10-08", "date_short": "10-08", "pack_size": 66.86},
        {"date": "2025-10-09", "date_short": "10-09", "pack_size": 64.28},
        {"date": "2025-10-10", "date_short": "10-10", "pack_size": 61.7},
        {"date": "2025-10-11", "date_short": "10-11", "pack_size": 58.7},
        {"date": "2025-10-12", "date_short": "10-12", "pack_size": 60.7},
        {"date": "2025-10-13", "date_short": "10-13", "pack_size": 66.3},
        {"date": "2025-10-14", "date_short": "10-14", "pack_size": 67.39},
        {"date": "2025-10-15", "date_short": "10-15", "pack_size": 62.02},
        {"date": "2025-10-16", "date_short": "10-16", "pack_size": 134.64},
        {"date": "2025-10-17", "date_short": "10-17", "pack_size": 463.04},
        {"date": "2025-10-18", "date_short": "10-18", "pack_size": 517.47}
    ]
}';

echo "=== 导入历史流量数据 ===\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

// 解析JSON
$response = json_decode($jsonData, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("❌ JSON解析失败: " . json_last_error_msg() . "\n");
}

if ($response['code'] !== 200) {
    die("❌ API返回错误: " . $response['msg'] . "\n");
}

$data = $response['data'];
echo "📊 找到 " . count($data) . " 条历史记录\n\n";

// 连接数据库
$db = new Database();

// 获取总流量限制配置
$totalBandwidthGB = defined('TRAFFIC_TOTAL_LIMIT_GB') ? TRAFFIC_TOTAL_LIMIT_GB : 0;

$successCount = 0;
$skipCount = 0;
$errorCount = 0;

// 累计使用量（用于计算每天的累计值）
$cumulativeUsage = 0;

foreach ($data as $record) {
    $date = $record['date'];
    $packSize = floatval($record['pack_size']);
    
    // 跳过当天的数据（pack_size为0）
    if ($packSize == 0) {
        echo "⏭️  跳过 {$date}: pack_size为0\n";
        $skipCount++;
        continue;
    }
    
    // pack_size是当日使用的流量（GB）
    $dailyUsage = $packSize;
    
    // 累加到总使用量
    $cumulativeUsage += $dailyUsage;
    
    // 计算累计使用流量（前N天的总和）
    $usedBandwidth = $cumulativeUsage;
    $remainingBandwidth = $totalBandwidthGB > 0 ? max(0, $totalBandwidthGB - $usedBandwidth) : 0;
    
    try {
        // 保存到数据库
        $result = $db->saveDailyTrafficStats(
            $date,
            $totalBandwidthGB,
            $usedBandwidth,
            $remainingBandwidth,
            $dailyUsage
        );
        
        if ($result) {
            echo "✅ {$date}: 当日使用 {$dailyUsage} GB, 累计 {$cumulativeUsage} GB\n";
            $successCount++;
        } else {
            echo "❌ {$date}: 保存失败\n";
            $errorCount++;
        }
    } catch (Exception $e) {
        echo "❌ {$date}: 错误 - " . $e->getMessage() . "\n";
        $errorCount++;
    }
}

echo "\n=== 导入完成 ===\n";
echo "✅ 成功: {$successCount} 条\n";
echo "⏭️  跳过: {$skipCount} 条\n";
echo "❌ 失败: {$errorCount} 条\n";
echo "完成时间: " . date('Y-m-d H:i:s') . "\n";
