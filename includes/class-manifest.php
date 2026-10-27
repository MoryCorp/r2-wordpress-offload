<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * Authoritative record of what was uploaded to R2 for an attachment, stored as one post meta
 * keyed by each file's path relative to the uploads basedir. URL rewriting, verification,
 * deletion and restore read it, never keys recomputed from the current prefix and metadata.
 * Each MD5 was compared to the R2 ETag at upload: it is the content identity checked before
 * any destructive action.
 */
class Manifest {

    const META_KEY = '_numos_r2_manifest';
    const SCHEMA = 1;

    const STATE_UPLOADED = 'uploaded';
    const STATE_DELETING = 'deletion_in_progress';
    const STATE_LOCAL_DELETED = 'local_deleted';

    /**
     * @return array{v:int,files:array<string,array{key:string,size:int,md5:string,ct:string,state:string}>}|null
     */
    public static function get(int $attachment_id): ?array {
        $raw = get_post_meta($attachment_id, self::META_KEY, true);
        if (!is_array($raw) || empty($raw['files']) || !is_array($raw['files'])) {
            return null;
        }
        return $raw;
    }

    /**
     * Persist a manifest. Returns true only when the meta write took effect, so a caller
     * can gate destructive local deletion on the manifest being safely stored.
     */
    public static function save(int $attachment_id, array $manifest): bool {
        $manifest['v'] = self::SCHEMA;
        // update_post_meta() also returns false when the stored value is already this one.
        return update_post_meta($attachment_id, self::META_KEY, $manifest) !== false
            || self::get($attachment_id) === $manifest;
    }

    /**
     * Those of $keys another attachment's manifest also records. Only first-release keys can
     * be shared: the current scheme puts the attachment ID in every key.
     *
     * @param string[] $keys
     * @return string[]
     */
    public static function shared_keys(int $attachment_id, array $keys): array {
        global $wpdb;

        return array_values(array_filter($keys, function ($key) use ($wpdb, $attachment_id) {
            return (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id <> %d AND meta_value LIKE %s LIMIT 1",
                self::META_KEY,
                $attachment_id,
                '%' . $wpdb->esc_like('"' . $key . '"') . '%'
            ));
        }));
    }

    public static function delete(int $attachment_id): void {
        delete_post_meta($attachment_id, self::META_KEY);
    }

    /**
     * $md5 is empty only for an object backfilled from a multipart ETag, which holds no MD5 of
     * the payload: only the size of such an entry can be verified.
     */
    public static function entry(string $key, int $size, string $md5, string $content_type, string $state = self::STATE_UPLOADED): array {
        return [
            'key' => $key,
            'size' => $size,
            'md5' => $md5,
            'ct' => $content_type,
            'state' => $state,
        ];
    }

    /**
     * R2 key for a new upload: tenant/<version>/<relative path>. Keeping the relative path
     * intact lets WordPress derive a size URL from the main URL by swapping the file name, as
     * it does for local files, so every file of an attachment shares the same version.
     */
    public static function content_key(string $tenant, string $version, string $relative_path): string {
        return $tenant . '/' . $version . '/' . $relative_path;
    }

    /**
     * Version segment of an attachment's keys, from its ID and the MD5 of every file: a new
     * payload never reuses a key (objects can be cached as immutable), and two attachments
     * never share one, so deleting either cannot remove the other's objects.
     *
     * @param string[] $md5s
     */
    public static function version(int $attachment_id, array $md5s): string {
        sort($md5s);
        return substr(md5($attachment_id . ':' . implode(',', $md5s)), 0, 8);
    }

    /**
     * Local paths of an attachment's files: the main file first, then each named size and the
     * pre-scaling original.
     *
     * @return string[]
     */
    public static function attachment_paths(string $main_path, array $metadata): array {
        $dir = dirname($main_path);
        $paths = [$main_path];
        $sizes = is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [];
        foreach ($sizes as $size) {
            if (!empty($size['file'])) {
                $paths[] = $dir . '/' . $size['file'];
            }
        }
        if (!empty($metadata['original_image'])) {
            $paths[] = $dir . '/' . $metadata['original_image'];
        }
        return array_values(array_unique($paths));
    }

    /**
     * Keys the first release gave an attachment's files: tenant/<relative path>.
     *
     * @return array<string,string> relative path => key
     */
    public static function legacy_keys(int $attachment_id, string $tenant, string $base_dir): array {
        $main = get_attached_file($attachment_id);
        if (!$main) {
            return [];
        }
        $metadata = wp_get_attachment_metadata($attachment_id);
        $keys = [];
        foreach (self::attachment_paths($main, is_array($metadata) ? $metadata : []) as $path) {
            $relative = self::relative_path($path, $base_dir);
            $keys[$relative] = $tenant . '/' . $relative;
        }
        return $keys;
    }

    /**
     * True when some of the attachment's files may be on R2: it has a manifest, or it carries
     * the metas the first release wrote on offloaded media.
     */
    public static function has_trace(int $attachment_id): bool {
        if (self::get($attachment_id) !== null) {
            return true;
        }
        foreach (['_numos_r2_key', '_numos_r2_url', '_numos_r2_local_deleted'] as $meta_key) {
            if (get_post_meta($attachment_id, $meta_key, true) !== '') {
                return true;
            }
        }
        return in_array(get_post_meta($attachment_id, '_numos_r2_status', true), ['synced', 'partial'], true);
    }

    /**
     * True while the attachment carries the local deletion marker: some of its files may exist
     * only on R2, and a file found at one of its paths may be another upload under a reused name.
     */
    public static function has_deletion_marker(int $attachment_id): bool {
        return get_post_meta($attachment_id, '_numos_r2_local_deleted', true) !== '';
    }

    /**
     * R2 key of every file recorded for this attachment.
     *
     * @return array<string,string> relative path => key
     */
    public static function keys(array $manifest): array {
        return array_map(function ($file) {
            return $file['key'];
        }, $manifest['files']);
    }

    /**
     * Files whose local copy may be gone: removed, or caught by an interrupted deletion.
     */
    public static function remote_only(array $manifest): array {
        return array_filter($manifest['files'], function ($file) {
            return $file['state'] !== self::STATE_UPLOADED;
        });
    }

    /**
     * Path of a file relative to the uploads basedir, forward-slashed, no leading slash.
     * Strict prefix strip (not str_replace, which would remove the basedir wherever it recurs).
     */
    public static function relative_path(string $local_path, string $base_dir): string {
        $local_path = str_replace('\\', '/', $local_path);
        $base_dir = str_replace('\\', '/', $base_dir);
        if (strpos($local_path, $base_dir) === 0) {
            return ltrim(substr($local_path, strlen($base_dir)), '/');
        }
        return ltrim($local_path, '/');
    }
}
