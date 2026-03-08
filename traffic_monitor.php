<?php
/**
 * 流量监控类
 * 负责从API获取流量数据并保存到数据库
 */

require_once 'config.php';
require_once 'database.php';
require_once 'logger.php';
require_once 'includes/Config.php';

class TrafficMonitor {
    private Database $db;
    private Logger $logger;
    private string $apiUrl;
    private string $proxyHost;
    private string $proxyUsername;
    private string $proxyPassword;
    private int $proxyPort;
    
    public function __construct(?Database $db = null, ?Logger $logger = null) {
        ensure_valid_config();
        $this->db = $db ?? new Database();
        $this->db->initializeSchema();
        $this->logger = $logger ?? new Logger();
        
        $this->apiUrl = (string) config('traffic.api_url', '');
        $this->proxyHost = (string) config('traffic.proxy_host', '');
        $this->proxyUsername = (string) config('traffic.proxy_username', '');
        $this->proxyPassword = (string) config('traffic.proxy_password', '');
        $this->proxyPort = (int) config('traffic.proxy_port', 8080);
    }
    
    /**
     * 从API获取流量数据
     * @return array|false
     */
    public function fetchTrafficData(): array|false {
        if (empty($this->apiUrl)) {
            $this->logger->error('traffic_api_url_missing', [
                'api_url' => $this->apiUrl,
            ]);
            return false;
        }
        
        $maxRetries = (int) config('monitoring.max_retries', 3);
        $retryDelayUs = (int) config('monitoring.retry_delay_us', 200000);
        $lastError = '';
        $verifySsl = (bool) config('security.verify_ssl', true);
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
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
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
                
                // 如果配置了代理认证，使用HTTP代理
                if (!empty($this->proxyHost) && !empty($this->proxyUsername) && !empty($this->proxyPassword)) {
                    $proxyUrl = "http://{$this->proxyUsername}:{$this->proxyPassword}@{$this->proxyHost}:{$this->proxyPort}";
                    curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                    
                    if ($attempt === 1) {
                        $this->logger->info('traffic_api_proxy_enabled', [
                            'proxy_host' => $this->proxyHost,
                            'proxy_port' => $this->proxyPort,
                        ]);
                    }
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
                
                $this->logger->info('traffic_data_fetch_succeeded', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'used_retry' => $attempt > 1,
                ]);
                return $data;
                
            } catch (Exception $e) {
                if ($ch !== null) {
                    curl_close($ch);
                    $ch = null;
                }
                $lastError = $e->getMessage();
                
                if ($attempt < $maxRetries) {
                    $this->logger->warning('traffic_data_fetch_retrying', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'exception' => $lastError,
                    ]);
                    usleep($retryDelayUs * $attempt); // 递增延迟
                }
            }
        }
        
        $this->logger->error('traffic_data_fetch_failed', [
            'max_retries' => $maxRetries,
            'exception' => $lastError,
        ]);
        return false;
    }
    
    /**
     * 更新实时流量数据
     * @return bool
     */
    public function updateRealtimeTraffic(): bool {
        $data = $this->fetchTrafficData();
        
        if ($data === false) {
            return false;
        }
        
        return $this->updateRealtimeTrafficWithData($data);
    }
    
    /**
     * 使用已获取的 API 数据更新实时流量数据
     * @param array|null $data API 返回的数据
     * @return bool
     */
    public function updateRealtimeTrafficWithData(?array $data): bool {
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
        $totalBandwidthGB = (float) config('traffic.total_limit_gb', 0);
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
            $this->logger->info('traffic_realtime_stats_updated', [
                'port' => $port,
                'rx_gb' => $rxGB,
                'tx_gb' => $txGB,
                'total_used_gb' => $totalUsedGB,
                'usage_percentage' => $usagePercentage,
                'total_bandwidth_gb' => $totalBandwidthGB,
            ]);
            if ($snapshotResult) {
                $this->logger->info('traffic_snapshot_saved', [
                    'rx_bytes' => $rxBytes,
                    'tx_bytes' => $txBytes,
                    'port' => $port,
                ]);
            }
        }
        
        return $result;
    }
    
    /**
     * 更新每日流量统计
     * @return bool
     */
    public function updateDailyStats(): bool {
        $data = $this->fetchTrafficData();
        
        if ($data === false) {
            return false;
        }
        
        return $this->updateDailyStatsWithData($data);
    }
    
    /**
     * 使用已获取的 API 数据更新每日流量统计
     * @param array|null $data API 返回的数据
     * @return bool
     */
    public function updateDailyStatsWithData(?array $data): bool {
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
        $totalBandwidthGB = (float) config('traffic.total_limit_gb', 0);
        $remainingBandwidthGB = $totalBandwidthGB > 0 ? max(0, $totalBandwidthGB - $totalUsedGB) : 0;
        
        // 计算今日使用量：使用快照增量累加，避免流量重置导致的数据丢失
        $dailyUsage = $this->calculateDailyUsageFromSnapshots($today);
        
        // 如果快照数据不足，回退到使用昨天最后快照与当前API值的差值
        if ($dailyUsage === false) {
            $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
            $yesterdayLastSnapshot = $this->db->getLastSnapshotOfDay($yesterday);
            
            if ($yesterdayLastSnapshot) {
                // 使用同源数据：API累计值 - 昨天最后快照的累计值
                $yesterdayTotal = $yesterdayLastSnapshot['total_bytes'] / (1024 * 1024 * 1024);
                $dailyUsage = $totalUsedGB - $yesterdayTotal;
                
                // 检测流量重置或API数据异常：如果计算结果为负
                if ($dailyUsage < 0) {
                    $difference = abs($dailyUsage);
                    
                    // 只有差值超过阈值才认为是真正的流量重置
                    $resetThreshold = (float) config('traffic.reset_threshold_gb', 100);
                    if ($difference >= $resetThreshold) {
                        $dailyUsage = $totalUsedGB;
                        $this->logger->warning('traffic_reset_detected_from_negative_daily_usage', [
                            'difference_gb' => $difference,
                            'reset_threshold_gb' => $resetThreshold,
                            'total_used_gb' => $totalUsedGB,
                        ]);
                    } else {
                        // 差值 < 100GB，可能是API数据异常，跳过本次更新
                        $this->logger->warning('traffic_api_anomaly_below_reset_threshold', [
                            'today_total_used_gb' => $totalUsedGB,
                            'yesterday_snapshot_total_gb' => $yesterdayTotal,
                            'difference_gb' => $difference,
                            'reset_threshold_gb' => $resetThreshold,
                        ]);
                        return false;
                    }
                }
            } else {
                // 没有昨天的快照数据，使用今天的累计值作为当日使用量
                $dailyUsage = $totalUsedGB;
            }
        }
        
        // 计算要存储的"已用流量"值（当月累计）
        $displayUsedGB = $totalUsedGB;
        $trafficReset = false;
        $skipUpdate = false;
        
        // 核心原则：今日 used_bandwidth = 昨日 used_bandwidth + 今日 daily_usage
        // 每月1日或无昨日数据时，当月累计 = 当日使用量
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        $yesterdayData = $this->db->getDailyTrafficStats($yesterday);
        $isFirstDayOfMonth = (date('d') === '01');
        
        if (!$isFirstDayOfMonth && $yesterdayData && isset($yesterdayData['used_bandwidth'])) {
            $displayUsedGB = $yesterdayData['used_bandwidth'] + $dailyUsage;
            $this->logger->info('traffic_monthly_usage_accumulated_from_yesterday', [
                'display_used_gb' => $displayUsedGB,
                'yesterday_used_bandwidth_gb' => $yesterdayData['used_bandwidth'],
                'daily_usage_gb' => $dailyUsage,
                'today' => $today,
            ]);
        } else {
            // 每月1日或无昨日数据：当月累计 = 当日使用量
            $displayUsedGB = $dailyUsage;
            $this->logger->info('traffic_monthly_usage_initialized_from_daily', [
                'display_used_gb' => $displayUsedGB,
                'today' => $today,
                'is_first_day_of_month' => $isFirstDayOfMonth,
                'has_yesterday_data' => (bool) $yesterdayData,
            ]);
        }
        
        if ($dailyUsage > $totalUsedGB) {
            // 流量重置（月初自动或手动重置）：新计费周期从0开始
            // used_bandwidth 和 daily_usage 都只算重置后的流量
            $this->logger->info('traffic_billing_cycle_reset_detected', [
                'daily_usage_gb' => $dailyUsage,
                'total_used_gb' => $totalUsedGB,
                'today' => $today,
            ]);
            $dailyUsage = $totalUsedGB;
            $displayUsedGB = $totalUsedGB;
            $remainingBandwidthGB = $totalBandwidthGB > 0 ? max(0, $totalBandwidthGB - $displayUsedGB) : 0;
            $trafficReset = true;
            
            // 只有在真正发生流量重置时才回溯更新昨天的数据
            // 判断条件：今天的第一个快照值必须明显小于昨天的累计值（说明发生了重置）
            $todaySnapshots = $this->db->getTrafficSnapshotsByDate($today);
            if (!empty($todaySnapshots)) {
                $firstSnapshot = $todaySnapshots[0];
                $todayFirstValue = $firstSnapshot['total_bytes'] / (1024 * 1024 * 1024);
                
                $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
                $yesterdayData = $this->db->getDailyTrafficStats($yesterday);
                
                // 关键判断：只有当今天首个快照值比昨天累计值少超过阈值时，才认为是真正的流量重置
                $resetThreshold = (float) config('traffic.reset_threshold_gb', 100);
                $difference = $yesterdayData['used_bandwidth'] - $todayFirstValue;
                if ($yesterdayData && $difference >= $resetThreshold) {
                    // 真正的流量重置：今天的值比昨天少100GB以上，说明流量被重置了
                    // 这种情况下，需要将 23:55 ~ 00:00 这段跨日流量累加到昨天的数据中
                    $this->logger->info('traffic_true_reset_confirmed', [
                        'yesterday_used_bandwidth_gb' => $yesterdayData['used_bandwidth'],
                        'today_first_value_gb' => $todayFirstValue,
                        'difference_gb' => $difference,
                        'reset_threshold_gb' => $resetThreshold,
                        'yesterday' => $yesterday,
                        'today' => $today,
                    ]);
                    
                    $yesterdayLastSnapshot = $this->db->getLastSnapshotOfDay($yesterday);
                    if ($yesterdayLastSnapshot) {
                        $yesterday23_55Value = $yesterdayLastSnapshot['total_bytes'] / (1024 * 1024 * 1024);
                        
                        // 计算 23:55 ~ 00:00 的跨日流量增量
                        // 因为发生了重置，今天00:00的值是新周期的起点
                        // 所以跨日流量 = 今天00:00的值（重置后的新起点）
                        $crossDayTraffic = $todayFirstValue;
                        
                        // 昨天的最终累计值 = 昨天23:55的值 + 跨日流量
                        $resetBeforeValue = $yesterday23_55Value + $crossDayTraffic;
                        
                        $this->logger->info('traffic_reset_backfill_calculated', [
                            'yesterday_23_55_gb' => $yesterday23_55Value,
                            'cross_day_traffic_gb' => $crossDayTraffic,
                            'reset_before_value_gb' => $resetBeforeValue,
                            'yesterday' => $yesterday,
                        ]);
                        
                        // 重新计算昨天的当日使用量
                        // 注意：calculateDailyUsageFromSnapshots 已包含跨日增量，无需再额外加
                        $yesterdayDailyUsage = $this->calculateDailyUsageFromSnapshots($yesterday);
                        
                        if ($yesterdayDailyUsage === false) {
                            // 如果无法计算，使用重置前的累计值作为当日使用量
                            $yesterdayDailyUsage = $resetBeforeValue;
                        }
                        
                        // 更新昨天的已用流量和当日使用量
                        $yesterdayRemaining = $yesterdayData['total_bandwidth'] > 0
                            ? max(0, $yesterdayData['total_bandwidth'] - $resetBeforeValue)
                            : 0;
                        $this->db->saveDailyTrafficStats(
                            $yesterday,
                            $yesterdayData['total_bandwidth'],
                            $resetBeforeValue,
                            $yesterdayRemaining,
                            $yesterdayDailyUsage
                        );
                        $this->logger->info('traffic_reset_backfill_saved', [
                            'yesterday' => $yesterday,
                            'previous_used_bandwidth_gb' => $yesterdayData['used_bandwidth'],
                            'updated_used_bandwidth_gb' => $resetBeforeValue,
                            'previous_daily_usage_gb' => $yesterdayData['daily_usage'],
                            'updated_daily_usage_gb' => $yesterdayDailyUsage,
                        ]);
                    }
                } else {
                    if ($yesterdayData) {
                        $this->logger->warning('traffic_reset_backfill_skipped_due_to_small_difference', [
                            'difference_gb' => $difference,
                            'reset_threshold_gb' => $resetThreshold,
                            'today' => $today,
                            'yesterday' => $yesterday,
                        ]);
                        
                        // API数据异常：今天的值比昨天小，但差值不足100GB
                        // 这种情况下不应该保存异常数据，跳过本次更新
                        if ($todayFirstValue < $yesterdayData['used_bandwidth']) {
                            $skipUpdate = true;
                            $this->logger->warning('traffic_update_skipped_due_to_today_less_than_yesterday', [
                                'today_first_value_gb' => $todayFirstValue,
                                'yesterday_used_bandwidth_gb' => $yesterdayData['used_bandwidth'],
                                'today' => $today,
                                'yesterday' => $yesterday,
                            ]);
                        }
                    } else {
                        $this->logger->warning('traffic_reset_backfill_skipped_missing_yesterday_data', [
                            'today' => $today,
                            'daily_usage_gb' => $dailyUsage,
                            'total_used_gb' => $totalUsedGB,
                        ]);
                    }
                }
            }
        }
        
        // 保存每日统计（如果检测到API数据异常则跳过）
        if ($skipUpdate) {
            $this->logger->info('traffic_daily_stats_update_skipped', [
                'today' => $today,
                'reason' => 'api_data_anomaly',
            ]);
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
            $this->logger->info('traffic_daily_stats_updated', [
                'date' => $today,
                'total_used_gb' => $totalUsedGB,
                'daily_usage_gb' => $dailyUsage,
                'display_used_gb' => $displayUsedGB,
                'remaining_bandwidth_gb' => $remainingBandwidthGB,
                'traffic_reset' => $trafficReset,
            ]);
        }
        
        return $result;
    }
    
    /**
     * 从快照数据计算当日真实流量使用量（增量累加）
     * 这样即使发生流量重置，也能准确计算当日流量
     * @param string $date 日期 (Y-m-d)
     * @return float|false 当日使用量(GB)，如果数据不足返回false
     */
    private function calculateDailyUsageFromSnapshots(string $date): float|false {
        // 获取当日所有快照
        $snapshots = $this->db->getTrafficSnapshotsByDate($date);
        
        if (empty($snapshots)) {
            return false;
        }
        
        // 获取前一天最后一个快照（23:55）作为基准点
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $yesterdayLastSnapshot = $this->db->getLastSnapshotOfDay($yesterday);
        
        $totalDailyUsage = 0;
        
        // 统一逻辑：无论是否跨月，都从昨天最后快照开始计算
        // 跨月时 API 重置会导致负增量，负增量处理逻辑会正确使用当前快照绝对值
        if ($yesterdayLastSnapshot && !empty($snapshots)) {
            $hasMidnightSnapshot = isset($snapshots[0]['snapshot_time']) && $snapshots[0]['snapshot_time'] === '00:00:00';
            if ($hasMidnightSnapshot) {
                // 有 00:00 快照时，先计算跨日增量（昨天最后快照 → 今天 00:00）
                $yesterdayLastTotalGB = $yesterdayLastSnapshot['total_bytes'] / (1024 * 1024 * 1024);
                $todayMidnightTotalGB = $snapshots[0]['total_bytes'] / (1024 * 1024 * 1024);
                $crossDayIncrement = $todayMidnightTotalGB - $yesterdayLastTotalGB;
                $crossDayMaxGB = (float) config('traffic.crossday_max_gb', 50);
                $crossDayHandling = 'zero_increment_skip';
                if ($crossDayIncrement > 0) {
                    $totalDailyUsage += $crossDayIncrement;
                    $crossDayHandling = 'positive_increment_included';
                    if ($crossDayIncrement >= $crossDayMaxGB) {
                        $this->logger->warning('traffic_crossday_increment_large_but_included', [
                            'date' => $date,
                            'cross_day_increment_gb' => $crossDayIncrement,
                            'crossday_max_gb' => $crossDayMaxGB,
                        ]);
                    } else {
                        $this->logger->debug('traffic_crossday_increment_recorded', [
                            'date' => $date,
                            'cross_day_increment_gb' => $crossDayIncrement,
                        ]);
                    }
                } elseif ($crossDayIncrement < 0) {
                    // 负增量说明发生了流量重置，将 00:00 的值作为新起点
                    $totalDailyUsage += $todayMidnightTotalGB;
                    $crossDayHandling = 'negative_increment_reset_use_midnight_absolute';
                }
                $enableCrossDayValidationLog = (bool) config('traffic.crossday_validation_log', false);
                if ($enableCrossDayValidationLog) {
                    $this->logger->info('traffic_crossday_validation', [
                        'date' => $date,
                        'yesterday' => $yesterday,
                        'yesterday_last_gb' => $yesterdayLastTotalGB,
                        'today_midnight_gb' => $todayMidnightTotalGB,
                        'cross_day_increment_gb' => $crossDayIncrement,
                        'handling' => $crossDayHandling,
                    ]);
                }
                // 然后从 00:05 开始累计当天内的增量
                $startIndex = 1;
            } else {
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
            }
        } else {
            // 没有前一天的数据，只计算当天快照间的增量（不使用首个快照的绝对值，因为那是月度累计）
            $this->logger->warning('traffic_missing_yesterday_snapshot', [
                'date' => $date,
            ]);
            $startIndex = 1;
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
            }
        }
        
        return $totalDailyUsage;
    }
    
    /**
     * 获取实时流量数据
     * @return array|null
     */
    public function getRealtimeTraffic(): ?array {
        return $this->db->getRealtimeTraffic();
    }
    
    /**
     * 获取最近N天的流量统计
     * @param int $days 天数
     * @return array
     */
    public function getRecentStats(int $days = 30): array {
        return $this->db->getRecentTrafficStats($days);
    }
    
    /**
     * 获取指定日期的流量统计
     * @param string $date 日期 (Y-m-d)
     * @return array|null
     */
    public function getStatsForDate(string $date): ?array {
        return $this->db->getDailyTrafficStats($date);
    }
    
    /**
     * 获取指定日期的最后一个快照
     * @param string $date 日期 (Y-m-d)
     * @return array|null
     */
    public function getLastSnapshotOfDay(string $date): ?array {
        return $this->db->getLastSnapshotOfDay($date);
    }
    
    /**
     * 获取指定日期的第一个快照
     * @param string $date 日期 (Y-m-d)
     * @return array|null
     */
    public function getFirstSnapshotOfDay(string $date): ?array {
        return $this->db->getFirstSnapshotOfDay($date);
    }
    
    /**
     * 获取指定日期前后N天的流量统计
     * @param string $centerDate 中心日期 (Y-m-d)
     * @param int $daysBefore 前面天数
     * @param int $daysAfter 后面天数
     * @return array
     */
    public function getStatsAroundDate(string $centerDate, int $daysBefore = 7, int $daysAfter = 7): array {
        $startDate = date('Y-m-d', strtotime($centerDate . " -{$daysBefore} days"));
        $endDate = date('Y-m-d', strtotime($centerDate . " +{$daysAfter} days"));
        return $this->db->getTrafficStatsByDateRange($startDate, $endDate);
    }
    
    /**
     * 格式化流量大小（统一使用GB）
     * @param float $gb 流量GB
     * @return string
     */
    public function formatBandwidth(float $gb): string {
        return number_format($gb, 2) . ' GB';
    }
    
    /**
     * 格式化百分比
     * @param float $percentage 百分比
     * @return string
     */
    public function formatPercentage(float $percentage): string {
        return number_format($percentage, 2) . '%';
    }
    
    /**
     * 获取今日流量快照用于图表展示
     * @return array
     */
    public function getTodaySnapshots(): array {
        return $this->db->getTodayTrafficSnapshots();
    }
    
    /**
     * 获取指定日期的流量快照用于图表展示
     * @param string $date 日期
     * @return array
     */
    public function getSnapshotsByDate(string $date): array {
        return $this->db->getTrafficSnapshotsByDate($date);
    }

    /**
     * 构建实时图展示上下文（统一口径）
     *
     * 将「前一日最后快照 -> 当日首个快照」的跨日增量折算到当日首个采样点，
     * 使图表增量总和与当日使用口径一致。
     *
     * @param string $date 查询日期 (Y-m-d)
     * @param array $snapshots 当日快照（按时间升序）
     * @return array
     */
    public function buildSnapshotChartContext(string $date, array $snapshots): array {
        $initialIntervalGb = 0.0;

        if (!empty($snapshots)) {
            $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
            $yesterdayLastSnapshot = $this->db->getLastSnapshotOfDay($yesterday);

            if ($yesterdayLastSnapshot) {
                $firstSnapshot = $snapshots[0];
                $firstTotalGb = floatval($firstSnapshot['total_bytes']) / (1024 * 1024 * 1024);
                $yesterdayLastTotalGb = floatval($yesterdayLastSnapshot['total_bytes']) / (1024 * 1024 * 1024);
                $initialIntervalGb = $firstTotalGb - $yesterdayLastTotalGb;

                // 与日统计口径一致：若出现负增量（重置），使用首个快照绝对值作为新周期起点
                if ($initialIntervalGb < 0) {
                    $initialIntervalGb = $firstTotalGb;
                }

                if ($initialIntervalGb < 0) {
                    $initialIntervalGb = 0.0;
                }
            }
        }

        return [
            'initial_interval_mb' => $initialIntervalGb * 1024,
        ];
    }

    /**
     * 构建当月流量计算上下文（统一口径，供页面和 API 复用）
     * @param array|null $realtimeData 实时流量数据
     * @return array
     */
    public function buildMonthlyTrafficContext(?array $realtimeData): array {
        $totalTrafficRaw = 0.0;
        if (
            isset($realtimeData['rx_bytes'], $realtimeData['tx_bytes']) &&
            ($realtimeData['rx_bytes'] > 0 || $realtimeData['tx_bytes'] > 0)
        ) {
            $rxBytes = floatval($realtimeData['rx_bytes']);
            $txBytes = floatval($realtimeData['tx_bytes']);
            $totalTrafficRaw = ($rxBytes + $txBytes) / (1024 * 1024 * 1024);
        } elseif (isset($realtimeData['used_bandwidth'])) {
            $totalTrafficRaw = floatval($realtimeData['used_bandwidth']);
        }

        $totalTraffic = $totalTrafficRaw;
        $monthlyRxBytes = isset($realtimeData['rx_bytes']) ? floatval($realtimeData['rx_bytes']) : 0.0;
        $monthlyTxBytes = isset($realtimeData['tx_bytes']) ? floatval($realtimeData['tx_bytes']) : 0.0;

        $firstDayOfMonth = date('Y-m-01');
        $lastDayOfPrevMonth = date('Y-m-d', strtotime($firstDayOfMonth . ' -1 day'));
        $prevMonthLastSnapshot = $this->getLastSnapshotOfDay($lastDayOfPrevMonth);

        if ($prevMonthLastSnapshot) {
            $prevRxBytes = floatval($prevMonthLastSnapshot['rx_bytes']);
            $prevTxBytes = floatval($prevMonthLastSnapshot['tx_bytes']);

            $monthlyRx = $monthlyRxBytes - $prevRxBytes;
            if ($monthlyRx >= 0) {
                $monthlyRxBytes = $monthlyRx;
            }

            $monthlyTx = $monthlyTxBytes - $prevTxBytes;
            if ($monthlyTx >= 0) {
                $monthlyTxBytes = $monthlyTx;
            }

            $totalTraffic = ($monthlyRxBytes + $monthlyTxBytes) / (1024 * 1024 * 1024);
        } else {
            $prevMonthLastDayData = $this->getStatsForDate($lastDayOfPrevMonth);
            if ($prevMonthLastDayData && isset($prevMonthLastDayData['used_bandwidth'])) {
                $monthlyUsed = $totalTrafficRaw - floatval($prevMonthLastDayData['used_bandwidth']);
                if ($monthlyUsed >= 0) {
                    $totalTraffic = $monthlyUsed;
                }
            }
        }

        return [
            'total_traffic_raw' => $totalTrafficRaw,
            'total_traffic' => $totalTraffic,
            'monthly_rx_bytes' => $monthlyRxBytes,
            'monthly_tx_bytes' => $monthlyTxBytes,
            'prev_month_last_snapshot' => $prevMonthLastSnapshot,
        ];
    }

    /**
     * 构建今日展示计算上下文（统一口径，供页面和 API 复用）
     * @param float $totalTrafficRaw API 原始累计值（GB）
     * @param float $totalTraffic 当月累计展示值（GB）
     * @param array|null $prevMonthLastSnapshot 上月最后快照
     * @return array
     */
    public function buildTodayDisplayContext(float $totalTrafficRaw, float $totalTraffic, ?array $prevMonthLastSnapshot): array {
        $isFirstDayOfMonth = (date('d') === '01');
        $todayStr = date('Y-m-d');
        $yesterdayStr = date('Y-m-d', strtotime('-1 day'));

        $todayDailyUsage = 0.0;
        $yesterdayUsedBandwidth = 0.0;
        $yesterdayUsedBandwidthForDisplay = 0.0;
        $yesterdayLastTotal = null;
        $todayDailySource = 'snapshot_increment';

        $yesterdayStats = $this->getStatsForDate($yesterdayStr);
        if ($yesterdayStats && isset($yesterdayStats['used_bandwidth'])) {
            $yesterdayUsedBandwidth = floatval($yesterdayStats['used_bandwidth']);
            $yesterdayUsedBandwidthForDisplay = $yesterdayUsedBandwidth;
        }

        $yesterdayLastSnapshot = $this->getLastSnapshotOfDay($yesterdayStr);
        if ($yesterdayLastSnapshot) {
            $yesterdayLastTotal = (
                floatval($yesterdayLastSnapshot['rx_bytes']) + floatval($yesterdayLastSnapshot['tx_bytes'])
            ) / (1024 * 1024 * 1024);
        }

        // 单一标准：累计链路统一使用昨日统计表中的 used_bandwidth 作为基线，
        // 今日日增量使用快照算法。避免“昨日累计基线”在展示层被替换导致表格不闭合。

        $snapshotDailyUsage = $this->calculateDailyUsageFromSnapshots($todayStr);
        $todayUsedBandwidth = $totalTraffic;

        if ($isFirstDayOfMonth) {
            // 月初：优先使用快照增量；仅在快照不可用时回退 total 链路
            if ($snapshotDailyUsage !== false) {
                $todayDailyUsage = max(0.0, $snapshotDailyUsage);
                $todayUsedBandwidth = $todayDailyUsage;
                $todayDailySource = 'first_day_snapshot_daily_only';
            } else {
                $todayDailyUsage = max(0.0, $todayUsedBandwidth);
                $todayUsedBandwidth = $todayDailyUsage;
                $todayDailySource = 'first_day_total_traffic_fallback';
            }
        } else {
            // 非月初：单一数据源策略
            // 快照可用 => 今日使用 = 快照日增量；今日已用 = 昨日已用 + 今日使用
            // 快照不可用 => 回退 total 链路
            if ($snapshotDailyUsage !== false) {
                $todayDailyUsage = max(0.0, $snapshotDailyUsage);
                $todayUsedBandwidth = $yesterdayUsedBandwidthForDisplay + $todayDailyUsage;
                $todayDailySource = 'snapshot_daily_only';
            } else {
                $todayDailyUsage = $todayUsedBandwidth - $yesterdayUsedBandwidthForDisplay;
                if ($todayDailyUsage < 0) {
                    $todayDailyUsage = 0.0;
                }
                $todayUsedBandwidth = $yesterdayUsedBandwidthForDisplay + $todayDailyUsage;
                $todayDailySource = 'total_traffic_fallback';
            }
        }

        if ($todayUsedBandwidth < 0) {
            $todayUsedBandwidth = $totalTraffic;
        }

        $todayDailyUsageForDisplay = $todayDailyUsage;

        $enableDailyStandardLog = defined('TRAFFIC_DAILY_STANDARD_LOG')
            ? (bool) TRAFFIC_DAILY_STANDARD_LOG
            : false;
        if ($enableDailyStandardLog) {
            $this->logger->info(
                "单一口径校验: date={$todayStr}, source={$todayDailySource}, yesterday_used={$yesterdayUsedBandwidthForDisplay}GB, today_daily={$todayDailyUsage}GB, today_used={$todayUsedBandwidth}GB, snapshot_daily=" . (($snapshotDailyUsage !== false) ? $snapshotDailyUsage : 'false') . "GB"
            );
        }

        return [
            'is_first_day_of_month' => $isFirstDayOfMonth,
            'yesterday_date' => $yesterdayStr,
            'today_daily_usage' => $todayDailyUsage,
            'today_used_bandwidth' => $todayUsedBandwidth,
            'yesterday_used_bandwidth' => $yesterdayUsedBandwidth,
            'yesterday_used_bandwidth_for_display' => $yesterdayUsedBandwidthForDisplay,
            'today_daily_usage_for_display' => $todayDailyUsageForDisplay,
        ];
    }

    /**
     * 构建 Proxy-Status 页面/API 的统一展示上下文
     *
     * 目标：从单一入口计算并返回「顶部总量 + 今日行 + RX/TX 详情」所需数据，
     * 避免页面层各自修补导致口径漂移。
     *
     * @param array|null $realtimeData 实时流量数据
     * @return array
     */
    public function buildProxyStatusDisplayContext(?array $realtimeData): array {
        $monthlyContext = $this->buildMonthlyTrafficContext($realtimeData);
        $todayContext = $this->buildTodayDisplayContext(
            $monthlyContext['total_traffic_raw'],
            $monthlyContext['total_traffic'],
            $monthlyContext['prev_month_last_snapshot']
        );

        // 统一展示总量：确保与今日行保持一致，不出现“顶部 < 今日表格”的回退
        $displayMonthlyUsed = max(
            floatval($monthlyContext['total_traffic']),
            floatval($todayContext['today_used_bandwidth'])
        );

        // 统一展示 RX/TX：确保 RX + TX = displayMonthlyUsed
        $monthlyRxBytes = floatval($monthlyContext['monthly_rx_bytes']);
        $monthlyTxBytes = floatval($monthlyContext['monthly_tx_bytes']);
        $displayMonthlyRxBytes = $monthlyRxBytes;
        $displayMonthlyTxBytes = $monthlyTxBytes;

        $rawMonthlyUsed = ($monthlyRxBytes + $monthlyTxBytes) / (1024 * 1024 * 1024);
        if ($displayMonthlyUsed > $rawMonthlyUsed) {
            if ($rawMonthlyUsed > 0) {
                $scale = $displayMonthlyUsed / $rawMonthlyUsed;
                $displayMonthlyRxBytes = $monthlyRxBytes * $scale;
                $displayMonthlyTxBytes = $monthlyTxBytes * $scale;
            } else {
                // 无可用 RX/TX 基线时，退化为总量展示，保证 UI 数学关系成立
                $displayMonthlyRxBytes = 0.0;
                $displayMonthlyTxBytes = $displayMonthlyUsed * 1024 * 1024 * 1024;
            }
        }

        return [
            'monthly_context' => $monthlyContext,
            'today_context' => $todayContext,
            'display_monthly_used' => $displayMonthlyUsed,
            'display_monthly_rx_bytes' => $displayMonthlyRxBytes,
            'display_monthly_tx_bytes' => $displayMonthlyTxBytes,
        ];
    }
}
