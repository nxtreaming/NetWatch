// 注意：fetchApi 和 getApiUrl 函数已在 utils.js 中定义，此处不再重复定义

// 离线代理并行检测函数 - 最简化实现，复用现有逻辑
async function checkOfflineProxiesParallel() {
    const btn = event.target;
    const originalText = btn.textContent;
    
    if (btn.disabled) return;
    
    btn.disabled = true;
    btn.textContent = '正在准备...';
    
    try {
        // 启动离线代理并行检测 - 复用现有的AJAX端点
        const response = await fetchApi('ajax=1&action=startOfflineParallelCheck');
        const data = await response.json();
        
        // 检查登录状态
        if (data.error === 'unauthorized') {
            alert(data.message || '登录已过期，请重新登录');
            window.location.href = 'login.php';
            return;
        }
        
        if (!data.success) {
            btn.textContent = originalText;
            btn.disabled = false;
            showCustomAlert(data.error || '启动离线代理检测失败');
            return;
        }
        
        // 如果没有离线代理，直接提示
        if (data.total_proxies === 0) {
            btn.textContent = originalText;
            btn.disabled = false;
            showCustomAlert(data.error || '🎉 太好了！当前没有离线的代理需要检测。');
            return;
        }
        
        // 直接复用现有的并行检测UI，只是修改一些文本
        // 临时修改全局变量来影响现有UI的显示
        window.isOfflineMode = true;
        
        // 调用现有的并行检测进度显示逻辑
        showParallelProgress(data);
        
    } catch (error) {
        showCustomAlert('❌ 离线代理检测失败: ' + error.message);
        btn.textContent = originalText;
        btn.disabled = false;
    }
}

// 完成对话框函数，支持HTML内容和自动刷新
function showCompletionDialog(message, autoReload = false) {
    // 创建遮罩层
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
    `;
    
    // 创建提示框 - 深色主题
    const alertBox = document.createElement('div');
    alertBox.style.cssText = `
        background: #111c32;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.8);
        max-width: 450px;
        min-width: 320px;
        text-align: center;
        font-size: 15px;
        line-height: 1.6;
        border: 1px solid rgba(255, 255, 255, 0.08);
    `;
    
    // 创建消息内容
    const messageDiv = document.createElement('div');
    messageDiv.innerHTML = message;
    messageDiv.style.cssText = `
        margin-bottom: 25px;
        color: #e2e8f0;
    `;
    
    // 创建确定按钮
    const okButton = document.createElement('button');
    okButton.textContent = '确定';
    okButton.style.cssText = `
        background: #3b82f6;
        color: white;
        border: none;
        padding: 10px 24px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        margin: 0 auto;
        display: block;
        font-weight: 500;
        transition: background 0.3s ease;
    `;
    
    // 按钮悬停效果
    okButton.onmouseover = () => okButton.style.background = '#2563eb';
    okButton.onmouseout = () => okButton.style.background = '#3b82f6';
    
    // 点击确定关闭提示框
    okButton.onclick = () => {
        document.body.removeChild(overlay);
        if (autoReload) {
            location.reload();
        }
    };
    
    // 组装提示框
    alertBox.appendChild(messageDiv);
    alertBox.appendChild(okButton);
    overlay.appendChild(alertBox);
    
    // 添加到页面
    document.body.appendChild(overlay);
    
    // 点击遮罩层也可以关闭
    overlay.onclick = (e) => {
        if (e.target === overlay) {
            document.body.removeChild(overlay);
            if (autoReload) {
                location.reload();
            }
        }
    };
}

// 自定义提示框函数，支持HTML内容和居中按钮
function showCustomAlert(message) {
    // 创建遮罩层
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
    `;
    
    // 创建提示框 - 深色主题
    const alertBox = document.createElement('div');
    alertBox.style.cssText = `
        background: #111c32;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.8);
        max-width: 450px;
        min-width: 320px;
        text-align: center;
        font-size: 15px;
        line-height: 1.6;
        border: 1px solid rgba(255, 255, 255, 0.08);
    `;
    
    // 创建消息内容
    const messageDiv = document.createElement('div');
    messageDiv.innerHTML = message;
    messageDiv.style.cssText = `
        margin-bottom: 25px;
        color: #e2e8f0;
    `;
    
    // 创建确定按钮
    const okButton = document.createElement('button');
    okButton.textContent = '确定';
    okButton.style.cssText = `
        background: #3b82f6;
        color: white;
        border: none;
        padding: 10px 24px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        margin: 0 auto;
        display: block;
        font-weight: 500;
        transition: background 0.3s ease;
    `;
    
    // 按钮悬停效果
    okButton.onmouseover = () => okButton.style.background = '#2563eb';
    okButton.onmouseout = () => okButton.style.background = '#3b82f6';
    
    // 点击确定关闭提示框
    okButton.onclick = () => document.body.removeChild(overlay);
    
    // 组装提示框
    alertBox.appendChild(messageDiv);
    alertBox.appendChild(okButton);
    overlay.appendChild(alertBox);
    
    // 添加到页面
    document.body.appendChild(overlay);
    
    // 点击遮罩层也可以关闭
    overlay.onclick = (e) => {
        if (e.target === overlay) {
            document.body.removeChild(overlay);
        }
    };
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
        background: #111c32; padding: 30px; border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.8); z-index: 1000;
        text-align: center; min-width: 300px; max-width: 500px;
        font-family: Arial, sans-serif;
        border: 1px solid rgba(255, 255, 255, 0.08);
    `;
    
    progressDiv.innerHTML = `
        <h3 style="margin: 0 0 20px 0; color: #e2e8f0;">${title}</h3>
        <div id="progress-info" style="margin-bottom: 15px; color: #94a3b8;">正在启动检测...</div>
        <div style="background: #14213d; border-radius: 10px; height: 20px; margin: 15px 0; overflow: hidden; border: 1px solid rgba(255, 255, 255, 0.08);">
            <div id="progress-bar" style="background: ${isOfflineMode ? '#f59e0b' : '#10b981'}; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
        </div>
        <div id="batch-info" style="margin: 15px 0; color: #94a3b8; font-size: 14px;">
            总批次: ${data.total_batches} | 已完成: 0
        </div>
        <button onclick="cancelCheck()" style="background: #ef4444; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer; transition: background 0.3s ease;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
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
            fetchApi(`ajax=1&action=${action}&session_id=${sessionId}`)
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
            const response = await fetchApi(`ajax=1&action=${action}&session_id=${sessionId}`);
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
                        showCompletionDialog(
                            `✅ 离线代理检测完成！<br><br>已恢复: <strong>${recovered}</strong> 个<br>仍离线: <strong>${stillOffline}</strong> 个<br><br>页面将刷新`,
                            true
                        );
                    } else {
                        showCompletionDialog(
                            `✅ 并行检测完成！<br><br>在线: <strong>${progressData.total_online}</strong><br>离线: <strong>${progressData.total_offline}</strong><br><br>页面将刷新`,
                            true
                        );
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
            showCompletionDialog('⏱️ 检测超时，页面将刷新', true);
        }
    }, 10 * 60 * 1000);
}
