<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * Uploads new and existing media to R2 and records the manifest: only the files R2 confirmed,
 * each with its MD5. An attachment is "synced" only when every expected file was confirmed.
 */
class MediaHandler {

    private R2Client $r2_client;
    private array $settings;

    /** @var string[] paths of attachments deleted in this request that hold another file */
    private array $foreign_paths = [];

    /** @var string[] paths holding the own file of an attachment deleted in this request */
    private array $owned_paths = [];

    public function __construct(R2Client $r2_client) {
        $this->r2_client = $r2_client;
        $this->settings = get_option('numos_r2_settings', []);
    }

    public function register_hooks(): void {
        // Local copies already removed by the plugin must stay protected whatever the settings.
        add_action('delete_attachment', [$this, 'protect_foreign_files'], 10, 1);
        add_filter('wp_delete_file', [$this, 'keep_foreign_file']);

        if (empty($this->settings['enabled']) || empty($this->settings['sync_new_uploads'])) {
            return;
        }

        add_filter('wp_generate_attachment_metadata', [$this, 'handle_new_upload'], 10, 2);

        add_action('delete_attachment', [$this, 'handle_delete'], 10, 1);
    }

    /**
     * Offload a new upload once WordPress has generated its sizes. The metadata is not saved
     * yet at this point, hence passed along.
     */
    public function handle_new_upload($metadata, $attachment_id) {
        $id = numos_r2_attachment_id($attachment_id);
        if ($id > 0 && is_array($metadata)) {
            $this->sync([$id => $metadata]);
        }
        return $metadata;
    }

    /**
     * Upload a single attachment to R2 (for migration / CLI).
     *
     * @return array{success:bool,files_uploaded:int,files_failed:int,error:?string}
     */
    public function upload_attachment(int $attachment_id): array {
        $outcome = $this->upload_attachments_batch([$attachment_id])[$attachment_id];
        return [
            'success' => $outcome['status'] === 'synced',
            'files_uploaded' => $outcome['files_uploaded'],
            'files_failed' => $outcome['files_failed'],
            'error' => $outcome['error'],
        ];
    }

    /**
     * @return array<int,array{status:string,error:?string,files_uploaded:int,files_failed:int}>
     */
    public function upload_attachments_batch(array $attachment_ids): array {
        $metadata = [];
        foreach ($attachment_ids as $attachment_id) {
            $meta = wp_get_attachment_metadata((int) $attachment_id);
            $metadata[(int) $attachment_id] = is_array($meta) ? $meta : [];
        }
        return $this->sync($metadata);
    }

    /**
     * Upload the files of several attachments in one parallel batch, then record one manifest
     * per attachment and optionally remove the local copies. Status "skipped": the attachment
     * cannot be offloaded; "failed": it was left exactly as it was.
     *
     * @param array<int,array> $metadata attachment ID => attachment metadata
     * @return array<int,array{status:string,error:?string,files_uploaded:int,files_failed:int}>
     */
    private function sync(array $metadata): array {
        if (!$this->r2_client->is_available()) {
            Logger::log('warning', 'R2 not available, upload skipped for ' . count($metadata) . ' attachment(s)');
            return array_map(function () {
                return $this->outcome('failed', 'R2 not available');
            }, $metadata);
        }

        $outcomes = [];
        $files = [];
        foreach ($metadata as $attachment_id => $meta) {
            $refusal = $this->refusal($attachment_id);
            if ($refusal !== null) {
                $outcomes[$attachment_id] = $refusal;
            } else {
                $files[$attachment_id] = $this->collect_files($attachment_id, $meta);
            }
        }

        $uploads = $files ? $this->r2_client->upload_files_parallel(array_merge(...array_values($files)), 10) : [];

        foreach ($files as $attachment_id => $set) {
            $built = $this->build_manifest($set, $uploads);
            $this->record_outcome($attachment_id, $built);

            if ($built['all_ok']) {
                $this->maybe_remove_local($attachment_id);
                Logger::log('info', "Synced attachment {$attachment_id}: {$built['uploaded']} files");
                $outcomes[$attachment_id] = $this->outcome('synced', null, $built['uploaded']);
            } else {
                Logger::log('warning', "Partially synced attachment {$attachment_id}: {$built['uploaded']} ok, {$built['failed']} failed");
                $outcomes[$attachment_id] = $this->outcome('partial', "{$built['failed']} file(s) failed to upload", $built['uploaded'], $built['failed']);
            }
        }

        UrlRewriter::forget_map();
        return $outcomes;
    }

