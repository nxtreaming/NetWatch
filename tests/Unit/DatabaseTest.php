<?php
/**
 * Database 类单元测试
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once PROJECT_ROOT . '/database.php';

class DatabaseTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];
    private Database $db;

    public function __construct() {
        $this->db = new Database();
        $this->db->initializeSchema();
    }

    public function run(): bool {
        echo "=== Database 单元测试 ===\n\n";

        $this->testProxyExistsBehavior();
        $this->testGetAllProxiesReturnsArrayRows();
        $this->testAddAuditLogPersistsRecord();

        $this->printResults();

        return $this->failed === 0;
    }

    private function testProxyExistsBehavior(): void {
        echo "测试 proxyExists():\n";

        $this->resetTables();
        $ip = '203.0.113.10';
        $port = 8080;

        $this->assert($this->db->proxyExists($ip, $port) === false, '新增前代理不存在');

        $added = $this->db->addProxy($ip, $port, 'http');
        $this->assert($added === true, 'addProxy 返回 true');
        $this->assert($this->db->proxyExists($ip, $port) === true, '新增后代理存在');

        echo "\n";
    }

    private function testGetAllProxiesReturnsArrayRows(): void {
        echo "测试 getAllProxies():\n";

        $this->resetTables();
        $this->db->addProxy('198.51.100.11', 8081, 'http');
        $this->db->addProxy('198.51.100.12', 8082, 'socks5');

        $proxies = $this->db->getAllProxies();

        $this->assert(is_array($proxies), '返回值为数组');
        $this->assert(count($proxies) === 2, '返回两条代理记录');
        $this->assert(isset($proxies[0]['ip']) && isset($proxies[0]['port']), '记录包含 ip/port 字段');

        echo "\n";
    }

    private function testAddAuditLogPersistsRecord(): void {
        echo "测试 addAuditLog():\n";

        $this->resetTables();

        $result = $this->db->addAuditLog('tester', 'unit_test_action', 'proxy', '1', 'details', '127.0.0.1', 'phpunit');
        $this->assert($result === true, 'addAuditLog 返回 true');

        $pdo = $this->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE username = ? AND action = ?');
        $stmt->execute(['tester', 'unit_test_action']);
        $count = (int) $stmt->fetchColumn();

        $this->assert($count === 1, '审计日志已写入数据库');

        echo "\n";
    }

    private function resetTables(): void {
        $pdo = $this->getPdo();

        $pdo->exec('DELETE FROM check_logs');
        $pdo->exec('DELETE FROM alerts');
        $pdo->exec('DELETE FROM token_proxy_assignments');
        $pdo->exec('DELETE FROM api_tokens');
        $pdo->exec('DELETE FROM proxies');
        $pdo->exec('DELETE FROM audit_logs');
    }

    private function getPdo(): PDO {
        $reflection = new ReflectionProperty(Database::class, 'pdo');
        $reflection->setAccessible(true);

        $pdo = $reflection->getValue($this->db);
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('Database::pdo is not a PDO instance');
        }

        return $pdo;
    }

    private function assert(bool $condition, string $message): void {
        if ($condition) {
            echo "  ✓ {$message}\n";
            $this->passed++;
        } else {
            echo "  ✗ {$message}\n";
            $this->failed++;
            $this->errors[] = $message;
        }
    }

    private function printResults(): void {
        echo "=== 测试结果 ===\n";
        echo "通过: {$this->passed}\n";
        echo "失败: {$this->failed}\n";

        if (!empty($this->errors)) {
            echo "\n失败的测试:\n";
            foreach ($this->errors as $error) {
                echo "  - {$error}\n";
            }
        }

        echo "\n";
    }
}

if (php_sapi_name() === 'cli') {
    $test = new DatabaseTest();
    exit($test->run() ? 0 : 1);
}
