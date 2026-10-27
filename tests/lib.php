<?php
/**
 * Shared helpers for harness scenarios, loaded by `wp eval-file` inside the test WordPress.
 * Scenarios assert the safe behaviour: on the code that has the defect they print ECHEC.
 */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

class R2tDie extends Exception {}

function r2t_variant(): string {
    return class_exists('NumosR2\Manifest') ? 'apres' : 'avant';
}

function r2t_out(string $id, ?bool $ok, string $msg): void {
    $label = $ok === null ? 'N/A' : ($ok ? 'OK' : 'ECHEC');
    echo "$label $id : $msg\n";
}

function r2t_state(): string {
    return getenv('R2T_STATE');
}

function r2t_rmtree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function r2t_reset_static_caches(): void {
    (static function () { self::$available_cache = null; })->bindTo(null, NumosR2\R2Client::class)();
    $rewriter_cache = class_exists('NumosR2\Manifest') ? 'map_cache' : 'synced_paths_cache';
    (static function () use ($rewriter_cache) { self::$$rewriter_cache = null; })->bindTo(null, NumosR2\UrlRewriter::class)();
}

/**
 * Empty media library, uploads, fake bucket, faults and plugin transients; default settings.
 */
function r2t_reset(array $settings = []): void {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment')");
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'attachment'");
    foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%numos_r2%'") as $name) {
        if (strpos($name, '_transient') === 0) {
            delete_option($name);
        }
    }
    wp_cache_flush();

    // wp_upload_dir() remembers which directories it already created: recreate the current one.
    $uploads = wp_upload_dir();
    r2t_rmtree($uploads['basedir']);
    wp_mkdir_p($uploads['path']);
    r2t_rmtree(r2t_state() . '/s3');
    r2t_fault([]);
    r2t_reset_static_caches();
    r2t_settings($settings);
}

function r2t_fault(array $faults): void {
    file_put_contents(r2t_state() . '/faults.json', json_encode((object) $faults));
}

/**
 * Apply plugin settings to the option and to the already-constructed components, which read
 * the option once at construction.
 */
function r2t_settings(array $changes): void {
    $settings = array_merge([
        'enabled' => true,
        'sync_new_uploads' => true,
        'remove_local_files' => false,
        'log_level' => 'info',
        'batch_size' => 50,
    ], $changes);
    update_option('numos_r2_settings', $settings);
    foreach ([r2t_media_handler(), r2t_url_rewriter()] as $component) {
        if ($component) {
            r2t_prop($component, 'settings', $settings);
        }
    }
}

function r2t_cleaner(): NumosR2\LocalCleaner {
    return new NumosR2\LocalCleaner(r2t_client());
}

function r2t_admin(): NumosR2\Admin {
    $migrator = new NumosR2\Migrator(r2t_client());
    $migrator->set_media_handler(r2t_media_handler());
    return new NumosR2\Admin(r2t_client(), $migrator, r2t_cleaner());
}

/**
 * Call an admin AJAX handler as an administrator and return its decoded JSON response.
 */
function r2t_ajax(string $method, array $post = []): ?array {
    wp_set_current_user(1);
    $_POST = $_REQUEST = array_merge(['nonce' => wp_create_nonce('numos_r2_nonce')], $post);
    add_filter('wp_doing_ajax', '__return_true');
    add_filter('wp_die_ajax_handler', function () {
        return function () { throw new R2tDie(); };
    });
    $admin = r2t_admin();
    ob_start();
    try {
        $admin->$method();
    } catch (R2tDie $e) {
    }
    return json_decode(ob_get_clean(), true);
}

/**
 * A JPEG whose content is determined by $seed, so two seeds give two different payloads.
 */
function r2t_image(int $seed, int $width = 1600, int $height = 1200): string {
    mt_srand($seed);
    $im = imagecreatetruecolor($width, $height);
    for ($y = 0; $y < $height; $y += 100) {
        for ($x = 0; $x < $width; $x += 100) {
            $color = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($im, $x, $y, $x + 99, $y + 99, $color);
        }
    }
    $path = wp_tempnam("r2t-$seed.jpg");
    imagejpeg($im, $path, 85);
    imagedestroy($im);
    return $path;
}

/**
 * Upload a file through the regular media pipeline (fires the offload hook unless $sync is false).
 */
function r2t_upload(string $source, string $name, bool $sync = true): int {
    $handler = r2t_media_handler();
    if (!$sync) {
        remove_filter('wp_generate_attachment_metadata', [$handler, 'handle_new_upload'], 10);
    }
    // Local deletion removes an emptied month directory that wp_upload_dir() still believes exists.
    wp_mkdir_p(wp_upload_dir()['path']);
    $tmp = wp_tempnam($name);
    copy($source, $tmp);
    $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0);
    if (!$sync) {
        add_filter('wp_generate_attachment_metadata', [$handler, 'handle_new_upload'], 10, 2);
    }
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    return $id;
}

