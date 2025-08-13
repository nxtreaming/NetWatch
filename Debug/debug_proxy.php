<?php
/**
 * 代理调试工具
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../monitor.php';

// 检查登录状态
Auth::requireLogin();

if (isset($_GET['proxy_id'])) {
    $proxyId = $_GET['proxy_id'];
    
    $monitor = new NetworkMonitor();
    $proxies = $monitor->getAllProxies();
    
    $proxy = null;
    foreach ($proxies as $p) {
        if ($p['id'] == $proxyId) {
            $proxy = $p;
            break;
        }
    }
    
    if (!$proxy) {
        die("代理不存在");
    }
    
    echo "<h2>代理调试信息</h2>";
    echo "<p><strong>代理:</strong> {$proxy['ip']}:{$proxy['port']} ({$proxy['type']})</p>";
    
    // 详细检查代理
    $startTime = microtime(true);
    
    $ch = curl_init();
    
    // 基本curl设置
    curl_setopt_array($ch, [
        CURLOPT_URL => TEST_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'NetWatch Monitor/1.0',
        CURLOPT_VERBOSE => true,
        CURLOPT_HEADER => true
    ]);
    
    // 设置代理
    if ($proxy['type'] === 'socks5') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    } else {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    }
    
    $proxyUrl = $proxy['ip'] . ':' . $proxy['port'];
    curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
    
    // 如果有认证信息
    if (!empty($proxy['username']) && !empty($proxy['password'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['username'] . ':' . $proxy['password']);
    }
    
    echo "<h3>请求详情</h3>";
    echo "<p><strong>测试URL:</strong> " . TEST_URL . "</p>";
    echo "<p><strong>代理URL:</strong> $proxyUrl</p>";
    echo "<p><strong>代理类型:</strong> " . ($proxy['type'] === 'socks5' ? 'SOCKS5' : 'HTTP') . "</p>";
    echo "<p><strong>超时设置:</strong> " . TIMEOUT . " 秒</p>";
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    
    curl_close($ch);
    
    $responseTime = (microtime(true) - $startTime) * 1000;
    
    echo "<h3>响应结果</h3>";
    echo "<p><strong>响应时间:</strong> " . number_format($responseTime, 2) . " ms</p>";
    echo "<p><strong>HTTP状态码:</strong> $httpCode</p>";
    echo "<p><strong>cURL错误:</strong> " . ($curlError ?: '无') . "</p>";
    
    echo "<h3>详细信息</h3>";
    echo "<pre>";
    echo "总时间: " . number_format($curlInfo['total_time'] * 1000, 2) . " ms\n";
    echo "连接时间: " . number_format($curlInfo['connect_time'] * 1000, 2) . " ms\n";
    echo "DNS解析时间: " . number_format($curlInfo['namelookup_time'] * 1000, 2) . " ms\n";
    echo "重定向次数: " . $curlInfo['redirect_count'] . "\n";
    echo "下载大小: " . $curlInfo['size_download'] . " bytes\n";
    echo "</pre>";
    
    if ($response !== false) {
        echo "<h3>响应内容 (前500字符)</h3>";
        echo "<pre>" . htmlspecialchars(substr($response, 0, 500)) . "</pre>";
        
        if ($httpCode === 200) {
            echo "<p style='color: green;'><strong>✓ 代理工作正常</strong></p>";
        } else {
            echo "<p style='color: orange;'><strong>⚠ 代理连接成功但HTTP状态码不是200</strong></p>";
        }
    } else {
        echo "<p style='color: red;'><strong>✗ 代理连接失败</strong></p>";
    }
    
    // 测试不使用代理的情况
    echo "<hr><h3>不使用代理的测试</h3>";
    $ch2 = curl_init();
    curl_setopt_array($ch2, [
        CURLOPT_URL => TEST_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'NetWatch Monitor/1.0'
    ]);
    
    $directResponse = curl_exec($ch2);
    $directHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $directError = curl_error($ch2);
    curl_close($ch2);
    
    echo "<p><strong>直连HTTP状态码:</strong> $directHttpCode</p>";
    echo "<p><strong>直连错误:</strong> " . ($directError ?: '无') . "</p>";
    
    if ($directResponse !== false && $directHttpCode === 200) {
        echo "<p style='color: green;'><strong>✓ 测试URL可以直接访问</strong></p>";
    } else {
        echo "<p style='color: red;'><strong>✗ 测试URL无法直接访问，可能需要更换测试URL</strong></p>";
    }
    
} else {
    echo "<p>请在URL中添加 ?proxy_id=代理ID 来调试特定代理</p>";
    
    // 显示所有代理列表
    $monitor = new NetworkMonitor();
    $proxies = $monitor->getAllProxies();
    
    echo "<h3>可用代理列表:</h3>";
    echo "<ul>";
    foreach ($proxies as $proxy) {
        echo "<li><a href='?proxy_id={$proxy['id']}'>{$proxy['ip']}:{$proxy['port']} ({$proxy['type']})</a></li>";
    }
    echo "</ul>";
}
?>
