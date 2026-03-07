<?php

require_once __DIR__ . '/Config.php';

class ParallelCheckController {
    private Logger $logger;

    public function __construct(?Logger $logger = null) {
        $this->logger = $logger ?? new Logger();
    }

    public function startParallelCheck(bool $offlineOnly = false): void {
        $this->setJsonHeaders();
        try {
            require_once 'parallel_monitor.php';
            $sessionId = session_id() . '_' . time() . '_' . mt_rand(1000, 9999);

            if (file_exists(__DIR__ . '/AuditLogger.php')) {
                require_once __DIR__ . '/AuditLogger.php';
                AuditLogger::log('parallel_check_start', 'proxy', $sessionId, [
                    'offline_only' => (bool)$offlineOnly
                ]);
            }

            if ($offlineOnly) {
                $maxProcesses = 8;
                $batchSize = 50;
            } else {
                $maxProcesses = (int) config('monitoring.parallel_max_processes', 24);
                $batchSize = (int) config('monitoring.parallel_batch_size', 200);
            }

            $parallelMonitor = new ParallelMonitor($maxProcesses, $batchSize, $sessionId, $offlineOnly);
            $result = $parallelMonitor->startParallelCheck();

            echo json_encode($result);
        } catch (Exception $e) {
            $checkType = $offlineOnly ? '离线代理' : '并行';
            error_log('[NetWatch][ajax] startParallelCheck: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => "启动{$checkType}检测失败，请稍后重试"
            ]);
        }
    }

    public function getParallelProgress(): void {
        $this->setJsonHeaders();
        try {
            require_once 'parallel_monitor.php';
            $sessionId = $_GET['session_id'] ?? null;
            if (!$sessionId) {
                echo json_encode(['success' => false, 'error' => '缺少会话ID参数']);
                return;
            }
            if (!self::isValidParallelSessionId($sessionId)) {
                echo json_encode(['success' => false, 'error' => '无效的会话ID']);
                return;
            }
            if (!self::isOwnedParallelSessionId($sessionId)) {
                error_log('[NetWatch][ajax] getParallelProgress: session_id ownership mismatch: ' . $sessionId);
                echo json_encode(['success' => false, 'error' => '无权访问该检测任务']);
                return;
            }
            $parallelMonitor = new ParallelMonitor(
                (int) config('monitoring.parallel_max_processes', 24),
                (int) config('monitoring.parallel_batch_size', 200),
                $sessionId
            );

            $progress = $parallelMonitor->getParallelProgress();
            echo json_encode($progress);
        } catch (Exception $e) {
            error_log('[NetWatch][ajax] getParallelProgress: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => '获取进度失败，请稍后重试'
            ]);
        }
    }

    public function cancelParallelCheck(): void {
        $this->setJsonHeaders();
        try {
            require_once 'parallel_monitor.php';
            $sessionId = $_GET['session_id'] ?? null;
            if (!$sessionId) {
                echo json_encode(['success' => false, 'error' => '缺少会话ID参数']);
                return;
            }
            if (!self::isValidParallelSessionId($sessionId)) {
                echo json_encode(['success' => false, 'error' => '无效的会话ID']);
                return;
            }
            if (!self::isOwnedParallelSessionId($sessionId)) {
                error_log('[NetWatch][ajax] cancelParallelCheck: session_id ownership mismatch: ' . $sessionId);
                echo json_encode(['success' => false, 'error' => '无权操作该检测任务']);
                return;
            }

            if (file_exists(__DIR__ . '/AuditLogger.php')) {
                require_once __DIR__ . '/AuditLogger.php';
                AuditLogger::log('parallel_check_cancel', 'proxy', $sessionId);
            }
            $parallelMonitor = new ParallelMonitor(
                (int) config('monitoring.parallel_max_processes', 24),
                (int) config('monitoring.parallel_batch_size', 200),
                $sessionId
            );

            $result = $parallelMonitor->cancelParallelCheck();
            echo json_encode($result);
        } catch (Exception $e) {
            error_log('[NetWatch][ajax] cancelParallelCheck: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => '取消检测失败，请稍后重试'
            ]);
        }
    }

    private function setJsonHeaders(): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }

    private static function isValidParallelSessionId(string $sessionId): bool {
        return (bool) preg_match('/^[A-Za-z0-9_-]{20,120}$/', $sessionId);
    }

    private static function isOwnedParallelSessionId(string $sessionId): bool {
        if (session_status() === PHP_SESSION_NONE) {
            return false;
        }
        $phpSessionId = session_id();
        if (empty($phpSessionId)) {
            return false;
        }
        return strncmp($sessionId, $phpSessionId . '_', strlen($phpSessionId) + 1) === 0;
    }
}
