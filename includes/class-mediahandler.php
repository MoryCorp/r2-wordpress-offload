<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * Media Handler - Handles automatic upload of new media to R2
 */
class MediaHandler {

    private R2Client $r2_client;
    private array $settings;

    public function __construct(R2Client $r2_client) {
        $this->r2_client = $r2_client;
        $this->settings = get_option('numos_r2_settings', []);
    }

    /**
     * Register WordPress hooks
     */
    public function register_hooks(): void {
        // Only if enabled
        if (empty($this->settings['enabled']) || empty($this->settings['sync_new_uploads'])) {
            return;
        }

        // Hook after WordPress generates thumbnails
        add_filter('wp_generate_attachment_metadata', [$this, 'handle_new_upload'], 10, 2);

        // Hook for attachment deletion
        add_action('delete_attachment', [$this, 'handle_delete'], 10, 1);
    }

    /**
     * Handle new upload - upload main file + all thumbnails to R2
     *
     * @param array $metadata Attachment metadata
     * @param int $attachment_id Attachment ID
     * @return array Original metadata
     */
    public function handle_new_upload(array $metadata, int $attachment_id): array {
        // Check if R2 is available
        if (!$this->r2_client->is_available()) {
            Logger::log('warning', "R2 not available, skipping upload for attachment {$attachment_id}");
            return $metadata;
        }

        $local_path = get_attached_file($attachment_id);

        if (!$local_path || !file_exists($local_path)) {
            Logger::log('error', "Local file not found for attachment {$attachment_id}");
            return $metadata;
        }

        // Validate file type
        if (!$this->is_allowed_file($local_path)) {
            Logger::log('warning', "File type not allowed for attachment {$attachment_id}");
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = dirname($local_path);

        // Collect all files to upload
        $parallel_files = [];
        $main_r2_key = $this->get_r2_key($local_path, $upload_dir['basedir']);
        $parallel_files[] = ['local_path' => $local_path, 'r2_key' => $main_r2_key];

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_data) {
                $thumb_path = $base_dir . '/' . $size_data['file'];
                if (file_exists($thumb_path)) {
                    $parallel_files[] = ['local_path' => $thumb_path, 'r2_key' => $this->get_r2_key($thumb_path, $upload_dir['basedir'])];
                }
            }
        }

        if (!empty($metadata['original_image'])) {
            $original_path = $base_dir . '/' . $metadata['original_image'];
            if (file_exists($original_path)) {
                $parallel_files[] = ['local_path' => $original_path, 'r2_key' => $this->get_r2_key($original_path, $upload_dir['basedir'])];
            }
        }

        // Upload all files in parallel
        $upload_results = $this->r2_client->upload_files_parallel($parallel_files);

        $uploaded_files = [];
        $failed_files = [];

        foreach ($parallel_files as $file_info) {
            $ur = $upload_results[$file_info['r2_key']] ?? null;
            if ($ur && $ur['success']) {
                $uploaded_files[] = $file_info['r2_key'];
                if ($file_info['r2_key'] === $main_r2_key) {
                    update_post_meta($attachment_id, '_numos_r2_key', $main_r2_key);
                    update_post_meta($attachment_id, '_numos_r2_url', $ur['url']);
                    update_post_meta($attachment_id, '_numos_r2_synced', time());
                }
            } else {
                $failed_files[] = $file_info['r2_key'];
            }
        }

        $total_uploaded = count($uploaded_files);
        $total_failed = count($failed_files);

        if ($total_failed === 0) {
            Logger::log('info', "Successfully uploaded attachment {$attachment_id}: {$total_uploaded} files");
            update_post_meta($attachment_id, '_numos_r2_status', 'synced');
            delete_transient('numos_r2_synced_paths');

            // Auto-delete local files if setting is enabled
            if (!empty($this->settings['remove_local_files'])) {
                $cleaner = new LocalCleaner($this->r2_client);
                $del_result = $cleaner->delete_local_files($attachment_id);
                if (!$del_result['success']) {
                    Logger::log('warning', "Auto-delete failed for attachment {$attachment_id}: " . implode(', ', $del_result['errors']));
                }
            }
        } else {
            Logger::log('warning', "Partially uploaded attachment {$attachment_id}: {$total_uploaded} success, {$total_failed} failed");
            update_post_meta($attachment_id, '_numos_r2_status', 'partial');
            update_post_meta($attachment_id, '_numos_r2_failed_files', $failed_files);
        }

