<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * Verification, deletion and restore are driven by the manifest and the MD5 it records, never
 * by size alone nor by keys recomputed from the current state. A local file is removed only
 * once its R2 object and the file itself both match the manifest entry, and its deletion is
 * recorded before the unlink.
 */
class LocalCleaner {

    private R2Client $r2_client;

    public function __construct(R2Client $r2_client) {
        $this->r2_client = $r2_client;
    }

    private function basedir(): string {
        return wp_upload_dir()['basedir'];
    }

    /**
     * A multipart ETag (md5-N) and an empty recorded MD5 (backfilled entry) cannot be compared
     * and count as a match: the size check done before is then the only control.
     */
    private function etag_matches(string $etag, string $md5): bool {
        if ($md5 === '' || strpos($etag, '-') !== false) {
            return true;
        }
        return $etag === $md5;
    }

    /**
     * True when the file at $path holds the bytes the manifest entry describes. Without a
     * recorded MD5 (multipart upload of the first release) only the size can be compared.
     */
    private function matches_entry(string $path, array $entry): bool {
        return filesize($path) === (int) $entry['size']
            && ($entry['md5'] === '' || md5_file($path) === $entry['md5']);
    }

    /**
     * Manifest of an attachment, or null with the reason appended to $errors when it has none
     * or when R2 is needed and unreachable.
     */
    private function manifest_for(int $attachment_id, bool $needs_r2, array &$errors): ?array {
        $manifest = Manifest::get($attachment_id);
        if ($manifest === null) {
            $errors[] = "Attachment {$attachment_id} has no R2 manifest";
        } elseif ($needs_r2 && !$this->r2_client->is_available()) {
            $errors[] = 'R2 is not available';
            $manifest = null;
        }
        return $manifest;
    }

    /**
     * Verify every file of an attachment against R2 by size and MD5 (via HEAD).
     *
     * @return array{verified:bool,files:array,errors:string[]}
     */
    public function verify_attachment(int $attachment_id): array {
        $result = ['verified' => false, 'files' => [], 'errors' => []];

        $manifest = $this->manifest_for($attachment_id, true, $result['errors']);
        if ($manifest === null) {
            return $result;
        }

        $all_ok = true;
        foreach ($manifest['files'] as $relative => $entry) {
            $head = $this->r2_client->head_object($entry['key']);
            $info = ['relative' => $relative, 'key' => $entry['key'], 'ok' => false];

            if ($head === false) {
                $info['error'] = 'Not found on R2';
                $result['errors'][] = "Not on R2: {$entry['key']}";
                $all_ok = false;
            } elseif ((int) $head['content_length'] !== (int) $entry['size']) {
                $info['error'] = "Size mismatch: R2={$head['content_length']}, manifest={$entry['size']}";
                $result['errors'][] = "Size mismatch for {$entry['key']}";
                $all_ok = false;
            } elseif (!$this->etag_matches($head['etag'], $entry['md5'])) {
                $info['error'] = 'Content (ETag) mismatch';
                $result['errors'][] = "Content mismatch for {$entry['key']}";
                $all_ok = false;
            } else {
                $info['ok'] = true;
            }

            $result['files'][] = $info;
        }

        $result['verified'] = $all_ok;
        return $result;
    }

    /**
     * Verify all manifests against a single R2 listing (cheaper than per-file HEAD).
     *
     * @return array{ok:int,issues:int,details:array,error?:string}
     */
    public function verify_all(): array {
        $result = ['ok' => 0, 'issues' => 0, 'details' => []];

        $index = $this->r2_client->list_all_objects(numos_r2_get_site_prefix() . '/');
        if ($index === null) {
            $result['error'] = 'Could not list R2 objects';
            return $result;
        }

        foreach ($this->all_manifests() as $id => $manifest) {
            $errors = $this->verify_against_index($manifest, $index);
            if (empty($errors)) {
                $result['ok']++;
            } else {
                $result['issues']++;
                $result['details'][] = ['id' => $id, 'errors' => $errors];
            }
        }

        return $result;
    }

    /**
     * Check a manifest against an R2 listing. Returns the list of problems (empty = all good).
     *
     * @return string[]
     */
    private function verify_against_index(array $manifest, array $index): array {
        $errors = [];
        foreach ($manifest['files'] as $entry) {
            $key = $entry['key'];
            if (!isset($index[$key])) {
                $errors[] = "Not on R2: {$key}";
            } elseif ((int) $index[$key]['size'] !== (int) $entry['size']) {
                $errors[] = "Size mismatch for {$key}";
            } elseif (!$this->etag_matches($index[$key]['etag'], $entry['md5'])) {
                $errors[] = "Content mismatch for {$key}";
            }
        }
        return $errors;
    }

