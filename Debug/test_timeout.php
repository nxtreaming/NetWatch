<?php
/**
 * 测试代理检测超时设置
 * 验证2秒超时是否正常工作
 */

require_once '../auth.php';
require_once '../config.php';
require_once '../includes/Config.php';
require_once '../database.php';
require_once '../monitor.php';
require_once '../logger.php';

// 检查登录状态
Auth::requireLogin();

echo "=== NetWatch 代理超时测试 ===\n\n";

// 显示当前配置
echo "当前配置:\n";
echo "   常规检测超时: " . config('monitoring.timeout', '未定义') . "秒\n";
echo "   快速检测超时: 2秒 (固定)\n";
echo "   连接超时: 2秒 (固定)\n\n";

// 初始化组件
$db = new Database();
$monitor = new NetworkMonitor();

// 获取一个代理进行测试
$proxies = $db->getProxiesBatch(0, 1);
if (empty($proxies)) {
    echo "❌ 没有找到代理数据，请先导入代理\n";
    exit(1);
}

$testProxy = $proxies[0];
echo "测试代理: {$testProxy['ip']}:{$testProxy['port']} ({$testProxy['type']})\n\n";

// 测试1: 常规检测
echo "🔍 测试1: 常规检测 (使用TIMEOUT常量)\n";
$startTime = microtime(true);
$result1 = $monitor->checkProxy($testProxy);
$duration1 = (microtime(true) - $startTime) * 1000;

echo "   结果: {$result1['status']}\n";
echo "   耗时: " . round($duration1, 2) . "ms\n";
if ($result1['error_message']) {
    echo "   错误: {$result1['error_message']}\n";
}
echo "\n";

// 测试2: 快速检测
echo "🚀 测试2: 快速检测 (2秒超时)\n";
$startTime = microtime(true);
$result2 = $monitor->checkProxyFast($testProxy);
$duration2 = (microtime(true) - $startTime) * 1000;

echo "   结果: {$result2['status']}\n";
echo "   耗时: " . round($duration2, 2) . "ms\n";
if ($result2['error_message']) {
    echo "   错误: {$result2['error_message']}\n";
}
echo "\n";

// 测试3: 模拟超时情况
echo "⏱️  测试3: 超时测试 (使用无效代理)\n";
$timeoutProxy = [
    'id' => 999999,
    'ip' => '192.0.2.1', // RFC3330测试用IP，应该无法连接
    'port' => 8080,
    'type' => 'http',
    'username' => null,
    'password' => null
];

$startTime = microtime(true);
$result3 = $monitor->checkProxyFast($timeoutProxy);
$duration3 = (microtime(true) - $startTime) * 1000;

echo "   结果: {$result3['status']}\n";
echo "   耗时: " . round($duration3, 2) . "ms\n";
echo "   预期: 应该在2000ms左右超时\n";
if ($result3['error_message']) {
    echo "   错误: {$result3['error_message']}\n";
}
echo "\n";

// 性能对比
echo "📊 性能对比:\n";
echo "   常规检测: " . round($duration1, 2) . "ms\n";
echo "   快速检测: " . round($duration2, 2) . "ms\n";
echo "   超时测试: " . round($duration3, 2) . "ms\n\n";

// 计算预期的并行检测时间
$totalProxies = $db->getProxyCount();
$batchSize = (int) config('monitoring.parallel_batch_size', 200);
$maxProcesses = (int) config('monitoring.parallel_max_processes', 24);

echo "📈 并行检测预估:\n";
echo "   总代理数: {$totalProxies}\n";
echo "   批次大小: {$batchSize}\n";
echo "   最大并行: {$maxProcesses}\n";

if ($totalProxies > 0) {
    $totalBatches = ceil($totalProxies / $batchSize);
    $batchRounds = ceil($totalBatches / $maxProcesses);
    
    // 假设平均每个代理检测时间为500ms（包括网络延迟）
    $avgTimePerProxy = 500; // ms
    $estimatedTimePerBatch = ($batchSize * $avgTimePerProxy) / 1000; // 秒
    $estimatedTotalTime = $batchRounds * $estimatedTimePerBatch;
    
    echo "   总批次数: {$totalBatches}\n";
    echo "   批次轮数: {$batchRounds}\n";
    echo "   预估时间: " . round($estimatedTotalTime / 60, 1) . "分钟\n";
    echo "   (基于平均500ms/代理的假设)\n";
}

echo "\n✅ 超时测试完成\n";
echo "💡 提示: 2秒超时适合快速检测，正常代理应在200ms内响应\n";
