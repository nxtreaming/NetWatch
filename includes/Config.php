<?php
/**
 * 统一配置管理类
 * 支持数组配置、环境变量、默认值
 */

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/JsonResponse.php';

class Config {
    private static ?Config $instance = null;
    private array $config = [];
    private array $envCache = [];
    private bool $validated = false;
    private array $deprecatedWarnings = [];
    
    private function __construct() {
        $this->loadDefaults();
    }
    
    /**
     * 获取配置单例
     */
    public static function getInstance(): Config {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 加载默认配置
     */
    private function loadDefaults(): void {
        $this->config = [
            'app' => [
                'name' => 'NetWatch',
                'version' => defined('APP_VERSION') ? APP_VERSION : '1.0.0',
                'debug' => defined('DEBUG') ? DEBUG : false,
                'env' => defined('APP_ENV') ? APP_ENV : 'production',
                'timezone' => 'Asia/Shanghai',
            ],
            'database' => [
                'path' => defined('DB_PATH') ? DB_PATH : __DIR__ . '/../data/netwatch.db',
            ],
            'monitoring' => [
                'timeout' => defined('TIMEOUT') ? TIMEOUT : 10,
                'batch_size' => defined('BATCH_SIZE') ? BATCH_SIZE : 200,
                'test_url' => defined('TEST_URL') ? TEST_URL : 'http://httpbin.org/ip',
                'max_retries' => defined('MAX_RETRIES') ? (int) MAX_RETRIES : 3,
                'retry_delay_us' => defined('PROXY_RETRY_DELAY_US') ? (int) PROXY_RETRY_DELAY_US : 200000,
                'request_throttle_us' => defined('PROXY_REQUEST_THROTTLE_US') ? (int) PROXY_REQUEST_THROTTLE_US : 10000,
                'parallel_batch_poll_us' => defined('PARALLEL_BATCH_POLL_US') ? (int) PARALLEL_BATCH_POLL_US : 500000,
                'parallel_cancel_poll_us' => defined('PARALLEL_CANCEL_POLL_US') ? (int) PARALLEL_CANCEL_POLL_US : 100000,
                'parallel_batch_size' => defined('PARALLEL_BATCH_SIZE') ? (int) PARALLEL_BATCH_SIZE : 200,
                'parallel_max_processes' => defined('PARALLEL_MAX_PROCESSES') ? (int) PARALLEL_MAX_PROCESSES : 24,
                'max_workers' => 8,
            ],
            'logging' => [
                'path' => defined('LOG_PATH') ? LOG_PATH : __DIR__ . '/../logs/',
                'level' => defined('LOG_LEVEL') ? LOG_LEVEL : 'INFO',
                'json_format' => false,
            ],
            'security' => [
                'session_timeout' => defined('SESSION_TIMEOUT') ? (int) SESSION_TIMEOUT : 3600,
                'verify_ssl' => defined('VERIFY_SSL') ? (bool) VERIFY_SSL : true,
                'csrf_enabled' => true,
                'rate_limit' => [
                    'enabled' => true,
                    'max_requests' => 60,
                    'window_seconds' => 60,
                ],
            ],
            'mail' => [
                'enabled' => defined('MAIL_ENABLED') ? MAIL_ENABLED : false,
                'host' => defined('SMTP_HOST') ? SMTP_HOST : (defined('MAIL_HOST') ? MAIL_HOST : ''),
                'port' => defined('SMTP_PORT') ? SMTP_PORT : (defined('MAIL_PORT') ? MAIL_PORT : 587),
                'username' => defined('SMTP_USERNAME') ? SMTP_USERNAME : (defined('MAIL_USERNAME') ? MAIL_USERNAME : ''),
                'password' => defined('SMTP_PASSWORD') ? SMTP_PASSWORD : (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : ''),
                'password_env' => defined('SMTP_PASSWORD_ENV') ? SMTP_PASSWORD_ENV : '',
                'password_file' => defined('SMTP_PASSWORD_FILE') ? SMTP_PASSWORD_FILE : '',
                'from' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : (defined('MAIL_FROM') ? MAIL_FROM : ''),
                'from_name' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'NetWatch',
                'to' => defined('SMTP_TO_EMAIL') ? SMTP_TO_EMAIL : (defined('MAIL_TO') ? MAIL_TO : ''),
            ],
            'traffic' => [
                'api_url' => defined('TRAFFIC_API_URL') ? TRAFFIC_API_URL : '',
                'proxy_host' => defined('TRAFFIC_API_PROXY_HOST') ? TRAFFIC_API_PROXY_HOST : '',
                'proxy_port' => defined('TRAFFIC_API_PROXY_PORT') ? TRAFFIC_API_PROXY_PORT : 8080,
                'proxy_username' => defined('TRAFFIC_API_PROXY_USERNAME') ? TRAFFIC_API_PROXY_USERNAME : '',
                'proxy_password' => defined('TRAFFIC_API_PROXY_PASSWORD') ? TRAFFIC_API_PROXY_PASSWORD : '',
                'update_interval' => defined('TRAFFIC_UPDATE_INTERVAL') ? TRAFFIC_UPDATE_INTERVAL : 300,
                'total_limit_gb' => defined('TRAFFIC_TOTAL_LIMIT_GB') ? TRAFFIC_TOTAL_LIMIT_GB : 0,
                'reset_threshold_gb' => defined('TRAFFIC_RESET_THRESHOLD_GB') ? TRAFFIC_RESET_THRESHOLD_GB : 100,
                'crossday_max_gb' => defined('TRAFFIC_CROSSDAY_MAX_GB') ? TRAFFIC_CROSSDAY_MAX_GB : 50,
                'crossday_validation_log' => defined('TRAFFIC_CROSSDAY_VALIDATION_LOG') ? TRAFFIC_CROSSDAY_VALIDATION_LOG : false,
            ],
            'scheduler' => [
                'loop_sleep_sec' => defined('SCHEDULER_LOOP_SLEEP_SEC') ? (int) SCHEDULER_LOOP_SLEEP_SEC : 60,
            ],
            'api' => [
                'allow_origin' => defined('API_ALLOW_ORIGIN') ? API_ALLOW_ORIGIN : '',
                'require_https' => defined('API_REQUIRE_HTTPS') ? API_REQUIRE_HTTPS : false,
                'ip_whitelist' => defined('API_IP_WHITELIST') ? API_IP_WHITELIST : '',
                'allow_post_token' => defined('API_ALLOW_POST_TOKEN') ? API_ALLOW_POST_TOKEN : false,
            ],
        ];

        $this->warnOnDeprecatedMailConstants();
    }

    private function warnOnDeprecatedMailConstants(): void {
        $this->warnIfDeprecatedMailConstantUsed('MAIL_HOST', 'SMTP_HOST');
        $this->warnIfDeprecatedMailConstantUsed('MAIL_PORT', 'SMTP_PORT');
        $this->warnIfDeprecatedMailConstantUsed('MAIL_USERNAME', 'SMTP_USERNAME');
        $this->warnIfDeprecatedMailConstantUsed('MAIL_PASSWORD', 'SMTP_PASSWORD');
        $this->warnIfDeprecatedMailConstantUsed('MAIL_FROM', 'SMTP_FROM_EMAIL');
        $this->warnIfDeprecatedMailConstantUsed('MAIL_TO', 'SMTP_TO_EMAIL');
    }

    private function warnIfDeprecatedMailConstantUsed(string $deprecatedConstant, string $replacementConstant): void {
        if (!defined($deprecatedConstant) || defined($replacementConstant)) {
            return;
        }

        if (isset($this->deprecatedWarnings[$deprecatedConstant])) {
            return;
        }

        $this->deprecatedWarnings[$deprecatedConstant] = true;
        error_log('[NetWatch][Config] Deprecated config constant ' . $deprecatedConstant . ' is in use. Please migrate to ' . $replacementConstant . '.');
    }
    
    /**
     * 获取配置值
     * @param string $key 配置键，支持点号分隔（如 'database.path'）
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * 设置配置值
     * @param string $key 配置键
     * @param mixed $value 配置值
     */
    public function set(string $key, $value): void {
        $keys = explode('.', $key);
        $config = &$this->config;
        
        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }
    }
    
