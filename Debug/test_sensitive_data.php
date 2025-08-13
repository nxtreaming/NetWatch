<?php
/**
 * 测试敏感数据过滤功能
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../database.php';
require_once '../monitor.php';
require_once '../logger.php';

// 检查登录状态
Auth::requireLogin();

echo "=== NetWatch 敏感数据过滤测试 ===\n\n";

try {
    // 初始化组件
    $db = new Database();
    $logger = new Logger();
    $monitor = new NetworkMonitor($db, $logger);
    
    // 1. 测试 getAllProxies() vs getAllProxiesSafe()
    echo "1. 测试代理数据获取方法:\n";
    
    $allProxies = $monitor->getAllProxies();
    $safeProxies = $monitor->getAllProxiesSafe();
    
    echo "   总代理数量: " . count($allProxies) . "\n";
    
    if (count($allProxies) > 0) {
        $firstProxy = $allProxies[0];
        $firstSafeProxy = $safeProxies[0];
        
        echo "\n   原始代理数据字段:\n";
        foreach ($firstProxy as $key => $value) {
            if (in_array($key, ['username', 'password'])) {
                echo "     - $key: [敏感信息] " . (empty($value) ? '(空)' : '(有值)') . "\n";
            } else {
                echo "     - $key: $value\n";
            }
        }
        
        echo "\n   安全代理数据字段:\n";
        foreach ($firstSafeProxy as $key => $value) {
            echo "     - $key: $value\n";
        }
        
        // 检查敏感字段是否被移除
        $hasUsername = array_key_exists('username', $firstSafeProxy);
        $hasPassword = array_key_exists('password', $firstSafeProxy);
        
        echo "\n   安全检查结果:\n";
        echo "     - username 字段存在: " . ($hasUsername ? "❌ 是" : "✅ 否") . "\n";
        echo "     - password 字段存在: " . ($hasPassword ? "❌ 是" : "✅ 否") . "\n";
        
        if (!$hasUsername && !$hasPassword) {
            echo "     ✅ 敏感数据过滤成功！\n";
        } else {
            echo "     ❌ 敏感数据过滤失败！\n";
        }
    }
    
    // 2. 测试分批检查的数据过滤
    echo "\n2. 测试分批检查数据过滤:\n";
    
    $batchResults = $monitor->checkProxyBatch(0, 2);
    
    if (count($batchResults) > 0) {
        $firstResult = $batchResults[0];
        
        echo "   分批检查结果字段:\n";
        foreach ($firstResult as $key => $value) {
            echo "     - $key: $value\n";
        }
        
        $hasUsername = array_key_exists('username', $firstResult);
        $hasPassword = array_key_exists('password', $firstResult);
        
        echo "\n   分批检查安全检查:\n";
        echo "     - username 字段存在: " . ($hasUsername ? "❌ 是" : "✅ 否") . "\n";
        echo "     - password 字段存在: " . ($hasPassword ? "❌ 是" : "✅ 否") . "\n";
        
        if (!$hasUsername && !$hasPassword) {
            echo "     ✅ 分批检查敏感数据过滤成功！\n";
        } else {
            echo "     ❌ 分批检查敏感数据过滤失败！\n";
        }
    }
    
    // 3. 模拟 AJAX 请求测试
    echo "\n3. 模拟 AJAX 请求测试:\n";
    
    // 模拟 checkBatch 请求
    $_GET['offset'] = 0;
    $_GET['limit'] = 2;
    
    ob_start();
    
    // 模拟 checkBatch 操作
    try {
        $offset = intval($_GET['offset'] ?? 0);
        $limit = intval($_GET['limit'] ?? 10);
        $results = $monitor->checkProxyBatch($offset, $limit);
        $response = [
            'success' => true,
            'results' => $results
        ];
        echo json_encode($response);
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'error' => '批量检查失败: ' . $e->getMessage()
        ];
        echo json_encode($response);
    }
    
    $jsonOutput = ob_get_clean();
    $responseData = json_decode($jsonOutput, true);
    
    echo "   AJAX 响应检查:\n";
    if ($responseData && $responseData['success'] && isset($responseData['results'])) {
        $results = $responseData['results'];
        if (count($results) > 0) {
            $firstResult = $results[0];
            
            $hasUsername = array_key_exists('username', $firstResult);
            $hasPassword = array_key_exists('password', $firstResult);
            
            echo "     - username 字段存在: " . ($hasUsername ? "❌ 是" : "✅ 否") . "\n";
            echo "     - password 字段存在: " . ($hasPassword ? "❌ 是" : "✅ 否") . "\n";
            
            if (!$hasUsername && !$hasPassword) {
                echo "     ✅ AJAX 响应敏感数据过滤成功！\n";
            } else {
                echo "     ❌ AJAX 响应敏感数据过滤失败！\n";
            }
        }
    } else {
        echo "     ❌ AJAX 请求失败或返回数据异常\n";
    }
    
    // 4. 检查前端页面数据
    echo "\n4. 检查前端页面数据:\n";
    
    $pageProxies = $monitor->getAllProxiesSafe();
    
    if (count($pageProxies) > 0) {
        $firstPageProxy = $pageProxies[0];
        
        $hasUsername = array_key_exists('username', $firstPageProxy);
        $hasPassword = array_key_exists('password', $firstPageProxy);
        
        echo "     - username 字段存在: " . ($hasUsername ? "❌ 是" : "✅ 否") . "\n";
        echo "     - password 字段存在: " . ($hasPassword ? "❌ 是" : "✅ 否") . "\n";
        
        if (!$hasUsername && !$hasPassword) {
            echo "     ✅ 前端页面数据敏感信息过滤成功！\n";
        } else {
            echo "     ❌ 前端页面数据敏感信息过滤失败！\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ 测试失败: " . $e->getMessage() . "\n";
    echo "错误堆栈:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== 测试完成 ===\n";
?>
