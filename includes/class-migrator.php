<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * Migrator - Handles batch migration of existing media to R2
 */
class Migrator {

    private R2Client $r2_client;
    private ?MediaHandler $media_handler = null;
    private ?array $cached_settings = null;

    const DEFAULT_BATCH_SIZE = 50;
    const CHECKPOINT_OPTION = 'numos_r2_migration_checkpoint';
    const STATS_OPTION = 'numos_r2_migration_stats';
    const LAST_MIGRATION_OPTION = 'numos_r2_last_migration';
    const PROGRESS_CACHE_KEY = 'numos_r2_progress_cache';
    const STATS_CACHE_KEY = 'numos_r2_stats_cache';
    const LOCK_KEY = 'numos_r2_migration_lock';
    const PROGRESS_CACHE_TTL = 300; // 5 minutes
    const STATS_CACHE_TTL = 600; // 10 minutes
    const LOCK_TTL = 120; // 2 minutes - auto-expire if process dies

    public function __construct(R2Client $r2_client) {
        $this->r2_client = $r2_client;
    }

    /**
     * Get configured batch size
     *
     * @return int
     */
    private function get_batch_size(): int {
        if ($this->cached_settings === null) {
            $this->cached_settings = get_option('numos_r2_settings', []);
        }

        $batch_size = $this->cached_settings['batch_size'] ?? self::DEFAULT_BATCH_SIZE;
        return max(10, min(200, (int)$batch_size));
    }

    /**
     * Set media handler for uploads
     */
    public function set_media_handler(MediaHandler $handler): void {
        $this->media_handler = $handler;
    }

    /**
     * Check if a migration is currently locked
     *
     * @return bool
     */
    public function is_migration_locked(): bool {
        return (bool)get_transient(self::LOCK_KEY);
    }

    /**
     * Acquire migration lock
     *
     * @return bool True if lock acquired, false if already locked
     */
    public function acquire_lock(): bool {
        // Atomic: wp_cache_add returns false if key already exists (works with object caches)
        // Fallback: check transient then set (TOCTOU acceptable for non-object-cache setups)
        if (wp_using_ext_object_cache()) {
            if (!wp_cache_add(self::LOCK_KEY, time(), 'transient', self::LOCK_TTL)) {
                return false;
            }
            set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);
            return true;
        }

