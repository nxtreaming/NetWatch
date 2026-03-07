<?php
/**
 * NetWatch 安全响应头（Web 请求）
 */

if (!function_exists('netwatch_is_https_request')) {
    function netwatch_is_https_request(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos((string) $_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
    }
}

if (!function_exists('netwatch_send_security_headers')) {
    function netwatch_send_security_headers(): void {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $csp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: https://cloudflareinsights.com; "
            . "font-src 'self' data:; "
            . "connect-src 'self' https://cdn.jsdelivr.net https://cloudflareinsights.com https://static.cloudflareinsights.com; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "frame-ancestors 'none'; "
            . "form-action 'self'";

        header('Content-Security-Policy: ' . $csp);
    }
}

netwatch_send_security_headers();
