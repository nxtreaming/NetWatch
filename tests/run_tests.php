<?php
/**
 * 简单测试运行器
 * 运行所有单元测试
 */

echo "╔══════════════════════════════════════╗\n";
echo "║     NetWatch 单元测试运行器          ║\n";
echo "╚══════════════════════════════════════╝\n\n";

$testDir = __DIR__ . '/Unit';
$testFiles = glob($testDir . '/*Test.php');
$phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';

if ($testFiles === false) {
    fwrite(STDERR, "无法读取测试目录\n");
    exit(1);
}

if (empty($testFiles)) {
    fwrite(STDERR, "未找到任何测试文件\n");
    exit(1);
}

sort($testFiles);

$totalPassed = 0;
$totalFailed = 0;

foreach ($testFiles as $testFile) {
    $testName = basename($testFile, '.php');
    echo "运行测试: {$testName}\n";
    echo str_repeat('-', 40) . "\n";
    
    // 在子进程中运行测试
    $output = [];
    $returnCode = 0;
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($testFile) . ' 2>&1';
    exec($command, $output, $returnCode);
    
    if (!empty($output)) {
        echo implode("\n", $output) . "\n";
    }

    if ($returnCode === 0) {
        $totalPassed++;
        echo "结果: 通过\n";
    } else {
        $totalFailed++;
        echo "结果: 失败 (退出码: {$returnCode})\n";
    }

    echo str_repeat('=', 40) . "\n\n";
}

echo "测试文件通过: {$totalPassed}\n";
echo "测试文件失败: {$totalFailed}\n";

if ($totalFailed > 0) {
    echo "所有测试完成：存在失败用例。\n";
    exit(1);
}

echo "所有测试完成：全部通过。\n";
exit(0);
