<?php
/**
 * Product Availability Schedule Helper
 * Checks if a product is currently available based on its time schedule.
 *
 * Schedule JSON format:
 * {"start":"06:00","end":"11:00","days":["seg","ter","qua","qui","sex","sab","dom"]}
 * null = always available
 */

/**
 * Check if a product is available right now based on its schedule.
 *
 * @param string|array|null $schedule  JSON string or decoded array, or null
 * @return bool  true if available now
 */
function isProductAvailable($schedule) {
    if ($schedule === null || $schedule === '' || $schedule === 'null') {
        return true; // Always available
    }

    if (is_string($schedule)) {
        $schedule = json_decode($schedule, true);
    }

    if (!is_array($schedule) || empty($schedule['start']) || empty($schedule['end'])) {
        // If we got here with a non-null string that failed json_decode, the data is corrupt
        // Treat corrupt schedule data as unavailable (fail-safe)
        error_log("[availability] Corrupt schedule data detected — treating product as unavailable. Raw: " . json_encode($schedule));
        return false;
    }

    $tz = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTime('now', $tz);

    // Check day of week
    if (!empty($schedule['days']) && is_array($schedule['days'])) {
        $dayMap = [
            1 => 'seg', // Monday
            2 => 'ter',
            3 => 'qua',
            4 => 'qui',
            5 => 'sex',
            6 => 'sab',
            0 => 'dom', // Sunday
        ];
        $currentDay = $dayMap[(int)$now->format('w')] ?? '';
        if (!in_array($currentDay, $schedule['days'])) {
            return false;
        }
    }

    // Check time window
    $start = $schedule['start']; // "HH:MM"
    $end = $schedule['end'];     // "HH:MM"
    $currentTime = $now->format('H:i');

    // Handle overnight schedules (e.g., 23:00 - 06:00)
    if ($start <= $end) {
        // Normal range: start <= current < end
        return $currentTime >= $start && $currentTime < $end;
    } else {
        // Overnight: current >= start OR current < end
        return $currentTime >= $start || $currentTime < $end;
    }
}

/**
 * Get the next available time string for display.
 * Returns formatted time like "06:00" or null if always available.
 *
 * @param string|array|null $schedule
 * @return string|null
 */
function getNextAvailableTime($schedule) {
    if ($schedule === null || $schedule === '' || $schedule === 'null') {
        return null;
    }

    if (is_string($schedule)) {
        $schedule = json_decode($schedule, true);
    }

    if (!is_array($schedule) || empty($schedule['start'])) {
        return null;
    }

    return $schedule['start'];
}

/**
 * Validate a schedule array/object before saving.
 * Returns [valid => bool, error => string|null, schedule => array|null]
 *
 * @param mixed $schedule
 * @return array
 */
function validateSchedule($schedule) {
    if ($schedule === null || $schedule === '' || $schedule === 'null') {
        return ['valid' => true, 'error' => null, 'schedule' => null];
    }

    if (is_string($schedule)) {
        $schedule = json_decode($schedule, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['valid' => false, 'error' => 'JSON invalido para horario', 'schedule' => null];
        }
    }

    if (!is_array($schedule)) {
        return ['valid' => false, 'error' => 'Formato de horario invalido', 'schedule' => null];
    }

    // Validate start time
    if (empty($schedule['start']) || !preg_match('/^\d{2}:\d{2}$/', $schedule['start'])) {
        return ['valid' => false, 'error' => 'Horario de inicio invalido (use HH:MM)', 'schedule' => null];
    }

    // Validate end time
    if (empty($schedule['end']) || !preg_match('/^\d{2}:\d{2}$/', $schedule['end'])) {
        return ['valid' => false, 'error' => 'Horario de fim invalido (use HH:MM)', 'schedule' => null];
    }

    // Validate hours/minutes are in range
    $startParts = explode(':', $schedule['start']);
    $endParts = explode(':', $schedule['end']);
    if ((int)$startParts[0] > 23 || (int)$startParts[1] > 59 || (int)$endParts[0] > 23 || (int)$endParts[1] > 59) {
        return ['valid' => false, 'error' => 'Horario fora do intervalo valido', 'schedule' => null];
    }

    // Validate days
    $validDays = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
    $days = $schedule['days'] ?? $validDays;
    if (!is_array($days)) {
        return ['valid' => false, 'error' => 'Dias da semana devem ser um array', 'schedule' => null];
    }
    $days = array_values(array_intersect($days, $validDays));
    if (empty($days)) {
        return ['valid' => false, 'error' => 'Selecione pelo menos um dia da semana', 'schedule' => null];
    }

    // Start and end cannot be the same
    if ($schedule['start'] === $schedule['end']) {
        return ['valid' => false, 'error' => 'Horario de inicio e fim nao podem ser iguais', 'schedule' => null];
    }

    return [
        'valid' => true,
        'error' => null,
        'schedule' => [
            'start' => $schedule['start'],
            'end' => $schedule['end'],
            'days' => $days,
        ]
    ];
}
