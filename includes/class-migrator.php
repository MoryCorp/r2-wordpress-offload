<?php
namespace NumosR2;

defined('ABSPATH') || exit;

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
    const LOCK_TTL = 600; // must exceed one batch; a single PUT alone can run up to 280s

    public function __construct(R2Client $r2_client) {
        $this->r2_client = $r2_client;
    }

    private function get_batch_size(): int {
        if ($this->cached_settings === null) {
            $this->cached_settings = get_option('numos_r2_settings', []);
        }

        $batch_size = $this->cached_settings['batch_size'] ?? self::DEFAULT_BATCH_SIZE;
        return max(10, min(200, (int)$batch_size));
    }

    public function set_media_handler(MediaHandler $handler): void {
        $this->media_handler = $handler;
    }

    public function is_migration_locked(): bool {
        return (bool)get_transient(self::LOCK_KEY);
    }

    public function acquire_lock(): bool {
        // wp_cache_add is atomic with a persistent object cache; the transient fallback has a
        // check-then-set race, accepted without one.
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

    public function extend_lock(): void {
        set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);
    }

    public function release_lock(): void {
        delete_transient(self::LOCK_KEY);
    }

    public function get_pending_count(): int {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(DISTINCT p.ID) ' . $this->pending_clauses());
    }

    /**
     * FROM and WHERE clauses selecting the attachments still to migrate: neither synced nor
     * skipped.
     */
    private function pending_clauses(): string {
        global $wpdb;

        return $wpdb->prepare("
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_numos_r2_status'
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND (pm.meta_value IS NULL OR pm.meta_value NOT IN (%s, %s))
        ", 'synced', 'skipped');
    }

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

    public function get_pending_attachments(?int $batch_size = null, int $offset = 0): array {
        $batch_size = $batch_size ?? $this->get_batch_size();
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT p.ID ' . $this->pending_clauses() . ' ORDER BY p.ID ASC LIMIT %d OFFSET %d',
            $batch_size,
            $offset
        ));

        if (empty($ids)) {
            return [];
        }

        update_meta_cache('post', $ids);

        return array_map('get_post', $ids);
    }

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

        if (!$this->r2_client->is_available()) {
            $result['errors'][] = 'R2 is not available';
            return $result;
        }

        $this->extend_lock();

        $attachments = $this->get_pending_attachments($batch_size);

        if (empty($attachments)) {
            $result['done'] = true;
            $this->clear_migration_state();
            $this->release_lock();
            return $result;
        }

        if (!$this->media_handler) {
            $this->media_handler = new MediaHandler($this->r2_client);
        }

        $attachment_ids = array_map(function ($a) { return $a->ID; }, $attachments);

        $batch_results = $this->media_handler->upload_attachments_batch($attachment_ids);

        foreach ($attachments as $attachment) {
            $result['processed']++;
            $aid = $attachment->ID;
            $ar = $batch_results[$aid] ?? null;

            if (!$ar) {
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
                $result['failed']++;
                $error_msg = $ar['error'] ?? 'Upload failed';
                update_post_meta($aid, '_numos_r2_error', $error_msg);
                update_post_meta($aid, '_numos_r2_error_time', time());
                $result['errors'][] = "Attachment {$aid}: {$error_msg}";
                Logger::log('error', "Failed to migrate attachment {$aid}: {$error_msg}");
            }

        }

        if (!empty($attachments)) {
            $last = end($attachments);
            $this->update_checkpoint($last->ID);
        }

        $this->update_stats($result);

        $this->invalidate_progress_cache();

        $remaining = $this->get_pending_count();
        $result['done'] = ($remaining === 0);

        if ($result['done']) {
            $this->clear_migration_state();
            $this->release_lock();
            Logger::log('info', 'Migration completed');
        }

        return $result;
    }

    public function get_progress(bool $force_refresh = false): array {
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

        set_transient(self::PROGRESS_CACHE_KEY, $progress, self::PROGRESS_CACHE_TTL);

        return $progress;
    }

    public function invalidate_progress_cache(): void {
        delete_transient(self::PROGRESS_CACHE_KEY);
    }

    /**
     * Forget everything recorded about storage: every _numos_r2_ meta (manifests included),
     * migration state and caches. Callers check first that no file only exists in storage.
     *
     * @return int Deleted meta rows
     */
    public function purge_metadata(): int {
        global $wpdb;
        $deleted = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('_numos_r2_') . '%'
        ));

        delete_option('numos_r2_migration_checkpoint');
        delete_option('numos_r2_migration_stats');
        delete_transient('numos_r2_health_status');
        delete_transient(self::STATS_CACHE_KEY);
        UrlRewriter::forget_map();
        $this->invalidate_progress_cache();
        return $deleted;
    }

    public function get_stats(): array {
        $stats = get_option(self::STATS_OPTION, [
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'last_run' => null,
            'started_at' => null,
        ]);

        $progress = $this->get_progress();

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

    public function start_migration(): bool {
        if (!$this->acquire_lock()) {
            return false;
        }

        update_option(self::STATS_OPTION, [
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'last_run' => null,
            'started_at' => time(),
        ]);

        delete_option(self::CHECKPOINT_OPTION);

        Logger::log('info', 'Migration started');
        return true;
    }

    /**
     * Clear the status of failed, partial and skipped attachments so the next migration retries them.
     *
     * @return int Deleted meta rows
     */
    public function reset_failed(): int {
        global $wpdb;

        $failed_ids = $wpdb->get_col($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_numos_r2_status'
            AND meta_value IN (%s, %s, %s)
        ", 'failed', 'partial', 'skipped'));

        if (empty($failed_ids)) {
            return 0;
        }

        // Chunks keep the IN () list under max_allowed_packet.
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

        $this->invalidate_progress_cache();

        Logger::log('info', "Reset {$count} failed items for retry");

        return $count;
    }

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

    private function update_checkpoint(int $attachment_id): void {
        update_option(self::CHECKPOINT_OPTION, [
            'last_id' => $attachment_id,
            'timestamp' => time(),
        ]);
    }

    public function get_checkpoint(): ?array {
        return get_option(self::CHECKPOINT_OPTION, null);
    }

    private function clear_migration_state(): void {
        delete_option(self::CHECKPOINT_OPTION);

        $stats = get_option(self::STATS_OPTION, []);
        $stats['completed_at'] = time();
        update_option(self::STATS_OPTION, $stats);

        $duration = isset($stats['started_at']) ? (time() - $stats['started_at']) : 0;
        update_option(self::LAST_MIGRATION_OPTION, [
            'completed_at' => time(),
            'files_count' => $stats['total_success'] ?? 0,
            'duration' => $duration,
        ]);

        delete_transient(self::STATS_CACHE_KEY);
    }

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
     * Attachments neither synced nor skipped whose main file is missing locally, among the first
     * $limit of them.
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
     * Exact up to 1000 attachments neither synced nor skipped, a lower bound beyond.
     */
    public function count_db_orphans(): int {
        return count($this->get_db_orphans(1000));
    }

    public function delete_db_orphans(array $ids): array {
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            $id = (int)$id;

            // Must be an attachment, never anything else handed in via a forged request.
            if (get_post_type($id) !== 'attachment') {
                $errors[] = "Attachment {$id}: not an attachment, skipped";
                continue;
            }

            $file = get_attached_file($id);
            if ($file && file_exists($file)) {
                $errors[] = "Attachment {$id}: File exists, skipped";
                continue;
            }

            // A missing local file is not an orphan when the media lives on R2: deleting the
            // attachment would fire the R2 delete hook and destroy the only copy.
            if (Manifest::has_trace($id)) {
                $errors[] = "Attachment {$id}: offloaded to R2, not an orphan (restore or reconcile)";
                continue;
            }

            $result = wp_delete_attachment($id, true);

            if ($result) {
                $deleted++;
                Logger::log('info', "Deleted orphan database entry: {$id}");
            } else {
                $errors[] = "Attachment {$id}: Failed to delete";
            }
        }

        $this->invalidate_progress_cache();

        Logger::log('info', "Cleaned {$deleted} orphan database entries");

        return [
            'deleted' => $deleted,
            'errors' => $errors,
        ];
    }
}
