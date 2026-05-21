<?php
declare(strict_types=1);

class DatabaseConnection {
    public static function createSqlite(string $dbPath): PDO {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
