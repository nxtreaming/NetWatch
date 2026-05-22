<?php
declare(strict_types=1);
/**
 * NetWatch API接口
 * 基于Token的代理授权API
 */

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once __DIR__ . '/config.php';

if (!class_exists('Database')) {
    require_once __DIR__ . '/database.php';
}

if (!class_exists('Config')) {
    require_once __DIR__ . '/includes/Config.php';
}

if (!class_exists('RateLimiter')) {
    require_once __DIR__ . '/includes/RateLimiter.php';
}

if (!class_exists('JsonResponse')) {
    require_once __DIR__ . '/includes/JsonResponse.php';
}

if (!function_exists('app')) {
    require_once __DIR__ . '/includes/Container.php';
}

require_once __DIR__ . '/includes/security_headers.php';

ensure_valid_config('api');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$apiAllowOrigin = trim((string) config('api.allow_origin', ''));
if ($apiAllowOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $apiAllowOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

function api_log_event(string $event, array $context = []): void {
    error_log('[NetWatch][API] ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
}

function api_get_request_headers(): array {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0) {
            continue;
        }

        $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$headerName] = $value;
    }

    return $headers;
}

function api_extract_token(): string {
    $headers = api_get_request_headers();
    foreach ($headers as $headerName => $headerValue) {
        if (strcasecmp($headerName, 'Authorization') === 0 && preg_match('/Bearer\s+(.+)$/i', (string) $headerValue, $matches)) {
            return trim($matches[1]);
        }
    }

    $allowPostToken = (bool) config('api.allow_post_token', false);
    if (!$allowPostToken) {
        return '';
    }

    $postToken = trim((string) ($_POST['token'] ?? ''));
    if ($postToken !== '') {
        api_log_event('deprecated_post_token_auth_used', [
            'client_ip' => RateLimiter::getClientIp(),
            'deprecation' => 'Use Authorization: Bearer <token> instead of POST token parameter.',
        ]);
        return $postToken;
    }

    return '';
}

function api_build_rate_limit_key(string $token, string $clientIp): string {
    if ($token !== '') {
        return 'api:token:' . hash('sha256', $token);
    }

    return 'api:ip:' . $clientIp;
}

function api_send_response(array $response): void {
    $statusCode = (int) ($response['status_code'] ?? 200);
    $contentType = (string) ($response['content_type'] ?? 'application/json; charset=utf-8');
    $body = $response['body'] ?? null;

    if (strpos($contentType, 'application/json') === 0 && is_array($body)) {
        JsonResponse::send($body, $statusCode);
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: ' . $contentType);
    }
    http_response_code($statusCode);
    echo (string) $body;
}

function api_is_valid_ip_or_cidr(string $value): bool {
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

    $prefix = (int) $prefix;
    if ($isIpv4) {
        return $prefix >= 0 && $prefix <= 32;
    }

    return $prefix >= 0 && $prefix <= 128;
}

function api_parse_ip_whitelist($rawWhitelist): array {
    $entries = is_array($rawWhitelist)
        ? $rawWhitelist
        : explode(',', (string) $rawWhitelist);

    $validEntries = [];
    $invalidEntries = [];

    foreach ($entries as $entry) {
        $normalized = trim((string) $entry);
        if ($normalized === '') {
            continue;
        }

        if (!api_is_valid_ip_or_cidr($normalized)) {
            $invalidEntries[] = $normalized;
            continue;
        }

        if ($normalized === '0.0.0.0/0' || $normalized === '::/0') {
            $invalidEntries[] = $normalized;
            continue;
        }

        $validEntries[] = $normalized;
    }

    return [
        'valid' => array_values(array_unique($validEntries)),
        'invalid' => array_values(array_unique($invalidEntries)),
    ];
}

function api_is_ip_in_cidr(string $ip, string $cidr): bool {
    [$network, $prefix] = explode('/', $cidr, 2);
    $ipBin = inet_pton($ip);
    $networkBin = inet_pton($network);
    if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
        return false;
    }

    $prefix = (int) $prefix;
    $maxBits = strlen($networkBin) * 8;
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

function api_is_ip_whitelisted(string $clientIp, array $whitelist): bool {
    if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    foreach ($whitelist as $entry) {
        if (strpos($entry, '/') === false) {
            if ($entry === $clientIp) {
                return true;
            }
            continue;
        }

        if (api_is_ip_in_cidr($clientIp, $entry)) {
            return true;
        }
    }

    return false;
}

// 处理OPTIONS请求（CORS预检）
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// S-8: HTTPS强制检查（生产环境建议启用）
if ((bool) config('api.require_https', false) === true) {
    $isHttps = netwatch_is_https_request();
    if (!$isHttps) {
        JsonResponse::error('https_required', 'HTTPS is required for API access', 403);
        exit;
    }
}

$clientIp = RateLimiter::getClientIp();

