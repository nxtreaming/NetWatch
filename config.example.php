<?php
/**
 * NetWatch 配置文件示例
 * 复制此文件为 config.php 并修改相应配置
 */

// 数据库配置（使用SQLite）
// 建议权限（Linux/Unix）：data 目录 0750，数据库文件 0640（或更严格）
define('DB_PATH', __DIR__ . '/data/netwatch.db');

// 邮件配置
define('SMTP_HOST', 'smtp.gmail.com');  // 修改为您的SMTP服务器
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');  // 修改为您的邮箱
// 推荐：通过环境变量或密码文件提供 SMTP 密码，避免明文写入 config.php
define('SMTP_PASSWORD_ENV', 'NETWATCH_SMTP_PASSWORD');
// define('SMTP_PASSWORD_FILE', '/run/secrets/netwatch_smtp_password');
// 仅兼容旧配置（不推荐）：define('SMTP_PASSWORD', 'your-password');
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');  // 发件人邮箱
define('SMTP_FROM_NAME', 'NetWatch Monitor');
define('SMTP_TO_EMAIL', 'admin@example.com');  // 接收通知的邮箱

// 监控配置
define('CHECK_INTERVAL', 300);  // 检查间隔（秒）
define('TIMEOUT', 10);  // curl超时时间（秒）
define('CONNECT_TIMEOUT', 5);  // curl连接超时时间（秒）
define('VERIFY_SSL', true);  // 是否验证目标站点SSL证书（生产环境建议保持开启）
define('ENABLE_RETRY', true);  // 是否启用重试机制（失败后进行第二次检测）
define('MAX_RETRIES', 3);  // 最大重试次数
define('PROXY_RETRY_DELAY_US', 200000);
define('PROXY_REQUEST_THROTTLE_US', 10000);
define('AJAX_STREAM_THROTTLE_US', 10000);
define('PARALLEL_BATCH_POLL_US', 500000);
define('PARALLEL_CANCEL_POLL_US', 100000);
define('SCHEDULER_LOOP_SLEEP_SEC', 60);
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

// 运行环境配置（默认建议 production）
define('APP_ENV', 'production'); // 可选: production, local, dev, development, test

// Debug 工具开关（生产环境必须设置为 false，本地开发可改为 true）
define('ENABLE_DEBUG_TOOLS', false);
define('ALLOW_DEBUG_TOOLS_IN_PRODUCTION', false); // 生产环境二次保险，强烈不建议开启

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
// 生成方式示例：php -r 'echo password_hash("admin!@#*1234", PASSWORD_BCRYPT), PHP_EOL;'
define('LOGIN_PASSWORD_HASH', '$2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SESSION_TIMEOUT', 3600);  // 会话超时时间（秒），默认1小时
define('ENABLE_LOGIN', true);  // 是否启用登录功能
define('ENFORCE_LOGIN_PASSWORD_STRENGTH', true);  // 是否启用登录密码复杂度校验（长度>=12，含大小写字母/数字/特殊字符）
// define('SESSION_PATH_STRICT_CHECK', true); // 是否严格检查 Session 目录权限（生产建议 true）

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

// API安全配置
// define('API_ALLOW_ORIGIN', 'https://your-app.example.com'); // 仅在确实需要浏览器跨域访问API时配置，留空表示不发送CORS允许头
// define('API_REQUIRE_HTTPS', true);  // 是否强制API通过HTTPS访问（生产环境建议启用）
// define('API_IP_WHITELIST', '');     // API IP白名单，多个IP用逗号分隔，留空表示不限制
// define('API_ALLOW_POST_TOKEN', false); // 默认关闭。仅兼容旧客户端时才开启 POST token（token=...）
// define('API_EXPOSE_PROXY_AUTH', false); // 是否在API返回中包含代理账号密码（默认关闭，仅调试/管理员场景临时开启）
// define('CHART_JS_SRI', 'sha384-...'); // 可选：为 proxy-status 页面 Chart.js CDN 脚本配置 SRI
// 推荐优先使用 Authorization: Bearer YOUR_TOKEN，而不是 URL query token
// 示例: define('API_IP_WHITELIST', '192.168.1.100,10.0.0.1');

// 可信任反向代理 CIDR 列表（用于限流器的客户端 IP 识别）
// 仅当请求来自此列表中的代理时，才信任 X-Forwarded-For / X-Real-IP 等转发头
// 留空（不定义）表示始终使用 REMOTE_ADDR，不信任任何转发头（最安全的默认值）
// ⚠️ 请勿配置 0.0.0.0/0 或 ::/0，这会导致任何来源都被当作可信代理
// 示例（Cloudflare + 本地 Nginx）:
// define('TRUSTED_PROXY_CIDRS', '127.0.0.1/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');
// define('TRUSTED_PROXY_CIDRS', '');

// 分页配置
define('PROXIES_PER_PAGE', 200);  // 每页显示的代理数量
