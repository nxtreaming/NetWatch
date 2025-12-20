/**
 * NetWatch Core JavaScript Module
 * 核心模块 - 提供基础功能和命名空间
 */

// 创建全局命名空间
window.NetWatch = window.NetWatch || {};

(function(NW) {
    'use strict';
    
    // 版本信息
    NW.version = '2.0.0';
    
    // 配置
    NW.config = {
        apiEndpoint: 'index.php',
        refreshInterval: 300000, // 5分钟
        cacheTimeout: 300000,    // 5分钟缓存
        requestTimeout: 120000,  // 2分钟请求超时
        parallelCheckInterval: 500, // 并行检测进度查询间隔
        maxParallelTimeout: 30 * 60 * 1000 // 30分钟最大超时
    };
    
    // 状态管理
    NW.state = {
        cachedProxyCount: null,
        cacheTimestamp: null,
        isOfflineMode: false,
        currentSessionId: null
    };
    
    /**
     * 获取API URL
     * @param {string} params - 查询参数
     * @returns {string} 完整URL
     */
    NW.getApiUrl = function(params) {
        const currentPath = window.location.pathname;
        let indexPath;
        
        if (currentPath.endsWith('index.php')) {
            indexPath = currentPath;
        } else if (currentPath.endsWith('/')) {
            indexPath = currentPath + 'index.php';
        } else {
            const lastSlash = currentPath.lastIndexOf('/');
            indexPath = currentPath.substring(0, lastSlash + 1) + 'index.php';
        }
        
        const ajaxToken = Date.now();
        return `${indexPath}?${params}&_ajax_token=${ajaxToken}`;
    };
    
    /**
     * AJAX请求封装
     * @param {string} params - 查询参数
     * @param {object} options - fetch选项
     * @returns {Promise} fetch Promise
     */
    NW.fetchApi = function(params, options = {}) {
        const url = NW.getApiUrl(params);
        
        const defaultOptions = {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json, */*',
                'X-CSRF-Token': window.csrfToken || '',
                ...options.headers
            },
            credentials: 'same-origin',
            ...options
        };
        
        return fetch(url, defaultOptions);
    };
    
    /**
     * 检查登录状态
     * @param {object} data - API响应数据
     * @returns {boolean} 是否需要重新登录
     */
    NW.checkAuth = function(data) {
        if (data.error === 'unauthorized') {
            alert(data.message || '登录已过期，请重新登录');
            window.location.href = 'login.php';
            return false;
        }
        return true;
    };
    
    /**
     * 获取代理数量（带缓存）
     * @returns {Promise<number|null>}
     */
    NW.getProxyCount = async function() {
        // 检查缓存
        if (NW.state.cachedProxyCount !== null && 
            NW.state.cacheTimestamp && 
            (Date.now() - NW.state.cacheTimestamp) < NW.config.cacheTimeout) {
            return NW.state.cachedProxyCount;
        }
        
        try {
            const response = await NW.fetchApi('ajax=1&action=getProxyCount');
            const data = await response.json();
            
            if (data.success) {
                NW.state.cachedProxyCount = data.count;
                NW.state.cacheTimestamp = Date.now();
                console.log(`获取代理数量: ${data.count} (查询时间: ${data.execution_time}ms, 缓存: ${data.cached ? '是' : '否'})`);
                return data.count;
            }
        } catch (error) {
            console.error('获取代理数量失败:', error);
        }
        return null;
    };
    
    /**
     * 获取缓存的代理数量
     * @returns {number|null}
     */
    NW.getCachedProxyCount = function() {
        if (NW.state.cachedProxyCount !== null && 
            NW.state.cacheTimestamp && 
            (Date.now() - NW.state.cacheTimestamp) < NW.config.cacheTimeout) {
            return NW.state.cachedProxyCount;
        }
        return null;
    };
    
    /**
     * 会话检查
     */
    NW.checkSession = function() {
        NW.fetchApi('ajax=1&action=sessionCheck')
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
    };
    
    /**
     * 检测设备类型
     * @returns {boolean} 是否为移动设备
     */
    NW.isMobile = function() {
        return window.innerWidth <= 768;
    };
    
    /**
     * 格式化字节数
     * @param {number} bytes - 字节数
     * @returns {string} 格式化后的字符串
     */
    NW.formatBytes = function(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };
    
    /**
     * 格式化时间
     * @param {number} ms - 毫秒数
     * @returns {string} 格式化后的字符串
     */
    NW.formatTime = function(ms) {
        if (ms < 1000) return ms + 'ms';
        if (ms < 60000) return (ms / 1000).toFixed(1) + 's';
        return (ms / 60000).toFixed(1) + 'min';
    };
    
    // 初始化
    NW.init = function() {
        console.log('NetWatch Core v' + NW.version + ' initialized');
    };
    
})(window.NetWatch);

// 自动初始化
document.addEventListener('DOMContentLoaded', function() {
    window.NetWatch.init();
});
