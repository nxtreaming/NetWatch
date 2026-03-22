<?php

class AuditLogger {
    private static function getClientIp(): string {
        if (!class_exists('RateLimiter')) {
            require_once __DIR__ . '/RateLimiter.php';
        }

        if (class_exists('RateLimiter')) {
            return RateLimiter::getClientIp();
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function log(string $action, ?string $targetType = null, $targetId = null, $details = null, ?string $username = null): void {
        try {
            if ($username === null && class_exists('Auth')) {
                $username = Auth::getCurrentUser();
            }

            $ip = self::getClientIp();
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

            if (is_array($details) || is_object($details)) {
                $details = json_encode($details, JSON_UNESCAPED_UNICODE);
            } elseif ($details !== null) {
                $details = (string)$details;
            }

            if ($targetId !== null) {
                $targetId = (string)$targetId;
            }

            if (!defined('DB_PATH')) {
                return;
            }

            if (!class_exists('Database')) {
                require_once __DIR__ . '/../database.php';
            }

            if (!class_exists('Database')) {
                return;
            }

            $db = new Database();
            $db->initializeSchema();
            $db->addAuditLog($username, $action, $targetType, $targetId, $details, $ip, $ua);
        } catch (Throwable $e) {
            error_log('[NetWatch][AuditLogger] Failed to write audit log: ' . $e->getMessage());
        }
    }
}
