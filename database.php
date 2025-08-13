<?php
/**
 * 数据库操作类
 */

class Database {
    private $pdo;
    
    public function __construct() {
        $this->connect();
        $this->createTables();
    }
    
    private function connect() {
        try {
            // 确保数据目录存在
            $dataDir = dirname(DB_PATH);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            
            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        }
    }
    
    private function createTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS proxies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            port INTEGER NOT NULL,
            type TEXT NOT NULL,
            username TEXT,
            password TEXT,
            status TEXT DEFAULT 'unknown',
            last_check DATETIME,
            failure_count INTEGER DEFAULT 0,
            response_time REAL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS check_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proxy_id INTEGER,
            status TEXT NOT NULL,
            response_time REAL,
            error_message TEXT,
            checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (proxy_id) REFERENCES proxies (id)
        );
        
        CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proxy_id INTEGER,
            alert_type TEXT NOT NULL,
            message TEXT,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (proxy_id) REFERENCES proxies (id)
        );
        
        CREATE INDEX IF NOT EXISTS idx_proxies_status ON proxies(status);
        CREATE INDEX IF NOT EXISTS idx_check_logs_proxy_id ON check_logs(proxy_id);
        CREATE INDEX IF NOT EXISTS idx_check_logs_checked_at ON check_logs(checked_at);
        ";
        
        $this->pdo->exec($sql);
    }
    
    public function addProxy($ip, $port, $type, $username = null, $password = null) {
        $sql = "INSERT INTO proxies (ip, port, type, username, password) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([$ip, $port, $type, $username, $password]);
        
        // 清理缓存
        if ($result) {
            $this->clearProxyCountCache();
        }
        
        return $result;
    }
    
    public function proxyExists($ip, $port) {
        $sql = "SELECT COUNT(*) FROM proxies WHERE ip = ? AND port = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ip, $port]);
        return $stmt->fetchColumn() > 0;
    }
    
    public function getAllProxies() {
        $sql = "SELECT * FROM proxies ORDER BY id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getProxiesPaginated($page = 1, $perPage = 200) {
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM proxies ORDER BY id LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$perPage, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function updateProxyStatus($id, $status, $responseTime = 0, $errorMessage = null) {
        $failureCount = $status === 'online' ? 0 : null;
        
        if ($status === 'offline') {
            // 获取当前失败次数并增加
            $sql = "SELECT failure_count FROM proxies WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            $failureCount = ($current['failure_count'] ?? 0) + 1;
        }
        
        $sql = "UPDATE proxies SET status = ?, last_check = CURRENT_TIMESTAMP, response_time = ?, updated_at = CURRENT_TIMESTAMP";
        $params = [$status, $responseTime];
        
        if ($failureCount !== null) {
            $sql .= ", failure_count = ?";
            $params[] = $failureCount;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($params);
        
        // 记录检查日志
        $this->addCheckLog($id, $status, $responseTime, $errorMessage);
        
        return $result;
    }
    
    public function addCheckLog($proxyId, $status, $responseTime, $errorMessage = null) {
        $sql = "INSERT INTO check_logs (proxy_id, status, response_time, error_message) VALUES (?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$proxyId, $status, $responseTime, $errorMessage]);
    }
    
    public function getProxyStats() {
        $sql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online,
            SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline,
            SUM(CASE WHEN status = 'unknown' THEN 1 ELSE 0 END) as unknown,
            AVG(response_time) as avg_response_time
        FROM proxies
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getRecentLogs($limit = 100) {
        $sql = "
        SELECT cl.*, p.ip, p.port, p.type 
        FROM check_logs cl 
        JOIN proxies p ON cl.proxy_id = p.id 
        ORDER BY cl.checked_at DESC 
        LIMIT ?
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function addAlert($proxyId, $alertType, $message) {
        $sql = "INSERT INTO alerts (proxy_id, alert_type, message) VALUES (?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$proxyId, $alertType, $message]);
    }
    
    public function getFailedProxies() {
        $sql = "SELECT * FROM proxies WHERE failure_count >= ? ORDER BY failure_count DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([ALERT_THRESHOLD]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function cleanupOldLogs($days = 30) {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        // 清理检查日志
        $sql = "DELETE FROM check_logs WHERE checked_at < ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$cutoffDate]);
        $deletedLogs = $stmt->rowCount();
        
        // 清理警报记录
        $sql = "DELETE FROM alerts WHERE sent_at < ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$cutoffDate]);
        $deletedAlerts = $stmt->rowCount();
        
        return [
            'deleted_logs' => $deletedLogs,
            'deleted_alerts' => $deletedAlerts
        ];
    }
    
    /**
     * 更新代理认证信息
     */
    public function updateProxyAuth($id, $username, $password) {
        $sql = "UPDATE proxies SET username = ?, password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([$username, $password, $id]);
        $this->clearProxyCountCache();
        return $result;
    }
    
    /**
     * 清空所有数据（代理、日志、警报）
     */
    public function clearAllData() {
        try {
            // 删除所有相关数据
            $this->pdo->exec("DELETE FROM alerts");
            $this->pdo->exec("DELETE FROM check_logs");
            $this->pdo->exec("DELETE FROM proxies");
            
            // 重置自增ID
            $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='proxies'");
            $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='check_logs'");
            $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='alerts'");
            
            $this->clearProxyCountCache();
            
            return true;
        } catch (PDOException $e) {
            throw new Exception("清空数据失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取代理总数
     */
    public function getProxyCount() {
        // 记录开始时间
        $startTime = microtime(true);
        
        $sql = "SELECT COUNT(*) as count FROM proxies";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 记录执行时间
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        error_log("getProxyCount执行时间: {$executionTime}ms");
        
        return (int)$result['count'];
    }
    
    /**
     * 分批获取代理列表
     */
    public function getProxiesBatch($offset = 0, $limit = 20) {
        $sql = "SELECT * FROM proxies ORDER BY id LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 搜索代理
     * @param string $searchTerm 搜索词，支持IP地址或网段
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @param string $statusFilter 状态筛选
     * @return array 搜索结果
     */
    public function searchProxies($searchTerm, $page = 1, $perPage = 200, $statusFilter = '') {
        $offset = ($page - 1) * $perPage;
        
        // 构建查询条件
        $conditions = [];
        $params = [];
        
        // 处理搜索条件
        if (!empty($searchTerm)) {
            // 检查是否是网段搜索（如 1.2.3.x 或 1.2.3.）
            if (strpos($searchTerm, '.x') !== false || substr($searchTerm, -1) === '.') {
                // 网段搜索
                $networkPrefix = str_replace(['.x', 'x'], ['', ''], $searchTerm);
                $networkPrefix = rtrim($networkPrefix, '.');
                $conditions[] = "ip LIKE ?";
                $params[] = $networkPrefix . '.%';
            } else {
                // 精确IP搜索或部分匹配
                $conditions[] = "ip LIKE ?";
                $params[] = '%' . $searchTerm . '%';
            }
        }
        
        // 处理状态筛选
        if (!empty($statusFilter)) {
            $conditions[] = "status = ?";
            $params[] = $statusFilter;
        }
        
        // 构建 SQL 查询
        $sql = "SELECT * FROM proxies";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY ip, port LIMIT ? OFFSET ?";
        
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取搜索结果总数
     * @param string $searchTerm 搜索词
     * @param string $statusFilter 状态筛选
     * @return int 搜索结果总数
     */
    public function getSearchCount($searchTerm, $statusFilter = '') {
        // 构建查询条件
        $conditions = [];
        $params = [];
        
        // 处理搜索条件
        if (!empty($searchTerm)) {
            // 检查是否是网段搜索
            if (strpos($searchTerm, '.x') !== false || substr($searchTerm, -1) === '.') {
                // 网段搜索
                $networkPrefix = str_replace(['.x', 'x'], ['', ''], $searchTerm);
                $networkPrefix = rtrim($networkPrefix, '.');
                $conditions[] = "ip LIKE ?";
                $params[] = $networkPrefix . '.%';
            } else {
                // 精确IP搜索或部分匹配
                $conditions[] = "ip LIKE ?";
                $params[] = '%' . $searchTerm . '%';
            }
        }
        
        // 处理状态筛选
        if (!empty($statusFilter)) {
            $conditions[] = "status = ?";
            $params[] = $statusFilter;
        }
        
        // 构建 SQL 查询
        $sql = "SELECT COUNT(*) as count FROM proxies";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['count'];
    }
    
    /**
     * 获取离线代理列表
     * @return array 离线代理列表
     */
    public function getOfflineProxies() {
        $sql = "SELECT * FROM proxies WHERE status = 'offline' ORDER BY failure_count DESC, last_check ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取离线代理总数
     * @return int 离线代理数量
     */
    public function getOfflineProxyCount() {
        $sql = "SELECT COUNT(*) as count FROM proxies WHERE status = 'offline'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['count'];
    }
    
    /**
     * 分批获取离线代理列表
     * @param int $offset 偏移量
     * @param int $limit 限制数量
     * @return array 离线代理列表
     */
    public function getOfflineProxiesBatch($offset = 0, $limit = 20) {
        $sql = "SELECT * FROM proxies WHERE status = 'offline' ORDER BY failure_count DESC, last_check ASC LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 清理代理数量缓存
     */
    private function clearProxyCountCache() {
        $cacheFile = 'cache_proxy_count.txt';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
}
