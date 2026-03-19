<?php

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/JsonResponse.php';

class ParallelCheckController {
    private Logger $logger;

    public function __construct(?Logger $logger = null) {
        $this->logger = $logger ?? new Logger();
    }

    public function startParallelCheck(bool $offlineOnly = false): void {
        try {
            require_once 'parallel_monitor.php';
            $sid = session_id();
            if ($sid === '') {
                $sid = bin2hex(random_bytes(8));
            }
            $sessionId = $sid . '_' . time() . '_' . mt_rand(1000, 9999);

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

            JsonResponse::send($result);
        } catch (Exception $e) {
            $checkType = $offlineOnly ? '离线代理' : '并行';
            $this->logger->error('parallel_check_controller_start_failed', [
                'offline_only' => $offlineOnly,
                'check_type' => $checkType,
                'exception' => $e->getMessage(),
            ]);
            JsonResponse::error('parallel_check_start_failed', "启动{$checkType}检测失败，请稍后重试", 500);
        }
    }

    public function getParallelProgress(): void {
        try {
            require_once 'parallel_monitor.php';
            $sessionId = $_GET['session_id'] ?? null;
            if (!$sessionId) {
                JsonResponse::error('missing_session_id', '缺少会话ID参数', 400);
                return;
            }
            if (!self::isValidParallelSessionId($sessionId)) {
                JsonResponse::error('invalid_session_id', '无效的会话ID', 400);
                return;
            }
            if (!self::isOwnedParallelSessionId($sessionId)) {
                $this->logger->warning('parallel_check_controller_progress_ownership_mismatch', [
                    'session_id' => $sessionId,
                ]);
                JsonResponse::error('forbidden_session_access', '无权访问该检测任务', 403);
                return;
            }
            $parallelMonitor = new ParallelMonitor(
                (int) config('monitoring.parallel_max_processes', 24),
                (int) config('monitoring.parallel_batch_size', 200),
                $sessionId
            );

            $progress = $parallelMonitor->getParallelProgress();
            JsonResponse::send($progress);
        } catch (Exception $e) {
            $this->logger->error('parallel_check_controller_progress_failed', [
                'session_id' => $sessionId ?? null,
                'exception' => $e->getMessage(),
            ]);
            JsonResponse::error('parallel_check_progress_failed', '获取进度失败，请稍后重试', 500);
        }
    }

    public function cancelParallelCheck(): void {
        try {
            require_once 'parallel_monitor.php';
            $sessionId = $_GET['session_id'] ?? null;
            if (!$sessionId) {
                JsonResponse::error('missing_session_id', '缺少会话ID参数', 400);
                return;
            }
            if (!self::isValidParallelSessionId($sessionId)) {
                JsonResponse::error('invalid_session_id', '无效的会话ID', 400);
                return;
            }
            if (!self::isOwnedParallelSessionId($sessionId)) {
                $this->logger->warning('parallel_check_controller_cancel_ownership_mismatch', [
                    'session_id' => $sessionId,
                ]);
                JsonResponse::error('forbidden_session_access', '无权操作该检测任务', 403);
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
            JsonResponse::send($result);
        } catch (Exception $e) {
            $this->logger->error('parallel_check_controller_cancel_failed', [
                'session_id' => $sessionId ?? null,
                'exception' => $e->getMessage(),
            ]);
            JsonResponse::error('parallel_check_cancel_failed', '取消检测失败，请稍后重试', 500);
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
