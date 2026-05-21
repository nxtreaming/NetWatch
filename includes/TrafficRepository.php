<?php
declare(strict_types=1);

class TrafficRepository {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function saveRealtimeTraffic(
        float $totalBandwidth,
        float $usedBandwidth,
        float $remainingBandwidth,
        float $usagePercentage,
        float $rxBytes = 0,
        float $txBytes = 0,
        int $port = 0
    ): bool {
        $countStmt = $this->pdo->query("SELECT COUNT(*) FROM traffic_realtime");
        $count = (int) $countStmt->fetchColumn();

        if ($count > 1) {
            $this->pdo->exec("DELETE FROM traffic_realtime WHERE id NOT IN (SELECT id FROM traffic_realtime ORDER BY updated_at DESC LIMIT 1)");
        }

        if ($count >= 1) {
            $sql = "UPDATE traffic_realtime SET
                        total_bandwidth = ?, used_bandwidth = ?, remaining_bandwidth = ?,
                        usage_percentage = ?, rx_bytes = ?, tx_bytes = ?, port = ?,
                        updated_at = datetime('now')
                    WHERE id = (SELECT id FROM traffic_realtime ORDER BY updated_at DESC LIMIT 1)";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([$totalBandwidth, $usedBandwidth, $remainingBandwidth, $usagePercentage, $rxBytes, $txBytes, $port]);
        }

        $sql = "INSERT INTO traffic_realtime (total_bandwidth, used_bandwidth, remaining_bandwidth, usage_percentage, rx_bytes, tx_bytes, port, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$totalBandwidth, $usedBandwidth, $remainingBandwidth, $usagePercentage, $rxBytes, $txBytes, $port]);
    }

    public function getRealtimeTraffic() {
        $sql = "SELECT * FROM traffic_realtime ORDER BY updated_at DESC LIMIT 1";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function saveDailyTrafficStats(
        string $date,
        float $totalBandwidth,
        float $usedBandwidth,
        float $remainingBandwidth,
        float $dailyUsage
    ): bool {
        $existing = $this->getDailyTrafficStats($date);

        if ($existing) {
            $sql = "UPDATE traffic_stats
                    SET total_bandwidth = ?,
                        used_bandwidth = ?,
                        remaining_bandwidth = ?,
                        daily_usage = ?,
                        recorded_at = datetime('now')
                    WHERE usage_date = ?";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([$totalBandwidth, $usedBandwidth, $remainingBandwidth, $dailyUsage, $date]);
        }

        $sql = "INSERT INTO traffic_stats (usage_date, total_bandwidth, used_bandwidth, remaining_bandwidth, daily_usage, recorded_at)
                VALUES (?, ?, ?, ?, ?, datetime('now'))";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$date, $totalBandwidth, $usedBandwidth, $remainingBandwidth, $dailyUsage]);
    }

    public function getDailyTrafficStats(string $date) {
        $sql = "SELECT * FROM traffic_stats WHERE usage_date = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateUsedBandwidth(string $date, float $usedBandwidth): bool {
        $sql = "UPDATE traffic_stats
                SET used_bandwidth = ?,
                    recorded_at = datetime('now')
                WHERE usage_date = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$usedBandwidth, $date]);
    }

    public function getRecentTrafficStats(int $days = 30): array {
        $sql = "SELECT * FROM traffic_stats ORDER BY usage_date DESC LIMIT ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTrafficStatsByDateRange(string $startDate, string $endDate): array {
        $sql = "SELECT * FROM traffic_stats
                WHERE usage_date >= ? AND usage_date <= ?
                ORDER BY usage_date DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function calculateDailyUsage(string $date) {
        $todayData = $this->getDailyTrafficStats($date);
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $yesterdayData = $this->getDailyTrafficStats($yesterday);

        if ($todayData && $yesterdayData) {
            if ($todayData['used_bandwidth'] < $yesterdayData['used_bandwidth']) {
                return $todayData['used_bandwidth'];
            }

            return $todayData['used_bandwidth'] - $yesterdayData['used_bandwidth'];
        }

        if ($todayData) {
            return $todayData['used_bandwidth'];
        }

        return 0;
    }

    public function saveTrafficSnapshot(float $rxBytes, float $txBytes): bool {
        $date = date('Y-m-d');
        $currentMinute = (int) date('i');

        $remainder = $currentMinute % 5;
        if ($remainder > 2) {
            return false;
        }
        $roundedMinute = $currentMinute - $remainder;

        $time = sprintf('%s:%02d:00', date('H'), $roundedMinute);
        $totalBytes = $rxBytes + $txBytes;

        $sql = "INSERT OR REPLACE INTO traffic_snapshots (snapshot_date, snapshot_time, rx_bytes, tx_bytes, total_bytes, recorded_at)
                VALUES (?, ?, ?, ?, ?, datetime('now'))";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$date, $time, $rxBytes, $txBytes, $totalBytes]);
    }

    public function getTrafficSnapshotsByDate(string $date): array {
        $sql = "SELECT snapshot_time, rx_bytes, tx_bytes, total_bytes, recorded_at
                FROM traffic_snapshots
                WHERE snapshot_date = ?
                AND (
                    substr(snapshot_time, 4, 2) IN ('00', '05', '10', '15', '20', '25', '30', '35', '40', '45', '50', '55')
                )
                ORDER BY snapshot_time ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTodayTrafficSnapshots(): array {
        $today = date('Y-m-d');
        return $this->getTrafficSnapshotsByDate($today);
    }

    public function getLastSnapshotOfDay(string $date) {
        $sql = "SELECT snapshot_time, rx_bytes, tx_bytes, total_bytes, recorded_at
                FROM traffic_snapshots
                WHERE snapshot_date = ?
                ORDER BY snapshot_time DESC
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getFirstSnapshotOfDay(string $date) {
        $sql = "SELECT snapshot_time, rx_bytes, tx_bytes, total_bytes, recorded_at
                FROM traffic_snapshots
                WHERE snapshot_date = ?
                ORDER BY snapshot_time ASC
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function cleanOldTrafficSnapshots(int $daysToKeep = 35): bool {
        $firstDayOfMonth = date('Y-m-01');
        $lastDayOfPrevMonth = date('Y-m-d', strtotime($firstDayOfMonth . ' -1 day'));
        $nDaysAgo = date('Y-m-d', strtotime("-{$daysToKeep} days"));

        $cutoffDate = min($lastDayOfPrevMonth, $nDaysAgo);

        $sql = "DELETE FROM traffic_snapshots WHERE snapshot_date < ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$cutoffDate]);
    }
}
