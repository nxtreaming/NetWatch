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
        
        return $this->updateRealtimeTrafficWithData($data);
    }
    
    /**
     * 使用已获取的 API 数据更新实时流量数据
     * @param array $data API 返回的数据
     * @return bool
     */
    public function updateRealtimeTrafficWithData($data) {
        if (!$data || !is_array($data)) {
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
        
        // 同时保存快照数据用于图表展示
        $snapshotResult = $this->db->saveTrafficSnapshot($rxBytes, $txBytes);
        
        if ($result) {
            $this->logger->info("实时流量数据已更新: 端口={$port}, RX={$rxGB}GB, TX={$txGB}GB, 已用(RX+TX)={$totalUsedGB}GB, 使用率={$usagePercentage}%");
            if ($snapshotResult) {
                $this->logger->info("流量快照已保存");
            }
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
        
        return $this->updateDailyStatsWithData($data);
    }
    
    /**
     * 使用已获取的 API 数据更新每日流量统计
     * @param array $data API 返回的数据
     * @return bool
     */
    public function updateDailyStatsWithData($data) {
        if (!$data || !is_array($data)) {
            return false;
        }
        
        $today = date('Y-m-d');
        
        // 解析API数据
        $rxBytes = isset($data['rx']) ? floatval($data['rx']) : 0;
        $txBytes = isset($data['tx']) ? floatval($data['tx']) : 0;
        
        // 转换为GB
        $rxGB = $rxBytes / (1024 * 1024 * 1024);
        $txGB = $txBytes / (1024 * 1024 * 1024);
        $totalUsedGB = $txGB + $rxGB;
        
        // 从配置获取总流量限制
        $totalBandwidthGB = defined('TRAFFIC_TOTAL_LIMIT_GB') ? TRAFFIC_TOTAL_LIMIT_GB : 0;
        $remainingBandwidthGB = $totalBandwidthGB > 0 ? max(0, $totalBandwidthGB - $totalUsedGB) : 0;
        
        // 计算今日使用量：使用快照增量累加，避免流量重置导致的数据丢失
        $dailyUsage = $this->calculateDailyUsageFromSnapshots($today);
        
        // 检测是否是每月1日（跨月）
        $isFirstDayOfMonth = (date('d') === '01');
        
        // 如果快照数据不足，回退到简单计算
        if ($dailyUsage === false) {
            // 如果是每月1日，不与上月数据做差值
            if ($isFirstDayOfMonth) {
                $dailyUsage = $totalUsedGB;
                $this->logger->info("跨月（每月1日）：使用当天累计值({$totalUsedGB}GB)作为当日使用量");
            } else {
                $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
                $yesterdayData = $this->db->getDailyTrafficStats($yesterday);
                
                if ($yesterdayData) {
                    $dailyUsage = $totalUsedGB - $yesterdayData['used_bandwidth'];
                    
                    // 检测流量重置或API数据异常：如果计算结果为负
                    if ($dailyUsage < 0) {
                        $difference = abs($dailyUsage);
                        
                        // 只有差值 >= 100GB 才认为是真正的流量重置
                        if ($difference >= 100) {
                            $dailyUsage = $totalUsedGB;
                            $this->logger->warning("检测到流量重置（差值{$difference}GB >= 100GB），当日使用量可能不准确（丢失跨日流量）");
                        } else {
                            // 差值 < 100GB，可能是API数据异常，跳过本次更新
                            $this->logger->warning("API数据异常：今天累计值({$totalUsedGB}GB)比昨天({$yesterdayData['used_bandwidth']}GB)少{$difference}GB，但不足100GB阈值，跳过本次更新");
                            return false;
                        }
                    }
                } else {
                    // 没有昨天的数据，使用今天的累计值作为当日使用量
                    $dailyUsage = $totalUsedGB;
                }
            }
        }
        
        // 计算要存储的"已用流量"值
        // 关键：每月1日存储当月累计值，而不是原始累计值
        $displayUsedGB = $totalUsedGB;
        $trafficReset = false;
        $skipUpdate = false;  // 是否跳过本次更新（API数据异常时）
        
        if ($isFirstDayOfMonth) {
            // 每月1日：存储当月累计值（= 当日使用量）
            $displayUsedGB = $dailyUsage;
            $this->logger->info("跨月（每月1日）：存储当月累计值 {$dailyUsage}GB（而不是原始累计值 {$totalUsedGB}GB）");
        } elseif ($dailyUsage > $totalUsedGB) {
            // 当日使用量包含了重置前的流量，使用当日使用量作为显示值
            $displayUsedGB = $dailyUsage;
            $trafficReset = true;
            $this->logger->info("检测到流量重置，使用当日使用量({$dailyUsage}GB)作为已用流量显示值");
            
            // 只有在真正发生流量重置时才回溯更新昨天的数据
            // 判断条件：今天的第一个快照值必须明显小于昨天的累计值（说明发生了重置）
            $todaySnapshots = $this->db->getTrafficSnapshotsByDate($today);
            if (!empty($todaySnapshots)) {
                $firstSnapshot = $todaySnapshots[0];
                $todayFirstValue = $firstSnapshot['total_bytes'] / (1024 * 1024 * 1024);
                
                $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
                $yesterdayData = $this->db->getDailyTrafficStats($yesterday);
                
                // 关键判断：只有当今天首个快照值比昨天累计值少100GB以上时，才认为是真正的流量重置
                // 如果差值小于100GB，可能只是API数据波动或异常，不应该修正昨天的数据
                $difference = $yesterdayData['used_bandwidth'] - $todayFirstValue;
                if ($yesterdayData && $difference >= 100) {
                    // 真正的流量重置：今天的值比昨天少100GB以上，说明流量被重置了
                    // 这种情况下，需要将 23:55 ~ 00:00 这段跨日流量累加到昨天的数据中
                    $this->logger->info("检测到真正的流量重置：昨天{$yesterdayData['used_bandwidth']}GB，今天首个快照{$todayFirstValue}GB，差值{$difference}GB >= 100GB");
                    
                    $yesterdayLastSnapshot = $this->db->getLastSnapshotOfDay($yesterday);
                    if ($yesterdayLastSnapshot) {
                        $yesterday23_55Value = $yesterdayLastSnapshot['total_bytes'] / (1024 * 1024 * 1024);
                        
                        // 计算 23:55 ~ 00:00 的跨日流量增量
                        // 因为发生了重置，今天00:00的值是新周期的起点
                        // 所以跨日流量 = 今天00:00的值（重置后的新起点）
                        $crossDayTraffic = $todayFirstValue;
                        
                        // 昨天的最终累计值 = 昨天23:55的值 + 跨日流量
                        $resetBeforeValue = $yesterday23_55Value + $crossDayTraffic;
                        
                        $this->logger->info("流量重置回溯计算：昨天23:55={$yesterday23_55Value}GB + 跨日流量(23:55~00:00)={$crossDayTraffic}GB = {$resetBeforeValue}GB");
                        
                        // 重新计算昨天的当日使用量（包含跨日流量）
                        $yesterdayDailyUsage = $this->calculateDailyUsageFromSnapshots($yesterday);
                        
                        // 如果能计算出当日使用量，则加上跨日流量
                        if ($yesterdayDailyUsage !== false) {
                            $yesterdayDailyUsage += $crossDayTraffic;
                        } else {
                            // 如果无法计算，使用重置前的累计值作为当日使用量
                            $yesterdayDailyUsage = $resetBeforeValue;
                        }
                        
                        // 更新昨天的已用流量和当日使用量
                        $this->db->saveDailyTrafficStats(
                            $yesterday,
                            $yesterdayData['total_bandwidth'],
                            $resetBeforeValue,
                            $yesterdayData['remaining_bandwidth'],
                            $yesterdayDailyUsage
                        );
                        $this->logger->info("流量重置：回溯更新 {$yesterday} 的数据 - 已用流量: {$yesterdayData['used_bandwidth']}GB → {$resetBeforeValue}GB, 当日使用: {$yesterdayData['daily_usage']}GB → {$yesterdayDailyUsage}GB");
                    }
                } else {
                    if ($yesterdayData) {
                        $this->logger->warning("检测到 dailyUsage > totalUsedGB，但差值({$difference}GB)小于100GB阈值，判断为API数据异常而非真正的流量重置，跳过回溯更新");
                        
                        // API数据异常：今天的值比昨天小，但差值不足100GB
                        // 这种情况下不应该保存异常数据，跳过本次更新
                        if ($todayFirstValue < $yesterdayData['used_bandwidth']) {
                            $skipUpdate = true;
                            $this->logger->warning("API数据异常：今天累计值({$todayFirstValue}GB)小于昨天({$yesterdayData['used_bandwidth']}GB)，跳过本次数据更新");
                        }
                    } else {
                        $this->logger->warning("检测到 dailyUsage > totalUsedGB，但没有昨天的数据，跳过回溯更新");
                    }
                }
            }
        }
        
        // 保存每日统计（如果检测到API数据异常则跳过）
        if ($skipUpdate) {
            $this->logger->info("由于API数据异常，跳过本次流量统计更新");
            return false;
        }
        
        $result = $this->db->saveDailyTrafficStats(
            $today,
            $totalBandwidthGB,
            $displayUsedGB,
            $remainingBandwidthGB,
            $dailyUsage
        );
        
        if ($result) {
            $this->logger->info("每日流量统计已更新: 日期={$today}, 累计使用(RX+TX)={$totalUsedGB}GB, 今日使用={$dailyUsage}GB");
        }
        
        return $result;
    }
    
    /**
     * 从快照数据计算当日真实流量使用量（增量累加）
     * 这样即使发生流量重置，也能准确计算当日流量
     * @param string $date 日期 (Y-m-d)
     * @return float|false 当日使用量(GB)，如果数据不足返回false
     */
    private function calculateDailyUsageFromSnapshots($date) {
        // 获取当日所有快照
        $snapshots = $this->db->getTrafficSnapshotsByDate($date);
        
        if (empty($snapshots)) {
            return false;
        }
        
        // 检测是否是每月1日（跨月）
        $isFirstDayOfMonth = (date('d', strtotime($date)) === '01');
        
        // 获取前一天最后一个快照（23:55）作为基准点
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $yesterdayLastSnapshot = $this->db->getLastSnapshotOfDay($yesterday);
        
        // 获取次日第一个快照（00:00），用于计算跨日流量
        $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
        $tomorrowSnapshots = $this->db->getTrafficSnapshotsByDate($tomorrow);
        $tomorrowFirstSnapshot = !empty($tomorrowSnapshots) ? $tomorrowSnapshots[0] : null;
        
        $totalDailyUsage = 0;
        
        // 如果是每月1日，不与上月最后一天做差值，直接计算当天的增量
        if ($isFirstDayOfMonth) {
            $this->logger->info("检测到跨月：{$date} 是每月1日，不与上月数据做差值计算");
            // 从第一个快照开始计算当天的增量
            $startIndex = 0;
        } elseif ($yesterdayLastSnapshot && !empty($snapshots)) {
            // 非跨月：如果有前一天的最后快照，计算第一个点的增量
            $firstSnapshot = $snapshots[0];
            $increment = ($firstSnapshot['total_bytes'] - $yesterdayLastSnapshot['total_bytes']) / (1024 * 1024 * 1024);
            
            // 如果增量为负，说明发生了流量重置，只计算第一个快照的值
            if ($increment < 0) {
                $totalDailyUsage += $firstSnapshot['total_bytes'] / (1024 * 1024 * 1024);
            } else {
                $totalDailyUsage += $increment;
            }
            
            // 从第二个快照开始计算增量
            $startIndex = 1;
        } else {
            // 没有前一天的数据，从第一个快照开始
            $startIndex = 0;
        }
        
        // 计算当日各快照之间的增量
        for ($i = $startIndex; $i < count($snapshots); $i++) {
            if ($i > 0) {
                $increment = ($snapshots[$i]['total_bytes'] - $snapshots[$i-1]['total_bytes']) / (1024 * 1024 * 1024);
                
                // 如果增量为负，说明发生了流量重置
                if ($increment < 0) {
                    // 只累加当前快照的值（重置后的新流量）
                    $totalDailyUsage += $snapshots[$i]['total_bytes'] / (1024 * 1024 * 1024);
                } else {
                    $totalDailyUsage += $increment;
                }
            } elseif ($i === 0 && $startIndex === 0) {
                // 第一个快照且没有前一天数据，直接使用其值
                $totalDailyUsage += $snapshots[$i]['total_bytes'] / (1024 * 1024 * 1024);
            }
        }
        
        // 计算跨日流量：当日23:55 → 次日00:00
        if ($tomorrowFirstSnapshot && !empty($snapshots)) {
            $lastSnapshot = $snapshots[count($snapshots) - 1];
            $crossDayIncrement = ($tomorrowFirstSnapshot['total_bytes'] - $lastSnapshot['total_bytes']) / (1024 * 1024 * 1024);
            
            // 如果增量为正，说明是正常的跨日流量增量
            if ($crossDayIncrement > 0) {
                $totalDailyUsage += $crossDayIncrement;
                $this->logger->info("计算跨日流量: {$date} 23:55 → {$tomorrow} 00:00 = {$crossDayIncrement}GB");
            }
            // 如果增量为负，说明次日发生了流量重置，不计入当日
        }
        
        return $totalDailyUsage;
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
     * 获取指定日期的流量统计
     * @param string $date 日期 (Y-m-d)
     * @return array|null
     */
    public function getStatsForDate($date) {
        return $this->db->getDailyTrafficStats($date);
    }
    
    /**
     * 获取指定日期的最后一个快照
     * @param string $date 日期 (Y-m-d)
     * @return array|null
     */
    public function getLastSnapshotOfDay($date) {
        return $this->db->getLastSnapshotOfDay($date);
    }
    
    /**
     * 获取指定日期前后N天的流量统计
     * @param string $centerDate 中心日期 (Y-m-d)
     * @param int $daysBefore 前面天数
     * @param int $daysAfter 后面天数
     * @return array
     */
    public function getStatsAroundDate($centerDate, $daysBefore = 7, $daysAfter = 7) {
        $startDate = date('Y-m-d', strtotime($centerDate . " -{$daysBefore} days"));
        $endDate = date('Y-m-d', strtotime($centerDate . " +{$daysAfter} days"));
        return $this->db->getTrafficStatsByDateRange($startDate, $endDate);
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
    
    /**
     * 获取今日流量快照用于图表展示
     */
    public function getTodaySnapshots() {
        return $this->db->getTodayTrafficSnapshots();
    }
    
    /**
     * 获取指定日期的流量快照用于图表展示
     */
    public function getSnapshotsByDate($date) {
        return $this->db->getTrafficSnapshotsByDate($date);
    }
}
