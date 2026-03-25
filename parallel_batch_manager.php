<?php
/**
 * 并行批次管理器
 * 负责管理和启动多个并行批次
 */

// 设置错误报告：仅记录到日志，不向外部输出
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 设置执行时间限制
set_time_limit(0); // 无限制

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/includes/Config.php';
require_once __DIR__ . '/includes/ParallelStatusUtils.php';
require_once __DIR__ . '/logger.php';

// 检查命令行参数
if ($argc < 4) {
    echo "Usage: php parallel_batch_manager.php <total_proxies> <batch_size> <temp_dir> [offline_only]\n";
    exit(1);
}

$totalProxies = intval($argv[1]);
$batchSize = intval($argv[2]);
$tempDir = $argv[3];
$offlineOnly = isset($argv[4]) && $argv[4] === '1';

// 初始化组件
$logger = new Logger();
$maxProcesses = (int) config('monitoring.parallel_max_processes', 24);
$managerStartTime = time();
$managerMaxRuntimeSeconds = max(60, (int) config('monitoring.parallel_manager_max_runtime_seconds', 1800));
$batchMaxRuntimeSeconds = max(60, (int) config('monitoring.parallel_batch_max_runtime_seconds', 900));

$logger->info('parallel_batch_manager_started', [
    'total_proxies' => $totalProxies,
    'batch_size' => $batchSize,
    'max_processes' => $maxProcesses,
    'temp_dir' => $tempDir,
    'offline_only' => $offlineOnly,
]);

try {
    // 更新主状态为运行中
    $mainStatusFile = $tempDir . '/main_status.json';
    if (file_exists($mainStatusFile)) {
        $mainStatus = netwatch_read_json_file($mainStatusFile);
        if (is_array($mainStatus)) {
            $mainStatus['status'] = 'running';
            netwatch_write_json_file($mainStatusFile, $mainStatus);
        }
    }
    
    // 计算批次数
    $totalBatches = ceil($totalProxies / $batchSize);
    $processes = [];
    $batchIndex = 0;
    
    // 启动所有批次
    for ($offset = 0; $offset < $totalProxies; $offset += $batchSize) {
        // 检查是否被取消
        if (netwatch_is_cancelled_dir($tempDir)) {
            $logger->info('parallel_batch_manager_cancelled', [
                'temp_dir' => $tempDir,
                'started_batch_count' => $batchIndex,
            ]);
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
        netwatch_write_json_file($statusFile, $batchStatus);
        
        // 如果达到最大进程数，等待一些进程完成
        if (count($processes) >= $maxProcesses) {
            waitForProcesses(
                $processes,
                $maxProcesses - 1,
                $logger,
                $tempDir,
                $managerStartTime,
                $managerMaxRuntimeSeconds,
                $batchMaxRuntimeSeconds
            );
        }
        
        // 启动新的检测进程
        $process = startBatchProcess($batchId, $offset, $currentBatchSize, $statusFile, $offlineOnly);
        if ($process) {
            $processes[$batchId] = $process;
            $checkType = $offlineOnly ? "离线代理" : "所有代理";
            $logger->info('parallel_batch_started', [
                'batch_id' => $batchId,
                'check_type' => $checkType,
                'offset' => $offset,
                'limit' => $currentBatchSize,
                'status_file' => $statusFile,
                'temp_dir' => $tempDir,
                'offline_only' => $offlineOnly,
            ]);
        }
        
        $batchIndex++;
    }
    
    // 等待所有进程完成
    $logger->info('parallel_batch_manager_waiting_for_completion', [
        'active_batch_count' => count($processes),
        'temp_dir' => $tempDir,
    ]);
    waitForAllProcesses(
        $processes,
        $logger,
        $tempDir,
        $managerStartTime,
        $managerMaxRuntimeSeconds,
        $batchMaxRuntimeSeconds
    );
    
    // 更新主状态为完成
    if (file_exists($mainStatusFile)) {
        $mainStatus = netwatch_read_json_file($mainStatusFile);
        if (is_array($mainStatus)) {
            $mainStatus['status'] = netwatch_is_cancelled_dir($tempDir) ? 'cancelled' : 'completed';
            $mainStatus['end_time'] = time();
            netwatch_write_json_file($mainStatusFile, $mainStatus);
        }
    }
    
    $logger->info('parallel_batch_manager_completed', [
        'total_batches' => $totalBatches,
        'temp_dir' => $tempDir,
        'offline_only' => $offlineOnly,
    ]);
    
} catch (Throwable $e) {
    $logger->error('parallel_batch_manager_failed', [
        'temp_dir' => $tempDir,
        'total_proxies' => $totalProxies,
        'batch_size' => $batchSize,
        'exception' => $e->getMessage(),
    ]);
    
    // 更新主状态为错误
    if (file_exists($mainStatusFile)) {
        $mainStatus = netwatch_read_json_file($mainStatusFile);
        if (is_array($mainStatus)) {
            $mainStatus['status'] = 'error';
            $mainStatus['error'] = $e->getMessage();
            $mainStatus['end_time'] = time();
            netwatch_write_json_file($mainStatusFile, $mainStatus);
        }
    }
    
    exit(1);
}

/**
 * 启动单个批次检测进程
 * @param string $batchId 批次ID
 * @param int $offset 偏移量
 * @param int $limit 数量限制
 * @param string $statusFile 状态文件路径
 * @param bool $offlineOnly 是否只检测离线代理
 * @return string|false 状态文件路径
 */
function startBatchProcess(string $batchId, int $offset, int $limit, string $statusFile, bool $offlineOnly = false) {
    $scriptPath = __DIR__ . '/parallel_worker.php';
    $offlineFlag = $offlineOnly ? '1' : '0';

    // 所有参数均通过 escapeshellarg 转义，防止命令注入
    $args = implode(' ', [
        escapeshellarg($scriptPath),
        escapeshellarg($batchId),
        (int)$offset,
        (int)$limit,
        escapeshellarg($statusFile),
        $offlineFlag,
    ]);

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = 'start /B php ' . $args;
    } else {
        $command = 'php ' . $args . ' > /dev/null 2>&1 &';
    }

    $process = popen($command, 'r');
    if (is_resource($process)) {
        pclose($process);
        return $statusFile;
    }

    return false;
}

