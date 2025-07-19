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
define('TEST_URL', 'http://httpbin.org/ip');  // 用于测试代理的URL
define('TEST_HTTPS_URL', 'https://httpbin.org/ip');  // 用于测试HTTPS代理的URL

// 日志配置
define('LOG_PATH', __DIR__ . '/logs/');
define('LOG_LEVEL', 'INFO');  // DEBUG, INFO, WARNING, ERROR

// 时区设置
date_default_timezone_set('Asia/Shanghai');
