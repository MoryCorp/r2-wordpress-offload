<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * LocalCleaner - Verify R2 files, delete local copies, restore from R2
 */
class LocalCleaner {

    private R2Client $r2_client;

    public function __construct(R2Client $r2_client) {
        $this->r2_client = $r2_client;
    }

    /**
     * Verify that all files for an attachment exist on R2 with correct sizes
     *
     * @param int $attachment_id Attachment ID
     * @return array ['verified' => bool, 'files' => [...], 'errors' => [...]]
     */
    public function verify_attachment(int $attachment_id): array {
        $result = [
            'verified' => false,
            'files' => [],
            'errors' => [],
        ];

        $status = get_post_meta($attachment_id, '_numos_r2_status', true);
        if ($status !== 'synced') {
            $result['errors'][] = "Attachment {$attachment_id} is not synced (status: {$status})";
            return $result;
        }

        if (!$this->r2_client->is_available()) {
            $result['errors'][] = 'R2 is not available';
            return $result;
        }

        $local_files = $this->get_attachment_files($attachment_id);
        if (empty($local_files)) {
            $result['errors'][] = "No files found for attachment {$attachment_id}";
            return $result;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $prefix = numos_r2_get_site_prefix();
        $all_ok = true;

        foreach ($local_files as $file_path) {
            $relative = ltrim(str_replace($base_dir, '', $file_path), '/');
            $r2_key = $prefix . '/' . $relative;

            $head = $this->r2_client->head_object($r2_key);
            $local_size = file_exists($file_path) ? filesize($file_path) : null;

            $file_info = [
                'path' => $file_path,
                'r2_key' => $r2_key,
                'local_size' => $local_size,
                'r2_size' => $head ? $head['content_length'] : null,
                'exists_on_r2' => $head !== false,
                'size_match' => false,
            ];

            if ($head === false) {
                $file_info['error'] = 'Not found on R2';
                $result['errors'][] = "File not on R2: {$r2_key}";
                $all_ok = false;
            } elseif ($local_size !== null && $head['content_length'] !== $local_size) {
                $file_info['error'] = "Size mismatch: local={$local_size}, R2={$head['content_length']}";
                $result['errors'][] = "Size mismatch for {$r2_key}";
                $all_ok = false;
            } else {
                $file_info['size_match'] = true;
            }

            $result['files'][] = $file_info;
        }

        $result['verified'] = $all_ok;
        return $result;
    }

    /**
     * Verify all synced attachments using list_objects instead of individual HEAD requests
     *
     * @return array ['ok' => int, 'issues' => int, 'details' => [...]]
     */
    public function verify_all(): array {
        $result = ['ok' => 0, 'issues' => 0, 'details' => []];

        $prefix = numos_r2_get_site_prefix();
        $r2_index = $this->r2_client->list_all_objects($prefix . '/');

        if (empty($r2_index)) {
            return $result;
        }

        global $wpdb;
        $synced_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_numos_r2_status' AND meta_value = %s",
            'synced'
        )));

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        foreach ($synced_ids as $id) {
            $files = $this->get_attachment_files($id);
            $has_issue = false;
            $errors = [];

            foreach ($files as $file_path) {
                $relative = ltrim(str_replace($base_dir, '', $file_path), '/');
                $r2_key = $prefix . '/' . $relative;

                if (!isset($r2_index[$r2_key])) {
                    $errors[] = "Not found on R2: {$r2_key}";
                    $has_issue = true;
                } else {
                    $local_size = file_exists($file_path) ? filesize($file_path) : null;
                    if ($local_size !== null && $r2_index[$r2_key]['size'] !== $local_size) {
                        $errors[] = "Size mismatch for {$r2_key}: local={$local_size}, R2={$r2_index[$r2_key]['size']}";
                        $has_issue = true;
                    }
                }
            }

            if ($has_issue) {
                $result['issues']++;
                $result['details'][] = ['id' => $id, 'errors' => $errors];
            } else {
                $result['ok']++;
            }
        }

        return $result;
    }

    /**
     * Delete all local files for synced attachments using R2 index (single list call)
     *
     * @return array ['deleted' => int, 'freed_bytes' => int, 'skipped' => int, 'errors' => [...]]
     */
    public function delete_all_local(): array {
        $result = ['deleted' => 0, 'freed_bytes' => 0, 'skipped' => 0, 'errors' => []];

        if (!$this->r2_client->is_available()) {
            $result['errors'][] = 'R2 is not available — refusing to delete local files';
            return $result;
        }

        // 1. Build R2 index once
        $prefix = numos_r2_get_site_prefix();
        $r2_index = $this->r2_client->list_all_objects($prefix . '/');

        if (empty($r2_index)) {
            $result['errors'][] = 'R2 index is empty — refusing to delete local files';
            return $result;
        }

        // 2. Load all synced attachments that still have local files
        $attachment_ids = $this->get_synced_with_local(999999, 0);

        if (empty($attachment_ids)) {
            return $result;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // 3. Process each attachment
        foreach ($attachment_ids as $id) {
            $already_deleted = get_post_meta($id, '_numos_r2_local_deleted', true);
            if ($already_deleted) {
                continue;
            }

            $files = $this->get_attachment_files($id);
            if (empty($files)) {
                $result['skipped']++;
                $result['errors'][] = "Attachment {$id}: no files found";
                continue;
            }

            // Verify all files against R2 index
            $all_verified = true;
            foreach ($files as $file_path) {
                $relative = ltrim(str_replace($base_dir, '', $file_path), '/');
                $r2_key = $prefix . '/' . $relative;

                if (!isset($r2_index[$r2_key])) {
                    $all_verified = false;
                    $result['errors'][] = "Attachment {$id}: not on R2: {$r2_key}";
                    break;
                }

                $local_size = file_exists($file_path) ? filesize($file_path) : null;
                if ($local_size !== null && $r2_index[$r2_key]['size'] !== $local_size) {
                    $all_verified = false;
                    $result['errors'][] = "Attachment {$id}: size mismatch for {$r2_key}";
                    break;
                }
            }

            if (!$all_verified) {
                $result['skipped']++;
                continue;
            }

            // Delete local files
            $total_freed = 0;
            $deleted_count = 0;
            $delete_errors = false;

            foreach ($files as $file_path) {
                if (!file_exists($file_path)) {
                    continue;
                }

                $size = filesize($file_path);
                if (!is_writable($file_path)) {
                    $result['errors'][] = "Attachment {$id}: not writable: {$file_path}";
                    $delete_errors = true;
                    continue;
                }
                if (unlink($file_path)) {
                    $deleted_count++;
                    $total_freed += $size;
                } else {
                    $result['errors'][] = "Attachment {$id}: failed to delete: {$file_path}";
                    $delete_errors = true;
                }
            }

            // Clean empty directories
            $dir = dirname($files[0]);
            $this->cleanup_empty_dirs($dir);

            if ($delete_errors) {
                // Record partial deletion so next run doesn't re-verify already-missing files
                if ($deleted_count > 0) {
                    update_post_meta($id, '_numos_r2_local_deleted', time());
                    update_post_meta($id, '_numos_r2_local_deleted_size', $total_freed);
                    $result['freed_bytes'] += $total_freed;
                }
                $result['skipped']++;
                continue;
            }

            // Record deletion metadata
            update_post_meta($id, '_numos_r2_local_deleted', time());
            update_post_meta($id, '_numos_r2_local_deleted_size', $total_freed);

            $result['deleted']++;
            $result['freed_bytes'] += $total_freed;
        }

        Logger::log('info', "delete_all_local: deleted {$result['deleted']}, freed " . size_format($result['freed_bytes']) . ", skipped {$result['skipped']}");

        return $result;
    }

    /**
     * Delete local files for an attachment after verifying R2 copies
     *
     * @param int $attachment_id Attachment ID
     * @param bool $skip_verify Skip verification (dangerous, for internal use only)
     * @return array ['success' => bool, 'deleted_files' => int, 'freed_bytes' => int, 'errors' => [...]]
     */
    public function delete_local_files(int $attachment_id, bool $skip_verify = false): array {
        $result = [
            'success' => false,
            'deleted_files' => 0,
            'freed_bytes' => 0,
            'errors' => [],
        ];

        // Check if already deleted
        $already_deleted = get_post_meta($attachment_id, '_numos_r2_local_deleted', true);
        if ($already_deleted) {
            $result['errors'][] = "Local files already deleted for attachment {$attachment_id}";
            return $result;
        }

        // Verify R2 copies first
        if (!$skip_verify) {
            $verification = $this->verify_attachment($attachment_id);
            if (!$verification['verified']) {
                $result['errors'] = $verification['errors'];
                return $result;
            }
        }

        $local_files = $this->get_attachment_files($attachment_id);
        if (empty($local_files)) {
            $result['errors'][] = "No local files found for attachment {$attachment_id}";
            return $result;
        }

        $total_freed = 0;
        $deleted_count = 0;

        foreach ($local_files as $file_path) {
            if (!file_exists($file_path)) {
                continue;
            }

            $size = filesize($file_path);
            if (!is_writable($file_path)) {
                Logger::log('error', "Cannot delete local file (not writable): {$file_path}");
                $result['errors'][] = "Not writable: {$file_path}";
                continue;
            }
            if (unlink($file_path)) {
                $deleted_count++;
                $total_freed += $size;
            } else {
                Logger::log('error', "Failed to delete local file: {$file_path}");
                $result['errors'][] = "Failed to delete: {$file_path}";
            }
        }

        // Clean empty year/month directories
        if (!empty($local_files)) {
            $dir = dirname($local_files[0]);
            $this->cleanup_empty_dirs($dir);
        }

        if (!empty($result['errors'])) {
            // Record partial deletion so files aren't re-processed
            if ($deleted_count > 0) {
                update_post_meta($attachment_id, '_numos_r2_local_deleted', time());
                update_post_meta($attachment_id, '_numos_r2_local_deleted_size', $total_freed);
                $result['deleted_files'] = $deleted_count;
                $result['freed_bytes'] = $total_freed;
            }
            return $result;
        }

        // Record deletion metadata
        update_post_meta($attachment_id, '_numos_r2_local_deleted', time());
        update_post_meta($attachment_id, '_numos_r2_local_deleted_size', $total_freed);

        $result['success'] = true;
        $result['deleted_files'] = $deleted_count;
        $result['freed_bytes'] = $total_freed;

        Logger::log('info', "Deleted {$deleted_count} local files for attachment {$attachment_id}, freed " . size_format($total_freed));

        return $result;
    }

    /**
     * Batch delete local files for verified synced attachments
     *
     * @param int $batch_size Number of attachments per batch
     * @param int $offset Offset for pagination
     * @return array ['processed' => int, 'deleted' => int, 'freed_bytes' => int, 'errors' => [...], 'done' => bool]
     */
    public function batch_delete_local(int $batch_size = 50, int $offset = 0): array {
        $result = [
            'processed' => 0,
            'deleted' => 0,
            'freed_bytes' => 0,
            'errors' => [],
            'done' => false,
        ];

        if (!$this->r2_client->is_available()) {
            $result['errors'][] = 'R2 is not available — refusing to delete local files';
            return $result;
        }

        $attachment_ids = $this->get_synced_with_local($batch_size, $offset);

        if (empty($attachment_ids)) {
            $result['done'] = true;
            return $result;
        }

        foreach ($attachment_ids as $id) {
            $result['processed']++;
            $del = $this->delete_local_files($id);

            if ($del['success']) {
                $result['deleted']++;
                $result['freed_bytes'] += $del['freed_bytes'];
            } else {
                foreach ($del['errors'] as $err) {
                    $result['errors'][] = "Attachment {$id}: {$err}";
                }
            }
        }

        return $result;
    }

    /**
     * Restore a single attachment from R2 to local filesystem
     *
     * @param int $attachment_id Attachment ID
     * @return array ['success' => bool, 'restored_files' => int, 'errors' => [...]]
     */
    public function restore_from_r2(int $attachment_id): array {
        $result = [
            'success' => false,
            'restored_files' => 0,
            'errors' => [],
        ];

        $status = get_post_meta($attachment_id, '_numos_r2_status', true);
        if ($status !== 'synced') {
            $result['errors'][] = "Attachment {$attachment_id} is not synced";
            return $result;
        }

        if (!$this->r2_client->is_available()) {
            $result['errors'][] = 'R2 is not available';
            return $result;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $prefix = numos_r2_get_site_prefix();

        // Get all expected files
        $local_files = $this->get_attachment_files($attachment_id);
        if (empty($local_files)) {
            $result['errors'][] = "Cannot determine file paths for attachment {$attachment_id}";
            return $result;
        }

        $restored = 0;

        foreach ($local_files as $file_path) {
            if (file_exists($file_path)) {
                $restored++; // Already exists locally
                continue;
            }

            $relative = ltrim(str_replace($base_dir, '', $file_path), '/');
            $r2_key = $prefix . '/' . $relative;

            // Ensure directory exists
            $dir = dirname($file_path);
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }

            if ($this->r2_client->get_object($r2_key, $file_path)) {
                $restored++;
                Logger::log('info', "Restored file from R2: {$r2_key}");
            } else {
                $result['errors'][] = "Failed to download: {$r2_key}";
            }
        }

        if (empty($result['errors'])) {
            // Clear deletion metadata
            delete_post_meta($attachment_id, '_numos_r2_local_deleted');
            delete_post_meta($attachment_id, '_numos_r2_local_deleted_size');
            $result['success'] = true;
        }

        $result['restored_files'] = $restored;

        Logger::log('info', "Restored {$restored} files for attachment {$attachment_id}");

        return $result;
    }

    /**
     * Restore all R2-only attachments to local filesystem in a single call
     *
     * @return array ['restored' => int, 'skipped' => int, 'errors' => [...]]
     */
    public function restore_all(): array {
        $result = ['restored' => 0, 'skipped' => 0, 'errors' => []];

        if (!$this->r2_client->is_available()) {
            $result['errors'][] = 'R2 is not available';
            return $result;
        }

        $attachment_ids = $this->get_r2_only_attachments(999999);

        if (empty($attachment_ids)) {
            return $result;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $prefix = numos_r2_get_site_prefix();

        foreach ($attachment_ids as $id) {
            $local_files = $this->get_attachment_files($id);
            if (empty($local_files)) {
                $result['skipped']++;
                $result['errors'][] = "Attachment {$id}: cannot determine file paths";
                continue;
            }

            $restored = 0;
            $has_error = false;

            foreach ($local_files as $file_path) {
                if (file_exists($file_path)) {
                    $restored++;
                    continue;
                }

                $relative = ltrim(str_replace($base_dir, '', $file_path), '/');
                $r2_key = $prefix . '/' . $relative;

                $dir = dirname($file_path);
                if (!file_exists($dir)) {
                    wp_mkdir_p($dir);
                }

                if ($this->r2_client->get_object($r2_key, $file_path)) {
                    $restored++;
                } else {
                    $result['errors'][] = "Attachment {$id}: failed to download {$r2_key}";
                    $has_error = true;
                }
            }

            if (!$has_error) {
                delete_post_meta($id, '_numos_r2_local_deleted');
                delete_post_meta($id, '_numos_r2_local_deleted_size');
                $result['restored']++;
            } else {
                $result['skipped']++;
            }
        }

        Logger::log('info', "restore_all: restored {$result['restored']}, skipped {$result['skipped']}");

        return $result;
    }

    /**
     * Batch restore from R2
     *
     * @param int $batch_size Number of attachments per batch
     * @return array ['processed' => int, 'restored' => int, 'errors' => [...], 'done' => bool]
     */
    public function batch_restore_from_r2(int $batch_size = 50): array {
        $result = [
            'processed' => 0,
            'restored' => 0,
            'errors' => [],
            'done' => false,
        ];

        if (!$this->r2_client->is_available()) {
            $result['errors'][] = 'R2 is not available';
            return $result;
        }

        $attachment_ids = $this->get_r2_only_attachments($batch_size);

        if (empty($attachment_ids)) {
            $result['done'] = true;
            return $result;
        }

        foreach ($attachment_ids as $id) {
            $result['processed']++;
            $restore = $this->restore_from_r2($id);

            if ($restore['success']) {
                $result['restored']++;
            } else {
                foreach ($restore['errors'] as $err) {
                    $result['errors'][] = "Attachment {$id}: {$err}";
                }
            }
        }

        return $result;
    }

    /**
     * Get storage savings data
     *
     * @return array ['freed_bytes' => int, 'freed_human' => string, 'r2_only_count' => int, 'total_r2_size' => int, 'total_r2_size_human' => string]
     */
    public function get_savings(): array {
        global $wpdb;

        // Total freed bytes
        $freed = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0)
            FROM {$wpdb->postmeta}
            WHERE meta_key = %s",
            '_numos_r2_local_deleted_size'
        ));
        $freed = (int)$freed;

        // R2-only count
        $r2_only = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = %s",
            '_numos_r2_local_deleted'
        ));
        $r2_only = (int)$r2_only;

        // Total synced count
        $synced = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_numos_r2_status' AND meta_value = %s",
            'synced'
        ));
        $synced = (int)$synced;

        return [
            'freed_bytes' => $freed,
            'freed_human' => size_format($freed),
            'r2_only_count' => $r2_only,
            'synced_count' => $synced,
        ];
    }

    /**
     * Sync database from R2: scan R2 index and mark unsynced attachments whose files are all present on R2
     *
     * @return array ['synced' => int, 'partial' => int, 'skipped' => int, 'total' => int]
     */
    public function sync_from_r2(): array {
        $result = ['synced' => 0, 'partial' => 0, 'skipped' => 0, 'total' => 0];

        if (!$this->r2_client->is_available()) {
            Logger::log('error', 'sync_from_r2: R2 is not available');
            $result['error'] = __('R2 is not available. Check your connection settings.', 'numos-r2');
            return $result;
        }

        // 1. Build R2 index
        $prefix = numos_r2_get_site_prefix();
        Logger::log('info', "sync_from_r2: scanning R2 with prefix '{$prefix}/'");
        $r2_index = $this->r2_client->list_all_objects($prefix . '/');

        if (empty($r2_index)) {
            Logger::log('warning', "sync_from_r2: no files found on R2 under prefix '{$prefix}/'");
            $result['error'] = sprintf(
                __('No files found on R2 under prefix "%s/". Check that your files are stored under this prefix.', 'numos-r2'),
                $prefix
            );
            return $result;
        }

        $result['r2_files'] = count($r2_index);
        Logger::log('info', "sync_from_r2: found " . count($r2_index) . " files on R2");

        // 2. Load all attachment IDs that are NOT already synced
        global $wpdb;
        $attachment_ids = array_map('intval', $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
                 ON p.ID = pm.post_id
                 AND pm.meta_key = '_numos_r2_status'
                 AND pm.meta_value = 'synced'
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND pm.post_id IS NULL"
        ));

        $result['total'] = count($attachment_ids);

        if (empty($attachment_ids)) {
            return $result;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // 3. Check each attachment against R2 index
        foreach ($attachment_ids as $id) {
            $files = $this->get_attachment_files($id);
            if (empty($files)) {
                $result['skipped']++;
                continue;
            }

            // First file is the main file
            $main_path = $files[0];
            $main_relative = ltrim(str_replace($base_dir, '', $main_path), '/');
            $main_r2_key = $prefix . '/' . $main_relative;

            // Check if main file exists on R2
            if (!isset($r2_index[$main_r2_key])) {
                $result['skipped']++;
                continue;
            }

            // Check thumbnails
            $thumbs_total = count($files) - 1;
            $thumbs_found = 0;

            for ($i = 1; $i < count($files); $i++) {
                $relative = ltrim(str_replace($base_dir, '', $files[$i]), '/');
                $r2_key = $prefix . '/' . $relative;
                if (isset($r2_index[$r2_key])) {
                    $thumbs_found++;
                }
            }

            if ($thumbs_total === 0 || $thumbs_found === $thumbs_total) {
                // All files present on R2 — mark as synced
                update_post_meta($id, '_numos_r2_status', 'synced');
                update_post_meta($id, '_numos_r2_synced', time());
                update_post_meta($id, '_numos_r2_key', $main_r2_key);
                update_post_meta($id, '_numos_r2_url', $this->r2_client->get_public_url($main_r2_key));

                // If local file doesn't exist, also mark as local deleted
                if (!file_exists($main_path)) {
                    update_post_meta($id, '_numos_r2_local_deleted', time());
                    // Estimate size from R2 index
                    $total_size = $r2_index[$main_r2_key]['size'];
                    for ($i = 1; $i < count($files); $i++) {
                        $relative = ltrim(str_replace($base_dir, '', $files[$i]), '/');
                        $r2_key = $prefix . '/' . $relative;
                        if (isset($r2_index[$r2_key])) {
                            $total_size += $r2_index[$r2_key]['size'];
                        }
                    }
                    update_post_meta($id, '_numos_r2_local_deleted_size', $total_size);
                }

                $result['synced']++;
            } else {
                // Main OK but some thumbs missing
                $result['partial']++;
                Logger::log('warning', "sync_from_r2: attachment {$id} partial — {$thumbs_found}/{$thumbs_total} thumbnails found on R2");
            }
        }

        Logger::log('info', "sync_from_r2: synced {$result['synced']}, partial {$result['partial']}, skipped {$result['skipped']} of {$result['total']} attachments");

        if ($result['synced'] > 0) {
            delete_transient('numos_r2_synced_paths');
        }

        return $result;
    }

    /**
     * Get all file paths for an attachment (main + thumbnails + original)
     *
     * @param int $attachment_id Attachment ID
     * @return array File paths
     */
    public function get_attachment_files(int $attachment_id): array {
        $files = [];

        $local_path = get_attached_file($attachment_id);
        if (!$local_path) {
            return $files;
        }

        $files[] = $local_path;
        $base_dir = dirname($local_path);

        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_data) {
                $thumb_path = $base_dir . '/' . $size_data['file'];
                $files[] = $thumb_path;
            }
        }

        if (!empty($metadata['original_image'])) {
            $files[] = $base_dir . '/' . $metadata['original_image'];
        }

        return array_unique($files);
    }

    /**
     * Get synced attachments that still have local files (not yet deleted)
     *
     * @param int $limit Number of items
     * @param int $offset Offset
     * @return array Attachment IDs
     */
    public function get_synced_with_local(int $limit = 50, int $offset = 0): array {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm_status.post_id
            FROM {$wpdb->postmeta} pm_status
            LEFT JOIN {$wpdb->postmeta} pm_deleted
                ON pm_status.post_id = pm_deleted.post_id
                AND pm_deleted.meta_key = '_numos_r2_local_deleted'
            WHERE pm_status.meta_key = '_numos_r2_status'
            AND pm_status.meta_value = 'synced'
            AND pm_deleted.meta_value IS NULL
            ORDER BY pm_status.post_id ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset)));
    }

    /**
     * Count synced attachments that still have local files
     *
     * @return int
     */
    public function count_synced_with_local(): int {
        global $wpdb;

        return (int)$wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT pm_status.post_id)
            FROM {$wpdb->postmeta} pm_status
            LEFT JOIN {$wpdb->postmeta} pm_deleted
                ON pm_status.post_id = pm_deleted.post_id
                AND pm_deleted.meta_key = %s
            WHERE pm_status.meta_key = %s
            AND pm_status.meta_value = %s
            AND pm_deleted.meta_value IS NULL
        ", '_numos_r2_local_deleted', '_numos_r2_status', 'synced'));
    }

    /**
     * Get attachments that are R2-only (local deleted)
     *
     * @param int $limit Number of items
     * @return array Attachment IDs
     */
    public function get_r2_only_attachments(int $limit = 50): array {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_numos_r2_local_deleted'
            ORDER BY post_id ASC
            LIMIT %d
        ", $limit)));
    }

    /**
     * Clean up empty year/month directories after file deletion
     *
     * @param string $dir Directory to check
     */
    private function cleanup_empty_dirs(string $dir): void {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // Don't go above uploads base dir
        if (strlen($dir) <= strlen($base_dir)) {
            return;
        }

        // Check if directory is empty
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        if (empty($files)) {
            @rmdir($dir);
            Logger::log('debug', "Removed empty directory: {$dir}");

            // Try parent (e.g., year directory)
            $parent = dirname($dir);
            $this->cleanup_empty_dirs($parent);
        }
    }
}
