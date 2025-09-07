<?php
/**
 * API功能测试脚本
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'database.php';

// 检查登录状态
Auth::requireLogin();

echo "=== NetWatch API 功能测试 ===\n\n";

$db = new Database();

// 1. 测试数据库表是否创建成功
echo "1. 检查数据库表结构...\n";
try {
    $tokens = $db->getAllTokens();
    echo "✅ 数据库表创建成功\n\n";
} catch (Exception $e) {
    echo "❌ 数据库表创建失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 2. 创建测试Token
echo "2. 创建测试Token...\n";
$testTokenName = "测试Token_" . date('YmdHis');
$testProxyCount = 5;
$testExpiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

$testToken = $db->createApiToken($testTokenName, $testProxyCount, $testExpiresAt);
if ($testToken) {
    echo "✅ 测试Token创建成功: $testToken\n\n";
} else {
    echo "❌ 测试Token创建失败\n\n";
    exit(1);
}

// 3. 验证Token
echo "3. 验证Token...\n";
$tokenInfo = $db->validateToken($testToken);
if ($tokenInfo) {
    echo "✅ Token验证成功\n";
    echo "   - 名称: {$tokenInfo['name']}\n";
    echo "   - 代理数量: {$tokenInfo['proxy_count']}\n";
    echo "   - 过期时间: {$tokenInfo['expires_at']}\n\n";
} else {
    echo "❌ Token验证失败\n\n";
}

// 4. 获取分配的代理
echo "4. 检查代理分配...\n";
$assignedProxies = $db->getTokenProxies($tokenInfo['id']);
echo "✅ 已分配代理数量: " . count($assignedProxies) . "\n";
if (!empty($assignedProxies)) {
    echo "   示例代理: {$assignedProxies[0]['ip']}:{$assignedProxies[0]['port']} ({$assignedProxies[0]['type']})\n";
}
echo "\n";

// 5. 测试API端点
echo "5. 测试API端点...\n";

// 构建测试URL
$baseUrl = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/api.php";

$testEndpoints = [
    'help' => "?action=help",
    'token_info' => "?action=info&token=$testToken",
    'proxy_list_json' => "?action=proxies&token=$testToken",
    'proxy_list_txt' => "?action=proxies&token=$testToken&format=txt",
    'status' => "?action=status&token=$testToken"
];

foreach ($testEndpoints as $name => $endpoint) {
    $url = $baseUrl . $endpoint;
    echo "测试 $name: ";
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $httpCode = 200;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }
        
        if ($httpCode === 200) {
            echo "✅ 成功\n";
            
            // 对于JSON响应，验证格式
            if (strpos($endpoint, 'format=txt') === false) {
                $data = json_decode($response, true);
                if ($data && isset($data['success'])) {
                    if ($data['success']) {
                        echo "   - JSON格式正确，操作成功\n";
                    } else {
                        echo "   - JSON格式正确，但操作失败: " . ($data['error'] ?? '未知错误') . "\n";
                    }
                } else {
                    echo "   - ⚠️ 响应不是有效的JSON格式\n";
                }
            } else {
                echo "   - 文本格式响应长度: " . strlen($response) . " 字符\n";
            }
        } else {
            echo "❌ HTTP错误 $httpCode\n";
        }
    } else {
        echo "❌ 请求失败\n";
    }
}

echo "\n";

// 6. 测试无效Token
echo "6. 测试安全性（无效Token）...\n";
$invalidToken = "invalid_token_12345";
$url = $baseUrl . "?action=proxies&token=$invalidToken";

$response = @file_get_contents($url, false, $context);
if ($response !== false) {
    $data = json_decode($response, true);
    if ($data && !$data['success']) {
        echo "✅ 无效Token正确被拒绝\n";
    } else {
        echo "❌ 安全漏洞：无效Token被接受\n";
    }
} else {
    echo "❌ 请求失败\n";
}

echo "\n";

// 7. 清理测试数据
echo "7. 清理测试数据...\n";
$cleanupResult = $db->deleteToken($tokenInfo['id']);
if ($cleanupResult) {
    echo "✅ 测试Token已删除\n";
} else {
    echo "⚠️ 测试Token删除失败，请手动清理\n";
}

echo "\n=== 测试完成 ===\n";
echo "如果所有测试都显示 ✅，说明API功能正常工作。\n";
echo "您可以通过以下方式使用API：\n";
echo "1. 访问 token_manager.php 创建正式的Token\n";
echo "2. 访问 api_demo.php 查看详细的使用示例\n";
echo "3. 使用 api.php 端点获取授权的代理数据\n";
?>
