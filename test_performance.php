<?php
/**
 * NetWatch 性能测试和诊断脚本
 */

require_once 'config.php';
require_once 'database.php';
require_once 'monitor.php';

echo "=== NetWatch 性能测试和诊断 ===\n\n";

try {
    $monitor = new NetworkMonitor();
    
    // 1. 基本信息
    echo "1. 系统信息:\n";
    echo "   PHP版本: " . PHP_VERSION . "\n";
    echo "   内存限制: " . ini_get('memory_limit') . "\n";
    echo "   执行时间限制: " . ini_get('max_execution_time') . "秒\n";
    echo "   超时设置: " . (defined('TIMEOUT') ? TIMEOUT : '未定义') . "秒\n\n";
    
    // 2. 数据库统计
    echo "2. 数据库统计:\n";
    $totalCount = $monitor->getProxyCount();
    $stats = $monitor->getStats();
    echo "   总代理数: $totalCount\n";
    echo "   在线: {$stats['online']}\n";
    echo "   离线: {$stats['offline']}\n";
    echo "   未知: {$stats['unknown']}\n";
    echo "   平均响应时间: " . number_format($stats['avg_response_time'], 2) . "ms\n\n";
    
    // 3. 批量检查性能测试
    echo "3. 批量检查性能测试:\n";
    
    $batchSizes = [5, 10, 20];
    foreach ($batchSizes as $batchSize) {
        echo "   测试批量大小: $batchSize\n";
        
        $startTime = microtime(true);
        $results = $monitor->checkProxyBatch(0, $batchSize);
        $endTime = microtime(true);
        
        $executionTime = ($endTime - $startTime) * 1000;
        $avgTimePerProxy = count($results) > 0 ? $executionTime / count($results) : 0;
        
        echo "     - 实际检查: " . count($results) . " 个代理\n";
        echo "     - 总用时: " . number_format($executionTime, 2) . "ms\n";
        echo "     - 平均每个: " . number_format($avgTimePerProxy, 2) . "ms\n";
        
        $onlineCount = 0;
        $offlineCount = 0;
        foreach ($results as $result) {
            if ($result['status'] === 'online') $onlineCount++;
            if ($result['status'] === 'offline') $offlineCount++;
        }
        echo "     - 在线: $onlineCount, 离线: $offlineCount\n\n";
    }
    
    // 4. 预估全量检查时间
    echo "4. 全量检查时间预估:\n";
    if ($totalCount > 0) {
        // 基于20个代理批量的平均时间
        $sampleStartTime = microtime(true);
        $sampleResults = $monitor->checkProxyBatch(0, min(20, $totalCount));
        $sampleEndTime = microtime(true);
        
        $sampleTime = ($sampleEndTime - $sampleStartTime) * 1000;
        $avgTimePerBatch = $sampleTime;
        $totalBatches = ceil($totalCount / 20);
        $estimatedTotalTime = ($avgTimePerBatch * $totalBatches) / 1000; // 转换为秒
        
        echo "   样本批量(20个): " . number_format($sampleTime, 2) . "ms\n";
        echo "   总批次数: $totalBatches\n";
        echo "   预估总时间: " . number_format($estimatedTotalTime, 2) . "秒 (" . number_format($estimatedTotalTime/60, 2) . "分钟)\n\n";
        
        if ($estimatedTotalTime > 60) {
            echo "   ⚠️  警告: 预估时间超过1分钟，可能会遇到超时问题\n";
            echo "   建议:\n";
            echo "   - 减少批量大小到10-15个\n";
            echo "   - 优化网络连接\n";
            echo "   - 增加服务器超时设置\n\n";
        }
    }
    
    // 5. 网络连接测试
    echo "5. 网络连接测试:\n";
    $testUrl = defined('TEST_URL') ? TEST_URL : 'http://www.google.com';
    echo "   测试URL: $testUrl\n";
    
    $startTime = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'NetWatch Performance Test'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $networkTime = (microtime(true) - $startTime) * 1000;
    
    if ($response !== false && $httpCode === 200) {
        echo "   ✅ 网络连接正常\n";
        echo "   响应时间: " . number_format($networkTime, 2) . "ms\n\n";
    } else {
        echo "   ❌ 网络连接异常\n";
        echo "   错误: " . ($curlError ?: "HTTP $httpCode") . "\n";
        echo "   响应时间: " . number_format($networkTime, 2) . "ms\n\n";
    }
    
    // 6. 优化建议
    echo "6. 性能优化建议:\n";
    
    if ($totalCount > 1000) {
        echo "   📊 大量代理检测优化:\n";
        echo "   - 当前代理数量: $totalCount (较多)\n";
        echo "   - 建议使用较小的批量大小(10-15个)\n";
        echo "   - 考虑分时段检查，避免高峰期\n";
        echo "   - 可以实现增量检查，优先检查失败的代理\n\n";
    }
    
    if (ini_get('max_execution_time') < 300) {
        echo "   ⏱️  执行时间限制优化:\n";
        echo "   - 当前限制: " . ini_get('max_execution_time') . "秒\n";
        echo "   - 建议在php.ini中设置: max_execution_time = 300\n";
        echo "   - 或在代码中使用: set_time_limit(300)\n\n";
    }
    
    $memoryLimit = ini_get('memory_limit');
    if (preg_match('/(\d+)([MG]?)/', $memoryLimit, $matches)) {
        $memoryMB = $matches[1];
        if ($matches[2] === 'G') $memoryMB *= 1024;
        
        if ($memoryMB < 256) {
            echo "   💾 内存限制优化:\n";
            echo "   - 当前限制: $memoryLimit\n";
            echo "   - 建议设置: memory_limit = 256M\n\n";
        }
    }
    
    echo "=== 性能测试完成 ===\n";
    
} catch (Exception $e) {
    echo "❌ 测试失败: " . $e->getMessage() . "\n";
    echo "错误详情: " . $e->getTraceAsString() . "\n";
}
