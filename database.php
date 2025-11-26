<?php
/**
 * 数据库操作类
 */

class Database {
    private $pdo;
    private static $tablesCreated = false;
    
    public function __construct() {
        $this->connect();
        // 只在首次实例化时创建表，避免重复检查
        if (!self::$tablesCreated) {
            $this->createTables();
            self::$tablesCreated = true;
        }
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
        
        CREATE TABLE IF NOT EXISTS api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            proxy_count INTEGER NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active INTEGER DEFAULT 1
        );
        
        CREATE TABLE IF NOT EXISTS token_proxy_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_id INTEGER NOT NULL,
            proxy_id INTEGER NOT NULL,
            assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE CASCADE,
            FOREIGN KEY (proxy_id) REFERENCES proxies (id) ON DELETE CASCADE,
            UNIQUE(token_id, proxy_id)
        );
        
        CREATE TABLE IF NOT EXISTS traffic_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            total_bandwidth REAL DEFAULT 0,
            used_bandwidth REAL DEFAULT 0,
            remaining_bandwidth REAL DEFAULT 0,
            daily_usage REAL DEFAULT 0,
            usage_date DATE NOT NULL,
            recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(usage_date)
        );
        
        CREATE TABLE IF NOT EXISTS traffic_realtime (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            total_bandwidth REAL DEFAULT 0,
            used_bandwidth REAL DEFAULT 0,
            remaining_bandwidth REAL DEFAULT 0,
            usage_percentage REAL DEFAULT 0,
            rx_bytes REAL DEFAULT 0,
            tx_bytes REAL DEFAULT 0,
            port INTEGER DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS traffic_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            snapshot_date DATE NOT NULL,
            snapshot_time TIME NOT NULL,
            rx_bytes REAL DEFAULT 0,
            tx_bytes REAL DEFAULT 0,
            total_bytes REAL DEFAULT 0,
            recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(snapshot_date, snapshot_time)
        );
        
        -- 代理表索引优化
        CREATE INDEX IF NOT EXISTS idx_proxies_status ON proxies(status);
        CREATE INDEX IF NOT EXISTS idx_proxies_ip ON proxies(ip);
        CREATE INDEX IF NOT EXISTS idx_proxies_updated_at ON proxies(updated_at);
        CREATE INDEX IF NOT EXISTS idx_proxies_ip_port ON proxies(ip, port);
        CREATE INDEX IF NOT EXISTS idx_proxies_status_response ON proxies(status, response_time);
        CREATE INDEX IF NOT EXISTS idx_proxies_failure_count ON proxies(failure_count);
        
        -- 检查日志索引
        CREATE INDEX IF NOT EXISTS idx_check_logs_proxy_id ON check_logs(proxy_id);
        CREATE INDEX IF NOT EXISTS idx_check_logs_checked_at ON check_logs(checked_at);
        CREATE INDEX IF NOT EXISTS idx_check_logs_proxy_checked ON check_logs(proxy_id, checked_at);
        
        -- API Token索引
        CREATE INDEX IF NOT EXISTS idx_api_tokens_token ON api_tokens(token);
        CREATE INDEX IF NOT EXISTS idx_api_tokens_expires ON api_tokens(expires_at);
        CREATE INDEX IF NOT EXISTS idx_token_assignments_token ON token_proxy_assignments(token_id);
        
        -- 流量统计索引
        CREATE INDEX IF NOT EXISTS idx_traffic_stats_date ON traffic_stats(usage_date);
        CREATE INDEX IF NOT EXISTS idx_traffic_realtime_updated ON traffic_realtime(updated_at);
        CREATE INDEX IF NOT EXISTS idx_traffic_snapshots_date ON traffic_snapshots(snapshot_date);
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
    
    /**
     * 根据ID获取单个代理（高效直接查询）
     * @param int $id 代理ID
     * @return array|null 代理信息或null
     */
    public function getProxyById(int $id): ?array {
        $sql = "SELECT * FROM proxies WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
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
     * 构建搜索条件（公共方法，消除代码重复）
     * @param string $searchTerm 搜索词
     * @param string $statusFilter 状态筛选
     * @return array ['conditions' => [], 'params' => []]
     */
    private function buildSearchConditions(string $searchTerm, string $statusFilter = ''): array {
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
        
        return ['conditions' => $conditions, 'params' => $params];
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
        
        // 使用公共方法构建条件
        $result = $this->buildSearchConditions($searchTerm, $statusFilter);
        $conditions = $result['conditions'];
        $params = $result['params'];
        
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
        // 使用公共方法构建条件
        $result = $this->buildSearchConditions($searchTerm, $statusFilter);
        $conditions = $result['conditions'];
        $params = $result['params'];
        
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

    // ==================== API Token 管理方法 ====================
    
    /**
     * 创建API Token
     */
    public function createApiToken($name, $proxyCount, $expiresAt) {
        $token = $this->generateUniqueToken();
        $sql = "INSERT INTO api_tokens (token, name, proxy_count, expires_at) VALUES (?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([$token, $name, $proxyCount, $expiresAt]);
        
        if ($result) {
            $tokenId = $this->pdo->lastInsertId();
            $this->assignProxiesToToken($tokenId, $proxyCount);
            return $token;
        }
        return false;
    }
    
    /**
     * 生成唯一Token
     */
    private function generateUniqueToken() {
        do {
            $token = bin2hex(random_bytes(32));
            $sql = "SELECT COUNT(*) FROM api_tokens WHERE token = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$token]);
            $exists = $stmt->fetchColumn() > 0;
        } while ($exists);
        
        return $token;
    }
    
    /**
     * 为Token分配代理
     */
    private function assignProxiesToToken($tokenId, $proxyCount) {
        // 获取在线代理，按响应时间排序
        $sql = "SELECT id FROM proxies WHERE status = 'online' ORDER BY response_time ASC, failure_count ASC LIMIT ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$proxyCount]);
        $proxies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 如果在线代理不够，补充未知状态的代理
        if (count($proxies) < $proxyCount) {
            $remainingCount = $proxyCount - count($proxies);
            $existingIds = array_column($proxies, 'id');
            $placeholders = str_repeat('?,', count($existingIds));
            $placeholders = rtrim($placeholders, ',');
            
            $sql = "SELECT id FROM proxies WHERE status = 'unknown'";
            if (!empty($existingIds)) {
                $sql .= " AND id NOT IN ($placeholders)";
            }
            $sql .= " ORDER BY created_at ASC LIMIT ?";
            
            $stmt = $this->pdo->prepare($sql);
            $params = array_merge($existingIds, [$remainingCount]);
            $stmt->execute($params);
            $additionalProxies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $proxies = array_merge($proxies, $additionalProxies);
        }
        
        // 分配代理到Token
        foreach ($proxies as $proxy) {
            $sql = "INSERT INTO token_proxy_assignments (token_id, proxy_id) VALUES (?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tokenId, $proxy['id']]);
        }
    }
    
    /**
     * 获取所有Token列表
     */
    public function getAllTokens() {
        $sql = "SELECT t.*, 
                       COUNT(a.proxy_id) as assigned_count,
                       CASE WHEN t.expires_at > datetime('now') THEN 1 ELSE 0 END as is_valid
                FROM api_tokens t 
                LEFT JOIN token_proxy_assignments a ON t.id = a.token_id 
                WHERE t.is_active = 1 
                GROUP BY t.id 
                ORDER BY t.created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 根据Token获取Token信息
     */
    public function getTokenByValue($token) {
        $sql = "SELECT * FROM api_tokens WHERE token = ? AND is_active = 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 验证Token是否有效
     */
    public function validateToken($token) {
        $sql = "SELECT * FROM api_tokens WHERE token = ? AND is_active = 1 AND expires_at > datetime('now')";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取Token分配的代理列表
     */
    public function getTokenProxies($tokenId) {
        $sql = "SELECT p.* FROM proxies p 
                INNER JOIN token_proxy_assignments a ON p.id = a.proxy_id 
                WHERE a.token_id = ? 
                ORDER BY p.response_time ASC, p.failure_count ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tokenId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 刷新Token有效期
     */
    public function refreshToken($tokenId, $newExpiresAt) {
        $sql = "UPDATE api_tokens SET expires_at = ?, updated_at = datetime('now') WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$newExpiresAt, $tokenId]);
    }
    
    /**
     * 删除Token
     */
    public function deleteToken($tokenId) {
        $sql = "UPDATE api_tokens SET is_active = 0, updated_at = datetime('now') WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$tokenId]);
    }
    
    /**
     * 重新分配Token的代理
     */
    public function reassignTokenProxies($tokenId, $proxyCount) {
        // 删除现有分配
        $sql = "DELETE FROM token_proxy_assignments WHERE token_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tokenId]);
        
        // 重新分配
        $this->assignProxiesToToken($tokenId, $proxyCount);
        
        // 更新Token信息
        $sql = "UPDATE api_tokens SET proxy_count = ?, updated_at = datetime('now') WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$proxyCount, $tokenId]);
    }
    
    /**
     * 保存实时流量数据
     */
    public function saveRealtimeTraffic($totalBandwidth, $usedBandwidth, $remainingBandwidth, $usagePercentage, $rxBytes = 0, $txBytes = 0, $port = 0) {
        // 删除旧数据，只保留最新的一条
        $sql = "DELETE FROM traffic_realtime";
        $this->pdo->exec($sql);
        
        // 插入新数据
        $sql = "INSERT INTO traffic_realtime (total_bandwidth, used_bandwidth, remaining_bandwidth, usage_percentage, rx_bytes, tx_bytes, port, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$totalBandwidth, $usedBandwidth, $remainingBandwidth, $usagePercentage, $rxBytes, $txBytes, $port]);
    }
    
    /**
     * 获取最新的实时流量数据
     */
    public function getRealtimeTraffic() {
        $sql = "SELECT * FROM traffic_realtime ORDER BY updated_at DESC LIMIT 1";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 保存每日流量统计
     * 注意：只更新指定日期的数据，不影响其他日期
     */
    public function saveDailyTrafficStats($date, $totalBandwidth, $usedBandwidth, $remainingBandwidth, $dailyUsage) {
        // 检查是否已存在该日期的记录
        $existing = $this->getDailyTrafficStats($date);
        
        if ($existing) {
            // 更新现有记录
            $sql = "UPDATE traffic_stats 
                    SET total_bandwidth = ?, 
                        used_bandwidth = ?, 
                        remaining_bandwidth = ?, 
                        daily_usage = ?, 
                        recorded_at = datetime('now') 
                    WHERE usage_date = ?";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([$totalBandwidth, $usedBandwidth, $remainingBandwidth, $dailyUsage, $date]);
        } else {
            // 插入新记录
            $sql = "INSERT INTO traffic_stats (usage_date, total_bandwidth, used_bandwidth, remaining_bandwidth, daily_usage, recorded_at) 
                    VALUES (?, ?, ?, ?, ?, datetime('now'))";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([$date, $totalBandwidth, $usedBandwidth, $remainingBandwidth, $dailyUsage]);
        }
    }
    
    /**
     * 获取指定日期的流量统计
     */
    public function getDailyTrafficStats($date) {
        $sql = "SELECT * FROM traffic_stats WHERE usage_date = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 更新指定日期的已用流量（用于流量重置时回溯更新）
     */
    public function updateUsedBandwidth($date, $usedBandwidth) {
        $sql = "UPDATE traffic_stats 
                SET used_bandwidth = ?, 
                    recorded_at = datetime('now') 
                WHERE usage_date = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$usedBandwidth, $date]);
    }
    
    /**
     * 获取最近N天的流量统计
     */
    public function getRecentTrafficStats($days = 30) {
        $sql = "SELECT * FROM traffic_stats ORDER BY usage_date DESC LIMIT ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取指定日期范围的流量统计
     * @param string $startDate 开始日期 (Y-m-d)
     * @param string $endDate 结束日期 (Y-m-d)
     * @return array
     */
    public function getTrafficStatsByDateRange($startDate, $endDate) {
        $sql = "SELECT * FROM traffic_stats 
                WHERE usage_date >= ? AND usage_date <= ? 
                ORDER BY usage_date DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 计算今日流量使用量
     */
    public function calculateDailyUsage($date) {
        // 获取今天和昨天的数据
        $todayData = $this->getDailyTrafficStats($date);
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $yesterdayData = $this->getDailyTrafficStats($yesterday);
        
        if ($todayData && $yesterdayData) {
            // 检测流量是否被重置：如果今天的累计流量比昨天少，说明流量被重置了
            if ($todayData['used_bandwidth'] < $yesterdayData['used_bandwidth']) {
                // 流量被重置，直接返回今天的累计使用量
                return $todayData['used_bandwidth'];
            }
            
            // 正常情况：今日使用量 = 今天已用 - 昨天已用
            return $todayData['used_bandwidth'] - $yesterdayData['used_bandwidth'];
        } elseif ($todayData) {
            // 如果没有昨天的数据，返回今天的已用量
            return $todayData['used_bandwidth'];
        }
        
        return 0;
    }
    
    /**
     * 保存流量快照（每5分钟一次）
     * 只在当前分钟是5的倍数时保存，避免产生非5分钟间隔的数据
     */
    public function saveTrafficSnapshot($rxBytes, $txBytes) {
        $date = date('Y-m-d');
        $currentMinute = intval(date('i'));
        
        // 只在5分钟倍数时保存快照（0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55）
        if ($currentMinute % 5 !== 0) {
            return true; // 不是5分钟倍数，跳过保存，返回true表示"成功"（不报错）
        }
        
        $time = date('H:i:00'); // 取整到分钟
        $totalBytes = $rxBytes + $txBytes;
        
        $sql = "INSERT OR REPLACE INTO traffic_snapshots (snapshot_date, snapshot_time, rx_bytes, tx_bytes, total_bytes, recorded_at) 
                VALUES (?, ?, ?, ?, ?, datetime('now'))";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$date, $time, $rxBytes, $txBytes, $totalBytes]);
    }
    
    /**
     * 获取指定日期的流量快照数据（只返回5分钟倍数的时间点）
     */
    public function getTrafficSnapshotsByDate($date) {
        $sql = "SELECT snapshot_time, rx_bytes, tx_bytes, total_bytes, recorded_at 
                FROM traffic_snapshots 
                WHERE snapshot_date = ? 
                AND (
                    substr(snapshot_time, 4, 2) IN ('00', '05', '10', '15', '20', '25', '30', '35', '40', '45', '50', '55')
                )
                ORDER BY snapshot_time ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取今日的流量快照数据
     */
    public function getTodayTrafficSnapshots() {
        $today = date('Y-m-d');
        return $this->getTrafficSnapshotsByDate($today);
    }
    
    /**
     * 获取指定日期的最后一个快照（通常是23:55）
     * 用于计算跨日流量时的基准点
     */
    public function getLastSnapshotOfDay($date) {
        $sql = "SELECT snapshot_time, rx_bytes, tx_bytes, total_bytes, recorded_at 
                FROM traffic_snapshots 
                WHERE snapshot_date = ? 
                ORDER BY snapshot_time DESC 
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 清理过期的流量快照（保留最近7天）
     */
    public function cleanOldTrafficSnapshots($daysToKeep = 7) {
        $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));
        $sql = "DELETE FROM traffic_snapshots WHERE snapshot_date < ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$cutoffDate]);
    }
}
