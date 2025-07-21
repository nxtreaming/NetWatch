<?php
/**
 * 并行代理检测管理器
 * 将大量代理分组并行检测，提高检测效率
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
    
    public function __construct($maxProcesses = 6, $batchSize = 400) {
        $this->db = new Database();
        $this->logger = new Logger();
        $this->monitor = new NetworkMonitor();
        $this->maxProcesses = $maxProcesses; // 最大并行进程数
        $this->batchSize = $batchSize; // 每组代理数量
    }
    
    /**
     * 启动并行检查所有代理（异步）
     * @return array 启动结果
     */
    public function startParallelCheck() {
        $startTime = microtime(true);
        $this->logger->info("启动并行检查所有代理");
        
        // 获取所有代理总数
        $totalProxies = $this->db->getProxyCount();
        if ($totalProxies == 0) {
            return ['success' => false, 'error' => '没有找到代理数据'];
        }
        
        // 计算需要的批次数
        $totalBatches = ceil($totalProxies / $this->batchSize);
        $this->logger->info("总计 {$totalProxies} 个代理，分为 {$totalBatches} 个批次，每批 {$this->batchSize} 个");
        
        // 创建临时状态文件目录
        $tempDir = sys_get_temp_dir() . '/netwatch_parallel';
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
            'status' => 'starting'
        ];
        file_put_contents($tempDir . '/main_status.json', json_encode($mainStatus));
        
        // 异步启动批次处理
        $this->startBatchesAsync($totalProxies, $tempDir);
        
        return [
            'success' => true,
            'total_proxies' => $totalProxies,
            'total_batches' => $totalBatches,
            'message' => '并行检测已启动'
        ];
    }
    
    /**
     * 异步启动所有批次
     */
    private function startBatchesAsync($totalProxies, $tempDir) {
        // 在后台启动批次管理器
        $managerScript = __DIR__ . '/parallel_batch_manager.php';
        $command = sprintf(
            'php "%s" %d %d "%s" > /dev/null 2>&1 &',
            $managerScript,
            $totalProxies,
            $this->batchSize,
            $tempDir
        );
        
        // 在Windows系统上使用不同的命令
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = sprintf(
                'start /B php "%s" %d %d "%s"',
                $managerScript,
                $totalProxies,
                $this->batchSize,
                $tempDir
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
        $this->logger->info("开始并行检查所有代理");
        
        // 获取所有代理总数
        $totalProxies = $this->db->getProxyCount();
        if ($totalProxies == 0) {
            return ['success' => false, 'error' => '没有找到代理数据'];
        }
        
        // 计算需要的批次数
        $totalBatches = ceil($totalProxies / $this->batchSize);
        $this->logger->info("总计 {$totalProxies} 个代理，分为 {$totalBatches} 个批次，每批 {$this->batchSize} 个");
        
        // 创建临时状态文件目录
        $tempDir = sys_get_temp_dir() . '/netwatch_parallel';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // 清理旧的状态文件
        $this->cleanupTempFiles($tempDir);
        
        // 启动并行检测进程
        $processes = [];
        $batchIndex = 0;
        
        for ($offset = 0; $offset < $totalProxies; $offset += $this->batchSize) {
            $batchId = 'batch_' . $batchIndex;
            $statusFile = $tempDir . '/' . $batchId . '.json';
            
            // 计算当前批次的实际大小
            $currentBatchSize = min($this->batchSize, $totalProxies - $offset);
            
            // 创建批次状态文件
            $batchStatus = [
                'batch_id' => $batchId,
                'offset' => $offset,
                'limit' => $currentBatchSize,
                'status' => 'pending',
                'progress' => 0,
                'checked' => 0,
                'online' => 0,
                'offline' => 0,
                'start_time' => time(),
                'end_time' => null,
                'error' => null
            ];
            file_put_contents($statusFile, json_encode($batchStatus));
            
            // 如果达到最大进程数，等待一些进程完成
            if (count($processes) >= $this->maxProcesses) {
                $this->waitForProcesses($processes, $this->maxProcesses - 1);
            }
            
            // 启动新的检测进程
            $process = $this->startBatchProcess($batchId, $offset, $currentBatchSize, $statusFile);
            if ($process) {
                $processes[$batchId] = $process;
                $this->logger->info("启动批次 {$batchId}: offset={$offset}, limit={$currentBatchSize}");
            }
            
            $batchIndex++;
            
            // 短暂延迟避免同时启动过多进程
            usleep(100000); // 0.1秒
        }
        
        // 等待所有进程完成
        $this->logger->info("等待所有批次完成...");
        $this->waitForAllProcesses($processes);
        
        // 收集所有批次的结果
        $results = $this->collectResults($tempDir, $totalBatches);
        
        // 清理临时文件
        $this->cleanupTempFiles($tempDir);
        
        $totalTime = round((microtime(true) - $startTime) * 1000);
        $this->logger->info("并行检查完成，总用时: {$totalTime}ms");
        
        return [
            'success' => true,
            'total_proxies' => $totalProxies,
            'total_batches' => $totalBatches,
            'checked' => $results['total_checked'],
            'online' => $results['total_online'],
            'offline' => $results['total_offline'],
            'execution_time' => $totalTime,
            'batch_results' => $results['batches']
        ];
    }
    
    /**
     * 启动单个批次检测进程
     */
    private function startBatchProcess($batchId, $offset, $limit, $statusFile) {
        // 构建命令行参数
        $scriptPath = __DIR__ . '/parallel_worker.php';
        $command = sprintf(
            'php "%s" "%s" %d %d "%s" > /dev/null 2>&1 &',
            $scriptPath,
            $batchId,
            $offset,
            $limit,
            $statusFile
        );
        
        // 在Windows系统上使用不同的命令
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = sprintf(
                'start /B php "%s" "%s" %d %d "%s"',
                $scriptPath,
                $batchId,
                $offset,
                $limit,
                $statusFile
            );
        }
        
        // 启动进程
        $process = popen($command, 'r');
        return $process;
    }
    
    /**
     * 等待指定数量的进程完成
     */
    private function waitForProcesses(&$processes, $maxRemaining) {
        while (count($processes) > $maxRemaining) {
            foreach ($processes as $batchId => $process) {
                // 检查进程是否完成
                $status = pclose($process);
                unset($processes[$batchId]);
                $this->logger->info("批次 {$batchId} 完成");
                break; // 只等待一个进程完成
            }
            usleep(500000); // 0.5秒检查间隔
        }
    }
    
    /**
     * 等待所有进程完成
     */
    private function waitForAllProcesses($processes) {
        foreach ($processes as $batchId => $process) {
            pclose($process);
            $this->logger->info("批次 {$batchId} 完成");
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
        $tempDir = sys_get_temp_dir() . '/netwatch_parallel';
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
                $totalChecked += $batchStatus['checked'];
                $totalOnline += $batchStatus['online'];
                $totalOffline += $batchStatus['offline'];
                $totalProxies += $batchStatus['limit']; // 累加每个批次的总数
                
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
            'batch_statuses' => $batchStatuses
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
        $tempDir = sys_get_temp_dir() . '/netwatch_parallel';
        
        // 创建取消标志文件
        $cancelFile = $tempDir . '/cancel.flag';
        file_put_contents($cancelFile, time());
        
        $this->logger->info("并行检测已被取消");
        
        return ['success' => true, 'message' => '并行检测已取消'];
    }
    
    /**
     * 检查是否被取消
     */
    public function isCancelled() {
        $tempDir = sys_get_temp_dir() . '/netwatch_parallel';
        $cancelFile = $tempDir . '/cancel.flag';
        return file_exists($cancelFile);
    }
}
