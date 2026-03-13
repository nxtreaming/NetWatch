<?php
/**
 * NetWatch Web 界面
 */

require_once 'config.php';
require_once 'includes/Config.php';
require_once 'auth.php';
require_once 'database.php';
require_once 'monitor.php';
require_once 'includes/JsonResponse.php';
require_once 'includes/IndexPageController.php';
require_once 'includes/functions.php';
require_once 'includes/ajax_handler.php';

 $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
 $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
 $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
 $allowedPaths = [
     $scriptDir === '' ? '/' : $scriptDir . '/',
     ($scriptDir === '' ? '' : $scriptDir) . '/index.php',
 ];

 if ($requestPath !== '' && !in_array($requestPath, $allowedPaths, true)) {
     http_response_code(404);
     header('Content-Type: text/plain; charset=UTF-8');
     echo '404 Not Found';
     exit;
 }

ensure_valid_config('web');

// 检查登录状态
Auth::requireLogin();

$monitor = new NetworkMonitor();
$pageController = new IndexPageController($monitor);
$action = $_GET['action'] ?? 'dashboard';

// 处理登出请求（仅接受 POST + CSRF，防止 CSRF 强制登出）
if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: index.php');
        exit;
    }
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Auth::validateCsrfToken($csrfToken)) {
        header('Location: index.php');
        exit;
    }
    Auth::logout();
    header('Location: login.php?action=logout');
    exit;
}

if (netwatch_is_ajax_mode_request()) {
    $pageController->handleAjaxRequest($action);
    exit;
}

// 获取分页参数、搜索参数和状态筛选参数
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 200;
$searchTerm = trim((string)($_GET['search'] ?? ''));
$searchTerm = mb_substr($searchTerm, 0, 64);
$statusFilter = $_GET['status'] ?? '';

