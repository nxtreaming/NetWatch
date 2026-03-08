<?php

function netwatch_read_json_file(string $filePath): ?array {
    if (!file_exists($filePath)) {
        return null;
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        return null;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function netwatch_write_json_file(string $filePath, array $payload): bool {
    $tempFile = $filePath . '.tmp';
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }

    $written = file_put_contents($tempFile, $encoded, LOCK_EX);
    if ($written === false) {
        return false;
    }

    return rename($tempFile, $filePath);
}

function netwatch_is_batch_finished(string $statusFile): bool {
    $status = netwatch_read_json_file($statusFile);
    if (!is_array($status)) {
        return false;
    }

    return in_array($status['status'] ?? '', ['completed', 'cancelled', 'error'], true);
}

function netwatch_is_cancelled_dir(string $tempDir): bool {
    $cancelFile = $tempDir . '/cancel.flag';
    return file_exists($cancelFile);
}
