<?php
/**
 * 分页功能测试脚本
 * 测试NetWatch系统的分页功能是否正常工作
 */

require_once 'config.php';
require_once 'database.php';
require_once 'monitor.php';

echo "<h1>NetWatch 分页功能测试</h1>\n";

try {
    $db = new Database();
    $monitor = new NetworkMonitor($db);
    
    // 1. 测试获取代理总数
    echo "<h2>1. 测试代理总数获取</h2>\n";
    $totalProxies = $monitor->getProxyCount();
    echo "代理总数: {$totalProxies}<br>\n";
    
    if ($totalProxies == 0) {
        echo "<strong>⚠️ 警告：没有找到代理数据，请先导入一些代理进行测试</strong><br>\n";
        exit;
    }
    
    // 2. 测试分页参数计算
    echo "<h2>2. 测试分页参数计算</h2>\n";
    $perPage = 200;
    $totalPages = ceil($totalProxies / $perPage);
    echo "每页显示: {$perPage} 条<br>\n";
    echo "总页数: {$totalPages}<br>\n";
    
    // 3. 测试各页数据获取
    echo "<h2>3. 测试分页数据获取</h2>\n";
    
    for ($page = 1; $page <= min(3, $totalPages); $page++) {
        echo "<h3>第 {$page} 页</h3>\n";
        
        $proxies = $monitor->getProxiesPaginatedSafe($page, $perPage);
        $actualCount = count($proxies);
        
        $startRecord = ($page - 1) * $perPage + 1;
        $endRecord = min($page * $perPage, $totalProxies);
        
        echo "预期显示: 第 {$startRecord} - {$endRecord} 条<br>\n";
        echo "实际获取: {$actualCount} 条<br>\n";
        
        if ($actualCount > 0) {
            echo "✅ 数据获取成功<br>\n";
            
            // 显示前3条代理信息（不包含敏感数据）
            echo "前3条代理预览:<br>\n";
            for ($i = 0; $i < min(3, $actualCount); $i++) {
                $proxy = $proxies[$i];
                echo "- ID: {$proxy['id']}, IP: {$proxy['ip']}, 端口: {$proxy['port']}, 类型: {$proxy['type']}, 状态: {$proxy['status']}<br>\n";
            }
        } else {
            echo "❌ 该页没有数据<br>\n";
        }
        echo "<br>\n";
    }
    
    // 4. 测试边界情况
    echo "<h2>4. 测试边界情况</h2>\n";
    
    // 测试第0页（应该返回第1页）
    echo "<h3>测试第0页（应该返回第1页数据）</h3>\n";
    $proxies = $monitor->getProxiesPaginatedSafe(0, $perPage);
    echo "获取到 " . count($proxies) . " 条数据<br>\n";
    
    // 测试超出范围的页面
    $beyondPage = $totalPages + 1;
    echo "<h3>测试第 {$beyondPage} 页（超出范围）</h3>\n";
    $proxies = $monitor->getProxiesPaginatedSafe($beyondPage, $perPage);
    echo "获取到 " . count($proxies) . " 条数据<br>\n";
    
    // 5. 测试敏感数据过滤
    echo "<h2>5. 测试敏感数据过滤</h2>\n";
    $proxies = $monitor->getProxiesPaginatedSafe(1, 1);
    if (count($proxies) > 0) {
        $proxy = $proxies[0];
        $hasSensitiveData = isset($proxy['username']) || isset($proxy['password']);
        
        if ($hasSensitiveData) {
            echo "❌ 敏感数据过滤失败：返回的数据包含用户名或密码<br>\n";
        } else {
            echo "✅ 敏感数据过滤成功：返回的数据不包含敏感信息<br>\n";
        }
        
        echo "返回的字段: " . implode(', ', array_keys($proxy)) . "<br>\n";
    }
    
    echo "<h2>✅ 分页功能测试完成</h2>\n";
    echo "<p><a href='index.php'>返回主页面测试分页功能</a></p>\n";
    
} catch (Exception $e) {
    echo "<h2>❌ 测试失败</h2>\n";
    echo "错误信息: " . htmlspecialchars($e->getMessage()) . "<br>\n";
    echo "错误位置: " . $e->getFile() . " 第 " . $e->getLine() . " 行<br>\n";
}
?>
