<?php
declare(strict_types=1);

class AuditLogRepository {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function addAuditLog(
        $username,
        $action,
        $targetType = null,
        $targetId = null,
        $details = null,
        $ipAddress = null,
        $userAgent = null
    ) {
        $sql = "INSERT INTO audit_logs (username, action, target_type, target_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$username, $action, $targetType, $targetId, $details, $ipAddress, $userAgent]);
    }
}
