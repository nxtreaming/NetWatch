<?php

class JsonResponse {
    public static function send(array $payload, int $statusCode = 200): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public static function success($data = null, string $message = '', int $statusCode = 200, array $extra = []): void {
        self::send(array_merge([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ], $extra), $statusCode);
    }

    public static function error(string $error, string $message = '', int $statusCode = 400, array $extra = []): void {
        self::send(array_merge([
            'success' => false,
            'error' => $error,
            'message' => $message,
            'timestamp' => time(),
        ], $extra), $statusCode);
    }
}
