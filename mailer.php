<?php
/**
 * 邮件发送类（使用PHPMailer）
 */

require_once 'vendor/autoload.php';
require_once 'config.php';
require_once 'includes/Config.php';
require_once 'logger.php';
require_once __DIR__ . '/includes/MailerInterface.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Mailer implements MailerInterface {
    private $logger;
    
    public function __construct() {
        $this->logger = new Logger();
    }
    
    /**
     * 发送邮件
     */
    public function sendMail($subject, $body, $isHTML = true) {
        $mail = new PHPMailer(true);
        
        try {
            $smtpPassword = $this->resolveSmtpPassword();
            $host = (string) config('mail.host', '');
            $username = (string) config('mail.username', '');
            $port = (int) config('mail.port', 587);
            $from = (string) config('mail.from', '');
            $fromName = (string) config('mail.from_name', 'NetWatch');
            $to = (string) config('mail.to', '');

            if ($host === '' || $username === '' || $from === '' || $to === '') {
                throw new Exception('SMTP configuration is incomplete');
            }

            if ($smtpPassword === '') {
                throw new Exception('SMTP password is not configured');
            }

            // 服务器设置
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $port;
            $mail->CharSet = 'UTF-8';
            
            // 发件人
            $mail->setFrom($from, $fromName);
            
            // 收件人
            $mail->addAddress($to);
            
            // 内容
            $mail->isHTML($isHTML);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            $mail->send();
            $this->logger->info("邮件发送成功: $subject");
            return true;
            
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            if (isset($mail) && $mail instanceof PHPMailer && !empty($mail->ErrorInfo)) {
                $errorInfo = $mail->ErrorInfo;
            }
            $this->logger->error("邮件发送失败: " . $errorInfo);
            return false;
        }
    }

    /**
     * 解析 SMTP 密码（优先级：环境变量 > 密码文件 > 配置常量）
     */
    private function resolveSmtpPassword() {
        $envConfig = config('mail.password_env', '');
        if (is_string($envConfig) && $envConfig !== '') {
            $envName = trim($envConfig);
            if ($envName !== '') {
                $envValue = getenv($envName);
                if ($envValue === false) {
                    $envValue = $_ENV[$envName] ?? $_SERVER[$envName] ?? false;
                }
                if (is_string($envValue) && $envValue !== '') {
                    return $envValue;
                }
            }
        }

        $fileConfig = config('mail.password_file', '');
        if (is_string($fileConfig) && $fileConfig !== '') {
            $filePath = trim($fileConfig);
            if ($filePath !== '' && is_readable($filePath)) {
                $fileValue = file_get_contents($filePath);
                if ($fileValue !== false) {
                    $fileValue = trim($fileValue);
                    if ($fileValue !== '') {
                        return $fileValue;
                    }
                }
            }
        }

        $password = config('mail.password', '');
        if (is_string($password) && $password !== '') {
            return $password;
        }

        return '';
    }
    
    /**
     * 发送代理故障通知
     */
    public function sendProxyAlert($failedProxies) {
        if (empty($failedProxies)) {
            return true;
        }
        
        $subject = 'NetWatch 代理故障通知 - ' . date('Y-m-d H:i:s');
        
        $body = $this->generateAlertHTML($failedProxies);
        
        return $this->sendMail($subject, $body);
    }
    
    /**
     * 生成故障通知HTML
     */
    private function generateAlertHTML($failedProxies) {
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>NetWatch 代理故障通知</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { background-color: #f44336; color: white; padding: 15px; border-radius: 5px; }
                .content { margin: 20px 0; }
                table { border-collapse: collapse; width: 100%; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .failed { background-color: #ffebee; }
                .footer { margin-top: 30px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>🚨 NetWatch 代理故障通知</h2>
                <p>检测到以下代理出现故障，请及时处理</p>
            </div>
            
            <div class="content">
                <p><strong>故障时间:</strong> ' . date('Y-m-d H:i:s') . '</p>
                <p><strong>故障数量:</strong> ' . count($failedProxies) . ' 个</p>
                
                <table>
                    <thead>
                        <tr>
                            <th>代理地址</th>
                            <th>类型</th>
                            <th>连续失败次数</th>
                            <th>最后检查时间</th>
                            <th>响应时间</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($failedProxies as $proxy) {
            $html .= '
                        <tr class="failed">
                            <td>' . htmlspecialchars($proxy['ip'] . ':' . $proxy['port']) . '</td>
                            <td>' . htmlspecialchars(strtoupper($proxy['type'])) . '</td>
                            <td>' . $proxy['failure_count'] . '</td>
                            <td>' . htmlspecialchars($proxy['last_check'] ?? 'N/A') . '</td>
                            <td>' . number_format($proxy['response_time'], 2) . ' ms</td>
                        </tr>';
        }
        
        $html .= '
                    </tbody>
                </table>
            </div>
            
            <div class="footer">
                <p>此邮件由 NetWatch 监控系统自动发送</p>
                <p>如需停止接收此类邮件，请联系系统管理员</p>
            </div>
        </body>
        </html>';
        
        return $html;
    }
    
    /**
     * 发送系统状态报告
     */
    public function sendStatusReport($stats) {
        $subject = 'NetWatch 系统状态报告 - ' . date('Y-m-d H:i:s');
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>NetWatch 系统状态报告</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { background-color: #4CAF50; color: white; padding: 15px; border-radius: 5px; }
                .stats { display: flex; justify-content: space-around; margin: 20px 0; }
                .stat-box { text-align: center; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                .stat-number { font-size: 24px; font-weight: bold; color: #333; }
                .stat-label { color: #666; margin-top: 5px; }
                .online { color: #4CAF50; }
                .offline { color: #f44336; }
                .unknown { color: #ff9800; }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>📊 NetWatch 系统状态报告</h2>
                <p>系统运行正常，以下是当前状态统计</p>
            </div>
            
            <div class="stats">
                <div class="stat-box">
                    <div class="stat-number">' . $stats['total'] . '</div>
                    <div class="stat-label">总代理数</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number online">' . $stats['online'] . '</div>
                    <div class="stat-label">在线数量</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number offline">' . $stats['offline'] . '</div>
                    <div class="stat-label">离线数量</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number unknown">' . $stats['unknown'] . '</div>
                    <div class="stat-label">未知</div>
                </div>
            </div>
            
            <p><strong>平均响应时间:</strong> ' . number_format($stats['avg_response_time'], 2) . ' ms</p>
            <p><strong>在线率:</strong> ' . number_format(($stats['online'] / $stats['total']) * 100, 2) . '%</p>
            
            <div style="margin-top: 30px; font-size: 12px; color: #666;">
                <p>此邮件由 NetWatch 监控系统自动发送</p>
                <p>报告时间: ' . date('Y-m-d H:i:s') . '</p>
            </div>
        </body>
        </html>';
        
        return $this->sendMail($subject, $body);
    }
}