/**
 * 等待指定数量的进程完成
 * @param array $processes 进程列表
 * @param int $maxRemaining 最大剩余进程数
 * @param Logger $logger 日志对象
 */
function waitForProcesses(
    array &$processes,
    int $maxRemaining,
    Logger $logger,
    string $tempDir,
    int $managerStartTime,
    int $managerMaxRuntimeSeconds,
    int $batchMaxRuntimeSeconds
): void {
    while (count($processes) > $maxRemaining) {
        if ((time() - $managerStartTime) > $managerMaxRuntimeSeconds) {
            forceCompleteRemainingBatches($processes, 'manager_timeout', $logger);
            break;
        }

        $completedBatchId = null;

        foreach ($processes as $batchId => $statusFile) {
            if (
                netwatch_is_batch_finished($statusFile)
                || finalizeStalledBatchIfNeeded($statusFile, $batchId, $batchMaxRuntimeSeconds, $logger)
            ) {
                $completedBatchId = $batchId;
                break;
            }
        }

        if ($completedBatchId !== null) {
            unset($processes[$completedBatchId]);
            $logger->info('parallel_batch_finished', [
                'batch_id' => $completedBatchId,
                'remaining_process_count' => count($processes),
            ]);
        }

        usleep((int) config('monitoring.parallel_batch_poll_us', 500000)); // 0.5秒检查间隔
    }
}

function finalizeStalledBatchIfNeeded(string $statusFile, string $batchId, int $batchMaxRuntimeSeconds, Logger $logger): bool {
    $status = netwatch_read_json_file($statusFile);
    if (!is_array($status)) {
        return false;
    }

    $currentStatus = (string) ($status['status'] ?? '');
    if (in_array($currentStatus, ['completed', 'cancelled', 'error'], true)) {
        return true;
    }

    $startTime = (int) ($status['start_time'] ?? 0);
    if ($startTime <= 0 || (time() - $startTime) <= $batchMaxRuntimeSeconds) {
        return false;
    }

    $status['status'] = 'error';
    $status['error'] = 'batch_timeout';
    $status['end_time'] = time();
    netwatch_write_json_file($statusFile, $status);

    $logger->warning('parallel_batch_stalled_timeout', [
        'batch_id' => $batchId,
        'status_file' => $statusFile,
        'batch_max_runtime_seconds' => $batchMaxRuntimeSeconds,
    ]);

    return true;
}

function forceCompleteRemainingBatches(array &$processes, string $reason, Logger $logger): void {
    foreach ($processes as $batchId => $statusFile) {
        $status = netwatch_read_json_file($statusFile);
        if (!is_array($status)) {
            continue;
        }

        $currentStatus = (string) ($status['status'] ?? '');
        if (in_array($currentStatus, ['completed', 'cancelled', 'error'], true)) {
            continue;
        }

        $status['status'] = $reason === 'cancelled' ? 'cancelled' : 'error';
        $status['error'] = $reason === 'cancelled' ? null : $reason;
        $status['end_time'] = time();
        netwatch_write_json_file($statusFile, $status);

        $logger->warning('parallel_batch_forced_completion', [
            'batch_id' => $batchId,
            'reason' => $reason,
            'status_file' => $statusFile,
        ]);
    }

    $processes = [];
}

/**
 * 等待所有进程完成
 * @param array $processes 进程列表
 * @param Logger $logger 日志对象
 */
function waitForAllProcesses(
    array $processes,
    Logger $logger,
    string $tempDir,
    int $managerStartTime,
    int $managerMaxRuntimeSeconds,
    int $batchMaxRuntimeSeconds
): void {
    while (!empty($processes)) {
        if (netwatch_is_cancelled_dir($tempDir)) {
            forceCompleteRemainingBatches($processes, 'cancelled', $logger);
            break;
        }

        if ((time() - $managerStartTime) > $managerMaxRuntimeSeconds) {
            forceCompleteRemainingBatches($processes, 'manager_timeout', $logger);
            break;
        }

        foreach ($processes as $batchId => $statusFile) {
            if (
                netwatch_is_batch_finished($statusFile)
                || finalizeStalledBatchIfNeeded($statusFile, $batchId, $batchMaxRuntimeSeconds, $logger)
            ) {
                unset($processes[$batchId]);
                $logger->info('parallel_batch_finished', [
                    'batch_id' => $batchId,
                    'remaining_process_count' => count($processes),
                ]);
            }
        }

        if (!empty($processes)) {
            usleep((int) config('monitoring.parallel_batch_poll_us', 500000));
        }
    }
}

