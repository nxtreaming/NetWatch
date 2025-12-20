/**
 * NetWatch UI Module
 * UI模块 - 提供对话框、提示框等UI组件
 */

(function(NW) {
    'use strict';
    
    NW.UI = NW.UI || {};
    
    /**
     * 创建遮罩层
     * @param {string} id - 遮罩层ID
     * @param {number} zIndex - z-index值
     * @returns {HTMLElement}
     */
    NW.UI.createOverlay = function(id, zIndex = 999) {
        const overlay = document.createElement('div');
        overlay.id = id;
        overlay.style.cssText = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7); z-index: ${zIndex};
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(5px);
        `;
        return overlay;
    };
    
    /**
     * 自定义确认框
     * @param {string} message - 消息内容
     * @param {function} onConfirm - 确认回调
     * @param {function} onCancel - 取消回调
     */
    NW.UI.confirm = function(message, onConfirm, onCancel) {
        const overlay = NW.UI.createOverlay('confirm-overlay', 10000);
        
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
        messageDiv.style.cssText = 'margin-bottom: 25px; color: #e2e8f0;';
        
        const buttonContainer = document.createElement('div');
        buttonContainer.style.cssText = 'display: flex; gap: 12px; justify-content: center;';
        
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
    };
    
    /**
     * 自定义提示框
     * @param {string} message - 消息内容
     * @param {function} callback - 关闭回调
     */
    NW.UI.alert = function(message, callback) {
        const overlay = NW.UI.createOverlay('alert-overlay', 10000);
        
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
        messageDiv.style.cssText = 'margin-bottom: 25px; color: #e2e8f0; white-space: pre-wrap;';
        
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
    };
    
    /**
     * 创建进度对话框
     * @param {object} options - 配置选项
     * @returns {object} 进度对话框控制器
     */
    NW.UI.createProgressDialog = function(options = {}) {
        const {
            title = '处理中...',
            color = '#10b981',
            overlayId = 'progress-overlay',
            dialogId = 'progress-dialog'
        } = options;
        
        const isMobile = NW.isMobile();
        
        // 创建遮罩
        const overlay = document.createElement('div');
        overlay.id = overlayId;
        overlay.style.cssText = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7); z-index: 999;
            backdrop-filter: blur(5px);
        `;
        
        // 创建对话框
        const dialog = document.createElement('div');
        dialog.id = dialogId;
        dialog.style.cssText = `
            position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: #111c32; padding: ${isMobile ? '25px' : '40px'}; 
            border-radius: ${isMobile ? '15px' : '20px'};
            box-shadow: 0 20px 60px rgba(0,0,0,0.8); z-index: 1000;
            text-align: center; min-width: 300px; max-width: ${isMobile ? '95vw' : '800px'}; 
            width: 90vw;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            border: 1px solid rgba(255, 255, 255, 0.08);
            max-height: 90vh; overflow-y: auto;
        `;
        
        const titleSize = isMobile ? '20px' : '24px';
        const textSize = isMobile ? '14px' : '16px';
        const progressHeight = isMobile ? '20px' : '25px';
        
        dialog.innerHTML = `
            <h3 style="margin: 0 0 20px 0; color: #e2e8f0; font-size: ${titleSize}; font-weight: 600;">${title}</h3>
            <div id="progress-info" style="margin-bottom: 15px; color: #94a3b8; font-size: ${textSize}; line-height: 1.5;">准备中...</div>
            <div style="background: #14213d; border-radius: 15px; height: ${progressHeight}; margin: 15px 0; overflow: hidden; border: 1px solid rgba(255, 255, 255, 0.08);">
                <div id="progress-bar" style="background: linear-gradient(90deg, ${color}, ${color}dd); height: 100%; width: 0%; transition: width 0.5s ease; border-radius: 15px; position: relative;">
                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-weight: 600; font-size: 12px; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);" id="progress-percent">0%</div>
                </div>
            </div>
            <div id="progress-stats" style="font-size: ${textSize}; color: #e2e8f0; margin-bottom: 15px; padding: 12px; background: #14213d; border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.08);">准备开始...</div>
            <div id="progress-extra" style="font-size: ${isMobile ? '13px' : '14px'}; color: #94a3b8; margin-bottom: 15px; display: none;"></div>
            <div style="display: flex; justify-content: center; gap: 15px; margin-top: 15px;">
                <button id="progress-cancel" style="padding: ${isMobile ? '10px 20px' : '12px 24px'}; background: #ef4444; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: ${isMobile ? '14px' : '16px'}; font-weight: 500; transition: background 0.3s ease;">取消</button>
            </div>
        `;
        
        document.body.appendChild(overlay);
        document.body.appendChild(dialog);
        
        // 返回控制器
        return {
            overlay: overlay,
            dialog: dialog,
            
            updateProgress: function(percent) {
                const bar = document.getElementById('progress-bar');
                const percentText = document.getElementById('progress-percent');
                if (bar) bar.style.width = percent + '%';
                if (percentText) percentText.textContent = Math.round(percent) + '%';
            },
            
            updateInfo: function(text) {
                const info = document.getElementById('progress-info');
                if (info) info.textContent = text;
            },
            
            updateStats: function(text) {
                const stats = document.getElementById('progress-stats');
                if (stats) stats.textContent = text;
            },
            
            updateExtra: function(text, show = true) {
                const extra = document.getElementById('progress-extra');
                if (extra) {
                    extra.textContent = text;
                    extra.style.display = show ? 'block' : 'none';
                }
            },
            
            onCancel: function(callback) {
                const cancelBtn = document.getElementById('progress-cancel');
                if (cancelBtn) {
                    cancelBtn.onclick = callback;
                }
            },
            
            close: function() {
                if (document.body.contains(overlay)) {
                    document.body.removeChild(overlay);
                }
                if (document.body.contains(dialog)) {
                    document.body.removeChild(dialog);
                }
            }
        };
    };
    
    /**
     * Toast提示
     * @param {string} message - 消息
     * @param {string} type - 类型 (success, error, warning, info)
     * @param {number} duration - 显示时长(ms)
     */
    NW.UI.toast = function(message, type = 'info', duration = 3000) {
        const colors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6'
        };
        
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed; top: 20px; right: 20px;
            background: ${colors[type] || colors.info}; color: white;
            padding: 12px 24px; border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 10001; font-size: 14px;
            animation: slideIn 0.3s ease;
        `;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                if (document.body.contains(toast)) {
                    document.body.removeChild(toast);
                }
            }, 300);
        }, duration);
    };
    
    // 注入Toast动画样式
    (function injectToastStyles() {
        if (document.getElementById('netwatch-toast-styles')) return;
        const style = document.createElement('style');
        style.id = 'netwatch-toast-styles';
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    })();
    
})(window.NetWatch);

// 兼容旧版函数名（保持向后兼容）
window.showCustomAlert = window.NetWatch.UI.alert;
window.showCustomConfirm = window.NetWatch.UI.confirm;
