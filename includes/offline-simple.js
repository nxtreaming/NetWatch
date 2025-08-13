// 离线代理并行检测函数 - 最简化实现，复用现有逻辑
async function checkOfflineProxiesParallel() {
    const btn = event.target;
    const originalText = btn.textContent;
    
    if (btn.disabled) return;
    
    btn.disabled = true;
    btn.textContent = '正在准备...';
    
    try {
        // 启动离线代理并行检测 - 复用现有的AJAX端点
        const response = await fetch('?ajax=1&action=startOfflineParallelCheck');
        const data = await response.json();
        
        // 检查登录状态
        if (data.error === 'unauthorized') {
            alert(data.message || '登录已过期，请重新登录');
            window.location.href = 'login.php';
            return;
        }
        
        if (!data.success) {
            throw new Error(data.error || '启动离线代理检测失败');
        }
        
        // 如果没有离线代理，直接提示
        if (data.total_proxies === 0) {
            btn.textContent = originalText;
            btn.disabled = false;
            alert('🎉 太好了！当前没有离线的代理需要检测。');
            return;
        }
        
        // 直接复用现有的并行检测UI，只是修改一些文本
        // 临时修改全局变量来影响现有UI的显示
        window.isOfflineMode = true;
        
        // 调用现有的并行检测进度显示逻辑
        showParallelProgress(data);
        
    } catch (error) {
        alert('❌ 离线代理检测失败: ' + error.message);
        btn.textContent = originalText;
        btn.disabled = false;
    }
}

// 简化的进度显示，基于现有代码
function showParallelProgress(data) {
    // 这里可以直接复制现有的并行检测UI代码
    // 只需要根据 window.isOfflineMode 来调整标题和颜色
    
    const isOfflineMode = window.isOfflineMode || false;
    const title = isOfflineMode ? '🔧 离线代理检测' : '🚀 并行检测所有代理';
    const progressColor = isOfflineMode ? '#FF8C00' : '#4CAF50';
    
    // 创建简化的进度界面
    const overlay = document.createElement('div');
    overlay.id = 'check-overlay';
    overlay.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.6); z-index: 999;
        backdrop-filter: blur(3px);
    `;
    document.body.appendChild(overlay);
    
    const progressDiv = document.createElement('div');
    progressDiv.id = 'check-progress';
    progressDiv.style.cssText = `
        position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
        background: white; padding: 30px; border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 1000;
        text-align: center; min-width: 300px; max-width: 500px;
        font-family: Arial, sans-serif;
    `;
    
    progressDiv.innerHTML = `
        <h3 style="margin: 0 0 20px 0; color: #333;">${title}</h3>
        <div id="progress-info" style="margin-bottom: 15px; color: #666;">正在启动检测...</div>
        <div style="background: #f0f0f0; border-radius: 10px; height: 20px; margin: 15px 0; overflow: hidden;">
            <div id="progress-bar" style="background: ${progressColor}; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
        </div>
        <div id="batch-info" style="margin: 15px 0; color: #666; font-size: 14px;">
            总批次: ${data.total_batches} | 已完成: 0
        </div>
        <button onclick="cancelCheck()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
            取消检测
        </button>
    `;
    
    document.body.appendChild(progressDiv);
    
    // 启动进度监控
    startProgressMonitoring(data.session_id, isOfflineMode);
}

// 简化的进度监控
function startProgressMonitoring(sessionId, isOfflineMode) {
    let cancelled = false;
    
    window.cancelCheck = function() {
        const action = isOfflineMode ? 'cancelOfflineParallelCheck' : 'cancelParallelCheck';
        if (confirm('确定要取消检测吗？')) {
            cancelled = true;
            fetch(`?ajax=1&action=${action}&session_id=${sessionId}`)
                .finally(() => {
                    document.body.removeChild(document.getElementById('check-overlay'));
                    document.body.removeChild(document.getElementById('check-progress'));
                    window.isOfflineMode = false;
                });
        }
    };
    
    const progressInterval = setInterval(async () => {
        if (cancelled) return;
        
        try {
            const action = isOfflineMode ? 'getOfflineParallelProgress' : 'getParallelProgress';
            const response = await fetch(`?ajax=1&action=${action}&session_id=${sessionId}`);
            const progressData = await response.json();
            
            if (progressData.success) {
                const percentage = Math.round((progressData.total_checked / progressData.total_proxies) * 100);
                document.getElementById('progress-bar').style.width = percentage + '%';
                document.getElementById('progress-info').textContent = 
                    `检测进行中... (${progressData.total_checked}/${progressData.total_proxies})`;
                document.getElementById('batch-info').textContent = 
                    `总批次: ${progressData.total_batches} | 已完成: ${progressData.completed_batches || 0}`;
                
                // 检查是否完成
                if (progressData.total_checked >= progressData.total_proxies && 
                    (progressData.active_batches || 0) === 0) {
                    
                    clearInterval(progressInterval);
                    document.body.removeChild(document.getElementById('check-overlay'));
                    document.body.removeChild(document.getElementById('check-progress'));
                    
                    if (isOfflineMode) {
                        const recovered = progressData.total_online || 0;
                        const stillOffline = progressData.total_offline || 0;
                        alert(`✅ 离线代理检测完成！\n\n已恢复: ${recovered} 个\n仍离线: ${stillOffline} 个\n\n页面将刷新`);
                    } else {
                        alert(`✅ 并行检测完成！\n\n在线: ${progressData.total_online}\n离线: ${progressData.total_offline}\n\n页面将刷新`);
                    }
                    
                    window.isOfflineMode = false;
                    location.reload();
                }
            }
        } catch (error) {
            // 忽略错误继续
        }
    }, 1000);
    
    // 10分钟超时
    setTimeout(() => {
        if (!cancelled) {
            clearInterval(progressInterval);
            alert('检测超时，页面将刷新');
            location.reload();
        }
    }, 10 * 60 * 1000);
}
