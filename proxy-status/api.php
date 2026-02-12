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
                // 计算当月累计流量
                $totalTrafficRaw = 0;
                if (isset($realtimeData['rx_bytes']) && isset($realtimeData['tx_bytes'])) {
                    $rxBytes = floatval($realtimeData['rx_bytes']);
                    $txBytes = floatval($realtimeData['tx_bytes']);
                    $totalTrafficRaw = ($rxBytes + $txBytes) / (1024*1024*1024);
                }
                
                // 获取上月最后快照计算当月累计
                $firstDayOfMonth = date('Y-m-01');
                $lastDayOfPrevMonth = date('Y-m-d', strtotime($firstDayOfMonth . ' -1 day'));
                $prevMonthLastSnapshot = $trafficMonitor->getLastSnapshotOfDay($lastDayOfPrevMonth);
                
                $totalTraffic = $totalTrafficRaw;
                if ($prevMonthLastSnapshot) {
                    $prevTotal = ($prevMonthLastSnapshot['rx_bytes'] + $prevMonthLastSnapshot['tx_bytes']) / (1024*1024*1024);
                    $monthlyUsed = $totalTrafficRaw - $prevTotal;
                    if ($monthlyUsed >= 0) {
                        $totalTraffic = $monthlyUsed;
                    }
                }
                
                // 计算今日使用量（与 index.php 保持一致，使用快照数据）
                $isFirstDayOfMonth = (date('d') === '01');
                $yesterdayStr = date('Y-m-d', strtotime('-1 day'));
                $yesterdayUsedBandwidth = 0;
                
                if ($isFirstDayOfMonth) {
                    $todayDailyUsage = $totalTraffic;
                } else {
                    // 获取昨日 used_bandwidth（用于后续一致性重算）
                    $yesterdayStats = $trafficMonitor->getStatsForDate($yesterdayStr);
                    if ($yesterdayStats && isset($yesterdayStats['used_bandwidth'])) {
                        $yesterdayUsedBandwidth = floatval($yesterdayStats['used_bandwidth']);
                    }
                    
                    // 使用快照计算今日增量（今日原始值 - 昨日最后快照）
                    $yesterdayLastSnapshot = $trafficMonitor->getLastSnapshotOfDay($yesterdayStr);
                    if ($yesterdayLastSnapshot) {
                        $yesterdayLastTotal = ($yesterdayLastSnapshot['rx_bytes'] + $yesterdayLastSnapshot['tx_bytes']) / (1024*1024*1024);
                        $todayDailyUsage = $totalTrafficRaw - $yesterdayLastTotal;
                        
                        if ($todayDailyUsage < 0) {
                            $todayDailyUsage = $totalTraffic;
                        }
                    } else {
                        // 没有昨日快照，使用数据库值计算
                        $todayDailyUsage = $totalTraffic - $yesterdayUsedBandwidth;
                        if ($todayDailyUsage < 0) {
                            $todayDailyUsage = $totalTraffic;
                        }
                    }
                }
                
                // 重新计算今日已用流量，确保一致性：今日已用 = 昨日已用 + 今日使用
                if (!$isFirstDayOfMonth) {
                    $totalTraffic = $yesterdayUsedBandwidth + $todayDailyUsage;
                }
                
                // 替换今日数据
                foreach ($stats as &$stat) {
                    if ($stat['usage_date'] === $todayStr) {
                        $stat['daily_usage'] = $todayDailyUsage;
                        $stat['used_bandwidth'] = $totalTraffic;
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
