<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>代理流量监控 - NetWatch</title>
    <link rel="stylesheet" href="proxy-status.css?v=<?php echo time(); ?>">
    <script src="assets/js/proxy-status.js?v=<?php echo time(); ?>" defer></script>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-wrapper">
                <div class="header-left">
                    <h1>🌐 IP池流量监控</h1>
                    <p>更新时间<?php 
                        if ($realtimeData['updated_at']) {
                            // 将UTC时间转换为北京时间（UTC+8）
                            $utcTime = strtotime($realtimeData['updated_at']);
                            $beijingTime = $utcTime + (8 * 3600);
                            echo ' (' . date('m/d H:i:s', $beijingTime) . ')';
                        }
                    ?></p>
                </div>
                <div class="user-info">
                    <a href="../index.php" class="nav-btn">🏠 主页</a>
                    <span>👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <a href="?action=logout" class="logout-btn" onclick="return confirm('确定要退出登录吗？')">🚪 退出</a>
                </div>
            </div>
        </div>
    </div>
