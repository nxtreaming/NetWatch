<?php

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/JsonResponse.php';
require_once __DIR__ . '/ajax_handler.php';
require_once __DIR__ . '/functions.php';

class IndexPageController {
    private NetworkMonitor $monitor;
    private Logger $logger;

    public function __construct(NetworkMonitor $monitor, ?Logger $logger = null) {
        $this->monitor = $monitor;
        $this->logger = $logger ?? new Logger();
    }

    public function handleAjaxRequest(string $action): void {
        $isValidAjax = isValidAjaxRequest();

        if (!$isValidAjax) {
            $this->redirectInvalidAjaxRequest($action);
            return;
        }

        if ($action !== 'sessionCheck' && !Auth::isLoggedIn()) {
            JsonResponse::error('unauthorized', '登录已过期，请重新登录', 401);
            return;
        }

        if (!netwatch_is_csrf_exempt_ajax_action($action)) {
            $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!Auth::validateCsrfToken($csrfToken)) {
                JsonResponse::error('csrf_validation_failed', 'CSRF验证失败，请刷新页面后重试', 403);
                return;
            }
        }

        $ajaxHandler = new AjaxHandler($this->monitor, $this->monitor->getDatabase());
        $ajaxHandler->handleRequest($action);
    }

    public function prepareDashboardData(int $page, int $perPage, string $searchTerm, string $statusFilter): array {
        $stats = $this->monitor->getStats();

        if ($searchTerm !== '' || $statusFilter !== '') {
            $proxies = $this->monitor->searchProxiesSafe($searchTerm, $page, $perPage, $statusFilter);
            $totalProxies = $this->monitor->getSearchCount($searchTerm, $statusFilter);
        } else {
            $totalProxies = $this->monitor->getProxyCount();
            $proxies = $this->monitor->getProxiesPaginatedSafe($page, $perPage);
        }

        return [
            'stats' => $stats,
            'proxies' => $proxies,
            'totalProxies' => $totalProxies,
            'totalPages' => (int) ceil($totalProxies / $perPage),
            'recentLogs' => $this->monitor->getRecentLogs(20),
        ];
    }

    private function redirectInvalidAjaxRequest(string $action): void {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isMobile = strpos($userAgent, 'Mobile') !== false ||
            strpos($userAgent, 'Android') !== false ||
            strpos($userAgent, 'iPhone') !== false ||
            strpos($userAgent, 'iPad') !== false;

        $expectsJson = netwatch_request_expects_json_response();

        $this->logger->warning('invalid_ajax_request_detected', [
            'user_agent' => $userAgent,
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'none',
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? 'none',
            'x_requested_with' => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? 'none',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'action' => $action,
            'is_mobile' => $isMobile,
            'expects_json' => $expectsJson,
        ]);

        if ($expectsJson) {
            JsonResponse::error('invalid_ajax_request', '无效的 AJAX 请求', 400);
            return;
        }

        $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
        $params = $_GET;
        unset($params['ajax']);
        if (!empty($params)) {
            $redirectUrl .= '?' . http_build_query($params);
        }

        if ($isMobile) {
            header('Location: ' . $redirectUrl, true, 302);
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>重定向中...</title>';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '">';
        echo '</head><body>';
        echo '<script>window.location.replace("' . htmlspecialchars($redirectUrl) . '");</script>';
        echo '<p>正在重定向到正确页面...</p>';
        echo '<p><a href="' . htmlspecialchars($redirectUrl) . '">如果没有自动跳转，请点击这里</a></p>';
        echo '</body></html>';
    }
}