// S-8: IP白名单检查（可选）
if (!empty(config('api.ip_whitelist', ''))) {
    $whitelistConfig = config('api.ip_whitelist', '');
    $whitelistParsed = api_parse_ip_whitelist($whitelistConfig);
    $whitelist = $whitelistParsed['valid'];
    $invalidEntries = $whitelistParsed['invalid'];

    if (!empty($invalidEntries)) {
        api_log_event('api_ip_whitelist_invalid_entries', [
            'invalid_entries' => $invalidEntries,
        ]);
    }

    if (empty($whitelist)) {
        api_log_event('api_ip_whitelist_empty_after_validation', [
            'client_ip' => $clientIp,
        ]);
        JsonResponse::error('configuration_error', 'API IP whitelist configuration is invalid', 500);
        exit;
    }

    if (!api_is_ip_whitelisted($clientIp, $whitelist)) {
        api_log_event('api_ip_not_in_whitelist', [
            'client_ip' => $clientIp,
        ]);
        JsonResponse::error('access_denied', 'Access denied', 403);
        exit;
    }
}

$tokenForRateLimit = api_extract_token();

$rateLimiter = RateLimitPresets::api();
$rateLimitKey = api_build_rate_limit_key($tokenForRateLimit, $clientIp);
if (!$rateLimiter->attempt($rateLimitKey)) {
    $rateLimiter->sendTooManyRequestsResponse($rateLimitKey);
}

class ProxyApi {
    private Database $db;
    
