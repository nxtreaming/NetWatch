// 获取正确的API路径（使用相对路径，自动适应任何部署环境）
function getApiUrl(params) {
    // 使用相对路径 'index.php' 而不是绝对路径 '/index.php'
    // 这样会相对于当前HTML页面的位置
    return `index.php?${params}`;
}

// AJAX fetch包装函数，自动添加必要的请求头
function fetchApi(params, options = {}) {
    // 添加特殊标记参数，让服务器识别这是真正的AJAX请求
    const ajaxToken = Date.now(); // 使用时间戳作为防伪标记
    const url = `index.php?${params}&_ajax_token=${ajaxToken}`;
    
    const defaultOptions = {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json, */*',
            ...options.headers
        },
        credentials: 'same-origin', // 确保发送cookies
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

function checkProxy(proxyId) {
    const btn = event.target;
    const originalText = btn.textContent;
    btn.textContent = '检查中...';
    btn.disabled = true;
    
    fetchApi(`ajax=1&action=check&proxy_id=${proxyId}`)
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
            alert('检查失败，请稍后重试');
        })
        .finally(() => {
            btn.textContent = originalText;
            btn.disabled = false;
        });
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
            const countResponse = await fetchApi('ajax=1&action=getProxyCount');
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
                
                const batchResponse = await fetchApi(`ajax=1&action=checkBatch&offset=${offset}&limit=${batchSize}`, {
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
                const alertResponse = await fetchApi('ajax=1&action=checkFailedProxies');
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
                await fetchApi(`ajax=1&action=cancelParallelCheck&session_id=${encodeURIComponent(currentSessionId)}`);
            }
        } catch (e) {
            // 取消请求失败，忽略错误
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
        
        const startResponse = await fetchApi('ajax=1&action=startParallelCheck');
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
            `每批次 ${startData.batch_size} 个代理，最多 ${startData.max_processes} 个批次并行执行`;
        
        // 开始监控进度
        const startTime = Date.now();
        const maxWaitTime = 30 * 60 * 1000; // 30分钟超时
        let waitingForBatchesTime = 0; // 等待批次完成的时间
        
        progressInterval = setInterval(async () => {
            if (cancelled) return;
            
            try {
                // 传递会话ID查询对应的检测进度
                const progressResponse = await fetchApi(`ajax=1&action=getParallelProgress&session_id=${encodeURIComponent(currentSessionId)}`);
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
                    if (shouldComplete) {
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
                                    document.body.removeChild(progressDiv);
                                    document.body.removeChild(overlay);
                                    
                                    alert(`🎉 并行检测完成！\n\n总计: ${progressData.total_checked} 个代理\n在线: ${progressData.total_online} 个\n离线: ${progressData.total_offline} 个\n\n页面将自动刷新显示最新状态`);
                                    
                                    // 刷新页面显示最新状态
                                    location.reload();
                                } else {
                                    // 最终安全检查失败，不显示对话框，继续等待
                                    return;
                                }
                            }
                        }, 100); // 100ms延迟，确保UI更新完成
                    } else {
                        // 批次还未全部完成，显示等待信息
                        // 只有在检测真正完成且所有代理都检测完后才开始超时计时
                        if (progressComplete && allProxiesChecked && !hasRunningBatches && waitingForBatchesTime === 0) {
                            waitingForBatchesTime = Date.now(); // 记录开始等待的时间
                        }
                        
                        const waitingDuration = waitingForBatchesTime > 0 ? Date.now() - waitingForBatchesTime : 0;
                        const waitingSeconds = Math.floor(waitingDuration / 1000);
                        
                        // 根据进度情况显示不同的等待信息
                        if (progressComplete && allProxiesChecked) {
                            document.getElementById('parallel-progress-info').textContent = 
                                `检测已完成，等待批次进程结束... (${completedBatches}/${progressData.total_batches} 个批次已完成, 已等待${waitingSeconds}秒)`;
                        } else {
                            document.getElementById('parallel-progress-info').textContent = 
                                `检测进行中... ${progressData.total_checked}/${progressData.total_proxies} 个代理已检测`;
                        }
                        
                        // 超时检查：只有在真正开始等待批次状态更新后才检查超时
                        if (waitingForBatchesTime > 0 && waitingDuration > 30000 && progressComplete && allProxiesChecked && !hasRunningBatches) { // 30秒
                            
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
                                
                                alert(`⚠️ 检测完成（部分批次超时）！\n\n总计: ${progressData.total_checked} 个代理\n在线: ${progressData.total_online} 个\n离线: ${progressData.total_offline} 个\n\n注意：有 ${progressData.total_batches - completedBatches} 个批次可能未完全结束，但检测已完成\n\n页面将自动刷新显示最新状态`);
                                
                                location.reload();
                            }
                        }
                    }
                }
            } catch (error) {
                // 获取进度失败，忽略错误继续尝试
            }
            
            // 整体超时检查：如果总时间超过30分钟，强制停止
            const totalDuration = Date.now() - startTime;
            if (totalDuration > maxWaitTime) {
                clearInterval(progressInterval);
                
                if (!cancelled) {
                    document.body.removeChild(progressDiv);
                    document.body.removeChild(overlay);
                    
                    alert(`⚠️ 并行检测超时！\n\n检测已运行超过30分钟，可能存在问题。\n请检查服务器状态或联系管理员。\n\n页面将自动刷新`);
                    
                    location.reload();
                }
            }
        }, 500); // 每0.5秒更新一次进度
    } catch (error) {
        if (!cancelled) {
            document.body.removeChild(progressDiv);
            document.body.removeChild(overlay);
            // 并行检测失败
            alert('❌ 并行检测失败: ' + error.message);
        }
    } finally {
        if (!cancelled) {
            btn.textContent = originalText;
            btn.disabled = false;
        }
    }
}

