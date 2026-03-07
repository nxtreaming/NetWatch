<?php
/**
 * 并行代理检测管理器
 * 将大量代理分组并行检测，提高检测效率
 * 修复版本：支持会话隔离，避免多设备/多用户之间的干扰
 */

require_once 'config.php';
require_once 'database.php';
require_once 'includes/Config.php';
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
     */
    private function generateSessionId(): string {
        return session_id() . '_' . time() . '_' . mt_rand(1000, 9999);
    }
    
    /**
     * 获取当前会话ID
     */
    public function getSessionId(): string {
        return $this->sessionId;
    }
    
    /**
     * 获取会话独立的临时目录路径
     * @return string 临时目录路径
     */
    private function getSessionTempDir() {
        return sys_get_temp_dir() . '/netwatch_parallel_' . $this->sessionId;
    }
    
    /**
     * 启动并行检查所有代理（异步）
     * @return array 启动结果
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
            'session_id' => $this->sessionId
        ];
        $this->writeJsonFile($tempDir . '/main_status.json', $mainStatus);
        
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
        
        $offlineFlag = $this->offlineOnly ? 1 : 0;
        $command = 'php ' . escapeshellarg($managerScript) . ' ' .
            (int)$totalProxies . ' ' .
            (int)$this->batchSize . ' ' .
            escapeshellarg($tempDir) . ' ' .
            (int)$offlineFlag . ' > /dev/null 2>&1 &';
        
        // 在Windows系统上使用不同的命令
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = 'start /B "" php ' . escapeshellarg($managerScript) . ' ' .
                (int)$totalProxies . ' ' .
                (int)$this->batchSize . ' ' .
                escapeshellarg($tempDir) . ' ' .
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
     * @return array 检查结果统计
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
     */
    private function startBatchProcess($batchId, $offset, $limit, $statusFile) {
        $scriptPath = __DIR__ . '/parallel_worker.php';
        $command = sprintf(
            'php "%s" "%s" %d %d "%s"',
            $scriptPath,
            $batchId,
            $offset,
            $limit,
            $statusFile
        );
        
        // 在Windows系统上使用不同的命令
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = 'start /B ' . $command;
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
        $status = $this->readJsonFile($statusFile);
        if (!is_array($status)) {
            return false;
        }

        return in_array($status['status'] ?? '', ['completed', 'cancelled', 'error'], true);
    }
    
    /**
     * 收集所有批次的结果
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
                $batchStatus = $this->readJsonFile($statusFile);
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
     */
    public function getParallelProgress() {
        $tempDir = $this->getSessionTempDir();
        
        if (!is_dir($tempDir)) {
            return ['success' => false, 'error' => '没有正在进行的并行检测'];
        }
        
        $statusFiles = glob($tempDir . '/batch_*.json');
        if (empty($statusFiles)) {
            return ['success' => false, 'error' => '没有找到批次状态文件'];
        }
        
        $totalChecked = 0;
        $totalOnline = 0;
        $totalOffline = 0;
        $completedBatches = 0;
        $totalBatches = count($statusFiles);
        $batchStatuses = [];
        $totalProxies = 0; // 总代理数量
        
        foreach ($statusFiles as $statusFile) {
            $batchStatus = $this->readJsonFile($statusFile);
            
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
        
        // 如果所有批次已完成，自动清理临时目录
        if ($completedBatches === $totalBatches && $totalBatches > 0) {
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
            'batch_statuses' => $batchStatuses,
            'session_id' => $this->sessionId
        ];
    }
    
    /**
     * 清理临时文件
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
     */
    public function isCancelled() {
        $tempDir = $this->getSessionTempDir();
        $cancelFile = $tempDir . '/cancel.flag';
        return file_exists($cancelFile);
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
     */
    private function scheduleCleanup(string $tempDir): void {
        // 写入清理标记文件，包含预定清理时间（当前时间 + 30秒）
        $cleanupFile = $tempDir . '/cleanup_scheduled.json';
        $this->writeJsonFile($cleanupFile, [
            'scheduled_at' => time(),
            'cleanup_after' => time() + 30
        ]);
    }

    /**
     * 读取 JSON 文件
     */
    private function readJsonFile(string $filePath): ?array {
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 原子写入 JSON 文件
     */
    private function writeJsonFile(string $filePath, array $payload): bool {
        $tempFile = $filePath . '.tmp';
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return false;
        }

        $written = file_put_contents($tempFile, $encoded, LOCK_EX);
        if ($written === false) {
            return false;
        }

        return rename($tempFile, $filePath);
    }
    
    /**
     * 递归删除临时目录
     */
    private function removeTempDir(string $tempDir): void {
        if (!is_dir($tempDir)) {
            return;
        }
        $files = glob($tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        @rmdir($tempDir);
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
            $mtime = @filemtime($dir);
            if ($mtime === false || ($now - $mtime) > $maxAgeSeconds) {
                // 删除目录内所有文件
                $files = glob($dir . '/*');
                if ($files !== false) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                        }
                    }
                }
                @rmdir($dir);
                $cleaned++;
            }
        }
        return $cleaned;
    }
}
