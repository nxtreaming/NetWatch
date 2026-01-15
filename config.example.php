<?php
/**
 * NetWatch 配置文件示例
 * 复制此文件为 config.php 并修改相应配置
 */

// 数据库配置（使用SQLite）
define('DB_PATH', __DIR__ . '/data/netwatch.db');

// 邮件配置
define('SMTP_HOST', 'smtp.gmail.com');  // 修改为您的SMTP服务器
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');  // 修改为您的邮箱
define('SMTP_PASSWORD', 'your-password');  // 修改为您的邮箱密码
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');  // 发件人邮箱
define('SMTP_FROM_NAME', 'NetWatch Monitor');
define('SMTP_TO_EMAIL', 'admin@example.com');  // 接收通知的邮箱

// 监控配置
define('CHECK_INTERVAL', 300);  // 检查间隔（秒）
define('TIMEOUT', 10);  // curl超时时间（秒）
define('MAX_RETRIES', 3);  // 最大重试次数
define('ALERT_THRESHOLD', 3);  // 连续失败多少次后发送邮件

// 测试URL配置
// 如果httpbin.org无法访问，可以尝试以下替代URL：
// define('TEST_URL', 'http://icanhazip.com');
// define('TEST_URL', 'http://ifconfig.me/ip');
// define('TEST_URL', 'http://api.ipify.org');
// define('TEST_URL', 'http://checkip.amazonaws.com');
define('TEST_URL', 'http://httpbin.org/ip');  // 用于测试代理的URL
define('TEST_HTTPS_URL', 'https://httpbin.org/ip');  // 用于测试HTTPS代理的URL

// 日志配置
define('LOG_PATH', __DIR__ . '/logs/');
define('LOG_LEVEL', 'INFO');  // DEBUG, INFO, WARNING, ERROR

// Debug 工具开关（生产环境建议设置为 false）
define('ENABLE_DEBUG_TOOLS', true);

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 全局异常处理（推荐开启，避免未捕获异常导致白屏）
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/includes/Exceptions.php';

$__netwatchLogger = new Logger();
ExceptionHandler::setLogger($__netwatchLogger);
ExceptionHandler::register();

// 登录配置
define('LOGIN_USERNAME', 'admin');  // 登录用户名
define('LOGIN_PASSWORD', 'admin123');  // 登录密码
// A1 兼容迁移：推荐使用哈希密码（优先级高于 LOGIN_PASSWORD）
// 生成方式示例：php -r "echo password_hash('admin123', PASSWORD_BCRYPT), PHP_EOL;"
// define('LOGIN_PASSWORD_HASH', '$2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SESSION_TIMEOUT', 3600);  // 会话超时时间（秒），默认1小时
define('ENABLE_LOGIN', true);  // 是否启用登录功能

// 流量监控API配置
// 示例: curl -s -x http://Admin:Passwd@api.example.com:12323 http://api.example.com:12323/stats
define('TRAFFIC_API_URL', 'http://api.example.com:12323/stats');  // 流量监控API地址（stats端点）
define('TRAFFIC_API_PROXY_HOST', 'api.example.com');  // 代理服务器主机名
define('TRAFFIC_API_PROXY_USERNAME', 'Admin');  // HTTP代理用户名
define('TRAFFIC_API_PROXY_PASSWORD', 'Passwd');  // HTTP代理密码
define('TRAFFIC_API_PROXY_PORT', 12323);  // HTTP代理端口
define('TRAFFIC_UPDATE_INTERVAL', 300);  // 流量更新间隔（秒），默认5分钟
define('TRAFFIC_TOTAL_LIMIT_GB', 1000);  // 总流量限制（GB），设置为0表示不限制

// 并行检测配置
define('PARALLEL_MAX_PROCESSES', 24);   // 最大并行进程数
define('PARALLEL_BATCH_SIZE', 200);     // 每批次代理数量

// 缓存配置
define('CACHE_DIR', __DIR__ . '/cache/');  // 缓存目录
define('PROXY_COUNT_CACHE_FILE', 'cache_proxy_count.txt');  // 代理数量缓存文件名
define('PROXY_COUNT_CACHE_TIME', 300);  // 代理数量缓存时间（秒），默认5分钟

// 分页配置
define('PROXIES_PER_PAGE', 200);  // 每页显示的代理数量
