# NetWatch 快速部署指南

## 适用场景
- 服务器已安装 PHP 8.2.3 和 Nginx
- 不想安装 Composer 和 PHPMailer
- 需要快速部署和测试

## 快速部署步骤

### 1. 上传文件
将整个 NetWatch 项目文件夹上传到服务器，例如：
```bash
/var/www/html/netwatch/
```

### 2. 修改邮件配置
由于不使用 PHPMailer，需要修改几个文件：

#### 修改 scheduler.php
将第4行：
```php
require_once 'mailer.php';
```
改为：
```php
require_once 'mailer_simple.php';
```

将第11行：
```php
$this->mailer = new Mailer();
```
改为：
```php
$this->mailer = new SimpleMailer();
```

#### 修改 config.php
简化邮件配置，只保留必要的设置：
```php
// 邮件配置（使用系统 sendmail）
define('SMTP_FROM_EMAIL', 'netwatch@yourdomain.com');
define('SMTP_FROM_NAME', 'NetWatch Monitor');
define('SMTP_TO_EMAIL', 'admin@yourdomain.com');
```

删除或注释掉这些行：
```php
// define('SMTP_HOST', 'smtp.gmail.com');
// define('SMTP_PORT', 587);
// define('SMTP_USERNAME', 'your-email@gmail.com');
// define('SMTP_PASSWORD', 'your-password');
```

### 3. 设置权限
```bash
# 创建必要目录
mkdir -p /var/www/html/netwatch/data
mkdir -p /var/www/html/netwatch/logs

# 设置权限
chown -R www-data:www-data /var/www/html/netwatch
chmod -R 755 /var/www/html/netwatch
chmod -R 777 /var/www/html/netwatch/data
chmod -R 777 /var/www/html/netwatch/logs
```

### 4. 配置 Nginx
创建站点配置：
```bash
nano /etc/nginx/sites-available/netwatch
```

内容：
```nginx
server {
    listen 80;
    server_name your-domain.com;  # 修改为您的域名
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
    
    location ~ /(data|logs)/ {
        deny all;
    }
}
```

启用站点：
```bash
ln -s /etc/nginx/sites-available/netwatch /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

### 5. 测试系统
```bash
cd /var/www/html/netwatch
php test.php
```

### 6. 导入代理数据

#### 方法1：Web界面导入
访问 `http://your-domain.com/import.php`

#### 方法2：命令行导入
创建代理文件 `proxies.txt`：
```
192.168.1.100:1080:socks5
192.168.1.101:8080:http:username:password
10.0.0.1:1080:socks5:user:pass
```

导入：
```bash
php -r "
require_once 'monitor.php';
\$monitor = new NetworkMonitor();
\$result = \$monitor->importFromFile('proxies.txt');
echo '导入成功: ' . \$result['imported'] . ' 个代理\n';
"
```

### 7. 启动监控

#### 使用 crontab（推荐）
```bash
crontab -e
```

添加：
```bash
# 每5分钟执行一次监控
*/5 * * * * cd /var/www/html/netwatch && php scheduler.php >/dev/null 2>&1
```

#### 或者手动运行
```bash
cd /var/www/html/netwatch
php scheduler.php
```

### 8. 访问系统
- 监控面板: `http://your-domain.com/`
- 代理导入: `http://your-domain.com/import.php`

## 邮件配置说明

### 使用系统 sendmail
确保服务器已安装并配置 sendmail：
```bash
# Ubuntu/Debian
apt-get install sendmail

# CentOS/RHEL
yum install sendmail
```

### 配置发件人
在 `config.php` 中设置：
```php
define('SMTP_FROM_EMAIL', 'netwatch@yourdomain.com');
define('SMTP_FROM_NAME', 'NetWatch Monitor');
define('SMTP_TO_EMAIL', 'admin@yourdomain.com');
```

### 测试邮件
```bash
php -r "
require_once 'mailer_simple.php';
\$mailer = new SimpleMailer();
\$result = \$mailer->sendMail('测试邮件', '这是一封测试邮件');
echo \$result ? '邮件发送成功' : '邮件发送失败';
"
```

## 文件修改清单

为了不依赖 PHPMailer，需要修改以下文件：

### 1. scheduler.php
- 第4行：`require_once 'mailer.php';` → `require_once 'mailer_simple.php';`
- 第11行：`$this->mailer = new Mailer();` → `$this->mailer = new SimpleMailer();`

### 2. config.php
删除或注释 SMTP 相关配置，只保留：
```php
define('SMTP_FROM_EMAIL', 'netwatch@yourdomain.com');
define('SMTP_FROM_NAME', 'NetWatch Monitor');
define('SMTP_TO_EMAIL', 'admin@yourdomain.com');
```

### 3. 删除不需要的文件
- `composer.json`
- `mailer.php`（使用 `mailer_simple.php` 代替）

## 优势
- 无需安装 Composer
- 无需下载 PHPMailer
- 部署更简单
- 依赖更少

## 限制
- 邮件功能相对简单
- 不支持 SMTP 认证
- 依赖系统的 sendmail 配置

## 故障排除

### 邮件发送失败
1. 检查 sendmail 是否安装和运行
2. 检查服务器的邮件配置
3. 查看系统邮件日志：`tail -f /var/log/mail.log`

### 权限问题
```bash
chown -R www-data:www-data /var/www/html/netwatch
chmod -R 755 /var/www/html/netwatch
chmod -R 777 /var/www/html/netwatch/data
chmod -R 777 /var/www/html/netwatch/logs
```

### PHP 扩展检查
```bash
php -m | grep -E "(curl|sqlite|pdo)"
```

如果缺少扩展：
```bash
apt-get install php8.2-curl php8.2-sqlite3 php8.2-pdo
systemctl restart php8.2-fpm
```

完成以上步骤后，您的 NetWatch 系统就可以正常运行了！
