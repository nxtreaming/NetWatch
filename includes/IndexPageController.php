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

        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
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

        $isCsrfExempt = $requestMethod === 'GET' && in_array($action, $csrfExemptReadActions, true);

        if (!$isCsrfExempt) {
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
            $db = new Database();
            $db->initializeSchema();
            $proxies = $db->searchProxies($searchTerm, $page, $perPage, $statusFilter);
            $proxies = array_map(function($proxy) {
                unset($proxy['username']);
                unset($proxy['password']);
                return $proxy;
            }, $proxies);
            $totalProxies = $db->getSearchCount($searchTerm, $statusFilter);
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

        $this->logger->warning('检测到无效 AJAX 请求，已降级为重定向', [
            'user_agent' => $userAgent,
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'none',
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? 'none',
            'x_requested_with' => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? 'none',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'action' => $action,
            'is_mobile' => $isMobile
        ]);

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