        return $metadata;
    }

    /**
     * Handle attachment deletion - delete from R2 too
     *
     * @param int $attachment_id Attachment ID
     */
    public function handle_delete(int $attachment_id): void {
        $metadata = wp_get_attachment_metadata($attachment_id);
        $local_path = get_attached_file($attachment_id);

        if (!$local_path) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = dirname($local_path);

        // Delete main file from R2
        $r2_key = $this->get_r2_key($local_path, $upload_dir['basedir']);
        if (!$this->r2_client->delete_object($r2_key)) {
            Logger::log('error', "Failed to delete {$r2_key} from R2 during attachment {$attachment_id} deletion");
        }

        // Delete all thumbnails from R2
        if (is_array($metadata) && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_data) {
                $thumb_path = $base_dir . '/' . $size_data['file'];
                $thumb_key = $this->get_r2_key($thumb_path, $upload_dir['basedir']);
                if (!$this->r2_client->delete_object($thumb_key)) {
                    Logger::log('error', "Failed to delete {$thumb_key} from R2 during attachment {$attachment_id} deletion");
                }
            }
        }

        // Delete original if exists
        if (is_array($metadata) && !empty($metadata['original_image'])) {
            $original_path = $base_dir . '/' . $metadata['original_image'];
            $original_key = $this->get_r2_key($original_path, $upload_dir['basedir']);
            if (!$this->r2_client->delete_object($original_key)) {
                Logger::log('error', "Failed to delete {$original_key} from R2 during attachment {$attachment_id} deletion");
            }
        }

        // Clean up meta
        delete_post_meta($attachment_id, '_numos_r2_key');
        delete_post_meta($attachment_id, '_numos_r2_url');
        delete_post_meta($attachment_id, '_numos_r2_synced');
        delete_post_meta($attachment_id, '_numos_r2_status');
        delete_transient('numos_r2_synced_paths');

        Logger::log('info', "Deleted attachment {$attachment_id} from R2");
    }

    /**
     * Upload a single attachment to R2 (for migration)
     *
     * @param int $attachment_id Attachment ID
     * @return array Result with 'success', 'files_uploaded', 'files_failed'
     */
    public function upload_attachment(int $attachment_id): array {
        $result = [
            'success' => false,
            'files_uploaded' => 0,
            'files_failed' => 0,
            'error' => null,
        ];

        // Check if R2 is available
        if (!$this->r2_client->is_available()) {
            $result['error'] = 'R2 not available';
            return $result;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        $local_path = get_attached_file($attachment_id);

        if (!$local_path || !file_exists($local_path)) {
            $result['error'] = 'Local file not found';
            return $result;
        }

        // Validate file type
        if (!$this->is_allowed_file($local_path)) {
            $result['error'] = 'File type not allowed';
            return $result;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = dirname($local_path);

        // Collect all files to upload
        $files_to_upload = [];

        // Main file
        $files_to_upload[] = $local_path;

        // Thumbnails
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_data) {
                $thumb_path = $base_dir . '/' . $size_data['file'];
                if (file_exists($thumb_path)) {
                    $files_to_upload[] = $thumb_path;
                }
            }
        }

        // Original image
        if (!empty($metadata['original_image'])) {
            $original_path = $base_dir . '/' . $metadata['original_image'];
            if (file_exists($original_path)) {
                $files_to_upload[] = $original_path;
            }
        }

        // Build parallel upload list
        $parallel_files = [];
        $main_r2_key = null;
        foreach ($files_to_upload as $file_path) {
            $r2_key = $this->get_r2_key($file_path, $upload_dir['basedir']);
            $parallel_files[] = ['local_path' => $file_path, 'r2_key' => $r2_key];
            if ($file_path === $local_path) {
                $main_r2_key = $r2_key;
            }
        }

        // Upload all files in parallel (6 concurrent)
        $upload_results = $this->r2_client->upload_files_parallel($parallel_files);

        foreach ($parallel_files as $file_info) {
            $ur = $upload_results[$file_info['r2_key']] ?? null;
            if ($ur && $ur['success']) {
                $result['files_uploaded']++;
                if ($file_info['r2_key'] === $main_r2_key) {
                    update_post_meta($attachment_id, '_numos_r2_key', $main_r2_key);
                    update_post_meta($attachment_id, '_numos_r2_url', $ur['url']);
                }
            } else {
                $result['files_failed']++;
            }
        }

        // Update status
        if ($result['files_failed'] === 0) {
            update_post_meta($attachment_id, '_numos_r2_synced', time());
            update_post_meta($attachment_id, '_numos_r2_status', 'synced');
            delete_transient('numos_r2_synced_paths');
            $result['success'] = true;

            // Auto-delete local files if setting is enabled
            if (!empty($this->settings['remove_local_files'])) {
                $cleaner = new LocalCleaner($this->r2_client);
                $del_result = $cleaner->delete_local_files($attachment_id);
                if (!$del_result['success']) {
                    Logger::log('warning', "Auto-delete failed for attachment {$attachment_id}: " . implode(', ', $del_result['errors']));
                }
            }
        } else {
            update_post_meta($attachment_id, '_numos_r2_status', 'partial');
        }

        return $result;
    }

    /**
     * Upload multiple attachments in a single parallel batch
     *
     * Collects ALL files from ALL attachments and uploads them in one curl_multi call.
     *
     * @param array $attachment_ids Array of attachment IDs
     * @return array Keyed by attachment ID: ['status' => 'synced'|'partial'|'skipped', 'error' => string|null, 'files_uploaded' => int, 'files_failed' => int]
     */
    public function upload_attachments_batch(array $attachment_ids): array {
        $results = [];
        $all_files = [];
        $file_map = []; // r2_key => [['attachment_id' => int, 'is_main' => bool], ...]
        $validated_ids = []; // attachment IDs that passed validation
        $upload_dir = wp_upload_dir();

        // Phase 1: Validate and collect all files from all attachments
        foreach ($attachment_ids as $attachment_id) {
            $attachment_id = (int)$attachment_id;
            $metadata = wp_get_attachment_metadata($attachment_id);
            $local_path = get_attached_file($attachment_id);

            // Validate: file exists
            if (!$local_path || !file_exists($local_path)) {
                $results[$attachment_id] = [
                    'status' => 'skipped',
                    'error' => 'Local file not found',
                    'files_uploaded' => 0,
                    'files_failed' => 0,
                ];
                continue;
            }

            // Validate: file type allowed
            if (!$this->is_allowed_file($local_path)) {
                $results[$attachment_id] = [
                    'status' => 'skipped',
                    'error' => 'File type not allowed',
                    'files_uploaded' => 0,
                    'files_failed' => 0,
                ];
                continue;
            }

            $validated_ids[] = $attachment_id;
            $base_dir = dirname($local_path);

            // Main file
            $main_r2_key = $this->get_r2_key($local_path, $upload_dir['basedir']);
            if (!isset($file_map[$main_r2_key])) {
                $all_files[] = ['local_path' => $local_path, 'r2_key' => $main_r2_key];
                $file_map[$main_r2_key] = [];
            }
            $file_map[$main_r2_key][] = ['attachment_id' => $attachment_id, 'is_main' => true];

            // Thumbnails
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size_data) {
                    $thumb_path = $base_dir . '/' . $size_data['file'];
                    if (file_exists($thumb_path)) {
                        $thumb_key = $this->get_r2_key($thumb_path, $upload_dir['basedir']);
                        if (!isset($file_map[$thumb_key])) {
                            $all_files[] = ['local_path' => $thumb_path, 'r2_key' => $thumb_key];
                            $file_map[$thumb_key] = [];
                        }
                        $file_map[$thumb_key][] = ['attachment_id' => $attachment_id, 'is_main' => false];
                    }
                }
            }

            // Original image
            if (!empty($metadata['original_image'])) {
                $original_path = $base_dir . '/' . $metadata['original_image'];
                if (file_exists($original_path)) {
                    $original_key = $this->get_r2_key($original_path, $upload_dir['basedir']);
                    if (!isset($file_map[$original_key])) {
                        $all_files[] = ['local_path' => $original_path, 'r2_key' => $original_key];
                        $file_map[$original_key] = [];
                    }
                    $file_map[$original_key][] = ['attachment_id' => $attachment_id, 'is_main' => false];
                }
            }
        }

        // Phase 2: Upload ALL files in one parallel call (deduplicated by r2_key)
        if (!empty($all_files)) {
            $upload_results = $this->r2_client->upload_files_parallel($all_files, 10);
        } else {
            $upload_results = [];
        }

        // Phase 3: Dispatch results per attachment
        // Initialize counters for all validated attachments (prevents missing results)
        $per_attachment = [];
        foreach ($validated_ids as $aid) {
            $per_attachment[$aid] = ['uploaded' => 0, 'failed' => 0, 'main_url' => null, 'main_r2_key' => null];
        }

        foreach ($file_map as $r2_key => $entries) {
            $ur = $upload_results[$r2_key] ?? null;
            $success = $ur && $ur['success'];

            foreach ($entries as $entry) {
                $aid = $entry['attachment_id'];
                if ($success) {
                    $per_attachment[$aid]['uploaded']++;
                    if ($entry['is_main']) {
                        $per_attachment[$aid]['main_url'] = $ur['url'];
                        $per_attachment[$aid]['main_r2_key'] = $r2_key;
                    }
                } else {
                    $per_attachment[$aid]['failed']++;
                }
            }
        }

        // Phase 4: Update meta per attachment
        foreach ($per_attachment as $aid => $counts) {
            if ($counts['failed'] === 0) {
                // All files succeeded
                if ($counts['main_r2_key']) {
                    update_post_meta($aid, '_numos_r2_key', $counts['main_r2_key']);
                    update_post_meta($aid, '_numos_r2_url', $counts['main_url']);
                }
                update_post_meta($aid, '_numos_r2_synced', time());
                update_post_meta($aid, '_numos_r2_status', 'synced');

                $results[$aid] = [
                    'status' => 'synced',
                    'error' => null,
                    'files_uploaded' => $counts['uploaded'],
                    'files_failed' => 0,
                ];

                // Auto-delete local files if setting is enabled
                if (!empty($this->settings['remove_local_files'])) {
                    $cleaner = new LocalCleaner($this->r2_client);
                    $del_result = $cleaner->delete_local_files($aid);
                    if (!$del_result['success']) {
                        Logger::log('warning', "Auto-delete failed for attachment {$aid}: " . implode(', ', $del_result['errors']));
                    }
                }
            } else {
                // Some or all files failed
                update_post_meta($aid, '_numos_r2_status', 'partial');

                $results[$aid] = [
                    'status' => 'partial',
                    'error' => "{$counts['failed']} file(s) failed to upload",
                    'files_uploaded' => $counts['uploaded'],
                    'files_failed' => $counts['failed'],
                ];
            }
        }

        delete_transient('numos_r2_synced_paths');

        return $results;
    }

    /**
     * Get R2 key from local path
     *
     * @param string $local_path Local file path
     * @param string $base_dir Upload base directory
     * @return string R2 key
     */
    private function get_r2_key(string $local_path, string $base_dir): string {
        // Convert Windows paths
        $local_path = str_replace('\\', '/', $local_path);
        $base_dir = str_replace('\\', '/', $base_dir);

        // Get relative path from uploads directory
        $relative_path = str_replace($base_dir, '', $local_path);
        $relative_path = ltrim($relative_path, '/');

        // Add site prefix to avoid conflicts between sites
        $prefix = numos_r2_get_site_prefix();

        return $prefix . '/' . $relative_path;
    }

    /**
     * Extensions explicitly blocked (security risk - can contain executable code)
     */
    private const BLOCKED_EXTENSIONS = ['svg', 'svgz', 'html', 'htm', 'js', 'php', 'phtml', 'exe', 'sh', 'bat'];

    /**
     * Check if file type is allowed
     *
     * @param string $file_path File path
     * @return bool
     */
    private function is_allowed_file(string $file_path): bool {
        // Check file exists and is readable
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        // Block dangerous extensions
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return false;
        }

        // Use WordPress's own allowed file type check
        $mime_check = wp_check_filetype($file_path);
        if (empty($mime_check['type'])) {
            Logger::log('warning', "MIME type not recognized by WordPress for: {$file_path}");
            return false;
        }

        return true;
    }

    /**
     * Check if an attachment is synced to R2
     *
     * @param int $attachment_id Attachment ID
     * @return bool
     */
    public function is_synced(int $attachment_id): bool {
        $status = get_post_meta($attachment_id, '_numos_r2_status', true);
        return $status === 'synced';
    }
}
