<?php
/**
 * Storage metadata (_numos_r2_* post meta, manifests included) is kept: once local copies are
 * removed it is the only mapping to the files on R2, and an uninstall must not lose it. The
 * "Purge R2 Metadata" admin action is the deliberate wipe.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('numos_r2_settings');
delete_option('numos_r2_migration_checkpoint');
delete_option('numos_r2_migration_stats');
delete_option('numos_r2_last_migration');
delete_option('numos_r2_cors_configured');

delete_transient('numos_r2_health_status');
delete_transient('numos_r2_stats_cache');
delete_transient('numos_r2_progress_cache');
delete_transient('numos_r2_migration_lock');
delete_transient('numos_r2_key_map');

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
    @rmdir($log_dir);
}
