<?php
/**
 * functions.php 工具函数单元测试
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once PROJECT_ROOT . '/includes/functions.php';

class FunctionsTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    public function run(): bool {
        echo "=== functions.php 单元测试 ===\n\n";

        $this->testAjaxModeRequest();
        $this->testRequestExpectsJsonResponse();
        $this->testIsValidAjaxRequest();

        $this->printResults();

        return $this->failed === 0;
    }

    private function testAjaxModeRequest(): void {
        echo "测试 netwatch_is_ajax_mode_request():\n";

        $this->withRequestState([
            '_GET' => ['ajax' => '1'],
        ], function (): void {
            $this->assert(netwatch_is_ajax_mode_request(), 'ajax=1 被识别为 AJAX 模式');
        });

        $this->withRequestState([
            '_GET' => ['ajax' => 'true'],
        ], function (): void {
            $this->assert(netwatch_is_ajax_mode_request(), 'ajax=true 被识别为 AJAX 模式');
        });

        $this->withRequestState([
            '_GET' => ['ajax' => '0'],
        ], function (): void {
            $this->assert(!netwatch_is_ajax_mode_request(), 'ajax=0 不被识别为 AJAX 模式');
        });

        $this->withRequestState([
            '_GET' => [],
        ], function (): void {
            $this->assert(!netwatch_is_ajax_mode_request(), '缺少 ajax 参数时不是 AJAX 模式');
        });

        echo "\n";
    }

    private function testRequestExpectsJsonResponse(): void {
        echo "测试 netwatch_request_expects_json_response():\n";

        $this->withRequestState([
            '_SERVER' => [
                'HTTP_ACCEPT' => 'application/json',
            ],
            '_GET' => [],
        ], function (): void {
            $this->assert(netwatch_request_expects_json_response(), 'Accept: application/json 时期待 JSON 响应');
        });

        $this->withRequestState([
            '_SERVER' => [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ],
            '_GET' => [],
        ], function (): void {
            $this->assert(netwatch_request_expects_json_response(), 'XMLHttpRequest 请求期待 JSON 响应');
        });

        $this->withRequestState([
            '_SERVER' => [
                'HTTP_ACCEPT' => 'text/html,application/json',
            ],
            '_GET' => [],
        ], function (): void {
            $this->assert(!netwatch_request_expects_json_response(), '同时接受 HTML 时不强制判定为 JSON 响应');
        });

        echo "\n";
    }

    private function testIsValidAjaxRequest(): void {
        echo "测试 isValidAjaxRequest():\n";

        $this->withRequestState([
            '_GET' => ['ajax' => '1'],
            '_SERVER' => [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ],
        ], function (): void {
            $this->assert(isValidAjaxRequest(), 'ajax=1 且 XMLHttpRequest 时为有效 AJAX 请求');
        });

        $this->withRequestState([
            '_GET' => ['ajax' => '1'],
            '_SERVER' => [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_SEC_FETCH_MODE' => 'cors',
                'HTTP_HOST' => 'example.com',
            ],
        ], function (): void {
            $this->assert(isValidAjaxRequest(), 'ajax=1 且程序化 fetch + JSON Accept 时为有效 AJAX 请求');
        });

        $this->withRequestState([
            '_GET' => ['ajax' => '1'],
            '_SERVER' => [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_REFERER' => 'https://example.com/dashboard',
                'HTTP_HOST' => 'example.com',
            ],
        ], function (): void {
            $this->assert(isValidAjaxRequest(), 'ajax=1 且同源 Referer + JSON Accept 时为有效 AJAX 请求');
        });

        $this->withRequestState([
            '_GET' => ['ajax' => '1'],
            '_SERVER' => [
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_HOST' => 'example.com',
            ],
        ], function (): void {
            $this->assert(!isValidAjaxRequest(), '缺少可靠 AJAX 信号时请求无效');
        });

        $this->withRequestState([
            '_GET' => [],
            '_SERVER' => [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ],
        ], function (): void {
            $this->assert(!isValidAjaxRequest(), '缺少 ajax=1 时请求无效');
        });

        echo "\n";
    }

    private function withRequestState(array $state, callable $callback): void {
        $originalGet = $_GET;
        $originalServer = $_SERVER;

        $_GET = $state['_GET'] ?? [];
        $_SERVER = array_merge([
            'HTTP_HOST' => 'localhost',
        ], $state['_SERVER'] ?? []);

        try {
            $callback();
        } finally {
            $_GET = $originalGet;
            $_SERVER = $originalServer;
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
    $test = new FunctionsTest();
    exit($test->run() ? 0 : 1);
}
