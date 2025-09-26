<?php
/**
 * 用户认证管理类
 */

class Auth {
    
    /**
     * 启动会话
     */
    public static function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * 检查是否启用登录功能
     */
    public static function isLoginEnabled() {
        return defined('ENABLE_LOGIN') && ENABLE_LOGIN === true;
    }
    
    /**
     * 验证用户凭据
     */
    public static function validateCredentials($username, $password) {
        if (!defined('LOGIN_USERNAME') || !defined('LOGIN_PASSWORD')) {
            return false;
        }
        
        return $username === LOGIN_USERNAME && $password === LOGIN_PASSWORD;
    }
    
    /**
     * 用户登录
     */
    public static function login($username, $password) {
        self::startSession();
        
        if (self::validateCredentials($username, $password)) {
            // 尝试设置session数据
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            
            // 强制写入session数据到存储
            session_write_close();
            
            // 重新启动session并验证数据是否成功写入
            self::startSession();
            
            // 检查session数据是否成功保存
            if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || 
                !isset($_SESSION['username']) || $_SESSION['username'] !== $username) {
                
                // Session写入失败，清理可能的残留数据
                $_SESSION = array();
                return 'session_write_failed';
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 用户登出
     */
    public static function logout() {
        self::startSession();
        
        // 清除会话数据
        $_SESSION = array();
        
        // 删除会话cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // 销毁会话
        session_destroy();
        
        // 重定向到登录页面
        header('Location: login.php');
        exit;
    }
    
    /**
     * 检查用户是否已登录
     */
    public static function isLoggedIn() {
        // 如果未启用登录功能，直接返回true
        if (!self::isLoginEnabled()) {
            return true;
        }
        
        self::startSession();
        
        // 检查是否已登录
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }
        
        // 检查会话是否超时
        if (self::isSessionExpired()) {
            self::logout();
            return false;
        }
        
        // 更新最后活动时间
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * 检查会话是否过期
     */
    public static function isSessionExpired() {
        if (!isset($_SESSION['last_activity'])) {
            return true;
        }
        
        $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
        return (time() - $_SESSION['last_activity']) > $timeout;
    }
    
    /**
     * 获取当前登录用户名
     */
    public static function getCurrentUser() {
        self::startSession();
        return $_SESSION['username'] ?? null;
    }
    
    /**
     * 获取登录时间
     */
    public static function getLoginTime() {
        self::startSession();
        return $_SESSION['login_time'] ?? null;
    }
    
    /**
     * 获取剩余会话时间（秒）
     */
    public static function getRemainingSessionTime() {
        if (!isset($_SESSION['last_activity'])) {
            return 0;
        }
        
        $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
        $elapsed = time() - $_SESSION['last_activity'];
        
        return max(0, $timeout - $elapsed);
    }
    
    /**
     * 要求用户登录（重定向到登录页面）
     */
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            // 如果是AJAX请求，返回JSON响应
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'unauthorized',
                    'message' => '请先登录'
                ]);
                exit;
            }
            
            // 保存当前页面URL，登录后重定向
            $currentUrl = $_SERVER['REQUEST_URI'];
            if ($currentUrl !== '/login.php') {
                $_SESSION['redirect_after_login'] = $currentUrl;
            }
            
            // 重定向到登录页面
            header('Location: login.php');
            exit;
        }
    }
    
    /**
     * 获取登录后重定向URL
     */
    public static function getRedirectUrl() {
        self::startSession();
        $redirectUrl = $_SESSION['redirect_after_login'] ?? '/';
        unset($_SESSION['redirect_after_login']);
        
        // Ensure we return a valid URL path
        if ($redirectUrl === '/' || empty($redirectUrl)) {
            return '/';
        }
        
        // Remove any leading slashes to prevent double slashes
        return '/' . ltrim($redirectUrl, '/');
    }
    
    /**
     * 检测存储空间是否足够
     */
    public static function checkStorageSpace() {
        $sessionPath = session_save_path();
        if (empty($sessionPath)) {
            $sessionPath = sys_get_temp_dir();
        }
        
        // 检查磁盘空间
        $freeBytes = disk_free_space($sessionPath);
        $totalBytes = disk_total_space($sessionPath);
        
        if ($freeBytes === false || $totalBytes === false) {
            return [
                'status' => 'unknown',
                'message' => '无法检测存储空间'
            ];
        }
        
        $freePercent = ($freeBytes / $totalBytes) * 100;
        
        if ($freePercent < 1) {
            return [
                'status' => 'critical',
                'message' => '存储空间严重不足（剩余 ' . round($freePercent, 2) . '%），可能导致登录失败',
                'free_percent' => $freePercent,
                'free_mb' => round($freeBytes / 1024 / 1024, 2)
            ];
        } elseif ($freePercent < 5) {
            return [
                'status' => 'warning',
                'message' => '存储空间不足（剩余 ' . round($freePercent, 2) . '%）',
                'free_percent' => $freePercent,
                'free_mb' => round($freeBytes / 1024 / 1024, 2)
            ];
        }
        
        return [
            'status' => 'ok',
            'message' => '存储空间充足',
            'free_percent' => $freePercent,
            'free_mb' => round($freeBytes / 1024 / 1024, 2)
        ];
    }
}
?>