/**
 * Local paths of an attachment: main file, each named size, original image.
 *
 * @return string[]
 */
function r2t_files(int $id): array {
    $main = get_attached_file($id);
    $dir = dirname($main);
    $meta = wp_get_attachment_metadata($id);
    $files = [$main];
    foreach (($meta['sizes'] ?? []) as $size) {
        $files[] = "$dir/{$size['file']}";
    }
    if (!empty($meta['original_image'])) {
        $files[] = "$dir/{$meta['original_image']}";
    }
    return array_values(array_unique($files));
}

function r2t_relative(string $path): string {
    return ltrim(substr($path, strlen(wp_upload_dir()['basedir'])), '/');
}

function r2t_legacy_key(string $path): string {
    return numos_r2_get_site_prefix() . '/' . r2t_relative($path);
}

function r2t_store_file(string $key, string $kind): string {
    return r2t_state() . "/s3/$kind/$key" . ($kind === 'meta' ? '.json' : '');
}

function r2t_store_put(string $key, string $bytes): void {
    foreach (['data', 'meta'] as $kind) {
        wp_mkdir_p(dirname(r2t_store_file($key, $kind)));
    }
    file_put_contents(r2t_store_file($key, 'data'), $bytes);
    file_put_contents(r2t_store_file($key, 'meta'), json_encode([
        'size' => strlen($bytes),
        'etag' => md5($bytes),
        'content_type' => wp_check_filetype($key)['type'] ?: '',
        'cache_control' => '',
        'last_modified' => gmdate('Y-m-d\TH:i:s.000\Z'),
    ]));
}

/**
 * Metadata-only object, as imported from a real bucket listing.
 */
function r2t_store_put_meta(string $key, int $size, string $etag): void {
    wp_mkdir_p(dirname(r2t_store_file($key, 'meta')));
    file_put_contents(r2t_store_file($key, 'meta'), json_encode([
        'size' => $size,
        'etag' => $etag,
        'content_type' => '',
        'cache_control' => '',
        'last_modified' => gmdate('Y-m-d\TH:i:s.000\Z'),
    ]));
}

function r2t_store_get(string $key): ?string {
    $file = r2t_store_file($key, 'data');
    return is_file($file) ? file_get_contents($file) : null;
}

function r2t_store_meta(string $key): ?array {
    $file = r2t_store_file($key, 'meta');
    return is_file($file) ? json_decode(file_get_contents($file), true) : null;
}

/** @return string[] */
function r2t_store_keys(string $prefix = ''): array {
    $root = r2t_state() . '/s3/meta';
    if (!is_dir($root)) {
        return [];
    }
    $keys = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        $key = substr($f->getPathname(), strlen($root) + 1, -5);
        if (strpos($key, $prefix) === 0) {
            $keys[] = $key;
        }
    }
    sort($keys);
    return $keys;
}

/**
 * Object key behind a public URL, or null when the URL is not on the public base.
 */
function r2t_key_of_url(string $url): ?string {
    $base = rtrim(NUMOS_R2_PUBLIC_URL, '/') . '/';
    return strpos($url, $base) === 0 ? rawurldecode(substr($url, strlen($base))) : null;
}

/**
 * Put an attachment in the state the original plugin version leaves behind: objects at
 * prefix/relative, status and URL metas, optionally local files removed. Files listed in
 * $missing never reach the bucket.
 */
function r2t_make_legacy(int $id, bool $r2_only, array $missing = []): void {
    $files = r2t_files($id);
    $total = 0;
    foreach ($files as $path) {
        $total += filesize($path);
        if (!in_array($path, $missing, true)) {
            r2t_store_put(r2t_legacy_key($path), file_get_contents($path));
        }
    }
    $main_key = r2t_legacy_key($files[0]);
    update_post_meta($id, '_numos_r2_status', 'synced');
    update_post_meta($id, '_numos_r2_key', $main_key);
    update_post_meta($id, '_numos_r2_url', rtrim(NUMOS_R2_PUBLIC_URL, '/') . '/' . $main_key);
    update_post_meta($id, '_numos_r2_synced', time());
    if ($r2_only) {
        foreach ($files as $path) {
            unlink($path);
        }
        update_post_meta($id, '_numos_r2_local_deleted', time());
        update_post_meta($id, '_numos_r2_local_deleted_size', $total);
    }
}

/**
 * Run PHP in a separate WordPress process, killed after $seconds (simulates a request that dies).
 */
function r2t_child(string $php, int $seconds): void {
    exec('timeout ' . $seconds . ' ' . getenv('R2T_WP') . ' eval ' . escapeshellarg($php) . ' > /dev/null 2>&1');
}

/**
 * Same size, different bytes: flips one byte in the middle of the payload.
 */
function r2t_flip_byte(string $bytes): string {
    $i = intdiv(strlen($bytes), 2);
    $bytes[$i] = chr(ord($bytes[$i]) ^ 0xFF);
    return $bytes;
}
