<?php
/**
 * 测试脚本
 */

require_once 'config.php';
require_once 'monitor.php';
require_once 'mailer.php';

echo "=== NetWatch 系统测试 ===\n\n";

// 测试数据库连接
echo "1. 测试数据库连接...\n";
try {
    $db = new Database();
    echo "✓ 数据库连接成功\n\n";
} catch (Exception $e) {
    echo "✗ 数据库连接失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试监控功能
echo "2. 测试监控功能...\n";
try {
    $monitor = new NetworkMonitor();
    
    // 添加测试代理
    $testProxy = [
        'ip' => '127.0.0.1',
        'port' => 8080,
        'type' => 'http',
        'username' => null,
        'password' => null
    ];
    
    $db->addProxy($testProxy['ip'], $testProxy['port'], $testProxy['type']);
    echo "✓ 添加测试代理成功\n";
    
    // 获取统计信息
    $stats = $monitor->getStats();
    echo "✓ 获取统计信息成功: 总计 {$stats['total']} 个代理\n\n";
    
} catch (Exception $e) {
    echo "✗ 监控功能测试失败: " . $e->getMessage() . "\n\n";
}

// 测试日志功能
echo "3. 测试日志功能...\n";
try {
    $logger = new Logger();
    $logger->info("这是一条测试日志");
    echo "✓ 日志记录成功\n\n";
} catch (Exception $e) {
    echo "✗ 日志功能测试失败: " . $e->getMessage() . "\n\n";
}

// 测试邮件功能（仅测试配置，不实际发送）
echo "4. 测试邮件配置...\n";
try {
    if (SMTP_USERNAME === 'your-email@gmail.com') {
        echo "⚠ 邮件配置未完成，请修改 config.php 中的邮件设置\n";
    } else {
        echo "✓ 邮件配置已设置\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ 邮件配置测试失败: " . $e->getMessage() . "\n\n";
}

// 测试curl功能
echo "5. 测试网络连接...\n";
try {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'http://httpbin.org/ip',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response !== false && $httpCode === 200) {
        echo "✓ 网络连接正常\n";
    } else {
        echo "⚠ 网络连接可能有问题 (HTTP $httpCode)\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ 网络连接测试失败: " . $e->getMessage() . "\n\n";
}

// 检查目录权限
echo "6. 检查目录权限...\n";
$directories = [
    dirname(DB_PATH),
    LOG_PATH
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "✓ 创建目录: $dir\n";
        } else {
            echo "✗ 无法创建目录: $dir\n";
        }
    } else {
        if (is_writable($dir)) {
            echo "✓ 目录可写: $dir\n";
        } else {
            echo "✗ 目录不可写: $dir\n";
        }
    }
}

echo "\n=== 测试完成 ===\n";
echo "如果所有测试都通过，您可以开始使用 NetWatch 系统了！\n";
echo "\n使用说明:\n";
echo "1. 访问 http://your-domain/netwatch/ 查看监控面板\n";
echo "2. 访问 http://your-domain/netwatch/import.php 导入代理\n";
echo "3. 运行 'php scheduler.php' 启动后台监控\n";
echo "4. 修改 config.php 中的邮件设置以启用邮件通知\n";
