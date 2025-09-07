<?php
/**
 * NetWatch 准备阶段性能测试工具
 * 专门测试获取代理数量等准备操作的性能
 */

require_once '../auth.php';
require_once '../config.php';
require_once '../database.php';
require_once '../monitor.php';

// 检查登录状态
Auth::requireLogin();

echo "<h2>🔍 NetWatch 准备阶段性能测试</h2>\n";
echo "<pre>\n";

// 1. 系统信息
echo "=== 系统信息 ===\n";
echo "PHP版本: " . PHP_VERSION . "\n";
echo "内存限制: " . ini_get('memory_limit') . "\n";
echo "执行时间限制: " . ini_get('max_execution_time') . "秒\n";
echo "当前时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 2. 数据库连接测试
    echo "=== 数据库连接测试 ===\n";
    $startTime = microtime(true);
    $db = new Database();
    $dbConnectTime = round((microtime(true) - $startTime) * 1000, 2);
    echo "数据库连接时间: {$dbConnectTime}ms\n";
    
    // 3. 代理数量查询测试（多次测试）
    echo "\n=== 代理数量查询测试 ===\n";
    $times = [];
    for ($i = 1; $i <= 5; $i++) {
        $startTime = microtime(true);
        $count = $db->getProxyCount();
        $queryTime = round((microtime(true) - $startTime) * 1000, 2);
        $times[] = $queryTime;
        echo "第{$i}次查询: {$queryTime}ms (代理数量: {$count})\n";
    }
    
    $avgTime = round(array_sum($times) / count($times), 2);
    $minTime = min($times);
    $maxTime = max($times);
    echo "平均查询时间: {$avgTime}ms\n";
    echo "最快查询时间: {$minTime}ms\n";
    echo "最慢查询时间: {$maxTime}ms\n";
    
    // 4. 数据库表结构分析
    echo "\n=== 数据库表结构分析 ===\n";
    $startTime = microtime(true);
    $stmt = $db->pdo->query("PRAGMA table_info(proxies)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $structureTime = round((microtime(true) - $startTime) * 1000, 2);
    echo "获取表结构时间: {$structureTime}ms\n";
    echo "代理表字段数量: " . count($columns) . "\n";
    
    // 5. 索引分析
    echo "\n=== 索引分析 ===\n";
    $startTime = microtime(true);
    $stmt = $db->pdo->query("PRAGMA index_list(proxies)");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $indexTime = round((microtime(true) - $startTime) * 1000, 2);
    echo "获取索引信息时间: {$indexTime}ms\n";
    echo "代理表索引数量: " . count($indexes) . "\n";
    
    if (!empty($indexes)) {
        foreach ($indexes as $index) {
            echo "- 索引: {$index['name']} (唯一: " . ($index['unique'] ? '是' : '否') . ")\n";
        }
    }
    
    // 6. 数据库文件大小
    echo "\n=== 数据库文件信息 ===\n";
    $dbFile = 'netwatch.db';
    if (file_exists($dbFile)) {
        $fileSize = filesize($dbFile);
        $fileSizeMB = round($fileSize / 1024 / 1024, 2);
        echo "数据库文件大小: {$fileSizeMB}MB ({$fileSize} bytes)\n";
        echo "文件修改时间: " . date('Y-m-d H:i:s', filemtime($dbFile)) . "\n";
    } else {
        echo "数据库文件不存在或路径错误\n";
    }
    
    // 7. 网络监控器初始化测试
    echo "\n=== NetworkMonitor 初始化测试 ===\n";
    $startTime = microtime(true);
    $monitor = new NetworkMonitor();
    $monitorInitTime = round((microtime(true) - $startTime) * 1000, 2);
    echo "NetworkMonitor初始化时间: {$monitorInitTime}ms\n";
    
    // 8. 通过监控器获取代理数量
    echo "\n=== 通过NetworkMonitor获取代理数量测试 ===\n";
    $times = [];
    for ($i = 1; $i <= 3; $i++) {
        $startTime = microtime(true);
        $count = $monitor->getProxyCount();
        $queryTime = round((microtime(true) - $startTime) * 1000, 2);
        $times[] = $queryTime;
        echo "第{$i}次查询: {$queryTime}ms (代理数量: {$count})\n";
    }
    
    $avgTime = round(array_sum($times) / count($times), 2);
    echo "平均查询时间: {$avgTime}ms\n";
    
    // 9. 完整的AJAX请求模拟
    echo "\n=== AJAX请求模拟测试 ===\n";
    $startTime = microtime(true);
    
    // 模拟AJAX请求的完整流程
    ob_start();
    try {
        $count = $monitor->getProxyCount();
        $response = [
            'success' => true,
            'count' => $count
        ];
        $jsonResponse = json_encode($response);
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'error' => '获取代理数量失败: ' . $e->getMessage()
        ];
        $jsonResponse = json_encode($response);
    }
    ob_end_clean();
    
    $ajaxTime = round((microtime(true) - $startTime) * 1000, 2);
    echo "AJAX请求模拟时间: {$ajaxTime}ms\n";
    echo "响应数据大小: " . strlen($jsonResponse) . " bytes\n";
    echo "响应内容: {$jsonResponse}\n";
    
    // 10. 性能分析和建议
    echo "\n=== 性能分析和建议 ===\n";
    
    $totalPrepareTime = $dbConnectTime + $avgTime + $monitorInitTime + $ajaxTime;
    echo "预估总准备时间: {$totalPrepareTime}ms\n";
    
    if ($totalPrepareTime > 1000) {
        echo "⚠️  准备时间较长，建议优化:\n";
        if ($dbConnectTime > 200) {
            echo "- 数据库连接时间过长，检查数据库文件位置和磁盘性能\n";
        }
        if ($avgTime > 500) {
            echo "- 代理数量查询时间过长，考虑添加索引或优化查询\n";
        }
        if ($fileSizeMB > 100) {
            echo "- 数据库文件较大，考虑清理历史数据或优化表结构\n";
        }
    } else {
        echo "✅ 准备阶段性能良好\n";
    }
    
    // 11. 优化建议
    echo "\n=== 优化建议 ===\n";
    echo "1. 如果代理数量查询慢，可以考虑:\n";
    echo "   - 为proxies表添加适当的索引\n";
    echo "   - 定期清理无效的代理数据\n";
    echo "   - 使用缓存机制减少重复查询\n\n";
    
    echo "2. 如果数据库连接慢，可以考虑:\n";
    echo "   - 检查数据库文件的磁盘I/O性能\n";
    echo "   - 使用内存数据库或SSD存储\n";
    echo "   - 优化SQLite配置参数\n\n";
    
    echo "3. 前端优化建议:\n";
    echo "   - 使用缓存避免重复的getProxyCount请求\n";
    echo "   - 在页面加载时预先获取代理数量\n";
    echo "   - 添加加载动画提升用户体验\n";
    
} catch (Exception $e) {
    echo "❌ 测试过程中发生错误: " . $e->getMessage() . "\n";
    echo "错误文件: " . $e->getFile() . "\n";
    echo "错误行号: " . $e->getLine() . "\n";
}

echo "\n=== 测试完成 ===\n";
echo "测试时间: " . date('Y-m-d H:i:s') . "\n";
echo "</pre>\n";
?>
