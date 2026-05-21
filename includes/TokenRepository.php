<?php
declare(strict_types=1);

class TokenRepository {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function createApiToken(string $name, int $proxyCount, string $expiresAt) {
        $token = $this->generateUniqueToken();
        $sql = "INSERT INTO api_tokens (token, name, proxy_count, expires_at) VALUES (?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([$token, $name, $proxyCount, $expiresAt]);

        if ($result) {
            $tokenId = (int) $this->pdo->lastInsertId();
            $assignedCount = $this->assignProxiesToToken($tokenId, $proxyCount);
            if ($assignedCount === 0) {
                $sql = "DELETE FROM api_tokens WHERE id = ?";
                $cleanupStmt = $this->pdo->prepare($sql);
                $cleanupStmt->execute([$tokenId]);
                error_log('create_api_token_no_available_proxies: token creation aborted due to no assignable proxies');
                return false;
            }
            return $token;
        }

        return false;
    }

    public function getAllTokens(): array {
        $sql = "SELECT t.id,
                       t.name,
                       t.proxy_count,
                       t.created_at,
                       t.expires_at,
                       CASE
                           WHEN LENGTH(t.token) > 10 THEN SUBSTR(t.token, 1, 6) || '...' || SUBSTR(t.token, -4)
                           ELSE '******'
                       END as token_preview,
                       COUNT(a.proxy_id) as assigned_count,
                       CASE WHEN t.expires_at > datetime('now') THEN 1 ELSE 0 END as is_valid
                FROM api_tokens t
                LEFT JOIN token_proxy_assignments a ON t.id = a.token_id
                WHERE t.is_active = 1
                GROUP BY t.id
                ORDER BY t.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTokenByValue(string $token) {
        $sql = "SELECT * FROM api_tokens WHERE token = ? AND is_active = 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function validateToken(string $token) {
        $sql = "SELECT * FROM api_tokens WHERE token = ? AND is_active = 1 AND expires_at > datetime('now')";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getTokenProxies(int $tokenId): array {
        $sql = "SELECT p.* FROM proxies p
                INNER JOIN token_proxy_assignments a ON p.id = a.proxy_id
                WHERE a.token_id = ?
                ORDER BY p.response_time ASC, p.failure_count ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tokenId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function refreshToken(int $tokenId, string $newExpiresAt): bool {
        $sql = "UPDATE api_tokens SET expires_at = ?, updated_at = datetime('now') WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$newExpiresAt, $tokenId]);
    }

    public function deleteToken(int $tokenId): bool {
        $sql = "UPDATE api_tokens SET is_active = 0, updated_at = datetime('now') WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$tokenId]);
    }

    public function reassignTokenProxies(int $tokenId, int $proxyCount): bool {
        try {
            $this->pdo->beginTransaction();

            $sql = "DELETE FROM token_proxy_assignments WHERE token_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tokenId]);

            $assignedCount = $this->assignProxiesToToken($tokenId, $proxyCount);
            if ($assignedCount === 0) {
                $this->pdo->rollBack();
                error_log('reassign_token_proxies_no_available_proxies: no assignable proxies for token_id=' . $tokenId);
                return false;
            }

            $sql = "UPDATE api_tokens SET proxy_count = ?, updated_at = datetime('now') WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([$proxyCount, $tokenId]);

            $this->pdo->commit();
            return $result;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception('代理重新分配失败: ' . $e->getMessage());
        }
    }

    private function generateUniqueToken(): string {
        do {
            $token = bin2hex(random_bytes(32));
            $sql = "SELECT COUNT(*) FROM api_tokens WHERE token = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$token]);
            $exists = $stmt->fetchColumn() > 0;
        } while ($exists);

        return $token;
    }

    private function assignProxiesToToken(int $tokenId, int $proxyCount): int {
        $sql = "SELECT id FROM proxies WHERE status = 'online' ORDER BY response_time ASC, failure_count ASC LIMIT ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$proxyCount]);
        $proxies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($proxies) < $proxyCount) {
            $remainingCount = $proxyCount - count($proxies);
            $existingIds = array_column($proxies, 'id');
            $placeholders = str_repeat('?,', count($existingIds));
            $placeholders = rtrim($placeholders, ',');

            $sql = "SELECT id FROM proxies WHERE status = 'unknown'";
            if (!empty($existingIds)) {
                $sql .= " AND id NOT IN ($placeholders)";
            }
            $sql .= " ORDER BY created_at ASC LIMIT ?";

            $stmt = $this->pdo->prepare($sql);
            $params = array_merge($existingIds, [$remainingCount]);
            $stmt->execute($params);
            $additionalProxies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $proxies = array_merge($proxies, $additionalProxies);
        }

        foreach ($proxies as $proxy) {
            $sql = "INSERT INTO token_proxy_assignments (token_id, proxy_id) VALUES (?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tokenId, $proxy['id']]);
        }

        return count($proxies);
    }
}
