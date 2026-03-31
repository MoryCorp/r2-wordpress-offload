<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * Logger - Shared logging utility
 */
class Logger {

    private static ?array $settings = null;
    private static bool $dir_ensured = false;

    /**
     * Log a message
     *
     * @param string $level Log level (debug, info, warning, error)
     * @param string $message Message to log
     */
    public static function log(string $level, string $message): void {
        if (self::$settings === null) {
            self::$settings = get_option('numos_r2_settings', []);
        }

        $min_level = self::$settings['log_level'] ?? 'info';
        $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

        if (($levels[$level] ?? 0) < ($levels[$min_level] ?? 1)) {
            return;
        }

        $log_dir = WP_CONTENT_DIR . '/numos-r2-logs';
        if (!self::$dir_ensured) {
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);
                file_put_contents($log_dir . '/.htaccess', 'Deny from all');
                file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
            }
            self::$dir_ensured = true;
        }

        $log_file = $log_dir . '/numos-r2-' . gmdate('Y-m-d') . '.log.php';
        $timestamp = gmdate('Y-m-d H:i:s');
        $message = str_replace(["\r", "\n"], ' ', $message);
        $log_line = "[{$timestamp}] [{$level}] {$message}\n";

        // Add PHP guard as first line for new files (prevents direct access under Nginx)
        if (!file_exists($log_file)) {
            file_put_contents($log_file, "<?php exit; ?>\n" . $log_line, LOCK_EX);
        } else {
            file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
        }

        // Also log errors to WordPress debug.log
        if ($level === 'error' && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Numos R2] {$message}");
        }
    }

    /**
     * Reset cached settings (call after settings update)
     */
    public static function reset(): void {
        self::$settings = null;
    }
}
