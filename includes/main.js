/**
 * NetWatch 主要JavaScript功能
 * 包含自动刷新、统计更新、日志刷新等核心功能
 */

// 自动刷新定时器
setInterval(refreshStats, 30000); // 30秒刷新统计
setInterval(refreshLogs, 60000);  // 60秒刷新日志

/**
 * 刷新统计数据
 */
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

/**
 * 刷新日志数据
 */
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

/**
 * 刷新所有数据
 */
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
