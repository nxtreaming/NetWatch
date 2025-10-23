<?php
/**
 * NetWatch AJAX请求处理器
 * 处理所有AJAX请求的逻辑
 */

class AjaxHandler {
    private $monitor;
    
    public function __construct($monitor) {
        $this->monitor = $monitor;
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
        echo json_encode($this->monitor->getStats());
    }
    
    private function handleCheck() {
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
        $logs = $this->monitor->getRecentLogs(50);
        echo json_encode($logs);
    }
    
    private function handleCheckAll() {
        try {
            $results = $this->monitor->checkAllProxies();
            
            // 检查是否有需要发送警报的代理
            $failedProxies = $this->monitor->getFailedProxies();
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
                        $this->monitor->addAlert(
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
    }
    
    private function handleGetProxyCount() {
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
            $offset = intval($_GET['offset'] ?? 0);
            $limit = intval($_GET['limit'] ?? 20);
            
            // 检查monitor对象
            if (!$this->monitor) {
                throw new Exception('Monitor对象未初始化');
            }
            
            $results = $this->monitor->checkProxyBatch($offset, $limit);
            
            // 检查结果
            if (!is_array($results)) {
                throw new Exception('checkProxyBatch返回值不是数组');
            }
            
            echo json_encode([
                'success' => true,
                'results' => $results,
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
        try {
            // 检查是否有需要发送警报的代理
            $failedProxies = $this->monitor->getFailedProxies();
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
                        $this->monitor->addAlert(
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
    }
    
    private function handleStartParallelCheck($offlineOnly = false) {
        try {
            require_once 'parallel_monitor.php';
            // 创建会话独立的并行监控器：使用配置常量和会话ID
            $sessionId = session_id() . '_' . time() . '_' . mt_rand(1000, 9999);
            
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
        try {
            require_once 'parallel_monitor.php';
            // 获取会话ID，用于取消对应的检测任务
            $sessionId = $_GET['session_id'] ?? null;
            if (!$sessionId) {
                echo json_encode(['success' => false, 'error' => '缺少会话ID参数']);
                return;
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
    }
    
    private function handleCreateTestData() {
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
    }
    
    private function handleSearch() {
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
    }

}
