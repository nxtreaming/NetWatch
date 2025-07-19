# NetWatch 部署指南

## 服务器环境要求

- PHP 8.2.3+ (已安装)
- Nginx + PHP-FPM (已配置)
- cURL 扩展
- SQLite 扩展
- 文件系统写权限

## 部署步骤

### 1. 上传文件到服务器

将整个 NetWatch 项目文件夹上传到您的服务器 Web 目录，例如：
```
/var/www/html/netwatch/
```

### 2. 安装 PHPMailer

在服务器上执行以下命令：

```bash
cd /var/www/html/netwatch
composer install
```

如果服务器没有 Composer，可以先安装：
```bash
# 下载 Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

# 然后安装依赖
composer install
```

### 3. 配置系统

复制配置文件并修改：
```bash
cp config.example.php config.php
nano config.php
```

修改以下配置项：
```php
// 邮件配置 - 修改为您的邮箱设置
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_TO_EMAIL', 'admin@yourdomain.com');

// 监控配置 - 根据需要调整
define('CHECK_INTERVAL', 300);  // 5分钟检查一次
define('TIMEOUT', 10);          // 10秒超时
define('ALERT_THRESHOLD', 3);   // 连续失败3次后发邮件
```

### 4. 设置目录权限

```bash
# 创建必要的目录
mkdir -p data logs

# 设置权限
chown -R www-data:www-data /var/www/html/netwatch
chmod -R 755 /var/www/html/netwatch
chmod -R 777 /var/www/html/netwatch/data
chmod -R 777 /var/www/html/netwatch/logs
```

### 5. 配置 Nginx

在 Nginx 配置中添加或修改站点配置：

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/html/netwatch;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 安全配置
    location ~ /\. {
        deny all;
    }
    
    location ~ /(data|logs|vendor)/ {
        deny all;
    }
}
```

重启 Nginx：
```bash
systemctl reload nginx
```

### 6. 测试系统

```bash
cd /var/www/html/netwatch
php test.php
```

### 7. 导入代理数据

创建代理数据文件 `proxies.txt`：
```
# 格式: IP:端口:类型:用户名:密码
192.168.1.100:1080:socks5
192.168.1.101:8080:http:username:password
10.0.0.1:1080:socks5:user:pass
```

然后导入：
```bash
php -r "
require_once 'monitor.php';
\$monitor = new NetworkMonitor();
\$result = \$monitor->importFromFile('proxies.txt');
echo '导入成功: ' . \$result['imported'] . ' 个代理\n';
"
```

### 8. 启动监控服务

#### 方法1: 使用 systemd 服务 (推荐)

创建服务文件：
```bash
sudo nano /etc/systemd/system/netwatch.service
```

内容：
```ini
[Unit]
Description=NetWatch Monitor Service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/netwatch
ExecStart=/usr/bin/php scheduler.php
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

启动服务：
```bash
sudo systemctl daemon-reload
sudo systemctl enable netwatch
sudo systemctl start netwatch
sudo systemctl status netwatch
```

#### 方法2: 使用 crontab

```bash
crontab -e
```

添加：
```bash
# 每5分钟执行一次监控
*/5 * * * * cd /var/www/html/netwatch && php -f scheduler.php >/dev/null 2>&1
```

### 9. 访问系统

- 监控面板: `http://your-domain.com/netwatch/`
- 代理导入: `http://your-domain.com/netwatch/import.php`

## 批量导入代理

### 准备代理数据

创建一个文本文件，每行一个代理，格式如下：
```
IP:端口:类型:用户名:密码
```

示例：
```
192.168.1.100:1080:socks5
192.168.1.101:8080:http:user1:pass1
10.0.0.1:1080:socks5:user2:pass2
203.0.113.1:3128:http
```

### 导入方法

#### 方法1: Web界面导入
访问 `http://your-domain.com/netwatch/import.php`，粘贴或上传代理数据

