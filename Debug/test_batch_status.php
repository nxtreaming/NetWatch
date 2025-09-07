<?php
/**
 * 测试批次状态文件的读写和同步
 * 验证状态更新的原子性和一致性
 */

require_once '../auth.php';
require_once '../config.php';

// 检查登录状态
Auth::requireLogin();

echo "=== 批次状态文件测试 ===\n\n";

$tempDir = sys_get_temp_dir() . '/netwatch_parallel';
echo "临时目录: {$tempDir}\n";

// 检查临时目录
if (!is_dir($tempDir)) {
    echo "❌ 临时目录不存在\n";
    exit(1);
}

// 查找所有批次状态文件
$statusFiles = glob($tempDir . '/batch_*.json');
$totalFiles = count($statusFiles);

echo "找到 {$totalFiles} 个批次状态文件\n\n";

if ($totalFiles == 0) {
    echo "💡 提示: 没有找到批次状态文件，可能没有正在运行的并行检测\n";
    exit(0);
}

// 分析每个批次状态
$totalChecked = 0;
$totalOnline = 0;
$totalOffline = 0;
$completedBatches = 0;
$totalProxies = 0;
$batchDetails = [];

echo "📊 批次状态详情:\n";
echo str_repeat("-", 80) . "\n";
printf("%-10s %-10s %-8s %-8s %-8s %-8s %-12s %s\n", 
    "批次ID", "状态", "进度", "已检测", "在线", "离线", "总数", "最后更新");
echo str_repeat("-", 80) . "\n";

foreach ($statusFiles as $index => $statusFile) {
    $batchStatus = json_decode(file_get_contents($statusFile), true);
    
    if (!$batchStatus) {
        echo "❌ 无法读取文件: " . basename($statusFile) . "\n";
        continue;
    }
    
    $batchId = $batchStatus['batch_id'] ?? 'unknown';
    $status = $batchStatus['status'] ?? 'unknown';
    $progress = $batchStatus['progress'] ?? 0;
    $checked = $batchStatus['checked'] ?? 0;
    $online = $batchStatus['online'] ?? 0;
    $offline = $batchStatus['offline'] ?? 0;
    $limit = $batchStatus['limit'] ?? 0;
    $lastUpdate = isset($batchStatus['start_time']) ? 
        date('H:i:s', $batchStatus['start_time']) : 'N/A';
    
    printf("%-10s %-10s %-7.1f%% %-8d %-8d %-8d %-12d %s\n",
        $batchId, $status, $progress, $checked, $online, $offline, $limit, $lastUpdate);
    
    // 累计统计
    $totalChecked += $checked;
    $totalOnline += $online;
    $totalOffline += $offline;
    $totalProxies += $limit;
    
    if ($status === 'completed') {
        $completedBatches++;
    }
    
    $batchDetails[] = [
        'file' => $statusFile,
        'status' => $batchStatus,
        'file_time' => filemtime($statusFile),
        'file_size' => filesize($statusFile)
    ];
}

echo str_repeat("-", 80) . "\n";
printf("%-10s %-10s %-7.1f%% %-8d %-8d %-8d %-12d %s\n",
    "总计", "{$completedBatches}/{$totalFiles}", 
    $totalProxies > 0 ? ($totalChecked / $totalProxies) * 100 : 0,
    $totalChecked, $totalOnline, $totalOffline, $totalProxies, "");
echo str_repeat("-", 80) . "\n\n";

// 分析完成状态
$overallProgress = $totalProxies > 0 ? ($totalChecked / $totalProxies) * 100 : 0;
$allBatchesCompleted = $completedBatches >= $totalFiles;
$allProxiesChecked = $totalChecked >= $totalProxies;

echo "🎯 完成状态分析:\n";
echo "   总体进度: " . round($overallProgress, 2) . "%\n";
echo "   所有批次完成: " . ($allBatchesCompleted ? "是" : "否") . " ({$completedBatches}/{$totalFiles})\n";
echo "   所有代理检测完成: " . ($allProxiesChecked ? "是" : "否") . " ({$totalChecked}/{$totalProxies})\n";

// 判断是否应该完成
$shouldComplete = $overallProgress >= 100 && ($allBatchesCompleted || $allProxiesChecked);
echo "   应该显示完成: " . ($shouldComplete ? "是" : "否") . "\n\n";

// 文件一致性检查
echo "🔍 文件一致性检查:\n";
$now = time();
foreach ($batchDetails as $detail) {
    $fileName = basename($detail['file']);
    $fileAge = $now - $detail['file_time'];
    $status = $detail['status'];
    
    echo "   {$fileName}:\n";
    echo "     文件大小: {$detail['file_size']} 字节\n";
    echo "     最后修改: {$fileAge} 秒前\n";
    echo "     状态: {$status['status']}\n";
    
    // 检查是否有异常
    if ($status['status'] === 'completed' && $status['progress'] < 100) {
        echo "     ⚠️  警告: 状态为完成但进度小于100%\n";
    }
    
    if ($status['checked'] > $status['limit']) {
        echo "     ⚠️  警告: 已检测数量超过限制\n";
    }
    
    if ($fileAge > 300 && $status['status'] === 'running') {
        echo "     ⚠️  警告: 运行状态但文件超过5分钟未更新\n";
    }
    
    echo "\n";
}

// 模拟前端完成判断逻辑
echo "🖥️  前端完成判断模拟:\n";
echo "   进度完成: " . ($overallProgress >= 100 ? "是" : "否") . "\n";
echo "   批次完成: " . ($allBatchesCompleted ? "是" : "否") . "\n";
echo "   代理完成: " . ($allProxiesChecked ? "是" : "否") . "\n";
echo "   最终判断: " . ($shouldComplete ? "应该完成" : "继续等待") . "\n\n";

if (!$shouldComplete && $overallProgress >= 100) {
    echo "💡 建议: 进度已100%但未完成，可能存在批次状态同步延迟\n";
    echo "   - 检查是否有批次进程卡住\n";
    echo "   - 验证文件锁是否正常工作\n";
    echo "   - 考虑调整超时时间\n";
}

echo "\n✅ 批次状态测试完成\n";
