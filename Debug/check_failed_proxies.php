<?php
/**
 * 检查失败代理和邮件通知状态
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../database.php';
require_once '../monitor.php';
require_once '../logger.php';

// 检查登录状态
Auth::requireLogin();

echo "=== NetWatch 失败代理检查 ===\n\n";

try {
    // 初始化组件
    $db = new Database();
    $logger = new Logger();
    $monitor = new NetworkMonitor($db, $logger);
    
    // 1. 获取所有代理统计
    $stats = $monitor->getStats();
    echo "1. 代理统计:\n";
    echo "   总计: {$stats['total']} 个\n";
    echo "   在线: {$stats['online']} 个\n";
    echo "   离线: {$stats['offline']} 个\n";
    echo "   未知: {$stats['unknown']} 个\n";
    echo "   平均响应时间: " . round($stats['avg_response_time'], 2) . "ms\n\n";
    
    // 2. 检查失败代理（达到警报阈值的）
    $failedProxies = $monitor->getFailedProxies();
    echo "2. 需要发送警报的代理 (连续失败 >= " . ALERT_THRESHOLD . " 次):\n";
    echo "   数量: " . count($failedProxies) . " 个\n";
    
    if (count($failedProxies) > 0) {
        echo "   详情:\n";
        foreach ($failedProxies as $proxy) {
            echo "   - {$proxy['type']}://{$proxy['ip']}:{$proxy['port']} (失败 {$proxy['failure_count']} 次)\n";
        }
    } else {
        echo "   ✅ 没有需要发送警报的代理\n";
    }
    echo "\n";
    
    // 3. 检查所有离线代理
    $allProxies = $monitor->getAllProxies();
    $offlineProxies = array_filter($allProxies, function($proxy) {
        return $proxy['status'] === 'offline';
    });
    
    echo "3. 所有离线代理:\n";
    echo "   数量: " . count($offlineProxies) . " 个\n";
    
    if (count($offlineProxies) > 0) {
        echo "   详情:\n";
        foreach ($offlineProxies as $proxy) {
            $failureCount = $proxy['failure_count'] ?? 0;
            $lastCheck = $proxy['last_check'] ?? '从未检查';
            echo "   - {$proxy['type']}://{$proxy['ip']}:{$proxy['port']} (失败 {$failureCount} 次, 最后检查: {$lastCheck})\n";
        }
    }
    echo "\n";
    
    // 4. 检查邮件配置
    echo "4. 邮件配置检查:\n";
    
    if (defined('SMTP_HOST')) {
        echo "   SMTP_HOST: " . SMTP_HOST . "\n";
    }
    if (defined('SMTP_USERNAME')) {
        echo "   SMTP_USERNAME: " . SMTP_USERNAME . "\n";
    }
    if (defined('SMTP_TO_EMAIL')) {
        echo "   SMTP_TO_EMAIL: " . SMTP_TO_EMAIL . "\n";
    }
    if (defined('SMTP_FROM_EMAIL')) {
        echo "   SMTP_FROM_EMAIL: " . SMTP_FROM_EMAIL . "\n";
    }
    
    echo "   ALERT_THRESHOLD: " . ALERT_THRESHOLD . " 次\n";
    
    // 检查邮件发送器
    if (file_exists('vendor/autoload.php')) {
        echo "   邮件发送器: PHPMailer (vendor/autoload.php 存在)\n";
    } else {
        echo "   邮件发送器: SimpleMailer (使用系统 sendmail)\n";
    }
    echo "\n";
    
    // 5. 测试邮件发送（如果有失败代理）
    if (count($failedProxies) > 0) {
        echo "5. 测试邮件发送:\n";
        try {
            if (file_exists('vendor/autoload.php')) {
                require_once 'mailer.php';
                $mailer = new Mailer();
            } else {
                require_once 'mailer_simple.php';
                $mailer = new SimpleMailer();
            }
            
            echo "   正在发送测试邮件...\n";
            $result = $mailer->sendProxyAlert($failedProxies);
            
            if ($result) {
                echo "   ✅ 邮件发送成功！\n";
                
                // 记录警报
                foreach ($failedProxies as $proxy) {
                    $monitor->addAlert(
                        $proxy['id'],
                        'proxy_failure',
                        "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                    );
                }
                echo "   ✅ 警报记录已保存\n";
            } else {
                echo "   ❌ 邮件发送失败\n";
            }
        } catch (Exception $e) {
            echo "   ❌ 邮件发送异常: " . $e->getMessage() . "\n";
        }
    } else {
        echo "5. 邮件发送:\n";
        echo "   没有需要发送警报的代理，跳过邮件发送\n";
    }
    
} catch (Exception $e) {
    echo "❌ 检查失败: " . $e->getMessage() . "\n";
    echo "错误堆栈:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== 检查完成 ===\n";
?>
