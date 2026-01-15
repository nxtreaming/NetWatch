<?php
/**
 * NetWatch AJAX请求处理器
 * 处理所有AJAX请求的逻辑
 */

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
        }

        if (is_object($monitor) && method_exists($monitor, 'getLogger')) {
            $this->logger = $monitor->getLogger();
        } else {
            $this->logger = new Logger();
        }
    }
    
    /**
     * 设置标准JSON响应头
     */
    private function setJsonHeaders() {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
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
                echo json_encode(['error' => '未知操作']);
        }
    }
    
    private function handleStats() {
        $this->setJsonHeaders();
        echo json_encode($this->monitor->getStats());
    }
    
    private function handleCheck() {
        $this->setJsonHeaders();
        $proxyId = $_GET['proxy_id'] ?? null;
        if ($proxyId) {
            $proxy = $this->monitor->getProxyById($proxyId);
            if ($proxy) {
                $result = $this->monitor->checkProxy($proxy);
                echo json_encode($result);
            } else {
                echo json_encode(['error' => '代理不存在']);
            }
        } else {
            echo json_encode(['error' => '缺少代理ID']);
        }
    }
    
    private function handleLogs() {
        $this->setJsonHeaders();
        $logs = $this->monitor->getRecentLogs(50);
        echo json_encode($logs);
    }
    
    private function handleCheckAll() {
        $this->setJsonHeaders();
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
                    
                    $mailer->sendProxyAlert($failedProxies);
                    $emailSent = true;
                    
                    // 记录警报
                    foreach ($failedProxies as $proxy) {
                        $this->monitor->addAlert(
                            $proxy['id'],
                            'proxy_failure',
                            "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                        );
                    }
                } catch (Exception $mailError) {
                    $this->logger->error('发送邮件失败', [
                        'error' => $mailError->getMessage()
                    ]);
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
    }
    
    private function handleGetProxyCount() {
        $this->setJsonHeaders();
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
            header('Content-Type: application/json');
            header('X-Accel-Buffering: no'); // 禁用nginx缓冲
            header('Cache-Control: no-cache'); // 禁用缓存
            
            // 记录开始时间
            $startTime = microtime(true);
            
            $offset = intval($_GET['offset'] ?? 0);
            $limit = intval($_GET['limit'] ?? 20);
            
            // 检查monitor对象
            if (!$this->monitor) {
                throw new Exception('Monitor对象未初始化');
            }
            
            // 获取要检查的代理列表
            $proxies = $this->db->getProxiesBatch($offset, $limit);
            
            if (empty($proxies)) {
                echo json_encode([
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
                usleep(10000); // 0.01秒
            }
            
            // 计算执行时间
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // 发送最终结果（前面的空格会被JSON解析器忽略）
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
        } catch (Error $e) {
            echo json_encode([
                'success' => false,
                'error' => 'PHP Fatal Error: ' . $e->getMessage()
            ]);
        }
    }
    
    private function handleCheckFailedProxies() {
        $this->setJsonHeaders();
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
                    
                    $mailer->sendProxyAlert($failedProxies);
                    $emailSent = true;
                    
                    // 记录警报
                    foreach ($failedProxies as $proxy) {
                        $this->monitor->addAlert(
                            $proxy['id'],
                            'proxy_failure',
                            "代理 {$proxy['ip']}:{$proxy['port']} 连续失败 {$proxy['failure_count']} 次"
                        );
                    }
                } catch (Exception $mailError) {
                    $this->logger->error('发送邮件失败', [
                        'error' => $mailError->getMessage()
                    ]);
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
    }
    
    private function handleStartParallelCheck($offlineOnly = false) {
        $this->setJsonHeaders();
        try {
            require_once 'parallel_monitor.php';
            // 创建会话独立的并行监控器：使用配置常量和会话ID
            $sessionId = session_id() . '_' . time() . '_' . mt_rand(1000, 9999);

            if (file_exists(__DIR__ . '/AuditLogger.php')) {
                require_once __DIR__ . '/AuditLogger.php';
                AuditLogger::log('parallel_check_start', 'proxy', $sessionId, [
                    'offline_only' => (bool)$offlineOnly
                ]);
            }
            
            // 如果是离线代理检测，使用更小的批次大小和更少的进程数
            if ($offlineOnly) {
                $maxProcesses = 8; // 离线检测使用较少的进程
                $batchSize = 50;   // 离线检测使用较小的批次
            } else {
                $maxProcesses = PARALLEL_MAX_PROCESSES;
                $batchSize = PARALLEL_BATCH_SIZE;
            }
            
            $parallelMonitor = new ParallelMonitor($maxProcesses, $batchSize, $sessionId, $offlineOnly);
            
            // 启动并行检测（异步）
            $result = $parallelMonitor->startParallelCheck();
            
            echo json_encode($result);
        } catch (Exception $e) {
            $checkType = $offlineOnly ? '离线代理' : '并行';
            echo json_encode([
                'success' => false,
                'error' => "启动{$checkType}检测失败: " . $e->getMessage()
            ]);
        }
    }
    
    private function handleGetParallelProgress() {
        $this->setJsonHeaders();
        try {
            require_once 'parallel_monitor.php';
            // 获取会话ID，用于查询对应的检测进度
            $sessionId = $_GET['session_id'] ?? null;
            if (!$sessionId) {
                echo json_encode(['success' => false, 'error' => '缺少会话ID参数']);
                return;
            }
            $parallelMonitor = new ParallelMonitor(PARALLEL_MAX_PROCESSES, PARALLEL_BATCH_SIZE, $sessionId);
            
            $progress = $parallelMonitor->getParallelProgress();
            echo json_encode($progress);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => '获取进度失败: ' . $e->getMessage()
            ]);
        }
    }
    
    private function handleCancelParallelCheck() {
        $this->setJsonHeaders();
        try {
            require_once 'parallel_monitor.php';
            // 获取会话ID，用于取消对应的检测任务
            $sessionId = $_GET['session_id'] ?? null;
            if (!$sessionId) {
                echo json_encode(['success' => false, 'error' => '缺少会话ID参数']);
                return;
            }

            if (file_exists(__DIR__ . '/AuditLogger.php')) {
                require_once __DIR__ . '/AuditLogger.php';
                AuditLogger::log('parallel_check_cancel', 'proxy', $sessionId);
            }
            $parallelMonitor = new ParallelMonitor(PARALLEL_MAX_PROCESSES, PARALLEL_BATCH_SIZE, $sessionId);
            
            $result = $parallelMonitor->cancelParallelCheck();
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => '取消检测失败: ' . $e->getMessage()
            ]);
        }
    }
    
    private function handleSessionCheck() {
        $this->setJsonHeaders();
        try {
            if (!Auth::isLoggedIn()) {
                echo json_encode(['valid' => false]);
            } else {
                echo json_encode(['valid' => true]);
            }
        } catch (Exception $e) {
            echo json_encode(['valid' => false, 'error' => $e->getMessage()]);
        }
    }
    
    private function handleDebugStatuses() {
        $this->setJsonHeaders();
        try {
            $statuses = $this->db->getDistinctStatuses();
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
    }
    
    private function handleCreateTestData() {
        $this->setJsonHeaders();
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
    }
    
    private function handleSearch() {
        $this->setJsonHeaders();
        try {
            $searchTerm = $_GET['term'] ?? '';
            $statusFilter = $_GET['status'] ?? '';
            $page = max(1, intval($_GET['page'] ?? 1));
            $perPage = defined('PROXIES_PER_PAGE') ? PROXIES_PER_PAGE : 200;
            
            // 直接使用数据库对象实现搜索和筛选
            $proxies = $this->db->searchProxies($searchTerm, $page, $perPage, $statusFilter);
            // 过滤敏感信息
            $proxies = array_map(function($proxy) {
                unset($proxy['username']);
                unset($proxy['password']);
                return $proxy;
            }, $proxies);
            $totalCount = $this->db->getSearchCount($searchTerm, $statusFilter);
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
    }

}
