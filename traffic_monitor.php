<?php
/**
 * 流量监控类
 * 负责从API获取流量数据并保存到数据库
 */

require_once 'config.php';
require_once 'database.php';
require_once 'logger.php';

class TrafficMonitor {
    private $db;
    private $logger;
    private $apiUrl;
    private $proxyHost;
    private $proxyUsername;
    private $proxyPassword;
    private $proxyPort;
    
    public function __construct() {
        $this->db = new Database();
        $this->logger = new Logger();
        
        // 从配置文件获取API配置
        $this->apiUrl = defined('TRAFFIC_API_URL') ? TRAFFIC_API_URL : '';
        $this->proxyHost = defined('TRAFFIC_API_PROXY_HOST') ? TRAFFIC_API_PROXY_HOST : '';
        $this->proxyUsername = defined('TRAFFIC_API_PROXY_USERNAME') ? TRAFFIC_API_PROXY_USERNAME : '';
        $this->proxyPassword = defined('TRAFFIC_API_PROXY_PASSWORD') ? TRAFFIC_API_PROXY_PASSWORD : '';
        $this->proxyPort = defined('TRAFFIC_API_PROXY_PORT') ? TRAFFIC_API_PROXY_PORT : 8080;
    }
    
    /**
     * 从API获取流量数据
     */
    public function fetchTrafficData() {
        if (empty($this->apiUrl)) {
            $this->logger->error('流量监控API URL未配置');
            return false;
        }
        
        $ch = null;
        try {
            $ch = curl_init();
            if ($ch === false) {
                throw new Exception('curl初始化失败');
            }
            
            // 设置curl选项
            curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            // 如果配置了代理认证，使用HTTP代理
            if (!empty($this->proxyHost) && !empty($this->proxyUsername) && !empty($this->proxyPassword)) {
                $proxyUrl = "http://{$this->proxyUsername}:{$this->proxyPassword}@{$this->proxyHost}:{$this->proxyPort}";
                curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                
                $this->logger->info("使用HTTP代理: {$this->proxyHost}:{$this->proxyPort}");
            }
            
            // 执行请求
            $response = curl_exec($ch);
            
            if ($response === false) {
                $error = curl_error($ch);
                throw new Exception("API请求失败: $error");
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode !== 200) {
                throw new Exception("API返回错误状态码: $httpCode");
            }
            
            curl_close($ch);
            $ch = null;
            
            // 解析JSON响应
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON解析失败: ' . json_last_error_msg());
            }
            
            $this->logger->info('成功获取流量数据');
            return $data;
            
        } catch (Exception $e) {
            if ($ch !== null) {
                curl_close($ch);
                $ch = null;
            }
            $this->logger->error('获取流量数据失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新实时流量数据
     */
    public function updateRealtimeTraffic() {
        $data = $this->fetchTrafficData();
        
        if ($data === false) {
            return false;
        }
        
        // API返回的数据格式：
        // {
        //   "port": 12323,           // 端口号
        //   "rx": 48726511916,       // 接收流量（字节，累计）
        //   "tx": 1785605916694      // 发送流量（字节，累计）
        // }
        
        $port = isset($data['port']) ? intval($data['port']) : 0;
        $rxBytes = isset($data['rx']) ? floatval($data['rx']) : 0;
        $txBytes = isset($data['tx']) ? floatval($data['tx']) : 0;
        
        // 转换为GB（1 GB = 1024^3 bytes）
        $rxGB = $rxBytes / (1024 * 1024 * 1024);
        $txGB = $txBytes / (1024 * 1024 * 1024);
        $totalUsedGB = $txGB + $rxGB;
        
        // 从配置获取总流量限制（如果有的话）
        $totalBandwidthGB = defined('TRAFFIC_TOTAL_LIMIT_GB') ? TRAFFIC_TOTAL_LIMIT_GB : 0;
        $remainingBandwidthGB = $totalBandwidthGB > 0 ? max(0, $totalBandwidthGB - $totalUsedGB) : 0;
        
        // 计算使用百分比
        $usagePercentage = $totalBandwidthGB > 0 ? ($totalUsedGB / $totalBandwidthGB) * 100 : 0;
        
        // 保存到数据库（包含原始字节数）
        $result = $this->db->saveRealtimeTraffic(
            $totalBandwidthGB,
            $totalUsedGB,
            $remainingBandwidthGB,
            $usagePercentage,
            $rxBytes,
            $txBytes,
            $port
        );
        
        if ($result) {
            $this->logger->info("实时流量数据已更新: 端口={$port}, RX={$rxGB}GB, TX={$txGB}GB, 已用(仅TX)={$totalUsedGB}GB, 使用率={$usagePercentage}%");
        }
        
        return $result;
    }
    
    /**
     * 更新每日流量统计
     */
    public function updateDailyStats() {
        $data = $this->fetchTrafficData();
        
        if ($data === false) {
            return false;
        }
        
        $today = date('Y-m-d');
        
        // 解析API数据
        $rxBytes = isset($data['rx']) ? floatval($data['rx']) : 0;
        $txBytes = isset($data['tx']) ? floatval($data['tx']) : 0;
        
        // 转换为GB
        $rxGB = $rxBytes / (1024 * 1024 * 1024);
        $txGB = $txBytes / (1024 * 1024 * 1024);
        // 只计算TX（发送流量）作为已用流量
        $totalUsedGB = $txGB;
        
        // 从配置获取总流量限制
        $totalBandwidthGB = defined('TRAFFIC_TOTAL_LIMIT_GB') ? TRAFFIC_TOTAL_LIMIT_GB : 0;
        $remainingBandwidthGB = $totalBandwidthGB > 0 ? max(0, $totalBandwidthGB - $totalUsedGB) : 0;
        
        // 计算今日使用量（当前累计 - 昨天累计）
        $dailyUsage = $this->db->calculateDailyUsage($today);
        
        // 保存每日统计
        $result = $this->db->saveDailyTrafficStats(
            $today,
            $totalBandwidthGB,
            $totalUsedGB,
            $remainingBandwidthGB,
            $dailyUsage
        );
        
        if ($result) {
            $this->logger->info("每日流量统计已更新: 日期={$today}, 累计使用(仅TX)={$totalUsedGB}GB, 今日使用={$dailyUsage}GB");
        }
        
        return $result;
    }
    
    /**
     * 获取实时流量数据
     */
    public function getRealtimeTraffic() {
        return $this->db->getRealtimeTraffic();
    }
    
    /**
     * 获取最近N天的流量统计
     */
    public function getRecentStats($days = 30) {
        return $this->db->getRecentTrafficStats($days);
    }
    
    /**
     * 格式化流量大小（统一使用GB）
     */
    public function formatBandwidth($gb) {
        return number_format($gb, 2) . ' GB';
    }
    
    /**
     * 格式化百分比
     */
    public function formatPercentage($percentage) {
        return number_format($percentage, 2) . '%';
    }
}
