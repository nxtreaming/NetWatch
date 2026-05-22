<?php
declare(strict_types=1);
/**
 * 数据库操作类
 */

require_once __DIR__ . '/includes/Exceptions.php';
require_once __DIR__ . '/includes/Migration.php';
require_once __DIR__ . '/includes/DatabaseConnection.php';
require_once __DIR__ . '/includes/TokenRepository.php';
require_once __DIR__ . '/includes/TrafficRepository.php';
require_once __DIR__ . '/includes/ProxyRepository.php';
require_once __DIR__ . '/includes/AuditLogRepository.php';

class Database {
    private $pdo;
    private static $tablesCreated = false;
    private int $busyTimeoutMs = 15000;
    private ?TokenRepository $tokenRepository = null;
    private ?TrafficRepository $trafficRepository = null;
    private ?ProxyRepository $proxyRepository = null;
    private ?AuditLogRepository $auditLogRepository = null;
    
    public function __construct() {
        $this->connect();
    }

    public function initializeSchema(): void {
        $this->ensureConnection();
        if (self::$tablesCreated) {
            return;
        }

        $this->createTables();
        $this->runPendingMigrations();
        self::$tablesCreated = true;
    }
    
    private function connect() {
        try {
            // 确保数据目录存在
            $dataDir = dirname(DB_PATH);
            if (!is_dir($dataDir)) {
                if (!mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
                    $lastError = error_get_last();
                    throw new DatabaseException('无法创建数据库目录', 500, null, [
                        'dir' => $dataDir,
                        'error' => is_array($lastError) ? ($lastError['message'] ?? '未知错误') : '未知错误'
                    ]);
                }
            }

            if (!is_readable($dataDir) || !is_writable($dataDir)) {
                throw new DatabaseException('数据库目录权限不足（需可读可写）', 500, null, [
                    'dir' => $dataDir,
                ]);
            }
            $this->warnIfWorldWritablePath($dataDir, 'database_dir');
            
            $this->pdo = DatabaseConnection::createSqlite(DB_PATH);
            $this->tokenRepository = null;
            $this->trafficRepository = null;
            $this->proxyRepository = null;
            $this->auditLogRepository = null;
            $this->applyConnectionPragmas();

            if (is_file(DB_PATH)) {
                $this->warnIfWorldWritablePath(DB_PATH, 'database_file');
            }
        } catch (PDOException $e) {
            throw new DatabaseException('数据库连接失败', 500, $e, [
                'db_path' => DB_PATH
            ]);
        }
    }

    private function applyConnectionPragmas(): void {
        $this->busyTimeoutMs = $this->resolveBusyTimeoutMs();

        // SQLite 连接与锁等待窗口（秒）
        $this->pdo->setAttribute(PDO::ATTR_TIMEOUT, max(1, (int) ceil($this->busyTimeoutMs / 1000)));
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA busy_timeout=' . $this->busyTimeoutMs);
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
    }

    private function resolveBusyTimeoutMs(): int {
        $timeout = defined('DB_BUSY_TIMEOUT_MS') ? (int) DB_BUSY_TIMEOUT_MS : 15000;
        return max(1000, $timeout);
    }

