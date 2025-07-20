<?php
/**
 * 并行代理检测功能测试脚本
 */

require_once 'config.php';
require_once 'database.php';
require_once 'monitor.php';
require_once 'parallel_monitor.php';

// 并行检测配置常量
define('PARALLEL_MAX_PROCESSES', 6);    // 最大并行进程数
define('PARALLEL_BATCH_SIZE', 400);     // 每批次代理数量

echo "=== NetWatch 并行检测功能测试 ===\n\n";

// 检查系统环境
echo "1. 系统环境检查\n";
echo "   PHP版本: " . PHP_VERSION . "\n";
echo "   操作系统: " . PHP_OS . "\n";
echo "   最大执行时间: " . ini_get('max_execution_time') . "秒\n";
echo "   内存限制: " . ini_get('memory_limit') . "\n";
echo "   临时目录: " . sys_get_temp_dir() . "\n\n";

// 检查数据库连接
echo "2. 数据库连接测试\n";
try {
    $db = new Database();
    $proxyCount = $db->getProxyCount();
    echo "   ✅ 数据库连接成功\n";
    echo "   📊 代理总数: {$proxyCount}\n\n";
} catch (Exception $e) {
    echo "   ❌ 数据库连接失败: " . $e->getMessage() . "\n";
    exit(1);
}

// 检查代理数据
if ($proxyCount == 0) {
    echo "⚠️ 没有代理数据，无法进行并行检测测试\n";
    echo "请先导入一些代理数据后再运行此测试\n";
    exit(0);
}

// 计算批次信息
$batchSize = PARALLEL_BATCH_SIZE;
$totalBatches = ceil($proxyCount / $batchSize);
$maxProcesses = PARALLEL_MAX_PROCESSES;

echo "3. 并行检测配置\n";
echo "   每批次代理数: {$batchSize}\n";
echo "   总批次数: {$totalBatches}\n";
echo "   最大并行进程数: {$maxProcesses}\n";
echo "   预计并行度: " . min($totalBatches, $maxProcesses) . "\n\n";

// 检查临时目录权限
echo "4. 临时目录权限测试\n";
$tempDir = sys_get_temp_dir() . '/netwatch_parallel';
try {
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    $testFile = $tempDir . '/test_' . time() . '.txt';
    file_put_contents($testFile, 'test');
    
    if (file_exists($testFile)) {
        unlink($testFile);
        echo "   ✅ 临时目录可读写\n";
    } else {
        throw new Exception('无法创建测试文件');
    }
} catch (Exception $e) {
    echo "   ❌ 临时目录权限错误: " . $e->getMessage() . "\n";
    exit(1);
}

// 检查工作进程脚本
echo "\n5. 工作进程脚本检查\n";
$workerScript = __DIR__ . '/parallel_worker.php';
if (file_exists($workerScript)) {
    echo "   ✅ 工作进程脚本存在: {$workerScript}\n";
} else {
    echo "   ❌ 工作进程脚本不存在: {$workerScript}\n";
    exit(1);
}

// 测试单个批次处理
echo "\n6. 单批次处理测试\n";
try {
    $monitor = new NetworkMonitor();
    $testBatch = $monitor->checkProxyBatch(0, min(5, $proxyCount)); // 测试前5个代理
    echo "   ✅ 单批次处理成功，检查了 " . count($testBatch) . " 个代理\n";
    
    foreach ($testBatch as $i => $result) {
        $status = $result['status'] === 'online' ? '✅' : '❌';
        echo "   {$status} {$result['ip']}:{$result['port']} - {$result['status']} ({$result['response_time']}ms)\n";
        if ($i >= 2) break; // 只显示前3个
    }
} catch (Exception $e) {
    echo "   ❌ 单批次处理失败: " . $e->getMessage() . "\n";
}

// 测试并行监控器初始化
echo "\n7. 并行监控器初始化测试\n";
try {
    // 创建并行监控器：使用配置常量
    $parallelMonitor = new ParallelMonitor(PARALLEL_MAX_PROCESSES, PARALLEL_BATCH_SIZE);
    echo "   ✅ 并行监控器初始化成功\n";
} catch (Exception $e) {
    echo "   ❌ 并行监控器初始化失败: " . $e->getMessage() . "\n";
    exit(1);
}

// 询问是否进行完整并行测试
echo "\n8. 完整并行检测测试\n";
echo "   是否要进行完整的并行检测测试？\n";
echo "   这将检测所有 {$proxyCount} 个代理，可能需要几分钟时间。\n";
echo "   输入 'yes' 继续，或按回车跳过: ";

$input = trim(fgets(STDIN));
if (strtolower($input) === 'yes') {
    echo "\n   🚀 开始并行检测测试...\n";
    
    $startTime = microtime(true);
    
    try {
        $result = $parallelMonitor->checkAllProxiesParallel();
        
        $totalTime = microtime(true) - $startTime;
        
        if ($result['success']) {
            echo "   ✅ 并行检测完成！\n";
            echo "   📊 检测结果:\n";
            echo "      - 总代理数: {$result['total_proxies']}\n";
            echo "      - 总批次数: {$result['total_batches']}\n";
            echo "      - 已检查: {$result['checked']}\n";
            echo "      - 在线: {$result['online']}\n";
            echo "      - 离线: {$result['offline']}\n";
            echo "      - 执行时间: " . round($totalTime, 2) . "秒\n";
            echo "      - 平均速度: " . round($result['checked'] / $totalTime, 2) . " 代理/秒\n";
            
            // 显示批次详情
            if (!empty($result['batch_results'])) {
                echo "\n   📋 批次执行详情:\n";
                foreach ($result['batch_results'] as $i => $batch) {
                    $batchTime = $batch['end_time'] - $batch['start_time'];
                    echo "      批次 {$batch['batch_id']}: {$batch['checked']} 个代理，用时 {$batchTime}秒\n";
                    if ($i >= 4) {
                        echo "      ... (显示前5个批次)\n";
                        break;
                    }
                }
            }
        } else {
            echo "   ❌ 并行检测失败: " . $result['error'] . "\n";
        }
        
    } catch (Exception $e) {
        echo "   ❌ 并行检测异常: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ⏭️ 跳过完整并行检测测试\n";
}

// 性能对比建议
echo "\n9. 性能优化建议\n";
$estimatedSerialTime = $proxyCount * 5; // 假设每个代理5秒
$estimatedParallelTime = ceil($proxyCount / $batchSize) * 5 / $maxProcesses; // 并行估算

echo "   串行检测预计时间: " . round($estimatedSerialTime / 60, 1) . " 分钟\n";
echo "   并行检测预计时间: " . round($estimatedParallelTime / 60, 1) . " 分钟\n";
echo "   性能提升: " . round($estimatedSerialTime / $estimatedParallelTime, 1) . "x\n";

if ($proxyCount > 1000) {
    echo "   💡 建议: 对于大量代理，并行检测能显著提升效率\n";
} else {
    echo "   💡 建议: 代理数量较少时，串行和并行检测差异不大\n";
}

// 清理测试文件
try {
    $files = glob($tempDir . '/test_*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
} catch (Exception $e) {
    // 忽略清理错误
}

echo "\n=== 测试完成 ===\n";
echo "如果所有测试都通过，您可以在Web界面中使用并行检测功能。\n";
echo "点击 '🚀 并行检测' 按钮来体验高性能的代理检测！\n";
?>
