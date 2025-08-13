<?php
/**
 * 调试 checkAllProxies 功能
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../database.php';
require_once '../monitor.php';
require_once '../logger.php';

// 检查登录状态
Auth::requireLogin();

echo "=== NetWatch checkAllProxies 调试 ===\n\n";

try {
    // 初始化组件
    $db = new Database();
    $logger = new Logger();
    $monitor = new NetworkMonitor($db, $logger);
    
    // 1. 检查数据库中的代理数量
    $proxies = $db->getAllProxies();
    echo "1. 数据库中的代理数量: " . count($proxies) . "\n";
    
    if (count($proxies) === 0) {
        echo "   ❌ 数据库中没有代理数据！\n";
        echo "   请先导入代理数据。\n\n";
        exit(1);
    }
    
    // 显示前5个代理信息
    echo "   前5个代理信息:\n";
    for ($i = 0; $i < min(5, count($proxies)); $i++) {
        $proxy = $proxies[$i];
        echo "   - ID: {$proxy['id']}, {$proxy['type']}://{$proxy['ip']}:{$proxy['port']}\n";
    }
    echo "\n";
    
    // 2. 检查配置常量
    echo "2. 配置检查:\n";
    echo "   TEST_URL: " . (defined('TEST_URL') ? TEST_URL : '未定义') . "\n";
    echo "   TIMEOUT: " . (defined('TIMEOUT') ? TIMEOUT : '未定义') . "\n";
    echo "   LOG_PATH: " . (defined('LOG_PATH') ? LOG_PATH : '未定义') . "\n";
    echo "\n";
    
    // 3. 测试单个代理检查
    echo "3. 测试单个代理检查:\n";
    $testProxy = $proxies[0];
    echo "   测试代理: {$testProxy['type']}://{$testProxy['ip']}:{$testProxy['port']}\n";
    
    $startTime = microtime(true);
    $result = $monitor->checkProxy($testProxy);
    $duration = microtime(true) - $startTime;
    
    echo "   结果: {$result['status']}\n";
    echo "   响应时间: " . round($result['response_time'], 2) . "ms\n";
    echo "   检查耗时: " . round($duration * 1000, 2) . "ms\n";
    if ($result['error_message']) {
        echo "   错误信息: {$result['error_message']}\n";
    }
    echo "\n";
    
    // 4. 测试少量代理的批量检查
    echo "4. 测试批量检查（前3个代理）:\n";
    $testProxies = array_slice($proxies, 0, 3);
    
    $startTime = microtime(true);
    $results = [];
    
    foreach ($testProxies as $proxy) {
        echo "   检查 {$proxy['ip']}:{$proxy['port']}... ";
        $result = $monitor->checkProxy($proxy);
        $results[] = array_merge($proxy, $result);
        echo $result['status'] . "\n";
        
        // 添加延迟
        usleep(100000); // 0.1秒
    }
    
    $totalDuration = microtime(true) - $startTime;
    echo "   批量检查完成，总耗时: " . round($totalDuration, 2) . "秒\n";
    echo "\n";
    
    // 5. 检查日志文件
    echo "5. 日志文件检查:\n";
    $logPath = LOG_PATH;
    if (is_dir($logPath)) {
        $logFiles = glob($logPath . '*.log');
        echo "   日志目录: $logPath\n";
        echo "   日志文件数量: " . count($logFiles) . "\n";
        
        if (count($logFiles) > 0) {
            $latestLog = end($logFiles);
            echo "   最新日志文件: " . basename($latestLog) . "\n";
            
            // 读取最后几行日志
            $lines = file($latestLog);
            if ($lines) {
                echo "   最后5行日志:\n";
                $lastLines = array_slice($lines, -5);
                foreach ($lastLines as $line) {
                    echo "     " . trim($line) . "\n";
                }
            }
        }
    } else {
        echo "   ❌ 日志目录不存在: $logPath\n";
    }
    echo "\n";
    
    // 6. 模拟 AJAX 请求
    echo "6. 模拟 AJAX checkAll 请求:\n";
    echo "   开始模拟完整的 checkAllProxies() 调用...\n";
    
    $startTime = microtime(true);
    try {
        $results = $monitor->checkAllProxies();
        $duration = microtime(true) - $startTime;
        
        echo "   ✅ 检查完成！\n";
        echo "   总耗时: " . round($duration, 2) . "秒\n";
        echo "   检查结果数量: " . count($results) . "\n";
        
        // 统计结果
        $online = 0;
        $offline = 0;
        foreach ($results as $result) {
            if ($result['status'] === 'online') {
                $online++;
            } else {
                $offline++;
            }
        }
        
        echo "   在线: $online, 离线: $offline\n";
        
    } catch (Exception $e) {
        echo "   ❌ 检查失败: " . $e->getMessage() . "\n";
        echo "   错误堆栈:\n";
        echo "   " . $e->getTraceAsString() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ 初始化失败: " . $e->getMessage() . "\n";
    echo "错误堆栈:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== 调试完成 ===\n";
?>
