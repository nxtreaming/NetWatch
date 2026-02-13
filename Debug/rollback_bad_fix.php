<?php
/**
 * 回滚 fix_crossday_traffic_loss.php 的错误修复
 * 使用错误修复输出中的"原"值恢复数据
 * 
 * 用法：
 *   ?apply=1  执行回滚
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../auth.php';
    Auth::requireLogin();
    header('Content-Type: text/plain; charset=utf-8');
}

$db = new Database();

$applyMode = false;
if (php_sapi_name() === 'cli') {
    $applyMode = in_array('--apply', $argv ?? []);
} else {
    $applyMode = isset($_GET['apply']) && $_GET['apply'] === '1';
}

// 错误修复前的原始数据（从诊断输出和修复输出中提取）
$originalData = [
    ['date' => '2026-02-01', 'daily_usage' => 1026.29, 'used_bandwidth' => 1026.29],
    ['date' => '2026-02-02', 'daily_usage' => 1041.55, 'used_bandwidth' => 2067.84],
    ['date' => '2026-02-03', 'daily_usage' => 1020.44, 'used_bandwidth' => 3088.28],
    ['date' => '2026-02-04', 'daily_usage' => 1264.55, 'used_bandwidth' => 4352.83],
    ['date' => '2026-02-05', 'daily_usage' => 1177.48, 'used_bandwidth' => 5530.32],
    ['date' => '2026-02-06', 'daily_usage' => 1188.02, 'used_bandwidth' => 6718.34],
    ['date' => '2026-02-07', 'daily_usage' => 987.41,  'used_bandwidth' => 7705.75],
    ['date' => '2026-02-08', 'daily_usage' => 1209.64, 'used_bandwidth' => 8915.40],
    ['date' => '2026-02-09', 'daily_usage' => 1236.97, 'used_bandwidth' => 10152.37],
    ['date' => '2026-02-10', 'daily_usage' => 1095.81, 'used_bandwidth' => 11248.17],
    ['date' => '2026-02-11', 'daily_usage' => 1117.32, 'used_bandwidth' => 12365.49],
    ['date' => '2026-02-12', 'daily_usage' => 1100.23, 'used_bandwidth' => 13465.72],
    ['date' => '2026-02-13', 'daily_usage' => 1249.17, 'used_bandwidth' => 14714.89],
    ['date' => '2026-02-14', 'daily_usage' => 35.25,   'used_bandwidth' => 14750.14],
];

$totalBandwidth = defined('TRAFFIC_TOTAL_LIMIT_GB') ? TRAFFIC_TOTAL_LIMIT_GB : 0;

echo "=== 回滚错误修复 ===\n";
echo "模式: " . ($applyMode ? "⚠️  实际回滚" : "📋 预览") . "\n\n";

echo str_pad("日期", 13) . str_pad("daily_usage", 16) . str_pad("used_bw", 16) . "\n";
echo str_repeat("-", 45) . "\n";

foreach ($originalData as $row) {
    echo str_pad($row['date'], 13) . 
         str_pad(number_format($row['daily_usage'], 2) . " GB", 16) . 
         str_pad(number_format($row['used_bandwidth'], 2) . " GB", 16) . "\n";
    
    if ($applyMode) {
        $remaining = $totalBandwidth > 0 ? max(0, $totalBandwidth - $row['used_bandwidth']) : 0;
        $db->saveDailyTrafficStats(
            $row['date'],
            $totalBandwidth,
            $row['used_bandwidth'],
            $remaining,
            $row['daily_usage']
        );
    }
}

echo str_repeat("-", 45) . "\n";

if ($applyMode) {
    echo "\n✅ 回滚完成！数据已恢复到错误修复前的原始值。\n";
    echo "现在可以运行 fix_crossday_traffic_loss.php 进行正确的修复。\n";
} else {
    echo "\n📋 预览模式，数据未修改。\n";
    echo "执行回滚: ?apply=1\n";
}
