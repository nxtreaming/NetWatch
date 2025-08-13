<?php
/**
 * 修复代理认证问题的脚本
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../database.php';
require_once '../monitor.php';

// 检查登录状态
Auth::requireLogin();
$monitor = new NetworkMonitor();

echo "<h2>代理认证修复工具</h2>";

// 获取所有代理
$proxies = $monitor->getAllProxies();

echo "<h3>当前代理配置：</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>ID</th><th>IP:端口</th><th>类型</th><th>用户名</th><th>密码</th><th>状态</th><th>操作</th></tr>";

foreach ($proxies as $proxy) {
    $username = $proxy['username'] ?: '<span style="color: red;">未设置</span>';
    $password = $proxy['password'] ? '***已设置***' : '<span style="color: red;">未设置</span>';
    
    echo "<tr>";
    echo "<td>{$proxy['id']}</td>";
    echo "<td>{$proxy['ip']}:{$proxy['port']}</td>";
    echo "<td>" . strtoupper($proxy['type']) . "</td>";
    echo "<td>$username</td>";
    echo "<td>$password</td>";
    echo "<td>{$proxy['status']}</td>";
    echo "<td><a href='debug_proxy.php?proxy_id={$proxy['id']}' target='_blank'>调试</a></td>";
    echo "</tr>";
}

echo "</table>";

// 处理更新请求
if ($_POST && isset($_POST['proxy_id'], $_POST['username'], $_POST['password'])) {
    $proxyId = $_POST['proxy_id'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // 使用Database类的公共方法更新认证信息
    $db = new Database();
    
    if ($db->updateProxyAuth($proxyId, $username, $password)) {
        echo "<div style='color: green; padding: 10px; background: #e8f5e8; margin: 10px 0;'>";
        echo "✓ 代理 ID $proxyId 的认证信息已更新";
        echo "</div>";
        
        // 刷新页面显示更新后的数据
        echo "<script>setTimeout(function(){ location.reload(); }, 2000);</script>";
    } else {
        echo "<div style='color: red; padding: 10px; background: #ffe8e8; margin: 10px 0;'>";
        echo "✗ 更新失败";
        echo "</div>";
    }
}

echo "<hr>";
echo "<h3>批量更新代理认证信息：</h3>";

// 显示需要认证的代理
$needAuthProxies = array_filter($proxies, function($p) {
    return empty($p['username']) || empty($p['password']);
});

if (!empty($needAuthProxies)) {
    echo "<p style='color: orange;'>以下代理缺少认证信息：</p>";
    
    foreach ($needAuthProxies as $proxy) {
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
        echo "<h4>代理: {$proxy['ip']}:{$proxy['port']}</h4>";
        echo "<form method='post' style='display: inline-block;'>";
        echo "<input type='hidden' name='proxy_id' value='{$proxy['id']}'>";
        echo "<label>用户名: <input type='text' name='username' value='{$proxy['username']}' placeholder='输入用户名'></label><br><br>";
        echo "<label>密码: <input type='password' name='password' placeholder='输入密码'></label><br><br>";
        echo "<button type='submit'>更新认证信息</button>";
        echo "</form>";
        echo "</div>";
    }
} else {
    echo "<p style='color: green;'>✓ 所有代理都已配置认证信息</p>";
}

echo "<hr>";
echo "<h3>快速导入带认证的代理：</h3>";
echo "<p>如果您有完整的代理列表，可以重新导入。格式：</p>";
echo "<pre>IP:端口:类型:用户名:密码</pre>";
echo "<p>例如：</p>";
echo "<pre>23.94.152.162:24122:http:Ack0107sAdmin:your_password</pre>";
echo "<p><a href='import.php'>点击这里重新导入代理</a></p>";

echo "<hr>";
echo "<h3>测试建议：</h3>";
echo "<ol>";
echo "<li>确保用户名和密码正确</li>";
echo "<li>如果是SOCKS5代理，确认代理类型设置正确</li>";
echo "<li>更新认证信息后，点击'调试'链接重新测试</li>";
echo "<li>如果仍然失败，可能需要联系代理提供商确认认证方式</li>";
echo "</ol>";
?>

<style>
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
form { margin: 10px 0; }
input[type="text"], input[type="password"] { padding: 5px; margin: 5px; }
button { padding: 8px 15px; background: #007cba; color: white; border: none; cursor: pointer; }
button:hover { background: #005a87; }
</style>
