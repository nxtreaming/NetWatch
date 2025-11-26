<?php
/**
 * 输入验证器类
 * 提供统一的输入验证方法，增强安全性
 */

class Validator {
    
    /**
     * 验证IP地址格式
     * @param string $ip IP地址
     * @return bool
     */
    public static function ip(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    /**
     * 验证IPv4地址格式
     * @param string $ip IP地址
     * @return bool
     */
    public static function ipv4(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
    
    /**
     * 验证IPv6地址格式
     * @param string $ip IP地址
     * @return bool
     */
    public static function ipv6(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
    
    /**
     * 验证端口号
     * @param mixed $port 端口号
     * @return bool
     */
    public static function port($port): bool {
        $port = filter_var($port, FILTER_VALIDATE_INT);
        return $port !== false && $port >= 1 && $port <= 65535;
    }
    
    /**
     * 验证代理类型
     * @param string $type 代理类型
     * @return bool
     */
    public static function proxyType(string $type): bool {
        $validTypes = ['http', 'https', 'socks4', 'socks5'];
        return in_array(strtolower($type), $validTypes, true);
    }
    
    /**
     * 验证代理状态
     * @param string $status 状态
     * @return bool
     */
    public static function proxyStatus(string $status): bool {
        $validStatuses = ['online', 'offline', 'unknown'];
        return in_array(strtolower($status), $validStatuses, true);
    }
    
    /**
     * 验证邮箱地址
     * @param string $email 邮箱地址
     * @return bool
     */
    public static function email(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * 验证URL格式
     * @param string $url URL地址
     * @return bool
     */
    public static function url(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * 验证正整数
     * @param mixed $value 值
     * @return bool
     */
    public static function positiveInt($value): bool {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        return $value !== false && $value > 0;
    }
    
    /**
     * 验证非负整数
     * @param mixed $value 值
     * @return bool
     */
    public static function nonNegativeInt($value): bool {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        return $value !== false && $value >= 0;
    }
    
    /**
     * 验证日期格式 (Y-m-d)
     * @param string $date 日期字符串
     * @return bool
     */
    public static function date(string $date): bool {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * 验证日期时间格式 (Y-m-d H:i:s)
     * @param string $datetime 日期时间字符串
     * @return bool
     */
    public static function datetime(string $datetime): bool {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        return $d && $d->format('Y-m-d H:i:s') === $datetime;
    }
    
    /**
     * 验证字符串长度范围
     * @param string $str 字符串
     * @param int $min 最小长度
     * @param int $max 最大长度
     * @return bool
     */
    public static function stringLength(string $str, int $min = 0, int $max = PHP_INT_MAX): bool {
        $len = mb_strlen($str);
        return $len >= $min && $len <= $max;
    }
    
    /**
     * 验证用户名格式（字母数字下划线，3-32位）
     * @param string $username 用户名
     * @return bool
     */
    public static function username(string $username): bool {
        return preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username) === 1;
    }
    
    /**
     * 验证Token格式（64位十六进制字符串）
     * @param string $token Token
     * @return bool
     */
    public static function apiToken(string $token): bool {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }
    
    /**
     * 清理并验证代理地址字符串
     * @param string $proxyString 代理字符串 (格式: ip:port 或 ip:port:type 或 ip:port:type:user:pass)
     * @return array|false 解析后的数组或false
     */
    public static function parseProxyString(string $proxyString) {
        $proxyString = trim($proxyString);
        if (empty($proxyString)) {
            return false;
        }
        
        $parts = explode(':', $proxyString);
        $count = count($parts);
        
        if ($count < 2) {
            return false;
        }
        
        $ip = $parts[0];
        $port = $parts[1];
        $type = $count >= 3 ? $parts[2] : 'http';
        $username = $count >= 4 ? $parts[3] : null;
        $password = $count >= 5 ? $parts[4] : null;
        
        // 验证IP和端口
        if (!self::ip($ip) || !self::port($port)) {
            return false;
        }
        
        // 验证代理类型
        if (!self::proxyType($type)) {
            $type = 'http'; // 默认类型
        }
        
        return [
            'ip' => $ip,
            'port' => (int)$port,
            'type' => strtolower($type),
            'username' => $username,
            'password' => $password
        ];
    }
    
    /**
     * 过滤HTML特殊字符（防止XSS）
     * @param string $str 输入字符串
     * @return string 过滤后的字符串
     */
    public static function escapeHtml(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * 过滤SQL通配符（用于LIKE查询）
     * @param string $str 输入字符串
     * @return string 过滤后的字符串
     */
    public static function escapeLike(string $str): string {
        return addcslashes($str, '%_\\');
    }
}
