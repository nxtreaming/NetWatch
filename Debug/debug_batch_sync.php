<?php
/**
 * 批次状态同步问题诊断脚本
 * 用于检查批次状态文件与前端显示不一致的问题
 */

require_once 'parallel_monitor.php';

echo "=== NetWatch 批次状态同步诊断 ===\n\n";

$tempDir = sys_get_temp_dir() . '/netwatch_parallel';
echo "临时目录: $tempDir\n";

if (!is_dir($tempDir)) {
    echo "❌ 临时目录不存在，没有正在进行的并行检测\n";
    exit;
}

$statusFiles = glob($tempDir . '/batch_*.json');
if (empty($statusFiles)) {
    echo "❌ 没有找到批次状态文件\n";
    exit;
}

echo "📁 找到 " . count($statusFiles) . " 个批次状态文件\n\n";

// 分析每个批次状态文件
echo "=== 详细批次状态分析 ===\n";
$totalChecked = 0;
$totalOnline = 0;
$totalOffline = 0;
$totalProxies = 0;
$completedCount = 0;
$runningCount = 0;
$errorCount = 0;

foreach ($statusFiles as $i => $statusFile) {
    $filename = basename($statusFile);
    echo "\n📄 文件 " . ($i + 1) . ": $filename\n";
    
    if (!file_exists($statusFile)) {
        echo "   ❌ 文件不存在\n";
        continue;
    }
    
    $content = file_get_contents($statusFile);
    if ($content === false) {
        echo "   ❌ 无法读取文件\n";
        continue;
    }
    
    $batchStatus = json_decode($content, true);
    if (!$batchStatus) {
        echo "   ❌ JSON 解析失败\n";
        echo "   原始内容: " . substr($content, 0, 200) . "\n";
        continue;
    }
    
    // 显示批次详情
    echo "   批次ID: " . ($batchStatus['batch_id'] ?? 'N/A') . "\n";
    echo "   状态: " . ($batchStatus['status'] ?? 'unknown') . "\n";
    echo "   进度: " . ($batchStatus['progress'] ?? 0) . "%\n";
    echo "   已检测: " . ($batchStatus['checked'] ?? 0) . "\n";
    echo "   总数: " . ($batchStatus['limit'] ?? 0) . "\n";
    echo "   在线: " . ($batchStatus['online'] ?? 0) . "\n";
    echo "   离线: " . ($batchStatus['offline'] ?? 0) . "\n";
    
    if (isset($batchStatus['start_time'])) {
        echo "   开始时间: " . date('H:i:s', $batchStatus['start_time']) . "\n";
    }
    if (isset($batchStatus['end_time'])) {
        echo "   结束时间: " . date('H:i:s', $batchStatus['end_time']) . "\n";
    }
    
    // 累计统计
    $totalChecked += $batchStatus['checked'] ?? 0;
    $totalOnline += $batchStatus['online'] ?? 0;
    $totalOffline += $batchStatus['offline'] ?? 0;
    $totalProxies += $batchStatus['limit'] ?? 0;
    
    $status = $batchStatus['status'] ?? 'unknown';
    if ($status === 'completed') {
        $completedCount++;
    } elseif ($status === 'running') {
        $runningCount++;
    } else {
        $errorCount++;
    }
}

echo "\n=== 汇总统计 ===\n";
echo "总批次数: " . count($statusFiles) . "\n";
echo "已完成批次: $completedCount\n";
echo "运行中批次: $runningCount\n";
echo "异常批次: $errorCount\n";
echo "总代理数: $totalProxies\n";
echo "已检测数: $totalChecked\n";
echo "在线数: $totalOnline\n";
echo "离线数: $totalOffline\n";

$overallProgress = $totalProxies > 0 ? ($totalChecked / $totalProxies) * 100 : 0;
echo "总体进度: " . round($overallProgress, 2) . "%\n";

// 完成条件检查
echo "\n=== 完成条件检查 ===\n";
$allBatchesCompleted = $completedCount >= count($statusFiles);
$progressComplete = $overallProgress >= 100;
$allProxiesChecked = $totalChecked >= $totalProxies;
$hasRunningBatches = $runningCount > 0;

echo "所有批次完成: " . ($allBatchesCompleted ? '是' : '否') . " ($completedCount >= " . count($statusFiles) . ")\n";
echo "进度完成: " . ($progressComplete ? '是' : '否') . " ($overallProgress >= 100)\n";
echo "所有代理检测完成: " . ($allProxiesChecked ? '是' : '否') . " ($totalChecked >= $totalProxies)\n";
echo "有运行中批次: " . ($hasRunningBatches ? '是' : '否') . " ($runningCount > 0)\n";

$shouldComplete = $allBatchesCompleted && !$hasRunningBatches && $allProxiesChecked;
echo "\n🎯 应该显示完成对话框: " . ($shouldComplete ? '是' : '否') . "\n";

if (!$shouldComplete) {
    echo "\n❌ 不满足完成条件的原因:\n";
    if (!$allBatchesCompleted) {
        echo "   - 还有 " . (count($statusFiles) - $completedCount) . " 个批次未完成\n";
    }
    if ($hasRunningBatches) {
        echo "   - 还有 $runningCount 个批次正在运行\n";
    }
    if (!$allProxiesChecked) {
        echo "   - 还有 " . ($totalProxies - $totalChecked) . " 个代理未检测\n";
    }
}

// 调用 ParallelMonitor 获取官方进度
echo "\n=== ParallelMonitor 官方数据对比 ===\n";
$parallelMonitor = new ParallelMonitor();
$officialProgress = $parallelMonitor->getParallelProgress();

if ($officialProgress['success']) {
    echo "官方数据:\n";
    echo "   总批次: " . $officialProgress['total_batches'] . "\n";
    echo "   完成批次: " . $officialProgress['completed_batches'] . "\n";
    echo "   总代理: " . $officialProgress['total_proxies'] . "\n";
    echo "   已检测: " . $officialProgress['total_checked'] . "\n";
    echo "   总体进度: " . $officialProgress['overall_progress'] . "%\n";
    
    // 检查数据一致性
    echo "\n数据一致性检查:\n";
    echo "   批次数一致: " . (count($statusFiles) == $officialProgress['total_batches'] ? '是' : '否') . "\n";
    echo "   完成批次一致: " . ($completedCount == $officialProgress['completed_batches'] ? '是' : '否') . "\n";
    echo "   总代理一致: " . ($totalProxies == $officialProgress['total_proxies'] ? '是' : '否') . "\n";
    echo "   已检测一致: " . ($totalChecked == $officialProgress['total_checked'] ? '是' : '否') . "\n";
    
    if ($completedCount != $officialProgress['completed_batches']) {
        echo "\n⚠️  完成批次数不一致！这可能是同步问题的根源\n";
        echo "   本地统计: $completedCount\n";
        echo "   官方统计: " . $officialProgress['completed_batches'] . "\n";
    }
} else {
    echo "❌ 无法获取官方进度数据: " . $officialProgress['error'] . "\n";
}

echo "\n=== 诊断完成 ===\n";
?>
