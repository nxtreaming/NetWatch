<?php
/**
 * NetWatch 通用工具函数
 */

function netwatch_is_ajax_mode_request(): bool {
    return isset($_GET['ajax']) && (
        $_GET['ajax'] === '1'
        || $_GET['ajax'] === 'true'
    );
}

function netwatch_request_expects_json_response(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isXmlHttpRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $acceptsJson = $accept !== '' && strpos($accept, 'application/json') !== false;
    $acceptsHtml = $accept !== '' && strpos($accept, 'text/html') !== false;

    return $isXmlHttpRequest || netwatch_is_ajax_mode_request() || ($acceptsJson && !$acceptsHtml);
}

function netwatch_enforce_entrypoint_paths(string $defaultScriptName): void {
    $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? $defaultScriptName);
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $allowedPaths = [
        $scriptDir === '' ? '/' : $scriptDir . '/',
        ($scriptDir === '' ? '' : $scriptDir) . '/index.php',
    ];

    if ($requestPath !== '' && !in_array($requestPath, $allowedPaths, true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '404 Not Found';
        exit;
    }
}

function netwatch_is_csrf_exempt_ajax_action(string $action, ?string $requestMethod = null): bool {
    $method = strtoupper($requestMethod ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $csrfExemptReadActions = [
        'sessionCheck',
        'stats',
        'logs',
        'getProxyCount',
        'getParallelProgress',
        'getOfflineParallelProgress',
        'search',
        'debugStatuses'
    ];

    return $method === 'GET' && in_array($action, $csrfExemptReadActions, true);
}

/**
 * 验证是否为真正的AJAX请求
 * 防止移动端浏览器错误地将页面请求误认为AJAX请求
 */
function isValidAjaxRequest(): bool {
    if (!netwatch_is_ajax_mode_request()) {
        return false;
    }

    $xRequestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $secFetchMode = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
    $secFetchDest = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
    $csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

    $isXmlHttpRequest = $xRequestedWith === 'xmlhttprequest';
    $acceptsJson = strpos($accept, 'application/json') !== false;
    $contentTypeJson = strpos($contentType, 'json') !== false;
    $hasCsrfHeader = $csrfToken !== '';
    $isProgrammaticFetch = in_array($secFetchMode, ['cors', 'same-origin'], true)
        || ($secFetchDest !== '' && $secFetchDest !== 'document');

    $refererHost = strtolower((string) parse_url($referer, PHP_URL_HOST));
    $refererPort = parse_url($referer, PHP_URL_PORT);
    $normalizedHost = $host;
    if (strpos($normalizedHost, ':') !== false) {
        $normalizedHost = strtolower((string) parse_url('http://' . $normalizedHost, PHP_URL_HOST));
    }
    $hasSameOriginReferer = $refererHost !== ''
        && $normalizedHost !== ''
        && $refererHost === $normalizedHost
        && ($refererPort === null || strpos($host, ':' . $refererPort) !== false || strpos($host, ':') === false);

    return $isXmlHttpRequest
        || $hasCsrfHeader
        || $contentTypeJson
        || ($acceptsJson && ($isProgrammaticFetch || $hasSameOriginReferer))
        || ($isProgrammaticFetch && $hasSameOriginReferer);
}

/**
 * 格式化时间显示
 * @param string $timeString 时间字符串
 * @param string $format 时间格式，默认'm-d H:i'
 * @param bool $isUtc 是否为UTC时间，默认false（本地时间）
 * @return string 格式化后的时间字符串
 */
function formatTime($timeString, $format = 'm-d H:i', $isUtc = true): string {
    if (!$timeString) {
        return 'N/A';
    }
    
    try {
        if ($isUtc) {
            // 数据库中的时间是UTC，转换为当前系统配置时区
            $dt = new DateTime($timeString, new DateTimeZone('UTC'));
            $timezoneName = date_default_timezone_get();
            if (!is_string($timezoneName) || $timezoneName === '') {
                $timezoneName = 'UTC';
            }
            $dt->setTimezone(new DateTimeZone($timezoneName));
        } else {
            // 直接格式化本地时间
            $dt = new DateTime($timeString);
        }
        return $dt->format($format);
    } catch (Exception $e) {
        // 如果转换失败，使用原始方法
        return date($format, strtotime($timeString));
    }
}