    private function ensureConnection(): void {
        if (!($this->pdo instanceof PDO)) {
            $this->connect();
            return;
        }

        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            $this->connect();
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

        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            action TEXT NOT NULL,
            target_type TEXT,
            target_id TEXT,
            details TEXT,
            ip_address TEXT,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
        CREATE INDEX IF NOT EXISTS idx_proxies_status_failure ON proxies(status, failure_count);
        
        -- 检查日志索引
        CREATE INDEX IF NOT EXISTS idx_check_logs_proxy_id ON check_logs(proxy_id);
        CREATE INDEX IF NOT EXISTS idx_check_logs_checked_at ON check_logs(checked_at);
        CREATE INDEX IF NOT EXISTS idx_check_logs_proxy_checked ON check_logs(proxy_id, checked_at);
        CREATE INDEX IF NOT EXISTS idx_check_logs_status_time ON check_logs(status, checked_at);
        
        -- API Token索引
        CREATE INDEX IF NOT EXISTS idx_api_tokens_token ON api_tokens(token);
        CREATE INDEX IF NOT EXISTS idx_api_tokens_expires ON api_tokens(expires_at);
        CREATE INDEX IF NOT EXISTS idx_token_assignments_token ON token_proxy_assignments(token_id);

        -- 审计日志索引
        CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at);
        CREATE INDEX IF NOT EXISTS idx_audit_logs_username ON audit_logs(username);
        CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action);
        
        -- 流量统计索引
        CREATE INDEX IF NOT EXISTS idx_traffic_stats_date ON traffic_stats(usage_date);
        CREATE INDEX IF NOT EXISTS idx_traffic_realtime_updated ON traffic_realtime(updated_at);
        CREATE INDEX IF NOT EXISTS idx_traffic_snapshots_date ON traffic_snapshots(snapshot_date);
        ";
        
        $statements = preg_split('/;\s*(?:\R|$)/', $sql);

        foreach ($statements as $index => $statement) {
            $statement = trim((string) $statement);
            if ($statement === '') {
                continue;
            }

            try {
                $this->pdo->exec($statement);
            } catch (PDOException $e) {
                $preview = preg_replace('/\s+/', ' ', $statement);
                $preview = $preview === null ? '' : substr($preview, 0, 120);

                throw new DatabaseException('数据库表结构初始化失败', 500, $e, [
                    'statement_index' => $index + 1,
                    'statement_preview' => $preview
                ]);
            }
        }
    }

    private function runPendingMigrations(): void {
        $migrationsDir = __DIR__ . '/migrations';
        if (!is_dir($migrationsDir)) {
            return;
        }

        try {
            $migration = new Migration($this->pdo, $migrationsDir);
            $migration->migrate();
        } catch (Throwable $e) {
            throw new DatabaseException('数据库迁移执行失败', 500, $e, [
                'migrations_dir' => $migrationsDir
            ]);
        }
    }

    public function addAuditLog($username, $action, $targetType = null, $targetId = null, $details = null, $ipAddress = null, $userAgent = null) {
        $this->ensureConnection();
        return $this->auditLogRepository()->addAuditLog($username, $action, $targetType, $targetId, $details, $ipAddress, $userAgent);
    }
    
    public function addProxy($ip, $port, $type, $username = null, $password = null) {
        $this->ensureConnection();
        return $this->proxyRepository()->addProxy($ip, $port, $type, $username, $password);
    }
    
    public function proxyExists($ip, $port): bool {
        $this->ensureConnection();
        return $this->proxyRepository()->proxyExists($ip, $port);
    }
    
    public function getAllProxies(): array {
        $this->ensureConnection();
        return $this->proxyRepository()->getAllProxies();
    }
    
    public function getProxiesPaginated($page = 1, $perPage = 200) {
        $this->ensureConnection();
        return $this->proxyRepository()->getProxiesPaginated((int) $page, (int) $perPage);
    }
    
    /**
     * 根据ID获取单个代理（高效直接查询）
     * @param int $id 代理ID
     * @return array|null 代理信息或null
     */
    public function getProxyById(int $id): ?array {
        $this->ensureConnection();
        return $this->proxyRepository()->getProxyById($id);
    }
    
    public function updateProxyStatus($id, $status, $responseTime = 0, $errorMessage = null) {
        $this->ensureConnection();
        return $this->proxyRepository()->updateProxyStatus($id, $status, $responseTime, $errorMessage);
    }
    
    public function addCheckLog($proxyId, $status, $responseTime, $errorMessage = null) {
        $this->ensureConnection();
        return $this->proxyRepository()->addCheckLog($proxyId, $status, $responseTime, $errorMessage);
    }
    
    public function getProxyStats() {
        $this->ensureConnection();
        return $this->proxyRepository()->getProxyStats();
    }
    
    public function getRecentLogs($limit = 100) {
        $this->ensureConnection();
        return $this->proxyRepository()->getRecentLogs((int) $limit);
    }
    
    public function addAlert($proxyId, $alertType, $message) {
        $this->ensureConnection();
        return $this->proxyRepository()->addAlert($proxyId, $alertType, $message);
    }
    
    public function getFailedProxies() {
        $this->ensureConnection();
        return $this->proxyRepository()->getFailedProxies();
    }
    
    public function cleanupOldLogs($days = 30) {
        $this->ensureConnection();
        return $this->proxyRepository()->cleanupOldLogs((int) $days);
    }
    
    /**
     * 更新代理认证信息
     */
    public function updateProxyAuth($id, $username, $password) {
        $this->ensureConnection();
        return $this->proxyRepository()->updateProxyAuth($id, $username, $password);
    }
    
    /**
     * 清空所有数据（代理、日志、警报）
     */
    public function clearAllData() {
        $this->ensureConnection();
        return $this->proxyRepository()->clearAllData();
    }
    
    /**
     * 获取代理总数
     */
    public function getProxyCount() {
        $this->ensureConnection();
        return $this->proxyRepository()->getProxyCount();
    }
    
    /**
     * 分批获取代理列表
     */
    public function getProxiesBatch($offset = 0, $limit = 20) {
        $this->ensureConnection();
        return $this->proxyRepository()->getProxiesBatch((int) $offset, (int) $limit);
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
        $this->ensureConnection();
        return $this->proxyRepository()->searchProxies($searchTerm, (int) $page, (int) $perPage, (string) $statusFilter);
    }
    
    /**
     * 获取搜索结果总数
     * @param string $searchTerm 搜索词
     * @param string $statusFilter 状态筛选
     * @return int 搜索结果总数
     */
    public function getSearchCount($searchTerm, $statusFilter = '') {
        $this->ensureConnection();
        return $this->proxyRepository()->getSearchCount($searchTerm, (string) $statusFilter);
    }
    
    /**
     * 获取离线代理列表
     * @return array 离线代理列表
     */
    public function getOfflineProxies() {
        $this->ensureConnection();
        return $this->proxyRepository()->getOfflineProxies();
    }
    
    /**
     * 获取离线代理总数
     * @return int 离线代理数量
     */
    public function getOfflineProxyCount() {
        $this->ensureConnection();
        return $this->proxyRepository()->getOfflineProxyCount();
    }
    
    /**
     * 分批获取离线代理列表
     * @param int $offset 偏移量
     * @param int $limit 限制数量
     * @return array 离线代理列表
     */
    public function getOfflineProxiesBatch($offset = 0, $limit = 20) {
        $this->ensureConnection();
        return $this->proxyRepository()->getOfflineProxiesBatch((int) $offset, (int) $limit);
    }

    private function warnIfWorldWritablePath(string $path, string $type): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $perms = fileperms($path);
        if ($perms === false) {
            return;
        }

        if (($perms & 0x0002) === 0x0002) {
            error_log('[NetWatch][SECURITY] ' . $type . ' is world-writable: ' . $path);
        }
    }

    private function tokenRepository(): TokenRepository {
        if (!($this->tokenRepository instanceof TokenRepository)) {
            $this->tokenRepository = new TokenRepository($this->pdo);
        }

        return $this->tokenRepository;
    }

    private function trafficRepository(): TrafficRepository {
        if (!($this->trafficRepository instanceof TrafficRepository)) {
            $this->trafficRepository = new TrafficRepository($this->pdo);
        }

        return $this->trafficRepository;
    }

    private function proxyRepository(): ProxyRepository {
        if (!($this->proxyRepository instanceof ProxyRepository)) {
            $this->proxyRepository = new ProxyRepository($this->pdo);
        }

        return $this->proxyRepository;
    }

    private function auditLogRepository(): AuditLogRepository {
        if (!($this->auditLogRepository instanceof AuditLogRepository)) {
            $this->auditLogRepository = new AuditLogRepository($this->pdo);
        }

        return $this->auditLogRepository;
    }

    // ==================== API Token 管理方法 ====================
    
    /**
     * 创建API Token
     */
    public function createApiToken($name, $proxyCount, $expiresAt) {
        $this->ensureConnection();
        return $this->tokenRepository()->createApiToken((string) $name, (int) $proxyCount, (string) $expiresAt);
    }
    
    /**
     * 获取所有Token列表
     */
    public function getAllTokens() {
        $this->ensureConnection();
        return $this->tokenRepository()->getAllTokens();
    }
    
    /**
     * 根据Token获取Token信息
     */
    public function getTokenByValue($token) {
        $this->ensureConnection();
        return $this->tokenRepository()->getTokenByValue((string) $token);
    }
    
    /**
     * 验证Token是否有效
     */
    public function validateToken($token) {
        $this->ensureConnection();
        return $this->tokenRepository()->validateToken((string) $token);
    }
    
    /**
     * 获取Token分配的代理列表
     */
    public function getTokenProxies($tokenId) {
        $this->ensureConnection();
        return $this->tokenRepository()->getTokenProxies((int) $tokenId);
    }
    
    /**
     * 刷新Token有效期
     */
    public function refreshToken($tokenId, $newExpiresAt) {
        $this->ensureConnection();
        return $this->tokenRepository()->refreshToken((int) $tokenId, (string) $newExpiresAt);
    }
    
    /**
     * 删除Token
     */
    public function deleteToken($tokenId) {
        $this->ensureConnection();
        return $this->tokenRepository()->deleteToken((int) $tokenId);
    }
    
    /**
     * 重新分配Token的代理
     */
    public function reassignTokenProxies($tokenId, $proxyCount) {
        $this->ensureConnection();
        return $this->tokenRepository()->reassignTokenProxies((int) $tokenId, (int) $proxyCount);
    }
    
    /**
     * 保存实时流量数据
     */
    public function saveRealtimeTraffic($totalBandwidth, $usedBandwidth, $remainingBandwidth, $usagePercentage, $rxBytes = 0, $txBytes = 0, $port = 0) {
        $this->ensureConnection();
        return $this->trafficRepository()->saveRealtimeTraffic(
            (float) $totalBandwidth,
            (float) $usedBandwidth,
            (float) $remainingBandwidth,
            (float) $usagePercentage,
            (float) $rxBytes,
            (float) $txBytes,
            (int) $port
        );
    }
    
    /**
     * 获取最新的实时流量数据
     */
    public function getRealtimeTraffic() {
        $this->ensureConnection();
        return $this->trafficRepository()->getRealtimeTraffic();
    }
    
    /**
     * 保存每日流量统计
     * 注意：只更新指定日期的数据，不影响其他日期
     */
    public function saveDailyTrafficStats($date, $totalBandwidth, $usedBandwidth, $remainingBandwidth, $dailyUsage) {
        $this->ensureConnection();
        return $this->trafficRepository()->saveDailyTrafficStats(
            (string) $date,
            (float) $totalBandwidth,
            (float) $usedBandwidth,
            (float) $remainingBandwidth,
            (float) $dailyUsage
        );
    }
    
    /**
     * 获取指定日期的流量统计
     */
    public function getDailyTrafficStats($date) {
        $this->ensureConnection();
        return $this->trafficRepository()->getDailyTrafficStats((string) $date);
    }
    
    /**
     * 更新指定日期的已用流量（用于流量重置时回溯更新）
     */
    public function updateUsedBandwidth($date, $usedBandwidth) {
        $this->ensureConnection();
        return $this->trafficRepository()->updateUsedBandwidth((string) $date, (float) $usedBandwidth);
    }
    
    /**
     * 获取最近N天的流量统计
     */
    public function getRecentTrafficStats($days = 30) {
        $this->ensureConnection();
        return $this->trafficRepository()->getRecentTrafficStats((int) $days);
    }
    
    /**
     * 获取指定日期范围的流量统计
     * @param string $startDate 开始日期 (Y-m-d)
     * @param string $endDate 结束日期 (Y-m-d)
     * @return array
     */
    public function getTrafficStatsByDateRange($startDate, $endDate) {
        $this->ensureConnection();
        return $this->trafficRepository()->getTrafficStatsByDateRange((string) $startDate, (string) $endDate);
    }
    
    /**
     * 计算今日流量使用量
     */
    public function calculateDailyUsage($date) {
        $this->ensureConnection();
        return $this->trafficRepository()->calculateDailyUsage((string) $date);
    }
    
    /**
     * 保存流量快照（每5分钟一次）
     * 只在当前分钟是5的倍数时保存，避免产生非5分钟间隔的数据
     */
    public function saveTrafficSnapshot($rxBytes, $txBytes) {
        $this->ensureConnection();
        return $this->trafficRepository()->saveTrafficSnapshot((float) $rxBytes, (float) $txBytes);
    }
    
    /**
     * 获取指定日期的流量快照数据（只返回5分钟倍数的时间点）
     */
    public function getTrafficSnapshotsByDate($date) {
        $this->ensureConnection();
        return $this->trafficRepository()->getTrafficSnapshotsByDate((string) $date);
    }
    
    /**
     * 获取今日的流量快照数据
     */
    public function getTodayTrafficSnapshots() {
        $this->ensureConnection();
        return $this->trafficRepository()->getTodayTrafficSnapshots();
    }
    
    /**
     * 获取指定日期的最后一个快照（通常是23:55）
     * 用于计算跨日流量时的基准点
     */
    public function getLastSnapshotOfDay($date) {
        $this->ensureConnection();
        return $this->trafficRepository()->getLastSnapshotOfDay((string) $date);
    }
    
    /**
     * 获取指定日期的第一个快照（通常是00:00）
     * 用于计算当日使用量的基准点
     */
    public function getFirstSnapshotOfDay($date) {
        $this->ensureConnection();
        return $this->trafficRepository()->getFirstSnapshotOfDay((string) $date);
    }

    public function getDistinctStatuses(): array {
        $this->ensureConnection();
        return $this->proxyRepository()->getDistinctStatuses();
    }

    public function runInTransaction(callable $callback) {
        $this->ensureConnection();

        if ($this->pdo->inTransaction()) {
            return $callback();
        }

        try {
            $this->pdo->beginTransaction();
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
    
    /**
     * 清理过期的流量快照
     * 保留当月所有数据 + 上月最后一天（用于跨日增量计算）
     * @param int $daysToKeep 最少保留天数（默认35天，覆盖当月+上月末）
     */
    public function cleanOldTrafficSnapshots($daysToKeep = 35) {
        $this->ensureConnection();
        return $this->trafficRepository()->cleanOldTrafficSnapshots((int) $daysToKeep);
    }
}
