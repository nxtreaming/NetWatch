<?php
/**
 * NetWatch 自定义异常类
 * 提供标准化的异常处理机制
 */

/**
 * NetWatch 基础异常类
 */
class NetWatchException extends Exception {
    protected $context = [];
    
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null, array $context = []) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }
    
    /**
     * 获取异常上下文信息
     */
    public function getContext(): array {
        return $this->context;
    }
    
    /**
     * 获取格式化的错误信息
     */
    public function getFormattedMessage(): string {
        $msg = $this->getMessage();
        if (!empty($this->context)) {
            $msg .= ' | Context: ' . json_encode($this->context, JSON_UNESCAPED_UNICODE);
        }
        return $msg;
    }
}

/**
 * 数据库异常
 */
class DatabaseException extends NetWatchException {
    public function __construct(string $message = "数据库操作失败", int $code = 500, ?Throwable $previous = null, array $context = []) {
        parent::__construct($message, $code, $previous, $context);
    }
}

/**
 * 验证异常
 */
class ValidationException extends NetWatchException {
    protected $field;
    
    public function __construct(string $message = "验证失败", string $field = '', int $code = 400, ?Throwable $previous = null) {
        $this->field = $field;
        parent::__construct($message, $code, $previous, ['field' => $field]);
    }
    
    public function getField(): string {
        return $this->field;
    }
}

/**
 * 认证异常
 */
class AuthenticationException extends NetWatchException {
    public function __construct(string $message = "认证失败", int $code = 401, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * 授权异常
 */
class AuthorizationException extends NetWatchException {
    public function __construct(string $message = "权限不足", int $code = 403, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * 资源未找到异常
 */
class NotFoundException extends NetWatchException {
    public function __construct(string $message = "资源未找到", int $code = 404, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * 代理检测异常
 */
class ProxyCheckException extends NetWatchException {
    protected $proxyId;
    
    public function __construct(string $message = "代理检测失败", int $proxyId = 0, int $code = 500, ?Throwable $previous = null) {
        $this->proxyId = $proxyId;
        parent::__construct($message, $code, $previous, ['proxy_id' => $proxyId]);
    }
    
    public function getProxyId(): int {
        return $this->proxyId;
    }
}

/**
 * 配置异常
 */
class ConfigurationException extends NetWatchException {
    public function __construct(string $message = "配置错误", int $code = 500, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * API异常
 */
class ApiException extends NetWatchException {
    protected $httpCode;
    
    public function __construct(string $message = "API错误", int $httpCode = 500, int $code = 0, ?Throwable $previous = null) {
        $this->httpCode = $httpCode;
        parent::__construct($message, $code, $previous, ['http_code' => $httpCode]);
    }
    
    public function getHttpCode(): int {
        return $this->httpCode;
    }
}

/**
 * 限流异常
 */
class RateLimitException extends NetWatchException {
    protected $retryAfter;
    
    public function __construct(string $message = "请求过于频繁", int $retryAfter = 60, int $code = 429, ?Throwable $previous = null) {
        $this->retryAfter = $retryAfter;
        parent::__construct($message, $code, $previous, ['retry_after' => $retryAfter]);
    }
    
    public function getRetryAfter(): int {
        return $this->retryAfter;
    }
}

/**
 * 全局异常处理器
 */
class ExceptionHandler {
    private static $logger = null;
    
    /**
     * 设置日志记录器
     */
    public static function setLogger($logger): void {
        self::$logger = $logger;
    }
    
    /**
     * 注册全局异常处理器
     */
    public static function register(): void {
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
    }
    
    /**
     * 处理未捕获的异常
     */
    public static function handleException(Throwable $e): void {
        $code = $e instanceof NetWatchException ? $e->getCode() : 500;
        $message = $e->getMessage();
        
        // 记录日志
        if (self::$logger) {
            $context = $e instanceof NetWatchException ? $e->getContext() : [];
            $context['trace'] = $e->getTraceAsString();
            self::$logger->error("Uncaught Exception: $message", $context);
        }
        
        // 如果是AJAX请求，返回JSON
        if (self::isAjaxRequest()) {
            http_response_code($code >= 400 && $code < 600 ? $code : 500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => true,
                'message' => $message,
                'code' => $code
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // 否则显示错误页面
            http_response_code($code >= 400 && $code < 600 ? $code : 500);
            echo "<h1>错误</h1><p>" . htmlspecialchars($message) . "</p>";
        }
        
        exit(1);
    }
    
    /**
     * 将PHP错误转换为异常
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
    
    /**
     * 检测是否为AJAX请求
     */
    private static function isAjaxRequest(): bool {
        return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
               (isset($_GET['ajax']) && $_GET['ajax'] == '1') ||
               (isset($_SERVER['HTTP_ACCEPT']) && 
                strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }
}
