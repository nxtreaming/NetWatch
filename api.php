<?php
/**
 * NetWatch API接口
 * 基于Token的代理授权API
 */

require_once 'config.php';
require_once 'database.php';
require_once 'includes/RateLimiter.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . (defined('API_ALLOW_ORIGIN') ? API_ALLOW_ORIGIN : '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 处理OPTIONS请求（CORS预检）
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// S-8: HTTPS强制检查（生产环境建议启用）
if (defined('API_REQUIRE_HTTPS') && API_REQUIRE_HTTPS === true) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
              (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
              (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
              (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
    if (!$isHttps) {
        echo ApiResponse::error('HTTPS is required for API access', 403);
        exit;
    }
}

// S-8: IP白名单检查（可选）
if (defined('API_IP_WHITELIST') && !empty(API_IP_WHITELIST)) {
    $clientIp = RateLimiter::getClientIp();
    $whitelist = is_array(API_IP_WHITELIST) ? API_IP_WHITELIST : explode(',', API_IP_WHITELIST);
    $whitelist = array_map('trim', $whitelist);
    if (!in_array($clientIp, $whitelist, true)) {
        error_log('[NetWatch][API] IP not in whitelist: ' . $clientIp);
        echo json_encode(['success' => false, 'error' => 'Access denied', 'timestamp' => time()]);
        http_response_code(403);
        exit;
    }
}

$tokenForRateLimit = $_GET['token'] ?? $_POST['token'] ?? '';
if (empty($tokenForRateLimit)) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            $tokenForRateLimit = $matches[1];
        }
    }
}

$rateLimiter = RateLimitPresets::api();
$rateLimitKey = !empty($tokenForRateLimit)
    ? ('api:token:' . $tokenForRateLimit)
    : ('api:ip:' . RateLimiter::getClientIp());
if (!$rateLimiter->attempt($rateLimitKey)) {
    $rateLimiter->sendTooManyRequestsResponse($rateLimitKey);
}

class ApiResponse {
    public static function success($data = null, $message = '') {
        return json_encode([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'timestamp' => time()
        ]);
    }
    
    public static function error($message, $code = 400) {
        http_response_code($code);
        return json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => time()
        ]);
    }
}

class ProxyApi {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * 验证Token并获取Token信息
     */
    private function validateToken($token) {
        if (empty($token)) {
            return false;
        }
        
        return $this->db->validateToken($token);
    }
    
    /**
     * 获取授权的代理列表
     */
    public function getProxies($token, $format = 'json') {
        // 验证Token
        $tokenInfo = $this->validateToken($token);
        if (!$tokenInfo) {
            return ApiResponse::error('Invalid or expired token', 401);
        }
        
        // 获取分配给该Token的代理
        $proxies = $this->db->getTokenProxies($tokenInfo['id']);
        
        // 过滤只返回在线的代理
        $onlineProxies = array_filter($proxies, function($proxy) {
            return $proxy['status'] === 'online';
        });
        
        // 如果没有在线代理，返回所有分配的代理
        if (empty($onlineProxies)) {
            $onlineProxies = $proxies;
        }
        
        // 格式化代理数据
        $formattedProxies = [];
        foreach ($onlineProxies as $proxy) {
            $proxyData = [
                'id' => $proxy['id'],
                'host' => $proxy['ip'],
                'port' => (int)$proxy['port'],
                'type' => strtolower($proxy['type']),
                'status' => $proxy['status'],
                'response_time' => $proxy['response_time']
            ];
            
            // 如果有认证信息，添加到返回数据中
            if (!empty($proxy['username']) && !empty($proxy['password'])) {
                $proxyData['auth'] = [
                    'username' => $proxy['username'],
                    'password' => $proxy['password']
                ];
                // S-8: 记录敏感数据访问
                error_log('[NetWatch][API] Proxy auth data accessed for proxy_id=' . $proxy['id'] . ' by token=' . substr($token, 0, 8) . '...');
            }
            
            $formattedProxies[] = $proxyData;
        }
        
        // 根据格式返回数据
        switch ($format) {
            case 'txt':
                return $this->formatAsText($formattedProxies);
            case 'list':
                return $this->formatAsList($formattedProxies);
            default:
                return ApiResponse::success([
                    'token_name' => $tokenInfo['name'],
                    'total_assigned' => count($proxies),
                    'online_count' => count($onlineProxies),
                    'proxies' => $formattedProxies
                ]);
        }
    }
    
    /**
     * 格式化为文本格式
     */
    private function formatAsText($proxies) {
        header('Content-Type: text/plain');
        $output = [];
        
        foreach ($proxies as $proxy) {
            if (isset($proxy['auth'])) {
                $output[] = sprintf(
                    "%s://%s:%s@%s:%d",
                    $proxy['type'],
                    $proxy['auth']['username'],
                    $proxy['auth']['password'],
                    $proxy['host'],
                    $proxy['port']
                );
            } else {
                $output[] = sprintf(
                    "%s://%s:%d",
                    $proxy['type'],
                    $proxy['host'],
                    $proxy['port']
                );
            }
        }
        
        return implode("\n", $output);
    }
    
