<?php
/**
 * NetWatch Web 界面
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'database.php';
require_once 'monitor.php';
require_once 'includes/functions.php';
require_once 'includes/ajax_handler.php';

// 并行检测配置常量
define('PARALLEL_MAX_PROCESSES', 12);   // 最大并行进程数
define('PARALLEL_BATCH_SIZE', 400);     // 每批次代理数量

// 设置时区为中国标准时间
date_default_timezone_set('Asia/Shanghai');

// 检查登录状态
Auth::requireLogin();

$monitor = new NetworkMonitor();
$action = $_GET['action'] ?? 'dashboard';

// 处理登出请求
if ($action === 'logout') {
    Auth::logout();
    header('Location: login.php?action=logout');
    exit;
}

// 处理AJAX请求
// 添加更严格的AJAX请求检查，防止移动端浏览器错误处理URL参数
if (isset($_GET['ajax'])) {
    $isValidAjax = isValidAjaxRequest();
    
    // 如果有ajax参数但不是真正的AJAX请求，记录日志并重定向
    if (!$isValidAjax) {
        // 检查是否为移动端
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isMobile = strpos($userAgent, 'Mobile') !== false || 
                    strpos($userAgent, 'Android') !== false || 
                    strpos($userAgent, 'iPhone') !== false || 
                    strpos($userAgent, 'iPad') !== false;
        
        // 记录调试信息
        $debugInfo = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_agent' => $userAgent,
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'none',
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? 'none',
            'x_requested_with' => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? 'none',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'action' => $action,
            'is_mobile' => $isMobile
        ];
        
        // 将调试信息写入日志文件
        file_put_contents('debug_ajax_mobile.log', json_encode($debugInfo) . "\n", FILE_APPEND | LOCK_EX);
        
        // 重定向到主页，清除ajax参数
        $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
        $params = $_GET;
        unset($params['ajax']);
        if (!empty($params)) {
            $redirectUrl .= '?' . http_build_query($params);
        }
        
        // 对于移动端，使用更强的重定向方式
        if ($isMobile) {
            // 先尝试header重定向
            header('Location: ' . $redirectUrl, true, 302);
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        
        // 使用JavaScript重定向作为备用方案（防止header重定向失败）
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>重定向中...</title>';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '">';
        echo '</head><body>';
        echo '<script>window.location.replace("' . htmlspecialchars($redirectUrl) . '");</script>';
        echo '<p>正在重定向到正确页面...</p>';
        echo '<p><a href="' . htmlspecialchars($redirectUrl) . '">如果没有自动跳转，请点击这里</a></p>';
        echo '</body></html>';
        exit;
    }
    
    // 只有真正的AJAX请求才返回JSON
    if ($isValidAjax) {
        header('Content-Type: application/json');
        
        // 统一检查登录状态（除了sessionCheck操作）
        if ($action !== 'sessionCheck' && !Auth::isLoggedIn()) {
            echo json_encode([
                'success' => false,
                'error' => 'unauthorized',
                'message' => '登录已过期，请重新登录'
            ]);
            exit;
        }
        
        // 使用AJAX处理器处理请求
        $ajaxHandler = new AjaxHandler($monitor);
        $ajaxHandler->handleRequest($action);
        exit;
    }
}

// 获取分页参数、搜索参数和状态筛选参数
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 200;
$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// 获取数据
$stats = $monitor->getStats();

if (!empty($searchTerm) || !empty($statusFilter)) {
    // 搜索或筛选模式 - 直接使用数据库对象实现筛选
    $db = new Database();
    $proxies = $db->searchProxies($searchTerm, $page, $perPage, $statusFilter);
    // 过滤敏感信息
    $proxies = array_map(function($proxy) {
        unset($proxy['username']);
        unset($proxy['password']);
        return $proxy;
    }, $proxies);
    $totalProxies = $db->getSearchCount($searchTerm, $statusFilter);
    $totalPages = ceil($totalProxies / $perPage);
} else {
    // 正常分页模式
    $totalProxies = $monitor->getProxyCount();
    $totalPages = ceil($totalProxies / $perPage);
    $proxies = $monitor->getProxiesPaginatedSafe($page, $perPage);
}

$recentLogs = $monitor->getRecentLogs(20);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch - 网络监控系统</title>
    <link rel="stylesheet" href="includes/style-v2.css?v=<?php echo time(); ?>">
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
                            <a href="?action=logout" class="logout-btn" onclick="return confirm('确定要退出登录吗？')">退出</a>
                        </div>
                        <div class="session-time">登录时间：<?php 
                            $loginTime = Auth::getLoginTime();
                            echo $loginTime ? date('m-d H:i', $loginTime) : 'N/A';
                        ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- 统计信息 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number total"><?php echo $stats['total']; ?></div>
                <div class="stat-label">总代理数</div>
            </div>
            <div class="stat-card">
                <div class="stat-number online"><?php echo $stats['online']; ?></div>
                <div class="stat-label">在线数量</div>
            </div>
            <div class="stat-card">
                <div class="stat-number offline"><?php echo $stats['offline']; ?></div>
                <div class="stat-label">离线数量</div>
            </div>
            <div class="stat-card">
                <div class="stat-number unknown"><?php echo $stats['unknown']; ?></div>
                <div class="stat-label">未知</div>
            </div>
            <div class="stat-card">
                <div class="stat-number total"><?php echo number_format($stats['avg_response_time'], 0); ?>ms</div>
                <div class="stat-label">平均响应时间</div>
            </div>
        </div>
        
        <!-- 代理列表 -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">代理服务器列表</h2>
                <div class="header-actions">
                    <div class="search-container">
                        <input type="text" id="search-input" placeholder="搜索IP地址或网段（如: 1.2.3.4 或 1.2.3.x）" value="<?php echo htmlspecialchars($searchTerm); ?>">
                        <button class="btn search-btn" onclick="performSearch()">搜索</button>
                        <?php if (!empty($searchTerm) || !empty($statusFilter)): ?>
                        <button class="btn clear-btn" onclick="clearSearch()">清除</button>
                        <?php endif; ?>
                    </div>
                    <div class="controls-row">
                        <div class="status-filter-container">
                            <span class="filter-label">状态：</span>
                            <button class="btn filter-btn <?php echo empty($statusFilter) ? 'active' : ''; ?>" onclick="filterByStatus('')">全部</button>
                            <button class="btn filter-btn <?php echo $statusFilter === 'online' ? 'active' : ''; ?>" onclick="filterByStatus('online')">在线</button>
                            <button class="btn filter-btn <?php echo $statusFilter === 'offline' ? 'active' : ''; ?>" onclick="filterByStatus('offline')">离线</button>
                            <button class="btn filter-btn <?php echo $statusFilter === 'unknown' ? 'active' : ''; ?>" onclick="filterByStatus('unknown')">未知</button>
                        </div>
                        <div class="action-buttons">
                            <button class="btn" onclick="checkAllProxies()">🔍 逐个检测</button>
                            <button class="btn btn-parallel" onclick="checkAllProxiesParallel()" title="使用并行检测，速度更快！每400个IP一组并行执行">🚀 并行检测</button>
                        </div>
                    </div>
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
            <div class="table-container">
                <table id="proxies-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>地址</th>
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
                            <td><?php echo $proxy['id']; ?></td>
                            <td><?php echo htmlspecialchars($proxy['ip']); ?></td>
                            <td><?php echo strtoupper($proxy['type']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $proxy['status']; ?>">
                                    <?php echo $proxy['status']; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($proxy['response_time'], 2); ?>ms</td>
                            <td><?php echo $proxy['failure_count']; ?></td>
                            <td><?php echo formatTime($proxy['last_check'], 'm-d H:i'); // 自动从UTC转换为北京时间 ?></td>
                            <td>
                                <button class="btn btn-small" onclick="checkProxy(<?php echo $proxy['id']; ?>)">检查</button>
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
    
    <button class="refresh-btn" onclick="refreshAll()" title="刷新所有数据">
        🔄
    </button>
    
    <script>
        // 自动刷新
        setInterval(refreshStats, 30000); // 30秒刷新统计
        setInterval(refreshLogs, 60000);  // 60秒刷新日志
        
        function refreshStats() {
            fetch('?ajax=1&action=stats')
                .then(response => response.json())
                .then(data => {
                    document.querySelector('.stats-grid .stat-card:nth-child(1) .stat-number').textContent = data.total;
                    document.querySelector('.stats-grid .stat-card:nth-child(2) .stat-number').textContent = data.online;
                    document.querySelector('.stats-grid .stat-card:nth-child(3) .stat-number').textContent = data.offline;
                    document.querySelector('.stats-grid .stat-card:nth-child(4) .stat-number').textContent = data.unknown;
                    document.querySelector('.stats-grid .stat-card:nth-child(5) .stat-number').textContent = Math.round(data.avg_response_time) + 'ms';
                })
                .catch(error => console.error('刷新统计失败:', error));
        }
        
        function refreshLogs() {
            fetch('?ajax=1&action=logs')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('logs-container');
                    container.innerHTML = '';
                    
                    data.forEach(log => {
                        const div = document.createElement('div');
                        div.className = 'log-entry';
                        
                        const time = new Date(log.checked_at).toLocaleString('zh-CN', {
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit'
                        });
                        
                        let html = `
                            <span class="log-time">${time}</span>
                            <span class="log-status log-${log.status}">${log.status.toUpperCase()}</span>
                            <span>${log.ip}:${log.port}</span>
                            <span>(${parseFloat(log.response_time).toFixed(2)}ms)</span>
                        `;
                        
                        if (log.error_message) {
                            html += `<span style="color: #f44336;"> - ${log.error_message}</span>`;
                        }
                        
                        div.innerHTML = html;
                        container.appendChild(div);
                    });
                })
                .catch(error => console.error('刷新日志失败:', error));
        }
        
        function checkProxy(proxyId) {
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = '检查中...';
            btn.disabled = true;
            
            fetch(`?ajax=1&action=check&proxy_id=${proxyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('检查失败: ' + data.error);
                    } else {
                        // 刷新页面以显示最新状态
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('检查代理失败:', error);
                    alert('检查失败，请稍后重试');
                })
                .finally(() => {
                    btn.textContent = originalText;
                    btn.disabled = false;
                });
        }
        
        async function checkAllProxies() {
            if (confirm('确定要检查所有代理吗？这可能需要一些时间。')) {
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '检查中...';
                btn.disabled = true;
                
                // 创建进度显示界面
                const progressDiv = document.createElement('div');
                progressDiv.id = 'check-progress';
                progressDiv.style.cssText = `
                    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                    background: white; padding: 30px; border-radius: 15px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.3); z-index: 1000;
                    text-align: center; min-width: 400px; max-width: 500px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                `;
                
                progressDiv.innerHTML = `
                    <h3 style="margin: 0 0 20px 0; color: #333;">🔍 正在检查所有代理</h3>
                    <div id="progress-info" style="margin-bottom: 20px; color: #666;">正在获取代理列表...</div>
                    <div style="background: #f0f0f0; border-radius: 10px; height: 20px; margin: 20px 0; overflow: hidden;">
                        <div id="progress-bar" style="background: linear-gradient(90deg, #4CAF50, #45a049); height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 10px;"></div>
                    </div>
                    <div id="progress-stats" style="font-size: 14px; color: #888;">准备开始...</div>
                    <button id="cancel-check" style="margin-top: 15px; padding: 8px 16px; background: #f44336; color: white; border: none; border-radius: 5px; cursor: pointer;">取消检查</button>
                `;
                
                document.body.appendChild(progressDiv);
                
                let cancelled = false;
                document.getElementById('cancel-check').onclick = () => {
                    cancelled = true;
                    document.body.removeChild(progressDiv);
                    btn.textContent = originalText;
                    btn.disabled = false;
                };
                
                try {
                    // 更新状态为正在准备
                    document.getElementById('progress-info').textContent = '正在连接数据库...';
                    
                    // 记录开始时间
                    const prepareStartTime = Date.now();
                    
                    // 首先尝试使用缓存的代理数量
                    let totalProxies = getCachedProxyCount();
                    let countData = null;
                    
                    if (totalProxies !== null) {
                        // 使用缓存数据
                        document.getElementById('progress-info').textContent = `使用缓存数据: ${totalProxies} 个代理`;
                        countData = { cached: true, execution_time: 0 };
                    } else {
                        // 缓存无效，重新查询
                        document.getElementById('progress-info').textContent = '正在获取代理数量...';
                        const countResponse = await fetch('?ajax=1&action=getProxyCount');
                        countData = await countResponse.json();
                        
                        if (!countData.success) {
                            throw new Error(countData.error || '获取代理数量失败');
                        }
                        
                        totalProxies = countData.count;
                        
                        // 更新缓存
                        cachedProxyCount = totalProxies;
                        cacheTimestamp = Date.now();
                    }
                    if (totalProxies === 0) {
                        alert('没有找到代理数据，请先导入代理。');
                        document.body.removeChild(progressDiv);
                        btn.textContent = originalText;
                        btn.disabled = false;
                        return;
                    }
                    
                    // 计算准备时间
                    const prepareTime = Date.now() - prepareStartTime;
                    
                    // 显示缓存状态和执行时间
                    const cacheStatus = countData.cached ? '缓存' : '数据库';
                    const queryTime = countData.execution_time || 0;
                    
                    // 更新进度信息，显示详细信息
                    document.getElementById('progress-info').textContent = `找到 ${totalProxies} 个代理 (查询: ${queryTime}ms ${cacheStatus}, 总用时: ${prepareTime}ms)，开始检查...`;
                    
                    // 如果准备时间较长，显示更长时间让用户看到
                    const displayTime = prepareTime > 1000 ? 1500 : 500;
                    await new Promise(resolve => setTimeout(resolve, displayTime));
                    
                    // 分批检查代理
                    const batchSize = 20; // 每批检查20个代理
                    let checkedCount = 0;
                    let onlineCount = 0;
                    let offlineCount = 0;
                    
                    for (let offset = 0; offset < totalProxies && !cancelled; offset += batchSize) {
                        try {
                            // 设置超时时间为2分钟
                            const controller = new AbortController();
                            const timeoutId = setTimeout(() => controller.abort(), 120000);
                            
                            const batchResponse = await fetch(`?ajax=1&action=checkBatch&offset=${offset}&limit=${batchSize}`, {
                                signal: controller.signal
                            });
                            
                            clearTimeout(timeoutId);
                            
                            if (!batchResponse.ok) {
                                throw new Error(`HTTP ${batchResponse.status}: ${batchResponse.statusText}`);
                            }
                            
                            const batchData = await batchResponse.json();
                            
                            if (!batchData.success) {
                                throw new Error(batchData.error || '批量检查失败');
                            }
                            
                            // 更新统计
                            checkedCount += batchData.results.length;
                            onlineCount += batchData.results.filter(r => r.status === 'online').length;
                            offlineCount += batchData.results.filter(r => r.status === 'offline').length;
                            
                            // 更新进度条
                            const progress = (checkedCount / totalProxies) * 100;
                            document.getElementById('progress-bar').style.width = progress + '%';
                            
                            // 更新进度信息，显示执行时间
                            const executionTime = batchData.execution_time ? ` (用时: ${batchData.execution_time}ms)` : '';
                            document.getElementById('progress-info').textContent = 
                                `正在检查第 ${Math.min(offset + batchSize, totalProxies)} / ${totalProxies} 个代理${executionTime}...`;
                            
                            // 更新统计信息
                            document.getElementById('progress-stats').textContent = 
                                `已检查: ${checkedCount} | 在线: ${onlineCount} | 离线: ${offlineCount}`;
                            
                            // 减少延迟时间，提高整体速度
                            await new Promise(resolve => setTimeout(resolve, 100));
                            
                        } catch (error) {
                            if (error.name === 'AbortError') {
                                throw new Error(`第 ${offset + 1}-${Math.min(offset + batchSize, totalProxies)} 个代理检查超时，请检查网络连接或减少批量大小`);
                            }
                            throw error;
                        }
                    }
                    
                    if (!cancelled) {
                        // 检查是否有失败的代理需要发送邮件
                        try {
                            const alertResponse = await fetch('?ajax=1&action=checkFailedProxies');
                            const alertData = await alertResponse.json();
                            
                            let alertMessage = '';
                            if (alertData.success && alertData.failed_proxies > 0) {
                                alertMessage = alertData.email_sent ? 
                                    `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理，已发送邮件通知！` :
                                    `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理。`;
                            }
                            
                            document.body.removeChild(progressDiv);
                            
                            alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个${alertMessage}\n\n页面将自动刷新显示最新状态`);
                            
                        } catch (alertError) {
                            document.body.removeChild(progressDiv);
                            alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个\n\n页面将自动刷新显示最新状态`);
                        }
                        
                        // 刷新页面显示最新状态
                        location.reload();
                    }
                    
                } catch (error) {
                    if (!cancelled) {
                        document.body.removeChild(progressDiv);
                        console.error('检查所有代理失败:', error);
                        alert('❌ 检查失败: ' + error.message);
                    }
                } finally {
                    if (!cancelled) {
                        btn.textContent = originalText;
                        btn.disabled = false;
                    }
                }
            }
        }
        
        function refreshAll() {
            const btn = document.querySelector('.refresh-btn');
            btn.style.transform = 'rotate(360deg)';
            
            refreshStats();
            refreshLogs();
            
            // 在分页模式下刷新当前页面
            setTimeout(() => {
                location.reload();
            }, 1000);
        }
        
        // 搜索功能
        function performSearch() {
            const searchInput = document.getElementById('search-input');
            const searchTerm = searchInput.value.trim();
            
            if (searchTerm) {
                // 跳转到搜索结果页面
                window.location.href = '?search=' + encodeURIComponent(searchTerm);
            } else {
                // 如果搜索词为空，清除搜索
                clearSearch();
            }
        }
        
        function clearSearch() {
            // 清除搜索，返回主页面
            window.location.href = '?';
        }
        
        // 检查所有代理函数
        async function checkAllProxies() {
            const btn = event.target;
            const originalText = btn.textContent;
            
            if (btn.disabled) return;
            
            btn.disabled = true;
            btn.textContent = '正在准备...';
            
            // 创建背景遮罩层
            const overlay = document.createElement('div');
            overlay.id = 'check-overlay';
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.6); z-index: 999;
                backdrop-filter: blur(3px);
            `;
            document.body.appendChild(overlay);
            
            // 创建进度显示界面
            const progressDiv = document.createElement('div');
            progressDiv.id = 'check-progress';
            progressDiv.style.cssText = `
                position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                background: white; padding: 40px; border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.5); z-index: 1000;
                text-align: center; min-width: 300px; max-width: 800px; width: 90vw;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                border: 1px solid #e0e0e0;
                max-height: 90vh; overflow-y: auto;
            `;
            
            // 移动端适配
            if (window.innerWidth <= 768) {
                progressDiv.style.cssText = `
                    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                    background: white; padding: 20px; border-radius: 15px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 1000;
                    text-align: center; width: 95vw; max-width: 400px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    border: 1px solid #e0e0e0;
                    max-height: 90vh; overflow-y: auto;
                `;
            }
            
            // 移动端适配的HTML内容
            const isMobile = window.innerWidth <= 768;
            const titleSize = isMobile ? '20px' : '24px';
            const textSize = isMobile ? '14px' : '16px';
            const buttonPadding = isMobile ? '8px 16px' : '12px 24px';
            const buttonSize = isMobile ? '14px' : '16px';
            const progressHeight = isMobile ? '25px' : '30px';
            const margin = isMobile ? '15px' : '30px';
            
            progressDiv.innerHTML = `
                <h3 style="margin: 0 0 ${margin} 0; color: #333; font-size: ${titleSize}; font-weight: 600;">🔍 正在检查所有代理</h3>
                <div id="progress-info" style="margin-bottom: 20px; color: #666; font-size: ${textSize}; line-height: 1.5;">正在连接数据库...</div>
                <div style="background: #f5f5f5; border-radius: 15px; height: ${progressHeight}; margin: 20px 0; overflow: hidden; border: 1px solid #e0e0e0;">
                    <div id="progress-bar" style="background: linear-gradient(90deg, #4CAF50, #45a049); height: 100%; width: 0%; transition: width 0.5s ease; border-radius: 15px; position: relative;">
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-weight: 600; font-size: 12px; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);" id="progress-percent">0%</div>
                    </div>
                </div>
                <div id="progress-stats" style="font-size: ${textSize}; color: #555; margin-bottom: 20px; padding: ${isMobile ? '10px' : '15px'}; background: #f8f9fa; border-radius: 10px; border: 1px solid #e0e0e0; word-break: break-word;">准备开始...</div>
                <div style="display: flex; justify-content: center; gap: ${isMobile ? '10px' : '15px'}; margin-top: 15px;">
                    <button id="cancel-check" style="padding: ${buttonPadding}; background: #f44336; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: ${buttonSize}; font-weight: 500; transition: background 0.3s ease;" onmouseover="this.style.background='#d32f2f'" onmouseout="this.style.background='#f44336'">取消检查</button>
                </div>
            `;
            
            document.body.appendChild(progressDiv);
            
            let cancelled = false;
            document.getElementById('cancel-check').onclick = () => {
                cancelled = true;
                document.body.removeChild(progressDiv);
                document.body.removeChild(overlay);
                btn.textContent = originalText;
                btn.disabled = false;
            };
            
            try {
                // 更新状态为正在准备
                document.getElementById('progress-info').textContent = '正在连接数据库...';
                
                // 记录开始时间
                const prepareStartTime = Date.now();
                
                // 首先尝试使用缓存的代理数量
                let totalProxies = getCachedProxyCount();
                let countData = null;
                
                if (totalProxies !== null) {
                    // 使用缓存数据
                    document.getElementById('progress-info').textContent = `使用缓存数据: ${totalProxies} 个代理`;
                    countData = { cached: true, execution_time: 0 };
                } else {
                    // 缓存无效，重新查询
                    document.getElementById('progress-info').textContent = '正在获取代理数量...';
                    const countResponse = await fetch('?ajax=1&action=getProxyCount');
                    countData = await countResponse.json();
                    
                    if (!countData.success) {
                        throw new Error(countData.error || '获取代理数量失败');
                    }
                    
                    totalProxies = countData.count;
                    
                    // 更新缓存
                    cachedProxyCount = totalProxies;
                    cacheTimestamp = Date.now();
                }
                
                if (totalProxies === 0) {
                    alert('没有找到代理数据，请先导入代理。');
                    document.body.removeChild(progressDiv);
                    document.body.removeChild(overlay);
                    btn.textContent = originalText;
                    btn.disabled = false;
                    return;
                }
                
                // 计算准备时间
                const prepareTime = Date.now() - prepareStartTime;
                
                // 显示缓存状态和执行时间
                const cacheStatus = countData.cached ? '缓存' : '数据库';
                const queryTime = countData.execution_time || 0;
                
                // 更新进度信息，显示详细信息
                document.getElementById('progress-info').textContent = `找到 ${totalProxies} 个代理 (查询: ${queryTime}ms ${cacheStatus}, 总用时: ${prepareTime}ms)，开始检查...`;
                
                // 如果准备时间较长，显示更长时间让用户看到
                const displayTime = prepareTime > 1000 ? 1500 : 500;
                await new Promise(resolve => setTimeout(resolve, displayTime));
                
                // 分批检查代理
                const batchSize = 20; // 每批检查20个代理
                let checkedCount = 0;
                let onlineCount = 0;
                let offlineCount = 0;
                
                for (let offset = 0; offset < totalProxies && !cancelled; offset += batchSize) {
                    try {
                        // 设置超时时间为2分钟
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 120000);
                        
                        const batchResponse = await fetch(`?ajax=1&action=checkBatch&offset=${offset}&limit=${batchSize}`, {
                            signal: controller.signal
                        });
                        
                        clearTimeout(timeoutId);
                        
                        if (!batchResponse.ok) {
                            throw new Error(`HTTP ${batchResponse.status}: ${batchResponse.statusText}`);
                        }
                        
                        const batchData = await batchResponse.json();
                        
                        // 检查是否是登录过期
                        if (!batchData.success && batchData.error === 'unauthorized') {
                            document.body.removeChild(progressDiv);
                            document.body.removeChild(overlay);
                            alert('登录已过期，请重新登录');
                            window.location.href = 'login.php';
                            return;
                        }
                        
                        if (!batchData.success) {
                            throw new Error(batchData.error || '批量检查失败');
                        }
                        
                        // 更新统计
                        checkedCount += batchData.results.length;
                        onlineCount += batchData.results.filter(r => r.status === 'online').length;
                        offlineCount += batchData.results.filter(r => r.status === 'offline').length;
                        
                        // 更新进度条
                        const progress = (checkedCount / totalProxies) * 100;
                        document.getElementById('progress-bar').style.width = progress + '%';
                        document.getElementById('progress-percent').textContent = Math.round(progress) + '%';
                        
                        // 更新进度信息，显示执行时间
                        const executionTime = batchData.execution_time ? ` (用时: ${batchData.execution_time}ms)` : '';
                        document.getElementById('progress-info').textContent = 
                            `正在检查第 ${Math.min(offset + batchSize, totalProxies)} / ${totalProxies} 个代理${executionTime}...`;
                        
                        // 更新统计信息
                        document.getElementById('progress-stats').textContent = 
                            `已检查: ${checkedCount} | 在线: ${onlineCount} | 离线: ${offlineCount}`;
                        
                        // 减少延迟时间，提高整体速度
                        await new Promise(resolve => setTimeout(resolve, 100));
                        
                    } catch (error) {
                        if (error.name === 'AbortError') {
                            throw new Error(`第 ${offset + 1}-${Math.min(offset + batchSize, totalProxies)} 个代理检查超时，请检查网络连接或减少批量大小`);
                        }
                        throw error;
                    }
                }
                
                if (!cancelled) {
                    // 检查是否有失败的代理需要发送邮件
                    try {
                        const alertResponse = await fetch('?ajax=1&action=checkFailedProxies');
                        const alertData = await alertResponse.json();
                        
                        let alertMessage = '';
                        if (alertData.success && alertData.failed_proxies > 0) {
                            alertMessage = alertData.email_sent ? 
                                `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理，已发送邮件通知！` :
                                `\n\n⚠️ 发现 ${alertData.failed_proxies} 个连续失败的代理。`;
                        }
                        
                        document.body.removeChild(progressDiv);
                        document.body.removeChild(overlay);
                        
                        alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个${alertMessage}\n\n页面将自动刷新显示最新状态`);
                        
                    } catch (alertError) {
                        document.body.removeChild(progressDiv);
                        document.body.removeChild(overlay);
                        alert(`✅ 检查完成！\n\n总计: ${checkedCount} 个代理\n在线: ${onlineCount} 个\n离线: ${offlineCount} 个\n\n页面将自动刷新显示最新状态`);
                    }
                    
                    // 刷新页面显示最新状态
                    location.reload();
                }
                
            } catch (error) {
                if (!cancelled) {
                    document.body.removeChild(progressDiv);
                    document.body.removeChild(overlay);
                    console.error('检查所有代理失败:', error);
                    alert('❌ 检查失败: ' + error.message);
                }
            } finally {
                if (!cancelled) {
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            }
        }
        
        /**
         * 并行检测所有代理（高性能版本）
         */
        async function checkAllProxiesParallel() {
            const btn = event.target;
            const originalText = btn.textContent;
            
            if (btn.disabled) return;
            
            btn.disabled = true;
            btn.textContent = '正在启动并行检测...';
            
            // 创建背景遮罩层
            const overlay = document.createElement('div');
            overlay.id = 'parallel-check-overlay';
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.7); z-index: 999;
                backdrop-filter: blur(5px);
            `;
            document.body.appendChild(overlay);
            
            // 创建进度显示界面
            const progressDiv = document.createElement('div');
            progressDiv.id = 'parallel-check-progress';
            progressDiv.style.cssText = `
                position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                background: white; padding: 50px; border-radius: 25px;
                box-shadow: 0 25px 80px rgba(0,0,0,0.6); z-index: 1000;
                text-align: center; min-width: 300px; max-width: 900px; width: 90vw;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                border: 2px solid #4CAF50;
                max-height: 90vh; overflow-y: auto;
            `;
            
            // 移动端适配
            if (window.innerWidth <= 768) {
                progressDiv.style.cssText = `
                    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                    background: white; padding: 25px; border-radius: 20px;
                    box-shadow: 0 15px 40px rgba(0,0,0,0.6); z-index: 1000;
                    text-align: center; width: 95vw; max-width: 420px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    border: 2px solid #4CAF50;
                    max-height: 90vh; overflow-y: auto;
                `;
            }
            
            // 移动端适配的HTML内容
            const isMobile = window.innerWidth <= 768;
            const titleSize = isMobile ? '22px' : '28px';
            const textSize = isMobile ? '14px' : '18px';
            const smallTextSize = isMobile ? '13px' : '16px';
            const buttonPadding = isMobile ? '10px 20px' : '15px 30px';
            const buttonSize = isMobile ? '16px' : '18px';
            const progressHeight = isMobile ? '30px' : '35px';
            const margin = isMobile ? '20px' : '30px';
            const gap = isMobile ? '15px' : '20px';
            
            progressDiv.innerHTML = `
                <h3 style="margin: 0 0 ${margin} 0; color: #333; font-size: ${titleSize}; font-weight: 700;">🚀 并行检测所有代理</h3>
                <div id="parallel-progress-info" style="margin-bottom: ${isMobile ? '20px' : '25px'}; color: #666; font-size: ${textSize}; line-height: 1.6; word-break: break-word;">正在启动并行检测引擎...</div>
                <div style="background: #f0f0f0; border-radius: ${isMobile ? '15px' : '20px'}; height: ${progressHeight}; margin: ${isMobile ? '20px' : '35px'} 0; overflow: hidden; border: 2px solid #ddd;">
                    <div id="parallel-progress-bar" style="background: linear-gradient(90deg, #4CAF50, #45a049, #2E7D32); height: 100%; width: 0%; transition: width 0.8s ease; border-radius: ${isMobile ? '13px' : '18px'}; position: relative;">
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-weight: 700; font-size: ${isMobile ? '12px' : '16px'}; text-shadow: 2px 2px 4px rgba(0,0,0,0.4);" id="parallel-progress-percent">0%</div>
                    </div>
                </div>
                <div id="parallel-progress-stats" style="font-size: ${textSize}; color: #555; margin-bottom: ${isMobile ? '20px' : '30px'}; padding: ${isMobile ? '15px' : '20px'}; background: #f8f9fa; border-radius: 15px; border: 2px solid #e0e0e0; word-break: break-word;">准备启动...</div>
                <div id="parallel-batch-info" style="font-size: ${smallTextSize}; color: #777; margin-bottom: ${isMobile ? '20px' : '25px'}; padding: ${isMobile ? '12px' : '15px'}; background: #fff3cd; border-radius: 10px; border: 1px solid #ffeaa7; word-break: break-word;">批次信息加载中...</div>
                <div style="display: flex; justify-content: center; gap: ${gap}; margin-top: ${isMobile ? '20px' : '25px'};">
                    <button id="cancel-parallel-check" style="padding: ${buttonPadding}; background: #f44336; color: white; border: none; border-radius: 10px; cursor: pointer; font-size: ${buttonSize}; font-weight: 600; transition: all 0.3s ease;" onmouseover="this.style.background='#d32f2f'; ${isMobile ? '' : 'this.style.transform=\'scale(1.05)\''};" onmouseout="this.style.background='#f44336'; ${isMobile ? '' : 'this.style.transform=\'scale(1)\''};">取消检测</button>
                </div>
            `;
            
            document.body.appendChild(progressDiv);
            
            let cancelled = false;
            let progressInterval = null;
            let currentSessionId = null; // 存储当前检测任务的会话ID
            
            document.getElementById('cancel-parallel-check').onclick = async () => {
                cancelled = true;
                
                // 发送取消请求，包含会话ID
                try {
                    if (currentSessionId) {
                        await fetch(`?ajax=1&action=cancelParallelCheck&session_id=${encodeURIComponent(currentSessionId)}`);
                    }
                } catch (e) {
                    console.error('取消请求失败:', e);
                }
                
                if (progressInterval) {
                    clearInterval(progressInterval);
                }
                
                document.body.removeChild(progressDiv);
                document.body.removeChild(overlay);
                btn.textContent = originalText;
                btn.disabled = false;
            };
            
            try {
                // 启动并行检测
                document.getElementById('parallel-progress-info').textContent = '正在启动并行检测引擎...';
                
                const startResponse = await fetch('?ajax=1&action=startParallelCheck');
                const startData = await startResponse.json();
                
                if (!startData.success) {
                    // 检查是否是登录过期
                    if (startData.error === 'unauthorized') {
                        alert('登录已过期，请重新登录');
                        window.location.href = 'login.php';
                        return;
                    }
                    throw new Error(startData.error || '启动并行检测失败');
                }
                
                // 保存会话ID用于后续的进度查询和取消操作
                currentSessionId = startData.session_id;
                
                // 检查会话ID是否有效
                if (!currentSessionId) {
                    throw new Error('未获取到有效的会话ID');
                }
                
                // 显示启动信息
                document.getElementById('parallel-progress-info').textContent = 
                    `并行检测已启动！总计 ${startData.total_proxies} 个代理，分为 ${startData.total_batches} 个批次`;
                
                document.getElementById('parallel-batch-info').textContent = 
                    `每批次 <?php echo PARALLEL_BATCH_SIZE; ?> 个代理，最多 <?php echo PARALLEL_MAX_PROCESSES; ?> 个批次并行执行`;
                
                // 开始监控进度
                const startTime = Date.now();
                const maxWaitTime = 30 * 60 * 1000; // 30分钟超时
                let waitingForBatchesTime = 0; // 等待批次完成的时间
                
                progressInterval = setInterval(async () => {
                    if (cancelled) return;
                    
                    try {
                        // 传递会话ID查询对应的检测进度
                        const progressResponse = await fetch(`?ajax=1&action=getParallelProgress&session_id=${encodeURIComponent(currentSessionId)}`);
                        const progressData = await progressResponse.json();
                        
                        // 检查是否是登录过期
                        if (!progressData.success && progressData.error === 'unauthorized') {
                            clearInterval(progressInterval);
                            document.body.removeChild(progressDiv);
                            document.body.removeChild(overlay);
                            alert('登录已过期，请重新登录');
                            window.location.href = 'login.php';
                            return;
                        }
                        
                        if (progressData.success) {
                            // 更新进度条
                            const progress = progressData.overall_progress;
                            document.getElementById('parallel-progress-bar').style.width = progress + '%';
                            document.getElementById('parallel-progress-percent').textContent = Math.round(progress) + '%';
                            
                            // 更新进度信息 - 基于实际检测的IP数量
                            document.getElementById('parallel-progress-info').textContent = 
                                `并行检测进行中... (${progressData.total_checked}/${progressData.total_proxies} 个代理已检测)`;
                            
                            // 更新统计信息
                            document.getElementById('parallel-progress-stats').textContent = 
                                `已检查: ${progressData.total_checked} | 在线: ${progressData.total_online} | 离线: ${progressData.total_offline}`;
                            
                            // 更新批次信息
                            const activeBatches = progressData.batch_statuses.filter(b => b.status === 'running').length;
                            const completedBatches = progressData.batch_statuses.filter(b => b.status === 'completed').length;
                            document.getElementById('parallel-batch-info').textContent = 
                                `活跃批次: ${activeBatches} | 已完成批次: ${completedBatches} | 总批次: ${progressData.total_batches}`;
                            
                            // 检查是否完成 - 绝对严格：必须所有批次都完成才能显示完成对话框
                            const allBatchesCompleted = completedBatches === progressData.total_batches; // 使用严格相等
                            const progressComplete = progress >= 100;
                            const allProxiesChecked = progressData.total_checked >= progressData.total_proxies;
                            
                            // 额外检查：确保没有正在运行的批次
                            const runningBatches = progressData.batch_statuses.filter(b => b.status === 'running').length;
                            const hasRunningBatches = runningBatches > 0;
                            
                            // 绝对严格的完成条件：所有批次完成 且 没有正在运行的批次 且 所有代理都检测完成
                            const shouldComplete = allBatchesCompleted && !hasRunningBatches && allProxiesChecked;
                            
                            // 特别调试：如果条件不满足但仍然触发了完成，记录警告
                            if (!shouldComplete) {
                                console.warn('⚠️ 完成条件不满足，不应该显示完成对话框:', {
                                    completedBatches,
                                    totalBatches: progressData.total_batches,
                                    allBatchesCompleted,
                                    runningBatches,
                                    hasRunningBatches,
                                    allProxiesChecked,
                                    totalChecked: progressData.total_checked,
                                    totalProxies: progressData.total_proxies
                                });
                            }
                            
                            // 调试日志：记录完成条件检查
                            console.log('完成条件检查:', {
                                completedBatches,
                                totalBatches: progressData.total_batches,
                                allBatchesCompleted,
                                runningBatches,
                                hasRunningBatches,
                                progressComplete,
                                allProxiesChecked,
                                shouldComplete,
                                totalChecked: progressData.total_checked,
                                totalProxies: progressData.total_proxies,
                                batchStatuses: progressData.batch_statuses.map(b => ({
                                    id: b.batch_id,
                                    status: b.status,
                                    progress: b.progress,
                                    checked: b.checked,
                                    limit: b.limit
                                }))
                            });
                            
                            if (shouldComplete) {
                                console.log('✅ 所有完成条件都满足，先同步更新UI再显示完成对话框');
                                
                                // 立即停止轮询，防止更多UI更新
                                clearInterval(progressInterval);
                                
                                // 同步更新UI显示为最终完成状态
                                document.getElementById('parallel-progress-bar').style.width = '100%';
                                document.getElementById('parallel-progress-percent').textContent = '100%';
                                document.getElementById('parallel-progress-info').textContent = 
                                    `检测完成！(${progressData.total_checked}/${progressData.total_proxies} 个代理已检测)`;
                                document.getElementById('parallel-batch-info').textContent = 
                                    `活跃批次: 0 | 已完成批次: ${progressData.total_batches} | 总批次: ${progressData.total_batches}`;
                                
                                // 使用setTimeout确保UI更新完成后再显示对话框
                                setTimeout(() => {
                                    if (!cancelled) {
                                        // 最终安全检查：再次验证所有条件
                                        const finalCompletedBatches = progressData.batch_statuses.filter(b => b.status === 'completed').length;
                                        const finalRunningBatches = progressData.batch_statuses.filter(b => b.status === 'running').length;
                                        const finalAllBatchesCompleted = finalCompletedBatches === progressData.total_batches;
                                        const finalNoRunningBatches = finalRunningBatches === 0;
                                        const finalAllProxiesChecked = progressData.total_checked >= progressData.total_proxies;
                                        
                                        if (finalAllBatchesCompleted && finalNoRunningBatches && finalAllProxiesChecked) {
                                            console.log('✅ 最终安全检查通过，显示完成对话框');
                                            document.body.removeChild(progressDiv);
                                            document.body.removeChild(overlay);
                                            
                                            alert(`🎉 并行检测完成！\n\n总计: ${progressData.total_checked} 个代理\n在线: ${progressData.total_online} 个\n离线: ${progressData.total_offline} 个\n\n页面将自动刷新显示最新状态`);
                                            
                                            // 刷新页面显示最新状态
                                            location.reload();
                                        } else {
                                            console.error('❌ 最终安全检查失败！阻止显示完成对话框:', {
                                                finalCompletedBatches,
                                                totalBatches: progressData.total_batches,
                                                finalAllBatchesCompleted,
                                                finalRunningBatches,
                                                finalNoRunningBatches,
                                                finalAllProxiesChecked,
                                                totalChecked: progressData.total_checked,
                                                totalProxies: progressData.total_proxies
                                            });
                                            // 不显示对话框，继续等待
                                            return;
                                        }
                                    }
                                }, 100); // 100ms延迟，确保UI更新完成
                            } else {
                                // 批次还未全部完成，显示等待信息
                                // 只有在检测真正完成且所有代理都检测完后才开始超时计时
                                if (progressComplete && allProxiesChecked && !hasRunningBatches && waitingForBatchesTime === 0) {
                                    waitingForBatchesTime = Date.now(); // 记录开始等待的时间
                                    console.log('开始等待批次状态更新计时');
                                }
                                
                                const waitingDuration = waitingForBatchesTime > 0 ? Date.now() - waitingForBatchesTime : 0;
                                const waitingSeconds = Math.floor(waitingDuration / 1000);
                                
                                // 根据进度情况显示不同的等待信息
                                if (progressComplete && allProxiesChecked) {
                                    document.getElementById('parallel-progress-info').textContent = 
                                        `检测已完成，等待批次进程结束... (${completedBatches}/${progressData.total_batches} 个批次已完成, 已等待${waitingSeconds}秒)`;
                                } else {
                                    document.getElementById('parallel-progress-info').textContent = 
                                        `并行检测进行中... (${progressData.total_checked}/${progressData.total_proxies} 个代理已检测, ${completedBatches}/${progressData.total_batches} 个批次已完成)`;
                                }
                                
                                // 超时检查：只有在真正开始等待批次状态更新后才检查超时
                                if (waitingForBatchesTime > 0 && waitingDuration > 30000 && progressComplete && allProxiesChecked && !hasRunningBatches) { // 30秒
                                    console.warn('批次进程超时，强制完成检测');
                                    
                                    // 更新UI显示为完成状态
                                    document.getElementById('parallel-progress-bar').style.width = '100%';
                                    document.getElementById('parallel-progress-percent').textContent = '100%';
                                    document.getElementById('parallel-progress-info').textContent = 
                                        `检测完成（超时）！(${progressData.total_checked}/${progressData.total_proxies} 个代理已检测)`;
                                    document.getElementById('parallel-batch-info').textContent = 
                                        `活跃批次: 0 | 已完成批次: ${completedBatches} | 总批次: ${progressData.total_batches}`;
                                    
                                    clearInterval(progressInterval);
                                    
                                    if (!cancelled) {
                                        document.body.removeChild(progressDiv);
                                        document.body.removeChild(overlay);
                                        
                                        alert(`⚠️ 并行检测完成（部分批次超时）！\n\n总计: ${progressData.total_checked} 个代理\n在线: ${progressData.total_online} 个\n离线: ${progressData.total_offline} 个\n\n注意：有 ${progressData.total_batches - completedBatches} 个批次可能未完全结束，但检测已完成\n\n页面将自动刷新显示最新状态`);
                                        
                                        location.reload();
                                    }
                                }
                            }
                        }
                    } catch (error) {
                        console.error('获取进度失败:', error);
                    }
                    
                    // 整体超时检查：如果总时间超过30分钟，强制停止
                    const totalDuration = Date.now() - startTime;
                    if (totalDuration > maxWaitTime) {
                        console.warn('并行检测总体超时，强制停止');
                        clearInterval(progressInterval);
                        
                        if (!cancelled) {
                            document.body.removeChild(progressDiv);
                            document.body.removeChild(overlay);
                            
                            alert(`⚠️ 并行检测超时！\n\n检测已运行超过30分钟，可能存在问题。\n请检查服务器状态或联系管理员。\n\n页面将自动刷新`);
                            
                            location.reload();
                        }
                    }
                }, 1000); // 每秒更新一次进度
                
            } catch (error) {
                if (!cancelled) {
                    document.body.removeChild(progressDiv);
                    document.body.removeChild(overlay);
                    console.error('并行检测失败:', error);
                    alert('❌ 并行检测失败: ' + error.message);
                }
            } finally {
                if (!cancelled) {
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            }
        }
        
        // 状态筛选功能
        function filterByStatus(status) {
            const currentUrl = new URL(window.location);
            const searchParams = currentUrl.searchParams;
            
            if (status) {
                searchParams.set('status', status);
            } else {
                searchParams.delete('status');
            }
            
            // 重置到第一页
            searchParams.delete('page');
            
            window.location.href = currentUrl.toString();
        }
        
        // 搜索功能
        function performSearch() {
            const searchTerm = document.getElementById('search-input').value.trim();
            const currentUrl = new URL(window.location);
            const searchParams = currentUrl.searchParams;
            
            if (searchTerm) {
                searchParams.set('search', searchTerm);
            } else {
                searchParams.delete('search');
            }
            
            // 重置到第一页
            searchParams.delete('page');
            
            window.location.href = currentUrl.toString();
        }
        
        // 清除搜索和筛选
        function clearSearch() {
            const currentUrl = new URL(window.location);
            const searchParams = currentUrl.searchParams;
            
            searchParams.delete('search');
            searchParams.delete('status');
            searchParams.delete('page');
            
            window.location.href = currentUrl.toString();
        }
        
        function refreshAll() {
            location.reload();
        }
        
        // 调试函数：查看数据库中的实际状态值
        function debugStatuses() {
            fetch('?ajax=1&action=debugStatuses')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('数据库中的状态值:', data.statuses);
                        alert('请查看浏览器控制台查看状态值');
                    } else {
                        console.error('获取状态值失败:', data.error);
                    }
                })
                .catch(error => {
                    console.error('调试失败:', error);
                });
        }
        
        // 测试函数：创建不同状态的测试数据
        function createTestData() {
            if (confirm('这将修改前4个代理的状态为离线和未知，用于测试筛选功能。确定继续吗？')) {
                fetch('?ajax=1&action=createTestData')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message + '\n\n页面将刷新以显示更新后的数据');
                            location.reload();
                        } else {
                            alert('创建测试数据失败: ' + data.error);
                        }
                    })
                    .catch(error => {
                        console.error('创建测试数据失败:', error);
                        alert('创建测试数据失败');
                    });
            }
        }
        
        // 监听搜索框的回车键
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        performSearch();
                    }
                });
                
                // 自动聚焦搜索框（如果有搜索词）
                <?php if (!empty($searchTerm)): ?>
                searchInput.focus();
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
                <?php endif; ?>
            }
        });
        
        // 会话管理
        <?php if (Auth::isLoginEnabled()): ?>
        function checkSession() {
            fetch('?ajax=1&action=sessionCheck')
                .then(response => response.json())
                .then(data => {
                    if (!data.valid) {
                        alert('会话已过期，请重新登录');
                        window.location.href = 'login.php';
                    }
                })
                .catch(error => {
                    console.error('会话检查失败:', error);
                });
        }
        
        // 每5分钟检查一次会话状态
        setInterval(checkSession, 5 * 60 * 1000);
        
        // 页面加载时检查一次
        checkSession();
        <?php endif; ?>
        
        // 全局变量存储代理数量
        let cachedProxyCount = null;
        let cacheTimestamp = null;
        
        // 按需获取代理数量（带缓存）
        async function getProxyCount() {
            // 检查缓存是否有效（5分钟）
            if (cachedProxyCount !== null && cacheTimestamp && (Date.now() - cacheTimestamp) < 300000) {
                return cachedProxyCount;
            }
            
            try {
                const response = await fetch('?ajax=1&action=getProxyCount');
                const data = await response.json();
                
                if (data.success) {
                    cachedProxyCount = data.count;
                    cacheTimestamp = Date.now();
                    console.log(`获取代理数量: ${data.count} (查询时间: ${data.execution_time}ms, 缓存: ${data.cached ? '是' : '否'})`);
                    return data.count;
                }
            } catch (error) {
                console.log('获取代理数量失败:', error);
            }
            return null;
        }
        
        // 获取缓存的代理数量（如果有效）
        function getCachedProxyCount() {
            // 缓存有效期5分钟
            if (cachedProxyCount !== null && cacheTimestamp && (Date.now() - cacheTimestamp) < 300000) {
                return cachedProxyCount;
            }
            return null;
        }
    </script>
</body>
</html>
