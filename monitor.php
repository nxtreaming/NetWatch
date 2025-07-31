<?php
/**
 * 网络监控核心类
 */

// 设置时区为中国标准时间
date_default_timezone_set('Asia/Shanghai');

require_once 'config.php';
require_once 'database.php';
require_once 'logger.php';

class NetworkMonitor {
    private $db;
    private $logger;
    
    public function __construct() {
        $this->db = new Database();
        $this->logger = new Logger();
    }
    
    /**
     * 检查单个代理
     */
    public function checkProxy($proxy) {
        $startTime = microtime(true);
        $status = 'offline';
        $errorMessage = null;
        $ch = null;
        
        try {
            $ch = curl_init();
            
            // 检查curl_init是否成功
            if ($ch === false) {
                throw new Exception('curl_init() 失败');
            }
            
            // 基本curl设置
            curl_setopt_array($ch, [
                CURLOPT_URL => TEST_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'NetWatch Monitor/1.0'
            ]);
            
            // 设置代理
            if ($proxy['type'] === 'socks5') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            } else {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
            
            $proxyUrl = $proxy['ip'] . ':' . $proxy['port'];
            curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
            
            // 如果有认证信息
            if (!empty($proxy['username']) && !empty($proxy['password'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['username'] . ':' . $proxy['password']);
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            curl_close($ch);
            $ch = null; // 标记已关闭
            
            if ($response !== false && $httpCode === 200) {
                $status = 'online';
                $this->logger->info("代理 {$proxy['ip']}:{$proxy['port']} 检查成功");
            } else {
                $errorMessage = $curlError ?: "HTTP Code: $httpCode";
                $this->logger->warning("代理 {$proxy['ip']}:{$proxy['port']} 检查失败: $errorMessage");
            }
            
        } catch (Exception $e) {
            // 确保在异常情况下也关闭curl句柄
            if ($ch !== null) {
                curl_close($ch);
                $ch = null;
            }
            $errorMessage = $e->getMessage();
            $this->logger->error("代理 {$proxy['ip']}:{$proxy['port']} 检查异常: $errorMessage");
        }
        
        $responseTime = (microtime(true) - $startTime) * 1000; // 转换为毫秒
        
        // 更新数据库
        $this->db->updateProxyStatus($proxy['id'], $status, $responseTime, $errorMessage);
        
        return [
            'status' => $status,
            'response_time' => $responseTime,
            'error_message' => $errorMessage
        ];
    }
    
    /**
     * 快速检查单个代理（用于批量检查，更短的超时时间）
     */
    public function checkProxyFast($proxy) {
        $startTime = microtime(true);
        $status = 'offline';
        $errorMessage = null;
        $ch = null;
        
        try {
            $ch = curl_init();
            
            // 检查curl_init是否成功
            if ($ch === false) {
                throw new Exception('curl_init() 失败');
            }
            
            // 基本curl设置，使用更短的超时时间
            curl_setopt_array($ch, [
                CURLOPT_URL => TEST_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3, // 批量检查时使用3秒超时
                CURLOPT_CONNECTTIMEOUT => 2, // 连接超时2秒
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'NetWatch Monitor/1.0'
            ]);
            
            // 设置代理
            if ($proxy['type'] === 'socks5') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            } else {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
            
            $proxyUrl = $proxy['ip'] . ':' . $proxy['port'];
            curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
            
            // 如果有认证信息
            if (!empty($proxy['username']) && !empty($proxy['password'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['username'] . ':' . $proxy['password']);
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            curl_close($ch);
            $ch = null; // 标记已关闭
            
            if ($response !== false && $httpCode === 200) {
                $status = 'online';
                $this->logger->info("代理 {$proxy['ip']}:{$proxy['port']} 快速检查成功");
            } else {
                $errorMessage = $curlError ?: "HTTP Code: $httpCode";
                $this->logger->warning("代理 {$proxy['ip']}:{$proxy['port']} 快速检查失败: $errorMessage");
            }
            
        } catch (Exception $e) {
            // 确保在异常情况下也关闭curl句柄
            if ($ch !== null) {
                curl_close($ch);
                $ch = null;
            }
            $errorMessage = $e->getMessage();
            $this->logger->error("代理 {$proxy['ip']}:{$proxy['port']} 快速检查异常: $errorMessage");
        }
        
        $responseTime = (microtime(true) - $startTime) * 1000; // 转换为毫秒
        
        // 更新数据库
        $this->db->updateProxyStatus($proxy['id'], $status, $responseTime, $errorMessage);
        
        return [
            'status' => $status,
            'response_time' => $responseTime,
            'error_message' => $errorMessage
        ];
    }
    
    /**
     * 检查所有代理
     */
    public function checkAllProxies() {
        $proxies = $this->db->getAllProxies();
        $results = [];
        
        $this->logger->info("开始检查 " . count($proxies) . " 个代理");
        
        foreach ($proxies as $proxy) {
            $result = $this->checkProxy($proxy);
            // 过滤敏感信息后再返回
            $filteredProxy = $this->filterSensitiveData($proxy);
            $results[] = array_merge($filteredProxy, $result);
            
            // 避免过于频繁的请求
            usleep(100000); // 0.1秒延迟
        }
        
        $this->logger->info("代理检查完成");
        return $results;
    }
    
    /**
     * 批量导入代理
     */
    public function importProxies($proxyList, $importMode = 'skip') {
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
        
        $this->logger->info("导入完成: 成功 $imported 个，跳过 $skipped 个，失败 " . count($errors) . " 个");
        
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
    public function importFromFile($filename) {
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
                $this->logger->warning("第 " . ($lineNum + 1) . " 行格式错误: $line");
                continue;
            }
            
            $proxyList[] = [
                'ip' => $parts[0],
                'port' => (int)$parts[1],
                'type' => $parts[2],
                'username' => $parts[3] ?? null,
                'password' => $parts[4] ?? null
            ];
        }
        
        return $this->importProxies($proxyList, 'add');
    }
    
    /**
     * 获取统计信息
     */
    public function getStats() {
        return $this->db->getProxyStats();
    }
    
    /**
     * 获取最近的日志
     */
    public function getRecentLogs($limit = 100) {
        return $this->db->getRecentLogs($limit);
    }
    
    /**
     * 获取所有代理（内部使用，包含敏感信息）
     */
    public function getAllProxies() {
        return $this->db->getAllProxies();
    }
    
    /**
     * 获取所有代理（安全版本，不包含敏感信息）
     */
    public function getAllProxiesSafe() {
        $proxies = $this->db->getAllProxies();
        return array_map([$this, 'filterSensitiveData'], $proxies);
    }
    
    /**
     * 获取分页代理列表（安全版本）
     */
    public function getProxiesPaginatedSafe($page = 1, $perPage = 200) {
        $proxies = $this->db->getProxiesPaginated($page, $perPage);
        return array_map([$this, 'filterSensitiveData'], $proxies);
    }
    
    /**
     * 根据ID获取代理（内部使用，包含敏感信息）
     */
    public function getProxyById($id) {
        $proxies = $this->db->getAllProxies();
        foreach ($proxies as $proxy) {
            if ($proxy['id'] == $id) {
                return $proxy;
            }
        }
        return null;
    }
    
    /**
     * 获取故障代理
     */
    public function getFailedProxies() {
        return $this->db->getFailedProxies();
    }
    
    /**
     * 添加警报
     */
    public function addAlert($proxyId, $alertType, $message) {
        return $this->db->addAlert($proxyId, $alertType, $message);
    }
    
    /**
     * 清理旧日志
     */
    public function cleanupOldLogs($days = 30) {
        return $this->db->cleanupOldLogs($days);
    }
    
    /**
     * 添加代理
     */
    public function addProxy($ip, $port, $type, $username = null, $password = null) {
        return $this->db->addProxy($ip, $port, $type, $username, $password);
    }
    
    /**
     * 获取代理总数
     */
    public function getProxyCount() {
        return $this->db->getProxyCount();
    }
    
    /**
     * 过滤代理敏感信息（用户名和密码）
     */
    private function filterSensitiveData($proxy) {
        $filtered = $proxy;
        // 移除敏感信息
        unset($filtered['username']);
        unset($filtered['password']);
        return $filtered;
    }
    
    /**
     * 分批检查代理
     */
    public function checkProxyBatch($offset = 0, $limit = 20) {
        $proxies = $this->db->getProxiesBatch($offset, $limit);
        $results = [];
        
        $this->logger->info("开始分批检查代理: offset=$offset, limit=$limit, 实际获取 " . count($proxies) . " 个代理");
        
        foreach ($proxies as $proxy) {
            $result = $this->checkProxyFast($proxy);
            // 过滤敏感信息后再返回
            $filteredProxy = $this->filterSensitiveData($proxy);
            $results[] = array_merge($filteredProxy, $result);
            
            // 减少延迟时间，提高批量检查速度
            usleep(10000); // 0.01秒延迟，更快的批量检查
        }
        
        $this->logger->info("分批检查完成: 检查了 " . count($results) . " 个代理");
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
    public function searchProxiesSafe($searchTerm, $page = 1, $perPage = 200, $statusFilter = '') {
        $proxies = $this->db->searchProxies($searchTerm, $page, $perPage, $statusFilter);
        return array_map([$this, 'filterSensitiveData'], $proxies);
    }
    
    /**
     * 获取搜索结果总数
     * @param string $searchTerm 搜索词
     * @param string $statusFilter 状态筛选
     * @return int 搜索结果总数
     */
    public function getSearchCount($searchTerm, $statusFilter = '') {
        return $this->db->getSearchCount($searchTerm, $statusFilter);
    }
}
