<?php
/**
 * 并行代理检测管理器
 * 将大量代理分组并行检测，提高检测效率
 * 修复版本：支持会话隔离，避免多设备/多用户之间的干扰
 */

// 设置时区为中国标准时间
date_default_timezone_set('Asia/Shanghai');

require_once 'config.php';
require_once 'database.php';
require_once 'monitor.php';
require_once 'logger.php';

class ParallelMonitor {
    private $db;
    private $logger;
    private $monitor;
    private $maxProcesses;
    private $batchSize;
    private $sessionId;
    private $offlineOnly;
    
    public function __construct($maxProcesses = 12, $batchSize = 200, $sessionId = null, $offlineOnly = false) {
        $this->db = new Database();
        $this->logger = new Logger();
        $this->monitor = new NetworkMonitor();
        $this->maxProcesses = $maxProcesses; // 最大并行进程数
        $this->batchSize = $batchSize; // 每组代理数量
        $this->offlineOnly = $offlineOnly; // 是否只检测离线代理
        
        // 生成或使用提供的会话ID，确保每个检测任务独立
        if ($sessionId === null) {
            // 使用会话ID + 时间戳 + 随机数确保唯一性
            $this->sessionId = session_id() . '_' . time() . '_' . mt_rand(1000, 9999);
        } else {
            $this->sessionId = $sessionId;
        }
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
        $this->logger->info("启动并行检查{$checkType} (会话: {$this->sessionId})");
        
        // 获取代理总数
        $totalProxies = $this->offlineOnly ? $this->db->getOfflineProxyCount() : $this->db->getProxyCount();
        if ($totalProxies == 0) {
            if ($this->offlineOnly) {
                $errorMsg = '🎉 太好了！当前没有离线代理需要检测。\n\n这意味着您的所有代理服务器都处于正常工作状态。如果您想检测所有代理的最新状态，可以使用"🚀 并行检测"功能。';
            } else {
                $errorMsg = '没有找到代理数据，请先添加代理服务器。';
            }
            return ['success' => false, 'error' => $errorMsg];
        }
        
        // 计算需要的批次数
        $totalBatches = ceil($totalProxies / $this->batchSize);
        $this->logger->info("总计 {$totalProxies} 个代理，分为 {$totalBatches} 个批次，每批 {$this->batchSize} 个 (会话: {$this->sessionId})");
        
        // 创建会话独立的临时状态文件目录
        $tempDir = $this->getSessionTempDir();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
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
        file_put_contents($tempDir . '/main_status.json', json_encode($mainStatus));
        
        // 异步启动批次处理
        $this->startBatchesAsync($totalProxies, $tempDir);
        
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
     */
    private function startBatchesAsync($totalProxies, $tempDir) {
        // 在后台启动批次管理器
        $managerScript = __DIR__ . '/parallel_batch_manager.php';
        $offlineFlag = $this->offlineOnly ? '1' : '0';
        $command = sprintf(
            'php "%s" %d %d "%s" %s > /dev/null 2>&1 &',
            $managerScript,
            $totalProxies,
            $this->batchSize,
            $tempDir,
            $offlineFlag
        );
        
        // 在Windows系统上使用不同的命令
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = sprintf(
                'start /B php "%s" %d %d "%s" %s',
                $managerScript,
                $totalProxies,
                $this->batchSize,
                $tempDir,
                $offlineFlag
            );
        }
        
        popen($command, 'r');
    }
    
    /**
     * 并行检查所有代理（同步版本，用于测试）
     * @return array 检查结果统计
     */
    public function checkAllProxiesParallel() {
        $startTime = microtime(true);
        $this->logger->info("开始并行检查所有代理 (会话: {$this->sessionId})");
        
        // 获取所有代理总数
        $totalProxies = $this->db->getProxyCount();
        if ($totalProxies == 0) {
            return ['success' => false, 'error' => '没有找到代理数据'];
        }
        
        // 计算需要的批次数
        $totalBatches = ceil($totalProxies / $this->batchSize);
        $this->logger->info("总计 {$totalProxies} 个代理，分为 {$totalBatches} 个批次，每批 {$this->batchSize} 个 (会话: {$this->sessionId})");
        
        // 创建会话独立的临时状态文件目录
        $tempDir = $this->getSessionTempDir();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
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
                $this->logger->info("检测到取消信号，停止启动新批次 (会话: {$this->sessionId})");
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
        $this->logger->info("并行检查完成，耗时: " . round($executionTime, 2) . "秒 (会话: {$this->sessionId})");
        
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
            $this->logger->info("启动批次 {$batchId}，偏移: {$offset}，数量: {$limit} (会话: {$this->sessionId})");
            return $process;
        } else {
            $this->logger->error("启动批次 {$batchId} 失败 (会话: {$this->sessionId})");
            return false;
        }
    }
    
    /**
     * 等待指定数量的进程完成
     */
    private function waitForProcesses(&$processes, $maxRemaining) {
        while (count($processes) > $maxRemaining) {
            foreach ($processes as $key => $process) {
                $status = pclose($process);
                unset($processes[$key]);
                break;
            }
            usleep(100000); // 100ms
        }
    }
    
    /**
     * 等待所有进程完成
     */
    private function waitForAllProcesses($processes) {
        foreach ($processes as $process) {
            pclose($process);
        }
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
                $batchStatus = json_decode(file_get_contents($statusFile), true);
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
            $batchStatus = json_decode(file_get_contents($statusFile), true);
            
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
        file_put_contents($cancelFile, time());
        
        $this->logger->info("并行检测已被取消 (会话: {$this->sessionId})");
        
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
}
