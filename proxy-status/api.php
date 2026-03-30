<?php
/**
 * 流量监控API端点
 * 支持局部刷新功能
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/JsonResponse.php';
require_once __DIR__ . '/../includes/RateLimiter.php';
require_once __DIR__ . '/includes/helpers.php';

// CORS: 仅允许同源请求，不对外暴露通配符
$allowedOrigin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    if ($_SERVER['HTTP_ORIGIN'] === $allowedOrigin) {
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Vary: Origin');
    }
    // 非同源请求不添加 CORS 头，浏览器将拒绝跨域访问
}
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../traffic_monitor.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    JsonResponse::error('method_not_allowed', '不支持的请求方法', 405);
    exit;
}

// 检查登录状态
if (!Auth::isLoggedIn()) {
    JsonResponse::error('unauthorized', '请先登录后再执行此操作', 401);
    exit;
}

$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_GET['csrf_token'] ?? ''));
if (!Auth::validateCsrfToken($csrfToken)) {
    JsonResponse::error('csrf_invalid', 'CSRF 验证失败', 403);
    exit;
}

$rateLimiter = RateLimitPresets::api();
$rateLimitKey = 'proxy-status-api:' . RateLimiter::getClientIp();
if (!$rateLimiter->attempt($rateLimitKey)) {
    JsonResponse::error('rate_limited', '请求过于频繁，请稍后再试', 429);
    exit;
}

$action = (string) ($_GET['action'] ?? '');

if (!in_array($action, ['chart', 'stats'], true)) {
    JsonResponse::error('invalid_action', '不支持的操作', 400);
    exit;
}

try {
    $trafficMonitor = new TrafficMonitor();
    
    switch ($action) {
        case 'chart':
            // 获取流量图表数据
            $normalizedDate = proxyStatusNormalizeAndClampDate($_GET['date'] ?? null);
            $date = $normalizedDate ?? date('Y-m-d');

            if (!proxyStatusIsValidDate($date)) {
                JsonResponse::error('invalid_date', '无效的日期格式', 400);
                exit;
            }
            
            if ($date === date('Y-m-d')) {
                // 获取今日数据
                $snapshots = $trafficMonitor->getTodaySnapshots();
            } else {
                // 获取指定日期数据
                $snapshots = $trafficMonitor->getSnapshotsByDate($date);
            }

            $chartDisplayContext = $trafficMonitor->buildSnapshotChartContext($date, $snapshots);
            
            JsonResponse::send([
                'success' => true,
                'data' => $snapshots,
                'chart_context' => $chartDisplayContext,
                'date' => $date,
                'is_today' => ($date === date('Y-m-d'))
            ]);
            break;
            
        case 'stats':
            // 获取统计数据
            $centerDate = proxyStatusNormalizeAndClampDate($_GET['date'] ?? null);

            if (isset($_GET['date']) && $centerDate === null && trim((string) $_GET['date']) !== '') {
                JsonResponse::error('invalid_date', '无效的日期格式', 400);
                exit;
            }
            
            if ($centerDate) {
                // 获取指定日期前后7天的数据
                $stats = $trafficMonitor->getStatsAroundDate($centerDate, 7, 7);
            } else {
                // 获取最近32天的数据
                $stats = $trafficMonitor->getRecentStats(32);
            }
            
            // 如果结果包含今日，用实时数据替换（避免丢失 23:55~00:00 数据）
            $todayStr = date('Y-m-d');
            $realtimeData = $trafficMonitor->getRealtimeTraffic();
            
            if ($realtimeData) {
                $displayContext = $trafficMonitor->buildProxyStatusDisplayContext($realtimeData);
                $todayContext = $displayContext['today_context'];
                $todayDailyUsageForDisplay = $todayContext['today_daily_usage_for_display'];
                $todayUsedBandwidth = $todayContext['today_used_bandwidth'];

                // 替换今日数据
                foreach ($stats as &$stat) {
                    if ($stat['usage_date'] === $todayStr) {
                        $stat['daily_usage'] = $todayDailyUsageForDisplay;
                        $stat['used_bandwidth'] = $todayUsedBandwidth;
                        break;
                    }
                }
                unset($stat);
            }
            
            JsonResponse::send([
                'success' => true,
                'data' => $stats,
                'center_date' => $centerDate
            ]);
            break;
            
        default:
            JsonResponse::error('invalid_action', '不支持的操作', 400);
            exit;
    }
    
} catch (Throwable $e) {
    // 记录详细错误到服务器日志，不向客户端暴露内部信息
    error_log('[NetWatch][proxy-status/api] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    JsonResponse::error('request_failed', '请求处理失败，请稍后重试', 500);
}
