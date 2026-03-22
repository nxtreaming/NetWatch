<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <?php
        $proxyStatusBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/proxy-status/index.php')), '/');
        $appRootPath = $proxyStatusBasePath === '' ? '/' : dirname($proxyStatusBasePath) . '/';
        $appRootPath = str_replace('//', '/', $appRootPath);
        $chartJsSRI = defined('CHART_JS_SRI') ? trim((string) CHART_JS_SRI) : '';
        $chartJsIntegrityAttr = $chartJsSRI !== '' ? ' integrity="' . htmlspecialchars($chartJsSRI, ENT_QUOTES, 'UTF-8') . '" crossorigin="anonymous"' : '';
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>动态IP池流量监控 - NetWatch</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($appRootPath . 'includes/style-v2.css?v=' . filemtime(__DIR__ . '/../../includes/style-v2.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(($proxyStatusBasePath === '' ? '' : $proxyStatusBasePath . '/') . 'assets/css/proxy-status.css?v=' . filemtime(__DIR__ . '/../assets/css/proxy-status.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"<?php echo $chartJsIntegrityAttr; ?>></script>
    <script src="<?php echo htmlspecialchars(($proxyStatusBasePath === '' ? '' : $proxyStatusBasePath . '/') . 'assets/js/proxy-status.js?v=' . filemtime(__DIR__ . '/../assets/js/proxy-status.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
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
                            <button type="button" class="logout-btn" onclick="showCustomConfirm('确定要退出登录吗？', () => submitLogout()); return false;">退出</button>
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
            <a href="<?php echo htmlspecialchars($appRootPath . 'index.php', ENT_QUOTES, 'UTF-8'); ?>" class="nav-link">主页</a>
        </div>
    </div>
