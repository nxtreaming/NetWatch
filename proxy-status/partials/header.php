<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>动态IP池流量监控 - NetWatch</title>
    <link rel="stylesheet" href="../includes/style-v2.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/proxy-status.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="assets/js/proxy-status.js?v=<?php echo time(); ?>" defer></script>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>📊 流量监控</h1>
                    <p>动态IP池流量监控</p>
                </div>
                <?php if (Auth::isLoginEnabled()): ?>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-row">
                            <div class="username">👤 <?php echo htmlspecialchars(Auth::getCurrentUser()); ?></div>
                            <a href="#" class="logout-btn" onclick="event.preventDefault(); showCustomConfirm('确定要退出登录吗？', () => window.location.href='?action=logout'); return false;">退出</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 导航链接 -->
    <div class="container">
        <div class="nav-links">
            <a href="../index.php" class="nav-link">🏠 主页</a>
            <a href="../import.php" class="nav-link">📥 代理导入</a>
            <a href="../import_subnets.php" class="nav-link">🌐 子网导入</a>
            <a href="../token_manager.php" class="nav-link">🔑 Token管理</a>
            <a href="../api_demo.php" class="nav-link">📖 API示例</a>
        </div>
    </div>
