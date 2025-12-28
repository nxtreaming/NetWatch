<?php

class AuditLogger {
    private static function getClientIp(): string {
        $keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $value = trim((string)$_SERVER[$key]);
                if ($key === 'HTTP_X_FORWARDED_FOR') {
                    $parts = explode(',', $value);
                    $value = trim($parts[0] ?? '');
                }
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
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
            $db->addAuditLog($username, $action, $targetType, $targetId, $details, $ip, $ua);
        } catch (Throwable $e) {
        }
    }
}
