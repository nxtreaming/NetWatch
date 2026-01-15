<?php
/**
 * 日志记录类（增强版）
 * 支持JSON格式、请求ID追踪、上下文信息
 */

class Logger {
    private string $logDir;
    private string $logFile;
    private string $jsonLogFile;
    private static ?string $requestId = null;
    private bool $jsonFormat = false;
    
    public function __construct(bool $jsonFormat = false) {
        $this->logDir = $this->resolveLogDir();
        
        $this->logFile = $this->logDir . 'netwatch_' . date('Y-m-d') . '.log';
        $this->jsonLogFile = $this->logDir . 'netwatch_' . date('Y-m-d') . '.json.log';
        $this->jsonFormat = $jsonFormat;
        
        // 生成请求ID
        if (self::$requestId === null) {
            self::$requestId = $this->generateRequestId();
        }
    }

    private function resolveLogDir(): string {
        $dir = defined('LOG_PATH') ? (string)LOG_PATH : '';
        if ($dir !== '') {
            $dir = rtrim($dir, "\\/ ") . DIRECTORY_SEPARATOR;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }

        $fallback = rtrim(sys_get_temp_dir(), "\\/ ") . DIRECTORY_SEPARATOR . 'netwatch_logs' . DIRECTORY_SEPARATOR;
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0755, true);
        }
        if (is_dir($fallback) && is_writable($fallback)) {
            return $fallback;
        }

        return '';
    }

    private function safeAppend(string $filePath, string $content): bool {
        if ($filePath === '') {
            return false;
        }

        $result = @file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX);
        return $result !== false;
    }
    
    /**
     * 生成唯一请求ID
     */
    private function generateRequestId(): string {
        return substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }
    
    /**
     * 获取当前请求ID
     */
    public static function getRequestId(): string {
        if (self::$requestId === null) {
            self::$requestId = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
        }
        return self::$requestId;
    }
    
    /**
     * 写入日志（文本格式）
     */
    private function writeLog(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $requestId = self::getRequestId();
        
        // 文本格式日志
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logEntry = "[$timestamp] [$requestId] [$level] $message$contextStr" . PHP_EOL;
        if (!$this->safeAppend($this->logFile, $logEntry)) {
            error_log(rtrim($logEntry));
        }
        
        // JSON格式日志（可选）
        if ($this->jsonFormat) {
            $this->writeJsonLog($level, $message, $context);
        }
    }
    
    /**
     * 写入JSON格式日志
     */
    private function writeJsonLog(string $level, string $message, array $context = []): void {
        $logData = [
            'timestamp' => date('c'),
            'level' => $level,
            'request_id' => self::getRequestId(),
            'message' => $message,
            'context' => $context,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'cli',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'cli'
        ];
        
        $jsonEntry = json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        if (!$this->safeAppend($this->jsonLogFile, $jsonEntry)) {
            error_log(rtrim($jsonEntry));
        }
    }
    
    /**
     * 启用/禁用JSON格式日志
     */
    public function setJsonFormat(bool $enabled): void {
        $this->jsonFormat = $enabled;
    }
    
    public function debug($message, array $context = []): void {
        if (LOG_LEVEL === 'DEBUG') {
            $this->writeLog('DEBUG', $message, $context);
        }
    }
    
    public function info($message, array $context = []): void {
        if (in_array(LOG_LEVEL, ['DEBUG', 'INFO'])) {
            $this->writeLog('INFO', $message, $context);
        }
    }
    
    public function warning($message, array $context = []): void {
        if (in_array(LOG_LEVEL, ['DEBUG', 'INFO', 'WARNING'])) {
            $this->writeLog('WARNING', $message, $context);
        }
    }
    
    public function error($message, array $context = []): void {
        $this->writeLog('ERROR', $message, $context);
    }
    
    public function getRecentLogs($lines = 100) {
        if (!file_exists($this->logFile)) {
            return [];
        }
        
        $file = new SplFileObject($this->logFile);
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        
        $startLine = max(0, $totalLines - $lines);
        $logs = [];
        
        $file->seek($startLine);
        while (!$file->eof()) {
            $line = trim($file->current());
            if (!empty($line)) {
                $logs[] = $line;
            }
            $file->next();
        }
        
        return $logs;
    }
}
