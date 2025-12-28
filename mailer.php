<?php
/**
 * 邮件发送类（使用PHPMailer）
 */

require_once 'vendor/autoload.php';
require_once 'config.php';
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
            // 服务器设置
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            
            // 发件人
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            
            // 收件人
            $mail->addAddress(SMTP_TO_EMAIL);
            
            // 内容
            $mail->isHTML($isHTML);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            $mail->send();
            $this->logger->info("邮件发送成功: $subject");
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("邮件发送失败: " . $mail->ErrorInfo);
            return false;
        }
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
