<?php
/**
 * ParallelStatusUtils 工具函数单元测试
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once PROJECT_ROOT . '/includes/ParallelStatusUtils.php';

class ParallelStatusUtilsTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];
    private string $tempDir;

    public function __construct() {
        $this->tempDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'netwatch_parallel_status_tests_' . uniqid('', true);
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    public function run(): bool {
        echo "=== ParallelStatusUtils 单元测试 ===\n\n";

        $this->testReadJsonFileReturnsNullForMissingFile();
        $this->testReadJsonFileReturnsNullForInvalidJson();
        $this->testWriteAndReadJsonFile();
        $this->testIsBatchFinishedRecognizesTerminalStatuses();
        $this->testIsBatchFinishedRejectsNonTerminalStatuses();
        $this->testIsCancelledDirDetectsCancelFlag();

        $this->printResults();
        $this->cleanup();

        return $this->failed === 0;
    }

    private function testReadJsonFileReturnsNullForMissingFile(): void {
        echo "测试 netwatch_read_json_file() 缺失文件:\n";

        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'missing.json';
        $this->assert(netwatch_read_json_file($file) === null, '缺失文件返回 null');

        echo "\n";
    }

    private function testReadJsonFileReturnsNullForInvalidJson(): void {
        echo "测试 netwatch_read_json_file() 非法 JSON:\n";

        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'invalid.json';
        file_put_contents($file, '{invalid json');

        $this->assert(netwatch_read_json_file($file) === null, '非法 JSON 返回 null');

        echo "\n";
    }

    private function testWriteAndReadJsonFile(): void {
        echo "测试 netwatch_write_json_file() / netwatch_read_json_file():\n";

        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'status.json';
        $payload = [
            'status' => 'running',
            'processed' => 3,
            'message' => '测试中',
        ];

        $this->assert(netwatch_write_json_file($file, $payload), '写入 JSON 文件成功');
        $this->assert(file_exists($file), '目标 JSON 文件已创建');
        $this->assert(!file_exists($file . '.tmp'), '临时文件已被重命名清理');

        $decoded = netwatch_read_json_file($file);
        $this->assert(is_array($decoded), '读取结果为数组');
        $this->assert($decoded === $payload, '读取结果与写入内容一致');

        echo "\n";
    }

    private function testIsBatchFinishedRecognizesTerminalStatuses(): void {
        echo "测试 netwatch_is_batch_finished() 终态:\n";

        foreach (['completed', 'cancelled', 'error'] as $status) {
            $file = $this->tempDir . DIRECTORY_SEPARATOR . 'terminal_' . $status . '.json';
            netwatch_write_json_file($file, ['status' => $status]);
            $this->assert(netwatch_is_batch_finished($file), "状态 {$status} 被识别为已结束");
        }

        echo "\n";
    }

    private function testIsBatchFinishedRejectsNonTerminalStatuses(): void {
        echo "测试 netwatch_is_batch_finished() 非终态:\n";

        $runningFile = $this->tempDir . DIRECTORY_SEPARATOR . 'running.json';
        netwatch_write_json_file($runningFile, ['status' => 'running']);
        $this->assert(!netwatch_is_batch_finished($runningFile), 'running 不是结束状态');

        $missingStatusFile = $this->tempDir . DIRECTORY_SEPARATOR . 'missing_status.json';
        netwatch_write_json_file($missingStatusFile, ['processed' => 10]);
        $this->assert(!netwatch_is_batch_finished($missingStatusFile), '缺少 status 字段时不是结束状态');

        $missingFile = $this->tempDir . DIRECTORY_SEPARATOR . 'not_exists.json';
        $this->assert(!netwatch_is_batch_finished($missingFile), '缺失状态文件时不是结束状态');

        echo "\n";
    }

    private function testIsCancelledDirDetectsCancelFlag(): void {
        echo "测试 netwatch_is_cancelled_dir():\n";

        $cancelDir = $this->tempDir . DIRECTORY_SEPARATOR . 'cancel_case';
        if (!is_dir($cancelDir)) {
            mkdir($cancelDir, 0755, true);
        }

        $this->assert(!netwatch_is_cancelled_dir($cancelDir), '无 cancel.flag 时未取消');

        file_put_contents($cancelDir . DIRECTORY_SEPARATOR . 'cancel.flag', '1');
        $this->assert(netwatch_is_cancelled_dir($cancelDir), '存在 cancel.flag 时已取消');

        echo "\n";
    }

    private function cleanup(): void {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $items = scandir($this->tempDir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $this->tempDir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $nestedItems = scandir($path);
                if ($nestedItems !== false) {
                    foreach ($nestedItems as $nestedItem) {
                        if ($nestedItem === '.' || $nestedItem === '..') {
                            continue;
                        }
                        @unlink($path . DIRECTORY_SEPARATOR . $nestedItem);
                    }
                }
                @rmdir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($this->tempDir);
    }

    private function assert(bool $condition, string $message): void {
        if ($condition) {
            echo "  ✓ {$message}\n";
            $this->passed++;
        } else {
            echo "  ✗ {$message}\n";
            $this->failed++;
            $this->errors[] = $message;
        }
    }

    private function printResults(): void {
        echo "=== 测试结果 ===\n";
        echo "通过: {$this->passed}\n";
        echo "失败: {$this->failed}\n";

        if (!empty($this->errors)) {
            echo "\n失败的测试:\n";
            foreach ($this->errors as $error) {
                echo "  - {$error}\n";
            }
        }

        echo "\n";
    }
}

if (php_sapi_name() === 'cli') {
    $test = new ParallelStatusUtilsTest();
    exit($test->run() ? 0 : 1);
}
