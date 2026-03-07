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

require_once 'config.php';
require_once 'database.php';
require_once 'includes/Config.php';
require_once 'logger.php';

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
$maxProcesses = 24; // 最大并行进程数

$logger->info("批次管理器启动: 总代理={$totalProxies}, 批次大小={$batchSize}, 最大进程={$maxProcesses}");

try {
    // 更新主状态为运行中
    $mainStatusFile = $tempDir . '/main_status.json';
    if (file_exists($mainStatusFile)) {
        $mainStatus = readJsonFile($mainStatusFile);
        if (is_array($mainStatus)) {
            $mainStatus['status'] = 'running';
            writeJsonFile($mainStatusFile, $mainStatus);
        }
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
        writeJsonFile($statusFile, $batchStatus);
        
        // 如果达到最大进程数，等待一些进程完成
        if (count($processes) >= $maxProcesses) {
            waitForProcesses($processes, $maxProcesses - 1, $logger);
        }
        
        // 启动新的检测进程
        $process = startBatchProcess($batchId, $offset, $currentBatchSize, $statusFile, $offlineOnly);
        if ($process) {
            $processes[$batchId] = $process;
            $checkType = $offlineOnly ? "离线代理" : "所有代理";
            $logger->info("启动批次 {$batchId} ({$checkType}): offset={$offset}, limit={$currentBatchSize}");
        }
        
        $batchIndex++;
    }
    
    // 等待所有进程完成
    $logger->info("等待所有批次完成...");
    waitForAllProcesses($processes, $logger);
    
    // 更新主状态为完成
    if (file_exists($mainStatusFile)) {
        $mainStatus = readJsonFile($mainStatusFile);
        if (is_array($mainStatus)) {
            $mainStatus['status'] = isCancelled($tempDir) ? 'cancelled' : 'completed';
            $mainStatus['end_time'] = time();
            writeJsonFile($mainStatusFile, $mainStatus);
        }
    }
    
    $logger->info("批次管理器完成");
    
} catch (Exception $e) {
    $logger->error("批次管理器出现错误: " . $e->getMessage());
    
    // 更新主状态为错误
    if (file_exists($mainStatusFile)) {
        $mainStatus = readJsonFile($mainStatusFile);
        if (is_array($mainStatus)) {
            $mainStatus['status'] = 'error';
            $mainStatus['error'] = $e->getMessage();
            $mainStatus['end_time'] = time();
            writeJsonFile($mainStatusFile, $mainStatus);
        }
    }
    
    exit(1);
}

/**
 * 读取 JSON 文件
 * @param string $filePath 文件路径
 * @return array|null 解析后的数组
 */
function readJsonFile(string $filePath): ?array {
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
 * @param string $filePath 文件路径
 * @param array $payload 写入内容
 * @return bool 是否成功
 */
function writeJsonFile(string $filePath, array $payload): bool {
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
 * 判断批次是否已结束
 * @param string $statusFile 状态文件路径
 * @return bool 是否已结束
 */
function isBatchFinished(string $statusFile): bool {
    $status = readJsonFile($statusFile);
    if (!is_array($status)) {
        return false;
    }

    return in_array($status['status'] ?? '', ['completed', 'cancelled', 'error'], true);
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
function waitForProcesses(array &$processes, int $maxRemaining, Logger $logger): void {
    while (count($processes) > $maxRemaining) {
        $completedBatchId = null;

        foreach ($processes as $batchId => $statusFile) {
            if (isBatchFinished($statusFile)) {
                $completedBatchId = $batchId;
                break;
            }
        }

        if ($completedBatchId !== null) {
            unset($processes[$completedBatchId]);
            $logger->info("批次 {$completedBatchId} 完成");
        }

        usleep((int) config('monitoring.parallel_batch_poll_us', 500000)); // 0.5秒检查间隔
    }
}

/**
 * 等待所有进程完成
 * @param array $processes 进程列表
 * @param Logger $logger 日志对象
 */
function waitForAllProcesses(array $processes, Logger $logger): void {
    while (!empty($processes)) {
        foreach ($processes as $batchId => $statusFile) {
            if (isBatchFinished($statusFile)) {
                unset($processes[$batchId]);
                $logger->info("批次 {$batchId} 完成");
            }
        }

        if (!empty($processes)) {
            usleep((int) config('monitoring.parallel_batch_poll_us', 500000));
        }
    }
}

/**
 * 检查是否被取消
 * @param string $tempDir 临时目录路径
 * @return bool 是否已取消
 */
function isCancelled(string $tempDir): bool {
    $cancelFile = $tempDir . '/cancel.flag';
    return file_exists($cancelFile);
}
?>
