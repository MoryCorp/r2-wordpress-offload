<?php
namespace NumosR2;

defined('ABSPATH') || exit;

class Logger {

    private static ?array $settings = null;
    private static bool $dir_ensured = false;

    /**
     * @param string $level debug, info, warning or error
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

        if (!self::$dir_ensured) {
            self::ensure_dir();
            self::$dir_ensured = true;
        }

        $log_file = self::file();
        $timestamp = gmdate('Y-m-d H:i:s');
        $message = str_replace(["\r", "\n"], ' ', $message);
        $log_line = "[{$timestamp}] [{$level}] {$message}\n";

        // The PHP guard keeps the log unreadable over HTTP where .htaccess is ignored (nginx).
        if (!file_exists($log_file)) {
            file_put_contents($log_file, "<?php exit; ?>\n" . $log_line, LOCK_EX);
        } else {
            file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
        }

        if ($level === 'error' && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Object Storage Offload] {$message}");
        }
    }

    /**
     * Today's log file.
     */
    public static function file(): string {
        return self::dir() . '/numos-r2-' . gmdate('Y-m-d') . '.log.php';
    }

    public static function ensure_dir(): void {
        $dir = self::dir();
        wp_mkdir_p($dir);
        foreach (['.htaccess' => 'Deny from all', 'index.php' => '<' . '?php // Silence is golden'] as $name => $content) {
            if (!file_exists("$dir/$name")) {
                file_put_contents("$dir/$name", $content);
            }
        }
    }

    private static function dir(): string {
        return WP_CONTENT_DIR . '/numos-r2-logs';
    }

    public static function reset(): void {
        self::$settings = null;
    }
}