    public function __construct(?Database $db = null) {
        $this->db = $db ?? app()->db();
        $this->db->initializeSchema();
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
     * 是否允许在API响应中返回代理认证信息
     * 默认关闭，需在配置中显式开启（仅建议调试/管理员场景）
     */
    private function shouldExposeProxyAuth() {
        return defined('API_EXPOSE_PROXY_AUTH') && API_EXPOSE_PROXY_AUTH === true;
    }
    
    /**
     * 获取授权的代理列表
     */
    public function getProxies($token, $format = 'json') {
        // 验证Token
        $tokenInfo = $this->validateToken($token);
        if (!$tokenInfo) {
            return [
                'status_code' => 401,
                'body' => [
                    'success' => false,
                    'error' => 'Invalid or expired token',
                    'timestamp' => time(),
                ],
            ];
        }

        $includeAuth = $this->shouldExposeProxyAuth();
        
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

            if ($includeAuth && !empty($proxy['username']) && !empty($proxy['password'])) {
                $proxyData['auth'] = [
                    'username' => $proxy['username'],
                    'password' => $proxy['password']
                ];
                api_log_event('api_proxy_auth_exposed', [
                    'proxy_id' => $proxy['id'],
                    'token_id' => $tokenInfo['id'],
                    'format' => $format,
                ]);
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
                return [
                    'status_code' => 200,
                    'body' => [
                        'success' => true,
                        'data' => [
                            'token_name' => $tokenInfo['name'],
                            'proxy_count' => $tokenInfo['proxy_count'],
                            'assigned_count' => count($proxies),
                            'online_count' => count($onlineProxies),
                            'proxies' => $formattedProxies
                        ],
                        'message' => '',
                        'timestamp' => time(),
                    ],
                ];
        }
    }
    
    /**
     * 格式化为文本格式
     */
    private function formatAsText($proxies) {
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
        
        return [
            'status_code' => 200,
            'content_type' => 'text/plain; charset=utf-8',
            'body' => implode("\n", $output),
        ];
    }
    
    /**
     * 格式化为简单列表格式
     */
    private function formatAsList($proxies) {
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
        
        return [
            'status_code' => 200,
            'content_type' => 'text/plain; charset=utf-8',
            'body' => implode("\n", $output),
        ];
    }
    
    /**
     * 获取Token信息
     */
    public function getTokenInfo($token) {
        $tokenInfo = $this->validateToken($token);
        if (!$tokenInfo) {
            return [
                'status_code' => 401,
                'body' => [
                    'success' => false,
                    'error' => 'Invalid or expired token',
                    'timestamp' => time(),
                ],
            ];
        }
        
        $proxies = $this->db->getTokenProxies($tokenInfo['id']);
        $onlineCount = count(array_filter($proxies, function($proxy) {
            return $proxy['status'] === 'online';
        }));
        
        return [
            'status_code' => 200,
            'body' => [
                'success' => true,
                'data' => [
                    'name' => $tokenInfo['name'],
                    'proxy_count' => $tokenInfo['proxy_count'],
                    'assigned_count' => count($proxies),
                    'online_count' => $onlineCount,
                    'expires_at' => $tokenInfo['expires_at'],
                    'created_at' => $tokenInfo['created_at']
                ],
                'message' => '',
                'timestamp' => time(),
            ],
        ];
    }
    
    /**
     * 检查代理状态
     */
    public function checkProxyStatus($token, $proxyId = null) {
        $tokenInfo = $this->validateToken($token);
        if (!$tokenInfo) {
            return [
                'status_code' => 401,
                'body' => [
                    'success' => false,
                    'error' => 'Invalid or expired token',
                    'timestamp' => time(),
                ],
            ];
        }
        
        $proxies = $this->db->getTokenProxies($tokenInfo['id']);
        
        if ($proxyId !== null) {
            // 检查特定代理
            $proxy = array_filter($proxies, function($p) use ($proxyId) {
                return (int) $p['id'] === $proxyId;
            });
            
            if (empty($proxy)) {
                return [
                    'status_code' => 404,
                    'body' => [
                        'success' => false,
                        'error' => 'Proxy not found or not authorized',
                        'timestamp' => time(),
                    ],
                ];
            }
            
            $proxy = array_values($proxy)[0];
            return [
                'status_code' => 200,
                'body' => [
                    'success' => true,
                    'data' => [
                        'id' => $proxy['id'],
                        'host' => $proxy['ip'],
                        'port' => $proxy['port'],
                        'status' => $proxy['status'],
                        'response_time' => $proxy['response_time'],
                        'last_check' => $proxy['last_check'],
                        'failure_count' => $proxy['failure_count']
                    ],
                    'message' => '',
                    'timestamp' => time(),
                ],
            ];
        } else {
            // 返回所有代理状态统计
            $statusCount = [
                'online' => 0,
                'offline' => 0,
                'unknown' => 0
            ];
            
            foreach ($proxies as $proxy) {
                $key = $proxy['status'] ?? 'unknown';
                $statusCount[$key] = ($statusCount[$key] ?? 0) + 1;
            }
            
            return [
                'status_code' => 200,
                'body' => [
                    'success' => true,
                    'data' => [
                        'total' => count($proxies),
                        'status_breakdown' => $statusCount,
                        'last_updated' => date('Y-m-d H:i:s')
                    ],
                    'message' => '',
                    'timestamp' => time(),
                ],
            ];
        }
    }
}

// 路由处理
try {
    $api = new ProxyApi();
    $action = $_GET['action'] ?? '';
    $token = api_extract_token();
     
    switch ($action) {
        case 'proxies':
        case 'get_proxies':
            $allowedFormats = ['json', 'txt', 'list'];
            $rawFormat = strtolower((string) ($_GET['format'] ?? 'json'));
            $format = in_array($rawFormat, $allowedFormats, true) ? $rawFormat : 'json';
            api_send_response($api->getProxies($token, $format));
            break;
            
        case 'info':
        case 'token_info':
            api_send_response($api->getTokenInfo($token));
            break;
            
        case 'status':
        case 'check_status':
            $rawProxyId = $_GET['proxy_id'] ?? null;
            $proxyId = ($rawProxyId === null || $rawProxyId === '') ? null : (int) $rawProxyId;
            api_send_response($api->checkProxyStatus($token, $proxyId));
            break;
            
        case 'help':
            $authEnabled = defined('API_EXPOSE_PROXY_AUTH') && API_EXPOSE_PROXY_AUTH === true;
            $allowPostToken = (bool) config('api.allow_post_token', false);
            JsonResponse::success([
                'endpoints' => [
                    'GET /api.php?action=proxies' => '获取授权的代理列表（推荐使用 Authorization: Bearer YOUR_TOKEN）',
                    'GET /api.php?action=proxies&format=txt' => '获取文本格式的代理列表',
                    'GET /api.php?action=proxies&format=list' => '获取简单列表格式的代理',
                    'GET /api.php?action=info' => '获取Token信息',
                    'GET /api.php?action=status' => '获取代理状态统计',
                    'GET /api.php?action=status&proxy_id=123' => '获取特定代理状态'
                ],
                'authentication' => [
                    'recommended' => 'Authorization: Bearer YOUR_TOKEN',
                    'post_parameter' => $allowPostToken ? 'token=YOUR_TOKEN（已启用）' : '默认禁用（可通过 API_ALLOW_POST_TOKEN 启用）',
                    'query_parameter' => '已禁用'
                ],
                'formats' => [
                    'json' => '默认JSON格式',
                    'txt' => $authEnabled ? '代理URL格式 (protocol://user:pass@host:port)' : '代理URL格式 (protocol://host:port)',
                    'list' => $authEnabled ? '简单列表格式 (host:port:user:pass)' : '简单列表格式 (host:port)'
                ],
                'security' => [
                    'api_expose_proxy_auth' => $authEnabled
                ]
            ]);
            break;
            
        default:
            JsonResponse::error('invalid_action', 'Invalid action. Use ?action=help for available endpoints', 400);
            break;
    }
    
} catch (Exception $e) {
    api_log_event('api_request_failed', [
        'action' => $action ?? '',
        'client_ip' => $clientIp ?? '',
        'exception' => $e->getMessage(),
    ]);
    JsonResponse::error('internal_server_error', 'Internal server error', 500);
}