    /**
     * 检查配置是否存在
     */
    public function has(string $key): bool {
        return $this->get($key) !== null;
    }
    
    /**
     * 获取所有配置
     */
    public function all(): array {
        return $this->config;
    }
    
    /**
     * 合并配置
     */
    public function merge(array $config): void {
        $this->config = array_replace_recursive($this->config, $config);
    }
    
    /**
     * 从文件加载配置
     */
    public function loadFromFile(string $filepath): void {
        if (file_exists($filepath)) {
            $config = require $filepath;
            if (is_array($config)) {
                $this->merge($config);
            }
        }
    }

    public function validate(bool $force = false): void {
        if ($this->validated && !$force) {
            return;
        }

        $env = (string) $this->get('app.env', 'production');
        $allowedEnvironments = ['production', 'local', 'dev', 'development', 'test'];
        if (!in_array($env, $allowedEnvironments, true)) {
            throw new ConfigurationException('APP_ENV 配置无效: ' . $env);
        }

        $databasePath = (string) $this->get('database.path', '');
        if ($databasePath === '') {
            throw new ConfigurationException('数据库路径未配置');
        }
        $this->assertParentDirectoryIsWritable($databasePath, '数据库路径');

        $logPath = (string) $this->get('logging.path', '');
        if ($logPath === '') {
            throw new ConfigurationException('日志路径未配置');
        }
        $this->assertDirectoryIsWritable($logPath, '日志目录');

        $trafficApiUrl = (string) $this->get('traffic.api_url', '');
        if ($trafficApiUrl !== '' && filter_var($trafficApiUrl, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('TRAFFIC_API_URL 配置无效');
        }

        $verifySsl = $this->get('security.verify_ssl', true);
        if (!is_bool($verifySsl)) {
            throw new ConfigurationException('VERIFY_SSL 配置无效，必须为布尔值');
        }

        $this->validated = true;
    }

    private function assertParentDirectoryIsWritable(string $path, string $label): void {
        $directory = dirname($path);

        if (is_dir($directory)) {
            if (!is_writable($directory)) {
                throw new ConfigurationException($label . ' 所在目录不可写: ' . $directory);
            }
            return;
        }

        $parentDirectory = dirname($directory);
        if (!is_dir($parentDirectory) || !is_writable($parentDirectory)) {
            throw new ConfigurationException($label . ' 所在目录无法创建: ' . $directory);
        }
    }

    private function assertDirectoryIsWritable(string $path, string $label): void {
        if (is_dir($path)) {
            if (!is_writable($path)) {
                throw new ConfigurationException($label . ' 不可写: ' . $path);
            }
            return;
        }

        $parentDirectory = dirname(rtrim($path, '/\\'));
        if (!is_dir($parentDirectory) || !is_writable($parentDirectory)) {
            throw new ConfigurationException($label . ' 无法创建: ' . $path);
        }
    }
}

/**
 * 获取环境变量
 * @param string $key 环境变量名
 * @param mixed $default 默认值
 * @return mixed
 */
function env(string $key, $default = null) {
    static $cache = [];
    
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    
    $value = getenv($key);
    
    if ($value === false) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    
    // 类型转换
    if (is_string($value)) {
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                $value = true;
                break;
            case 'false':
            case '(false)':
                $value = false;
                break;
            case 'null':
            case '(null)':
                $value = null;
                break;
            case 'empty':
            case '(empty)':
                $value = '';
                break;
        }
    }
    