    /**
     * Delete local files for an attachment after confirming each R2 copy.
     *
     * @param bool $skip_verify Caller has already verified against R2 (e.g. a batch listing).
     * @return array{success:bool,deleted_files:int,freed_bytes:int,errors:string[]}
     */
    public function delete_local_files(int $attachment_id, bool $skip_verify = false): array {
        $result = ['success' => false, 'deleted_files' => 0, 'freed_bytes' => 0, 'errors' => []];

        $manifest = $this->manifest_for($attachment_id, false, $result['errors']);
        if ($manifest === null) {
            return $result;
        }

        if (!$skip_verify) {
            $verification = $this->verify_attachment($attachment_id);
            if (!$verification['verified']) {
                $result['errors'] = $verification['errors'];
                return $result;
            }
        }

        $base = $this->basedir();

        // Persist the intent before touching the filesystem, so a crash after an unlink never
        // leaves a file marked as still local.
        $targets = [];
        foreach ($manifest['files'] as $relative => $entry) {
            if ($entry['state'] === Manifest::STATE_LOCAL_DELETED) {
                continue;
            }
            $targets[$relative] = $entry['state'];
            $manifest['files'][$relative]['state'] = Manifest::STATE_DELETING;
        }

        if (empty($targets)) {
            $result['success'] = true;
            $this->refresh_deletion_markers($attachment_id, $manifest);
            return $result;
        }

        if (!Manifest::save($attachment_id, $manifest)) {
            $result['errors'][] = 'Could not persist deletion state; aborting before deletion';
            return $result;
        }
        $this->refresh_deletion_markers($attachment_id, $manifest);

        $freed = 0;
        $deleted = 0;
        $first_dir = null;

        foreach ($targets as $relative => $previous_state) {
            $path = $base . '/' . $relative;
            if ($first_dir === null) {
                $first_dir = dirname($path);
            }

            if (!file_exists($path)) {
                $manifest['files'][$relative]['state'] = Manifest::STATE_LOCAL_DELETED;
                continue;
            }
            // R2 was compared to the manifest, not to this file: an optimizer may have
            // rewritten it since the upload. After an interrupted deletion it may also be
            // another attachment's file under a reused name, so that entry stays protected.
            if (!$this->matches_entry($path, $manifest['files'][$relative])) {
                $result['errors'][] = "Local file differs from the stored copy: {$path}";
                $manifest['files'][$relative]['state'] = $previous_state;
                continue;
            }
            if (!is_writable($path)) {
                $result['errors'][] = "Not writable: {$path}";
                $manifest['files'][$relative]['state'] = Manifest::STATE_UPLOADED;
                continue;
            }

            $size = (int) filesize($path);
            if (unlink($path)) {
                $manifest['files'][$relative]['state'] = Manifest::STATE_LOCAL_DELETED;
                $deleted++;
                $freed += $size;
            } else {
                $result['errors'][] = "Failed to delete: {$path}";
                $manifest['files'][$relative]['state'] = Manifest::STATE_UPLOADED;
            }
        }

        Manifest::save($attachment_id, $manifest);

        if ($first_dir !== null) {
            $this->cleanup_empty_dirs($first_dir);
        }

        $this->refresh_deletion_markers($attachment_id, $manifest);

        $result['deleted_files'] = $deleted;
        $result['freed_bytes'] = $freed;
        $result['success'] = empty($result['errors']) && !$this->has_remaining_local($manifest);

        if ($result['success']) {
            Logger::log('info', "Deleted {$deleted} local files for attachment {$attachment_id}, freed " . size_format($freed));
        }

        return $result;
    }

