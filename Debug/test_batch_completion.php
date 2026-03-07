<?php
/**
 * 测试批次完成状态验证
 * 确保批次状态更新的准确性和一致性
 */

require_once '../auth.php';
require_once '../config.php';
require_once '../includes/Config.php';
require_once '../parallel_monitor.php';

// 检查登录状态
Auth::requireLogin();

echo "=== 批次完成状态验证测试 ===\n\n";

// 初始化并行监控器
$parallelMonitor = new ParallelMonitor(
    (int) config('monitoring.parallel_max_processes', 24),
    (int) config('monitoring.parallel_batch_size', 200)
);

// 获取当前进度
$progress = $parallelMonitor->getParallelProgress();

if (!$progress['success']) {
    echo "❌ 无法获取并行检测进度\n";
    exit(1);
}

echo "📊 当前状态概览:\n";
echo "   总体进度: " . $progress['overall_progress'] . "%\n";
echo "   已检测代理: {$progress['total_checked']}/{$progress['total_proxies']}\n";
echo "   在线代理: {$progress['total_online']}\n";
echo "   离线代理: {$progress['total_offline']}\n";
echo "   完成批次: {$progress['completed_batches']}/{$progress['total_batches']}\n\n";

// 分析批次状态
echo "🔍 批次状态详细分析:\n";
echo str_repeat("-", 100) . "\n";
printf("%-12s %-12s %-8s %-10s %-8s %-8s %-8s %-12s %-15s\n", 
    "批次ID", "状态", "进度%", "已检测", "在线", "离线", "总数", "开始时间", "结束时间");
echo str_repeat("-", 100) . "\n";

$statusCounts = [
    'running' => 0,
    'completed' => 0,
    'error' => 0,
    'cancelled' => 0,
    'other' => 0
];

foreach ($progress['batch_statuses'] as $batch) {
    $batchId = $batch['batch_id'] ?? 'unknown';
    $status = $batch['status'] ?? 'unknown';
    $batchProgress = $batch['progress'] ?? 0;
    $checked = $batch['checked'] ?? 0;
    $online = $batch['online'] ?? 0;
    $offline = $batch['offline'] ?? 0;
    $limit = $batch['limit'] ?? 0;
    $startTime = isset($batch['start_time']) ? date('H:i:s', $batch['start_time']) : 'N/A';
    $endTime = isset($batch['end_time']) ? date('H:i:s', $batch['end_time']) : 'N/A';
    
    printf("%-12s %-12s %-7.1f%% %-10d %-8d %-8d %-8d %-12s %-15s\n",
        $batchId, $status, $batchProgress, $checked, $online, $offline, $limit, $startTime, $endTime);
    
    // 统计状态
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    } else {
        $statusCounts['other']++;
    }
}

echo str_repeat("-", 100) . "\n\n";

// 状态统计
echo "📈 批次状态统计:\n";
foreach ($statusCounts as $status => $count) {
    if ($count > 0) {
        echo "   {$status}: {$count} 个批次\n";
    }
}
echo "\n";

// 完成条件验证
echo "✅ 完成条件验证:\n";
$allBatchesCompleted = $progress['completed_batches'] >= $progress['total_batches'];
$runningBatches = $statusCounts['running'];
$hasRunningBatches = $runningBatches > 0;
$progressComplete = $progress['overall_progress'] >= 100;
$allProxiesChecked = $progress['total_checked'] >= $progress['total_proxies'];

echo "   所有批次完成: " . ($allBatchesCompleted ? "是" : "否") . " ({$progress['completed_batches']}/{$progress['total_batches']})\n";
echo "   正在运行批次: " . ($hasRunningBatches ? "是" : "否") . " ({$runningBatches} 个)\n";
echo "   进度完成: " . ($progressComplete ? "是" : "否") . " ({$progress['overall_progress']}%)\n";
echo "   所有代理检测完成: " . ($allProxiesChecked ? "是" : "否") . " ({$progress['total_checked']}/{$progress['total_proxies']})\n\n";

// 前端完成判断模拟
$shouldComplete = $allBatchesCompleted && !$hasRunningBatches;
echo "🎯 前端完成判断:\n";
echo "   应该显示完成对话框: " . ($shouldComplete ? "是" : "否") . "\n";

if (!$shouldComplete) {
    echo "   原因分析:\n";
    if (!$allBatchesCompleted) {
        echo "     - 还有 " . ($progress['total_batches'] - $progress['completed_batches']) . " 个批次未完成\n";
    }
    if ($hasRunningBatches) {
        echo "     - 还有 {$runningBatches} 个批次正在运行\n";
    }
}

echo "\n";

// 异常检查
echo "⚠️  异常检查:\n";
$hasAnomalies = false;

foreach ($progress['batch_statuses'] as $batch) {
    $batchId = $batch['batch_id'] ?? 'unknown';
    $status = $batch['status'] ?? 'unknown';
    $checked = $batch['checked'] ?? 0;
    $limit = $batch['limit'] ?? 0;
    $batchProgress = $batch['progress'] ?? 0;
    
    // 检查异常情况
    if ($status === 'completed' && $batchProgress < 100) {
        echo "   批次 {$batchId}: 状态为完成但进度小于100% ({$batchProgress}%)\n";
        $hasAnomalies = true;
    }
    
    if ($status === 'completed' && $checked < $limit) {
        echo "   批次 {$batchId}: 状态为完成但检测数量不足 ({$checked}/{$limit})\n";
        $hasAnomalies = true;
    }
    
    if ($status === 'running' && $batchProgress >= 100) {
        echo "   批次 {$batchId}: 状态为运行但进度已100%\n";
        $hasAnomalies = true;
    }
    
    if ($checked > $limit) {
        echo "   批次 {$batchId}: 检测数量超过限制 ({$checked}/{$limit})\n";
        $hasAnomalies = true;
    }
}

if (!$hasAnomalies) {
    echo "   未发现异常\n";
}

echo "\n";

// 建议
echo "💡 建议:\n";
if ($progressComplete && !$shouldComplete) {
    echo "   - 进度已100%但批次未全部完成，可能存在状态同步延迟\n";
    echo "   - 建议检查批次进程是否正常运行\n";
    echo "   - 可以运行 test_batch_status.php 进行详细诊断\n";
} elseif ($shouldComplete) {
    echo "   - 所有条件已满足，应该显示完成对话框\n";
} else {
    echo "   - 检测仍在进行中，请耐心等待\n";
}

echo "\n✅ 批次完成状态验证完成\n";
