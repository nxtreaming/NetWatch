<?php
/**
 * RateLimiter 类单元测试
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once PROJECT_ROOT . '/includes/RateLimiter.php';

class RateLimiterTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];
    private string $storageDir;

    public function __construct() {
        $this->storageDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'netwatch_ratelimiter_tests_' . uniqid('', true);
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    public function run(): bool {
        echo "=== RateLimiter 单元测试 ===\n\n";

        $this->testAttemptAndRemaining();
        $this->testIsLimitedAfterThreshold();
        $this->testRetryAfterForLimitedKey();
        $this->testClearRemovesLimitRecord();
        $this->testGetClientIpWithoutTrustedProxyUsesRemoteAddr();
        $this->testGetClientIpUsesForwardedHeaderForTrustedProxy();
        $this->testGetClientIpFallsBackWhenProxyNotTrusted();
        $this->testGetClientIpUsesLeftmostForwardedAddress();
        $this->testRateLimitPresetsCreateInstances();

        $this->printResults();
        $this->cleanup();

        return $this->failed === 0;
    }

    private function testAttemptAndRemaining(): void {
        echo "测试 attempt() / remaining():\n";

        $limiter = $this->makeLimiter(2, 60);
        $key = 'attempt_remaining';

        $this->assert($limiter->attempt($key), '第一次请求允许通过');
        $this->assert($limiter->remaining($key) === 1, '第一次请求后剩余次数为 1');

        $this->assert($limiter->attempt($key), '第二次请求允许通过');
        $this->assert($limiter->remaining($key) === 0, '达到上限后剩余次数为 0');

        $this->assert(!$limiter->attempt($key), '超过上限后请求被拒绝');
        $this->assert($limiter->remaining($key) === 0, '超限后剩余次数仍为 0');

        echo "\n";
    }

    private function testIsLimitedAfterThreshold(): void {
        echo "测试 isLimited():\n";

        $limiter = $this->makeLimiter(2, 60);
        $key = 'limited_check';

        $this->assert(!$limiter->isLimited($key), '初始状态未限流');
        $limiter->attempt($key);
        $this->assert(!$limiter->isLimited($key), '未达到阈值前未限流');
        $limiter->attempt($key);
        $this->assert($limiter->isLimited($key), '达到阈值后被限流');

        echo "\n";
    }

    private function testRetryAfterForLimitedKey(): void {
        echo "测试 retryAfter():\n";

        $limiter = $this->makeLimiter(1, 60);
        $key = 'retry_after';

        $this->assert($limiter->retryAfter($key) === 0, '无记录时 retryAfter 为 0');
        $limiter->attempt($key);

        $retryAfter = $limiter->retryAfter($key);
        $this->assert($retryAfter > 0, '达到限制后 retryAfter 大于 0');
        $this->assert($retryAfter <= 60, 'retryAfter 不超过窗口大小');

        echo "\n";
    }

    private function testClearRemovesLimitRecord(): void {
        echo "测试 clear():\n";

        $limiter = $this->makeLimiter(1, 60);
        $key = 'clear_key';

        $limiter->attempt($key);
        $this->assert($limiter->isLimited($key), 'clear 前处于限流状态');

        $limiter->clear($key);
        $this->assert(!$limiter->isLimited($key), 'clear 后不再限流');
        $this->assert($limiter->remaining($key) === 1, 'clear 后剩余次数恢复');

        echo "\n";
    }

    private function testGetClientIpWithoutTrustedProxyUsesRemoteAddr(): void {
        echo "测试 getClientIp() 直接使用 REMOTE_ADDR:\n";

        $this->withServerState([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ], function (): void {
            $this->assert(RateLimiter::getClientIp(['127.0.0.1/32']) === '203.0.113.10', '未命中可信代理时使用 REMOTE_ADDR');
        });

        $this->withServerState([
            'REMOTE_ADDR' => 'invalid-ip',
        ], function (): void {
            $this->assert(RateLimiter::getClientIp(['127.0.0.1/32']) === '0.0.0.0', '非法 REMOTE_ADDR 时回退到 0.0.0.0');
        });

        echo "\n";
    }

    private function testGetClientIpUsesForwardedHeaderForTrustedProxy(): void {
        echo "测试 getClientIp() 信任代理头:\n";

        $this->withServerState([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ], function (): void {
            $clientIp = RateLimiter::getClientIp(['127.0.0.1/32']);
            $this->assert($clientIp === '198.51.100.20', '可信代理场景使用 X-Forwarded-For');
        });

        $this->withServerState([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.30',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ], function (): void {
            $clientIp = RateLimiter::getClientIp(['127.0.0.1']);
            $this->assert($clientIp === '198.51.100.30', '可信代理场景优先使用 CF 头');
        });

        echo "\n";
    }

    private function testGetClientIpFallsBackWhenProxyNotTrusted(): void {
        echo "测试 getClientIp() 非可信代理回退:\n";

        $this->withServerState([
            'REMOTE_ADDR' => '192.0.2.55',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
            'HTTP_X_REAL_IP' => '198.51.100.30',
        ], function (): void {
            $clientIp = RateLimiter::getClientIp(['127.0.0.1/32']);
            $this->assert($clientIp === '192.0.2.55', '非可信代理时忽略转发头');
        });

        echo "\n";
    }

    private function testGetClientIpUsesLeftmostForwardedAddress(): void {
        echo "测试 getClientIp() 取最左侧转发地址:\n";

        $this->withServerState([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 198.51.100.21',
        ], function (): void {
            $clientIp = RateLimiter::getClientIp(['127.0.0.1/32']);
            $this->assert($clientIp === '198.51.100.20', '多级代理时取最左侧客户端 IP');
        });

        echo "\n";
    }

    private function testRateLimitPresetsCreateInstances(): void {
        echo "测试 RateLimitPresets:\n";

        $this->assert(RateLimitPresets::api() instanceof RateLimiter, 'api 预设返回 RateLimiter 实例');
        $this->assert(RateLimitPresets::login() instanceof RateLimiter, 'login 预设返回 RateLimiter 实例');
        $this->assert(RateLimitPresets::proxyCheck() instanceof RateLimiter, 'proxyCheck 预设返回 RateLimiter 实例');
        $this->assert(RateLimitPresets::strict() instanceof RateLimiter, 'strict 预设返回 RateLimiter 实例');

        echo "\n";
    }

    private function makeLimiter(int $maxRequests, int $windowSeconds): RateLimiter {
        return new RateLimiter($maxRequests, $windowSeconds, $this->storageDir);
    }

    private function withServerState(array $server, callable $callback): void {
        $originalServer = $_SERVER;
        $_SERVER = $server;

        try {
            $callback();
        } finally {
            $_SERVER = $originalServer;
        }
    }

    private function cleanup(): void {
        if (!is_dir($this->storageDir)) {
            return;
        }

        $items = scandir($this->storageDir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                @unlink($this->storageDir . DIRECTORY_SEPARATOR . $item);
            }
        }

        @rmdir($this->storageDir);
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
    $test = new RateLimiterTest();
    exit($test->run() ? 0 : 1);
}