    /**
     * True while at least one file is still expected to exist locally (state uploaded).
     */
    private function has_remaining_local(array $manifest): bool {
        foreach ($manifest['files'] as $entry) {
            if ($entry['state'] === Manifest::STATE_UPLOADED) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep the marker metas in step with the manifest. The marker is set as long as one file
     * may be missing locally: it is what the restore queue and the guards of every bulk
     * deletion read, so a partially restored or partially deleted attachment must keep it.
     */
    private function refresh_deletion_markers(int $attachment_id, array $manifest): void {
        $remote_only = Manifest::remote_only($manifest);
        if ($remote_only) {
            update_post_meta($attachment_id, '_numos_r2_local_deleted', time());
            update_post_meta($attachment_id, '_numos_r2_local_deleted_size', array_sum(array_column($remote_only, 'size')));
        } else {
            delete_post_meta($attachment_id, '_numos_r2_local_deleted');
            delete_post_meta($attachment_id, '_numos_r2_local_deleted_size');
        }
    }

    /**
     * Delete local files for all synced attachments, verifying against one R2 listing.
     *
     * @return array{deleted:int,freed_bytes:int,skipped:int,errors:string[]}
     */
    public function delete_all_local(): array {
        $result = ['deleted' => 0, 'freed_bytes' => 0, 'skipped' => 0, 'errors' => []];

        if (!$this->r2_client->is_available()) {
            $result['errors'][] = 'R2 is not available; refusing to delete local files';
            return $result;
        }

        $index = $this->r2_client->list_all_objects(numos_r2_get_site_prefix() . '/');
        if (empty($index)) {
            $result['errors'][] = 'R2 index is empty or could not be listed; refusing to delete local files';
            return $result;
        }

        foreach ($this->get_synced_with_local(999999, 0) as $id) {
            $manifest = Manifest::get($id);
            if ($manifest === null) {
                $result['skipped']++;
                continue;
            }

            $errors = $this->verify_against_index($manifest, $index);
            if (!empty($errors)) {
                $result['skipped']++;
                $result['errors'][] = "Attachment {$id}: " . $errors[0];
                continue;
            }

            $del = $this->delete_local_files($id, true);
            if ($del['success']) {
                $result['deleted']++;
                $result['freed_bytes'] += $del['freed_bytes'];
            } else {
                $result['skipped']++;
                foreach ($del['errors'] as $err) {
                    $result['errors'][] = "Attachment {$id}: {$err}";
                }
            }
        }

        Logger::log('info', "delete_all_local: deleted {$result['deleted']}, freed " . size_format($result['freed_bytes']) . ", skipped {$result['skipped']}");
        return $result;
    }

    /**
     * @return array{processed:int,deleted:int,freed_bytes:int,errors:string[],done:bool}
     */
    public function batch_delete_local(int $batch_size = 50, int $offset = 0): array {
        return $this->run_batch(
            $this->get_synced_with_local($batch_size, $offset),
            'R2 is not available; refusing to delete local files',
            [$this, 'delete_local_files'],
            'deleted',
            ['freed_bytes']
        );
    }

    /**
     * Restore an attachment's files from R2 to the local filesystem (atomic + verified).
     *
     * @return array{success:bool,restored_files:int,errors:string[]}
     */
    public function restore_from_r2(int $attachment_id): array {
        $result = ['success' => false, 'restored_files' => 0, 'errors' => []];

        $manifest = $this->manifest_for($attachment_id, true, $result['errors']);
        if ($manifest === null) {
            return $result;
        }

        $base = $this->basedir();
        $restored = 0;
        $ok = true;

        foreach ($manifest['files'] as $relative => $entry) {
            $path = $base . '/' . $relative;
            // A file never deleted is the reference copy. Any other file is never written over:
            // WordPress hands a freed name to the next upload, so it may be another
            // attachment's only copy.
            $kept = file_exists($path) && ($entry['state'] === Manifest::STATE_UPLOADED || $this->matches_entry($path, $entry));
            if ($kept || (!file_exists($path) && $this->r2_client->get_object($entry['key'], $path, $entry['md5']))) {
                $manifest['files'][$relative]['state'] = Manifest::STATE_UPLOADED;
                $restored++;
                continue;
            }
            $result['errors'][] = file_exists($path)
                ? "A different file already exists, left in place: {$path}"
                : "Failed to download: {$entry['key']}";
            $ok = false;
        }

        Manifest::save($attachment_id, $manifest);
        $this->refresh_deletion_markers($attachment_id, $manifest);

        $result['restored_files'] = $restored;
        $result['success'] = $ok;
        if ($ok) {
            Logger::log('info', "Restored {$restored} files for attachment {$attachment_id}");
        }
        return $result;
    }

    /**
     * @return array{processed:int,restored:int,errors:string[],done:bool}
     */
    public function batch_restore_from_r2(int $batch_size = 50): array {
        return $this->run_batch(
            $this->get_r2_only_attachments($batch_size),
            'R2 is not available',
            [$this, 'restore_from_r2'],
            'restored'
        );
    }

    /**
     * Run $process on each attachment of a batch: $count tallies the successes, and each field
     * in $sums adds up across their results.
     *
     * @param callable $process fn(int $id): array{success:bool,errors:string[]}
     */
    private function run_batch(array $ids, string $unavailable, callable $process, string $count, array $sums = []): array {
        $result = ['processed' => 0, $count => 0] + array_fill_keys($sums, 0) + ['errors' => [], 'done' => false];

        if (!$this->r2_client->is_available()) {
            $result['errors'][] = $unavailable;
            return $result;
        }

        if (empty($ids)) {
            $result['done'] = true;
            return $result;
        }

        foreach ($ids as $id) {
            $result['processed']++;
            $outcome = $process($id);
            if ($outcome['success']) {
                $result[$count]++;
                foreach ($sums as $field) {
                    $result[$field] += $outcome[$field];
                }
            } else {
                foreach ($outcome['errors'] as $err) {
                    $result['errors'][] = "Attachment {$id}: {$err}";
                }
            }
        }

        return $result;
    }

    /**
     * One-time backfill of the media offloaded by the first release, whose keys are
     * tenant/<relative path>: builds a manifest from the R2 listing for every attachment that
     * carries offload metadata but no manifest. Keys of the current scheme always get their
     * manifest at upload time.
     *
     * @return array{synced:int,partial:int,skipped:int,total:int,r2_files?:int,error?:string}
     */
    public function sync_from_r2(): array {
        $result = ['synced' => 0, 'partial' => 0, 'skipped' => 0, 'total' => 0];

        if (!$this->r2_client->is_available()) {
            $result['error'] = __('R2 is not available. Check your connection settings.', 'numos-r2');
            return $result;
        }

        $prefix = numos_r2_get_site_prefix();
        $index = $this->r2_client->list_all_objects($prefix . '/');
        if ($index === null) {
            $result['error'] = __('Could not list the objects in storage. Nothing was changed.', 'numos-r2');
            return $result;
        }
        if (empty($index)) {
            $result['error'] = sprintf(
                __('No files found on R2 under prefix "%s/".', 'numos-r2'),
                $prefix
            );
            return $result;
        }
        $result['r2_files'] = count($index);

        $base = $this->basedir();

        foreach ($this->backfill_candidates() as $id) {
            $built = $this->reconstruct_legacy_manifest($id, $index, $prefix, $base);
            if ($built === null) {
                $result['skipped']++;
                continue;
            }
            $result['total']++;

            $status = $built['complete'] ? 'synced' : 'partial';
            $result[$status]++;
            Manifest::save($id, $built['manifest']);
            update_post_meta($id, '_numos_r2_status', $status);
            update_post_meta($id, '_numos_r2_synced', time());
            if ($built['main_key'] !== null) {
                update_post_meta($id, '_numos_r2_url', $this->r2_client->get_public_url($built['main_key']));
            }
            $this->refresh_deletion_markers($id, $built['manifest']);
        }

        UrlRewriter::forget_map();
        Logger::log('info', "sync_from_r2: synced {$result['synced']}, partial {$result['partial']}, skipped {$result['skipped']}");
        return $result;
    }

    /**
     * Manifest of one attachment from the R2 listing, with the files found under their
     * first-release keys, or null when none is there.
     *
     * @return array{manifest:array,main_key:?string,complete:bool}|null
     */
    private function reconstruct_legacy_manifest(int $attachment_id, array $index, string $prefix, string $base): ?array {
        $keys = Manifest::legacy_keys($attachment_id, $prefix, $base);
        $manifest = ['v' => Manifest::SCHEMA, 'files' => []];
        // Once the first release removed the local copies, a file at one of these paths may be
        // another upload under a reused name: it counts as the local copy only if identical.
        $deleted_before = get_post_meta($attachment_id, '_numos_r2_local_deleted', true) !== '';

        foreach ($keys as $relative => $key) {
            if (!isset($index[$key])) {
                continue;
            }
            $path = $base . '/' . $relative;
            $etag = $index[$key]['etag'];
            $entry = Manifest::entry(
                $key,
                (int) $index[$key]['size'],
                (strpos($etag, '-') === false) ? $etag : '',
                R2Client::mime_type($path),
                Manifest::STATE_LOCAL_DELETED
            );
            if (file_exists($path) && (!$deleted_before || $this->matches_entry($path, $entry))) {
                $entry['state'] = Manifest::STATE_UPLOADED;
            }
            $manifest['files'][$relative] = $entry;
        }

        if (empty($manifest['files'])) {
            return null;
        }

        $main = array_key_first($keys);
        return [
            'manifest' => $manifest,
            'main_key' => isset($manifest['files'][$main]) ? $keys[$main] : null,
            'complete' => count($manifest['files']) === count($keys),
        ];
    }

    /**
     * Storage savings for the dashboard.
     *
     * @return array{freed_bytes:int,freed_human:string,r2_only_count:int,synced_count:int}
     */
    public function get_savings(): array {
        global $wpdb;

        $freed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_numos_r2_local_deleted_size'
        ));
        $r2_only = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_numos_r2_local_deleted'
        ));
        $synced = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_numos_r2_status' AND meta_value = %s",
            'synced'
        ));

        return [
            'freed_bytes' => $freed,
            'freed_human' => size_format($freed),
            'r2_only_count' => $r2_only,
            'synced_count' => $synced,
        ];
    }

    /**
     * @return int[]
     */
    public function get_synced_with_local(int $limit = 50, int $offset = 0): array {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT pm_status.post_id ' . $this->synced_with_local_clauses()
            . ' ORDER BY pm_status.post_id ASC LIMIT %d OFFSET %d',
            $limit, $offset
        )));
    }

    public function count_synced_with_local(): int {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(DISTINCT pm_status.post_id) ' . $this->synced_with_local_clauses());
    }

    /**
     * FROM and WHERE clauses selecting the synced attachments without the deletion marker:
     * every file is expected locally.
     */
    private function synced_with_local_clauses(): string {
        global $wpdb;

        return $wpdb->prepare("
            FROM {$wpdb->postmeta} pm_status
            LEFT JOIN {$wpdb->postmeta} pm_deleted
                ON pm_status.post_id = pm_deleted.post_id
                AND pm_deleted.meta_key = %s
            WHERE pm_status.meta_key = %s
            AND pm_status.meta_value = %s
            AND pm_deleted.meta_value IS NULL
        ", '_numos_r2_local_deleted', '_numos_r2_status', 'synced');
    }

    /**
     * Attachments with the deletion marker, at least one file possibly only on R2: the restore
     * queue.
     *
     * @return int[]
     */
    public function get_r2_only_attachments(int $limit = 50): array {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_numos_r2_local_deleted'
            ORDER BY post_id ASC
            LIMIT %d
        ", $limit)));
    }

    /**
     * Attachments without a manifest that the first release offloaded, whatever their status
     * (it served R2 URLs for partial uploads too).
     *
     * @return int[]
     */
    private function backfill_candidates(): array {
        global $wpdb;

        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm
                ON p.ID = pm.post_id AND pm.meta_key LIKE %s
            LEFT JOIN {$wpdb->postmeta} pm_man
                ON p.ID = pm_man.post_id AND pm_man.meta_key = %s
            WHERE p.post_type = 'attachment'
            AND pm_man.post_id IS NULL
            ORDER BY p.ID ASC
        ", $wpdb->esc_like('_numos_r2_') . '%', Manifest::META_KEY)));

        update_meta_cache('post', $ids);
        return array_values(array_filter($ids, [Manifest::class, 'has_trace']));
    }

    /**
     * Every R2 key in use, as a key => true set, so the orphan scan can tell a genuinely
     * orphaned object of this tenant from a live one: the keys of every manifest, plus the
     * first-release keys of every attachment that has no manifest yet.
     *
     * @return array<string,bool>
     */
    public function all_referenced_keys(): array {
        global $wpdb;

        $keys = [];
        foreach ($this->all_manifests() as $manifest) {
            $keys += array_fill_keys(Manifest::keys($manifest), true);
        }

        $without_manifest = array_map('intval', $wpdb->get_col($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_man
                ON p.ID = pm_man.post_id AND pm_man.meta_key = %s
            WHERE p.post_type = 'attachment'
            AND pm_man.post_id IS NULL
        ", Manifest::META_KEY)));
        update_meta_cache('post', $without_manifest);

        $prefix = numos_r2_get_site_prefix();
        $base = $this->basedir();
        foreach ($without_manifest as $id) {
            $keys += array_fill_keys(Manifest::legacy_keys($id, $prefix, $base), true);
        }
        return $keys;
    }

    /**
     * Every attachment manifest, keyed by attachment ID.
     *
     * @return array<int,array>
     */
    private function all_manifests(): array {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            Manifest::META_KEY
        ));

        $out = [];
        foreach ($rows as $row) {
            $manifest = maybe_unserialize($row->meta_value);
            if (is_array($manifest) && !empty($manifest['files'])) {
                $out[(int) $row->post_id] = $manifest;
            }
        }
        return $out;
    }

    /**
     * Remove empty year/month directories after deletion, never above the uploads base.
     */
    private function cleanup_empty_dirs(string $dir): void {
        $base_dir = $this->basedir();
        if (strlen($dir) <= strlen($base_dir) || !is_dir($dir)) {
            return;
        }

        $entries = array_diff(scandir($dir), ['.', '..']);
        if (empty($entries)) {
            @rmdir($dir);
            $this->cleanup_empty_dirs(dirname($dir));
        }
    }
}