    $cache[$key] = $value;
    return $value;
}

/**
 * 快捷函数：获取配置值
 */
function config(string $key, $default = null) {
    return Config::getInstance()->get($key, $default);
}

function validate_config(bool $force = false): void {
    Config::getInstance()->validate($force);
}

function ensure_valid_config(?string $context = null, bool $force = false): void {
    try {
        validate_config($force);
    } catch (ConfigurationException $exception) {
        handle_config_validation_failure($exception, $context);
    }
}

function handle_config_validation_failure(ConfigurationException $exception, ?string $context = null): void {
    $resolvedContext = resolve_config_error_context($context);
    $statusCode = $exception->getCode();

    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    error_log('[NetWatch][Config] ' . $exception->getMessage());

    if ($resolvedContext === 'api') {
        JsonResponse::error('configuration_error', $exception->getMessage(), $statusCode);
        exit(1);
    }

    if ($resolvedContext === 'cli') {
        fwrite(STDERR, '[NetWatch][Config] ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    http_response_code($statusCode);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>配置错误</title></head><body><h1>配置错误</h1><p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit(1);
}

function resolve_config_error_context(?string $context = null): string {
    if (is_string($context) && $context !== '') {
        return $context;
    }

    if (php_sapi_name() === 'cli') {
        return 'cli';
    }

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $requestPath = (string) parse_url($requestUri, PHP_URL_PATH);

    if (strpos($scriptName, 'api.php') !== false || strpos($requestPath, 'api.php') !== false) {
        return 'api';
    }

    return 'web';
}
