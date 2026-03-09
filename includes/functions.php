<?php
/**
 * NetWatch 通用工具函数
 */

function netwatch_is_ajax_mode_request(): bool {
    return isset($_GET['ajax']) && (
        $_GET['ajax'] === '1'
        || $_GET['ajax'] === 'true'
        || $_GET['ajax'] === 1
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
function formatTime($timeString, $format = 'm-d H:i', $isUtc = true) {
    if (!$timeString) {
        return 'N/A';
    }
    
    try {
        if ($isUtc) {
            // 数据库中的时间是UTC，转换为北京时间
            $dt = new DateTime($timeString, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
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
