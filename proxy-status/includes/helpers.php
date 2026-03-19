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
        return '';
    }

    return substr($value, 0, 10);
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
