<?php
/**
 * Logger 类单元测试
 */

require_once dirname(__DIR__) . '/bootstrap.php';

class LoggerTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];
    
    public function run(): void {
        echo "=== Logger 单元测试 ===\n\n";
        
        $this->testLoggerCreation();
        $this->testRequestId();
        $this->testLogLevels();
        $this->testJsonFormat();
        
        $this->printResults();
    }
    
    private function testLoggerCreation(): void {
        echo "测试 Logger 创建:\n";
        
        $logger = new Logger();
        $this->assert($logger instanceof Logger, 'Logger实例创建成功');
        
        $jsonLogger = new Logger(true);
        $this->assert($jsonLogger instanceof Logger, 'JSON格式Logger创建成功');
        
        echo "\n";
    }
    
    private function testRequestId(): void {
        echo "测试 RequestId:\n";
        
        $requestId = Logger::getRequestId();
        $this->assert(!empty($requestId), 'RequestId不为空');
        $this->assert(strlen($requestId) === 8, 'RequestId长度为8');
        
        // 同一请求中RequestId应该相同
        $requestId2 = Logger::getRequestId();
        $this->assert($requestId === $requestId2, '同一请求RequestId一致');
        
        echo "\n";
    }
    
    private function testLogLevels(): void {
        echo "测试日志级别:\n";
        
        $logger = new Logger();
        
        // 这些方法应该不抛出异常
        try {
            $logger->debug('Debug message');
            $logger->info('Info message');
            $logger->warning('Warning message');
            $logger->error('Error message');
            $this->assert(true, '所有日志级别方法正常工作');
        } catch (Exception $e) {
            $this->assert(false, '日志方法抛出异常: ' . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testJsonFormat(): void {
        echo "测试JSON格式日志:\n";
        
        $logger = new Logger(true);
        
        // 测试带上下文的日志
        try {
            $logger->info('Test message', ['key' => 'value', 'number' => 123]);
            $this->assert(true, 'JSON格式日志带上下文正常工作');
        } catch (Exception $e) {
            $this->assert(false, 'JSON格式日志抛出异常: ' . $e->getMessage());
        }
        
        echo "\n";
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

// 运行测试
if (php_sapi_name() === 'cli') {
    $test = new LoggerTest();
    $test->run();
}
