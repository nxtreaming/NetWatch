<?php
/**
 * 手动更新流量数据的AJAX端点
 * 已禁用 - 只允许定时任务更新数据
 */

header('Content-Type: application/json');

// 禁止手动更新，只允许定时任务更新
echo json_encode([
    'success' => false,
    'error' => 'disabled',
    'message' => '手动更新已禁用，数据由定时任务自动更新'
]);
exit;

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

try {
    $trafficMonitor = new TrafficMonitor();
    
    // 更新实时流量数据
    $result = $trafficMonitor->updateRealtimeTraffic();
    
    if ($result) {
        // 获取最新数据
        $data = $trafficMonitor->getRealtimeTraffic();
        
        // 计算总使用流量 (RX + TX)
        $totalUsedTraffic = 0;
        if (isset($data['rx_bytes']) && isset($data['tx_bytes'])) {
            $totalUsedTraffic = ($data['rx_bytes'] + $data['tx_bytes']) / (1024*1024*1024);
        }
        
        echo json_encode([
            'success' => true,
            'message' => '流量数据更新成功',
            'data' => [
                'total_bandwidth' => $data['total_bandwidth'],
                'used_bandwidth' => $data['used_bandwidth'],
                'remaining_bandwidth' => $data['remaining_bandwidth'],
                'usage_percentage' => $data['usage_percentage'],
                'updated_at' => $data['updated_at'],
                'rx_bytes' => isset($data['rx_bytes']) ? $data['rx_bytes'] : 0,
                'tx_bytes' => isset($data['tx_bytes']) ? $data['tx_bytes'] : 0,
                'port' => isset($data['port']) ? $data['port'] : 0,
                'formatted' => [
                    'total' => $trafficMonitor->formatBandwidth($data['total_bandwidth']),
                    'used' => $trafficMonitor->formatBandwidth($totalUsedTraffic),
                    'remaining' => $trafficMonitor->formatBandwidth($data['remaining_bandwidth']),
                    'percentage' => $trafficMonitor->formatPercentage($data['usage_percentage']),
                    'rx' => isset($data['rx_bytes']) ? $trafficMonitor->formatBandwidth($data['rx_bytes'] / (1024*1024*1024)) : '0.00 GB',
                    'tx' => isset($data['tx_bytes']) ? $trafficMonitor->formatBandwidth($data['tx_bytes'] / (1024*1024*1024)) : '0.00 GB'
                ]
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '流量数据更新失败，请检查API配置'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '更新失败: ' . $e->getMessage()
    ]);
}