    /**
     * Why an attachment must not be uploaded, or null. A re-upload replaces the manifest with
     * the files found locally: while one of its files may only exist on R2, the file at that
     * path may be another attachment's, and the R2 copy would drop out of the manifest.
     */
    private function refusal(int $attachment_id): ?array {
        $local_path = get_attached_file($attachment_id);
        if (!$local_path || !file_exists($local_path)) {
            return $this->outcome('skipped', 'Local file not found');
        }
        if (!$this->is_allowed_file($local_path)) {
            return $this->outcome('skipped', 'File type not allowed');
        }
        $manifest = Manifest::get($attachment_id);
        if ($manifest === null && Manifest::has_deletion_marker($attachment_id)) {
            return $this->outcome('failed', 'Offloaded by the first release: run sync-from-r2 before uploading it again');
        }
        $base_dir = wp_upload_dir()['basedir'];
        foreach ($manifest['files'] ?? [] as $relative => $entry) {
            if ($entry['state'] !== Manifest::STATE_UPLOADED || !file_exists($base_dir . '/' . $relative)) {
                return $this->outcome('failed', 'Some files only exist in object storage: restore the attachment before uploading it again');
            }
        }
        return null;
    }

    private function outcome(string $status, ?string $error, int $uploaded = 0, int $failed = 0): array {
        return ['status' => $status, 'error' => $error, 'files_uploaded' => $uploaded, 'files_failed' => $failed];
    }

    /**
     * Delete the R2 objects of an attachment's manifest. Without a manifest the owner of a
     * first-release key cannot be established, another attachment may hold the same one: those
     * objects are left to the orphan cleanup.
     */
    public function handle_delete(int $attachment_id): void {
        $manifest = Manifest::get($attachment_id);
        if ($manifest === null) {
            return;
        }
        $keys = Manifest::keys($manifest);
        $keys = array_diff($keys, Manifest::shared_keys($attachment_id, $keys));
        $result = $this->r2_client->delete_objects(array_values($keys));
        if (!empty($result['errors'])) {
            Logger::log('error', "Failed to delete " . count($result['errors']) . " R2 object(s) for attachment {$attachment_id}");
        }

        Manifest::delete($attachment_id);
        delete_post_meta($attachment_id, '_numos_r2_key');
        delete_post_meta($attachment_id, '_numos_r2_url');
        delete_post_meta($attachment_id, '_numos_r2_synced');
        delete_post_meta($attachment_id, '_numos_r2_status');
        UrlRewriter::forget_map();

        Logger::log('info', "Deleted attachment {$attachment_id} from R2");
    }

    /**
     * Before WordPress deletes an attachment's files, record the paths where its own file was
     * removed locally: WordPress hands a freed name to the next upload, so whatever is there now
     * belongs to another attachment. Records cover the whole request, since a hook may delete
     * other attachments meanwhile, and a path held by an attachment deleted in the same request
     * is never kept.
     */
    public function protect_foreign_files(int $attachment_id): void {
        $main = get_attached_file($attachment_id);
        $metadata = wp_get_attachment_metadata($attachment_id);
        $paths = $main ? Manifest::attachment_paths($main, is_array($metadata) ? $metadata : []) : [];

        $manifest = Manifest::get($attachment_id);
        if ($manifest !== null) {
            $base_dir = wp_upload_dir()['basedir'];
            $foreign = array_map(function ($relative) use ($base_dir) {
                return $base_dir . '/' . $relative;
            }, array_keys(Manifest::remote_only($manifest)));
        } else {
            $foreign = Manifest::has_deletion_marker($attachment_id) ? $paths : [];
        }

        $foreign = array_map('wp_normalize_path', $foreign);
        $owned = array_diff(array_map('wp_normalize_path', $paths), $foreign);
        $this->foreign_paths = array_merge($this->foreign_paths, $foreign);
        $this->owned_paths = array_merge($this->owned_paths, $owned);
    }

    /**
     * wp_delete_file filter: skips a path recorded as holding the file of an attachment that is
     * not being deleted.
     */
    public function keep_foreign_file($file) {
        if (!is_string($file)) {
            return $file;
        }
        $path = wp_normalize_path($file);
        if (in_array($path, $this->foreign_paths, true) && !in_array($path, $this->owned_paths, true)) {
            Logger::log('info', "Kept {$file}: it is not the deleted attachment's file");
            return '';
        }
        return $file;
    }

