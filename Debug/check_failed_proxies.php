<?php
/**
 * 检查失败代理和邮件通知状态
 */

require_once '../config.php';
require_once '../auth.php';
require_once '../includes/Config.php';
require_once '../database.php';
require_once '../monitor.php';
require_once '../logger.php';

// 检查登录状态
Auth::requireLogin();

function debug_check_failed_escape(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function debug_check_failed_mask(?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }

    $length = strlen($value);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 2) . str_repeat('*', max(2, $length - 4)) . substr($value, -2);
}

$sendAlertsRequested = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['send_alerts'])
    && Auth::validateCsrfToken($_POST['csrf_token'] ?? '');

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
            echo "   - " . debug_check_failed_escape($proxy['type'] . '://' . $proxy['ip'] . ':' . $proxy['port']) . " (失败 " . debug_check_failed_escape((string) $proxy['failure_count']) . " 次)\n";
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
            echo "   - " . debug_check_failed_escape($proxy['type'] . '://' . $proxy['ip'] . ':' . $proxy['port']) . " (失败 " . debug_check_failed_escape((string) $failureCount) . " 次, 最后检查: " . debug_check_failed_escape((string) $lastCheck) . ")\n";
        }
    }
    echo "\n";
    
    // 4. 检查邮件配置
    echo "4. 邮件配置检查:\n";
    
    if (config('mail.host', '') !== '') {
        echo "   SMTP_HOST: " . debug_check_failed_escape(debug_check_failed_mask((string) config('mail.host', ''))) . "\n";
    }
    if (config('mail.username', '') !== '') {
        echo "   SMTP_USERNAME: " . debug_check_failed_escape(debug_check_failed_mask((string) config('mail.username', ''))) . "\n";
    }
    if (config('mail.to', '') !== '') {
        echo "   SMTP_TO_EMAIL: " . debug_check_failed_escape(debug_check_failed_mask((string) config('mail.to', ''))) . "\n";
    }
    if (config('mail.from', '') !== '') {
        echo "   SMTP_FROM_EMAIL: " . debug_check_failed_escape(debug_check_failed_mask((string) config('mail.from', ''))) . "\n";
    }
    
    echo "   ALERT_THRESHOLD: " . ALERT_THRESHOLD . " 次\n";
    
    // 检查邮件发送器
    if (file_exists('vendor/autoload.php')) {
        echo "   邮件发送器: PHPMailer (vendor/autoload.php 存在)\n";
    } else {
        echo "   邮件发送器: SimpleMailer (使用系统 sendmail)\n";
    }
    echo "\n";
    
    // 5. 测试邮件发送（需要显式 POST + CSRF 触发）
    if (count($failedProxies) > 0) {
        echo "5. 邮件发送控制:\n";
        echo "   默认仅预览，不会自动发送。\n";
        if ($sendAlertsRequested) {
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
                echo "   ❌ 邮件发送异常\n";
            }
        } else {
            echo "   如需发送，请使用下方表单显式提交。\n";
        }
    } else {
        echo "5. 邮件发送:\n";
        echo "   没有需要发送警报的代理，跳过邮件发送\n";
    }
    
} catch (Exception $e) {
    echo "❌ 检查失败: " . debug_check_failed_escape($e->getMessage()) . "\n";
}

echo "\n=== 检查完成 ===\n";
if (count($failedProxies ?? []) > 0) {
    echo "\n<form method='post'>";
    echo "<input type='hidden' name='csrf_token' value='" . debug_check_failed_escape(Auth::getCsrfToken()) . "'>";
    echo "<button type='submit' name='send_alerts' value='1'>发送失败代理告警邮件</button>";
    echo "</form>";
}
?>
