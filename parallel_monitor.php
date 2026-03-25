<?php
/**
 * 并行代理检测管理器
 * 将大量代理分组并行检测，提高检测效率
 * 修复版本：支持会话隔离，避免多设备/多用户之间的干扰
 */

require_once 'config.php';
require_once 'database.php';
require_once 'includes/Config.php';
require_once 'includes/MailerFactory.php';
require_once 'includes/ParallelStatusUtils.php';
require_once 'monitor.php';
require_once 'logger.php';

class ParallelMonitor {
    private Database $db;
    private Logger $logger;
    private NetworkMonitor $monitor;
    private int $maxProcesses;
    private int $batchSize;
    private string $sessionId;
    private bool $offlineOnly;
    
    // 默认配置常量
    private const DEFAULT_MAX_PROCESSES = 12;
    private const DEFAULT_BATCH_SIZE = 200;
    private const DEFAULT_TIMEOUT_MINUTES = 30;
    
    public function __construct(
        int $maxProcesses = self::DEFAULT_MAX_PROCESSES, 
        int $batchSize = self::DEFAULT_BATCH_SIZE, 
        ?string $sessionId = null, 
        bool $offlineOnly = false,
        ?Database $db = null,
        ?Logger $logger = null,
        ?NetworkMonitor $monitor = null
    ) {
        $this->db = $db ?? new Database();
        $this->logger = $logger ?? new Logger();
        $this->monitor = $monitor ?? new NetworkMonitor();
        $this->maxProcesses = $maxProcesses === self::DEFAULT_MAX_PROCESSES
            ? (int) config('monitoring.parallel_max_processes', self::DEFAULT_MAX_PROCESSES)
            : $maxProcesses;
        $this->batchSize = $batchSize === self::DEFAULT_BATCH_SIZE
            ? (int) config('monitoring.parallel_batch_size', self::DEFAULT_BATCH_SIZE)
            : $batchSize;
        $this->offlineOnly = $offlineOnly;
        
        // 生成或使用提供的会话ID，确保每个检测任务独立
        $this->sessionId = $sessionId ?? $this->generateSessionId();
        
        // 每次实例化时顺便清理过期目录（轻量级，仅扫描目录列表）
        self::purgeStaleSessionDirs();
    }
    
    /**
     * 生成唯一会话ID
     * @return string 会话唯一标识
     * @throws \Exception random_bytes 生成失败时抛出
     */
    private function generateSessionId(): string {
        $sid = session_id();
        if ($sid === '') {
            $sid = bin2hex(random_bytes(8));
        }
        return $sid . '_' . time() . '_' . mt_rand(1000, 9999);
    }
    
    /**
     * 获取当前会话ID
     * @return string 当前会话ID
     */
    public function getSessionId(): string {
        return $this->sessionId;
    }
    
    /**
     * 获取会话独立的临时目录路径
     * @return string 临时目录绝对路径
     */
    private function getSessionTempDir() {
        return sys_get_temp_dir() . '/netwatch_parallel_' . $this->sessionId;
    }
    
