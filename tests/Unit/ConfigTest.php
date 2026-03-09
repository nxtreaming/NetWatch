<?php
/**
 * Config 类与配置辅助函数单元测试
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once PROJECT_ROOT . '/includes/Config.php';

class ConfigTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];
    private string $tempDir;

    public function __construct() {
        $this->tempDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'netwatch_config_tests_' . uniqid('', true);
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    public function run(): bool {
        echo "=== Config 单元测试 ===\n\n";

        $this->testGetSetHas();
        $this->testMergeOverridesNestedValues();
        $this->testLoadFromFileMergesArrayConfig();
        $this->testEnvCastsCommonStringValues();
        $this->testResolveConfigErrorContextInCli();
        $this->testValidateAcceptsWritablePathsAndBooleanVerifySsl();
        $this->testValidateRejectsInvalidVerifySslType();
        $this->testValidateRejectsInvalidTrafficApiUrl();

        $this->printResults();
        $this->cleanup();

        return $this->failed === 0;
    }

    private function testGetSetHas(): void {
        echo "测试 get() / set() / has():\n";

        $config = $this->freshConfig();
        $this->assert($config->get('app.name') === 'NetWatch', '默认配置可读取');
        $this->assert($config->get('missing.key', 'fallback') === 'fallback', '缺失配置返回默认值');

        $config->set('custom.feature.enabled', true);
        $this->assert($config->has('custom.feature.enabled'), 'set 后 has 返回 true');
        $this->assert($config->get('custom.feature.enabled') === true, 'set 后可正确读取嵌套值');

        echo "\n";
    }

    private function testMergeOverridesNestedValues(): void {
        echo "测试 merge():\n";

        $config = $this->freshConfig();
        $config->merge([
            'monitoring' => [
                'timeout' => 30,
            ],
            'security' => [
                'rate_limit' => [
                    'max_requests' => 120,
                ],
            ],
        ]);

        $this->assert($config->get('monitoring.timeout') === 30, 'merge 可覆盖一层嵌套值');
        $this->assert($config->get('security.rate_limit.max_requests') === 120, 'merge 可覆盖多层嵌套值');
        $this->assert($config->get('security.rate_limit.window_seconds') === 60, 'merge 保留未覆盖的原值');

        echo "\n";
    }

    private function testLoadFromFileMergesArrayConfig(): void {
        echo "测试 loadFromFile():\n";

        $config = $this->freshConfig();
        $configFile = $this->tempDir . DIRECTORY_SEPARATOR . 'config_override.php';
        file_put_contents($configFile, "<?php\nreturn [\n    'logging' => ['level' => 'DEBUG'],\n    'app' => ['name' => 'NetWatch Test'],\n];\n");

        $config->loadFromFile($configFile);

        $this->assert($config->get('logging.level') === 'DEBUG', 'loadFromFile 可载入并合并配置');
        $this->assert($config->get('app.name') === 'NetWatch Test', 'loadFromFile 可覆盖默认配置');

        $missingFile = $this->tempDir . DIRECTORY_SEPARATOR . 'missing.php';
        $config->loadFromFile($missingFile);
        $this->assert($config->get('app.name') === 'NetWatch Test', '缺失配置文件时保持现有配置不变');

        echo "\n";
    }

    private function testEnvCastsCommonStringValues(): void {
        echo "测试 env():\n";

        $trueKey = 'NETWATCH_TEST_ENV_TRUE_' . uniqid();
        $falseKey = 'NETWATCH_TEST_ENV_FALSE_' . uniqid();
        $nullKey = 'NETWATCH_TEST_ENV_NULL_' . uniqid();
        $emptyKey = 'NETWATCH_TEST_ENV_EMPTY_' . uniqid();
        $stringKey = 'NETWATCH_TEST_ENV_STRING_' . uniqid();

        putenv($trueKey . '=true');
        putenv($falseKey . '=(false)');
        putenv($nullKey . '=null');
        putenv($emptyKey . '=(empty)');
        putenv($stringKey . '=hello');

        $this->assert(env($trueKey) === true, 'env 可将 true 字符串转换为布尔 true');
        $this->assert(env($falseKey) === false, 'env 可将 false 字符串转换为布尔 false');
        $this->assert(env($nullKey) === null, 'env 可将 null 字符串转换为 null');
        $this->assert(env($emptyKey) === '', 'env 可将 empty 字符串转换为空字符串');
        $this->assert(env($stringKey) === 'hello', 'env 保留普通字符串值');
        $this->assert(env('NETWATCH_TEST_ENV_MISSING_' . uniqid(), 'fallback') === 'fallback', 'env 缺失时返回默认值');

        echo "\n";
    }

    private function testResolveConfigErrorContextInCli(): void {
        echo "测试 resolve_config_error_context():\n";

        $this->assert(resolve_config_error_context('api') === 'api', '显式 context 优先返回');
        $this->assert(resolve_config_error_context('web') === 'web', '显式 web context 优先返回');
        $this->assert(resolve_config_error_context(null) === 'cli', 'CLI 环境下默认返回 cli');

        echo "\n";
    }

    private function testValidateAcceptsWritablePathsAndBooleanVerifySsl(): void {
        echo "测试 validate() 合法配置:\n";

        $config = $this->freshConfig();
        $logDir = $this->tempDir . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $dbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'netwatch.db';
        $config->set('database.path', $dbPath);
        $config->set('logging.path', $logDir);
        $config->set('traffic.api_url', 'https://example.com/stats');
        $config->set('security.verify_ssl', true);
        $config->set('app.env', 'test');

        try {
            $config->validate(true);
            $this->assert(true, '合法配置验证通过');
        } catch (ConfigurationException $e) {
            $this->assert(false, '合法配置不应抛异常: ' . $e->getMessage());
        }

        echo "\n";
    }

    private function testValidateRejectsInvalidVerifySslType(): void {
        echo "测试 validate() 拒绝非法 verify_ssl:\n";

        $config = $this->makeValidatableConfig();
        $config->set('security.verify_ssl', 'yes');

        try {
            $config->validate(true);
            $this->assert(false, '非法 verify_ssl 应抛出异常');
        } catch (ConfigurationException $e) {
            $this->assert(strpos($e->getMessage(), 'VERIFY_SSL 配置无效') !== false, '非法 verify_ssl 抛出预期异常');
        }

        echo "\n";
    }

    private function testValidateRejectsInvalidTrafficApiUrl(): void {
        echo "测试 validate() 拒绝非法 URL:\n";

        $config = $this->makeValidatableConfig();
        $config->set('traffic.api_url', 'not-a-valid-url');

        try {
            $config->validate(true);
            $this->assert(false, '非法 URL 应抛出异常');
        } catch (ConfigurationException $e) {
            $this->assert(strpos($e->getMessage(), 'TRAFFIC_API_URL 配置无效') !== false, '非法 URL 抛出预期异常');
        }

        echo "\n";
    }

    private function makeValidatableConfig(): Config {
        $config = $this->freshConfig();
        $logDir = $this->tempDir . DIRECTORY_SEPARATOR . 'valid_logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $config->set('database.path', $this->tempDir . DIRECTORY_SEPARATOR . 'valid_data' . DIRECTORY_SEPARATOR . 'netwatch.db');
        $config->set('logging.path', $logDir);
        $config->set('traffic.api_url', 'https://example.com/stats');
        $config->set('security.verify_ssl', true);
        $config->set('app.env', 'test');

        return $config;
    }

    private function freshConfig(): Config {
        $reflection = new ReflectionClass(Config::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);

        return Config::getInstance();
    }

    private function withServerState(array $server, callable $callback): void {
        $originalServer = $_SERVER;
        $_SERVER = $server;

        try {
            $callback();
        } finally {
            $_SERVER = $originalServer;
        }
    }

    private function cleanup(): void {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $this->removeDirectoryRecursively($this->tempDir);
    }

    private function removeDirectoryRecursively(string $directory): void {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectoryRecursively($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
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
    $test = new ConfigTest();
    exit($test->run() ? 0 : 1);
}
