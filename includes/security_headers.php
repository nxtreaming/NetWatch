<?php
/**
 * NetWatch 安全响应头（Web 请求）
 */

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
            . "connect-src 'self' https://cloudflareinsights.com https://static.cloudflareinsights.com; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "frame-ancestors 'none'; "
            . "form-action 'self'";

        header('Content-Security-Policy: ' . $csp);
    }
}

netwatch_send_security_headers();
