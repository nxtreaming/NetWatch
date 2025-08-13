<?php
/**
 * 快速诊断脚本
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../monitor.php';

// 检查登录状态
Auth::requireLogin();

echo "<h2>NetWatch 诊断报告</h2>";

$monitor = new NetworkMonitor();
$proxies = $monitor->getAllProxies();

echo "<h3>代理认证状态分析</h3>";

$totalProxies = count($proxies);
$withAuth = 0;
$withoutAuth = 0;

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>ID</th><th>代理</th><th>类型</th><th>用户名</th><th>密码</th><th>认证状态</th></tr>";

foreach ($proxies as $proxy) {
    $hasUsername = !empty($proxy['username']);
    $hasPassword = !empty($proxy['password']);
    
    if ($hasUsername && $hasPassword) {
        $withAuth++;
        $authStatus = "<span style='color: green;'>✓ 完整</span>";
    } else {
        $withoutAuth++;
        $authStatus = "<span style='color: red;'>✗ 缺失</span>";
    }
    
    echo "<tr>";
    echo "<td>{$proxy['id']}</td>";
    echo "<td>{$proxy['ip']}:{$proxy['port']}</td>";
    echo "<td>" . strtoupper($proxy['type']) . "</td>";
    echo "<td>" . ($hasUsername ? "✓ " . htmlspecialchars($proxy['username']) : "<span style='color: red;'>未设置</span>") . "</td>";
    echo "<td>" . ($hasPassword ? "✓ ***" : "<span style='color: red;'>未设置</span>") . "</td>";
    echo "<td>$authStatus</td>";
    echo "</tr>";
}

echo "</table>";

echo "<h3>统计摘要</h3>";
echo "<ul>";
echo "<li><strong>总代理数:</strong> $totalProxies</li>";
echo "<li><strong>有认证信息:</strong> <span style='color: green;'>$withAuth</span></li>";
echo "<li><strong>缺少认证信息:</strong> <span style='color: red;'>$withoutAuth</span></li>";
echo "</ul>";

if ($withoutAuth > 0) {
    echo "<div style='background: #ffe8e8; padding: 15px; border-left: 4px solid #f44336; margin: 20px 0;'>";
    echo "<h4>⚠️ 发现问题</h4>";
    echo "<p>有 <strong>$withoutAuth</strong> 个代理缺少认证信息，这会导致407错误。</p>";
    echo "<p><strong>解决方案:</strong></p>";
    echo "<ol>";
    echo "<li>重新导入代理，确保格式为: <code>IP:端口:类型:用户名:密码</code></li>";
    echo "<li>或使用 <a href='fix_proxy_auth.php'>认证修复工具</a> 手动添加认证信息</li>";
    echo "</ol>";
    echo "</div>";
}

echo "<h3>导入格式检查</h3>";
echo "<p>正确的导入格式示例:</p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo "23.94.152.162:24122:http:Ack0107sAdmin:your_password\n";
echo "192.168.1.100:1080:socks5:username:password\n";
echo "10.0.0.1:8080:http:user:pass";
echo "</pre>";

echo "<h3>测试建议</h3>";
echo "<ol>";
echo "<li>确认您导入的代理格式包含用户名和密码</li>";
echo "<li>如果格式正确但仍然407错误，可能是认证信息不正确</li>";
echo "<li>联系代理提供商确认正确的用户名和密码</li>";
echo "</ol>";

// 显示一个示例代理的详细信息
if (!empty($proxies)) {
    $sampleProxy = $proxies[0];
    echo "<h3>示例代理详细信息</h3>";
    echo "<pre>";
    print_r($sampleProxy);
    echo "</pre>";
}
?>

<style>
table { border-collapse: collapse; width: 100%; margin: 20px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; }
code { background: #f0f0f0; padding: 2px 4px; }
</style>
