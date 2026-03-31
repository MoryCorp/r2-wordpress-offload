<?php
/**
 * Numos R2 Media Offload - Uninstall
 *
 * Cleans up all plugin data when uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete all R2-related post meta
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like('_numos_r2_') . '%'
));

// Delete plugin options
delete_option('numos_r2_settings');
delete_option('numos_r2_migration_checkpoint');
delete_option('numos_r2_migration_stats');
delete_option('numos_r2_last_migration');

// Delete transients
delete_transient('numos_r2_health_status');
delete_transient('numos_r2_stats_cache');
delete_transient('numos_r2_progress_cache');
delete_transient('numos_r2_fully_synced');
delete_transient('numos_r2_migration_lock');
delete_transient('numos_r2_synced_paths');

// Remove log directory
$log_dir = WP_CONTENT_DIR . '/numos-r2-logs';
if (is_dir($log_dir)) {
    $files = glob($log_dir . '/{*.log.php,.htaccess,index.php}', GLOB_BRACE);
    if (is_array($files)) {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    rmdir($log_dir);
}
