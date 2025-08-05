<?php
/**
 * NetWatch Web 界面
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'database.php';
require_once 'monitor.php';

// 并行检测配置常量
define('PARALLEL_MAX_PROCESSES', 12);   // 最大并行进程数
define('PARALLEL_BATCH_SIZE', 400);     // 每批次代理数量

// 设置时区为中国标准时间
date_default_timezone_set('Asia/Shanghai');

/**
 * 验证是否为真正的AJAX请求
 * 防止移动端浏览器错误地将页面请求误认为AJAX请求
 */
function isValidAjaxRequest() {
    // 检查是否有XMLHttpRequest标头
    $isXmlHttpRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                       strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    // 检查Accept标头是否包含json
    $acceptsJson = isset($_SERVER['HTTP_ACCEPT']) && 
                   strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    
    // 检查Content-Type是否为json相关
    $contentTypeJson = isset($_SERVER['CONTENT_TYPE']) && 
                      strpos($_SERVER['CONTENT_TYPE'], 'json') !== false;
    
    // 检查Referer是否来自同一页面（防止直接访问）
    $hasValidReferer = isset($_SERVER['HTTP_REFERER']) && 
                      strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false;
    
    // 额外检查：防止移动端浏览器的特殊行为
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isMobileBrowser = preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent);
    
    // 对于移动端浏览器，需要更严格的检查
    if ($isMobileBrowser) {
        // 移动端必须有明确的AJAX标志才能通过
        return $isXmlHttpRequest && $hasValidReferer;
    }
    
    // 只有同时满足多个条件才认为是真正的AJAX请求
    return ($isXmlHttpRequest || $acceptsJson || $contentTypeJson) && $hasValidReferer;
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

// 检查登录状态
Auth::requireLogin();

$monitor = new NetworkMonitor();
$action = $_GET['action'] ?? 'dashboard';

// 处理登出请求
if ($action === 'logout') {
    Auth::logout();
    header('Location: login.php?action=logout');
    exit;
}

