<?php
/**
 * 定时任务调度器
 */

require_once 'config.php';
require_once 'monitor.php';

// 选择邮件发送方式
if (file_exists('vendor/autoload.php')) {
    require_once 'mailer.php';
    define('USE_PHPMAILER', true);
} else {
    require_once 'mailer_simple.php';
    define('USE_PHPMAILER', false);
}

class Scheduler {
    private $monitor;
    private $mailer;
    private $db;
    private $logger;
    
    public function __construct() {
        $this->monitor = new NetworkMonitor();
        $this->mailer = USE_PHPMAILER ? new Mailer() : new SimpleMailer();
        $this->db = new Database();
        $this->logger = new Logger();
    }
    
    /**
     * 运行监控任务
     */
    public function runMonitorTask() {
        $this->logger->info("开始执行监控任务");
        
        try {
            // 检查所有代理
            $results = $this->monitor->checkAllProxies();
            
            // 检查是否有需要发送警报的代理
            $failedProxies = $this->monitor->getFailedProxies();
            
            if (!empty($failedProxies)) {
                $this->logger->warning("发现 " . count($failedProxies) . " 个故障代理，发送邮件通知");
                $this->mailer->sendProxyAlert($failedProxies);
                
                // 记录警报
                foreach ($failedProxies as $proxy) {
                    $this->monitor->addAlert(
                        $proxy['id'],
                        'proxy_failure',
                        "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                    );
                }
            }
            
            $this->logger->info("监控任务执行完成");
            
        } catch (Exception $e) {
            $this->logger->error("监控任务执行失败: " . $e->getMessage());
        }
    }
    
    /**
     * 发送每日报告
     */
    public function sendDailyReport() {
        $this->logger->info("开始发送每日报告");
        
        try {
            $stats = $this->monitor->getStats();
            $this->mailer->sendStatusReport($stats);
            $this->logger->info("每日报告发送完成");
            
        } catch (Exception $e) {
            $this->logger->error("每日报告发送失败: " . $e->getMessage());
        }
    }
    
    /**
     * 清理旧日志
     */
    public function cleanupOldLogs($days = 30) {
        $this->logger->info("开始清理 $days 天前的日志");
        
        try {
            $result = $this->monitor->cleanupOldLogs($days);
            $this->logger->info("清理完成: 删除了 {$result['deleted_logs']} 条日志记录和 {$result['deleted_alerts']} 条警报记录");
            
        } catch (Exception $e) {
            $this->logger->error("清理日志失败: " . $e->getMessage());
        }
    }
    
    /**
     * 主循环
     */
    public function run() {
        $this->logger->info("NetWatch 调度器启动");
        
        $lastCheck = 0;
        $lastDailyReport = date('Y-m-d');
        $lastCleanup = date('Y-m-d');
        
        while (true) {
            $currentTime = time();
            
            // 检查是否需要执行监控任务
            if ($currentTime - $lastCheck >= CHECK_INTERVAL) {
                $this->runMonitorTask();
                $lastCheck = $currentTime;
            }
            
            // 检查是否需要发送每日报告（每天上午9点）
            $currentDate = date('Y-m-d');
            $currentHour = (int)date('H');
            if ($currentDate !== $lastDailyReport && $currentHour >= 9) {
                $this->sendDailyReport();
                $lastDailyReport = $currentDate;
            }
            
            // 检查是否需要清理日志（每天凌晨2点）
            if ($currentDate !== $lastCleanup && $currentHour >= 2 && $currentHour < 3) {
                $this->cleanupOldLogs();
                $lastCleanup = $currentDate;
            }
            
            // 休眠60秒
            sleep(60);
        }
    }
}

// 如果直接运行此文件，启动调度器
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $scheduler = new Scheduler();
    $scheduler->run();
}
