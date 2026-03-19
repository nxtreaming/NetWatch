<?php
/**
 * 请求频率限制器
 * 防止API滥用和暴力攻击
 */

require_once __DIR__ . '/JsonResponse.php';

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
            @mkdir($this->storageDir, 0700, true);
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
     * 获取客户端 IP
     *
     * 安全策略：默认信任 REMOTE_ADDR。
     * 仅当 REMOTE_ADDR 在可信任代理 CIDR 列表中时，才读取转发头中的真实客户端 IP。
     * 这样可防止攻击者通过伪造 X-Forwarded-For 绕过限流。
     *
     * @param array $trustedProxyCidrs 可信任代理 CIDR 列表，例如 ['127.0.0.1/32', '10.0.0.0/8']
     *                                  如果为空数组，则仅使用 REMOTE_ADDR
     */
    public static function getClientIp(array $trustedProxyCidrs = []): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 如果没有配置可信任代理，直接返回 REMOTE_ADDR
        if (empty($trustedProxyCidrs)) {
            $trustedProxyCidrs = self::getTrustedProxyCidrs();
            if (empty($trustedProxyCidrs)) {
                return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
            }
        }

        // 检查 REMOTE_ADDR 是否在可信任代理列表中
        if (!self::isIpInCidrs($remoteAddr, $trustedProxyCidrs)) {
            return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
        }

        // REMOTE_ADDR 是可信任代理，按优先级读取转发头
        $forwardingHeaders = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',   // 标准代理头
            'HTTP_X_REAL_IP',         // Nginx
        ];

        foreach ($forwardingHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For 可能包含多个 IP，取最左边（最近客户端）
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                // 如果是私有 IP 也接受（内网环境）
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
    }

    /**
     * 从配置中解析可信任代理 CIDR 列表
     */
    public static function getTrustedProxyCidrs(): array {
        if (!defined('TRUSTED_PROXY_CIDRS')) {
            return [];
        }

        $rawCidrs = TRUSTED_PROXY_CIDRS;
        if (is_string($rawCidrs)) {
            $cidrs = array_filter(array_map('trim', explode(',', $rawCidrs)));
        } elseif (is_array($rawCidrs)) {
            $cidrs = array_filter(array_map('trim', $rawCidrs));
        } else {
            return [];
        }

        $safeCidrs = [];
        foreach ($cidrs as $cidr) {
            if (!self::isValidCidrOrIp($cidr)) {
                continue;
            }

            // 禁止全网信任配置，避免错误配置导致请求头可被滥用
            if ($cidr === '0.0.0.0/0' || $cidr === '::/0') {
                continue;
            }

            $safeCidrs[] = $cidr;
        }

        return $safeCidrs;
    }

    /**
     * 检查是否为合法 IP 或 CIDR
     */
    private static function isValidCidrOrIp(string $value): bool {
        if (strpos($value, '/') === false) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        [$network, $prefix] = explode('/', $value, 2);
        $isIpv4 = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!$isIpv4 && !$isIpv6) {
            return false;
        }

        if (!preg_match('/^\d+$/', $prefix)) {
            return false;
        }

        $prefix = (int)$prefix;
        if ($isIpv4) {
            return $prefix >= 0 && $prefix <= 32;
        }

        return $prefix >= 0 && $prefix <= 128;
    }

    /**
     * 检查 IP 是否属于给定的 CIDR 列表
     * 支持单个 IP（如 '127.0.0.1'）和 CIDR 表示法（如 '10.0.0.0/8'）
     */
    private static function isIpInCidrs(string $ip, array $cidrs): bool {
        $ipBin = inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach ($cidrs as $cidr) {
            if (strpos($cidr, '/') === false) {
                // 单个 IP 地址
                $cidrIpBin = inet_pton($cidr);
                if ($cidrIpBin !== false && $cidrIpBin === $ipBin) {
                    return true;
                }
            } else {
                if (self::isIpInCidr($ipBin, $cidr)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 检查二进制 IP 是否命中 CIDR（同时支持 IPv4/IPv6）
     */
    private static function isIpInCidr(string $ipBin, string $cidr): bool {
        [$network, $prefix] = explode('/', $cidr, 2);
        $networkBin = inet_pton($network);
        if ($networkBin === false || strlen($networkBin) !== strlen($ipBin)) {
            return false;
        }

        $maxBits = strlen($networkBin) * 8;
        $prefix = (int) $prefix;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
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
        header('Retry-After: ' . $this->retryAfter($key));
        $this->sendHeaders($key);
        
        if ($this->isJsonRequest()) {
            JsonResponse::error('too_many_requests', '请求过于频繁，请稍后再试', 429, [
                'error' => true,
                'retry_after' => $this->retryAfter($key)
            ]);
        } else {
            http_response_code(429);
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
