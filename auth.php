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
            if (!headers_sent()) {
                ini_set('session.use_strict_mode', '1');
                ini_set('session.use_only_cookies', '1');
                ini_set('session.cookie_httponly', '1');
                ini_set('session.cookie_samesite', 'Lax');

                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                          (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
                          (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
                          (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
                ini_set('session.cookie_secure', $isHttps ? '1' : '0');

                $cookieParams = session_get_cookie_params();
                session_set_cookie_params([
                    'lifetime' => $cookieParams['lifetime'] ?? 0,
                    'path' => $cookieParams['path'] ?? '/',
                    'domain' => $cookieParams['domain'] ?? '',
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }

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
        if (!defined('LOGIN_USERNAME')) {
            return false;
        }

        if ($username !== LOGIN_USERNAME) {
            return false;
        }

        // A1 兼容迁移：优先使用密码哈希校验
        if (defined('LOGIN_PASSWORD_HASH') && is_string(LOGIN_PASSWORD_HASH) && LOGIN_PASSWORD_HASH !== '') {
            return password_verify($password, LOGIN_PASSWORD_HASH);
        }

        // 回退到明文密码（过渡期兼容）
        if (!defined('LOGIN_PASSWORD')) {
            return false;
        }

        return $password === LOGIN_PASSWORD;
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

            // 登录成功后重新生成Session ID，防止会话固定攻击
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_regenerate_id(true);
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
        
        // 清除所有session数据
        $_SESSION = [];
        
        // 删除session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        // 销毁session
        session_destroy();
        
        // 重定向到登录页面（使用根目录路径）
        $loginPath = self::getLoginPath();
        header('Location: ' . $loginPath);
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
     * 生成CSRF Token
     */
    public static function generateCsrfToken() {
        self::startSession();
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * 验证CSRF Token
     */
    public static function validateCsrfToken($token) {
        self::startSession();
        
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * 获取当前CSRF Token
     */
    public static function getCsrfToken() {
        self::startSession();
        return $_SESSION['csrf_token'] ?? self::generateCsrfToken();
    }
    
    /**
     * 要求用户登录（重定向到登录页面）
     */
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $isXmlHttpRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
            $acceptsJson = !empty($accept) && (strpos($accept, 'application/json') !== false);
            $acceptsHtml = !empty($accept) && (strpos($accept, 'text/html') !== false);
            $hasAjaxParam = isset($_GET['ajax']) && ($_GET['ajax'] === '1' || $_GET['ajax'] === 'true' || $_GET['ajax'] === 1);

            // 如果是AJAX/JSON请求，返回JSON响应
            if ($isXmlHttpRequest || $hasAjaxParam || ($acceptsJson && !$acceptsHtml)) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: 0');
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
            
            // 重定向到登录页面（使用根目录路径）
            $loginPath = self::getLoginPath();
            header('Location: ' . $loginPath);
            exit;
        }
    }
    
    /**
     * 获取登录页面路径（支持从子目录调用）
     */
    private static function getLoginPath() {
        // 获取当前脚本相对于网站根目录的路径
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $scriptDir = dirname($scriptName);
        
        // 计算到根目录的相对路径
        if ($scriptDir === '/' || $scriptDir === '\\') {
            return 'login.php';
        }
        
        // 计算需要返回的层级数
        $levels = substr_count($scriptDir, '/');
        $relativePath = str_repeat('../', $levels) . 'login.php';
        
        return $relativePath;
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
