<?php
/**
 * 网络监控核心类
 */

require_once 'config.php';
require_once 'database.php';
require_once 'includes/Config.php';
require_once 'logger.php';
require_once 'proxy_checker.php';

class NetworkMonitor {
    protected Database $db;
    protected Logger $logger;
    protected ProxyChecker $proxyChecker;
    
    public function __construct(?Database $db = null, ?Logger $logger = null) {
        ensure_valid_config();
        $this->db = $db ?? new Database();
        $this->db->initializeSchema();
        $this->logger = $logger ?? new Logger();
        $this->proxyChecker = new ProxyChecker();
    }
    
    /**
     * 获取数据库实例
     */
    public function getDatabase(): Database {
        return $this->db;
    }
    
    /**
     * 获取日志实例
     */
    public function getLogger(): Logger {
        return $this->logger;
    }
    
    /**
     * 检查单个代理
     * @param array $proxy 代理信息
     * @return array 检查结果
     */
    public function checkProxy(array $proxy): array {
        return $this->executeProxyCheck($proxy, TIMEOUT, TIMEOUT, '逐个检查');
    }
    
    /**
     * 快速检查单个代理（用于批量检查，更短的超时时间）
     * @param array $proxy 代理信息
     * @param bool $enableRetry 是否启用失败重试（默认启用）
     * @return array 检查结果
     */
    public function checkProxyFast(array $proxy, bool $enableRetry = true): array {
        return $this->executeProxyCheck($proxy, 3, 2, '快速检查', $enableRetry);
    }
    
    /**
     * 执行代理检查的核心逻辑
     * @param array $proxy 代理信息
     * @param int $timeout 请求超时时间
     * @param int $connectTimeout 连接超时时间
     * @param string $logPrefix 日志前缀
     * @param bool $enableRetry 是否启用失败重试
     * @return array 检查结果
     */
    private function executeProxyCheck(array $proxy, int $timeout, int $connectTimeout, string $logPrefix, bool $enableRetry = false): array {
        $maxAttempts = $enableRetry ? max(1, (int) config('monitoring.max_retries', 3)) : 1;
        $retryDelayUs = (int) config('monitoring.retry_delay_us', 200000);
        $attempt = 1;

        do {
            $result = $this->proxyChecker->check($proxy, $timeout, $connectTimeout);

            if (($result['status'] ?? 'offline') === 'online') {
                $this->logSuccessfulCheck($proxy, $logPrefix, $result);
                break;
            }

            if ($attempt < $maxAttempts) {
                $this->logRetryAttempt($proxy, $logPrefix, (bool) ($result['is_exception'] ?? false), $attempt, $maxAttempts);
                usleep($retryDelayUs);
                $attempt++;
                continue;
            }

            $this->logFailedCheck($proxy, $logPrefix, $result, $attempt - 1);
            break;
        } while (true);

        $this->persistProxyCheckResult($proxy, $result);

        return [
            'status' => $result['status'] ?? 'offline',
            'response_time' => $result['response_time'] ?? 0,
            'error_message' => $result['error_message'] ?? null
        ];
    }

    private function persistProxyCheckResult(array $proxy, array $result): void {
        $this->db->updateProxyStatus(
            $proxy['id'],
            $result['status'] ?? 'offline',
            $result['response_time'] ?? 0,
            $result['error_message'] ?? null
        );
    }

    private function buildProxyCheckExceptionResult(Throwable $e): array {
        return [
            'status' => 'unknown',
            'response_time' => 0,
            'error_message' => $e->getMessage(),
        ];
    }

    private function logProxyCheckException(array $proxy, Throwable $e, string $scope): void {
        $this->logger->error('proxy_check_persist_error', [
            'scope' => $scope,
            'proxy_id' => $proxy['id'] ?? null,
            'proxy_ip' => $proxy['ip'] ?? null,
            'proxy_port' => $proxy['port'] ?? null,
            'proxy_type' => $proxy['type'] ?? null,
            'exception_class' => get_class($e),
            'error_message' => $e->getMessage(),
        ]);
    }

    private function logSuccessfulCheck(array $proxy, string $logPrefix, array $result): void {
        $responseTime = $result['response_time'] ?? 0;
        $errorMessage = $result['error_message'] ?? null;
        $context = [
            'proxy_id' => $proxy['id'] ?? null,
            'proxy_ip' => $proxy['ip'] ?? null,
            'proxy_port' => $proxy['port'] ?? null,
            'proxy_type' => $proxy['type'] ?? null,
            'log_prefix' => $logPrefix,
            'response_time_ms' => $responseTime,
        ];

        if (!empty($errorMessage)) {
            $context['error_message'] = $errorMessage;
            $this->logger->info('proxy_check_success_with_transport_warning', $context);
            return;
        }

        $this->logger->info('proxy_check_success', $context);
    }

