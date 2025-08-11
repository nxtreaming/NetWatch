<?php
/**
 * 并行代理检测工作进程
 * 处理单个批次的代理检测任务
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置执行时间限制
set_time_limit(600); // 10分钟

require_once 'config.php';
require_once 'database.php';
require_once 'monitor.php';
require_once 'logger.php';

// 检查命令行参数
if ($argc < 5) {
    echo "Usage: php parallel_worker.php <batch_id> <offset> <limit> <status_file>\n";
    exit(1);
}

$batchId = $argv[1];
$offset = (int)$argv[2];
$limit = (int)$argv[3];
$statusFile = $argv[4];

// 初始化组件
$db = new Database();
$monitor = new NetworkMonitor();
$logger = new Logger();

$logger->info("工作进程启动: {$batchId}, offset={$offset}, limit={$limit}");

try {
    // 更新批次状态为运行中
    updateBatchStatus($statusFile, [
        'status' => 'running',
        'start_time' => time()
    ]);
    
    // 获取当前批次的代理列表
    $proxies = $db->getProxiesBatch($offset, $limit);
    $totalProxies = count($proxies);
    
    if ($totalProxies == 0) {
        updateBatchStatus($statusFile, [
            'status' => 'completed',
            'end_time' => time(),
            'error' => '没有找到代理数据'
        ]);
        exit(0);
    }
    
    $logger->info("批次 {$batchId} 获取到 {$totalProxies} 个代理");
    
    // 检测每个代理
    $checkedCount = 0;
    $onlineCount = 0;
    $offlineCount = 0;
    
    foreach ($proxies as $proxy) {
        // 检查是否被取消
        if (isCancelled()) {
            $logger->info("批次 {$batchId} 被取消");
            updateBatchStatus($statusFile, [
                'status' => 'cancelled',
                'end_time' => time()
            ]);
            exit(0);
        }
        
        // 使用快速检测方法
        $result = $monitor->checkProxyFast($proxy);
        
        $checkedCount++;
        if ($result['status'] === 'online') {
            $onlineCount++;
        } else {
            $offlineCount++;
        }
        
        // 更新进度
        $progress = ($checkedCount / $totalProxies) * 100;
        updateBatchStatus($statusFile, [
            'progress' => round($progress, 2),
            'checked' => $checkedCount,
            'online' => $onlineCount,
            'offline' => $offlineCount
        ]);
        
        // 短暂延迟避免过于频繁的请求
        usleep(3000); // 0.005秒
        
        // 每检查20个代理记录一次日志
        if ($checkedCount % 20 == 0) {
            $logger->info("批次 {$batchId} 进度: {$checkedCount}/{$totalProxies} (在线: {$onlineCount}, 离线: {$offlineCount})");
        }
    }
    
    // 标记批次完成
    updateBatchStatus($statusFile, [
        'status' => 'completed',
        'progress' => 100,
        'end_time' => time()
    ]);
    
    $logger->info("批次 {$batchId} 完成: 检查 {$checkedCount} 个代理，在线 {$onlineCount} 个，离线 {$offlineCount} 个");
    
} catch (Exception $e) {
    $logger->error("批次 {$batchId} 出现错误: " . $e->getMessage());
    
    updateBatchStatus($statusFile, [
        'status' => 'error',
        'end_time' => time(),
        'error' => $e->getMessage()
    ]);
    
    exit(1);
}

/**
 * 更新批次状态
 */
function updateBatchStatus($statusFile, $updates) {
    if (!file_exists($statusFile)) {
        return false;
    }
    
    // 使用文件锁确保原子性操作
    $lockFile = $statusFile . '.lock';
    $lockHandle = fopen($lockFile, 'w');
    
    if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {
        if ($lockHandle) fclose($lockHandle);
        return false;
    }
    
    try {
        $status = json_decode(file_get_contents($statusFile), true);
        if (!$status) {
            return false;
        }
        
        // 合并更新
        foreach ($updates as $key => $value) {
            $status[$key] = $value;
        }
        
        // 原子性写入：先写临时文件，再重命名
        $tempFile = $statusFile . '.tmp';
        $result = file_put_contents($tempFile, json_encode($status, JSON_UNESCAPED_UNICODE));
        
        if ($result !== false) {
            // 原子性重命名
            $success = rename($tempFile, $statusFile);
            
            // 强制刷新文件系统缓存
            if ($success && function_exists('opcache_invalidate')) {
                opcache_invalidate($statusFile, true);
            }
            
            return $success;
        }
        
        return false;
        
    } finally {
        // 释放锁
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockFile);
    }
}

/**
 * 检查是否被取消
 */
function isCancelled() {
    global $statusFile;
    // 从状态文件路径推导出临时目录
    $tempDir = dirname($statusFile);
    $cancelFile = $tempDir . '/cancel.flag';
    return file_exists($cancelFile);
}
