<?php
/**
 * 统一配置管理类
 * 支持数组配置、环境变量、默认值
 */

class Config {
    private static ?Config $instance = null;
    private array $config = [];
    private array $envCache = [];
    
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
                'timezone' => 'Asia/Shanghai',
            ],
            'database' => [
                'path' => defined('DB_PATH') ? DB_PATH : __DIR__ . '/../data/netwatch.db',
            ],
            'monitoring' => [
                'timeout' => defined('TIMEOUT') ? TIMEOUT : 10,
                'batch_size' => defined('BATCH_SIZE') ? BATCH_SIZE : 200,
                'test_url' => defined('TEST_URL') ? TEST_URL : 'http://httpbin.org/ip',
                'parallel_batch_size' => 50,
                'max_workers' => 8,
            ],
            'logging' => [
                'path' => defined('LOG_PATH') ? LOG_PATH : __DIR__ . '/../logs/',
                'level' => defined('LOG_LEVEL') ? LOG_LEVEL : 'INFO',
                'json_format' => false,
            ],
            'security' => [
                'session_timeout' => 3600,
                'csrf_enabled' => true,
                'rate_limit' => [
                    'enabled' => true,
                    'max_requests' => 60,
                    'window_seconds' => 60,
                ],
            ],
            'mail' => [
                'enabled' => defined('MAIL_ENABLED') ? MAIL_ENABLED : false,
                'host' => defined('MAIL_HOST') ? MAIL_HOST : '',
                'port' => defined('MAIL_PORT') ? MAIL_PORT : 587,
                'username' => defined('MAIL_USERNAME') ? MAIL_USERNAME : '',
                'password' => defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '',
                'from' => defined('MAIL_FROM') ? MAIL_FROM : '',
                'to' => defined('MAIL_TO') ? MAIL_TO : '',
            ],
            'traffic' => [
                'api_url' => defined('TRAFFIC_API_URL') ? TRAFFIC_API_URL : '',
                'proxy_host' => defined('TRAFFIC_API_PROXY_HOST') ? TRAFFIC_API_PROXY_HOST : '',
                'proxy_port' => defined('TRAFFIC_API_PROXY_PORT') ? TRAFFIC_API_PROXY_PORT : 0,
                'proxy_username' => defined('TRAFFIC_API_PROXY_USERNAME') ? TRAFFIC_API_PROXY_USERNAME : '',
                'proxy_password' => defined('TRAFFIC_API_PROXY_PASSWORD') ? TRAFFIC_API_PROXY_PASSWORD : '',
                'update_interval' => defined('TRAFFIC_UPDATE_INTERVAL') ? TRAFFIC_UPDATE_INTERVAL : 300,
                'total_limit_gb' => defined('TRAFFIC_TOTAL_LIMIT_GB') ? TRAFFIC_TOTAL_LIMIT_GB : 0,
            ],
        ];
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