    /**
     * 格式化为简单列表格式
     */
    private function formatAsList($proxies) {
        header('Content-Type: text/plain');
        $output = [];
        
        foreach ($proxies as $proxy) {
            if (isset($proxy['auth'])) {
                $output[] = sprintf(
                    "%s:%d:%s:%s",
                    $proxy['host'],
                    $proxy['port'],
                    $proxy['auth']['username'],
                    $proxy['auth']['password']
                );
            } else {
                $output[] = sprintf(
                    "%s:%d",
                    $proxy['host'],
                    $proxy['port']
                );
            }
        }
        
        return implode("\n", $output);
    }
    
    /**
     * 获取Token信息
     */
    public function getTokenInfo($token) {
        $tokenInfo = $this->validateToken($token);
        if (!$tokenInfo) {
            return ApiResponse::error('Invalid or expired token', 401);
        }
        
        $proxies = $this->db->getTokenProxies($tokenInfo['id']);
        $onlineCount = count(array_filter($proxies, function($proxy) {
            return $proxy['status'] === 'online';
        }));
        
        return ApiResponse::success([
            'name' => $tokenInfo['name'],
            'proxy_count' => $tokenInfo['proxy_count'],
            'assigned_count' => count($proxies),
            'online_count' => $onlineCount,
            'expires_at' => $tokenInfo['expires_at'],
            'created_at' => $tokenInfo['created_at']
        ]);
    }
    
    /**
     * 检查代理状态
     */
    public function checkProxyStatus($token, $proxyId = null) {
        $tokenInfo = $this->validateToken($token);
        if (!$tokenInfo) {
            return ApiResponse::error('Invalid or expired token', 401);
        }
        
        $proxies = $this->db->getTokenProxies($tokenInfo['id']);
        
        if ($proxyId) {
            // 检查特定代理
            $proxy = array_filter($proxies, function($p) use ($proxyId) {
                return $p['id'] == $proxyId;
            });
            
            if (empty($proxy)) {
                return ApiResponse::error('Proxy not found or not authorized', 404);
            }
            
            $proxy = array_values($proxy)[0];
            return ApiResponse::success([
                'id' => $proxy['id'],
                'host' => $proxy['ip'],
                'port' => $proxy['port'],
                'status' => $proxy['status'],
                'response_time' => $proxy['response_time'],
                'last_check' => $proxy['last_check'],
                'failure_count' => $proxy['failure_count']
            ]);
        } else {
            // 返回所有代理状态统计
            $statusCount = [
                'online' => 0,
                'offline' => 0,
                'unknown' => 0
            ];
            
            foreach ($proxies as $proxy) {
                $statusCount[$proxy['status']]++;
            }
            
            return ApiResponse::success([
                'total' => count($proxies),
                'status_breakdown' => $statusCount,
                'last_updated' => date('Y-m-d H:i:s')
            ]);
        }
    }
}

// 路由处理
try {
    $api = new ProxyApi();
    $action = $_GET['action'] ?? '';
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    
    // 从Authorization头获取token
    if (empty($token)) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                $token = $matches[1];
            }
        }
    }
    
    switch ($action) {
        case 'proxies':
        case 'get_proxies':
            $format = $_GET['format'] ?? 'json';
            echo $api->getProxies($token, $format);
            break;
            
        case 'info':
        case 'token_info':
            echo $api->getTokenInfo($token);
            break;
            
        case 'status':
        case 'check_status':
            $proxyId = $_GET['proxy_id'] ?? null;
            echo $api->checkProxyStatus($token, $proxyId);
            break;
            
        case 'help':
            echo ApiResponse::success([
                'endpoints' => [
                    'GET /api.php?action=proxies&token=YOUR_TOKEN' => '获取授权的代理列表',
                    'GET /api.php?action=proxies&token=YOUR_TOKEN&format=txt' => '获取文本格式的代理列表',
                    'GET /api.php?action=proxies&token=YOUR_TOKEN&format=list' => '获取简单列表格式的代理',
                    'GET /api.php?action=info&token=YOUR_TOKEN' => '获取Token信息',
                    'GET /api.php?action=status&token=YOUR_TOKEN' => '获取代理状态统计',
                    'GET /api.php?action=status&token=YOUR_TOKEN&proxy_id=123' => '获取特定代理状态'
                ],
                'authentication' => [
                    'query_parameter' => '?token=YOUR_TOKEN',
                    'post_parameter' => 'token=YOUR_TOKEN',
                    'authorization_header' => 'Authorization: Bearer YOUR_TOKEN'
                ],
                'formats' => [
                    'json' => '默认JSON格式',
                    'txt' => '代理URL格式 (protocol://user:pass@host:port)',
                    'list' => '简单列表格式 (host:port:user:pass)'
                ]
            ]);
            break;
            
        default:
            echo ApiResponse::error('Invalid action. Use ?action=help for available endpoints', 400);
    }
    
} catch (Exception $e) {
    echo ApiResponse::error('Internal server error: ' . $e->getMessage(), 500);
}
?>