        if ($this->is_migration_locked()) {
            return false;
        }
        set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);
        return true;
    }

    /**
     * Extend migration lock (call during batch processing)
     */
    public function extend_lock(): void {
        set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);
    }

    /**
     * Release migration lock
     */
    public function release_lock(): void {
        delete_transient(self::LOCK_KEY);
    }

    /**
     * Get total count of attachments that need migration
     *
     * @return int
     */
    public function get_pending_count(): int {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_numos_r2_status'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND (pm.meta_value IS NULL OR pm.meta_value NOT IN (%s, %s))
        ", 'synced', 'skipped'));

        return (int)$count;
    }

    /**
     * Get total count of all attachments
     *
     * @return int
     */
    public function get_total_count(): int {
        global $wpdb;

        return (int)$wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_numos_r2_status'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND (pm.meta_value IS NULL OR pm.meta_value != %s)
        ", 'skipped'));
    }

    /**
     * Get count of synced attachments
     *
     * @return int
     */
    public function get_synced_count(): int {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND pm.meta_key = '_numos_r2_status'
            AND pm.meta_value = %s
        ", 'synced'));

        return (int)$count;
    }

    /**
     * Get attachments that need migration
     *
     * @param int|null $batch_size Number of attachments to get (null = use setting)
     * @param int $offset Offset for pagination
     * @return array
     */
    public function get_pending_attachments(?int $batch_size = null, int $offset = 0): array {
        $batch_size = $batch_size ?? $this->get_batch_size();
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_numos_r2_status'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND (pm.meta_value IS NULL OR pm.meta_value NOT IN (%s, %s))
            ORDER BY p.ID ASC
            LIMIT %d OFFSET %d
        ", 'synced', 'skipped', $batch_size, $offset));

        if (empty($ids)) {
            return [];
        }

        // Prime meta cache to avoid N+1 queries in batch processing
        update_meta_cache('post', $ids);

        return array_map('get_post', $ids);
    }

    /**
     * Migrate a batch of attachments
     *
     * @param int|null $batch_size Number of attachments to migrate (null = use setting)
     * @return array Migration result
     */
    public function migrate_batch(?int $batch_size = null): array {
        $batch_size = $batch_size ?? $this->get_batch_size();

        $result = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'done' => false,
        ];

        // Check if R2 is available
        if (!$this->r2_client->is_available()) {
            $result['errors'][] = 'R2 is not available';
            return $result;
        }

        // Extend lock for this batch
        $this->extend_lock();

        // Get pending attachments
        $attachments = $this->get_pending_attachments($batch_size);

        if (empty($attachments)) {
            $result['done'] = true;
            $this->clear_migration_state();
            $this->release_lock();
            return $result;
        }

        // Ensure we have a media handler
        if (!$this->media_handler) {
            $this->media_handler = new MediaHandler($this->r2_client);
        }

        // Collect all attachment IDs for batch upload
        $attachment_ids = array_map(function ($a) { return $a->ID; }, $attachments);

        // Upload ALL attachments in one parallel batch
        $batch_results = $this->media_handler->upload_attachments_batch($attachment_ids);

        // Process results
        foreach ($attachments as $attachment) {
            $result['processed']++;
            $aid = $attachment->ID;
            $ar = $batch_results[$aid] ?? null;

            if (!$ar) {
                // Should not happen, but handle gracefully
                $result['failed']++;
                $result['errors'][] = "Attachment {$aid}: No result returned";
                Logger::log('error', "No batch result for attachment {$aid}");
                continue;
            }

            if ($ar['status'] === 'synced') {
                $result['success']++;
                Logger::log('info', "Migrated attachment {$aid}");
            } elseif ($ar['status'] === 'skipped') {
                $result['skipped']++;
                $error_msg = $ar['error'] ?? 'Unknown skip reason';
                update_post_meta($aid, '_numos_r2_status', 'skipped');
                update_post_meta($aid, '_numos_r2_skip_reason', $error_msg);
                update_post_meta($aid, '_numos_r2_error', $error_msg);
                update_post_meta($aid, '_numos_r2_error_time', time());
                $result['errors'][] = "Attachment {$aid}: {$error_msg}";
                Logger::log('info', "Skipped attachment {$aid}: {$error_msg}");
            } else {
                // partial or other failure
                $result['failed']++;
                $error_msg = $ar['error'] ?? 'Upload failed';
                update_post_meta($aid, '_numos_r2_error', $error_msg);
                update_post_meta($aid, '_numos_r2_error_time', time());
                $result['errors'][] = "Attachment {$aid}: {$error_msg}";
                Logger::log('error', "Failed to migrate attachment {$aid}: {$error_msg}");
            }

        }

        // Update checkpoint once per batch (last processed ID)
        if (!empty($attachments)) {
            $last = end($attachments);
            $this->update_checkpoint($last->ID);
        }

        // Update migration stats
        $this->update_stats($result);

        // Invalidate progress cache after changes
        $this->invalidate_progress_cache();

        // Check if we're done (force refresh to get accurate count)
        $remaining = $this->get_pending_count();
        $result['done'] = ($remaining === 0);

        if ($result['done']) {
            $this->clear_migration_state();
            $this->release_lock();
            Logger::log('info', 'Migration completed');
        }

        return $result;
    }

    /**
     * Get migration progress (cached)
     *
     * @param bool $force_refresh Force cache refresh
     * @return array
     */
    public function get_progress(bool $force_refresh = false): array {
        // Try to get from cache first
        if (!$force_refresh) {
            $cached = get_transient(self::PROGRESS_CACHE_KEY);
            if ($cached !== false) {
                return $cached;
            }
        }

        $total = $this->get_total_count();
        $synced = $this->get_synced_count();
        $pending = $this->get_pending_count();

        $progress = [
            'total' => $total,
            'synced' => $synced,
            'pending' => $pending,
            'percentage' => $total > 0 ? round(($synced / $total) * 100, 1) : 0,
            'cached_at' => time(),
        ];

        // Cache the result
        set_transient(self::PROGRESS_CACHE_KEY, $progress, self::PROGRESS_CACHE_TTL);

        return $progress;
    }

    /**
     * Invalidate progress cache (call after sync operations)
     */
    public function invalidate_progress_cache(): void {
        delete_transient(self::PROGRESS_CACHE_KEY);
    }

    /**
     * Get migration stats (with caching for synced size)
     *
     * @return array
     */
    public function get_stats(): array {
        $stats = get_option(self::STATS_OPTION, [
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'last_run' => null,
            'started_at' => null,
        ]);

        $progress = $this->get_progress();

        // Get cached synced size or calculate
        $cached_stats = get_transient(self::STATS_CACHE_KEY);
        if ($cached_stats !== false && isset($cached_stats['synced_size'])) {
            $synced_size = $cached_stats['synced_size'];
        } else {
            $synced_size = $this->calculate_synced_size();
            set_transient(self::STATS_CACHE_KEY, ['synced_size' => $synced_size], self::STATS_CACHE_TTL);
        }

        $stats['synced_size'] = $synced_size;
        $stats['synced_size_human'] = size_format($synced_size);

        return array_merge($stats, $progress);
    }

    /**
     * Start a new migration
     *
     * @return bool True if started, false if already locked
     */
    public function start_migration(): bool {
        if (!$this->acquire_lock()) {
            return false;
        }

        // Reset stats
        update_option(self::STATS_OPTION, [
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'last_run' => null,
            'started_at' => time(),
        ]);

        // Clear checkpoint
        delete_option(self::CHECKPOINT_OPTION);

        Logger::log('info', 'Migration started');
        return true;
    }

    /**
     * Reset migration state (to retry failed items)
     */
    public function reset_failed(): int {
        global $wpdb;

        // First, get the IDs of failed/partial/skipped items
        $failed_ids = $wpdb->get_col($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_numos_r2_status'
            AND meta_value IN (%s, %s, %s)
        ", 'failed', 'partial', 'skipped'));

        if (empty($failed_ids)) {
            return 0;
        }

        // Sanitize IDs and process in chunks to avoid max_allowed_packet overflow
        $failed_ids = array_map('intval', $failed_ids);
        $count = 0;

        foreach (array_chunk($failed_ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $count += (int)$wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta}
                WHERE meta_key IN ('_numos_r2_status', '_numos_r2_error', '_numos_r2_error_time', '_numos_r2_skip_reason')
                AND post_id IN ({$placeholders})",
                ...$chunk
            ));
        }

        // Invalidate cache after status changes
        $this->invalidate_progress_cache();

        Logger::log('info', "Reset {$count} failed items for retry");

        return $count;
    }

    /**
     * Get failed attachments
     *
     * @param int $limit Number of items to get
     * @return array
     */
    public function get_failed_attachments(int $limit = 100): array {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                p.ID,
                p.post_title,
                pm_error.meta_value as error_message,
                pm_time.meta_value as error_time
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_error
                ON p.ID = pm_error.post_id
                AND pm_error.meta_key = '_numos_r2_error'
            LEFT JOIN {$wpdb->postmeta} pm_time
                ON p.ID = pm_time.post_id
                AND pm_time.meta_key = '_numos_r2_error_time'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            ORDER BY pm_time.meta_value DESC
            LIMIT %d
        ", $limit));

        $failed = [];
        foreach ($results as $row) {
            $failed[] = [
                'id' => $row->ID,
                'title' => $row->post_title,
                'error' => $row->error_message,
                'error_time' => $row->error_time,
            ];
        }

        return $failed;
    }

    /**
     * Update checkpoint
     *
     * @param int $attachment_id Last processed attachment ID
     */
    private function update_checkpoint(int $attachment_id): void {
        update_option(self::CHECKPOINT_OPTION, [
            'last_id' => $attachment_id,
            'timestamp' => time(),
        ]);
    }

    /**
     * Get checkpoint
     *
     * @return array|null
     */
    public function get_checkpoint(): ?array {
        return get_option(self::CHECKPOINT_OPTION, null);
    }

    /**
     * Clear migration state
     */
    private function clear_migration_state(): void {
        delete_option(self::CHECKPOINT_OPTION);

        $stats = get_option(self::STATS_OPTION, []);
        $stats['completed_at'] = time();
        update_option(self::STATS_OPTION, $stats);

        // Save last migration info for status band
        $duration = isset($stats['started_at']) ? (time() - $stats['started_at']) : 0;
        update_option(self::LAST_MIGRATION_OPTION, [
            'completed_at' => time(),
            'files_count' => $stats['total_success'] ?? 0,
            'duration' => $duration,
        ]);

        // Invalidate stats cache
        delete_transient(self::STATS_CACHE_KEY);
    }

    /**
     * Update migration stats
     *
     * @param array $batch_result Batch result
     */
    private function update_stats(array $batch_result): void {
        $stats = get_option(self::STATS_OPTION, [
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'started_at' => time(),
        ]);

        $stats['total_processed'] += $batch_result['processed'];
        $stats['total_success'] += $batch_result['success'];
        $stats['total_failed'] += $batch_result['failed'];
        $stats['last_run'] = time();

        update_option(self::STATS_OPTION, $stats);
    }

    /**
     * Calculate total size of synced files
     *
     * @return int Size in bytes
     */
    public function calculate_synced_size(): int {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                pm_status.post_id,
                pm_file.meta_value as attached_file,
                pm_meta.meta_value as attachment_metadata
            FROM {$wpdb->postmeta} pm_status
            LEFT JOIN {$wpdb->postmeta} pm_file
                ON pm_status.post_id = pm_file.post_id
                AND pm_file.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->postmeta} pm_meta
                ON pm_status.post_id = pm_meta.post_id
                AND pm_meta.meta_key = '_wp_attachment_metadata'
            WHERE pm_status.meta_key = '_numos_r2_status'
            AND pm_status.meta_value = %s
        ", 'synced'));

        if (empty($results)) {
            return 0;
        }

        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'];
        $total_size = 0;

        foreach ($results as $row) {
            if (empty($row->attached_file)) {
                continue;
            }

            $file = $base_path . '/' . $row->attached_file;

            if (file_exists($file)) {
                $total_size += filesize($file);

                // Add thumbnail sizes from metadata
                if (!empty($row->attachment_metadata)) {
                    $metadata = maybe_unserialize($row->attachment_metadata);
                    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                        $base_dir = dirname($file);
                        foreach ($metadata['sizes'] as $size_data) {
                            $thumb_path = $base_dir . '/' . $size_data['file'];
                            if (file_exists($thumb_path)) {
                                $total_size += filesize($thumb_path);
                            }
                        }
                    }
                }
            }
        }

        return $total_size;
    }

    /**
     * Get database orphans (attachment entries without physical files)
     *
     * @param int $limit Maximum number to return
     * @return array
     */
    public function get_db_orphans(int $limit = 500): array {
        global $wpdb;

        $attachments = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT
                p.ID,
                p.post_title,
                p.post_mime_type,
                p.post_date,
                pm_file.meta_value as attached_file
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_numos_r2_status'
            LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND (pm.meta_value IS NULL OR pm.meta_value NOT IN (%s, %s))
            ORDER BY p.ID ASC
            LIMIT %d
        ", 'synced', 'skipped', $limit));

        if (empty($attachments)) {
            return [];
        }

        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'];
        $orphans = [];

        foreach ($attachments as $attachment) {
            $file = !empty($attachment->attached_file)
                ? $base_path . '/' . $attachment->attached_file
                : null;

            // Check if file exists
            if (!$file || !file_exists($file)) {
                $orphans[] = [
                    'id' => $attachment->ID,
                    'title' => $attachment->post_title,
                    'mime_type' => $attachment->post_mime_type,
                    'expected_path' => $file ?: 'unknown',
                    'date' => $attachment->post_date,
                ];
            }
        }

        return $orphans;
    }

    /**
     * Count database orphans (uses efficient COUNT query)
     *
     * @return int
     */
    public function count_db_orphans(): int {
        global $wpdb;

        // Get total unsynchronized attachment count
        $total_unsynced = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_numos_r2_status'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND (pm.meta_value IS NULL OR pm.meta_value NOT IN (%s, %s))
        ", 'synced', 'skipped'));

        // If few unsynced items, check each one (fast enough)
        if ((int)$total_unsynced <= 500) {
            return count($this->get_db_orphans(500));
        }

        // For large numbers, sample to estimate
        return count($this->get_db_orphans(1000));
    }

    /**
     * Delete database orphan entries
     *
     * @param array $ids Attachment IDs to delete
     * @return array Result with deleted count and errors
     */
    public function delete_db_orphans(array $ids): array {
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            $id = (int)$id;

            // Verify it's actually an orphan (file doesn't exist)
            $file = get_attached_file($id);
            if ($file && file_exists($file)) {
                $errors[] = "Attachment {$id}: File exists, skipped";
                continue;
            }

            // Delete the attachment (this also deletes meta)
            $result = wp_delete_attachment($id, true);

            if ($result) {
                $deleted++;
                Logger::log('info', "Deleted orphan database entry: {$id}");
            } else {
                $errors[] = "Attachment {$id}: Failed to delete";
            }
        }

        // Invalidate cache after deleting entries
        $this->invalidate_progress_cache();

        Logger::log('info', "Cleaned {$deleted} orphan database entries");

        return [
            'deleted' => $deleted,
            'errors' => $errors,
        ];
    }
}