// 处理AJAX请求
// 添加更严格的AJAX请求检查，防止移动端浏览器错误处理URL参数
if (isset($_GET['ajax'])) {
    $isValidAjax = isValidAjaxRequest();
    
    // 如果有ajax参数但不是真正的AJAX请求，记录日志并重定向
    if (!$isValidAjax) {
        // 记录调试信息
        $debugInfo = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'none',
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? 'none',
            'x_requested_with' => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? 'none',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'action' => $action
        ];
        
        // 将调试信息写入日志文件
        file_put_contents('debug_ajax_mobile.log', json_encode($debugInfo) . "\n", FILE_APPEND | LOCK_EX);
        
        // 重定向到主页，清除ajax参数
        $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
        $params = $_GET;
        unset($params['ajax']);
        if (!empty($params)) {
            $redirectUrl .= '?' . http_build_query($params);
        }
        
        // 使用JavaScript重定向作为备用方案（防止header重定向失败）
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>重定向中...</title></head><body>';
        echo '<script>window.location.href="' . htmlspecialchars($redirectUrl) . '";</script>';
        echo '<p>正在重定向到正确页面...</p>';
        echo '<p><a href="' . htmlspecialchars($redirectUrl) . '">如果没有自动跳转，请点击这里</a></p>';
        echo '</body></html>';
        exit;
    }
    
    // 只有真正的AJAX请求才返回JSON
    if ($isValidAjax) {
        header('Content-Type: application/json');
        
        // 统一检查登录状态（除了sessionCheck操作）
        if ($action !== 'sessionCheck' && !Auth::isLoggedIn()) {
            echo json_encode([
                'success' => false,
                'error' => 'unauthorized',
                'message' => '登录已过期，请重新登录'
            ]);
            exit;
        }
        
        switch ($action) {
            case 'stats':
                echo json_encode($monitor->getStats());
                break;
            
            case 'check':
                $proxyId = $_GET['proxy_id'] ?? null;
                if ($proxyId) {
                    $proxy = $monitor->getProxyById($proxyId);
                    if ($proxy) {
                        $result = $monitor->checkProxy($proxy);
                        echo json_encode($result);
                    } else {
                        echo json_encode(['error' => '代理不存在']);
                    }
                } else {
                    echo json_encode(['error' => '缺少代理ID']);
                }
                break;
                
            case 'logs':
                $logs = $monitor->getRecentLogs(50);
                echo json_encode($logs);
                break;
            
            case 'checkAll':
                try {
                    $results = $monitor->checkAllProxies();
                    
                    // 检查是否有需要发送警报的代理
                    $failedProxies = $monitor->getFailedProxies();
                    $emailSent = false;
                    
                    if (!empty($failedProxies)) {
                        try {
                            // 初始化邮件发送器
                            if (file_exists('vendor/autoload.php')) {
                                require_once 'mailer.php';
                                $mailer = new Mailer();
                            } else {
                                require_once 'mailer_simple.php';
                                $mailer = new SimpleMailer();
                            }
                            
                            $mailer->sendProxyAlert($failedProxies);
                            $emailSent = true;
                            
                            // 记录警报
                            foreach ($failedProxies as $proxy) {
                                $monitor->addAlert(
                                    $proxy['id'],
                                    'proxy_failure',
                                    "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                                );
                            }
                        } catch (Exception $mailError) {
                            error_log('发送邮件失败: ' . $mailError->getMessage());
                        }
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'message' => '所有代理检查完成',
                        'results' => $results,
                        'failed_proxies' => count($failedProxies),
                        'email_sent' => $emailSent
                    ]);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'error' => '检查失败: ' . $e->getMessage()
                    ]);
                }
                break;
            
            case 'getProxyCount':
                try {
                // 记录开始时间
                $startTime = microtime(true);
                
                // 检查是否有缓存（缓存5分钟）
                $cacheFile = 'cache_proxy_count.txt';
                $cacheTime = 300; // 5分钟
                $useCache = false;
                
                if (file_exists($cacheFile)) {
                    $cacheData = file_get_contents($cacheFile);
                    $cacheInfo = json_decode($cacheData, true);
                    
                    if ($cacheInfo && (time() - $cacheInfo['timestamp']) < $cacheTime) {
                        $count = $cacheInfo['count'];
                        $useCache = true;
                    }
                }
                
                // 如果没有缓存或缓存过期，查询数据库
                if (!$useCache) {
                    $count = $monitor->getProxyCount();
                    
                    // 保存缓存
                    $cacheData = json_encode([
                        'count' => $count,
                        'timestamp' => time()
                    ]);
                    file_put_contents($cacheFile, $cacheData);
                }
                
                // 计算执行时间
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                
                echo json_encode([
                    'success' => true,
                    'count' => $count,
                    'cached' => $useCache,
                    'execution_time' => $executionTime
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '获取代理数量失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'checkBatch':
            try {
                // 设置更长的PHP执行时间限制
                set_time_limit(120); // 2分钟
                
                $offset = intval($_GET['offset'] ?? 0);
                $limit = intval($_GET['limit'] ?? 20);
                
                // 记录开始时间
                $startTime = microtime(true);
                
                $results = $monitor->checkProxyBatch($offset, $limit);
                
                // 计算执行时间
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                
                echo json_encode([
                    'success' => true,
                    'results' => $results,
                    'execution_time' => $executionTime,
                    'batch_info' => [
                        'offset' => $offset,
                        'limit' => $limit,
                        'actual_count' => count($results)
                    ]
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '批量检查失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'checkFailedProxies':
            try {
                // 检查是否有需要发送警报的代理
                $failedProxies = $monitor->getFailedProxies();
                $emailSent = false;
                
                if (!empty($failedProxies)) {
                    try {
                        // 初始化邮件发送器
                        if (file_exists('vendor/autoload.php')) {
                            require_once 'mailer.php';
                            $mailer = new Mailer();
                        } else {
                            require_once 'mailer_simple.php';
                            $mailer = new SimpleMailer();
                        }
                        
                        $mailer->sendProxyAlert($failedProxies);
                        $emailSent = true;
                        
                        // 记录警报
                        foreach ($failedProxies as $proxy) {
                            $monitor->addAlert(
                                $proxy['id'],
                                'proxy_failure',
                                "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                            );
                        }
                    } catch (Exception $mailError) {
                        error_log('发送邮件失败: ' . $mailError->getMessage());
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'failed_proxies' => count($failedProxies),
                    'email_sent' => $emailSent
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '检查失败代理失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'startParallelCheck':
            try {
                require_once 'parallel_monitor.php';
                // 创建并行监控器：使用配置常量
                $parallelMonitor = new ParallelMonitor(PARALLEL_MAX_PROCESSES, PARALLEL_BATCH_SIZE);
                
                // 启动并行检测（异步）
                $result = $parallelMonitor->startParallelCheck();
                
                echo json_encode($result);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '启动并行检测失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'getParallelProgress':
            try {
                require_once 'parallel_monitor.php';
                // 创建并行监控器：使用配置常量
                $parallelMonitor = new ParallelMonitor(PARALLEL_MAX_PROCESSES, PARALLEL_BATCH_SIZE);
                
                $progress = $parallelMonitor->getParallelProgress();
                echo json_encode($progress);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '获取进度失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'cancelParallelCheck':
            try {
                require_once 'parallel_monitor.php';
                // 创建并行监控器：使用配置常量
                $parallelMonitor = new ParallelMonitor(PARALLEL_MAX_PROCESSES, PARALLEL_BATCH_SIZE);
                
                $result = $parallelMonitor->cancelParallelCheck();
                echo json_encode($result);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '取消检测失败: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'sessionCheck':
            try {
                if (!Auth::isLoggedIn()) {
                    echo json_encode(['valid' => false]);
                } else {
                    echo json_encode(['valid' => true]);
                }
            } catch (Exception $e) {
                echo json_encode(['valid' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'debugStatuses':
            try {
                $db = new Database();
                $statuses = $db->getDistinctStatuses();
                echo json_encode([
                    'success' => true,
                    'statuses' => $statuses
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            break;
            
        case 'createTestData':
            try {
                $db = new Database();
                // 获取前5个代理的ID
                $proxies = $db->getProxiesBatch(0, 5);
                $updated = 0;
                
                foreach ($proxies as $index => $proxy) {
                    if ($index < 2) {
                        // 前2个设为离线
                        $db->updateProxyStatus($proxy['id'], 'offline', 0, '测试数据');
                        $updated++;
                    } elseif ($index < 4) {
                        // 中间2个设为未知
                        $db->updateProxyStatus($proxy['id'], 'unknown', 0, '测试数据');
                        $updated++;
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => "已创建测试数据：2个离线代理和2个未知代理",
                    'updated' => $updated
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            break;
            
        case 'search':
            try {
                $searchTerm = $_GET['term'] ?? '';
                $statusFilter = $_GET['status'] ?? '';
                $page = max(1, intval($_GET['page'] ?? 1));
                $perPage = 200;
                
                // 直接使用数据库对象实现搜索和筛选
                $db = new Database();
                $proxies = $db->searchProxies($searchTerm, $page, $perPage, $statusFilter);
                // 过滤敏感信息
                $proxies = array_map(function($proxy) {
                    unset($proxy['username']);
                    unset($proxy['password']);
                    return $proxy;
                }, $proxies);
                $totalCount = $db->getSearchCount($searchTerm, $statusFilter);
                $totalPages = ceil($totalCount / $perPage);
                
                echo json_encode([
                    'success' => true,
                    'proxies' => $proxies,
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'search_term' => $searchTerm,
                    'status_filter' => $statusFilter
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => '搜索失败: ' . $e->getMessage()
                ]);
            }
            break;
            
            default:
                echo json_encode(['error' => '未知操作']);
        }
        exit;
    }
}

// 获取分页参数、搜索参数和状态筛选参数
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 200;
$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// 获取数据
$stats = $monitor->getStats();

if (!empty($searchTerm) || !empty($statusFilter)) {
    // 搜索或筛选模式 - 直接使用数据库对象实现筛选
    $db = new Database();
    $proxies = $db->searchProxies($searchTerm, $page, $perPage, $statusFilter);
    // 过滤敏感信息
    $proxies = array_map(function($proxy) {
        unset($proxy['username']);
        unset($proxy['password']);
        return $proxy;
    }, $proxies);
    $totalProxies = $db->getSearchCount($searchTerm, $statusFilter);
    $totalPages = ceil($totalProxies / $perPage);
} else {
    // 正常分页模式
    $totalProxies = $monitor->getProxyCount();
    $totalPages = ceil($totalProxies / $perPage);
    $proxies = $monitor->getProxiesPaginatedSafe($page, $perPage);
}

$recentLogs = $monitor->getRecentLogs(20);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch - 网络监控系统</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            color: #333;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left {
            flex: 1;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            font-size: 13px;
        }
        
        .user-row {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .username {
            font-weight: bold;
        }
        
        .session-time {
            opacity: 0.8;
            margin-top: 5px;
        }
        
        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 12px;
            transition: background 0.3s ease;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .stat-card {
            background: white;
            padding: 10px 5px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .online { color: #4CAF50; }
        .offline { color: #f44336; }
        .unknown { color: #ff9800; }
        .total { color: #2196F3; }
        
        .section {
            background: white;
            margin: 20px 0;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            padding-bottom: 20px;
        }
        
        .section-header {
            background: #f8f9fa;
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #5a6fd8;
        }
        
        .btn-parallel {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-parallel:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-parallel:active {
            transform: translateY(0);
        }
        
        .btn-parallel::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn-parallel:hover::before {
            left: 100%;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        /* 搜索功能样式 */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        
        /* 状态筛选样式 */
        .status-filter-container {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        
        .filter-label {
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }
        
        .filter-btn {
            background: #f8f9fa;
            color: #495057;
            border: 1px solid #dee2e6;
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }
        
        .filter-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        
        .filter-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .filter-btn.active:hover {
            background: #5a6fd8;
            border-color: #5a6fd8;
        }
        
        .search-container {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            flex: 1;
            min-width: 300px;
        }
        
        .controls-row {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
        }
        
        #search-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            width: 300px;
            min-width: 200px;
        }
        
        #search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }
        
        .search-btn {
            background: #28a745;
        }
        
        .search-btn:hover {
            background: #218838;
        }
        
        .clear-btn {
            background: #6c757d;
        }
        
        .clear-btn:hover {
            background: #5a6268;
        }
        
        .search-info {
            background: #e3f2fd;
            padding: 10px 20px;
            border-bottom: 1px solid #e9ecef;
            color: #1976d2;
            font-size: 14px;
        }
        
        .search-results {
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .header-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-container {
                justify-content: center;
            }
            
            #search-input {
                width: 100%;
                max-width: 300px;
            }
        }
        
        .table-container {
            overflow-x: auto;
            margin-top: 15px;
        }
        
        /* Table styles */
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto; /* Changed from fixed to auto for better column width handling */
        }
        
        #proxies-table th,
        #proxies-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
            word-break: break-word; /* Ensure long content wraps properly */
        }
        
        /* Set specific widths for columns */
        #proxies-table th:first-child,
        #proxies-table td:first-child {
            width: 60px;
        }
        
        #proxies-table th:nth-child(2),
        #proxies-table td:nth-child(2) {
            width: 150px;
            text-align: center;
        }
        
        #proxies-table th:nth-child(3),
        #proxies-table td:nth-child(3) {
            width: 100px;
            text-align: center;
        }
        
        #proxies-table th:nth-child(4),
        #proxies-table td:nth-child(4) {
            width: 120px;
            text-align: center;
        }
        
        #proxies-table th:nth-child(5),
        #proxies-table td:nth-child(5) {
            width: 120px;
            text-align: center;
        }
        
        #proxies-table th:nth-child(6),
        #proxies-table td:nth-child(6) {
            width: 100px;
            text-align: center;
        }
        
        #proxies-table th:nth-child(7),
        #proxies-table td:nth-child(7) {
            width: 150px;
            text-align: center;
        }
        
        #proxies-table th:nth-child(8),
        #proxies-table td:nth-child(8) {
            width: 100px; /* Actions column */
            text-align: center;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .status-online {
            background: #d4edda;
            color: #155724;
        }
        
        .status-offline {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-unknown {
            background: #fff3cd;
            color: #856404;
        }
        
        .refresh-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #667eea;
            color: white;
            border: none;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            transition: all 0.2s;
        }
        
        .log-entry {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        
        .log-entry:last-child {
            border-bottom: none;
        }
        
        .log-time {
            color: #666;
            margin-right: 10px;
        }
        
        .log-status {
            margin-right: 10px;
            font-weight: bold;
        }
        
        .log-online { color: #4CAF50; }
        .log-offline { color: #f44336; }
        
        /* 分页样式 */
        .pagination-container {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .pagination-info {
            color: #666;
            font-size: 14px;
        }
        
        .pagination {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .page-btn {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            color: #333;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .page-btn:hover {
            background: #f8f9fa;
            border-color: #667eea;
            color: #667eea;
        }
        
        .page-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .container {
                padding: 0 8px;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 6px;
            }
            
            /* 移动端隐藏除地址、状态、操作外的其他列 */
            #proxies-table th:nth-child(3),
            #proxies-table td:nth-child(3),  /* 类型 */
            #proxies-table th:nth-child(5),
            #proxies-table td:nth-child(5),  /* 响应时间 */
            #proxies-table th:nth-child(6),
            #proxies-table td:nth-child(6),  /* 失败次数 */
            #proxies-table th:nth-child(7),
            #proxies-table td:nth-child(7) { /* 最后检查 */
                display: none;
            }
            
            /* 调整剩余列的宽度 */
            #proxies-table th:nth-child(1),
            #proxies-table td:nth-child(1) { /* ID */
                width: 20%;
            }
            #proxies-table th:nth-child(2),
            #proxies-table td:nth-child(2) { /* 地址 */
                width: 40%;
            }
            
            #proxies-table th:nth-child(4),
            #proxies-table td:nth-child(4) { /* 状态 */
                width: 30%;
                text-align: center;
            }
            
            #proxies-table th:nth-child(8),
            #proxies-table td:nth-child(8) { /* 操作 */
                width: 10%;
                text-align: center;
            }
            
            /* 移动端按钮优化 */
            .btn-small {
                padding: 2px 6px;
                font-size: 10px;
                white-space: nowrap;
                min-width: 32px;
            }
            
            /* 移动端头部操作区域优化 */
            .header-actions {
                display: flex;
                flex-direction: column;
                gap: 10px;
                align-items: stretch;
            }
            
            .controls-row {
                flex-direction: column;
                gap: 10px;
                align-items: stretch;
            }
            
            /* 移动端状态筛选优化 */
            .status-filter-container {
                justify-content: center;
                gap: 5px;
            }
            
            .filter-label {
                font-size: 12px;
            }
            
            .filter-btn {
                padding: 4px 8px;
                font-size: 11px;
                min-width: 40px;
            }
            
            .search-container {
                width: 100%;
                justify-content: center;
            }
            
            #search-input {
                width: 60%;
                min-width: 120px;
                font-size: 12px;
                padding: 6px 8px;
            }
            
            /* 移动端按钮容器 - 水平布局 */
            .action-buttons {
                display: flex;
                gap: 16px;
                justify-content: center;
            }
            
            .action-buttons .btn {
                padding: 6px 12px;
                font-size: 12px;
                text-align: center;
                white-space: nowrap;
                min-width: 80px;
                flex: none;
            }
            
            .search-btn, .clear-btn {
                padding: 6px 10px;
                font-size: 12px;
            }
            
            .pagination-container {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .pagination {
                flex-wrap: wrap;
                justify-content: center;
                width: 100%;
            }
            
            .page-btn {
                padding: 6px 10px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>🌐 NetWatch</h1>
                    <p>网络代理监控系统</p>
                </div>
                <?php if (Auth::isLoginEnabled()): ?>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-row">
                            <div class="username">👤 <?php echo htmlspecialchars(Auth::getCurrentUser()); ?></div>
                            <a href="?action=logout" class="logout-btn" onclick="return confirm('确定要退出登录吗？')">退出</a>
                        </div>
                        <div class="session-time">登录时间：<?php 
                            $loginTime = Auth::getLoginTime();
                            echo $loginTime ? date('m-d H:i', $loginTime) : 'N/A';
                        ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- 统计信息 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number total"><?php echo $stats['total']; ?></div>
                <div class="stat-label">总代理数</div>
            </div>
            <div class="stat-card">
                <div class="stat-number online"><?php echo $stats['online']; ?></div>
                <div class="stat-label">在线数量</div>
            </div>
            <div class="stat-card">
                <div class="stat-number offline"><?php echo $stats['offline']; ?></div>
                <div class="stat-label">离线数量</div>
            </div>
            <div class="stat-card">
                <div class="stat-number unknown"><?php echo $stats['unknown']; ?></div>
                <div class="stat-label">未知</div>
            </div>
            <div class="stat-card">
                <div class="stat-number total"><?php echo number_format($stats['avg_response_time'], 0); ?>ms</div>
                <div class="stat-label">平均响应时间</div>
            </div>
        </div>
        
        <!-- 代理列表 -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">代理服务器列表</h2>
                <div class="header-actions">
                    <div class="search-container">
                        <input type="text" id="search-input" placeholder="搜索IP地址或网段（如: 1.2.3.4 或 1.2.3.x）" value="<?php echo htmlspecialchars($searchTerm); ?>">
                        <button class="btn search-btn" onclick="performSearch()">搜索</button>
                        <?php if (!empty($searchTerm) || !empty($statusFilter)): ?>
                        <button class="btn clear-btn" onclick="clearSearch()">清除</button>
                        <?php endif; ?>
                    </div>
                    <div class="controls-row">
                        <div class="status-filter-container">
                            <span class="filter-label">状态：</span>
                            <button class="btn filter-btn <?php echo empty($statusFilter) ? 'active' : ''; ?>" onclick="filterByStatus('')">全部</button>
                            <button class="btn filter-btn <?php echo $statusFilter === 'online' ? 'active' : ''; ?>" onclick="filterByStatus('online')">在线</button>
                            <button class="btn filter-btn <?php echo $statusFilter === 'offline' ? 'active' : ''; ?>" onclick="filterByStatus('offline')">离线</button>
                            <button class="btn filter-btn <?php echo $statusFilter === 'unknown' ? 'active' : ''; ?>" onclick="filterByStatus('unknown')">未知</button>
                        </div>
                        <div class="action-buttons">
                            <button class="btn" onclick="checkAllProxies()">🔍 逐个检测</button>
                            <button class="btn btn-parallel" onclick="checkAllProxiesParallel()" title="使用并行检测，速度更快！每400个IP一组并行执行">🚀 并行检测</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($searchTerm) || !empty($statusFilter)): ?>
            <div class="search-info">
                <span class="search-results">
                    <?php if (!empty($searchTerm) && !empty($statusFilter)): ?>
                        搜索 "<?php echo htmlspecialchars($searchTerm); ?>" 并筛选 "<?php echo $statusFilter; ?>" 状态，找到 <?php echo $totalProxies; ?> 个结果
                    <?php elseif (!empty($searchTerm)): ?>
                        搜索 "<?php echo htmlspecialchars($searchTerm); ?>" 找到 <?php echo $totalProxies; ?> 个结果
                    <?php elseif (!empty($statusFilter)): ?>
                        筛选 "<?php echo $statusFilter; ?>" 状态，找到 <?php echo $totalProxies; ?> 个结果
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="table-container">
                <table id="proxies-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>地址</th>
                            <th>类型</th>
                            <th>状态</th>
                            <th>响应时间</th>
                            <th>失败次数</th>
                            <th>最后检查</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proxies as $proxy): ?>
                        <tr>
                            <td><?php echo $proxy['id']; ?></td>
                            <td><?php echo htmlspecialchars($proxy['ip']); ?></td>
                            <td><?php echo strtoupper($proxy['type']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $proxy['status']; ?>">
                                    <?php echo $proxy['status']; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($proxy['response_time'], 2); ?>ms</td>
                            <td><?php echo $proxy['failure_count']; ?></td>
                            <td><?php echo formatTime($proxy['last_check'], 'm-d H:i'); // 自动从UTC转换为北京时间 ?></td>
                            <td>
                                <button class="btn btn-small" onclick="checkProxy(<?php echo $proxy['id']; ?>)">检查</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- 分页导航 -->
            <?php if ($totalPages > 1): ?>
            <?php 
            // 构建分页URL参数
            $urlParams = [];
            if (!empty($searchTerm)) {
                $urlParams[] = 'search=' . urlencode($searchTerm);
            }
            if (!empty($statusFilter)) {
                $urlParams[] = 'status=' . urlencode($statusFilter);
            }
            $searchParam = !empty($urlParams) ? '&' . implode('&', $urlParams) : '';
            ?>
            <div class="pagination-container" style="padding: 0 20px;">
                <div class="pagination-info">
                    显示第 <?php echo (($page - 1) * $perPage + 1); ?> - <?php echo min($page * $perPage, $totalProxies); ?> 条，共 <?php echo $totalProxies; ?> 条
                    <?php 
                    if (!empty($searchTerm) && !empty($statusFilter)) {
                        echo '搜索和筛选结果';
                    } elseif (!empty($searchTerm)) {
                        echo '搜索结果';
                    } elseif (!empty($statusFilter)) {
                        echo '筛选结果';
                    } else {
                        echo '代理';
                    }
                    ?>
                </div>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1<?php echo $searchParam; ?>" class="page-btn">首页</a>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $searchParam; ?>" class="page-btn">上一页</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?><?php echo $searchParam; ?>" class="page-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $searchParam; ?>" class="page-btn">下一页</a>
                        <a href="?page=<?php echo $totalPages; ?><?php echo $searchParam; ?>" class="page-btn">末页</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- 最近日志 -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">最近检查日志</h2>
                <button class="btn" onclick="refreshLogs()">刷新日志</button>
            </div>
            <div id="logs-container">
                <?php foreach ($recentLogs as $log): ?>
                <div class="log-entry">
                    <span class="log-time"><?php echo formatTime($log['checked_at'], 'm-d H:i:s'); ?></span>
                    <span class="log-status log-<?php echo $log['status']; ?>"><?php echo strtoupper($log['status']); ?></span>
                    <span><?php echo htmlspecialchars($log['ip'] . ':' . $log['port']); ?></span>
                    <span>(<?php echo number_format($log['response_time'], 2); ?>ms)</span>
                    <?php if ($log['error_message']): ?>
                    <span style="color: #f44336;"> - <?php echo htmlspecialchars($log['error_message']); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <button class="refresh-btn" onclick="refreshAll()" title="刷新所有数据">
        🔄
    </button>
    
    <script>
        // 自动刷新
        setInterval(refreshStats, 30000); // 30秒刷新统计
        setInterval(refreshLogs, 60000);  // 60秒刷新日志
        
        function refreshStats() {
            fetch('?ajax=1&action=stats')
                .then(response => response.json())
                .then(data => {
                    document.querySelector('.stats-grid .stat-card:nth-child(1) .stat-number').textContent = data.total;
                    document.querySelector('.stats-grid .stat-card:nth-child(2) .stat-number').textContent = data.online;
                    document.querySelector('.stats-grid .stat-card:nth-child(3) .stat-number').textContent = data.offline;
                    document.querySelector('.stats-grid .stat-card:nth-child(4) .stat-number').textContent = data.unknown;
                    document.querySelector('.stats-grid .stat-card:nth-child(5) .stat-number').textContent = Math.round(data.avg_response_time) + 'ms';
                })
                .catch(error => console.error('刷新统计失败:', error));
        }
        
        function refreshLogs() {
            fetch('?ajax=1&action=logs')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('logs-container');
                    container.innerHTML = '';
                    
                    data.forEach(log => {
                        const div = document.createElement('div');
                        div.className = 'log-entry';
                        
                        const time = new Date(log.checked_at).toLocaleString('zh-CN', {
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit'
                        });
                        
                        let html = `
                            <span class="log-time">${time}</span>
                            <span class="log-status log-${log.status}">${log.status.toUpperCase()}</span>
                            <span>${log.ip}:${log.port}</span>
                            <span>(${parseFloat(log.response_time).toFixed(2)}ms)</span>
                        `;
                        
                        if (log.error_message) {
                            html += `<span style="color: #f44336;"> - ${log.error_message}</span>`;
                        }
                        
                        div.innerHTML = html;
                        container.appendChild(div);
                    });
                })
                .catch(error => console.error('刷新日志失败:', error));
        }
        
        function checkProxy(proxyId) {
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = '检查中...';
            btn.disabled = true;
            
            fetch(`?ajax=1&action=check&proxy_id=${proxyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('检查失败: ' + data.error);
                    } else {
                        // 刷新页面以显示最新状态
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('检查代理失败:', error);
                    alert('检查失败，请稍后重试');
                })
                .finally(() => {
                    btn.textContent = originalText;
                    btn.disabled = false;
                });
        }
        
        async function checkAllProxies() {
            if (confirm('确定要检查所有代理吗？这可能需要一些时间。')) {
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '检查中...';
                btn.disabled = true;
                
                // 创建进度显示界面
                const progressDiv = document.createElement('div');
                progressDiv.id = 'check-progress';
                progressDiv.style.cssText = `
                    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                    background: white; padding: 30px; border-radius: 15px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.3); z-index: 1000;
                    text-align: center; min-width: 400px; max-width: 500px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                `;
                
                progressDiv.innerHTML = `
                    <h3 style="margin: 0 0 20px 0; color: #333;">🔍 正在检查所有代理</h3>
                    <div id="progress-info" style="margin-bottom: 20px; color: #666;">正在获取代理列表...</div>
                    <div style="background: #f0f0f0; border-radius: 10px; height: 20px; margin: 20px 0; overflow: hidden;">
                        <div id="progress-bar" style="background: linear-gradient(90deg, #4CAF50, #45a049); height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 10px;"></div>
                    </div>
                    <div id="progress-stats" style="font-size: 14px; color: #888;">准备开始...</div>
                    <button id="cancel-check" style="margin-top: 15px; padding: 8px 16px; background: #f44336; color: white; border: none; border-radius: 5px; cursor: pointer;">取消检查</button>
                `;
                
                document.body.appendChild(progressDiv);
                
                let cancelled = false;
                document.getElementById('cancel-check').onclick = () => {
                    cancelled = true;
                    document.body.removeChild(progressDiv);
                    btn.textContent = originalText;
                    btn.disabled = false;
                };
                
                try {
                    // 更新状态为正在准备
                    document.getElementById('progress-info').textContent = '正在连接数据库...';
                    
                    // 记录开始时间
                    const prepareStartTime = Date.now();
                    
                    // 首先尝试使用缓存的代理数量
                    let totalProxies = getCachedProxyCount();
                    let countData = null;
                    
                    if (totalProxies !== null) {
                        // 使用缓存数据
                        document.getElementById('progress-info').textContent = `使用缓存数据: ${totalProxies} 个代理`;
                        countData = { cached: true, execution_time: 0 };
                    } else {
                        // 缓存无效，重新查询
                        document.getElementById('progress-info').textContent = '正在获取代理数量...';
                        const countResponse = await fetch('?ajax=1&action=getProxyCount');
                        countData = await countResponse.json();
                        
                        if (!countData.success) {
                            throw new Error(countData.error || '获取代理数量失败');
                        }
                        
                        totalProxies = countData.count;
                        
                        // 更新缓存
                        cachedProxyCount = totalProxies;
                        cacheTimestamp = Date.now();
                    }
                    if (totalProxies === 0) {
                        alert('没有找到代理数据，请先导入代理。');
                        document.body.removeChild(progressDiv);
                        btn.textContent = originalText;
                        btn.disabled = false;
                        return;
                    }
                    
                    // 计算准备时间
                    const prepareTime = Date.now() - prepareStartTime;
                    
                    // 显示缓存状态和执行时间
                    const cacheStatus = countData.cached ? '缓存' : '数据库';
                    const queryTime = countData.execution_time || 0;
                    
                    // 更新进度信息，显示详细信息
                    document.getElementById('progress-info').textContent = `找到 ${totalProxies} 个代理 (查询: ${queryTime}ms ${cacheStatus}, 总用时: ${prepareTime}ms)，开始检查...`;
                    
                    // 如果准备时间较长，显示更长时间让用户看到
                    const displayTime = prepareTime > 1000 ? 1500 : 500;
                    await new Promise(resolve => setTimeout(resolve, displayTime));
                    
                    // 分批检查代理
                    const batchSize = 20; // 每批检查20个代理
                    let checkedCount = 0;
                    let onlineCount = 0;
                    let offlineCount = 0;
                    
                    for (let offset = 0; offset < totalProxies && !cancelled; offset += batchSize) {
                        try {
                            // 设置超时时间为2分钟
                            const controller = new AbortController();
                            const timeoutId = setTimeout(() => controller.abort(), 120000);
                            
                            const batchResponse = await fetch(`?ajax=1&action=checkBatch&offset=${offset}&limit=${batchSize}`, {
                                signal: controller.signal
                            });
                            
                            clearTimeout(timeoutId);
                            
                            if (!batchResponse.ok) {
                                throw new Error(`HTTP ${batchResponse.status}: ${batchResponse.statusText}`);
                            }
                            
                            const batchData = await batchResponse.json();
                            
                            if (!batchData.success) {
                                throw new Error(batchData.error || '批量检查失败');
                            }
                            
                            // 更新统计
                            checkedCount += batchData.results.length;
                            onlineCount += batchData.results.filter(r => r.status === 'online').length;
                            offlineCount += batchData.results.filter(r => r.status === 'offline').length;
                            
                            // 更新进度条
                            const progress = (checkedCount / totalProxies) * 100;
                            document.getElementById('progress-bar').style.width = progress + '%';
                            
                            // 更新进度信息，显示执行时间
                            const executionTime = batchData.execution_time ? ` (用时: ${batchData.execution_time}ms)` : '';
                            document.getElementById('progress-info').textContent = 
                                `正在检查第 ${Math.min(offset + batchSize, totalProxies)} / ${totalProxies} 个代理${executionTime}...`;
                            
                            // 更新统计信息
                            document.getElementById('progress-stats').textContent = 
                                `已检查: ${checkedCount} | 在线: ${onlineCount} | 离线: ${offlineCount}`;
                            
                            // 减少延迟时间，提高整体速度
                            await new Promise(resolve => setTimeout(resolve, 100));
                            
                        } catch (error) {
                            if (error.name === 'AbortError') {
                                throw new Error(`第 ${offset + 1}-${Math.min(offset + batchSize, totalProxies)} 个代理检查超时，请检查网络连接或减少批量大小`);
                            }
                            throw error;
                        }
                    }
                    
                    if (!cancelled) {
                        // 检查是否有失败的代理需要发送邮件
                        try {
                            const alertResponse = await fetch('?ajax=1&action=checkFailedProxies');
                            const alertData = await alertResponse.json();
                            
                            let alertMessage = '';
                            if (alertData.success && alertData.failed_proxies > 0) {
                                alertMessage = alertData.email_sent ? 
                                    `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理，已发送邮件通知！` :
                                    `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理。`;
                            }
                            
                            document.body.removeChild(progressDiv);
                            
                            alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个${alertMessage}\n\n页面将自动刷新显示最新状态`);
                            
                        } catch (alertError) {
                            document.body.removeChild(progressDiv);
                            alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个\n\n页面将自动刷新显示最新状态`);
                        }
                        
                        // 刷新页面显示最新状态
                        location.reload();
                    }
                    
                } catch (error) {
                    if (!cancelled) {
                        document.body.removeChild(progressDiv);
                        console.error('检查所有代理失败:', error);
                        alert('❌ 检查失败: ' + error.message);
                    }
                } finally {
                    if (!cancelled) {
                        btn.textContent = originalText;
                        btn.disabled = false;
                    }
                }
            }
        }
        
        function refreshAll() {
            const btn = document.querySelector('.refresh-btn');
            btn.style.transform = 'rotate(360deg)';
            
            refreshStats();
            refreshLogs();
            
            // 在分页模式下刷新当前页面
            setTimeout(() => {
                location.reload();
            }, 1000);
        }
        
        // 搜索功能
        function performSearch() {
            const searchInput = document.getElementById('search-input');
            const searchTerm = searchInput.value.trim();
            
            if (searchTerm) {
                // 跳转到搜索结果页面
                window.location.href = '?search=' + encodeURIComponent(searchTerm);
            } else {
                // 如果搜索词为空，清除搜索
                clearSearch();
            }
        }
        
        function clearSearch() {
            // 清除搜索，返回主页面
            window.location.href = '?';
        }
        
        // 检查所有代理函数
        async function checkAllProxies() {
            const btn = event.target;
            const originalText = btn.textContent;
            
            if (btn.disabled) return;
            
            btn.disabled = true;
            btn.textContent = '正在准备...';
            
            // 创建背景遮罩层
            const overlay = document.createElement('div');
            overlay.id = 'check-overlay';
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.6); z-index: 999;
                backdrop-filter: blur(3px);
            `;
            document.body.appendChild(overlay);
            
            // 创建进度显示界面
            const progressDiv = document.createElement('div');
            progressDiv.id = 'check-progress';
            progressDiv.style.cssText = `
                position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                background: white; padding: 40px; border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.5); z-index: 1000;
                text-align: center; min-width: 300px; max-width: 800px; width: 90vw;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                border: 1px solid #e0e0e0;
                max-height: 90vh; overflow-y: auto;
            `;
            
            // 移动端适配
            if (window.innerWidth <= 768) {
                progressDiv.style.cssText = `
                    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                    background: white; padding: 20px; border-radius: 15px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 1000;
                    text-align: center; width: 95vw; max-width: 400px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    border: 1px solid #e0e0e0;
                    max-height: 90vh; overflow-y: auto;
                `;
            }
            
            // 移动端适配的HTML内容
            const isMobile = window.innerWidth <= 768;
            const titleSize = isMobile ? '20px' : '24px';
            const textSize = isMobile ? '14px' : '16px';
            const buttonPadding = isMobile ? '8px 16px' : '12px 24px';
            const buttonSize = isMobile ? '14px' : '16px';
            const progressHeight = isMobile ? '25px' : '30px';
            const margin = isMobile ? '15px' : '30px';
            
            progressDiv.innerHTML = `
                <h3 style="margin: 0 0 ${margin} 0; color: #333; font-size: ${titleSize}; font-weight: 600;">🔍 正在检查所有代理</h3>
                <div id="progress-info" style="margin-bottom: 20px; color: #666; font-size: ${textSize}; line-height: 1.5;">正在连接数据库...</div>
                <div style="background: #f5f5f5; border-radius: 15px; height: ${progressHeight}; margin: 20px 0; overflow: hidden; border: 1px solid #e0e0e0;">
                    <div id="progress-bar" style="background: linear-gradient(90deg, #4CAF50, #45a049); height: 100%; width: 0%; transition: width 0.5s ease; border-radius: 15px; position: relative;">
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-weight: 600; font-size: 12px; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);" id="progress-percent">0%</div>
                    </div>
                </div>
                <div id="progress-stats" style="font-size: ${textSize}; color: #555; margin-bottom: 20px; padding: ${isMobile ? '10px' : '15px'}; background: #f8f9fa; border-radius: 10px; border: 1px solid #e0e0e0; word-break: break-word;">准备开始...</div>
                <div style="display: flex; justify-content: center; gap: ${isMobile ? '10px' : '15px'}; margin-top: 15px;">
                    <button id="cancel-check" style="padding: ${buttonPadding}; background: #f44336; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: ${buttonSize}; font-weight: 500; transition: background 0.3s ease;" onmouseover="this.style.background='#d32f2f'" onmouseout="this.style.background='#f44336'">取消检查</button>
                </div>
            `;
            
            document.body.appendChild(progressDiv);
            
            let cancelled = false;
            document.getElementById('cancel-check').onclick = () => {
                cancelled = true;
                document.body.removeChild(progressDiv);
                document.body.removeChild(overlay);
                btn.textContent = originalText;
                btn.disabled = false;
            };
            
            try {
                // 更新状态为正在准备
                document.getElementById('progress-info').textContent = '正在连接数据库...';
                
                // 记录开始时间
                const prepareStartTime = Date.now();
                
                // 首先尝试使用缓存的代理数量
                let totalProxies = getCachedProxyCount();
                let countData = null;
                
                if (totalProxies !== null) {
                    // 使用缓存数据
                    document.getElementById('progress-info').textContent = `使用缓存数据: ${totalProxies} 个代理`;
                    countData = { cached: true, execution_time: 0 };
                } else {
                    // 缓存无效，重新查询
                    document.getElementById('progress-info').textContent = '正在获取代理数量...';
                    const countResponse = await fetch('?ajax=1&action=getProxyCount');
                    countData = await countResponse.json();
                    
                    if (!countData.success) {
                        throw new Error(countData.error || '获取代理数量失败');
                    }
                    
                    totalProxies = countData.count;
                    
                    // 更新缓存
                    cachedProxyCount = totalProxies;
                    cacheTimestamp = Date.now();
                }
                
                if (totalProxies === 0) {
                    alert('没有找到代理数据，请先导入代理。');
                    document.body.removeChild(progressDiv);
                    document.body.removeChild(overlay);
                    btn.textContent = originalText;
                    btn.disabled = false;
                    return;
                }
                
                // 计算准备时间
                const prepareTime = Date.now() - prepareStartTime;
                
                // 显示缓存状态和执行时间
                const cacheStatus = countData.cached ? '缓存' : '数据库';
                const queryTime = countData.execution_time || 0;
                
                // 更新进度信息，显示详细信息
                document.getElementById('progress-info').textContent = `找到 ${totalProxies} 个代理 (查询: ${queryTime}ms ${cacheStatus}, 总用时: ${prepareTime}ms)，开始检查...`;
                
                // 如果准备时间较长，显示更长时间让用户看到
                const displayTime = prepareTime > 1000 ? 1500 : 500;
                await new Promise(resolve => setTimeout(resolve, displayTime));
                
                // 分批检查代理
                const batchSize = 20; // 每批检查20个代理
                let checkedCount = 0;
                let onlineCount = 0;
                let offlineCount = 0;
                
                for (let offset = 0; offset < totalProxies && !cancelled; offset += batchSize) {
                    try {
                        // 设置超时时间为2分钟
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 120000);
                        
                        const batchResponse = await fetch(`?ajax=1&action=checkBatch&offset=${offset}&limit=${batchSize}`, {
                            signal: controller.signal
                        });
                        
                        clearTimeout(timeoutId);
                        
                        if (!batchResponse.ok) {
                            throw new Error(`HTTP ${batchResponse.status}: ${batchResponse.statusText}`);
                        }
                        
                        const batchData = await batchResponse.json();
                        
                        // 检查是否是登录过期
                        if (!batchData.success && batchData.error === 'unauthorized') {
                            document.body.removeChild(progressDiv);
                            document.body.removeChild(overlay);
                            alert('登录已过期，请重新登录');
                            window.location.href = 'login.php';
                            return;
                        }
                        
                        if (!batchData.success) {
                            throw new Error(batchData.error || '批量检查失败');
                        }
                        
                        // 更新统计
                        checkedCount += batchData.results.length;
                        onlineCount += batchData.results.filter(r => r.status === 'online').length;
                        offlineCount += batchData.results.filter(r => r.status === 'offline').length;
                        
                        // 更新进度条
                        const progress = (checkedCount / totalProxies) * 100;
                        document.getElementById('progress-bar').style.width = progress + '%';
                        document.getElementById('progress-percent').textContent = Math.round(progress) + '%';
                        
                        // 更新进度信息，显示执行时间
                        const executionTime = batchData.execution_time ? ` (用时: ${batchData.execution_time}ms)` : '';
                        document.getElementById('progress-info').textContent = 
                            `正在检查第 ${Math.min(offset + batchSize, totalProxies)} / ${totalProxies} 个代理${executionTime}...`;
                        
                        // 更新统计信息
                        document.getElementById('progress-stats').textContent = 
                            `已检查: ${checkedCount} | 在线: ${onlineCount} | 离线: ${offlineCount}`;
                        
                        // 减少延迟时间，提高整体速度
                        await new Promise(resolve => setTimeout(resolve, 100));
                        
                    } catch (error) {
                        if (error.name === 'AbortError') {
                            throw new Error(`第 ${offset + 1}-${Math.min(offset + batchSize, totalProxies)} 个代理检查超时，请检查网络连接或减少批量大小`);
                        }
                        throw error;
                    }
                }
                
                if (!cancelled) {
                    // 检查是否有失败的代理需要发送邮件
                    try {
                        const alertResponse = await fetch('?ajax=1&action=checkFailedProxies');
                        const alertData = await alertResponse.json();
                        
                        let alertMessage = '';
                        if (alertData.success && alertData.failed_proxies > 0) {
                            alertMessage = alertData.email_sent ? 
                                `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理，已发送邮件通知！` :
                                `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理。`;
                        }
                        
                        document.body.removeChild(progressDiv);
                        document.body.removeChild(overlay);
                        
                        alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个${alertMessage}\n\n页面将自动刷新显示最新状态`);
                        
                    } catch (alertError) {
                        document.body.removeChild(progressDiv);
                        document.body.removeChild(overlay);
                        alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个\n\n页面将自动刷新显示最新状态`);
                    }
                    
                    // 刷新页面显示最新状态
                    location.reload();
                }
                
            } catch (error) {
                if (!cancelled) {
                    document.body.removeChild(progressDiv);
                    document.body.removeChild(overlay);
                    console.error('检查所有代理失败:', error);
                    alert('❌ 检查失败: ' + error.message);
                }
            } finally {
                if (!cancelled) {
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            }
        }
        
        /**
         * 并行检测所有代理（高性能版本）
         */
        async function checkAllProxiesParallel() {
            const btn = event.target;
            const originalText = btn.textContent;
            
            if (btn.disabled) return;
            
            btn.disabled = true;
            btn.textContent = '正在启动并行检测...';
            
            // 创建背景遮罩层
            const overlay = document.createElement('div');
            overlay.id = 'parallel-check-overlay';
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.7); z-index: 999;
                backdrop-filter: blur(5px);
            `;
            document.body.appendChild(overlay);
            
            // 创建进度显示界面
            const progressDiv = document.createElement('div');
            progressDiv.id = 'parallel-check-progress';
            progressDiv.style.cssText = `
                position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                background: white; padding: 50px; border-radius: 25px;
                box-shadow: 0 25px 80px rgba(0,0,0,0.6); z-index: 1000;
                text-align: center; min-width: 300px; max-width: 900px; width: 90vw;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                border: 2px solid #4CAF50;
                max-height: 90vh; overflow-y: auto;
            `;
            
            // 移动端适配
            if (window.innerWidth <= 768) {
                progressDiv.style.cssText = `
                    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                    background: white; padding: 25px; border-radius: 20px;
                    box-shadow: 0 15px 40px rgba(0,0,0,0.6); z-index: 1000;
                    text-align: center; width: 95vw; max-width: 420px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    border: 2px solid #4CAF50;
                    max-height: 90vh; overflow-y: auto;
                `;
            }
            
            // 移动端适配的HTML内容
            const isMobile = window.innerWidth <= 768;
            const titleSize = isMobile ? '22px' : '28px';
            const textSize = isMobile ? '14px' : '18px';
            const smallTextSize = isMobile ? '13px' : '16px';
            const buttonPadding = isMobile ? '10px 20px' : '15px 30px';
            const buttonSize = isMobile ? '16px' : '18px';
            const progressHeight = isMobile ? '30px' : '35px';
            const margin = isMobile ? '20px' : '30px';
            const gap = isMobile ? '15px' : '20px';
            
            progressDiv.innerHTML = `
                <h3 style="margin: 0 0 ${margin} 0; color: #333; font-size: ${titleSize}; font-weight: 700;">🚀 并行检测所有代理</h3>
                <div id="parallel-progress-info" style="margin-bottom: ${isMobile ? '20px' : '25px'}; color: #666; font-size: ${textSize}; line-height: 1.6; word-break: break-word;">正在启动并行检测引擎...</div>
                <div style="background: #f0f0f0; border-radius: ${isMobile ? '15px' : '20px'}; height: ${progressHeight}; margin: ${isMobile ? '20px' : '35px'} 0; overflow: hidden; border: 2px solid #ddd;">
                    <div id="parallel-progress-bar" style="background: linear-gradient(90deg, #4CAF50, #45a049, #2E7D32); height: 100%; width: 0%; transition: width 0.8s ease; border-radius: ${isMobile ? '13px' : '18px'}; position: relative;">
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-weight: 700; font-size: ${isMobile ? '12px' : '16px'}; text-shadow: 2px 2px 4px rgba(0,0,0,0.4);" id="parallel-progress-percent">0%</div>
                    </div>
                </div>
                <div id="parallel-progress-stats" style="font-size: ${textSize}; color: #555; margin-bottom: ${isMobile ? '20px' : '30px'}; padding: ${isMobile ? '15px' : '20px'}; background: #f8f9fa; border-radius: 15px; border: 2px solid #e0e0e0; word-break: break-word;">准备启动...</div>
                <div id="parallel-batch-info" style="font-size: ${smallTextSize}; color: #777; margin-bottom: ${isMobile ? '20px' : '25px'}; padding: ${isMobile ? '12px' : '15px'}; background: #fff3cd; border-radius: 10px; border: 1px solid #ffeaa7; word-break: break-word;">批次信息加载中...</div>
                <div style="display: flex; justify-content: center; gap: ${gap}; margin-top: ${isMobile ? '20px' : '25px'};">
                    <button id="cancel-parallel-check" style="padding: ${buttonPadding}; background: #f44336; color: white; border: none; border-radius: 10px; cursor: pointer; font-size: ${buttonSize}; font-weight: 600; transition: all 0.3s ease;" onmouseover="this.style.background='#d32f2f'; ${isMobile ? '' : 'this.style.transform=\'scale(1.05)\''};" onmouseout="this.style.background='#f44336'; ${isMobile ? '' : 'this.style.transform=\'scale(1)\''};">取消检测</button>
                </div>
            `;
            
            document.body.appendChild(progressDiv);
            
            let cancelled = false;
            let progressInterval = null;
            
            document.getElementById('cancel-parallel-check').onclick = async () => {
                cancelled = true;
                
                // 发送取消请求
                try {
                    await fetch('?ajax=1&action=cancelParallelCheck');
                } catch (e) {
                    console.error('取消请求失败:', e);
                }
                
                if (progressInterval) {
                    clearInterval(progressInterval);
                }
                
                document.body.removeChild(progressDiv);
                document.body.removeChild(overlay);
                btn.textContent = originalText;
                btn.disabled = false;
            };
            
            try {
                // 启动并行检测
                document.getElementById('parallel-progress-info').textContent = '正在启动并行检测引擎...';
                
                const startResponse = await fetch('?ajax=1&action=startParallelCheck');
                const startData = await startResponse.json();
                
                if (!startData.success) {
                    // 检查是否是登录过期
                    if (startData.error === 'unauthorized') {
                        alert('登录已过期，请重新登录');
                        window.location.href = 'login.php';
                        return;
                    }
                    throw new Error(startData.error || '启动并行检测失败');
                }
                
                // 显示启动信息
                document.getElementById('parallel-progress-info').textContent = 
                    `并行检测已启动！总计 ${startData.total_proxies} 个代理，分为 ${startData.total_batches} 个批次`;
                
                document.getElementById('parallel-batch-info').textContent = 
                    `每批次 <?php echo PARALLEL_BATCH_SIZE; ?> 个代理，最多 <?php echo PARALLEL_MAX_PROCESSES; ?> 个批次并行执行`;
                
                // 开始监控进度
                const startTime = Date.now();
                const maxWaitTime = 30 * 60 * 1000; // 30分钟超时
                let waitingForBatchesTime = 0; // 等待批次完成的时间
                
                progressInterval = setInterval(async () => {
                    if (cancelled) return;
                    
                    try {
                        const progressResponse = await fetch('?ajax=1&action=getParallelProgress');
                        const progressData = await progressResponse.json();
                        
                        // 检查是否是登录过期
                        if (!progressData.success && progressData.error === 'unauthorized') {
                            clearInterval(progressInterval);
                            document.body.removeChild(progressDiv);
                            document.body.removeChild(overlay);
                            alert('登录已过期，请重新登录');
                            window.location.href = 'login.php';
                            return;
                        }
                        
                        if (progressData.success) {
                            // 更新进度条
                            const progress = progressData.overall_progress;
                            document.getElementById('parallel-progress-bar').style.width = progress + '%';
                            document.getElementById('parallel-progress-percent').textContent = Math.round(progress) + '%';
                            
                            // 更新进度信息 - 基于实际检测的IP数量
                            document.getElementById('parallel-progress-info').textContent = 
                                `并行检测进行中... (${progressData.total_checked}/${progressData.total_proxies} 个代理已检测)`;
                            
                            // 更新统计信息
                            document.getElementById('parallel-progress-stats').textContent = 
                                `已检查: ${progressData.total_checked} | 在线: ${progressData.total_online} | 离线: ${progressData.total_offline}`;
                            
                            // 更新批次信息
                            const activeBatches = progressData.batch_statuses.filter(b => b.status === 'running').length;
                            const completedBatches = progressData.batch_statuses.filter(b => b.status === 'completed').length;
                            document.getElementById('parallel-batch-info').textContent = 
                                `活跃批次: ${activeBatches} | 已完成批次: ${completedBatches} | 总批次: ${progressData.total_batches}`;
                            
                            // 检查是否完成 - 绝对严格：必须所有批次都完成才能显示完成对话框
                            const allBatchesCompleted = completedBatches === progressData.total_batches; // 使用严格相等
                            const progressComplete = progress >= 100;
                            const allProxiesChecked = progressData.total_checked >= progressData.total_proxies;
                            
                            // 额外检查：确保没有正在运行的批次
                            const runningBatches = progressData.batch_statuses.filter(b => b.status === 'running').length;
                            const hasRunningBatches = runningBatches > 0;
                            
                            // 绝对严格的完成条件：所有批次完成 且 没有正在运行的批次 且 所有代理都检测完成
                            const shouldComplete = allBatchesCompleted && !hasRunningBatches && allProxiesChecked;
                            
                            // 特别调试：如果条件不满足但仍然触发了完成，记录警告
                            if (!shouldComplete) {
                                console.warn('⚠️ 完成条件不满足，不应该显示完成对话框:', {
                                    completedBatches,
                                    totalBatches: progressData.total_batches,
                                    allBatchesCompleted,
                                    runningBatches,
                                    hasRunningBatches,
                                    allProxiesChecked,
                                    totalChecked: progressData.total_checked,
                                    totalProxies: progressData.total_proxies
                                });
                            }
                            
                            // 调试日志：记录完成条件检查
                            console.log('完成条件检查:', {
                                completedBatches,
                                totalBatches: progressData.total_batches,
                                allBatchesCompleted,
                                runningBatches,
                                hasRunningBatches,
                                progressComplete,
                                allProxiesChecked,
                                shouldComplete,
                                totalChecked: progressData.total_checked,
                                totalProxies: progressData.total_proxies,
                                batchStatuses: progressData.batch_statuses.map(b => ({
                                    id: b.batch_id,
                                    status: b.status,
                                    progress: b.progress,
                                    checked: b.checked,
                                    limit: b.limit
                                }))
                            });
                            
                            if (shouldComplete) {
                                console.log('✅ 所有完成条件都满足，先同步更新UI再显示完成对话框');
                                
                                // 立即停止轮询，防止更多UI更新
                                clearInterval(progressInterval);
                                
                                // 同步更新UI显示为最终完成状态
                                document.getElementById('parallel-progress-bar').style.width = '100%';
                                document.getElementById('parallel-progress-percent').textContent = '100%';
                                document.getElementById('parallel-progress-info').textContent = 
                                    `检测完成！(${progressData.total_checked}/${progressData.total_proxies} 个代理已检测)`;
                                document.getElementById('parallel-batch-info').textContent = 
                                    `活跃批次: 0 | 已完成批次: ${progressData.total_batches} | 总批次: ${progressData.total_batches}`;
                                
                                // 使用setTimeout确保UI更新完成后再显示对话框
                                setTimeout(() => {
                                    if (!cancelled) {
                                        // 最终安全检查：再次验证所有条件
                                        const finalCompletedBatches = progressData.batch_statuses.filter(b => b.status === 'completed').length;
                                        const finalRunningBatches = progressData.batch_statuses.filter(b => b.status === 'running').length;
                                        const finalAllBatchesCompleted = finalCompletedBatches === progressData.total_batches;
                                        const finalNoRunningBatches = finalRunningBatches === 0;
                                        const finalAllProxiesChecked = progressData.total_checked >= progressData.total_proxies;
                                        
                                        if (finalAllBatchesCompleted && finalNoRunningBatches && finalAllProxiesChecked) {
                                            console.log('✅ 最终安全检查通过，显示完成对话框');
                                            document.body.removeChild(progressDiv);
                                            document.body.removeChild(overlay);
                                            
                                            alert(`🎉 并行检测完成！\n\n总计: ${progressData.total_checked} 个代理\n在线: ${progressData.total_online} 个\n离线: ${progressData.total_offline} 个\n\n页面将自动刷新显示最新状态`);
                                            
                                            // 刷新页面显示最新状态
                                            location.reload();
                                        } else {
                                            console.error('❌ 最终安全检查失败！阻止显示完成对话框:', {
                                                finalCompletedBatches,
                                                totalBatches: progressData.total_batches,
                                                finalAllBatchesCompleted,
                                                finalRunningBatches,
                                                finalNoRunningBatches,
                                                finalAllProxiesChecked,
                                                totalChecked: progressData.total_checked,
                                                totalProxies: progressData.total_proxies
                                            });
                                            // 不显示对话框，继续等待
                                            return;
                                        }
                                    }
                                }, 100); // 100ms延迟，确保UI更新完成
                            } else {
                                // 批次还未全部完成，显示等待信息
                                // 只有在检测真正完成且所有代理都检测完后才开始超时计时
                                if (progressComplete && allProxiesChecked && !hasRunningBatches && waitingForBatchesTime === 0) {
                                    waitingForBatchesTime = Date.now(); // 记录开始等待的时间
                                    console.log('开始等待批次状态更新计时');
                                }
                                
                                const waitingDuration = waitingForBatchesTime > 0 ? Date.now() - waitingForBatchesTime : 0;
                                const waitingSeconds = Math.floor(waitingDuration / 1000);
                                
                                // 根据进度情况显示不同的等待信息
                                if (progressComplete && allProxiesChecked) {
                                    document.getElementById('parallel-progress-info').textContent = 
                                        `检测已完成，等待批次进程结束... (${completedBatches}/${progressData.total_batches} 个批次已完成, 已等待${waitingSeconds}秒)`;
                                } else {
                                    document.getElementById('parallel-progress-info').textContent = 
                                        `并行检测进行中... (${progressData.total_checked}/${progressData.total_proxies} 个代理已检测, ${completedBatches}/${progressData.total_batches} 个批次已完成)`;
                                }
                                
                                // 超时检查：只有在真正开始等待批次状态更新后才检查超时
                                if (waitingForBatchesTime > 0 && waitingDuration > 30000 && progressComplete && allProxiesChecked && !hasRunningBatches) { // 30秒
                                    console.warn('批次进程超时，强制完成检测');
                                    
                                    // 更新UI显示为完成状态
                                    document.getElementById('parallel-progress-bar').style.width = '100%';
                                    document.getElementById('parallel-progress-percent').textContent = '100%';
                                    document.getElementById('parallel-progress-info').textContent = 
                                        `检测完成（超时）！(${progressData.total_checked}/${progressData.total_proxies} 个代理已检测)`;
                                    document.getElementById('parallel-batch-info').textContent = 
                                        `活跃批次: 0 | 已完成批次: ${completedBatches} | 总批次: ${progressData.total_batches}`;
                                    
                                    clearInterval(progressInterval);
                                    
                                    if (!cancelled) {
                                        document.body.removeChild(progressDiv);
                                        document.body.removeChild(overlay);
                                        
                                        alert(`⚠️ 并行检测完成（部分批次超时）！\n\n总计: ${progressData.total_checked} 个代理\n在线: ${progressData.total_online} 个\n离线: ${progressData.total_offline} 个\n\n注意：有 ${progressData.total_batches - completedBatches} 个批次可能未完全结束，但检测已完成\n\n页面将自动刷新显示最新状态`);
                                        
                                        location.reload();
                                    }
                                }
                            }
                        }
                    } catch (error) {
                        console.error('获取进度失败:', error);
                    }
                    
                    // 整体超时检查：如果总时间超过30分钟，强制停止
                    const totalDuration = Date.now() - startTime;
                    if (totalDuration > maxWaitTime) {
                        console.warn('并行检测总体超时，强制停止');
                        clearInterval(progressInterval);
                        
                        if (!cancelled) {
                            document.body.removeChild(progressDiv);
                            document.body.removeChild(overlay);
                            
                            alert(`⚠️ 并行检测超时！\n\n检测已运行超过30分钟，可能存在问题。\n请检查服务器状态或联系管理员。\n\n页面将自动刷新`);
                            
                            location.reload();
                        }
                    }
                }, 1000); // 每秒更新一次进度
                
            } catch (error) {
                if (!cancelled) {
                    document.body.removeChild(progressDiv);
                    document.body.removeChild(overlay);
                    console.error('并行检测失败:', error);
                    alert('❌ 并行检测失败: ' + error.message);
                }
            } finally {
                if (!cancelled) {
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            }
        }
        
        // 状态筛选功能
        function filterByStatus(status) {
            const currentUrl = new URL(window.location);
            const searchParams = currentUrl.searchParams;
            
            if (status) {
                searchParams.set('status', status);
            } else {
                searchParams.delete('status');
            }
            
            // 重置到第一页
            searchParams.delete('page');
            
            window.location.href = currentUrl.toString();
        }
        
        // 搜索功能
        function performSearch() {
            const searchTerm = document.getElementById('search-input').value.trim();
            const currentUrl = new URL(window.location);
            const searchParams = currentUrl.searchParams;
            
            if (searchTerm) {
                searchParams.set('search', searchTerm);
            } else {
                searchParams.delete('search');
            }
            
            // 重置到第一页
            searchParams.delete('page');
            
            window.location.href = currentUrl.toString();
        }
        
        // 清除搜索和筛选
        function clearSearch() {
            const currentUrl = new URL(window.location);
            const searchParams = currentUrl.searchParams;
            
            searchParams.delete('search');
            searchParams.delete('status');
            searchParams.delete('page');
            
            window.location.href = currentUrl.toString();
        }
        
        function refreshAll() {
            location.reload();
        }
        
        // 调试函数：查看数据库中的实际状态值
        function debugStatuses() {
            fetch('?ajax=1&action=debugStatuses')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('数据库中的状态值:', data.statuses);
                        alert('请查看浏览器控制台查看状态值');
                    } else {
                        console.error('获取状态值失败:', data.error);
                    }
                })
                .catch(error => {
                    console.error('调试失败:', error);
                });
        }
        
        // 测试函数：创建不同状态的测试数据
        function createTestData() {
            if (confirm('这将修改前4个代理的状态为离线和未知，用于测试筛选功能。确定继续吗？')) {
                fetch('?ajax=1&action=createTestData')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message + '\n\n页面将刷新以显示更新后的数据');
                            location.reload();
                        } else {
                            alert('创建测试数据失败: ' + data.error);
                        }
                    })
                    .catch(error => {
                        console.error('创建测试数据失败:', error);
                        alert('创建测试数据失败');
                    });
            }
        }
        
        // 监听搜索框的回车键
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        performSearch();
                    }
                });
                
                // 自动聚焦搜索框（如果有搜索词）
                <?php if (!empty($searchTerm)): ?>
                searchInput.focus();
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
                <?php endif; ?>
            }
        });
        
        // 会话管理
        <?php if (Auth::isLoginEnabled()): ?>
        function checkSession() {
            fetch('?ajax=1&action=sessionCheck')
                .then(response => response.json())
                .then(data => {
                    if (!data.valid) {
                        alert('会话已过期，请重新登录');
                        window.location.href = 'login.php';
                    }
                })
                .catch(error => {
                    console.error('会话检查失败:', error);
                });
        }
        
        // 每5分钟检查一次会话状态
        setInterval(checkSession, 5 * 60 * 1000);
        
        // 页面加载时检查一次
        checkSession();
        <?php endif; ?>
        
        // 全局变量存储代理数量
        let cachedProxyCount = null;
        let cacheTimestamp = null;
        
        // 预加载代理数量
        async function preloadProxyCount() {
            // 检查页面是否正确加载了HTML内容
            if (!document.getElementById('proxies-table') || !document.querySelector('.stats-grid')) {
                console.log('页面HTML未正确加载，跳过预加载代理数量');
                return;
            }
            
            try {
                const response = await fetch('?ajax=1&action=getProxyCount');
                const data = await response.json();
                
                if (data.success) {
                    cachedProxyCount = data.count;
                    cacheTimestamp = Date.now();
                    
                    console.log(`预加载代理数量: ${data.count} (查询时间: ${data.execution_time}ms, 缓存: ${data.cached ? '是' : '否'})`);
                }
            } catch (error) {
                console.log('预加载代理数量失败:', error);
            }
        }
        
        // 获取缓存的代理数量（如果有效）
        function getCachedProxyCount() {
            // 缓存有效期5分钟
            if (cachedProxyCount !== null && cacheTimestamp && (Date.now() - cacheTimestamp) < 300000) {
                return cachedProxyCount;
            }
            return null;
        }
        
        // 页面加载完成后预加载代理数量
        document.addEventListener('DOMContentLoaded', function() {
            // 延迟执行预加载，确保页面完全渲染完成
            setTimeout(() => {
                preloadProxyCount();
            }, 1000); // 1秒延迟
        });
    </script>
</body>
</html>
