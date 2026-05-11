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

if (!function_exists('netwatch_get_csp_nonce')) {
    function netwatch_get_csp_nonce(): string {
        static $nonce = null;
        if (is_string($nonce) && $nonce !== '') {
            return $nonce;
        }

        try {
            $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        } catch (Throwable $e) {
            $nonce = bin2hex(random_bytes(8));
        }

        return $nonce;
    }
}

if (!function_exists('netwatch_send_security_headers')) {
    function netwatch_send_security_headers(): void {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $nonce = netwatch_get_csp_nonce();
        $csp = "default-src 'self'; "
            . "script-src 'self' 'nonce-" . $nonce . "' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; "
            . "script-src-elem 'self' 'nonce-" . $nonce . "' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; "
            . "script-src-attr 'unsafe-inline'; "
            . "style-src 'self' 'nonce-" . $nonce . "'; "
            . "style-src-elem 'self' 'unsafe-inline'; "
            . "style-src-attr 'unsafe-inline'; "
            . "img-src 'self' data: https://cloudflareinsights.com; "
            . "font-src 'self' data:; "
            . "connect-src 'self' https://cdn.jsdelivr.net https://cloudflareinsights.com https://static.cloudflareinsights.com; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "frame-ancestors 'none'; "
            . "form-action 'self'";

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        if (netwatch_is_https_request()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        header('Content-Security-Policy: ' . $csp);
    }
}

netwatch_send_security_headers();
