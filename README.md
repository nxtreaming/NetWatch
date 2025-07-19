# NetWatch - 网络代理监控系统

NetWatch 是一个基于PHP的网络代理监控系统，专门用于监控大量SOCKS5和HTTP代理的可用性状态。

## 功能特性

- 🌐 **多协议支持**: 支持SOCKS5和HTTP代理监控
- 📊 **实时监控**: 实时检测代理服务器状态和响应时间
- 📧 **邮件通知**: 代理故障时自动发送邮件通知
- 📈 **Web界面**: 简洁美观的Web管理界面
- 📝 **日志记录**: 详细的监控日志和历史记录
- 🔄 **自动调度**: 后台定时任务自动执行监控
- 📥 **批量导入**: 支持批量导入代理配置
- 💾 **SQLite数据库**: 轻量级数据库，无需额外配置

## 系统要求

- PHP 8.0+
- cURL扩展
- SQLite扩展
- 已配置的Web服务器 (Nginx + PHP-FPM)

## 安装步骤

### 1. 安装依赖

```bash
# 安装Composer依赖
composer install
```

### 2. 配置系统

编辑 `config.php` 文件，修改以下配置：

```php
// 邮件配置
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-password');
define('SMTP_TO_EMAIL', 'admin@example.com');

// 监控配置
define('CHECK_INTERVAL', 300);  // 检查间隔（秒）
define('TIMEOUT', 10);          // 超时时间（秒）
define('ALERT_THRESHOLD', 3);   // 连续失败次数阈值
```

### 3. 测试系统

```bash
php test.php
```

### 4. 导入代理

访问 `http://your-domain/netwatch/import.php` 或使用命令行：

```bash
# 从文件导入
php import.php
```

代理格式示例：
```
192.168.1.100:1080:socks5
192.168.1.101:8080:http:username:password
10.0.0.1:1080:socks5:user:pass
```

### 5. 启动监控

```bash
# 启动后台监控服务
php scheduler.php
```

或者使用systemd服务：

```bash
# 创建服务文件
sudo nano /etc/systemd/system/netwatch.service
```

服务文件内容：
```ini
[Unit]
Description=NetWatch Monitor Service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/netwatch
ExecStart=/usr/bin/php scheduler.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

启动服务：
```bash
sudo systemctl enable netwatch
sudo systemctl start netwatch
```

## 使用说明

### Web界面

访问 `http://your-domain/netwatch/` 查看监控面板：

- **统计概览**: 显示代理总数、在线/离线状态、平均响应时间
- **代理列表**: 显示所有代理的详细状态信息
- **检查日志**: 显示最近的监控检查记录
- **手动检查**: 可以手动触发单个或全部代理检查

### 代理导入

访问 `http://your-domain/netwatch/import.php` 进行批量导入：

- 支持文本粘贴导入
- 支持文件上传导入
- 自动跳过格式错误的行
- 显示导入结果统计

### 邮件通知

系统会在以下情况发送邮件：

- **故障通知**: 代理连续失败达到阈值时
- **每日报告**: 每天上午9点发送系统状态报告

## 配置文件说明

### config.php

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `DB_PATH` | SQLite数据库文件路径 | `./data/netwatch.db` |
| `SMTP_HOST` | SMTP服务器地址 | `smtp.gmail.com` |
| `SMTP_PORT` | SMTP端口 | `587` |
| `CHECK_INTERVAL` | 检查间隔（秒） | `300` |
| `TIMEOUT` | 连接超时时间（秒） | `10` |
| `ALERT_THRESHOLD` | 故障通知阈值 | `3` |
| `TEST_URL` | 测试URL | `http://httpbin.org/ip` |

## 目录结构

```
netwatch/
├── config.php          # 配置文件
├── database.php        # 数据库操作类
├── monitor.php         # 监控核心类
├── logger.php          # 日志记录类
├── mailer.php          # 邮件发送类
├── scheduler.php       # 定时任务调度器
├── index.php           # Web监控面板
├── import.php          # 代理导入页面
├── test.php            # 系统测试脚本
├── composer.json       # Composer配置
├── README.md           # 说明文档
├── data/               # 数据目录
│   └── netwatch.db     # SQLite数据库
└── logs/               # 日志目录
    └── netwatch_*.log  # 日志文件
```

## API接口

系统提供简单的AJAX API：

- `GET /?ajax=1&action=stats` - 获取统计信息
- `GET /?ajax=1&action=check&proxy_id=1` - 检查指定代理
- `GET /?ajax=1&action=logs` - 获取最近日志

## 故障排除

### 常见问题

1. **数据库连接失败**
   - 检查data目录权限
   - 确保PHP有SQLite扩展

2. **邮件发送失败**
   - 检查SMTP配置
   - 确认邮箱密码正确
   - 可能需要使用应用专用密码

3. **代理检查失败**
   - 检查网络连接
   - 确认curl扩展已安装
   - 检查防火墙设置

4. **权限问题**
   - 确保Web服务器对data和logs目录有写权限
   - 检查文件所有者和权限设置

### 日志查看

```bash
# 查看最新日志
tail -f logs/netwatch_$(date +%Y-%m-%d).log

# 查看错误日志
grep ERROR logs/netwatch_*.log
```

## 性能优化

对于大量代理（2400+）的监控：

1. **调整检查间隔**: 根据需要调整`CHECK_INTERVAL`
2. **并发检查**: 可以修改代码实现多线程检查
3. **数据库优化**: 定期清理旧日志数据
4. **缓存机制**: 可以添加Redis缓存提高性能

## 许可证

MIT License

## 支持

如有问题或建议，请联系系统管理员。