    /**
     * Every file to offload for an attachment (main, each named size, original) with its MD5
     * and key. Files missing on disk are left out; the incomplete manifest then reports them.
     *
     * @return array<int,array{local_path:string,relative_path:string,r2_key:string,md5:string,size:int,content_type:string,is_main:bool}>
     */
    private function collect_files(int $attachment_id, array $metadata): array {
        $local_path = get_attached_file($attachment_id);
        $base_dir = wp_upload_dir()['basedir'];

        $files = [];
        foreach (Manifest::attachment_paths($local_path, $metadata) as $path) {
            $md5 = file_exists($path) ? md5_file($path) : false;
            if ($md5 === false) {
                continue;
            }
            $files[] = [
                'local_path' => $path,
                'relative_path' => Manifest::relative_path($path, $base_dir),
                'md5' => $md5,
                'size' => (int) filesize($path),
                'content_type' => R2Client::mime_type($path),
                'is_main' => ($path === $local_path),
            ];
        }

        $tenant = numos_r2_get_site_prefix();
        $version = Manifest::version($attachment_id, array_column($files, 'md5'));
        foreach ($files as &$file) {
            $file['r2_key'] = Manifest::content_key($tenant, $version, $file['relative_path']);
        }
        unset($file);
        return $files;
    }

    /**
     * Assemble the manifest from a collected file set and the upload results map. Only files
     * R2 confirmed enter the manifest. all_ok is true only when every collected file succeeded.
     *
     * @return array{manifest:array,uploaded:int,failed:int,main_url:?string,all_ok:bool}
     */
    private function build_manifest(array $files, array $results): array {
        $manifest = ['v' => Manifest::SCHEMA, 'files' => []];
        $uploaded = 0;
        $failed = 0;
        $main_url = null;

        foreach ($files as $f) {
            $r = $results[$f['r2_key']] ?? null;
            if ($r && $r['success']) {
                $uploaded++;
                $manifest['files'][$f['relative_path']] = Manifest::entry(
                    $f['r2_key'],
                    $f['size'],
                    $f['md5'],
                    $f['content_type']
                );
                if ($f['is_main']) {
                    $main_url = $r['url'];
                }
            } else {
                $failed++;
            }
        }

        $expected = count($files);
        return [
            'manifest' => $manifest,
            'uploaded' => $uploaded,
            'failed' => $failed,
            'main_url' => $main_url,
            'all_ok' => ($failed === 0 && $expected > 0 && $uploaded === $expected),
        ];
    }

    /**
     * Persist the manifest and the status meta. The manifest records exactly what is on R2,
     * even on a partial upload, so a later retry or verification has a truthful starting point.
     */
    private function record_outcome(int $attachment_id, array $built): void {
        Manifest::save($attachment_id, $built['manifest']);

        if ($built['all_ok']) {
            if ($built['main_url'] !== null) {
                update_post_meta($attachment_id, '_numos_r2_url', $built['main_url']);
            }
            update_post_meta($attachment_id, '_numos_r2_synced', time());
            update_post_meta($attachment_id, '_numos_r2_status', 'synced');
        } else {
            update_post_meta($attachment_id, '_numos_r2_status', 'partial');
        }
    }

    private function maybe_remove_local(int $attachment_id): void {
        if (empty($this->settings['remove_local_files'])) {
            return;
        }
        $cleaner = new LocalCleaner($this->r2_client);
        $del = $cleaner->delete_local_files($attachment_id);
        if (!$del['success']) {
            Logger::log('warning', "Auto-delete failed for attachment {$attachment_id}: " . implode(', ', $del['errors']));
        }
    }

    /**
     * Types that can carry executable code once served from the public bucket.
     */
    private const BLOCKED_EXTENSIONS = ['svg', 'svgz', 'html', 'htm', 'js', 'php', 'phtml', 'exe', 'sh', 'bat'];

    private function is_allowed_file(string $file_path): bool {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return false;
        }

        $mime_check = wp_check_filetype($file_path);
        if (empty($mime_check['type'])) {
            Logger::log('warning', "MIME type not recognized by WordPress for: {$file_path}");
            return false;
        }

        return true;
    }
}
