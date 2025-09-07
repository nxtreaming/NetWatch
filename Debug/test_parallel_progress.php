<?php
/**
 * 并行检测进度计算测试脚本
 */

require_once '../auth.php';
require_once '../config.php';
require_once '../parallel_monitor.php';

// 检查登录状态
Auth::requireLogin();

echo "=== 并行检测进度计算测试 ===\n\n";

// 使用配置常量创建并行监控器
$parallelMonitor = new ParallelMonitor(PARALLEL_MAX_PROCESSES, PARALLEL_BATCH_SIZE);

echo "1. 配置信息\n";
echo "   最大并行进程数: " . PARALLEL_MAX_PROCESSES . "\n";
echo "   每批次代理数量: " . PARALLEL_BATCH_SIZE . "\n\n";

// 模拟创建一些批次状态文件来测试进度计算
$tempDir = sys_get_temp_dir() . '/netwatch_parallel';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

echo "2. 创建模拟批次状态文件\n";

// 创建3个批次的模拟状态
$batchStatuses = [
    [
        'batch_id' => 'batch_0',
        'offset' => 0,
        'limit' => 400,
        'status' => 'completed',
        'checked' => 400,
        'online' => 350,
        'offline' => 50
    ],
    [
        'batch_id' => 'batch_1', 
        'offset' => 400,
        'limit' => 400,
        'status' => 'running',
        'checked' => 250,  // 部分完成
        'online' => 200,
        'offline' => 50
    ],
    [
        'batch_id' => 'batch_2',
        'offset' => 800,
        'limit' => 300,  // 最后一个批次可能少于400
        'status' => 'pending',
        'checked' => 0,
        'online' => 0,
        'offline' => 0
    ]
];

foreach ($batchStatuses as $status) {
    $statusFile = $tempDir . '/' . $status['batch_id'] . '.json';
    file_put_contents($statusFile, json_encode($status));
    echo "   创建: {$status['batch_id']} - 状态: {$status['status']}, 已检查: {$status['checked']}/{$status['limit']}\n";
}

echo "\n3. 测试进度计算\n";

// 获取进度
$progress = $parallelMonitor->getParallelProgress();

if ($progress['success']) {
    echo "   总代理数量: {$progress['total_proxies']}\n";
    echo "   已检查数量: {$progress['total_checked']}\n";
    echo "   在线数量: {$progress['total_online']}\n";
    echo "   离线数量: {$progress['total_offline']}\n";
    echo "   整体进度: {$progress['overall_progress']}%\n";
    echo "   完成批次: {$progress['completed_batches']}/{$progress['total_batches']}\n\n";
    
    // 验证进度计算是否正确
    $expectedProgress = ($progress['total_checked'] / $progress['total_proxies']) * 100;
    $actualProgress = $progress['overall_progress'];
    
    echo "4. 进度计算验证\n";
    echo "   期望进度: " . round($expectedProgress, 2) . "%\n";
    echo "   实际进度: {$actualProgress}%\n";
    
    if (abs($expectedProgress - $actualProgress) < 0.01) {
        echo "   ✅ 进度计算正确！基于实际检测的IP数量\n";
    } else {
        echo "   ❌ 进度计算错误！\n";
    }
    
    echo "\n5. 批次状态详情\n";
    foreach ($progress['batch_statuses'] as $batch) {
        $batchProgress = $batch['limit'] > 0 ? ($batch['checked'] / $batch['limit']) * 100 : 0;
        echo "   {$batch['batch_id']}: {$batch['status']} - {$batch['checked']}/{$batch['limit']} (" . round($batchProgress, 1) . "%)\n";
    }
    
} else {
    echo "   ❌ 获取进度失败: {$progress['error']}\n";
}

echo "\n6. 清理测试文件\n";
// 清理测试文件
foreach ($batchStatuses as $status) {
    $statusFile = $tempDir . '/' . $status['batch_id'] . '.json';
    if (file_exists($statusFile)) {
        unlink($statusFile);
        echo "   删除: {$status['batch_id']}.json\n";
    }
}

if (is_dir($tempDir) && count(scandir($tempDir)) == 2) { // 只有 . 和 ..
    rmdir($tempDir);
    echo "   删除临时目录\n";
}

echo "\n=== 测试完成 ===\n";
?>
