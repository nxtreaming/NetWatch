<?php
/**
 * 日志记录类（增强版）
 * 支持JSON格式、请求ID追踪、上下文信息
 */

class Logger {
    private string $logFile;
    private string $jsonLogFile;
    private static ?string $requestId = null;
    private bool $jsonFormat = false;
    
    public function __construct(bool $jsonFormat = false) {
        if (!is_dir(LOG_PATH)) {
            mkdir(LOG_PATH, 0755, true);
        }
        
        $this->logFile = LOG_PATH . 'netwatch_' . date('Y-m-d') . '.log';
        $this->jsonLogFile = LOG_PATH . 'netwatch_' . date('Y-m-d') . '.json.log';
        $this->jsonFormat = $jsonFormat;
        
        // 生成请求ID
        if (self::$requestId === null) {
            self::$requestId = $this->generateRequestId();
        }
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
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
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
        file_put_contents($this->jsonLogFile, $jsonEntry, FILE_APPEND | LOCK_EX);
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
