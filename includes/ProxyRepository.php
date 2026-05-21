<?php
declare(strict_types=1);

class ProxyRepository {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function addProxy($ip, $port, $type, $username = null, $password = null) {
        $sql = "INSERT INTO proxies (ip, port, type, username, password) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([$ip, $port, $type, $username, $password]);

        if ($result) {
            $this->clearProxyCountCache();
        }

        return $result;
    }

    public function proxyExists($ip, $port): bool {
        $sql = "SELECT COUNT(*) FROM proxies WHERE ip = ? AND port = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ip, $port]);
        return $stmt->fetchColumn() > 0;
    }

    public function getAllProxies(): array {
        $sql = "SELECT * FROM proxies ORDER BY id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProxiesPaginated(int $page = 1, int $perPage = 200): array {
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM proxies ORDER BY id LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$perPage, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

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

    public function getRecentLogs(int $limit = 100): array {
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

    public function getFailedProxies(): array {
        $sql = "SELECT * FROM proxies WHERE failure_count >= ? ORDER BY failure_count DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([ALERT_THRESHOLD]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cleanupOldLogs(int $days = 30): array {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $sql = "DELETE FROM check_logs WHERE checked_at < ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$cutoffDate]);
        $deletedLogs = $stmt->rowCount();

        $sql = "DELETE FROM alerts WHERE sent_at < ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$cutoffDate]);
        $deletedAlerts = $stmt->rowCount();

        return [
            'deleted_logs' => $deletedLogs,
            'deleted_alerts' => $deletedAlerts,
        ];
    }

    public function updateProxyAuth($id, $username, $password) {
        $sql = "UPDATE proxies SET username = ?, password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([$username, $password, $id]);
        $this->clearProxyCountCache();
        return $result;
    }

    public function clearAllData(): bool {
        try {
            $this->pdo->beginTransaction();

            $this->pdo->exec("DELETE FROM alerts");
            $this->pdo->exec("DELETE FROM check_logs");
            $this->pdo->exec("DELETE FROM proxies");

            $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='proxies'");
            $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='check_logs'");
            $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='alerts'");

            $this->pdo->commit();

            $this->clearProxyCountCache();

            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception('清空数据失败: ' . $e->getMessage());
        }
    }

    public function getProxyCount(): int {
        $sql = "SELECT COUNT(*) as count FROM proxies";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) $result['count'];
    }

    public function getProxiesBatch(int $offset = 0, int $limit = 20): array {
        $sql = "SELECT * FROM proxies ORDER BY id LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchProxies($searchTerm, int $page = 1, int $perPage = 200, string $statusFilter = ''): array {
        $offset = ($page - 1) * $perPage;

        $result = $this->buildSearchConditions((string) $searchTerm, $statusFilter);
        $conditions = $result['conditions'];
        $params = $result['params'];

        $sql = "SELECT * FROM proxies";
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY ip, port LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSearchCount($searchTerm, string $statusFilter = ''): int {
        $result = $this->buildSearchConditions((string) $searchTerm, $statusFilter);
        $conditions = $result['conditions'];
        $params = $result['params'];

        $sql = "SELECT COUNT(*) as count FROM proxies";
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['count'];
    }

    public function getOfflineProxies(): array {
        $sql = "SELECT * FROM proxies WHERE status = 'offline' ORDER BY failure_count DESC, last_check ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOfflineProxyCount(): int {
        $sql = "SELECT COUNT(*) as count FROM proxies WHERE status = 'offline'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['count'];
    }

    public function getOfflineProxiesBatch(int $offset = 0, int $limit = 20): array {
        $sql = "SELECT * FROM proxies WHERE status = 'offline' ORDER BY failure_count DESC, last_check ASC LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDistinctStatuses(): array {
        $sql = "SELECT DISTINCT status FROM proxies WHERE status IS NOT NULL AND status != '' ORDER BY status ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static function (array $row): string {
            return (string) $row['status'];
        }, $rows));
    }

    private function escapeLikePattern(string $value): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function buildSearchConditions(string $searchTerm, string $statusFilter = ''): array {
        $conditions = [];
        $params = [];

        $searchTerm = trim($searchTerm);
        if (strlen($searchTerm) > 64) {
            $searchTerm = substr($searchTerm, 0, 64);
        }

        if ($searchTerm !== '') {
            if (strpos($searchTerm, '.x') !== false || substr($searchTerm, -1) === '.') {
                $networkPrefix = str_replace(['.x', 'x'], ['', ''], $searchTerm);
                $networkPrefix = rtrim($networkPrefix, '.');
                $conditions[] = "ip LIKE ? ESCAPE '\\'";
                $params[] = $this->escapeLikePattern($networkPrefix) . '.%';
            } else {
                $conditions[] = "ip LIKE ? ESCAPE '\\'";
                $params[] = '%' . $this->escapeLikePattern($searchTerm) . '%';
            }
        }

        if ($statusFilter !== '') {
            $conditions[] = 'status = ?';
            $params[] = $statusFilter;
        }

        return ['conditions' => $conditions, 'params' => $params];
    }

    private function clearProxyCountCache(): void {
        $cacheFileName = defined('PROXY_COUNT_CACHE_FILE') ? (string) PROXY_COUNT_CACHE_FILE : 'cache_proxy_count.txt';
        $cacheDir = defined('CACHE_DIR') ? rtrim((string) CACHE_DIR, '/\\') : (__DIR__ . '/../cache');
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . ltrim($cacheFileName, '/\\');

        if (file_exists($cacheFile)) {
            if (!unlink($cacheFile)) {
                error_log('[NetWatch][ProxyRepository] Failed to remove cache file: ' . $cacheFile);
            }
            return;
        }

        if (file_exists($cacheFileName)) {
            if (!unlink($cacheFileName)) {
                error_log('[NetWatch][ProxyRepository] Failed to remove legacy cache file: ' . $cacheFileName);
            }
        }
    }
}
