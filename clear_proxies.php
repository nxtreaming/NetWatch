<?php
/**
 * 清空代理列表工具
 */

// 开启错误显示用于调试
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    require_once 'config.php';
    require_once 'auth.php';
    require_once 'monitor.php';
    
    // 检查登录状态
    Auth::requireLogin();
} catch (Exception $e) {
    die("<h2>加载文件失败</h2><p>错误: " . htmlspecialchars($e->getMessage()) . "</p>");
}

try {
    $monitor = new NetworkMonitor();
    echo "<h2>🗑️ 清空代理列表工具</h2>";
    
    // 获取当前代理数量
    $proxies = $monitor->getAllProxies();
    $totalProxies = count($proxies);
} catch (Exception $e) {
    die("<h2>初始化失败</h2><p>错误: " . htmlspecialchars($e->getMessage()) . "</p><p>请检查数据库连接和文件权限</p>");
}

echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
echo "<h3>⚠️ 警告</h3>";
echo "<p>此操作将<strong>永久删除</strong>所有代理数据，包括：</p>";
echo "<ul>";
echo "<li>所有代理配置信息</li>";
echo "<li>历史检查日志</li>";
echo "<li>警报记录</li>";
echo "</ul>";
echo "<p><strong>当前代理数量：$totalProxies 个</strong></p>";
echo "</div>";

// 标记是否已执行清空操作
$clearExecuted = false;

// 处理清空请求
if ($_POST && isset($_POST['confirm_clear']) && $_POST['confirm_clear'] === 'yes') {
    try {
        $db = new Database();
        
        // 使用Database类的公共方法清空所有数据
        $db->clearAllData();
        
        $clearExecuted = true;
        
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 20px 0; border-radius: 5px; color: #155724;'>";
        echo "<h3>✅ 清空完成</h3>";
        echo "<p>已成功删除 <strong>$totalProxies</strong> 个代理及相关数据</p>";
        echo "<p><a href='import.php' style='background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;'>立即导入新代理</a></p>";
        echo "</div>";
        
        // 刷新代理列表
        $proxies = $monitor->getAllProxies();
        $totalProxies = count($proxies);
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 20px 0; border-radius: 5px; color: #721c24;'>";
        echo "<h3>❌ 清空失败</h3>";
        echo "<p>错误信息：" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
}

// 只有在没有执行清空操作且有代理时才显示代理列表和清空表单
if ($totalProxies > 0 && !$clearExecuted) {
    echo "<h3>当前代理列表预览</h3>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 20px 0;'>";
    echo "<tr><th>ID</th><th>代理</th><th>类型</th><th>用户名</th><th>状态</th></tr>";
    
    $displayCount = min(10, $totalProxies); // 最多显示10个
    for ($i = 0; $i < $displayCount; $i++) {
        $proxy = $proxies[$i];
        echo "<tr>";
        echo "<td>{$proxy['id']}</td>";
        echo "<td>{$proxy['ip']}:{$proxy['port']}</td>";
        echo "<td>" . strtoupper($proxy['type']) . "</td>";
        echo "<td>" . htmlspecialchars($proxy['username'] ?: '未设置') . "</td>";
        echo "<td>{$proxy['status']}</td>";
        echo "</tr>";
    }
    
    if ($totalProxies > 10) {
        echo "<tr><td colspan='5' style='text-align: center; font-style: italic;'>... 还有 " . ($totalProxies - 10) . " 个代理</td></tr>";
    }
    
    echo "</table>";
    
    echo "<h3>确认清空</h3>";
    echo "<form method='post' onsubmit='return confirmClear()'>";
    echo "<div style='background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px;'>";
    echo "<p><strong>请确认您要清空所有代理数据：</strong></p>";
    echo "<label>";
    echo "<input type='checkbox' name='confirm_clear' value='yes' required> ";
    echo "我确认要删除所有 <strong>$totalProxies</strong> 个代理及相关数据";
    echo "</label><br><br>";
    echo "<button type='submit' style='background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>";
    echo "🗑️ 确认清空所有代理";
    echo "</button>";
    echo "</div>";
    echo "</form>";
    
} else if ($totalProxies == 0 && !$clearExecuted) {
    echo "<div style='background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; margin: 20px 0; border-radius: 5px; color: #0c5460;'>";
    echo "<h3>ℹ️ 代理列表已为空</h3>";
    echo "<p>当前没有任何代理数据</p>";
    echo "<p><a href='import.php' style='background: #17a2b8; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;'>点击这里导入代理</a></p>";
    echo "</div>";
}

echo "<hr>";
echo "<h3>其他操作</h3>";
echo "<ul>";
echo "<li><a href='index.php'>返回监控面板</a></li>";
echo "<li><a href='import.php'>导入新代理</a></li>";
echo "<li><a href='diagnose.php'>系统诊断</a></li>";
echo "</ul>";
?>

<style>
table { border-collapse: collapse; width: 100%; margin: 20px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
button:hover { background: #c82333 !important; }
a { color: #007bff; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>

<script>
function confirmClear() {
    return confirm('⚠️ 最后确认：您确定要删除所有代理数据吗？此操作无法撤销！');
}
</script>