    /**
     * 启动并行检查所有代理（异步）
     * @return array{success:bool,error?:string,total_proxies?:int,total_batches?:float|int,batch_size?:int,max_processes?:int,session_id?:string,message?:string} 启动结果
     */
    public function startParallelCheck() {
        $startTime = microtime(true);
        $checkType = $this->offlineOnly ? "离线代理" : "所有代理";
        $this->logger->info('parallel_check_started', [
            'session_id' => $this->sessionId,
            'check_type' => $checkType,
            'offline_only' => $this->offlineOnly,
        ]);
        
        // 获取代理总数
        $totalProxies = $this->offlineOnly ? $this->db->getOfflineProxyCount() : $this->db->getProxyCount();
        if ($totalProxies == 0) {
            if ($this->offlineOnly) {
                $errorMsg = '🎉 太好了！当前没有离线代理需要检测。<br><br>这意味着您的所有代理服务器都处于正常工作状态。如果您想检测所有代理的最新状态，可以使用"🚀 并行检测"功能。';
            } else {
                $errorMsg = '没有找到代理数据，请先添加代理服务器。';
            }
            return ['success' => false, 'error' => $errorMsg];
        }
        
        // 计算需要的批次数
        $totalBatches = ceil($totalProxies / $this->batchSize);
        $this->logger->info('parallel_check_batches_planned', [
            'session_id' => $this->sessionId,
            'total_proxies' => $totalProxies,
            'total_batches' => $totalBatches,
            'batch_size' => $this->batchSize,
            'max_processes' => $this->maxProcesses,
            'offline_only' => $this->offlineOnly,
        ]);
        
        // 创建会话独立的临时状态文件目录
        $tempDir = $this->getSessionTempDir();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0700, true);
        }
        
        // 清理旧的状态文件
        $this->cleanupTempFiles($tempDir);
        
        // 创建主状态文件
        $mainStatus = [
            'start_time' => time(),
            'total_proxies' => $totalProxies,
            'total_batches' => $totalBatches,
            'status' => 'starting',
            'session_id' => $this->sessionId,
            'alert_email_attempted' => false,
            'alert_email_sent' => false,
            'alert_email_failed_proxies' => 0,
            'alert_email_error' => null
        ];
        netwatch_write_json_file($tempDir . '/main_status.json', $mainStatus);
        
        // 异步启动批次处理
        $launched = $this->startBatchesAsync($totalProxies, $tempDir);
        if (!$launched) {
            $this->logger->error('parallel_batch_manager_launch_failed', [
                'session_id' => $this->sessionId,
                'temp_dir' => $tempDir,
                'total_proxies' => $totalProxies,
            ]);
            $this->removeTempDir($tempDir);
            return [
                'success' => false,
                'error' => '启动并行检测进程失败，请检查服务器 PHP 配置或磁盘空间'
            ];
        }
        
        return [
            'success' => true,
            'total_proxies' => $totalProxies,
            'total_batches' => $totalBatches,
            'batch_size' => $this->batchSize,
            'max_processes' => $this->maxProcesses,
            'session_id' => $this->sessionId,
            'message' => '并行检测已启动'
        ];
    }
    
    /**
     * 异步启动所有批次
     * @param int $totalProxies 代理总数
     * @param string $tempDir 会话临时目录
     * @return bool 启动是否成功
     */
    private function startBatchesAsync($totalProxies, $tempDir): bool {
        // 在后台启动批次管理器
        $managerScript = __DIR__ . '/parallel_batch_manager.php';
        if (!file_exists($managerScript)) {
            $this->logger->error('parallel_batch_manager_script_missing', [
                'session_id' => $this->sessionId,
                'script_path' => $managerScript,
            ]);
            return false;
        }

        $totalProxies = (int) $totalProxies;
        $batchSize = (int) $this->batchSize;
        if ($totalProxies < 1 || $batchSize < 1) {
            $this->logger->error('parallel_batch_manager_invalid_arguments', [
                'session_id' => $this->sessionId,
                'total_proxies' => $totalProxies,
                'batch_size' => $batchSize,
            ]);
            return false;
        }

        $resolvedTempDir = realpath((string) $tempDir);
        $resolvedSysTemp = realpath(sys_get_temp_dir());
        if (
            $resolvedTempDir === false
            || $resolvedSysTemp === false
            || strpos($resolvedTempDir, $resolvedSysTemp) !== 0
            || !is_dir($resolvedTempDir)
        ) {
            $this->logger->error('parallel_batch_manager_invalid_temp_dir', [
                'session_id' => $this->sessionId,
                'temp_dir' => $tempDir,
                'resolved_temp_dir' => $resolvedTempDir,
            ]);
            return false;
        }
        
        $offlineFlag = $this->offlineOnly ? 1 : 0;
        $phpBinary = escapeshellcmd(PHP_BINARY ?: 'php');
        $command = $phpBinary . ' ' . escapeshellarg($managerScript) . ' ' .
            $totalProxies . ' ' .
            $batchSize . ' ' .
            escapeshellarg($resolvedTempDir) . ' ' .
            (int)$offlineFlag . ' > /dev/null 2>&1 &';
        
        // 在Windows系统上使用不同的命令
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = 'start /B "" ' . $phpBinary . ' ' . escapeshellarg($managerScript) . ' ' .
                $totalProxies . ' ' .
                $batchSize . ' ' .
                escapeshellarg($resolvedTempDir) . ' ' .
                (int)$offlineFlag;
        }
        
        $process = popen($command, 'r');
        if ($process === false) {
            $this->logger->error('parallel_batch_manager_process_start_failed', [
                'session_id' => $this->sessionId,
                'command' => $command,
            ]);
            return false;
        }

        if (is_resource($process)) {
            pclose($process);
        }

        // 不等待进程完成，异步运行
        return true;
    }
    
    /**
     * 并行检查所有代理（同步版本，用于测试）
     * @return array<string,mixed> 检查结果统计
     */
    public function checkAllProxiesParallel() {
        $startTime = microtime(true);
        $this->logger->info('parallel_check_sync_started', [
            'session_id' => $this->sessionId,
        ]);
        
        // 获取所有代理总数
        $totalProxies = $this->db->getProxyCount();
        if ($totalProxies == 0) {
            return ['success' => false, 'error' => '没有找到代理数据'];
        }
        
        // 计算需要的批次数
        $totalBatches = ceil($totalProxies / $this->batchSize);
        $this->logger->info('parallel_check_sync_batches_planned', [
            'session_id' => $this->sessionId,
            'total_proxies' => $totalProxies,
            'total_batches' => $totalBatches,
            'batch_size' => $this->batchSize,
            'max_processes' => $this->maxProcesses,
        ]);

        // 创建会话独立的临时状态文件目录
        $tempDir = $this->getSessionTempDir();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0700, true);
        }
        
        // 清理旧的状态文件
        $this->cleanupTempFiles($tempDir);
        
        $processes = [];
        $batchResults = [];
        
        // 启动所有批次
        for ($i = 0; $i < $totalBatches; $i++) {
            $offset = $i * $this->batchSize;
            $limit = min($this->batchSize, $totalProxies - $offset);
            $batchId = 'batch_' . $i;
            $statusFile = $tempDir . '/' . $batchId . '.json';
            
            // 检查是否被取消
            if ($this->isCancelled()) {
                $this->logger->info('parallel_check_cancel_detected', [
                    'session_id' => $this->sessionId,
                ]);
                break;
            }
            
            // 启动批次进程
            $process = $this->startBatchProcess($batchId, $offset, $limit, $statusFile);
            if ($process) {
                $processes[] = $process;
            }
            
            // 控制并发数量
            if (count($processes) >= $this->maxProcesses) {
                $this->waitForProcesses($processes, $this->maxProcesses - 1);
            }
        }
        
        // 等待所有进程完成
        $this->waitForAllProcesses($processes);
        
        // 收集结果
        $results = $this->collectResults($tempDir, $totalBatches);
        
        $executionTime = microtime(true) - $startTime;
        $this->logger->info('parallel_check_completed', [
            'session_id' => $this->sessionId,
            'execution_time_seconds' => round($executionTime, 2),
            'total_batches' => $totalBatches,
        ]);
        
        return array_merge($results, [
            'success' => true,
            'execution_time' => round($executionTime, 2),
            'session_id' => $this->sessionId
        ]);
    }
    
    /**
     * 启动单个批次检测进程
     * @param string $batchId 批次ID（batch_x）
     * @param int $offset 代理偏移量
     * @param int $limit 本批次数量
     * @param string $statusFile 批次状态文件路径
     * @return string|false 成功返回状态文件路径，失败返回 false
     */
    private function startBatchProcess($batchId, $offset, $limit, $statusFile) {
        $batchId = (string) $batchId;
        $offset = (int) $offset;
        $limit = (int) $limit;
        $statusFile = (string) $statusFile;

        if (preg_match('/^batch_\d+$/', $batchId) !== 1 || $offset < 0 || $limit < 1) {
            $this->logger->error('parallel_batch_process_invalid_arguments', [
                'session_id' => $this->sessionId,
                'batch_id' => $batchId,
                'offset' => $offset,
                'limit' => $limit,
            ]);
            return false;
        }

        $statusDir = realpath(dirname($statusFile));
        $resolvedSysTemp = realpath(sys_get_temp_dir());
        if (
            $statusDir === false
            || $resolvedSysTemp === false
            || strpos($statusDir, $resolvedSysTemp) !== 0
            || basename($statusFile) !== ($batchId . '.json')
        ) {
            $this->logger->error('parallel_batch_process_invalid_status_file', [
                'session_id' => $this->sessionId,
                'batch_id' => $batchId,
                'status_file' => $statusFile,
                'status_dir' => $statusDir,
            ]);
            return false;
        }

        $scriptPath = __DIR__ . '/parallel_worker.php';
        if (!file_exists($scriptPath)) {
            $this->logger->error('parallel_worker_script_missing', [
                'session_id' => $this->sessionId,
                'script_path' => $scriptPath,
            ]);
            return false;
        }

        $phpBinary = escapeshellcmd(PHP_BINARY ?: 'php');
        $args = implode(' ', [
            escapeshellarg($scriptPath),
            escapeshellarg($batchId),
            $offset,
            $limit,
            escapeshellarg($statusFile),
        ]);
        $command = $phpBinary . ' ' . $args;
        
        // 在Windows系统上使用不同的命令
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = 'start /B "" ' . $command;
        } else {
            $command .= ' > /dev/null 2>&1 &';
        }
        
        $process = popen($command, 'r');
        if ($process) {
            pclose($process);
            $this->logger->info('parallel_batch_process_started', [
                'session_id' => $this->sessionId,
                'batch_id' => $batchId,
                'offset' => $offset,
                'limit' => $limit,
                'status_file' => $statusFile,
            ]);
            return $statusFile;
        } else {
            $this->logger->error('parallel_batch_process_start_failed', [
                'session_id' => $this->sessionId,
                'batch_id' => $batchId,
                'offset' => $offset,
                'limit' => $limit,
                'status_file' => $statusFile,
            ]);
            return false;
        }
    }
    
    /**
     * 等待指定数量的进程完成
     * @param array<int,string> $processes 进程状态文件列表（引用传递）
     * @param int $maxRemaining 允许保留的最大活动进程数
     * @return void
     */
    private function waitForProcesses(&$processes, $maxRemaining) {
        while (count($processes) > $maxRemaining) {
            $completedKey = null;

            foreach ($processes as $key => $statusFile) {
                if ($this->isBatchFinished($statusFile)) {
                    $completedKey = $key;
                    break;
                }
            }

            if ($completedKey !== null) {
                unset($processes[$completedKey]);
            }
            usleep((int) config('monitoring.parallel_cancel_poll_us', 100000)); // 100ms
        }
    }
    
    /**
     * 等待所有进程完成
     * @param array<int,string> $processes 进程状态文件列表
     * @return void
     */
    private function waitForAllProcesses($processes) {
        while (!empty($processes)) {
            foreach ($processes as $key => $statusFile) {
                if ($this->isBatchFinished($statusFile)) {
                    unset($processes[$key]);
                }
            }

            if (!empty($processes)) {
                usleep((int) config('monitoring.parallel_batch_poll_us', 500000));
            }
        }
    }

    /**
     * 判断批次是否已结束
     */
    private function isBatchFinished(string $statusFile): bool {
        return netwatch_is_batch_finished($statusFile);
    }
    
    /**
     * 收集所有批次的结果
     * @param string $tempDir 会话临时目录
     * @param int $totalBatches 批次数量
     * @return array{total_checked:int,total_online:int,total_offline:int,batches:array<int,array<string,mixed>>}
     */
    private function collectResults($tempDir, $totalBatches) {
        $totalChecked = 0;
        $totalOnline = 0;
        $totalOffline = 0;
        $batchResults = [];
        
        for ($i = 0; $i < $totalBatches; $i++) {
            $batchId = 'batch_' . $i;
            $statusFile = $tempDir . '/' . $batchId . '.json';
            
            if (file_exists($statusFile)) {
                $batchStatus = netwatch_read_json_file($statusFile);
                if ($batchStatus) {
                    $totalChecked += $batchStatus['checked'];
                    $totalOnline += $batchStatus['online'];
                    $totalOffline += $batchStatus['offline'];
                    $batchResults[] = $batchStatus;
                }
            }
        }
        
        return [
            'total_checked' => $totalChecked,
            'total_online' => $totalOnline,
            'total_offline' => $totalOffline,
            'batches' => $batchResults
        ];
    }
    
    /**
     * 获取并行检测进度
     * @return array<string,mixed> 进度与统计信息
     */
    public function getParallelProgress() {
        $tempDir = $this->getSessionTempDir();
        
        if (!is_dir($tempDir)) {
            return ['success' => false, 'error' => '没有正在进行的并行检测'];
        }

        $mainStatusFile = $tempDir . '/main_status.json';
        $mainStatus = netwatch_read_json_file($mainStatusFile);
        
        $statusFiles = glob($tempDir . '/batch_*.json');
        if (empty($statusFiles)) {
            if (!is_array($mainStatus)) {
                return ['success' => false, 'error' => '没有找到批次状态文件'];
            }

            return [
                'success' => true,
                'overall_progress' => 0.0,
                'completed_batches' => 0,
                'total_batches' => (int) ($mainStatus['total_batches'] ?? 0),
                'total_proxies' => (int) ($mainStatus['total_proxies'] ?? 0),
                'total_checked' => 0,
                'total_online' => 0,
                'total_offline' => 0,
                'failed_proxies' => 0,
                'email_attempted' => false,
                'email_sent' => false,
                'email_error' => null,
                'batch_statuses' => [],
                'session_id' => $this->sessionId,
                'status' => (string) ($mainStatus['status'] ?? 'starting'),
                'message' => '并行检测初始化中，请稍候...'
            ];
        }
        
        $totalChecked = 0;
        $totalOnline = 0;
        $totalOffline = 0;
        $completedBatches = 0;
        $totalBatches = count($statusFiles);
        $batchStatuses = [];
        $totalProxies = 0; // 总代理数量
        
        foreach ($statusFiles as $statusFile) {
            $batchStatus = netwatch_read_json_file($statusFile);
            
            if ($batchStatus) {
                $totalChecked += $batchStatus['checked'] ?? 0;
                $totalOnline += $batchStatus['online'] ?? 0;
                $totalOffline += $batchStatus['offline'] ?? 0;
                $totalProxies += $batchStatus['limit'] ?? 0; // 累加每个批次的总数
                
                if ($batchStatus['status'] === 'completed') {
                    $completedBatches++;
                }
                
                $batchStatuses[] = $batchStatus;
            }
        }
        
        // 基于实际检测的IP数量计算进度，而不是批次完成情况
        $overallProgress = $totalProxies > 0 ? ($totalChecked / $totalProxies) * 100 : 0;

        $emailSent = false;
        $emailAttempted = false;
        $emailError = null;
        $failedProxyCount = 0;

        if (is_array($mainStatus)) {
            $emailSent = (bool) ($mainStatus['alert_email_sent'] ?? false);
            $emailAttempted = (bool) ($mainStatus['alert_email_attempted'] ?? false);
            $emailError = $mainStatus['alert_email_error'] ?? null;
            $failedProxyCount = (int) ($mainStatus['alert_email_failed_proxies'] ?? 0);
        }

        if (
            $completedBatches === $totalBatches
            && $totalBatches > 0
            && is_array($mainStatus)
            && !$emailAttempted
        ) {
            $alertResult = $this->sendFailedProxyAlerts();
            $mainStatus['alert_email_attempted'] = true;
            $mainStatus['alert_email_sent'] = $alertResult['email_sent'];
            $mainStatus['alert_email_failed_proxies'] = $alertResult['failed_proxy_count'];
            $mainStatus['alert_email_error'] = $alertResult['email_error'];
            if (($mainStatus['status'] ?? '') === 'starting' || ($mainStatus['status'] ?? '') === 'running') {
                $mainStatus['status'] = 'completed';
                $mainStatus['end_time'] = $mainStatus['end_time'] ?? time();
            }
            netwatch_write_json_file($mainStatusFile, $mainStatus);

            $emailSent = $alertResult['email_sent'];
            $emailAttempted = true;
            $emailError = $alertResult['email_error'];
            $failedProxyCount = $alertResult['failed_proxy_count'];
        }

        if (
            $completedBatches === $totalBatches
            && $totalBatches > 0
            && is_array($mainStatus)
            && ($mainStatus['alert_email_attempted'] ?? false)
        ) {
            $this->removeTempDir($tempDir);
        }
        
        return [
            'success' => true,
            'overall_progress' => round($overallProgress, 2),
            'completed_batches' => $completedBatches,
            'total_batches' => $totalBatches,
            'total_proxies' => $totalProxies, // 添加总代理数量
            'total_checked' => $totalChecked,
            'total_online' => $totalOnline,
            'total_offline' => $totalOffline,
            'failed_proxies' => $failedProxyCount,
            'email_attempted' => $emailAttempted,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
            'batch_statuses' => $batchStatuses,
            'session_id' => $this->sessionId
        ];
    }

    /**
     * 发送离线代理告警邮件并记录告警结果
     * @return array{email_sent:bool,failed_proxy_count:int,email_error:?string}
     */
    private function sendFailedProxyAlerts(): array {
        $failedProxies = $this->monitor->getFailedProxies();
        $failedProxyCount = count($failedProxies);

        if ($failedProxyCount === 0) {
            return [
                'email_sent' => false,
                'failed_proxy_count' => 0,
                'email_error' => null,
            ];
        }

        try {
            $mailer = MailerFactory::create();
            $emailSent = $mailer->sendProxyAlert($failedProxies) === true;

            if ($emailSent) {
                foreach ($failedProxies as $proxy) {
                    $this->monitor->addAlert(
                        $proxy['id'],
                        'proxy_failure',
                        "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                    );
                }

                $this->logger->warning('parallel_check_failed_proxy_alert_sent', [
                    'session_id' => $this->sessionId,
                    'failed_proxy_count' => $failedProxyCount,
                    'offline_only' => $this->offlineOnly,
                ]);

                return [
                    'email_sent' => true,
                    'failed_proxy_count' => $failedProxyCount,
                    'email_error' => null,
                ];
            }

            $this->logger->error('parallel_check_failed_proxy_alert_send_failed', [
                'session_id' => $this->sessionId,
                'failed_proxy_count' => $failedProxyCount,
                'offline_only' => $this->offlineOnly,
            ]);

            return [
                'email_sent' => false,
                'failed_proxy_count' => $failedProxyCount,
                'email_error' => 'failed_to_send_proxy_alert',
            ];
        } catch (Throwable $e) {
            $this->logger->error('parallel_check_failed_proxy_alert_exception', [
                'session_id' => $this->sessionId,
                'failed_proxy_count' => $failedProxyCount,
                'offline_only' => $this->offlineOnly,
                'exception' => $e->getMessage(),
            ]);

            return [
                'email_sent' => false,
                'failed_proxy_count' => $failedProxyCount,
                'email_error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 清理临时文件
     * @param string $tempDir 临时目录
     * @return void
     */
    private function cleanupTempFiles($tempDir) {
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    
    /**
     * 取消并行检测
     * @return array{success:bool,message:string,session_id:string}
     */
    public function cancelParallelCheck() {
        $tempDir = $this->getSessionTempDir();
        
        // 创建取消标志文件
        $cancelFile = $tempDir . '/cancel.flag';
        file_put_contents($cancelFile, time(), LOCK_EX);
        
        $this->logger->info("并行检测已被取消 (会话: {$this->sessionId})");
        
        // 延迟清理：给工作进程一点时间响应取消信号后再清理
        $this->scheduleCleanup($tempDir);
        
        return ['success' => true, 'message' => '并行检测已取消', 'session_id' => $this->sessionId];
    }
    
    /**
     * 检查是否被取消
     * @return bool true=已取消，false=未取消
     */
    public function isCancelled() {
        $tempDir = $this->getSessionTempDir();
        return netwatch_is_cancelled_dir($tempDir);
    }
    
    /**
     * 清理会话临时目录（完成或取消后调用）
     */
    public function cleanup(): void {
        $tempDir = $this->getSessionTempDir();
        $this->removeTempDir($tempDir);
    }
    
    /**
     * 计划延迟清理（取消时使用，给工作进程响应时间）
     * @param string $tempDir 会话临时目录
     * @return void
     */
    private function scheduleCleanup(string $tempDir): void {
        // 写入清理标记文件，包含预定清理时间（当前时间 + 30秒）
        $cleanupFile = $tempDir . '/cleanup_scheduled.json';
        netwatch_write_json_file($cleanupFile, [
            'scheduled_at' => time(),
            'cleanup_after' => time() + 30
        ]);
    }
    
    /**
     * 递归删除临时目录
     * @param string $tempDir 会话临时目录
     * @return void
     */
    private function removeTempDir(string $tempDir): void {
        if (!is_dir($tempDir)) {
            return;
        }
        $files = glob($tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (!unlink($file)) {
                        $this->logger->warning('parallel_temp_file_remove_failed', [
                            'session_id' => $this->sessionId,
                            'file' => $file,
                        ]);
                    }
                }
            }
        }
        if (!rmdir($tempDir) && is_dir($tempDir)) {
            $this->logger->warning('parallel_temp_dir_remove_failed', [
                'session_id' => $this->sessionId,
                'dir' => $tempDir,
            ]);
        }
        $this->logger->info("已清理临时目录: {$tempDir}");
    }
    
    /**
     * 清理过期的会话临时目录（静态方法，可由定时任务调用）
     * @param int $maxAgeSeconds 最大保留时间（秒），默认 86400（1天）
     * @return int 清理的目录数量
     */
    public static function purgeStaleSessionDirs(int $maxAgeSeconds = 86400): int {
        $pattern = sys_get_temp_dir() . '/netwatch_parallel_*';
        $dirs = glob($pattern, GLOB_ONLYDIR);
        if ($dirs === false) {
            return 0;
        }
        
        $cleaned = 0;
        $now = time();
        foreach ($dirs as $dir) {
            $mtime = filemtime($dir);
            if ($mtime === false || ($now - $mtime) > $maxAgeSeconds) {
                // 删除目录内所有文件
                $files = glob($dir . '/*');
                if ($files !== false) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            if (!unlink($file)) {
                                error_log('[NetWatch][ParallelMonitor] Failed to remove stale temp file: ' . $file);
                            }
                        }
                    }
                }
                if (!rmdir($dir) && is_dir($dir)) {
                    error_log('[NetWatch][ParallelMonitor] Failed to remove stale temp dir: ' . $dir);
                    continue;
                }
                $cleaned++;
            }
        }
        return $cleaned;
    }
}
