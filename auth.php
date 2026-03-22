<?php
/**
 * 用户认证管理类
 */

require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/Config.php';
require_once __DIR__ . '/includes/functions.php';

class Auth {
    private const MAX_USERNAME_LENGTH = 64;
    private const MAX_PASSWORD_LENGTH = 1024;
    
    /**
     * 启动会话
     */
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                ini_set('session.use_strict_mode', '1');
                ini_set('session.use_only_cookies', '1');
                ini_set('session.cookie_httponly', '1');
                ini_set('session.cookie_samesite', 'Lax');

                $isHttps = netwatch_is_https_request();
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
    public static function isLoginEnabled(): bool {
        return defined('ENABLE_LOGIN') && ENABLE_LOGIN === true;
    }
    
    /**
     * 验证用户凭据
     */
    public static function validateCredentials(string $username, string $password): bool {
        if (
            strlen($username) === 0 ||
            strlen($username) > self::MAX_USERNAME_LENGTH ||
            strlen($password) === 0 ||
            strlen($password) > self::MAX_PASSWORD_LENGTH
        ) {
            return false;
        }

        if (!defined('LOGIN_USERNAME')) {
            return false;
        }

        if ($username !== LOGIN_USERNAME) {
            return false;
        }

        // 仅允许密码哈希校验
        if (defined('LOGIN_PASSWORD_HASH') && is_string(LOGIN_PASSWORD_HASH) && LOGIN_PASSWORD_HASH !== '') {
            return password_verify($password, LOGIN_PASSWORD_HASH);
        }

        error_log('[NetWatch][SECURITY] LOGIN_PASSWORD_HASH is required. Plaintext LOGIN_PASSWORD is no longer supported.');
        return false;
    }
    
    /**
     * 用户登录
     */
    public static function login(string $username, string $password): bool|string {
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
                try {
                    session_regenerate_id(true);
                } catch (\Exception $e) {
                    error_log('[NetWatch] session_regenerate_id 失败: ' . $e->getMessage());
                }
            }

            if (file_exists(__DIR__ . '/includes/AuditLogger.php')) {
                require_once __DIR__ . '/includes/AuditLogger.php';
                AuditLogger::log('login', 'user', $username);
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 用户登出
     * @param bool $redirect 是否重定向到登录页面（CLI/测试场景可设为false）
     */
    public static function logout(bool $redirect = true): void {
        self::startSession();

        $username = $_SESSION['username'] ?? null;
        if (file_exists(__DIR__ . '/includes/AuditLogger.php')) {
            require_once __DIR__ . '/includes/AuditLogger.php';
            AuditLogger::log('logout', 'user', $username);
        }
        
        // 清除所有session数据
        $_SESSION = [];
        
        // 删除session cookie
        if (isset($_COOKIE[session_name()])) {
            $cookieParams = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $cookieParams['path'] ?? '/',
                'domain' => $cookieParams['domain'] ?? '',
                'secure' => (bool) ($cookieParams['secure'] ?? false),
                'httponly' => (bool) ($cookieParams['httponly'] ?? true),
                'samesite' => $cookieParams['samesite'] ?? 'Lax'
            ]);
        }
        
        // 销毁session
        session_destroy();
        
        // CLI 模式或明确不重定向时，仅清理 session 不做 header/exit
        if (php_sapi_name() === 'cli' || !$redirect) {
            return;
        }
        
