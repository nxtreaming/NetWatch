<?php
/**
 * 登录功能测试页面
 */

require_once '../config.php';
require_once '../auth.php';

// 检查登录状态
Auth::requireLogin();

echo "=== NetWatch 登录功能测试 ===\n\n";

// 1. 检查配置
echo "1. 配置检查:\n";
echo "   ENABLE_LOGIN: " . (defined('ENABLE_LOGIN') ? (ENABLE_LOGIN ? 'true' : 'false') : '未定义') . "\n";
echo "   LOGIN_USERNAME: " . (defined('LOGIN_USERNAME') ? LOGIN_USERNAME : '未定义') . "\n";
echo "   LOGIN_PASSWORD_HASH: " . (defined('LOGIN_PASSWORD_HASH') ? '[已设置]' : '未定义') . "\n";
echo "   SESSION_TIMEOUT: " . (defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT . '秒' : '未定义') . "\n\n";

// 2. 测试认证功能
echo "2. 认证功能测试:\n";

if (!Auth::isLoginEnabled()) {
    echo "   登录功能已禁用，所有用户都可以访问\n\n";
} else {
    echo "   登录功能已启用\n";
    
    // 测试错误凭据
    $testResult1 = Auth::validateCredentials('wrong', 'wrong');
    echo "   错误凭据测试: " . ($testResult1 ? '❌ 失败' : '✅ 通过') . "\n";

    echo "   当前版本仅支持 LOGIN_PASSWORD_HASH，无法在调试页中直接使用明文密码做自动校验\n";
    echo "\n";
}

// 3. 会话状态检查
echo "3. 会话状态检查:\n";
Auth::startSession();

echo "   当前登录状态: " . (Auth::isLoggedIn() ? '✅ 已登录' : '❌ 未登录') . "\n";

if (Auth::isLoggedIn()) {
    echo "   当前用户: " . Auth::getCurrentUser() . "\n";
    echo "   登录时间: " . date('Y-m-d H:i:s', Auth::getLoginTime()) . "\n";
    echo "   剩余会话时间: " . Auth::getRemainingSessionTime() . " 秒\n";
} else {
    echo "   未登录状态\n";
}
echo "\n";

// 4. 模拟登录测试
if (Auth::isLoginEnabled() && !Auth::isLoggedIn()) {
    echo "4. 模拟登录测试:\n";

    echo "   出于安全考虑，调试页不再读取或使用明文密码进行自动登录测试\n";
    echo "   请通过 login.php 手动验证登录流程\n";
    echo "\n";
}

// 5. 安全检查
echo "5. 安全检查:\n";

// 检查会话安全设置
$sessionParams = session_get_cookie_params();
echo "   会话Cookie设置:\n";
echo "     - httponly: " . ($sessionParams['httponly'] ? '✅ 启用' : '❌ 禁用') . "\n";
echo "     - secure: " . ($sessionParams['secure'] ? '✅ 启用' : '⚠️ 禁用 (HTTP环境正常)') . "\n";

echo "   密码策略: 请在生成 LOGIN_PASSWORD_HASH 前确保原始密码具备足够复杂度\n";

echo "\n=== 测试完成 ===\n";

// 使用说明
echo "\n使用说明:\n";
echo "1. 在 config.php 中配置登录信息:\n";
echo "   define('ENABLE_LOGIN', true);\n";
echo "   define('LOGIN_USERNAME', 'your_username');\n";
echo "   define('LOGIN_PASSWORD_HASH', password_hash('your_password', PASSWORD_BCRYPT));\n";
echo "   define('SESSION_TIMEOUT', 3600);\n\n";
echo "2. 访问 login.php 进行登录\n";
echo "3. 访问 index.php 查看监控界面\n";
echo "4. 点击右上角的退出登录按钮登出\n";
?>
