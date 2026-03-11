<?php
/**
 * Auth 类单元测试
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once PROJECT_ROOT . '/auth.php';

class AuthTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    public function run(): bool {
        echo "=== Auth 单元测试 ===\n\n";

        $this->testGetRedirectUrlReturnsSafeRelativePath();
        $this->testGetRedirectUrlRejectsProtocolRelativeUrl();
        $this->testGetRedirectUrlRejectsAbsoluteUrlWithScheme();
        $this->testGetRedirectUrlRejectsNonRootRelativePath();
        $this->testGetRedirectUrlRejectsControlCharacters();
        $this->testGetRedirectUrlClearsSessionValueAfterRead();

        $this->printResults();
        $this->cleanupSession();

        return $this->failed === 0;
    }

    private function testGetRedirectUrlReturnsSafeRelativePath(): void {
        echo "测试 getRedirectUrl() 保留安全站内路径:\n";

        $this->withSessionRedirect('/dashboard?page=1', function (): void {
            $this->assert(Auth::getRedirectUrl() === '/dashboard?page=1', '安全站内路径原样返回');
        });

        echo "\n";
    }

    private function testGetRedirectUrlRejectsProtocolRelativeUrl(): void {
        echo "测试 getRedirectUrl() 拒绝协议相对 URL:\n";

        $this->withSessionRedirect('//evil.example.com/phish', function (): void {
            $this->assert(Auth::getRedirectUrl() === '/', '协议相对 URL 回退到根路径');
        });

        $this->withSessionRedirect('\\\\evil.example.com\\share', function (): void {
            $this->assert(Auth::getRedirectUrl() === '/', '反斜杠协议相对路径回退到根路径');
        });

        echo "\n";
    }

    private function testGetRedirectUrlRejectsAbsoluteUrlWithScheme(): void {
        echo "测试 getRedirectUrl() 拒绝带协议的绝对 URL:\n";

        $this->withSessionRedirect('https://evil.example.com/phish', function (): void {
            $this->assert(Auth::getRedirectUrl() === '/', 'https 绝对 URL 回退到根路径');
        });

        $this->withSessionRedirect('javascript:alert(1)', function (): void {
            $this->assert(Auth::getRedirectUrl() === '/', '脚本协议回退到根路径');
        });

        echo "\n";
    }

    private function testGetRedirectUrlRejectsNonRootRelativePath(): void {
        echo "测试 getRedirectUrl() 拒绝非根相对路径:\n";

        $this->withSessionRedirect('dashboard', function (): void {
            $this->assert(Auth::getRedirectUrl() === '/', '非根相对路径回退到根路径');
        });

        echo "\n";
    }

    private function testGetRedirectUrlRejectsControlCharacters(): void {
        echo "测试 getRedirectUrl() 拒绝控制字符:\n";

        $this->withSessionRedirect("/dashboard\nLocation: https://evil.example.com", function (): void {
            $this->assert(Auth::getRedirectUrl() === '/', '包含控制字符的路径回退到根路径');
        });

        echo "\n";
    }

    private function testGetRedirectUrlClearsSessionValueAfterRead(): void {
        echo "测试 getRedirectUrl() 读取后清理 session:\n";

        $this->withSessionRedirect('/settings', function (): void {
            $first = Auth::getRedirectUrl();
            $second = Auth::getRedirectUrl();

            $this->assert($first === '/settings', '第一次读取返回原始路径');
            $this->assert($second === '/', '第二次读取因 session 已清理而回退到根路径');
        });

        echo "\n";
    }

    private function withSessionRedirect(string $redirectUrl, callable $callback): void {
        Auth::startSession();
        $_SESSION['redirect_after_login'] = $redirectUrl;

        try {
            $callback();
        } finally {
            unset($_SESSION['redirect_after_login']);
        }
    }

    private function cleanupSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }
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
    $test = new AuthTest();
    exit($test->run() ? 0 : 1);
}