        // 重定向到登录页面（使用根目录路径）
        $loginPath = self::getLoginPath();
        header('Location: ' . $loginPath);
        exit;
    }
    
    /**
     * 检查用户是否已登录
     */
    public static function isLoggedIn(): bool {
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
    public static function isSessionExpired(): bool {
        if (!isset($_SESSION['last_activity'])) {
            return true;
        }
        
        $timeout = (int) config('security.session_timeout', 3600);
        return (time() - $_SESSION['last_activity']) > $timeout;
    }
    
    /**
     * 获取当前登录用户名
     */
    public static function getCurrentUser(): ?string {
        self::startSession();
        return $_SESSION['username'] ?? null;
    }
    
    /**
     * 获取登录时间
     */
    public static function getLoginTime(): ?int {
        self::startSession();
        return $_SESSION['login_time'] ?? null;
    }
    
    /**
     * 获取剩余会话时间（秒）
     */
    public static function getRemainingSessionTime(): int {
        if (!isset($_SESSION['last_activity'])) {
            return 0;
        }
        
        $timeout = (int) config('security.session_timeout', 3600);
        $elapsed = time() - $_SESSION['last_activity'];
        
        return max(0, $timeout - $elapsed);
    }
    
    /**
     * 生成CSRF Token
     */
    public static function generateCsrfToken(): string {
        self::startSession();
        
        $tokenLifetime = 3600; // CSRF Token有效期1小时
        
        // 检查是否需要轮换（不存在或已过期）
        if (!isset($_SESSION['csrf_token']) || 
            !isset($_SESSION['csrf_token_time']) ||
            (time() - $_SESSION['csrf_token_time']) > $tokenLifetime) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * 验证CSRF Token
     */
    public static function validateCsrfToken(string $token): bool {
        self::startSession();
        
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * 获取当前CSRF Token
     */
    public static function getCsrfToken(): string {
        self::startSession();
        return $_SESSION['csrf_token'] ?? self::generateCsrfToken();
    }
    
    /**
     * 要求用户登录（重定向到登录页面）
     */
    public static function requireLogin(): void {
        if (self::isDebugRequestPath()) {
            $debugEnabled = defined('ENABLE_DEBUG_TOOLS') && ENABLE_DEBUG_TOOLS === true;
            $allowInProduction = defined('ALLOW_DEBUG_TOOLS_IN_PRODUCTION') && ALLOW_DEBUG_TOOLS_IN_PRODUCTION === true;

            if (!$debugEnabled || (self::isProductionEnvironment() && !$allowInProduction)) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Not Found';
                exit;
            }
        }
        if (!self::isLoggedIn()) {
            // 如果是AJAX/JSON请求，返回JSON响应
            if (netwatch_request_expects_json_response()) {
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
     * 是否是 Debug 目录请求
     */
    private static function isDebugRequestPath(): bool {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        return stripos($scriptName, '/Debug/') !== false || stripos($scriptName, '\\Debug\\') !== false;
    }

    /**
     * 是否为生产环境（默认按生产环境处理，避免误暴露调试工具）
     */
    private static function isProductionEnvironment(): bool {
        $appEnv = defined('APP_ENV') ? strtolower((string)APP_ENV) : 'production';
        return !in_array($appEnv, ['local', 'dev', 'development', 'test', 'testing'], true);
    }
    
    /**
     * 获取登录页面路径（支持从子目录调用）
     */
    private static function getLoginPath(): string {
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
    public static function getRedirectUrl(): string {
        self::startSession();
        $redirectUrl = (string) ($_SESSION['redirect_after_login'] ?? '/');
        unset($_SESSION['redirect_after_login']);

        if ($redirectUrl === '' || $redirectUrl === '/') {
            return '/';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $redirectUrl) === 1) {
            return '/';
        }

        if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $redirectUrl) === 1) {
            return '/';
        }

        if (strncmp($redirectUrl, '//', 2) === 0 || strncmp($redirectUrl, '\\\\', 2) === 0) {
            return '/';
        }

        if ($redirectUrl[0] !== '/') {
            return '/';
        }

        return $redirectUrl;
    }
    
    /**
     * 检测存储空间是否足够
     */
    public static function checkStorageSpace(): array {
        $sessionPath = self::resolveSessionStoragePath();

        if (!is_dir($sessionPath) || !is_readable($sessionPath) || !is_writable($sessionPath)) {
            return [
                'status' => 'warning',
                'message' => 'Session 存储目录不可读或不可写：' . $sessionPath
            ];
        }

        $worldWritable = self::isWorldWritablePath($sessionPath);
        if ($worldWritable === true) {
            return [
                'status' => 'warning',
                'message' => 'Session 存储目录权限过宽（对所有用户可写），建议收紧目录权限：' . $sessionPath
            ];
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

    private static function resolveSessionStoragePath(): string {
        $sessionPath = trim((string) session_save_path());
        if ($sessionPath === '') {
            return sys_get_temp_dir();
        }

        if (strpos($sessionPath, ';') !== false) {
            $parts = explode(';', $sessionPath);
            $last = trim((string) end($parts));
            if ($last !== '') {
                return $last;
            }
        }

        return $sessionPath;
    }

    private static function isWorldWritablePath(string $path): ?bool {
        if (DIRECTORY_SEPARATOR !== '/') {
            return null;
        }

        $perms = @fileperms($path);
        if ($perms === false) {
            return null;
        }

        return (($perms & 0x0002) === 0x0002);
    }
}
