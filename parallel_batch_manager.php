<?php
/**
 * 并行批次管理器
 * 负责管理和启动多个并行批次
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置执行时间限制
set_time_limit(0); // 无限制

require_once 'config.php';
require_once 'database.php';
require_once 'logger.php';

// 检查命令行参数
if ($argc < 4) {
    echo "Usage: php parallel_batch_manager.php <total_proxies> <batch_size> <temp_dir>\n";
    exit(1);
}

$totalProxies = (int)$argv[1];
$batchSize = (int)$argv[2];
$tempDir = $argv[3];

// 初始化组件
$logger = new Logger();
$maxProcesses = 6; // 最大并行进程数

$logger->info("批次管理器启动: 总代理={$totalProxies}, 批次大小={$batchSize}, 最大进程={$maxProcesses}");

try {
    // 更新主状态为运行中
    $mainStatusFile = $tempDir . '/main_status.json';
    if (file_exists($mainStatusFile)) {
        $mainStatus = json_decode(file_get_contents($mainStatusFile), true);
        $mainStatus['status'] = 'running';
        file_put_contents($mainStatusFile, json_encode($mainStatus));
    }
    
    // 计算批次数
    $totalBatches = ceil($totalProxies / $batchSize);
    $processes = [];
    $batchIndex = 0;
    
    // 启动所有批次
    for ($offset = 0; $offset < $totalProxies; $offset += $batchSize) {
        // 检查是否被取消
        if (isCancelled($tempDir)) {
            $logger->info("批次管理器被取消");
            break;
        }
        
        $batchId = 'batch_' . $batchIndex;
        $statusFile = $tempDir . '/' . $batchId . '.json';
        
        // 计算当前批次的实际大小
        $currentBatchSize = min($batchSize, $totalProxies - $offset);
        
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
        if (count($processes) >= $maxProcesses) {
            waitForProcesses($processes, $maxProcesses - 1, $logger);
        }
        
        // 启动新的检测进程
        $process = startBatchProcess($batchId, $offset, $currentBatchSize, $statusFile);
        if ($process) {
            $processes[$batchId] = $process;
            $logger->info("启动批次 {$batchId}: offset={$offset}, limit={$currentBatchSize}");
        }
        
        $batchIndex++;
        
        // 短暂延迟避免同时启动过多进程
        usleep(100000); // 0.1秒
    }
    
    // 等待所有进程完成
    $logger->info("等待所有批次完成...");
    waitForAllProcesses($processes, $logger);
    
    // 更新主状态为完成
    if (file_exists($mainStatusFile)) {
        $mainStatus = json_decode(file_get_contents($mainStatusFile), true);
        $mainStatus['status'] = 'completed';
        $mainStatus['end_time'] = time();
        file_put_contents($mainStatusFile, json_encode($mainStatus));
    }
    
    $logger->info("批次管理器完成");
    
} catch (Exception $e) {
    $logger->error("批次管理器出现错误: " . $e->getMessage());
    
    // 更新主状态为错误
    if (file_exists($mainStatusFile)) {
        $mainStatus = json_decode(file_get_contents($mainStatusFile), true);
        $mainStatus['status'] = 'error';
        $mainStatus['error'] = $e->getMessage();
        $mainStatus['end_time'] = time();
        file_put_contents($mainStatusFile, json_encode($mainStatus));
    }
    
    exit(1);
}

/**
 * 启动单个批次检测进程
 */
function startBatchProcess($batchId, $offset, $limit, $statusFile) {
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
function waitForProcesses(&$processes, $maxRemaining, $logger) {
    while (count($processes) > $maxRemaining) {
        foreach ($processes as $batchId => $process) {
            // 检查进程是否完成
            $status = pclose($process);
            unset($processes[$batchId]);
            $logger->info("批次 {$batchId} 完成");
            break; // 只等待一个进程完成
        }
        usleep(500000); // 0.5秒检查间隔
    }
}

/**
 * 等待所有进程完成
 */
function waitForAllProcesses($processes, $logger) {
    foreach ($processes as $batchId => $process) {
        pclose($process);
        $logger->info("批次 {$batchId} 完成");
    }
}

/**
 * 检查是否被取消
 */
function isCancelled($tempDir) {
    $cancelFile = $tempDir . '/cancel.flag';
    return file_exists($cancelFile);
}
?>
