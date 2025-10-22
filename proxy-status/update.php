<?php
/**
 * 手动更新流量数据的AJAX端点
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

try {
    $trafficMonitor = new TrafficMonitor();
    
    // 更新实时流量数据
    $result = $trafficMonitor->updateRealtimeTraffic();
    
    if ($result) {
        // 获取最新数据
        $data = $trafficMonitor->getRealtimeTraffic();
        
        echo json_encode([
            'success' => true,
            'message' => '流量数据更新成功',
            'data' => [
                'total_bandwidth' => $data['total_bandwidth'],
                'used_bandwidth' => $data['used_bandwidth'],
                'remaining_bandwidth' => $data['remaining_bandwidth'],
                'usage_percentage' => $data['usage_percentage'],
                'updated_at' => $data['updated_at'],
                'formatted' => [
                    'total' => $trafficMonitor->formatBandwidth($data['total_bandwidth']),
                    'used' => $trafficMonitor->formatBandwidth($data['used_bandwidth']),
                    'remaining' => $trafficMonitor->formatBandwidth($data['remaining_bandwidth']),
                    'percentage' => $trafficMonitor->formatPercentage($data['usage_percentage'])
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
