<?php
if (!defined('ABSPATH')) { exit; }

class F360LS_Logger {
    private const MAX_LOGS = 200;

    public static function log(string $level, string $message, array $context = []): void {
        $logs = get_option(F360LS_OPTION_LOGS, []);
        $logs = is_array($logs) ? $logs : [];
        array_unshift($logs, [
            'time' => current_time('mysql'),
            'level' => sanitize_key($level),
            'message' => sanitize_text_field($message),
            'context' => self::sanitize_context($context),
        ]);
        $logs = array_slice($logs, 0, self::MAX_LOGS);
        update_option(F360LS_OPTION_LOGS, $logs, false);
    }

    public static function get_logs(): array {
        $logs = get_option(F360LS_OPTION_LOGS, []);
        return is_array($logs) ? $logs : [];
    }

    public static function clear(): void {
        delete_option(F360LS_OPTION_LOGS);
    }

    private static function sanitize_context(array $context): array {
        $out = [];
        foreach ($context as $key => $value) {
            $key = sanitize_key((string) $key);
            if (is_scalar($value) || $value === null) {
                $out[$key] = sanitize_text_field((string) $value);
            } elseif (is_array($value)) {
                $out[$key] = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $out;
    }
}
