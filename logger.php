<?php
/**
 * 日志记录类
 */

class Logger {
    private $logFile;
    
    public function __construct() {
        // 确保日志目录存在
        if (!is_dir(LOG_PATH)) {
            mkdir(LOG_PATH, 0755, true);
        }
        
        $this->logFile = LOG_PATH . 'netwatch_' . date('Y-m-d') . '.log';
    }
    
    private function writeLog($level, $message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    public function debug($message) {
        if (LOG_LEVEL === 'DEBUG') {
            $this->writeLog('DEBUG', $message);
        }
    }
    
    public function info($message) {
        if (in_array(LOG_LEVEL, ['DEBUG', 'INFO'])) {
            $this->writeLog('INFO', $message);
        }
    }
    
    public function warning($message) {
        if (in_array(LOG_LEVEL, ['DEBUG', 'INFO', 'WARNING'])) {
            $this->writeLog('WARNING', $message);
        }
    }
    
    public function error($message) {
        $this->writeLog('ERROR', $message);
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
