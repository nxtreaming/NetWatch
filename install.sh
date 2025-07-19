#!/bin/bash

# NetWatch 自动安装脚本
# 适用于 Ubuntu/Debian 系统

set -e

echo "=== NetWatch 自动安装脚本 ==="
echo "此脚本将自动安装和配置 NetWatch 监控系统"
echo ""

# 检查是否为root用户
if [[ $EUID -ne 0 ]]; then
   echo "请使用 root 用户运行此脚本"
   exit 1
fi

# 获取安装路径
read -p "请输入安装路径 [默认: /var/www/html/netwatch]: " INSTALL_PATH
INSTALL_PATH=${INSTALL_PATH:-/var/www/html/netwatch}

# 获取域名
read -p "请输入域名 [默认: localhost]: " DOMAIN
DOMAIN=${DOMAIN:-localhost}

echo ""
echo "安装配置："
echo "- 安装路径: $INSTALL_PATH"
echo "- 域名: $DOMAIN"
echo ""

read -p "确认开始安装? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "安装已取消"
    exit 1
fi

echo ""
echo "开始安装..."

# 更新系统
echo "1. 更新系统包..."
apt-get update

# 安装必要的软件包
echo "2. 安装必要软件包..."
apt-get install -y nginx php8.2 php8.2-fpm php8.2-curl php8.2-sqlite3 php8.2-mbstring composer unzip

# 创建安装目录
echo "3. 创建安装目录..."
mkdir -p $INSTALL_PATH
mkdir -p $INSTALL_PATH/data
mkdir -p $INSTALL_PATH/logs

# 复制文件（假设脚本在项目根目录）
echo "4. 复制项目文件..."
cp -r . $INSTALL_PATH/
cd $INSTALL_PATH

# 安装 Composer 依赖
echo "5. 安装 PHP 依赖..."
composer install --no-dev --optimize-autoloader

# 设置权限
echo "6. 设置文件权限..."
chown -R www-data:www-data $INSTALL_PATH
chmod -R 755 $INSTALL_PATH
chmod -R 777 $INSTALL_PATH/data
chmod -R 777 $INSTALL_PATH/logs

# 创建配置文件
echo "7. 创建配置文件..."
if [ ! -f "$INSTALL_PATH/config.php" ]; then
    cp $INSTALL_PATH/config.example.php $INSTALL_PATH/config.php
    echo "配置文件已创建，请编辑 $INSTALL_PATH/config.php 设置邮件参数"
fi

# 配置 Nginx
echo "8. 配置 Nginx..."
cat > /etc/nginx/sites-available/netwatch << EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $INSTALL_PATH;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # 安全配置
    location ~ /\. {
        deny all;
    }
    
    location ~ /(data|logs|vendor)/ {
        deny all;
    }
    
    location ~ \.(txt|md)$ {
        deny all;
    }
}
EOF

# 启用站点
ln -sf /etc/nginx/sites-available/netwatch /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# 创建 systemd 服务
echo "9. 创建系统服务..."
cat > /etc/systemd/system/netwatch.service << EOF
[Unit]
Description=NetWatch Monitor Service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=$INSTALL_PATH
ExecStart=/usr/bin/php scheduler.php
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

# 启用并启动服务
systemctl daemon-reload
systemctl enable netwatch

# 运行测试
echo "10. 运行系统测试..."
cd $INSTALL_PATH
php test.php

echo ""
echo "=== 安装完成 ==="
echo ""
echo "访问地址："
echo "- 监控面板: http://$DOMAIN/"
echo "- 代理导入: http://$DOMAIN/import.php"
echo ""
echo "下一步操作："
echo "1. 编辑配置文件: $INSTALL_PATH/config.php"
echo "2. 导入代理数据: 访问 http://$DOMAIN/import.php"
echo "3. 启动监控服务: systemctl start netwatch"
echo "4. 查看服务状态: systemctl status netwatch"
echo ""
echo "日志文件位置："
echo "- 应用日志: $INSTALL_PATH/logs/"
echo "- 服务日志: journalctl -u netwatch"
echo ""
echo "如需帮助，请查看 $INSTALL_PATH/README.md"
