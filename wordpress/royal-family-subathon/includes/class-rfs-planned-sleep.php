<?php
/** Planned sleep duration: server-owned, display-only expiry, no automatic wake. */
if (!defined('ABSPATH')) exit;
final class RFS_Planned_Sleep {
    public const MAX_SECONDS = 2592000;
    public static function validate_seconds($seconds): int {
        if (!is_int($seconds) || $seconds < 1 || $seconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException('Bitte eine Schlafzeit zwischen 1 Sekunde und 720 Stunden eingeben.');
        }
        return $seconds;
    }
    public static function fields(array $row, int $now): array {
        $duration = filter_var($row['sleep_duration_seconds'] ?? 0, FILTER_VALIDATE_INT);
        $started = filter_var($row['sleep_started_at'] ?? 0, FILTER_VALIDATE_INT);
        $valid = !empty($row['sleeping']) && $duration !== false && $started !== false
            && $duration >= 1 && $duration <= self::MAX_SECONDS && $started > 0 && $started <= $now;
        $ends = $valid ? $started + $duration : 0;
        return [
            'serverNow' => $now,
            'sleepDurationSeconds' => $duration !== false && $duration > 0 && $duration <= self::MAX_SECONDS ? $duration : 0,
            'sleepEndsAt' => $ends,
            'sleepRemainingSeconds' => $valid ? max(0, $ends - $now) : null,
            'sleepPlanKnown' => $valid,
        ];
    }
}
