<?php
/**
 * AJAX验证逻辑测试脚本
 */

// 模拟不同的请求环境来测试验证逻辑
function testAjaxValidation($testName, $serverVars) {
    // 备份原始的$_SERVER变量
    $originalServer = $_SERVER;
    
    // 设置测试环境
    foreach ($serverVars as $key => $value) {
        $_SERVER[$key] = $value;
    }
    
    // 包含验证函数（从index.php复制）
    function isValidAjaxRequest() {
        // 检查是否有XMLHttpRequest标头
        $isXmlHttpRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        // 检查Accept标头是否包含json或任意类型
        $acceptsJson = isset($_SERVER['HTTP_ACCEPT']) && 
                       (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false ||
                        strpos($_SERVER['HTTP_ACCEPT'], '*/*') !== false);
        
        // 检查Content-Type是否为json相关
        $contentTypeJson = isset($_SERVER['CONTENT_TYPE']) && 
                          strpos($_SERVER['CONTENT_TYPE'], 'json') !== false;
        
        // 检查Referer是否来自同一页面（防止直接访问）
        $hasValidReferer = isset($_SERVER['HTTP_REFERER']) && 
                          strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false;
        
        // 检查是否为浏览器发起的请求（而不是直接访问）
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isBrowserRequest = !empty($userAgent) && 
                           (strpos($userAgent, 'Mozilla') !== false || 
                            strpos($userAgent, 'Chrome') !== false ||
                            strpos($userAgent, 'Safari') !== false ||
                            strpos($userAgent, 'Edge') !== false);
        
        // 特殊情况：如果是浏览器直接访问且没有Referer，则可能是问题请求
        if (!$hasValidReferer && $isBrowserRequest) {
            // 检查是否是直接在地址栏输入或书签访问
            $isDirectAccess = !isset($_SERVER['HTTP_REFERER']) || empty($_SERVER['HTTP_REFERER']);
            if ($isDirectAccess) {
                return false; // 直接访问带ajax参数的URL，很可能是问题
            }
        }
        
        // 对于正常的AJAX请求，只要满足以下任一条件即可：
        // 1. 有XMLHttpRequest标头
        // 2. Accept头包含json或*/*
        // 3. 有有效的Referer且是浏览器请求
        return $isXmlHttpRequest || $acceptsJson || ($hasValidReferer && $isBrowserRequest);
    }
    
    $result = isValidAjaxRequest();
    
    // 恢复原始的$_SERVER变量
    $_SERVER = $originalServer;
    
    return $result;
}

// 测试用例
$tests = [
    // 正常的fetch请求（现代浏览器）
    'Fetch API请求' => [
        'HTTP_HOST' => 'check.frontech.dev',
        'HTTP_REFERER' => 'https://check.frontech.dev/index.php',
        'HTTP_ACCEPT' => '*/*',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ],
    
    // 传统的XMLHttpRequest
    'XMLHttpRequest请求' => [
        'HTTP_HOST' => 'check.frontech.dev',
        'HTTP_REFERER' => 'https://check.frontech.dev/index.php',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_ACCEPT' => 'application/json, text/javascript, */*; q=0.01',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ],
    
    // 移动端fetch请求
    '移动端Fetch请求' => [
        'HTTP_HOST' => 'check.frontech.dev',
        'HTTP_REFERER' => 'https://check.frontech.dev/index.php',
        'HTTP_ACCEPT' => '*/*',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Mobile/15E148 Safari/604.1'
    ],
    
    // 直接访问（应该被拦截）
    '直接访问URL' => [
        'HTTP_HOST' => 'check.frontech.dev',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        // 没有HTTP_REFERER
    ],
    
    // 书签访问（应该被拦截）
    '书签访问' => [
        'HTTP_HOST' => 'check.frontech.dev',
        'HTTP_REFERER' => '',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15'
    ],
    
    // 外部链接访问（应该被拦截）
    '外部链接访问' => [
        'HTTP_HOST' => 'check.frontech.dev',
        'HTTP_REFERER' => 'https://google.com',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]
];

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AJAX验证逻辑测试</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .test-result {
            margin: 20px 0;
            padding: 15px;
            border-radius: 5px;
            border-left: 5px solid;
        }
        .test-pass {
            background-color: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .test-fail {
            background-color: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        .test-name {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .test-details {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        .summary {
            margin-top: 30px;
            padding: 20px;
            background-color: #e9ecef;
            border-radius: 5px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 AJAX验证逻辑测试结果</h1>
        
        <?php
        $passCount = 0;
        $totalCount = count($tests);
        
        foreach ($tests as $testName => $serverVars) {
            $result = testAjaxValidation($testName, $serverVars);
            
            // 判断测试是否符合预期
            $shouldPass = !in_array($testName, ['直接访问URL', '书签访问', '外部链接访问']);
            $testPassed = ($result === $shouldPass);
            
            if ($testPassed) {
                $passCount++;
            }
            
            echo '<div class="test-result ' . ($testPassed ? 'test-pass' : 'test-fail') . '">';
            echo '<div class="test-name">' . htmlspecialchars($testName) . '</div>';
            echo '<div>验证结果: ' . ($result ? '✅ 通过' : '❌ 拦截') . '</div>';
            echo '<div>预期结果: ' . ($shouldPass ? '✅ 通过' : '❌ 拦截') . '</div>';
            echo '<div>测试状态: ' . ($testPassed ? '✅ 正确' : '❌ 错误') . '</div>';
            
            echo '<div class="test-details">';
            echo '用户代理: ' . htmlspecialchars($serverVars['HTTP_USER_AGENT'] ?? '无') . '<br>';
            echo 'Referer: ' . htmlspecialchars($serverVars['HTTP_REFERER'] ?? '无') . '<br>';
            echo 'Accept: ' . htmlspecialchars($serverVars['HTTP_ACCEPT'] ?? '无') . '<br>';
            echo 'X-Requested-With: ' . htmlspecialchars($serverVars['HTTP_X_REQUESTED_WITH'] ?? '无');
            echo '</div>';
            
            echo '</div>';
        }
        ?>
        
        <div class="summary">
            <h3>测试总结</h3>
            <p><strong><?php echo $passCount; ?></strong> / <?php echo $totalCount; ?> 测试通过</p>
            <?php if ($passCount === $totalCount): ?>
                <p style="color: #28a745;">🎉 所有测试都通过了！AJAX验证逻辑工作正常。</p>
            <?php else: ?>
                <p style="color: #dc3545;">⚠️ 有测试失败，需要调整验证逻辑。</p>
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 30px; text-align: center;">
            <a href="index.php" style="display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;">返回主页测试</a>
            <a href="view_debug_log.php" style="display: inline-block; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; margin-left: 10px;">查看调试日志</a>
        </div>
    </div>
</body>
</html>