    private function logRetryAttempt(array $proxy, string $logPrefix, bool $isException, int $attempt, int $maxAttempts): void {
        $reason = $isException ? '异常' : '失败';
        $this->logger->info('proxy_check_retry', [
            'proxy_id' => $proxy['id'] ?? null,
            'proxy_ip' => $proxy['ip'] ?? null,
            'proxy_port' => $proxy['port'] ?? null,
            'proxy_type' => $proxy['type'] ?? null,
            'log_prefix' => $logPrefix,
            'reason' => $reason,
            'retry_count' => $attempt,
            'max_retries' => max(0, $maxAttempts - 1),
        ]);
    }

    private function logFailedCheck(array $proxy, string $logPrefix, array $result, int $retryCount): void {
        $retryInfo = $retryCount > 0 ? "(重试{$retryCount}次后)" : "";
        $errorMessage = $result['error_message'] ?? '';
        $responseTime = $result['response_time'] ?? 0;
        $context = [
            'proxy_id' => $proxy['id'] ?? null,
            'proxy_ip' => $proxy['ip'] ?? null,
            'proxy_port' => $proxy['port'] ?? null,
            'proxy_type' => $proxy['type'] ?? null,
            'log_prefix' => $logPrefix,
            'retry_count' => $retryCount,
            'retry_label' => $retryInfo,
            'response_time_ms' => $responseTime,
            'error_message' => $errorMessage,
        ];

        if (!empty($result['is_exception'])) {
            $context['is_exception'] = true;
            $this->logger->error('proxy_check_exception', $context);
            return;
        }

        $context['is_exception'] = false;
        $this->logger->warning('proxy_check_failed', $context);
    }
    
    /**
     * 检查所有代理
     */
    public function checkAllProxies(): array {
        $proxies = $this->db->getAllProxies();
        $results = [];
        
        $this->logger->info('proxy_check_all_started', [
            'proxy_count' => count($proxies),
        ]);
        
        foreach ($proxies as $proxy) {
            try {
                $result = $this->checkProxy($proxy);
            } catch (Throwable $e) {
                $this->logProxyCheckException($proxy, $e, 'check_all');
                $result = $this->buildProxyCheckExceptionResult($e);
            }
            // 过滤敏感信息后再返回
            $filteredProxy = $this->filterSensitiveData($proxy);
            $results[] = array_merge($filteredProxy, $result);
            
            // 避免过于频繁的请求
            usleep((int) config('monitoring.request_throttle_us', 10000)); // 0.01秒延迟
        }
        
        $this->logger->info('proxy_check_all_completed', [
            'proxy_count' => count($results),
        ]);
        return $results;
    }
    
    /**
     * 批量导入代理
     */
    public function importProxies(array $proxyList, string $importMode = 'skip'): array {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        
        foreach ($proxyList as $proxyData) {
            try {
                // 如果是跳过模式，先检查是否已存在
                if ($importMode === 'skip' && $this->db->proxyExists($proxyData['ip'], $proxyData['port'])) {
                    $skipped++;
                    continue;
                }
                
                if ($this->db->addProxy(
                    $proxyData['ip'],
                    $proxyData['port'],
                    $proxyData['type'],
                    $proxyData['username'] ?? null,
                    $proxyData['password'] ?? null
                )) {
                    $imported++;
                } else {
                    $errors[] = "导入失败: {$proxyData['ip']}:{$proxyData['port']}";
                }
            } catch (Exception $e) {
                $errors[] = "导入异常: {$proxyData['ip']}:{$proxyData['port']} - " . $e->getMessage();
            }
        }
        
        $this->logger->info('proxy_import_completed', [
            'imported' => $imported,
            'skipped' => $skipped,
            'error_count' => count($errors),
        ]);
        
        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }
    
    /**
     * 从文件导入代理
     * 格式: ip:port:type:username:password (每行一个)
     */
    public function importFromFile(string $filename): array {
        if (!file_exists($filename)) {
            throw new Exception("文件不存在: $filename");
        }
        
        $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $proxyList = [];
        
        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue; // 跳过空行和注释
            }
            
            $parts = explode(':', $line);
            if (count($parts) < 3) {
                $this->logger->warning('proxy_import_line_invalid_format', [
                    'line_number' => $lineNum + 1,
                    'line_content' => $line,
                ]);
                continue;
            }
            
            $ip = trim($parts[0]);
            $port = (int)trim($parts[1]);
            $type = strtolower(trim($parts[2]));
            
