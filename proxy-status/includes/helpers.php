<?php
/**
 * Clamp a Y-m-d date string to today if it is in the future, otherwise return as-is.
 * Returns null if input is falsy or format invalid (non Y-m-d).
 */
function proxyStatusNormalizeDateParam($value): ?string {
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    return substr($value, 0, 10);
}

function proxyStatusIsValidDate(?string $date): bool {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $dateTime = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateTime) {
        return false;
    }

    return $dateTime->format('Y-m-d') === $date;
}

function proxyStatusNormalizeAndClampDate($value): ?string {
    $date = proxyStatusNormalizeDateParam($value);
    if ($date === null || !proxyStatusIsValidDate($date)) {
        return null;
    }

    return clampToToday($date);
}

function clampToToday(?string $date): ?string {
    if (!$date) {
        return $date; // keep null/empty as-is
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date; // do not mutate invalid format here
    }
    $today = date('Y-m-d');
    if ($date > $today) {
        return $today;
    }
    return $date;
}