$dashboardData = $pageController->prepareDashboardData($page, $perPage, $searchTerm, $statusFilter);
$stats = $dashboardData['stats'];
$proxies = $dashboardData['proxies'];
$totalProxies = $dashboardData['totalProxies'];
$totalPages = $dashboardData['totalPages'];
$recentLogs = $dashboardData['recentLogs'];

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch - 网络监控系统</title>
    <link rel="stylesheet" href="includes/style-v2.css?v=<?php echo filemtime(__DIR__ . '/includes/style-v2.css'); ?>">
    <script>
        // 将CSRF Token注入到全局变量
        window.csrfToken = '<?php echo Auth::getCsrfToken(); ?>';
    </script>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <h1>🌐 NetWatch</h1>
                    <p>网络代理监控系统</p>
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
            <a href="import.php" class="nav-link">代理导入</a>
            <a href="token_manager.php" class="nav-link">Token管理</a>
            <a href="proxy-status/" class="nav-link">流量监控</a>
        </div>
    </div>
    
    <div class="container">
        <!-- 统计信息 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-inline total">代理总数: <?php echo $stats['total']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-inline online">在线数量: <?php echo $stats['online']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-inline offline">离线数量: <?php echo $stats['offline']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-inline unknown">未知数量: <?php echo $stats['unknown']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-inline total">平均时间: <?php echo number_format($stats['avg_response_time'], 0); ?>ms</div>
            </div>
        </div>
        
        <!-- 检测功能 -->
        <div class="section">
            <div class="header-actions">
                <div class="search-container">
                    <input type="text" id="search-input" placeholder="搜索IP地址或网段（如: 1.2.3.4 或 1.2.3.x）" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <button class="btn search-btn" onclick="performSearch()">搜索</button>
                    <?php if (!empty($searchTerm) || !empty($statusFilter)): ?>
                    <button class="btn clear-btn" onclick="clearSearch()">清除</button>
                    <?php endif; ?>
                </div>
                
                <div class="status-filter-container">
                    <span class="filter-label">状态：</span>
                    <button class="btn filter-btn <?php echo empty($statusFilter) ? 'active' : ''; ?>" onclick="filterByStatus('')">全部</button>
                    <button class="btn filter-btn <?php echo $statusFilter === 'online' ? 'active' : ''; ?>" onclick="filterByStatus('online')">在线</button>
                    <button class="btn filter-btn <?php echo $statusFilter === 'offline' ? 'active' : ''; ?>" onclick="filterByStatus('offline')">离线</button>
                    <button class="btn filter-btn <?php echo $statusFilter === 'unknown' ? 'active' : ''; ?>" onclick="filterByStatus('unknown')">未知</button>
                </div>
                
                <div class="action-buttons">
                    <button class="btn" onclick="checkAllProxies()">🔍 逐个检测</button>
                    <button class="btn btn-parallel" onclick="checkAllProxiesParallel()" title="使用并行检测，速度更快！每<?php echo PARALLEL_BATCH_SIZE; ?>个IP一组并行执行">🚀 并行检测</button>
                    <button class="btn btn-offline" onclick="checkOfflineProxiesParallel()" title="专门检测离线代理，快速发现恢复的代理">🔧 离线检测</button>
                </div>
            </div>
            
            <?php if (!empty($searchTerm) || !empty($statusFilter)): ?>
            <div class="search-info">
                <span class="search-results">
                    <?php if (!empty($searchTerm) && !empty($statusFilter)): ?>
                        搜索 "<?php echo htmlspecialchars($searchTerm); ?>" 并筛选 "<?php echo $statusFilter; ?>" 状态，找到 <?php echo $totalProxies; ?> 个结果
                    <?php elseif (!empty($searchTerm)): ?>
                        搜索 "<?php echo htmlspecialchars($searchTerm); ?>" 找到 <?php echo $totalProxies; ?> 个结果
                    <?php elseif (!empty($statusFilter)): ?>
                        筛选 "<?php echo $statusFilter; ?>" 状态，找到 <?php echo $totalProxies; ?> 个结果
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- 代理IP列表 -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">代理IP列表</h2>
            </div>
            <div class="table-container">
                <table id="proxies-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>IP</th>
                            <th>类型</th>
                            <th>状态</th>
                            <th>响应时间</th>
                            <th>失败次数</th>
                            <th>最后检查</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proxies as $proxy): ?>
                        <tr>
                            <td data-label="ID"><?php echo $proxy['id']; ?></td>
                            <td data-label="IP"><?php echo htmlspecialchars($proxy['ip']); ?></td>
                            <td data-label="类型"><?php echo strtoupper($proxy['type']); ?></td>
                            <td data-label="状态">
                                <span class="status-badge status-<?php echo $proxy['status']; ?>">
                                    <?php echo $proxy['status']; ?>
                                </span>
                            </td>
                            <td data-label="响应时间"><?php echo number_format($proxy['response_time'], 2); ?>ms</td>
                            <td data-label="失败次数"><?php echo $proxy['failure_count']; ?></td>
                            <td data-label="最后检查"><?php echo formatTime($proxy['last_check'], 'm-d H:i'); // 自动从UTC转换为北京时间 ?></td>
                            <td data-label="操作">
                                <button class="btn btn-small" onclick="checkProxy(<?php echo $proxy['id']; ?>, this)">检查</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- 分页导航 -->
            <?php if ($totalPages > 1): ?>
            <?php 
            // 构建分页URL参数
            $urlParams = [];
            if (!empty($searchTerm)) {
                $urlParams[] = 'search=' . urlencode($searchTerm);
            }
            if (!empty($statusFilter)) {
                $urlParams[] = 'status=' . urlencode($statusFilter);
            }
            $searchParam = !empty($urlParams) ? '&' . implode('&', $urlParams) : '';
            ?>
            <div class="pagination-container" style="padding: 0 20px;">
                <div class="pagination-info">
                    显示第 <?php echo (($page - 1) * $perPage + 1); ?> - <?php echo min($page * $perPage, $totalProxies); ?> 条，共 <?php echo $totalProxies; ?> 条
                    <?php 
                    if (!empty($searchTerm) && !empty($statusFilter)) {
                        echo '搜索和筛选结果';
                    } elseif (!empty($searchTerm)) {
                        echo '搜索结果';
                    } elseif (!empty($statusFilter)) {
                        echo '筛选结果';
                    } else {
                        echo '代理';
                    }
                    ?>
                </div>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1<?php echo $searchParam; ?>" class="page-btn">首页</a>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $searchParam; ?>" class="page-btn">上一页</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?><?php echo $searchParam; ?>" class="page-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $searchParam; ?>" class="page-btn">下一页</a>
                        <a href="?page=<?php echo $totalPages; ?><?php echo $searchParam; ?>" class="page-btn">末页</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- 最近日志 -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">最近检查日志</h2>
                <button class="btn" onclick="refreshLogs()">刷新日志</button>
            </div>
            <div id="logs-container">
                <?php foreach ($recentLogs as $log): ?>
                <div class="log-entry">
                    <span class="log-time"><?php echo formatTime($log['checked_at'], 'm-d H:i:s'); ?></span>
                    <span class="log-status log-<?php echo $log['status']; ?>"><?php echo strtoupper($log['status']); ?></span>
                    <span><?php echo htmlspecialchars($log['ip'] . ':' . $log['port']); ?></span>
                    <span>(<?php echo number_format($log['response_time'], 2); ?>ms)</span>
                    <?php if ($log['error_message']): ?>
                    <span style="color: #f44336;"> - <?php echo htmlspecialchars($log['error_message']); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- 登出表单（POST + CSRF，防止 CSRF 强制登出） -->
    <form id="logout-form" method="POST" action="?action=logout" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    </form>

    <!-- JavaScript 文件引用 -->
    <!-- 新模块化JS（提供NetWatch命名空间和UI组件） -->
    <script src="includes/js/core.js?v=<?php echo filemtime(__DIR__ . '/includes/js/core.js'); ?>"></script>
    <script src="includes/js/ui.js?v=<?php echo filemtime(__DIR__ . '/includes/js/ui.js'); ?>"></script>
    <!-- 现有JS文件（逐步迁移中） -->
    <script src="includes/utils.js?v=<?php echo filemtime(__DIR__ . '/includes/utils.js'); ?>"></script>
    <script src="includes/search.js?v=<?php echo filemtime(__DIR__ . '/includes/search.js'); ?>"></script>
    <script src="includes/proxy-check.js?v=<?php echo filemtime(__DIR__ . '/includes/proxy-check.js'); ?>"></script>
    <script src="includes/offline-simple.js?v=<?php echo filemtime(__DIR__ . '/includes/offline-simple.js'); ?>"></script>
    
    <script>
        function submitLogout() {
            document.getElementById('logout-form').submit();
        }

        // 页面特定的初始化代码
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                // 自动聚焦搜索框（如果有搜索词）
                <?php if (!empty($searchTerm)): ?>
                searchInput.focus();
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
                <?php endif; ?>
            }
        });
        
        // 会话管理初始化
        <?php if (Auth::isLoginEnabled()): ?>
        // 每5分钟检查一次会话状态
        setInterval(checkSession, 5 * 60 * 1000);
        
        // 页面加载时检查一次
        document.addEventListener('DOMContentLoaded', function() {
            checkSession();
        });
        <?php endif; ?>
    </script>
</body>
</html>