#### 方法2: 命令行导入
```bash
cd /var/www/html/netwatch
php -r "
require_once 'monitor.php';
\$monitor = new NetworkMonitor();
\$result = \$monitor->importFromFile('your-proxies.txt');
echo '导入结果: 成功 ' . \$result['imported'] . ' 个，失败 ' . count(\$result['errors']) . ' 个\n';
if (!empty(\$result['errors'])) {
    echo '错误详情:\n';
    foreach (\$result['errors'] as \$error) {
        echo '  - ' . \$error . '\n';
    }
}
"
```

## 邮件配置

### Gmail 配置示例

1. 启用两步验证
2. 生成应用专用密码
3. 在 `config.php` 中配置：

```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');  // 应用专用密码
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('SMTP_TO_EMAIL', 'admin@yourdomain.com');
```

### 其他邮箱配置

#### QQ邮箱
```php
define('SMTP_HOST', 'smtp.qq.com');
define('SMTP_PORT', 587);
```

#### 163邮箱
```php
define('SMTP_HOST', 'smtp.163.com');
define('SMTP_PORT', 25);
```

## 监控和维护

### 查看日志
```bash
# 查看系统日志
tail -f /var/www/html/netwatch/logs/netwatch_$(date +%Y-%m-%d).log

# 查看服务状态
systemctl status netwatch

# 查看服务日志
journalctl -u netwatch -f
```

### 数据库维护
```bash
# 清理30天前的日志
php -r "
require_once 'scheduler.php';
\$scheduler = new Scheduler();
\$scheduler->cleanupOldLogs(30);
"
```

### 性能优化

对于2400+代理的监控，建议：

1. **调整检查间隔**：
```php
define('CHECK_INTERVAL', 600);  // 10分钟检查一次
```

2. **增加超时时间**：
```php
define('TIMEOUT', 15);  // 15秒超时
```

3. **分批检查**：
修改 `monitor.php` 中的 `checkAllProxies()` 方法，添加延迟：
```php
foreach ($proxies as $proxy) {
    $result = $this->checkProxy($proxy);
    $results[] = array_merge($proxy, $result);
    
    // 每检查10个代理休息1秒
    if (count($results) % 10 === 0) {
        sleep(1);
    }
}
```

## 故障排除

### 常见问题

1. **权限错误**
```bash
chown -R www-data:www-data /var/www/html/netwatch
chmod -R 755 /var/www/html/netwatch
chmod -R 777 /var/www/html/netwatch/data
chmod -R 777 /var/www/html/netwatch/logs
```

2. **PHP扩展缺失**
```bash
# 安装必要扩展
apt-get install php8.2-curl php8.2-sqlite3
systemctl restart php8.2-fpm
```

3. **邮件发送失败**
- 检查防火墙是否阻止SMTP端口
- 确认邮箱密码正确
- 使用应用专用密码而非登录密码

4. **数据库连接失败**
- 检查data目录权限
- 确保SQLite扩展已安装

### 调试命令

```bash
# 测试单个代理
php -r "
require_once 'monitor.php';
\$monitor = new NetworkMonitor();
\$proxies = \$monitor->db->getAllProxies();
if (!empty(\$proxies)) {
    \$result = \$monitor->checkProxy(\$proxies[0]);
    print_r(\$result);
}
"

# 发送测试邮件
php -r "
require_once 'mailer.php';
\$mailer = new Mailer();
\$result = \$mailer->sendMail('测试邮件', '这是一封测试邮件');
echo \$result ? '邮件发送成功' : '邮件发送失败';
"
```

## 安全建议

1. **限制访问**：
```nginx
# 只允许特定IP访问
location /netwatch {
    allow 192.168.1.0/24;
    allow 10.0.0.0/8;
    deny all;
    
    try_files $uri $uri/ /index.php?$query_string;
}
```

2. **HTTPS配置**：
```nginx
server {
    listen 443 ssl;
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    # ... 其他配置
}
```

3. **定期备份**：
```bash
# 备份数据库
cp /var/www/html/netwatch/data/netwatch.db /backup/netwatch_$(date +%Y%m%d).db

# 备份配置
cp /var/www/html/netwatch/config.php /backup/config_$(date +%Y%m%d).php
```

完成以上步骤后，您的NetWatch系统就可以正常运行了！
