// 获取正确的API路径（使用相对路径，自动适应任何部署环境）- 与proxy-check.js共享
function getApiUrl(params) {
    // 使用相对路径 'index.php' 而不是绝对路径 '/index.php'
    // 这样会相对于当前HTML页面的位置
    return `index.php?${params}`;
}

// AJAX fetch包装函数，自动添加必要的请求头
function fetchApi(params, options = {}) {
    const url = getApiUrl(params);
    const defaultOptions = {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json, */*',
            ...options.headers
        },
        ...options
    };
    
    // 调试日志：输出请求URL和头信息
    console.log('📡 AJAX请求:', {
        url: url,
        headers: defaultOptions.headers,
        options: options
    });
    
    return fetch(url, defaultOptions);
}

// 自动刷新
setInterval(refreshStats, 30000); // 30秒刷新统计
setInterval(refreshLogs, 60000);  // 60秒刷新日志

function refreshStats() {
    fetchApi('ajax=1&action=stats')
        .then(response => response.json())
        .then(data => {
            const totalEl = document.querySelector('.stats-grid .stat-card:nth-child(1) .stat-inline');
            const onlineEl = document.querySelector('.stats-grid .stat-card:nth-child(2) .stat-inline');
            const offlineEl = document.querySelector('.stats-grid .stat-card:nth-child(3) .stat-inline');
            const unknownEl = document.querySelector('.stats-grid .stat-card:nth-child(4) .stat-inline');
            const timeEl = document.querySelector('.stats-grid .stat-card:nth-child(5) .stat-inline');
            
            if (totalEl) totalEl.textContent = '总数: ' + data.total;
            if (onlineEl) onlineEl.textContent = '在线: ' + data.online;
            if (offlineEl) offlineEl.textContent = '离线: ' + data.offline;
            if (unknownEl) unknownEl.textContent = '未知: ' + data.unknown;
            if (timeEl) timeEl.textContent = '时间: ' + Math.round(data.avg_response_time) + 'ms';
        })
        .catch(error => console.error('刷新统计失败:', error));
}

function refreshLogs() {
    fetchApi('ajax=1&action=logs')
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


// 调试函数：查看数据库中的实际状态值
function debugStatuses() {
    if (confirm('这将显示所有代理的详细状态信息，可能需要一些时间。确定继续吗？')) {
        fetchApi('ajax=1&action=debugStatuses')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('代理状态调试信息:', data.debug_info);
                    alert('调试信息已输出到浏览器控制台，请按F12查看。');
                } else {
                    alert('获取调试信息失败: ' + (data.error || '未知错误'));
                }
            })
            .catch(error => {
                console.error('调试状态失败:', error);
                alert('调试失败，请稍后重试');
            });
    }
}

// 测试函数：创建不同状态的测试数据
function createTestData() {
    if (confirm('这将把前4个代理的状态设为离线和未知，用于测试筛选功能。确定继续吗？')) {
        fetchApi('ajax=1&action=createTestData', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ 测试数据创建成功！页面将自动刷新。');
                    location.reload();
                } else {
                    alert('创建测试数据失败: ' + (data.error || '未知错误'));
                }
            })
            .catch(error => {
                console.error('创建测试数据失败:', error);
                alert('创建测试数据失败，请稍后重试');
            });
    }
}

// 代理数量缓存相关
let cachedProxyCount = null;
let cacheTimestamp = null;

// 按需获取代理数量（带缓存）
async function getProxyCount() {
    // 检查缓存是否有效（5分钟）
    if (cachedProxyCount !== null && cacheTimestamp && (Date.now() - cacheTimestamp) < 300000) {
        return cachedProxyCount;
    }
    
    try {
        const response = await fetchApi('ajax=1&action=getProxyCount');
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

// 会话管理功能
function checkSession() {
    fetchApi('ajax=1&action=sessionCheck')
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
