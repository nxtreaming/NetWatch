<?php
/**
 * NetWatch AJAX请求处理器
 * 处理所有AJAX请求的逻辑
 */

require_once __DIR__ . '/ParallelCheckController.php';
require_once __DIR__ . '/JsonResponse.php';

class AjaxHandler {
    private $monitor;
    private $db;
    private $logger;
    
    public function __construct($monitor, $db = null) {
        $this->monitor = $monitor;

        if ($db !== null) {
            $this->db = $db;
        } elseif (is_object($monitor) && method_exists($monitor, 'getDatabase')) {
            $this->db = $monitor->getDatabase();
        } else {
            $this->db = new Database();
            $this->db->initializeSchema();
        }

        if (is_object($monitor) && method_exists($monitor, 'getLogger')) {
            $this->logger = $monitor->getLogger();
        } else {
            $this->logger = new Logger();
        }
    }

    private function startStreamingJsonResponse(): void {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-cache');
        }
    }

    private function emitStreamingJsonPayload(array $payload): void {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function emitStreamingErrorPayload(string $errorCode, string $message): void {
        $this->emitStreamingJsonPayload([
            'success' => false,
            'error' => $message,
            'error_code' => $errorCode,
        ]);
    }
    
    /**
     * 处理AJAX请求
     * @param string $action 操作类型
     * @return void 直接输出JSON响应
     */
    public function handleRequest($action) {
        switch ($action) {
            case 'stats':
                $this->handleStats();
                break;
            
            case 'check':
                $this->handleCheck();
                break;
                
            case 'logs':
                $this->handleLogs();
                break;
            
            case 'checkAll':
                $this->handleCheckAll();
                break;
            
            case 'getProxyCount':
                $this->handleGetProxyCount();
                break;
                
            case 'checkBatch':
                $this->handleCheckBatch();
                break;
                
            case 'checkFailedProxies':
                $this->handleCheckFailedProxies();
                break;
                
            case 'startParallelCheck':
                $this->handleStartParallelCheck();
                break;
                
            case 'getParallelProgress':
                $this->handleGetParallelProgress();
                break;
                
            case 'cancelParallelCheck':
                $this->handleCancelParallelCheck();
                break;
                
            case 'sessionCheck':
                $this->handleSessionCheck();
                break;
                
            case 'debugStatuses':
                $this->handleDebugStatuses();
                break;
                
            case 'createTestData':
                $this->handleCreateTestData();
                break;
                
            case 'search':
                $this->handleSearch();
                break;
                
            case 'startOfflineParallelCheck':
                $this->handleStartParallelCheck(true); // 传入true表示只检测离线代理
                break;
            case 'getOfflineParallelProgress':
                $this->handleGetParallelProgress(); // 复用现有的进度查询
                break;
            case 'cancelOfflineParallelCheck':
                $this->handleCancelParallelCheck(); // 复用现有的取消逻辑
                break;
                
            default:
                JsonResponse::error('unknown_action', '未知操作', 400);
        }
    }
    
    private function handleStats() {
        JsonResponse::send($this->monitor->getStats());
    }
    
    private function handleCheck() {
        $proxyId = isset($_GET['proxy_id']) ? intval($_GET['proxy_id']) : 0;
        if ($proxyId > 0) {
            $proxy = $this->monitor->getProxyById($proxyId);
            if ($proxy) {
                $result = $this->monitor->checkProxy($proxy);
                JsonResponse::send($result);
            } else {
                JsonResponse::error('proxy_not_found', '代理不存在', 404);
            }
        } else {
            JsonResponse::error('missing_proxy_id', '缺少代理ID', 400);
        }
    }
    
    private function handleLogs() {
        $logs = $this->monitor->getRecentLogs(50);
        JsonResponse::send($logs);
    }
    
    private function handleCheckAll() {
        try {
            if (file_exists(__DIR__ . '/AuditLogger.php')) {
                require_once __DIR__ . '/AuditLogger.php';
                AuditLogger::log('check_all', 'proxy');
            }
            $results = $this->monitor->checkAllProxies();
            
            // 检查是否有需要发送警报的代理
            $failedProxies = $this->monitor->getFailedProxies();
            $emailSent = false;
            
            if (!empty($failedProxies)) {
                try {
                    require_once __DIR__ . '/MailerFactory.php';
                    $mailer = MailerFactory::create();
                    
                    $emailSent = $mailer->sendProxyAlert($failedProxies) === true;

                    if ($emailSent) {
                        foreach ($failedProxies as $proxy) {
                            $this->monitor->addAlert(
                                $proxy['id'],
                                'proxy_failure',
                                "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                            );
                        }
                    } else {
                        $this->logger->error('ajax_check_all_email_send_failed', [
                            'failed_proxy_count' => count($failedProxies),
                        ]);
                    }
                } catch (Exception $mailError) {
                    $this->logger->error('ajax_check_all_email_failed', [
                        'failed_proxy_count' => count($failedProxies),
                        'exception' => $mailError->getMessage()
                    ]);
                }
            }
            
            JsonResponse::success(null, '所有代理检查完成', 200, [
                'success' => true,
                'results' => $results,
                'failed_proxies' => count($failedProxies),
                'email_sent' => $emailSent
            ]);
        } catch (Exception $e) {
            $this->logger->error('ajax_check_all_failed', [
                'exception' => $e->getMessage(),
            ]);
            JsonResponse::error('check_all_failed', '检查失败，请稍后重试', 500);
        }
    }
    
    private function handleGetProxyCount() {
        try {
            // 记录开始时间
            $startTime = microtime(true);
            
            // 检查是否有缓存
            $cacheFile = defined('PROXY_COUNT_CACHE_FILE') ? PROXY_COUNT_CACHE_FILE : 'cache_proxy_count.txt';
            $cacheTime = defined('PROXY_COUNT_CACHE_TIME') ? PROXY_COUNT_CACHE_TIME : 300;
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
                $count = $this->monitor->getProxyCount();
                
                // 原子写入缓存：先写临时文件再 rename，避免部分写入
                $cacheData = json_encode([
                    'count' => $count,
                    'timestamp' => time()
                ]);
                $tmpFile = $cacheFile . '.tmp.' . getmypid();
                $written = file_put_contents($tmpFile, $cacheData, LOCK_EX);
                if ($written !== false) {
                    if (!rename($tmpFile, $cacheFile)) {
                        if (file_exists($tmpFile) && !unlink($tmpFile)) {
                            $this->logger->warning('ajax_proxy_count_cache_tmp_cleanup_failed', [
                                'tmp_file' => $tmpFile,
                            ]);
                        }
                        $this->logger->warning('ajax_proxy_count_cache_rename_failed', [
                            'tmp_file' => $tmpFile,
                            'cache_file' => $cacheFile,
                        ]);
                    }
                } else {
                    // 写入失败，记录日志并继续（降级为无缓存模式）
                    if (file_exists($tmpFile) && !unlink($tmpFile)) {
                        $this->logger->warning('ajax_proxy_count_cache_tmp_cleanup_failed', [
                            'tmp_file' => $tmpFile,
                        ]);
                    }
                    $this->logger->warning('ajax_proxy_count_cache_write_failed', [
                        'cache_file' => $cacheFile,
                    ]);
                }
            }
            
            // 计算执行时间
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            JsonResponse::send([
                'success' => true,
                'count' => $count,
                'cached' => $useCache,
                'execution_time' => $executionTime
            ]);
        } catch (Exception $e) {
            $this->logger->error('ajax_get_proxy_count_failed', [
                'exception' => $e->getMessage(),
            ]);
            JsonResponse::error('get_proxy_count_failed', '获取代理数量失败，请稍后重试', 500);
        }
    }
    
    private function handleCheckBatch() {
        try {
            // 设置PHP执行时间限制
            set_time_limit(300); // 5分钟
            
            // 禁用所有输出缓冲，启用实时输出
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // 设置响应头，启用分块传输编码
            // 使用 text/plain 因为流式输出中会插入心跳空格，前端需要 trim 后再 JSON.parse
            $this->startStreamingJsonResponse();
            
            // 记录开始时间
            $startTime = microtime(true);
            
            $offset = max(0, intval($_GET['offset'] ?? 0));
            $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
            
            // 检查monitor对象
            if (!$this->monitor) {
                throw new Exception('Monitor对象未初始化');
            }
            
            // 获取要检查的代理列表
            $proxies = $this->db->getProxiesBatch($offset, $limit);
            
            if (empty($proxies)) {
                $this->emitStreamingJsonPayload([
                    'success' => true,
                    'results' => [],
                    'execution_time' => 0,
                    'batch_info' => [
                        'offset' => $offset,
                        'limit' => $limit,
                        'actual_count' => 0
                    ]
                ]);
                return;
            }
            
            $results = [];
            $processedCount = 0;
            
            // 逐个检查代理，每检查一个就发送心跳
            foreach ($proxies as $proxy) {
                // 检查代理
                $result = $this->monitor->checkProxyFast($proxy);
                
                // 过滤敏感信息
                $filteredProxy = $this->monitor->filterSensitiveData($proxy);
                $results[] = array_merge($filteredProxy, $result);
                
                $processedCount++;
                
                // 每检查一个代理就发送一个空格作为keep-alive心跳
                // 这样可以保持连接活跃，避免超时
                echo ' ';
                flush();
                
                // 短暂延迟，避免过度占用资源
                usleep(defined('AJAX_STREAM_THROTTLE_US') ? AJAX_STREAM_THROTTLE_US : 10000); // 0.01秒
            }
            
            // 计算执行时间
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->emitStreamingJsonPayload([
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
            $this->logger->error('ajax_check_batch_failed', [
                'offset' => $offset ?? null,
                'limit' => $limit ?? null,
                'exception' => $e->getMessage(),
            ]);
            $this->emitStreamingErrorPayload('batch_check_failed', '批量检查失败，请稍后重试');
        } catch (Error $e) {
            $this->logger->error('ajax_check_batch_fatal', [
                'offset' => $offset ?? null,
                'limit' => $limit ?? null,
                'exception' => $e->getMessage(),
            ]);
            $this->emitStreamingErrorPayload('internal_server_error', '服务器内部错误，请稍后重试');
        }
    }
    
    private function handleCheckFailedProxies() {
        try {
            if (file_exists(__DIR__ . '/AuditLogger.php')) {
                require_once __DIR__ . '/AuditLogger.php';
                AuditLogger::log('check_failed_proxies', 'proxy');
            }
            // 检查是否有需要发送警报的代理
            $failedProxies = $this->monitor->getFailedProxies();
            $emailSent = false;
            
            if (!empty($failedProxies)) {
                try {
                    require_once __DIR__ . '/MailerFactory.php';
                    $mailer = MailerFactory::create();
                    
                    $emailSent = $mailer->sendProxyAlert($failedProxies) === true;

                    if ($emailSent) {
                        foreach ($failedProxies as $proxy) {
                            $this->monitor->addAlert(
                                $proxy['id'],
                                'proxy_failure',
                                "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                            );
                        }
                    } else {
                        $this->logger->error('ajax_check_failed_proxies_email_send_failed', [
                            'failed_proxy_count' => count($failedProxies),
                        ]);
                    }
                } catch (Exception $mailError) {
                    $this->logger->error('ajax_check_failed_proxies_email_failed', [
                        'failed_proxy_count' => count($failedProxies),
                        'exception' => $mailError->getMessage()
                    ]);
                }
            }
            
            JsonResponse::send([
                'success' => true,
                'failed_proxies' => count($failedProxies),
                'email_sent' => $emailSent
            ]);
        } catch (Exception $e) {
            $this->logger->error('ajax_check_failed_proxies_failed', [
                'exception' => $e->getMessage(),
            ]);
            JsonResponse::error('check_failed_proxies_failed', '检查失败代理时出错，请稍后重试', 500);
        }
    }
    
    private function handleStartParallelCheck($offlineOnly = false) {
        $controller = new ParallelCheckController($this->logger);
        $controller->startParallelCheck((bool)$offlineOnly);
    }
    
    private function handleGetParallelProgress() {
        $controller = new ParallelCheckController($this->logger);
        $controller->getParallelProgress();
    }
    
    private function handleCancelParallelCheck() {
        $controller = new ParallelCheckController($this->logger);
        $controller->cancelParallelCheck();
    }
    
    private function handleSessionCheck() {
        try {
            if (!Auth::isLoggedIn()) {
                JsonResponse::send([
                    'valid' => false,
                    'timestamp' => time()
                ]);
            } else {
                JsonResponse::send([
                    'valid' => true,
                    'timestamp' => time()
                ]);
            }
        } catch (Exception $e) {
            JsonResponse::send([
                'valid' => false,
                'error' => $e->getMessage(),
                'timestamp' => time()
            ], 500);
        }
    }
    
    private function handleDebugStatuses() {
        try {
            $statuses = $this->db->getDistinctStatuses();
            JsonResponse::send([
                'success' => true,
                'statuses' => $statuses
            ]);
        } catch (Exception $e) {
            $this->logger->error('ajax_debug_statuses_failed', [
                'exception' => $e->getMessage(),
            ]);
            JsonResponse::error('debug_statuses_failed', '获取状态信息失败，请稍后重试', 500);
        }
    }
    
    private function handleCreateTestData() {
        try {
            // 获取前5个代理的ID
            $proxies = $this->db->getProxiesBatch(0, 5);
            $updated = 0;
            
            foreach ($proxies as $index => $proxy) {
                if ($index < 2) {
                    // 前2个设为离线
                    $this->db->updateProxyStatus($proxy['id'], 'offline', 0, '测试数据');
                    $updated++;
                } elseif ($index < 4) {
                    // 中间2个设为未知
                    $this->db->updateProxyStatus($proxy['id'], 'unknown', 0, '测试数据');
                    $updated++;
                }
            }
            
            JsonResponse::success(null, "已创建测试数据：2个离线代理和2个未知代理", 200, [
                'success' => true,
                'updated' => $updated
            ]);
        } catch (Exception $e) {
            $this->logger->error('ajax_create_test_data_failed', [
                'exception' => $e->getMessage(),
            ]);
            JsonResponse::error('create_test_data_failed', '创建测试数据失败，请稍后重试', 500);
        }
    }
    
    private function handleSearch() {
        try {
            $searchTerm = mb_substr(trim($_GET['term'] ?? ''), 0, 64);
            $statusFilter = $_GET['status'] ?? '';
            $page = max(1, min(10000, intval($_GET['page'] ?? 1)));
            $perPage = defined('PROXIES_PER_PAGE') ? PROXIES_PER_PAGE : 200;
            
            // 直接使用数据库对象实现搜索和筛选
            $proxies = $this->db->searchProxies($searchTerm, $page, $perPage, $statusFilter);
            // 过滤敏感信息
            $proxies = array_map(function($proxy) {
                return $this->monitor->filterSensitiveData($proxy);
            }, $proxies);
            $totalCount = $this->db->getSearchCount($searchTerm, $statusFilter);
            $totalPages = ceil($totalCount / $perPage);
            
            JsonResponse::send([
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
            $this->logger->error('ajax_search_failed', [
                'search_term' => $searchTerm ?? '',
                'status_filter' => $statusFilter ?? '',
                'page' => $page ?? null,
                'exception' => $e->getMessage(),
            ]);
            JsonResponse::error('search_failed', '搜索失败，请稍后重试', 500);
        }
    }

}
