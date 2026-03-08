<?php
/**
 * 定时任务调度器
 */

require_once 'config.php';
require_once 'includes/Config.php';
require_once 'monitor.php';

ensure_valid_config('cli');

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
        $this->db = $this->monitor->getDatabase();
        $this->logger = $this->monitor->getLogger();
    }
    
    /**
     * 运行监控任务
     */
    public function runMonitorTask() {
        $this->logger->info('scheduler_monitor_task_started', [
            'task' => 'monitor',
        ]);
        
        try {
            // 检查所有代理
            $results = $this->monitor->checkAllProxies();
            
            // 检查是否有需要发送警报的代理
            $failedProxies = $this->monitor->getFailedProxies();
            
            if (!empty($failedProxies)) {
                $this->logger->warning('scheduler_failed_proxies_detected', [
                    'task' => 'monitor',
                    'failed_proxy_count' => count($failedProxies),
                ]);
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
            
            $this->logger->info('scheduler_monitor_task_completed', [
                'task' => 'monitor',
                'result_count' => is_array($results) ? count($results) : 0,
                'failed_proxy_count' => count($failedProxies),
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('scheduler_monitor_task_failed', [
                'task' => 'monitor',
                'exception' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 发送每日报告
     */
    public function sendDailyReport() {
        $this->logger->info('scheduler_daily_report_started', [
            'task' => 'daily_report',
        ]);
        
        try {
            $stats = $this->monitor->getStats();
            $this->mailer->sendStatusReport($stats);
            $this->logger->info('scheduler_daily_report_completed', [
                'task' => 'daily_report',
                'stats_keys' => is_array($stats) ? array_keys($stats) : [],
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('scheduler_daily_report_failed', [
                'task' => 'daily_report',
                'exception' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 清理旧日志
     */
    public function cleanupOldLogs($days = 30) {
        $this->logger->info('scheduler_log_cleanup_started', [
            'task' => 'cleanup_old_logs',
            'days' => $days,
        ]);
        
        try {
            $result = $this->monitor->cleanupOldLogs($days);
            $this->logger->info('scheduler_log_cleanup_completed', [
                'task' => 'cleanup_old_logs',
                'days' => $days,
                'deleted_logs' => $result['deleted_logs'],
                'deleted_alerts' => $result['deleted_alerts'],
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('scheduler_log_cleanup_failed', [
                'task' => 'cleanup_old_logs',
                'days' => $days,
                'exception' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 主循环
     */
    public function run() {
        $this->logger->info('scheduler_started', [
            'loop_sleep_sec' => (int) config('scheduler.loop_sleep_sec', 60),
            'check_interval' => CHECK_INTERVAL,
        ]);
        
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
            sleep((int) config('scheduler.loop_sleep_sec', 60));
        }
    }
}

// 如果直接运行此文件，启动调度器
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $scheduler = new Scheduler();
    $scheduler->run();
}
