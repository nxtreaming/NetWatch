<?php
/**
 * Validator 类单元测试
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once PROJECT_ROOT . '/includes/Validator.php';

class ValidatorTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];
    
    public function run(): void {
        echo "=== Validator 单元测试 ===\n\n";
        
        $this->testValidateIp();
        $this->testValidatePort();
        $this->testValidateProxyType();
        $this->testValidateStatus();
        $this->testValidateEmail();
        $this->testValidateUrl();
        $this->testParseProxyString();
        $this->testSanitizeString();
        
        $this->printResults();
    }
    
    private function testValidateIp(): void {
        echo "测试 validateIp():\n";
        
        // 有效IP
        $this->assert(Validator::validateIp('192.168.1.1'), 'validateIp: 有效IPv4');
        $this->assert(Validator::validateIp('10.0.0.1'), 'validateIp: 有效私有IP');
        $this->assert(Validator::validateIp('8.8.8.8'), 'validateIp: 有效公共IP');
        
        // 无效IP
        $this->assert(!Validator::validateIp('256.1.1.1'), 'validateIp: 无效IP (256)');
        $this->assert(!Validator::validateIp('abc.def.ghi.jkl'), 'validateIp: 无效IP (字母)');
        $this->assert(!Validator::validateIp(''), 'validateIp: 空字符串');
        
        echo "\n";
    }
    
    private function testValidatePort(): void {
        echo "测试 validatePort():\n";
        
        // 有效端口
        $this->assert(Validator::validatePort(80), 'validatePort: 端口80');
        $this->assert(Validator::validatePort(443), 'validatePort: 端口443');
        $this->assert(Validator::validatePort(8080), 'validatePort: 端口8080');
        $this->assert(Validator::validatePort(1), 'validatePort: 最小端口1');
        $this->assert(Validator::validatePort(65535), 'validatePort: 最大端口65535');
        
        // 无效端口
        $this->assert(!Validator::validatePort(0), 'validatePort: 端口0');
        $this->assert(!Validator::validatePort(-1), 'validatePort: 负数端口');
        $this->assert(!Validator::validatePort(65536), 'validatePort: 超出范围');
        
        echo "\n";
    }
    
    private function testValidateProxyType(): void {
        echo "测试 validateProxyType():\n";
        
        $this->assert(Validator::validateProxyType('http'), 'validateProxyType: http');
        $this->assert(Validator::validateProxyType('https'), 'validateProxyType: https');
        $this->assert(Validator::validateProxyType('socks5'), 'validateProxyType: socks5');
        $this->assert(!Validator::validateProxyType('invalid'), 'validateProxyType: 无效类型');
        
        echo "\n";
    }
    
    private function testValidateStatus(): void {
        echo "测试 validateStatus():\n";
        
        $this->assert(Validator::validateStatus('online'), 'validateStatus: online');
        $this->assert(Validator::validateStatus('offline'), 'validateStatus: offline');
        $this->assert(Validator::validateStatus('unknown'), 'validateStatus: unknown');
        $this->assert(!Validator::validateStatus('invalid'), 'validateStatus: 无效状态');
        
        echo "\n";
    }
    
    private function testValidateEmail(): void {
        echo "测试 validateEmail():\n";
        
        $this->assert(Validator::validateEmail('test@example.com'), 'validateEmail: 有效邮箱');
        $this->assert(Validator::validateEmail('user.name@domain.org'), 'validateEmail: 带点的邮箱');
        $this->assert(!Validator::validateEmail('invalid'), 'validateEmail: 无效邮箱');
        $this->assert(!Validator::validateEmail('@example.com'), 'validateEmail: 缺少用户名');
        
        echo "\n";
    }
    
    private function testValidateUrl(): void {
        echo "测试 validateUrl():\n";
        
        $this->assert(Validator::validateUrl('http://example.com'), 'validateUrl: HTTP URL');
        $this->assert(Validator::validateUrl('https://example.com'), 'validateUrl: HTTPS URL');
        $this->assert(Validator::validateUrl('https://example.com/path?query=1'), 'validateUrl: 带路径和查询');
        $this->assert(!Validator::validateUrl('ftp://example.com'), 'validateUrl: FTP URL (不允许)');
        $this->assert(!Validator::validateUrl('invalid'), 'validateUrl: 无效URL');
        
        echo "\n";
    }
    
    private function testParseProxyString(): void {
        echo "测试 parseProxyString():\n";
        
        // IP:Port 格式
        $result = Validator::parseProxyString('192.168.1.1:8080');
        $this->assert($result !== null && $result['ip'] === '192.168.1.1' && $result['port'] === 8080, 
            'parseProxyString: IP:Port格式');
        
        // IP:Port:User:Pass 格式
        $result = Validator::parseProxyString('192.168.1.1:8080:user:pass');
        $this->assert($result !== null && $result['username'] === 'user' && $result['password'] === 'pass', 
            'parseProxyString: IP:Port:User:Pass格式');
        
        // 无效格式
        $result = Validator::parseProxyString('invalid');
        $this->assert($result === null, 'parseProxyString: 无效格式');
        
        echo "\n";
    }
    
    private function testSanitizeString(): void {
        echo "测试 sanitizeString():\n";
        
        $this->assert(Validator::sanitizeString('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 
            'sanitizeString: XSS防护');
        $this->assert(Validator::sanitizeString('  test  ') === 'test', 
            'sanitizeString: 去除空格');
        $this->assert(Validator::sanitizeString('normal text') === 'normal text', 
            'sanitizeString: 正常文本');
        
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
    $test = new ValidatorTest();
    $test->run();
}
