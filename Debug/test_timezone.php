<?php
/**
 * 时区测试脚本
 * 用于诊断NetWatch系统的时间显示问题
 */

require_once 'config.php';
require_once 'database.php';
require_once 'monitor.php';

// 引入时间格式化函数
/**
 * 格式化时间显示，自动处理UTC到北京时间的转换
 */
function formatTime($timeString, $format = 'm-d H:i') {
    if (!$timeString) {
        return 'N/A';
    }
    
    try {
        // 尝试从 UTC 时间转换为北京时间
        $dt = new DateTime($timeString, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
        return $dt->format($format);
    } catch (Exception $e) {
        // 如果转换失败，使用原始方法
        return date($format, strtotime($timeString));
    }
}

echo "<h1>NetWatch 时区测试</h1>\n";

// 设置时区
date_default_timezone_set('Asia/Shanghai');

echo "<h2>1. 系统时区信息</h2>\n";
echo "PHP时区: " . date_default_timezone_get() . "<br>\n";
echo "当前PHP时间: " . date('Y-m-d H:i:s') . "<br>\n";
echo "当前时间戳: " . time() . "<br>\n";

try {
    $db = new Database();
    $monitor = new NetworkMonitor($db);
    
    echo "<h2>2. 数据库时区信息</h2>\n";
    
    // 获取数据库时区
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $stmt = $pdo->query("SELECT NOW() as db_time, @@session.time_zone as session_tz, @@global.time_zone as global_tz");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "数据库当前时间: " . $result['db_time'] . "<br>\n";
    echo "数据库会话时区: " . $result['session_tz'] . "<br>\n";
    echo "数据库全局时区: " . $result['global_tz'] . "<br>\n";
    
    echo "<h2>3. 代理时间显示测试</h2>\n";
    
    // 获取一些代理数据来测试时间显示
    $proxies = $monitor->getProxiesPaginatedSafe(1, 5);
    
    if (count($proxies) > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>\n";
        echo "<tr><th>ID</th><th>IP:Port</th><th>原始 last_check</th><th>旧格式化</th><th>新formatTime</th><th>时间差异</th></tr>\n";
        
        foreach ($proxies as $proxy) {
            $rawTime = $proxy['last_check'];
            $oldFormattedTime = $rawTime ? date('m-d H:i', strtotime($rawTime)) : 'N/A';
            $newFormattedTime = formatTime($rawTime);
            
            // 计算与当前时间的差异
            $timeDiff = 'N/A';
            if ($rawTime) {
                $checkTime = strtotime($rawTime);
                $currentTime = time();
                $diffSeconds = $currentTime - $checkTime;
                $diffMinutes = round($diffSeconds / 60);
                $timeDiff = $diffMinutes . " 分钟前";
            }
            
            echo "<tr>";
            echo "<td>{$proxy['id']}</td>";
            echo "<td>{$proxy['ip']}:{$proxy['port']}</td>";
            echo "<td>{$rawTime}</td>";
            echo "<td>{$oldFormattedTime}</td>";
            echo "<td>{$newFormattedTime}</td>";
            echo "<td>{$timeDiff}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "没有找到代理数据进行测试<br>\n";
    }
    
    echo "<h2>4. 时间转换测试</h2>\n";
    
    // 测试不同的时间转换方法
    $testTime = '2024-01-15 10:30:00'; // 假设这是数据库中的时间
    
    echo "测试时间字符串: {$testTime}<br>\n";
    echo "strtotime() 结果: " . strtotime($testTime) . "<br>\n";
    echo "date() 格式化: " . date('Y-m-d H:i:s', strtotime($testTime)) . "<br>\n";
    echo "date() 简化格式: " . date('m-d H:i', strtotime($testTime)) . "<br>\n";
    
    // 测试UTC时间转换
    echo "<h3>UTC时间转换测试</h3>\n";
    $utcTime = '2024-01-15 02:30:00'; // UTC时间
    echo "假设UTC时间: {$utcTime}<br>\n";
    
    // 创建DateTime对象进行时区转换
    $dt = new DateTime($utcTime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
    echo "转换为北京时间: " . $dt->format('Y-m-d H:i:s') . "<br>\n";
    echo "简化显示: " . $dt->format('m-d H:i') . "<br>\n";
    
    echo "<h2>5. 建议解决方案</h2>\n";
    echo "<p>如果数据库存储的是UTC时间，需要在显示时进行时区转换。</p>\n";
    echo "<p>如果数据库存储的是本地时间，确保数据库时区设置正确。</p>\n";
    
} catch (Exception $e) {
    echo "<h2>❌ 测试失败</h2>\n";
    echo "错误信息: " . htmlspecialchars($e->getMessage()) . "<br>\n";
}
?>
