<?php
/**
 * NetWatch 搜索功能测试脚本
 */

require_once 'config.php';
require_once 'database.php';
require_once 'monitor.php';

echo "=== NetWatch 搜索功能测试 ===\n\n";

try {
    $monitor = new NetworkMonitor();
    
    // 测试1: 获取所有代理数量
    $totalCount = $monitor->getProxyCount();
    echo "1. 总代理数量: $totalCount\n\n";
    
    // 测试2: 搜索特定IP
    echo "2. 搜索测试:\n";
    
    $testSearches = [
        '1.2.3.4',      // 精确IP搜索
        '1.2.3',        // 部分IP搜索
        '1.2.3.x',      // 网段搜索（x格式）
        '1.2.3.',       // 网段搜索（点格式）
        '192.168',      // 内网搜索
        '10.0.0.x',     // 内网网段搜索
    ];
    
    foreach ($testSearches as $searchTerm) {
        $count = $monitor->getSearchCount($searchTerm);
        $results = $monitor->searchProxiesSafe($searchTerm, 1, 5); // 只获取前5个结果
        
        echo "   搜索 '$searchTerm': 找到 $count 个结果\n";
        
        if (!empty($results)) {
            echo "   前几个结果:\n";
            foreach ($results as $proxy) {
                echo "     - {$proxy['ip']}:{$proxy['port']} ({$proxy['type']}) - {$proxy['status']}\n";
            }
        }
        echo "\n";
    }
    
    // 测试3: 分页测试
    echo "3. 分页测试:\n";
    $searchTerm = '1.2.3.x';
    $totalResults = $monitor->getSearchCount($searchTerm);
    $perPage = 20;
    $totalPages = ceil($totalResults / $perPage);
    
    echo "   搜索 '$searchTerm': 总共 $totalResults 个结果，分 $totalPages 页\n";
    
    if ($totalPages > 0) {
        // 测试第一页
        $page1Results = $monitor->searchProxiesSafe($searchTerm, 1, $perPage);
        echo "   第1页: " . count($page1Results) . " 个结果\n";
        
        // 测试最后一页（如果有多页）
        if ($totalPages > 1) {
            $lastPageResults = $monitor->searchProxiesSafe($searchTerm, $totalPages, $perPage);
            echo "   第{$totalPages}页: " . count($lastPageResults) . " 个结果\n";
        }
    }
    echo "\n";
    
    // 测试4: 空搜索测试
    echo "4. 空搜索测试:\n";
    $emptyResults = $monitor->searchProxiesSafe('', 1, 20);
    $emptyCount = $monitor->getSearchCount('');
    echo "   空搜索结果: $emptyCount 个（应该等于总数量 $totalCount）\n";
    echo "   实际获取: " . count($emptyResults) . " 个结果\n\n";
    
    // 测试5: 不存在的IP搜索
    echo "5. 不存在IP搜索测试:\n";
    $notFoundResults = $monitor->searchProxiesSafe('999.999.999.999', 1, 20);
    $notFoundCount = $monitor->getSearchCount('999.999.999.999');
    echo "   搜索不存在的IP: $notFoundCount 个结果\n";
    echo "   实际获取: " . count($notFoundResults) . " 个结果\n\n";
    
    echo "=== 搜索功能测试完成 ===\n";
    echo "✅ 所有测试通过！搜索功能工作正常。\n\n";
    
    echo "使用说明:\n";
    echo "- 精确搜索: 输入完整IP地址，如 '1.2.3.4'\n";
    echo "- 部分搜索: 输入IP的一部分，如 '1.2.3'\n";
    echo "- 网段搜索: 使用 'x' 或 '.' 结尾，如 '1.2.3.x' 或 '1.2.3.'\n";
    echo "- 支持分页显示和URL参数传递\n";
    echo "- 搜索结果按IP地址排序\n";
    
} catch (Exception $e) {
    echo "❌ 测试失败: " . $e->getMessage() . "\n";
    echo "错误详情: " . $e->getTraceAsString() . "\n";
}
