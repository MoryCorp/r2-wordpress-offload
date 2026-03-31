<?php
/**
 * Plugin Name: Numos R2 Media Offload
 * Description: Offload WordPress media to Cloudflare R2 storage
 * Version: 1.0.0
 * Author: Numos
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

defined('ABSPATH') || exit;

// Plugin constants
define('NUMOS_R2_VERSION', '1.0.0');
define('NUMOS_R2_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NUMOS_R2_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check requirements before loading
 */
function numos_r2_check_requirements() {
    $errors = [];

    // PHP version check
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $errors[] = sprintf(
            __('Numos R2 Media Offload requires PHP 7.4 or higher. You are running PHP %s.', 'numos-r2'),
            PHP_VERSION
        );
    }

    // cURL extension check
    if (!function_exists('curl_init')) {
        $errors[] = __('Numos R2 Media Offload requires the cURL PHP extension.', 'numos-r2');
    }

    // Required constants check
    $required_constants = [
        'NUMOS_R2_ACCOUNT_ID',
        'NUMOS_R2_ACCESS_KEY',
        'NUMOS_R2_SECRET_KEY',
        'NUMOS_R2_BUCKET',
        'NUMOS_R2_PUBLIC_URL',
    ];

    $missing_constants = [];
    foreach ($required_constants as $constant) {
        if (!defined($constant)) {
            $missing_constants[] = $constant;
        }
    }

    if (!empty($missing_constants)) {
        $errors[] = sprintf(
            __('Numos R2 Media Offload requires the following constants to be defined in wp-config.php: %s', 'numos-r2'),
            implode(', ', $missing_constants)
        );
    }

    return $errors;
}

/**
 * Get site prefix for R2 paths (based on domain name)
 *
 * @return string Site prefix (e.g., 'nextage-ai' for nextage.ai)
 */
function numos_r2_get_site_prefix(): string {
    // Allow override via constant
    if (defined('NUMOS_R2_PREFIX') && NUMOS_R2_PREFIX) {
        return sanitize_file_name(NUMOS_R2_PREFIX);
    }

    // Auto-detect from site URL
    $host = parse_url(home_url(), PHP_URL_HOST);

    // Remove www. prefix
    $host = preg_replace('/^www\./', '', $host);

    // Convert to safe folder name (dots to dashes)
    $prefix = str_replace('.', '-', $host);

    return sanitize_file_name($prefix);
}

/**
 * Display admin notice for errors
 */
function numos_r2_admin_notice_errors() {
    $errors = numos_r2_check_requirements();

    if (empty($errors)) {
        return;
    }

    echo '<div class="notice notice-error">';
    echo '<p><strong>' . esc_html__('Numos R2 Media Offload', 'numos-r2') . '</strong></p>';
    foreach ($errors as $error) {
        echo '<p>' . esc_html($error) . '</p>';
    }
    echo '</div>';
}

/**
 * Autoload plugin classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'NumosR2\\';
    $base_dir = NUMOS_R2_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('\\', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Initialize the plugin
 */
function numos_r2_init() {
    // Check requirements
    $errors = numos_r2_check_requirements();

    if (!empty($errors)) {
        add_action('admin_notices', 'numos_r2_admin_notice_errors');
        return;
    }

    // Load text domain
    load_plugin_textdomain('numos-r2', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Initialize components
    $r2_client = new NumosR2\R2Client();
    $media_handler = new NumosR2\MediaHandler($r2_client);
    $url_rewriter = new NumosR2\UrlRewriter($r2_client);
    $migrator = new NumosR2\Migrator($r2_client);
    $migrator->set_media_handler($media_handler);
    $local_cleaner = new NumosR2\LocalCleaner($r2_client);

    // Initialize admin
    if (is_admin()) {
        new NumosR2\Admin($r2_client, $migrator, $local_cleaner);
    }

    // Register hooks for new uploads
    $media_handler->register_hooks();

    // Register URL rewriting hooks
    $url_rewriter->register_hooks();

    // Register WP-CLI commands
    NumosR2\CLI::register($r2_client, $migrator, $media_handler, $local_cleaner);
}
add_action('plugins_loaded', 'numos_r2_init');

/**
 * Activation hook
 */
function numos_r2_activate() {
    // Create log directory if needed
    $log_dir = WP_CONTENT_DIR . '/numos-r2-logs';
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    // Protect log directory (Apache + Nginx/direct access)
    if (!file_exists($log_dir . '/.htaccess')) {
        file_put_contents($log_dir . '/.htaccess', 'Deny from all');
    }
    if (!file_exists($log_dir . '/index.php')) {
        file_put_contents($log_dir . '/index.php', '<' . '?php // Silence is golden.');
    }

    // Note: Log files use .log.php extension with a PHP exit guard
    // to prevent direct access even under Nginx (see class-logger.php)

    // Set default options
    add_option('numos_r2_settings', [
        'enabled' => true,
        'sync_new_uploads' => true,
        'remove_local_files' => false,
        'log_level' => 'info',
        'batch_size' => 50,
    ]);
}
register_activation_hook(__FILE__, 'numos_r2_activate');

/**
 * Deactivation hook
 */
function numos_r2_deactivate() {
    // Clean up transients
    delete_transient('numos_r2_health_status');
    delete_transient('numos_r2_stats_cache');
    delete_transient('numos_r2_migration_lock');
    delete_transient('numos_r2_fully_synced');
    delete_transient('numos_r2_progress_cache');
    delete_transient('numos_r2_synced_paths');
}
register_deactivation_hook(__FILE__, 'numos_r2_deactivate');
