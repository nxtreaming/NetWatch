<?php
/**
 * 流量监控API端点
 * 支持局部刷新功能
 */

header('Content-Type: application/json');

require_once '../config.php';
require_once '../auth.php';
require_once '../traffic_monitor.php';

// 检查登录状态
if (!Auth::isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'error' => 'unauthorized',
        'message' => '请先登录后再执行此操作'
    ]);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    $trafficMonitor = new TrafficMonitor();
    
    switch ($action) {
        case 'chart':
            // 获取流量图表数据
            $date = $_GET['date'] ?? date('Y-m-d');
            
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('无效的日期格式');
            }
            
            if ($date === date('Y-m-d')) {
                // 获取今日数据
                $snapshots = $trafficMonitor->getTodaySnapshots();
            } else {
                // 获取指定日期数据
                $snapshots = $trafficMonitor->getSnapshotsByDate($date);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $snapshots,
                'date' => $date,
                'is_today' => ($date === date('Y-m-d'))
            ]);
            break;
            
        case 'stats':
            // 获取统计数据
            $centerDate = $_GET['date'] ?? null;
            
            if ($centerDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $centerDate)) {
                throw new Exception('无效的日期格式');
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
                $monthlyContext = $trafficMonitor->buildMonthlyTrafficContext($realtimeData);
                $totalTraffic = $monthlyContext['total_traffic'];
                $totalTrafficRaw = $monthlyContext['total_traffic_raw'];
                $prevMonthLastSnapshot = $monthlyContext['prev_month_last_snapshot'];

                $todayContext = $trafficMonitor->buildTodayDisplayContext($totalTrafficRaw, $totalTraffic, $prevMonthLastSnapshot);
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
            
            echo json_encode([
                'success' => true,
                'data' => $stats,
                'center_date' => $centerDate
            ]);
            break;
            
        default:
            throw new Exception('不支持的操作');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
