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
    
    public function run(): bool {
        echo "=== Validator 单元测试 ===\n\n";
        
        $this->testIp();
        $this->testPort();
        $this->testProxyType();
        $this->testProxyStatus();
        $this->testEmail();
        $this->testUrl();
        $this->testParseProxyString();
        $this->testEscapeHtml();
        
        $this->printResults();

        return $this->failed === 0;
    }
    
    private function testIp(): void {
        echo "测试 ip():\n";
        
        // 有效IP
        $this->assert(Validator::ip('192.168.1.1'), 'ip: 有效IPv4');
        $this->assert(Validator::ip('10.0.0.1'), 'ip: 有效私有IP');
        $this->assert(Validator::ip('8.8.8.8'), 'ip: 有效公共IP');
        
        // 无效IP
        $this->assert(!Validator::ip('256.1.1.1'), 'ip: 无效IP (256)');
        $this->assert(!Validator::ip('abc.def.ghi.jkl'), 'ip: 无效IP (字母)');
        $this->assert(!Validator::ip(''), 'ip: 空字符串');
        
        echo "\n";
    }
    
    private function testPort(): void {
        echo "测试 port():\n";
        
        // 有效端口
        $this->assert(Validator::port(80), 'port: 端口80');
        $this->assert(Validator::port(443), 'port: 端口443');
        $this->assert(Validator::port(8080), 'port: 端口8080');
        $this->assert(Validator::port(1), 'port: 最小端口1');
        $this->assert(Validator::port(65535), 'port: 最大端口65535');
        
        // 无效端口
        $this->assert(!Validator::port(0), 'port: 端口0');
        $this->assert(!Validator::port(-1), 'port: 负数端口');
        $this->assert(!Validator::port(65536), 'port: 超出范围');
        
        echo "\n";
    }
    
    private function testProxyType(): void {
        echo "测试 proxyType():\n";
        
        $this->assert(Validator::proxyType('http'), 'proxyType: http');
        $this->assert(Validator::proxyType('https'), 'proxyType: https');
        $this->assert(Validator::proxyType('socks5'), 'proxyType: socks5');
        $this->assert(!Validator::proxyType('invalid'), 'proxyType: 无效类型');
        
        echo "\n";
    }
    
    private function testProxyStatus(): void {
        echo "测试 proxyStatus():\n";
        
        $this->assert(Validator::proxyStatus('online'), 'proxyStatus: online');
        $this->assert(Validator::proxyStatus('offline'), 'proxyStatus: offline');
        $this->assert(Validator::proxyStatus('unknown'), 'proxyStatus: unknown');
        $this->assert(!Validator::proxyStatus('invalid'), 'proxyStatus: 无效状态');
        
        echo "\n";
    }
    
    private function testEmail(): void {
        echo "测试 email():\n";
        
        $this->assert(Validator::email('test@example.com'), 'email: 有效邮箱');
        $this->assert(Validator::email('user.name@domain.org'), 'email: 带点的邮箱');
        $this->assert(!Validator::email('invalid'), 'email: 无效邮箱');
        $this->assert(!Validator::email('@example.com'), 'email: 缺少用户名');
        
        echo "\n";
    }
    
    private function testUrl(): void {
        echo "测试 url():\n";
        
        $this->assert(Validator::url('http://example.com'), 'url: HTTP URL');
        $this->assert(Validator::url('https://example.com'), 'url: HTTPS URL');
        $this->assert(Validator::url('https://example.com/path?query=1'), 'url: 带路径和查询');
        $this->assert(Validator::url('ftp://example.com'), 'url: FTP URL');
        $this->assert(!Validator::url('invalid'), 'url: 无效URL');
        
        echo "\n";
    }
    
    private function testParseProxyString(): void {
        echo "测试 parseProxyString():\n";
        
        // IP:Port 格式
        $result = Validator::parseProxyString('192.168.1.1:8080');
        $this->assert($result !== false && $result['ip'] === '192.168.1.1' && $result['port'] === 8080, 
            'parseProxyString: IP:Port格式');
        
        // IP:Port:Type:User:Pass 格式
        $result = Validator::parseProxyString('192.168.1.1:8080:socks5:user:pass');
        $this->assert($result !== false && $result['type'] === 'socks5' && $result['username'] === 'user' && $result['password'] === 'pass', 
            'parseProxyString: IP:Port:Type:User:Pass格式');
        
        // 无效格式
        $result = Validator::parseProxyString('invalid');
        $this->assert($result === false, 'parseProxyString: 无效格式');
        
        echo "\n";
    }
    
    private function testEscapeHtml(): void {
        echo "测试 escapeHtml():\n";
        
        $this->assert(Validator::escapeHtml('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 
            'escapeHtml: XSS防护');
        $this->assert(Validator::escapeHtml('  test  ') === '  test  ', 
            'escapeHtml: 保留空格');
        $this->assert(Validator::escapeHtml('normal text') === 'normal text', 
            'escapeHtml: 正常文本');
        
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
    exit($test->run() ? 0 : 1);
}
