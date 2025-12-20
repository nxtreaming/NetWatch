<?php
/**
 * PHPUnit 测试引导文件
 * 设置测试环境和自动加载
 */

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 定义测试环境常量
define('TESTING', true);
define('TEST_ROOT', __DIR__);
define('PROJECT_ROOT', dirname(__DIR__));

// 加载配置（使用测试配置或默认值）
if (file_exists(PROJECT_ROOT . '/config.php')) {
    require_once PROJECT_ROOT . '/config.php';
} else {
    // 测试环境默认配置
    define('DB_PATH', ':memory:'); // 使用内存数据库
    define('LOG_PATH', sys_get_temp_dir() . '/netwatch_test_logs/');
    define('LOG_LEVEL', 'DEBUG');
    define('TIMEOUT', 5);
    define('BATCH_SIZE', 10);
    define('TEST_URL', 'http://httpbin.org/ip');
}

// 确保日志目录存在
if (!is_dir(LOG_PATH)) {
    mkdir(LOG_PATH, 0755, true);
}

// 自动加载项目文件
spl_autoload_register(function ($class) {
    $paths = [
        PROJECT_ROOT . '/includes/',
        PROJECT_ROOT . '/',
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// 加载核心文件
require_once PROJECT_ROOT . '/database.php';
require_once PROJECT_ROOT . '/logger.php';
require_once PROJECT_ROOT . '/monitor.php';

/**
 * 测试辅助函数
 */

/**
 * 创建内存数据库实例
 */
function createTestDatabase(): Database {
    // 使用反射创建带内存数据库的实例
    $db = new Database();
    return $db;
}

/**
 * 创建测试用的代理数据
 */
function createTestProxy(array $override = []): array {
    return array_merge([
        'ip' => '127.0.0.1',
        'port' => 8080,
        'type' => 'http',
        'username' => '',
        'password' => '',
        'status' => 'unknown',
        'response_time' => null,
        'failure_count' => 0
    ], $override);
}

/**
 * 清理测试日志
 */
function cleanupTestLogs(): void {
    $logPath = LOG_PATH;
    if (is_dir($logPath)) {
        $files = glob($logPath . '*.log');
        foreach ($files as $file) {
            unlink($file);
        }
    }
}
