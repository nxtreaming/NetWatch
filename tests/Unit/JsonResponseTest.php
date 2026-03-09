<?php
/**
 * JsonResponse 类单元测试
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once PROJECT_ROOT . '/includes/JsonResponse.php';

class JsonResponseTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    public function run(): bool {
        echo "=== JsonResponse 单元测试 ===\n\n";

        $this->testSendOutputsJsonPayload();
        $this->testSendSetsStatusCode();
        $this->testSuccessResponseStructure();
        $this->testSuccessExtraOverridesDefaults();
        $this->testErrorResponseStructure();
        $this->testErrorExtraOverridesDefaults();

        $this->printResults();

        return $this->failed === 0;
    }

    private function testSendOutputsJsonPayload(): void {
        echo "测试 send():\n";

        $payload = [
            'success' => true,
            'message' => '测试消息',
            'count' => 2,
        ];

        $output = $this->captureOutput(function () use ($payload): void {
            JsonResponse::send($payload);
        });

        $decoded = json_decode($output, true);
        $this->assert(is_array($decoded), 'send: 输出为有效 JSON');
        $this->assert($decoded === $payload, 'send: 输出 payload 与输入一致');

        echo "\n";
    }

    private function testSendSetsStatusCode(): void {
        echo "测试 send() 状态码:\n";

        http_response_code(200);
        $this->captureOutput(function (): void {
            JsonResponse::send(['ok' => true], 202);
        });

        $this->assert(http_response_code() === 202, 'send: 设置 HTTP 状态码');

        echo "\n";
    }

    private function testSuccessResponseStructure(): void {
        echo "测试 success():\n";

        $before = time();
        $output = $this->captureOutput(function (): void {
            JsonResponse::success(['id' => 123], '创建成功', 201, ['meta' => 'value']);
        });
        $after = time();

        $decoded = json_decode($output, true);
        $this->assert(is_array($decoded), 'success: 输出为有效 JSON');
        $this->assert(($decoded['success'] ?? null) === true, 'success: success=true');
        $this->assert(($decoded['message'] ?? null) === '创建成功', 'success: message 正确');
        $this->assert(($decoded['data']['id'] ?? null) === 123, 'success: data 正确');
        $this->assert(($decoded['meta'] ?? null) === 'value', 'success: extra 字段已合并');
        $this->assert(isset($decoded['timestamp']) && is_int($decoded['timestamp']), 'success: 包含 timestamp');
        $this->assert(($decoded['timestamp'] ?? 0) >= $before && ($decoded['timestamp'] ?? 0) <= $after, 'success: timestamp 在合理范围内');
        $this->assert(http_response_code() === 201, 'success: 设置 HTTP 状态码');

        echo "\n";
    }

    private function testSuccessExtraOverridesDefaults(): void {
        echo "测试 success() 覆盖默认字段:\n";

        $output = $this->captureOutput(function (): void {
            JsonResponse::success(['id' => 1], '原始消息', 200, [
                'message' => '覆盖消息',
                'data' => ['id' => 999],
                'success' => false,
            ]);
        });

        $decoded = json_decode($output, true);
        $this->assert(($decoded['message'] ?? null) === '覆盖消息', 'success: extra 可覆盖 message');
        $this->assert(($decoded['data']['id'] ?? null) === 999, 'success: extra 可覆盖 data');
        $this->assert(($decoded['success'] ?? null) === false, 'success: extra 可覆盖 success 标记');

        echo "\n";
    }

    private function testErrorResponseStructure(): void {
        echo "测试 error():\n";

        $before = time();
        $output = $this->captureOutput(function (): void {
            JsonResponse::error('validation_failed', '参数错误', 422, ['field' => 'email']);
        });
        $after = time();

        $decoded = json_decode($output, true);
        $this->assert(is_array($decoded), 'error: 输出为有效 JSON');
        $this->assert(($decoded['success'] ?? null) === false, 'error: success=false');
        $this->assert(($decoded['error'] ?? null) === 'validation_failed', 'error: error 正确');
        $this->assert(($decoded['message'] ?? null) === '参数错误', 'error: message 正确');
        $this->assert(($decoded['field'] ?? null) === 'email', 'error: extra 字段已合并');
        $this->assert(isset($decoded['timestamp']) && is_int($decoded['timestamp']), 'error: 包含 timestamp');
        $this->assert(($decoded['timestamp'] ?? 0) >= $before && ($decoded['timestamp'] ?? 0) <= $after, 'error: timestamp 在合理范围内');
        $this->assert(http_response_code() === 422, 'error: 设置 HTTP 状态码');

        echo "\n";
    }

    private function testErrorExtraOverridesDefaults(): void {
        echo "测试 error() 覆盖默认字段:\n";

        $output = $this->captureOutput(function (): void {
            JsonResponse::error('original_error', '原始消息', 400, [
                'error' => 'override_error',
                'message' => '覆盖后的消息',
                'success' => true,
            ]);
        });

        $decoded = json_decode($output, true);
        $this->assert(($decoded['error'] ?? null) === 'override_error', 'error: extra 可覆盖 error');
        $this->assert(($decoded['message'] ?? null) === '覆盖后的消息', 'error: extra 可覆盖 message');
        $this->assert(($decoded['success'] ?? null) === true, 'error: extra 可覆盖 success 标记');

        echo "\n";
    }

    private function captureOutput(callable $callback): string {
        ob_start();
        try {
            $callback();
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
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
    $test = new JsonResponseTest();
    exit($test->run() ? 0 : 1);
}