            // 校验IP地址合法性
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->logger->warning('proxy_import_invalid_ip', [
                    'line_number' => $lineNum + 1,
                    'proxy_ip' => $ip,
                ]);
                continue;
            }
            
            // 校验端口范围
            if ($port < 1 || $port > 65535) {
                $this->logger->warning('proxy_import_invalid_port', [
                    'line_number' => $lineNum + 1,
                    'proxy_port' => $port,
                ]);
                continue;
            }
            
            // 校验代理类型白名单
            $allowedTypes = ['http', 'https', 'socks5', 'socks4'];
            if (!in_array($type, $allowedTypes, true)) {
                $this->logger->warning('proxy_import_invalid_type', [
                    'line_number' => $lineNum + 1,
                    'proxy_type' => $type,
                    'allowed_types' => $allowedTypes,
                ]);
                continue;
            }
            
            $proxyList[] = [
                'ip' => $ip,
                'port' => $port,
                'type' => $type,
                'username' => isset($parts[3]) ? trim($parts[3]) : null,
                'password' => isset($parts[4]) ? trim($parts[4]) : null
            ];
        }
        
        return $this->importProxies($proxyList, 'add');
    }
    
    /**
     * 获取统计信息
     */
    public function getStats(): array {
        return $this->db->getProxyStats();
    }
    
    /**
     * 获取最近的日志
     */
    public function getRecentLogs(int $limit = 100): array {
        return $this->db->getRecentLogs($limit);
    }
    
    /**
     * 获取所有代理（内部使用，包含敏感信息）
     */
    public function getAllProxies(): array {
        return $this->db->getAllProxies();
    }
    
    /**
     * 获取所有代理（安全版本，不包含敏感信息）
     */
    public function getAllProxiesSafe(): array {
        $proxies = $this->db->getAllProxies();
        return array_map([$this, 'filterSensitiveData'], $proxies);
    }
    
    /**
     * 获取分页代理列表（安全版本）
     */
    public function getProxiesPaginatedSafe(int $page = 1, int $perPage = 200): array {
        $proxies = $this->db->getProxiesPaginated($page, $perPage);
        return array_map([$this, 'filterSensitiveData'], $proxies);
    }
    
    /**
     * 根据ID获取代理（内部使用，包含敏感信息）
     * @param int $id 代理ID
     * @return array|null 代理信息或null
     */
    public function getProxyById(int $id): ?array {
        return $this->db->getProxyById($id);
    }
    
    /**
     * 获取故障代理
     */
    public function getFailedProxies(): array {
        return $this->db->getFailedProxies();
    }
    
    /**
     * 添加警报
     */
    public function addAlert(int $proxyId, string $alertType, string $message): bool {
        return $this->db->addAlert($proxyId, $alertType, $message);
    }
    
    /**
     * 清理旧日志
     */
    public function cleanupOldLogs(int $days = 30): array {
        return $this->db->cleanupOldLogs($days);
    }
    
    /**
     * 添加代理
     */
    public function addProxy(string $ip, int $port, string $type, ?string $username = null, ?string $password = null): bool {
        return $this->db->addProxy($ip, $port, $type, $username, $password);
    }
    
    /**
     * 获取代理总数
     */
    public function getProxyCount(): int {
        return $this->db->getProxyCount();
    }
    
    /**
     * 过滤代理敏感信息（用户名和密码）
     */
    public function filterSensitiveData(array $proxy): array {
        $filtered = $proxy;
        // 移除敏感信息
        unset($filtered['username']);
        unset($filtered['password']);
        return $filtered;
    }
    
    /**
     * 分批检查代理
     */
    public function checkProxyBatch(int $offset = 0, int $limit = 20): array {
        $proxies = $this->db->getProxiesBatch($offset, $limit);
        $results = [];
        
        $this->logger->info('proxy_batch_check_started', [
            'offset' => $offset,
            'limit' => $limit,
            'actual_proxy_count' => count($proxies),
        ]);
        
        foreach ($proxies as $proxy) {
            try {
                $result = $this->checkProxyFast($proxy);
            } catch (Throwable $e) {
                $this->logProxyCheckException($proxy, $e, 'check_batch');
                $result = $this->buildProxyCheckExceptionResult($e);
            }
            // 过滤敏感信息后再返回
            $filteredProxy = $this->filterSensitiveData($proxy);
            $results[] = array_merge($filteredProxy, $result);
            
            // 减少延迟时间，提高批量检查速度
            usleep((int) config('monitoring.request_throttle_us', 10000)); // 0.01秒延迟，更快的批量检查
        }
        
        $this->logger->info('proxy_batch_check_completed', [
            'checked_proxy_count' => count($results),
            'offset' => $offset,
            'limit' => $limit,
        ]);
        return $results;
    }
    
    /**
     * 搜索代理（安全版本）
     * @param string $searchTerm 搜索词
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @param string $statusFilter 状态筛选
     * @return array 搜索结果
     */
    public function searchProxiesSafe(string $searchTerm, int $page = 1, int $perPage = 200, string $statusFilter = ''): array {
        $proxies = $this->db->searchProxies($searchTerm, $page, $perPage, $statusFilter);
        return array_map([$this, 'filterSensitiveData'], $proxies);
    }
    
    /**
     * 获取搜索结果总数
     * @param string $searchTerm 搜索词
     * @param string $statusFilter 状态筛选
     * @return int 搜索结果总数
     */
    public function getSearchCount(string $searchTerm, string $statusFilter = ''): int {
        return $this->db->getSearchCount($searchTerm, $statusFilter);
    }
}
