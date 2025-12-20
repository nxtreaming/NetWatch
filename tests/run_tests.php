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

$totalPassed = 0;
$totalFailed = 0;

foreach ($testFiles as $testFile) {
    $testName = basename($testFile, '.php');
    echo "运行测试: {$testName}\n";
    echo str_repeat('-', 40) . "\n";
    
    // 在子进程中运行测试
    $output = [];
    $returnCode = 0;
    exec("php \"{$testFile}\" 2>&1", $output, $returnCode);
    
    echo implode("\n", $output) . "\n";
    echo str_repeat('=', 40) . "\n\n";
}

echo "所有测试完成！\n";
