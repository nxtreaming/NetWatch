<?php
/**
 * 数据库迁移系统
 * 追踪和管理数据库表结构变更
 */

class Migration {
    private PDO $pdo;
    private string $migrationsTable = 'migrations';
    private string $migrationsDir;
    
    public function __construct(PDO $pdo, ?string $migrationsDir = null) {
        $this->pdo = $pdo;
        $this->migrationsDir = $migrationsDir ?? __DIR__ . '/../migrations';
        $this->ensureMigrationsTable();
    }
    
    /**
     * 确保迁移记录表存在
     */
    private function ensureMigrationsTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->migrationsTable} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration VARCHAR(255) NOT NULL UNIQUE,
            batch INTEGER NOT NULL,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
    }
    
    /**
     * 运行所有待执行的迁移
     */
    public function migrate(): array {
        $executed = $this->getExecutedMigrations();
        $pending = $this->getPendingMigrations($executed);
        $results = [];
        
        if (empty($pending)) {
            return ['message' => '没有待执行的迁移', 'migrations' => []];
        }
        
        $batch = $this->getNextBatch();
        
        foreach ($pending as $migration) {
            try {
                $this->runMigration($migration, 'up');
                $this->recordMigration($migration, $batch);
                $results[] = ['migration' => $migration, 'status' => 'success'];
            } catch (Exception $e) {
                $results[] = ['migration' => $migration, 'status' => 'failed', 'error' => $e->getMessage()];
                break; // 停止后续迁移
            }
        }
        
        return ['message' => '迁移完成', 'migrations' => $results];
    }
    
    /**
     * 回滚最后一批迁移
     */
    public function rollback(): array {
        $lastBatch = $this->getLastBatch();
        
        if ($lastBatch === 0) {
            return ['message' => '没有可回滚的迁移', 'migrations' => []];
        }
        
        $migrations = $this->getMigrationsByBatch($lastBatch);
        $results = [];
        
        foreach (array_reverse($migrations) as $migration) {
            try {
                $this->runMigration($migration, 'down');
                $this->removeMigration($migration);
                $results[] = ['migration' => $migration, 'status' => 'success'];
            } catch (Exception $e) {
                $results[] = ['migration' => $migration, 'status' => 'failed', 'error' => $e->getMessage()];
                break;
            }
        }
        
        return ['message' => '回滚完成', 'migrations' => $results];
    }
    
    /**
     * 重置所有迁移
     */
    public function reset(): array {
        $executed = $this->getExecutedMigrations();
        $results = [];
        
        foreach (array_reverse($executed) as $migration) {
            try {
                $this->runMigration($migration, 'down');
                $this->removeMigration($migration);
                $results[] = ['migration' => $migration, 'status' => 'success'];
            } catch (Exception $e) {
                $results[] = ['migration' => $migration, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }
        
        return ['message' => '重置完成', 'migrations' => $results];
    }
    
    /**
     * 获取迁移状态
     */
    public function status(): array {
        $executed = $this->getExecutedMigrations();
        $all = $this->getAllMigrationFiles();
        $status = [];
        
        foreach ($all as $migration) {
            $status[] = [
                'migration' => $migration,
                'status' => in_array($migration, $executed) ? 'executed' : 'pending'
            ];
        }
        
        return $status;
    }
    
    /**
     * 创建新迁移文件
     */
    public function create(string $name): string {
        if (!is_dir($this->migrationsDir)) {
            mkdir($this->migrationsDir, 0755, true);
        }
        
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $filepath = $this->migrationsDir . '/' . $filename;
        
        $template = $this->getMigrationTemplate($name);
        file_put_contents($filepath, $template);
        
        return $filename;
    }
    
    /**
     * 执行迁移文件
     */
    private function runMigration(string $migration, string $direction): void {
        $filepath = $this->migrationsDir . '/' . $migration . '.php';
        
        if (!file_exists($filepath)) {
            throw new RuntimeException("迁移文件不存在: {$migration}");
        }
        
        $migrationData = require $filepath;
        
        if (!isset($migrationData[$direction])) {
            throw new RuntimeException("迁移方法不存在: {$direction}");
        }
        
        $sql = $migrationData[$direction];
        
        if (is_callable($sql)) {
            $sql($this->pdo);
        } elseif (is_string($sql)) {
            $this->pdo->exec($sql);
        } elseif (is_array($sql)) {
            foreach ($sql as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }
    
    /**
     * 获取已执行的迁移
     */
    private function getExecutedMigrations(): array {
        $stmt = $this->pdo->query("SELECT migration FROM {$this->migrationsTable} ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * 获取待执行的迁移
     */
    private function getPendingMigrations(array $executed): array {
        $all = $this->getAllMigrationFiles();
        return array_diff($all, $executed);
    }
    
    /**
     * 获取所有迁移文件
     */
    private function getAllMigrationFiles(): array {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }
        
        $files = glob($this->migrationsDir . '/*.php');
        $migrations = [];
        
        foreach ($files as $file) {
            $migrations[] = basename($file, '.php');
        }
        
        sort($migrations);
        return $migrations;
    }
    
    /**
     * 记录迁移
     */
    private function recordMigration(string $migration, int $batch): void {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->migrationsTable} (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migration, $batch]);
    }
    
    /**
     * 删除迁移记录
     */
    private function removeMigration(string $migration): void {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->migrationsTable} WHERE migration = ?");
        $stmt->execute([$migration]);
    }
    
    /**
     * 获取下一个批次号
     */
    private function getNextBatch(): int {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM {$this->migrationsTable}");
        $max = $stmt->fetchColumn();
        return ($max ?? 0) + 1;
    }
    
    /**
     * 获取最后一个批次号
     */
    private function getLastBatch(): int {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM {$this->migrationsTable}");
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * 获取指定批次的迁移
     */
    private function getMigrationsByBatch(int $batch): array {
        $stmt = $this->pdo->prepare("SELECT migration FROM {$this->migrationsTable} WHERE batch = ? ORDER BY id");
        $stmt->execute([$batch]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * 获取迁移模板
     */
    private function getMigrationTemplate(string $name): string {
        $className = $this->toCamelCase($name);
        return <<<PHP
<?php
/**
 * 迁移: {$name}
 * 创建时间: {$this->getCurrentTimestamp()}
 */

return [
    // 执行迁移
    'up' => function(PDO \$pdo) {
        // \$pdo->exec("CREATE TABLE ...");
    },
    
    // 回滚迁移
    'down' => function(PDO \$pdo) {
        // \$pdo->exec("DROP TABLE ...");
    }
];
PHP;
    }
    
    private function toCamelCase(string $string): string {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
    }
    
    private function getCurrentTimestamp(): string {
        return date('Y-m-d H:i:s');
    }
}
