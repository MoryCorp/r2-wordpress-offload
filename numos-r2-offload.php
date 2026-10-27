<?php
/**
 * Plugin Name: Object Storage Offload
 * Description: Offload WordPress media to S3-compatible object storage
 * Version: 2.0.0
 * Author: Media Tools
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

defined('ABSPATH') || exit;

define('NUMOS_R2_VERSION', '2.0.0');
define('NUMOS_R2_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NUMOS_R2_PLUGIN_URL', plugin_dir_url(__FILE__));

function numos_r2_check_requirements() {
    $errors = [];

    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $errors[] = sprintf(
            __('Object Storage Offload requires PHP 7.4 or higher. You are running PHP %s.', 'numos-r2'),
            PHP_VERSION
        );
    }

    if (!function_exists('curl_init')) {
        $errors[] = __('Object Storage Offload requires the cURL PHP extension.', 'numos-r2');
    }

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
            __('Object Storage Offload requires the following constants to be defined in wp-config.php: %s', 'numos-r2'),
            implode(', ', $missing_constants)
        );
    }

    return $errors;
}

function numos_r2_get_site_prefix(): string {
    if (defined('NUMOS_R2_PREFIX') && NUMOS_R2_PREFIX) {
        return sanitize_file_name(NUMOS_R2_PREFIX);
    }

    $host = parse_url(home_url(), PHP_URL_HOST);

    $host = preg_replace('/^www\./', '', $host);

    $prefix = str_replace('.', '-', $host);

    return sanitize_file_name($prefix);
}

/**
 * Attachment ID received from a filter. Callers pass numeric strings, customizer changeset
 * UUIDs, null or ACF locations such as "options"; anything but a positive number gives 0.
 */
function numos_r2_attachment_id($raw): int {
    return is_numeric($raw) ? max(0, (int) $raw) : 0;
}

function numos_r2_admin_notice_errors() {
    $errors = numos_r2_check_requirements();

    if (empty($errors)) {
        return;
    }

    echo '<div class="notice notice-error">';
    echo '<p><strong>' . esc_html__('Object Storage Offload', 'numos-r2') . '</strong></p>';
    foreach ($errors as $error) {
        echo '<p>' . esc_html($error) . '</p>';
    }
    echo '</div>';
}

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

function numos_r2_init() {
    $errors = numos_r2_check_requirements();

    if (!empty($errors)) {
        add_action('admin_notices', 'numos_r2_admin_notice_errors');
        return;
    }

    load_plugin_textdomain('numos-r2', false, dirname(plugin_basename(__FILE__)) . '/languages');

    $r2_client = new NumosR2\R2Client();
    $media_handler = new NumosR2\MediaHandler($r2_client);
    $url_rewriter = new NumosR2\UrlRewriter();
    $migrator = new NumosR2\Migrator($r2_client);
    $migrator->set_media_handler($media_handler);
    $local_cleaner = new NumosR2\LocalCleaner($r2_client);

    if (is_admin()) {
        new NumosR2\Admin($r2_client, $migrator, $local_cleaner);
    }

    $media_handler->register_hooks();

    $url_rewriter->register_hooks();

    NumosR2\CLI::register($r2_client, $migrator, $media_handler, $local_cleaner);
}
add_action('plugins_loaded', 'numos_r2_init');

function numos_r2_activate() {
    NumosR2\Logger::ensure_dir();

    add_option('numos_r2_settings', [
        'enabled' => true,
        'sync_new_uploads' => true,
        'remove_local_files' => false,
        'log_level' => 'info',
        'batch_size' => 50,
    ]);
}
register_activation_hook(__FILE__, 'numos_r2_activate');

function numos_r2_deactivate() {
    delete_transient('numos_r2_health_status');
    delete_transient('numos_r2_stats_cache');
    delete_transient('numos_r2_migration_lock');
    delete_transient('numos_r2_progress_cache');
    NumosR2\UrlRewriter::forget_map();
}
register_deactivation_hook(__FILE__, 'numos_r2_deactivate');
