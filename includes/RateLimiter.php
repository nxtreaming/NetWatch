<?php
/**
 * 请求频率限制器
 * 防止API滥用和暴力攻击
 */

class RateLimiter {
    private string $storageDir;
    private int $maxRequests;
    private int $windowSeconds;
    
    /**
     * @param int $maxRequests 时间窗口内最大请求数
     * @param int $windowSeconds 时间窗口（秒）
     * @param string|null $storageDir 存储目录
     */
    public function __construct(int $maxRequests = 60, int $windowSeconds = 60, ?string $storageDir = null) {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->storageDir = $storageDir ?? sys_get_temp_dir() . '/netwatch_ratelimit';
        
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }
    
    /**
     * 检查是否允许请求
     * @param string $key 限制键（如IP地址、用户ID、API Token）
     * @return bool
     */
    public function attempt(string $key): bool {
        $data = $this->getData($key);
        $now = time();
        
        // 清理过期记录
        $data['requests'] = array_filter($data['requests'], function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->windowSeconds;
        });
        
        // 检查是否超限
        if (count($data['requests']) >= $this->maxRequests) {
            $this->saveData($key, $data);
            return false;
        }
        
        // 记录本次请求
        $data['requests'][] = $now;
        $this->saveData($key, $data);
        
        return true;
    }
    
    /**
     * 检查是否被限制（不增加计数）
     */
    public function isLimited(string $key): bool {
        $data = $this->getData($key);
        $now = time();
        
        $validRequests = array_filter($data['requests'], function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->windowSeconds;
        });
        
        return count($validRequests) >= $this->maxRequests;
    }
    
    /**
     * 获取剩余请求次数
     */
    public function remaining(string $key): int {
        $data = $this->getData($key);
        $now = time();
        
        $validRequests = array_filter($data['requests'], function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->windowSeconds;
        });
        
        return max(0, $this->maxRequests - count($validRequests));
    }
    
    /**
     * 获取重置时间（秒）
     */
    public function retryAfter(string $key): int {
        $data = $this->getData($key);
        
        if (empty($data['requests'])) {
            return 0;
        }
        
        $oldest = min($data['requests']);
        $resetAt = $oldest + $this->windowSeconds;
        
        return max(0, $resetAt - time());
    }
    
    /**
     * 清除限制记录
     */
    public function clear(string $key): void {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    
    /**
     * 获取客户端IP
     */
    public static function getClientIp(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // 代理
            'HTTP_X_REAL_IP',            // Nginx
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // 处理多个IP的情况
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * 发送限流响应头
     */
    public function sendHeaders(string $key): void {
        header('X-RateLimit-Limit: ' . $this->maxRequests);
        header('X-RateLimit-Remaining: ' . $this->remaining($key));
        header('X-RateLimit-Reset: ' . (time() + $this->retryAfter($key)));
    }
    
    /**
     * 发送429响应
     */
    public function sendTooManyRequestsResponse(string $key): void {
        http_response_code(429);
        header('Retry-After: ' . $this->retryAfter($key));
        $this->sendHeaders($key);
        
        if ($this->isJsonRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => true,
                'message' => '请求过于频繁，请稍后再试',
                'retry_after' => $this->retryAfter($key)
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo '请求过于频繁，请稍后再试';
        }
        exit;
    }
    
    private function getFilePath(string $key): string {
        return $this->storageDir . '/' . md5($key) . '.json';
    }
    
    private function getData(string $key): array {
        $file = $this->getFilePath($key);
        
        if (!file_exists($file)) {
            return ['requests' => []];
        }
        
        $content = @file_get_contents($file);
        if ($content === false) {
            return ['requests' => []];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['requests' => []];
    }
    
    private function saveData(string $key, array $data): void {
        $file = $this->getFilePath($key);
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
    
    private function isJsonRequest(): bool {
        return (isset($_SERVER['HTTP_ACCEPT']) && 
                strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
               (isset($_GET['ajax']) && $_GET['ajax'] == '1');
    }
}

/**
 * 预设限流配置
 */
class RateLimitPresets {
    /**
     * API请求限流（每分钟60次）
     */
    public static function api(): RateLimiter {
        return new RateLimiter(60, 60);
    }
    
    /**
     * 登录尝试限流（每分钟5次）
     */
    public static function login(): RateLimiter {
        return new RateLimiter(5, 60);
    }
    
    /**
     * 代理检测限流（每分钟10次）
     */
    public static function proxyCheck(): RateLimiter {
        return new RateLimiter(10, 60);
    }
    
    /**
     * 严格限流（每分钟3次）
     */
    public static function strict(): RateLimiter {
        return new RateLimiter(3, 60);
    }
}
