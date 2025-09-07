<?php
/**
 * JavaScript语法检查工具
 * 检查index.php中的JavaScript代码是否有语法错误
 */

require_once '../auth.php';

// 检查登录状态
Auth::requireLogin();

echo "<h2>🔍 JavaScript语法检查</h2>\n";
echo "<pre>\n";

// 读取index.php文件
$indexFile = '../index.php';
if (!file_exists($indexFile)) {
    echo "❌ 文件不存在: $indexFile\n";
    exit;
}

$content = file_get_contents($indexFile);

// 提取JavaScript代码
$jsStart = strpos($content, '<script>');
$jsEnd = strrpos($content, '</script>');

if ($jsStart === false || $jsEnd === false) {
    echo "❌ 未找到JavaScript代码块\n";
    exit;
}

$jsCode = substr($content, $jsStart + 8, $jsEnd - $jsStart - 8);

echo "=== JavaScript代码提取成功 ===\n";
echo "代码长度: " . strlen($jsCode) . " 字符\n";
echo "代码行数: " . substr_count($jsCode, "\n") . " 行\n\n";

// 检查常见的语法问题
echo "=== 语法检查 ===\n";

$errors = [];

// 检查括号匹配
$openBraces = substr_count($jsCode, '{');
$closeBraces = substr_count($jsCode, '}');
$openParens = substr_count($jsCode, '(');
$closeParens = substr_count($jsCode, ')');
$openBrackets = substr_count($jsCode, '[');
$closeBrackets = substr_count($jsCode, ']');

echo "括号匹配检查:\n";
echo "  大括号: {$openBraces} 开 / {$closeBraces} 闭 " . ($openBraces == $closeBraces ? "✅" : "❌") . "\n";
echo "  小括号: {$openParens} 开 / {$closeParens} 闭 " . ($openParens == $closeParens ? "✅" : "❌") . "\n";
echo "  方括号: {$openBrackets} 开 / {$closeBrackets} 闭 " . ($openBrackets == $closeBrackets ? "✅" : "❌") . "\n";

if ($openBraces != $closeBraces) {
    $errors[] = "大括号不匹配";
}
if ($openParens != $closeParens) {
    $errors[] = "小括号不匹配";
}
if ($openBrackets != $closeBrackets) {
    $errors[] = "方括号不匹配";
}

// 检查函数定义
echo "\n函数定义检查:\n";
preg_match_all('/function\s+(\w+)\s*\(/', $jsCode, $functions);
if (!empty($functions[1])) {
    foreach ($functions[1] as $func) {
        echo "  - 函数: {$func}()\n";
    }
} else {
    echo "  未找到函数定义\n";
}

// 检查常见的语法错误模式
echo "\n常见错误检查:\n";

// 检查未闭合的字符串
$singleQuotes = substr_count($jsCode, "'");
$doubleQuotes = substr_count($jsCode, '"');
echo "  单引号数量: {$singleQuotes} " . ($singleQuotes % 2 == 0 ? "✅" : "❌") . "\n";
echo "  双引号数量: {$doubleQuotes} " . ($doubleQuotes % 2 == 0 ? "✅" : "❌") . "\n";

if ($singleQuotes % 2 != 0) {
    $errors[] = "单引号未闭合";
}
if ($doubleQuotes % 2 != 0) {
    $errors[] = "双引号未闭合";
}

// 检查分号
$lines = explode("\n", $jsCode);
$missingSemicolons = 0;
foreach ($lines as $lineNum => $line) {
    $line = trim($line);
    if (empty($line) || strpos($line, '//') === 0 || strpos($line, '/*') !== false) {
        continue;
    }
    
    // 检查需要分号但没有分号的行
    if (preg_match('/^\s*(var|let|const|return|\w+\s*=|.*\))\s*$/', $line) && !preg_match('/[;{}]$/', $line)) {
        $missingSemicolons++;
        if ($missingSemicolons <= 5) { // 只显示前5个
            echo "  第" . ($lineNum + 1) . "行可能缺少分号: " . substr($line, 0, 50) . "\n";
        }
    }
}

if ($missingSemicolons > 0) {
    echo "  发现 {$missingSemicolons} 行可能缺少分号\n";
}

// 检查未定义的函数调用
echo "\n函数调用检查:\n";
preg_match_all('/(\w+)\s*\(/', $jsCode, $calls);
$definedFunctions = array_merge($functions[1], ['fetch', 'setTimeout', 'setInterval', 'console', 'alert', 'document', 'window', 'Date', 'JSON', 'Promise']);

$undefinedCalls = [];
foreach ($calls[1] as $call) {
    if (!in_array($call, $definedFunctions) && !in_array($call, $undefinedCalls)) {
        $undefinedCalls[] = $call;
    }
}

if (!empty($undefinedCalls)) {
    echo "  可能未定义的函数:\n";
    foreach ($undefinedCalls as $call) {
        echo "    - {$call}()\n";
    }
} else {
    echo "  所有函数调用看起来都正常 ✅\n";
}

// 总结
echo "\n=== 检查结果 ===\n";
if (empty($errors)) {
    echo "✅ 未发现严重的语法错误\n";
    echo "JavaScript代码结构看起来正常\n";
} else {
    echo "❌ 发现以下问题:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

// 提供修复建议
if (!empty($errors)) {
    echo "\n=== 修复建议 ===\n";
    if (in_array("大括号不匹配", $errors)) {
        echo "1. 检查所有函数和代码块的大括号是否正确闭合\n";
    }
    if (in_array("小括号不匹配", $errors)) {
        echo "2. 检查所有函数调用和条件语句的小括号是否正确闭合\n";
    }
    if (in_array("单引号未闭合", $errors) || in_array("双引号未闭合", $errors)) {
        echo "3. 检查所有字符串是否正确闭合\n";
    }
}

echo "\n=== 检查完成 ===\n";
echo "</pre>\n";
?>

<style>
body { font-family: 'Segoe UI', Arial, sans-serif; margin: 20px; }
h2 { color: #2196F3; }
pre { background: #f5f5f5; padding: 15px; border-radius: 5px; line-height: 1.4; }
</style>
