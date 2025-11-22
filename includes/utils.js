// 自定义深色主题确认框函数
function showCustomConfirm(message, onConfirm, onCancel) {
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.7); z-index: 10000;
        display: flex; align-items: center; justify-content: center;
        backdrop-filter: blur(5px);
    `;
    
    const confirmBox = document.createElement('div');
    confirmBox.style.cssText = `
        background: #111c32; padding: 30px; border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.8);
        max-width: 450px; min-width: 320px; text-align: center;
        font-size: 15px; line-height: 1.8;
        border: 1px solid rgba(255, 255, 255, 0.08);
    `;
    
    const messageDiv = document.createElement('div');
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
        margin-bottom: 25px; color: #e2e8f0;
    `;
    
    const buttonContainer = document.createElement('div');
    buttonContainer.style.cssText = `
        display: flex; gap: 12px; justify-content: center;
    `;
    
    const confirmButton = document.createElement('button');
    confirmButton.textContent = '确定';
    confirmButton.style.cssText = `
        background: #3b82f6; color: white; border: none;
        padding: 10px 24px; border-radius: 6px; cursor: pointer;
        font-size: 14px; font-weight: 500;
        transition: background 0.3s ease; flex: 1; max-width: 120px;
    `;
    
    const cancelButton = document.createElement('button');
    cancelButton.textContent = '取消';
    cancelButton.style.cssText = `
        background: #64748b; color: white; border: none;
        padding: 10px 24px; border-radius: 6px; cursor: pointer;
        font-size: 14px; font-weight: 500;
        transition: background 0.3s ease; flex: 1; max-width: 120px;
    `;
    
    confirmButton.onmouseover = () => confirmButton.style.background = '#2563eb';
    confirmButton.onmouseout = () => confirmButton.style.background = '#3b82f6';
    cancelButton.onmouseover = () => cancelButton.style.background = '#475569';
    cancelButton.onmouseout = () => cancelButton.style.background = '#64748b';
    
    confirmButton.onclick = () => {
        document.body.removeChild(overlay);
        if (onConfirm) onConfirm();
    };
    
    cancelButton.onclick = () => {
        document.body.removeChild(overlay);
        if (onCancel) onCancel();
    };
    
    buttonContainer.appendChild(confirmButton);
    buttonContainer.appendChild(cancelButton);
    confirmBox.appendChild(messageDiv);
    confirmBox.appendChild(buttonContainer);
    overlay.appendChild(confirmBox);
    document.body.appendChild(overlay);
}

// 自定义深色主题提示框函数
function showCustomAlert(message, callback) {
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.7); z-index: 10000;
        display: flex; align-items: center; justify-content: center;
        backdrop-filter: blur(5px);
    `;
    
    const alertBox = document.createElement('div');
    alertBox.style.cssText = `
        background: #111c32; padding: 30px; border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.8);
        max-width: 500px; min-width: 320px; text-align: center;
        font-size: 15px; line-height: 1.8;
        border: 1px solid rgba(255, 255, 255, 0.08);
    `;
    
    const messageDiv = document.createElement('div');
    messageDiv.innerHTML = message.replace(/\n/g, '<br>');
    messageDiv.style.cssText = `
        margin-bottom: 25px; color: #e2e8f0; white-space: pre-wrap;
    `;
    
    const okButton = document.createElement('button');
    okButton.textContent = '确定';
    okButton.style.cssText = `
        background: #3b82f6; color: white; border: none;
        padding: 10px 24px; border-radius: 6px; cursor: pointer;
        font-size: 14px; font-weight: 500;
        transition: background 0.3s ease;
    `;
    
    okButton.onmouseover = () => okButton.style.background = '#2563eb';
    okButton.onmouseout = () => okButton.style.background = '#3b82f6';
    okButton.onclick = () => {
        document.body.removeChild(overlay);
        if (callback) callback();
    };
    
    alertBox.appendChild(messageDiv);
    alertBox.appendChild(okButton);
    overlay.appendChild(alertBox);
    document.body.appendChild(overlay);
}

// 获取正确的API路径（使用相对路径，自动适应任何部署环境）- 与proxy-check.js共享
function getApiUrl(params) {
    // 使用相对路径 'index.php' 而不是绝对路径 '/index.php'
    // 这样会相对于当前HTML页面的位置
    return `index.php?${params}`;
}

// AJAX fetch包装函数，自动添加必要的请求头
function fetchApi(params, options = {}) {
    // 添加特殊标记参数，让服务器识别这是真正的AJAX请求
    const ajaxToken = Date.now(); // 使用时间戳作为防伪标记
    
    // 使用当前页面的origin和路径来构建完整URL
    // 如果当前页面是 /index.php，则使用 /index.php
    // 如果当前页面是 /subdir/index.php，则使用 /subdir/index.php
    const currentPath = window.location.pathname;
    let indexPath;
    
    if (currentPath.endsWith('index.php')) {
        // 当前就在 index.php 页面，直接使用
        indexPath = currentPath;
    } else if (currentPath.endsWith('/')) {
        // 当前在目录页面，添加 index.php
        indexPath = currentPath + 'index.php';
    } else {
        // 其他情况，获取目录部分并添加 index.php
        const lastSlash = currentPath.lastIndexOf('/');
        indexPath = currentPath.substring(0, lastSlash + 1) + 'index.php';
    }
    
    const url = `${indexPath}?${params}&_ajax_token=${ajaxToken}`;
    
    const defaultOptions = {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json, */*',
            'X-CSRF-Token': window.csrfToken || '',  // 添加CSRF Token
            ...options.headers
        },
        credentials: 'same-origin', // 确保发送cookies
        ...options
    };
    
    return fetch(url, defaultOptions);
}

// 注意：已移除自动刷新功能
// 原因：
// 1. 统计数据和日志只有在执行检测后才会变化
// 2. 检测完成后页面会自动刷新，无需定时刷新
// 3. 定时刷新浪费服务器资源和网络带宽
// 4. 用户体验没有实际提升


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
