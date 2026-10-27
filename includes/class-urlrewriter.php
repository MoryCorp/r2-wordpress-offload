<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * Serves offloaded media from R2. Every URL resolves through the manifest: the public URL is
 * the recorded key under the public base, never a path rebuilt from the local URL.
 *
 * Rewriting does not depend on the S3 API health check: the public CDN serves a file on R2
 * independently of the private API, and a local URL would 404 once the local copy is removed.
 */
class UrlRewriter {

    private array $settings;
    private string $local_base_url;
    private string $r2_base_url;

    const MAP_TRANSIENT = 'numos_r2_key_map';

    /** @var array<string,string>|null relative path => R2 key */
    private static ?array $map_cache = null;

    public function __construct() {
        $this->settings = get_option('numos_r2_settings', []);
        $this->r2_base_url = rtrim(NUMOS_R2_PUBLIC_URL, '/');
        $this->local_base_url = wp_upload_dir()['baseurl'];
    }

    public function register_hooks(): void {
        if (empty($this->settings['enabled'])) {
            return;
        }

        add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 99, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'rewrite_image_src'], 99, 4);
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_srcset'], 99, 5);

        add_filter('the_content', [$this, 'rewrite_content_urls'], 99);
        add_filter('widget_text', [$this, 'rewrite_content_urls'], 99);
        add_filter('widget_text_content', [$this, 'rewrite_content_urls'], 99);

        if (function_exists('acf')) {
            add_filter('acf/format_value/type=image', [$this, 'rewrite_acf_image'], 99, 3);
            add_filter('acf/format_value/type=gallery', [$this, 'rewrite_acf_gallery'], 99, 3);
            add_filter('acf/format_value/type=url', [$this, 'rewrite_acf_url'], 99, 3);
            add_filter('acf/format_value/type=file', [$this, 'rewrite_acf_file'], 99, 3);
        }

        // Output buffer catches URLs the typed filters miss (Elementor templates, inline CSS,
        // JSON in page builders). Never on admin screens, where editors need the local paths.
        if (!is_admin()) {
            add_action('template_redirect', [$this, 'start_output_buffer'], 1);
        }
        if (wp_doing_ajax()) {
            add_action('admin_init', [$this, 'start_output_buffer'], 1);
        }
        add_action('rest_api_init', [$this, 'start_output_buffer'], 1);
    }

    /**
     * R2 URL of a file written under the local uploads base, or null when the file is not on
     * R2. The path is kept exactly as written, raw or percent-encoded, behind the location of
     * its key: keys always end with the relative path, and WordPress derives size URLs and
     * srcset matches from the raw file names of the URL it gets back.
     *
     * @param array<string,string> $keys relative path => R2 key
     */
    private function to_r2(string $written, array $keys): ?string {
        $relative = isset($keys[$written]) ? $written : rawurldecode($written);
        return isset($keys[$relative]) ? $this->location($keys[$relative], $relative) . $written : null;
    }

    /**
     * Public URL of the folder a key lives in, given the relative path the key ends with.
     */
    private function location(string $key, string $relative): string {
        return $this->r2_base_url . '/' . substr($key, 0, strlen($key) - strlen($relative));
    }

    public function rewrite_attachment_url($url, $attachment_id) {
        $id = numos_r2_attachment_id($attachment_id);
        if ($id === 0 || !is_string($url)) {
            return $url;
        }
        $written = $this->url_to_relative($url);
        $manifest = $written === null ? null : Manifest::get($id);
        if ($manifest === null) {
            return $url;
        }
        return $this->to_r2($written, Manifest::keys($manifest)) ?? $url;
    }

    /**
     * Core builds a size URL by swapping the file name in the main file's URL, so a size that
     * never reached R2 comes out under the main file's R2 folder: it is sent back to its local
     * copy, and a size that is on R2 under a local main URL is sent to R2.
     */
    public function rewrite_image_src($image, $attachment_id, $size, $icon) {
        $id = numos_r2_attachment_id($attachment_id);
        $valid = $id > 0 && is_array($image) && !empty($image[0]) && is_string($image[0]);
        $manifest = $valid ? Manifest::get($id) : null;
        if ($manifest === null) {
            return $image;
        }

        $keys = Manifest::keys($manifest);
        $relative = array_key_first($keys);
        $location = $this->location($keys[$relative], $relative);
        $written = strpos($image[0], $location) === 0
            ? substr($image[0], strlen($location))
            : $this->url_to_relative($image[0]);
        if ($written !== null) {
            $image[0] = $this->to_r2($written, $keys) ?? $this->local_base_url . '/' . $written;
        }
        return $image;
    }

    public function rewrite_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        $id = numos_r2_attachment_id($attachment_id);
        $manifest = ($id === 0 || !is_array($sources)) ? null : Manifest::get($id);
        if ($manifest === null) {
            return $sources;
        }

        $keys = Manifest::keys($manifest);
        foreach ($sources as &$source) {
            $written = $this->url_to_relative($source['url']);
            if ($written !== null) {
                $source['url'] = $this->to_r2($written, $keys) ?? $source['url'];
            }
        }
        unset($source);

        return $sources;
    }

    /**
     * ACF passes the post ID, "options", "user_1", "term_2"... as second argument: unused here.
     */
    public function rewrite_acf_image($value, $post_id, $field) {
        if (empty($value)) {
            return $value;
        }

        if (is_array($value)) {
            if (isset($value['url'])) {
                $value['url'] = $this->rewrite_attachment_url($value['url'], $value['id'] ?? 0);
            }
            if (!empty($value['sizes']) && is_array($value['sizes'])) {
                foreach ($value['sizes'] as &$url) {
                    if (is_string($url)) {
                        $url = $this->rewrite_mapped_url($url);
                    }
                }
                unset($url);
            }
        } elseif (is_string($value)) {
            $value = $this->rewrite_mapped_url($value);
        }

        return $value;
    }

    public function rewrite_acf_gallery($value, $post_id, $field) {
        if (empty($value) || !is_array($value)) {
            return $value;
        }
        foreach ($value as &$image) {
            $image = $this->rewrite_acf_image($image, $post_id, $field);
        }
        unset($image);
        return $value;
    }

    public function rewrite_acf_url($value, $post_id, $field) {
        if (empty($value) || !is_string($value)) {
            return $value;
        }
        return $this->rewrite_mapped_url($value);
    }

    public function rewrite_acf_file($value, $post_id, $field) {
        if (empty($value)) {
            return $value;
        }
        if (is_array($value) && isset($value['url'])) {
            $value['url'] = $this->rewrite_mapped_url($value['url']);
        } elseif (is_string($value)) {
            $value = $this->rewrite_mapped_url($value);
        }
        return $value;
    }

    /**
     * Rewrite a local upload URL to R2 if its path is in the key map. Used where no
     * attachment ID is available (content, ACF strings, output buffer).
     */
    private function rewrite_mapped_url(string $url): string {
        $written = $this->url_to_relative($url);
        if ($written === null) {
            return $url;
        }
        return $this->to_r2($written, $this->get_key_map()) ?? $url;
    }

    /**
     * Path after the local uploads base, or null if the URL is not under it. Strict prefix match.
     */
    private function url_to_relative(string $url): ?string {
        if (strpos($url, $this->local_base_url) !== 0) {
            return null;
        }
        return ltrim(substr($url, strlen($this->local_base_url)), '/');
    }

    public function rewrite_content_urls($content) {
        if (empty($content) || !is_string($content)) {
            return $content;
        }
        return $this->rewrite_urls_in($content, false);
    }

    public function start_output_buffer(): void {
        ob_start([$this, 'rewrite_output_buffer']);
    }

    public function rewrite_output_buffer(string $output): string {
        if (empty($output)) {
            return $output;
        }
        // Plain URLs, then JSON-escaped URLs (slashes as \/).
        $output = $this->rewrite_urls_in($output, false);
        $output = $this->rewrite_urls_in($output, true);
        return $output;
    }

    private function rewrite_urls_in(string $text, bool $json_escaped): string {
        $map = $this->get_key_map();
        if (empty($map)) {
            return $text;
        }

        $base_pattern = $this->build_base_pattern($json_escaped);

        if ($json_escaped) {
            $pattern = '#' . $base_pattern . '((?:\\\\/[^\\s"\'<>&\\\\]+)+)#';
            $replace = function ($matches) use ($map) {
                $url = $this->to_r2(str_replace('\\/', '/', ltrim($matches[1], '\\/')), $map);
                return $url === null ? $matches[0] : str_replace('/', '\\/', $url);
            };
        } else {
            $pattern = '#' . $base_pattern . '/([^\s"\'<>\\\\]+)#';
            $replace = function ($matches) use ($map) {
                return $this->to_r2($matches[1], $map) ?? $matches[0];
            };
        }

        return preg_replace_callback($pattern, $replace, $text);
    }

    /**
     * Drop the key map so the next lookup rebuilds it from the manifests.
     */
    public static function forget_map(): void {
        delete_transient(self::MAP_TRANSIENT);
        self::$map_cache = null;
    }

    /**
     * Map of every offloaded file: relative path => R2 key, merged from all attachment
     * manifests. Cached per request and in a short transient.
     *
     * @return array<string,string>
     */
    private function get_key_map(): array {
        if (self::$map_cache !== null) {
            return self::$map_cache;
        }

        $cached = get_transient(self::MAP_TRANSIENT);
        if (is_array($cached)) {
            self::$map_cache = $cached;
            return self::$map_cache;
        }

        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            Manifest::META_KEY
        ));

        $map = [];
        foreach ($rows as $raw) {
            $manifest = maybe_unserialize($raw);
            if (is_array($manifest) && !empty($manifest['files']) && is_array($manifest['files'])) {
                $map = Manifest::keys($manifest) + $map;
            }
        }

        set_transient(self::MAP_TRANSIENT, $map, 5 * MINUTE_IN_SECONDS);
        self::$map_cache = $map;
        return self::$map_cache;
    }

    /**
     * Regex fragment matching the local uploads base URL. Host matched case-insensitively
     * (RFC 3986), path case-sensitive. Optionally with JSON-escaped slashes.
     */
    private function build_base_pattern(bool $json_escaped = false): string {
        $parsed = parse_url($this->local_base_url);

        if (!$parsed || empty($parsed['host'])) {
            $base = $json_escaped ? str_replace('/', '\\/', $this->local_base_url) : $this->local_base_url;
            return preg_quote($base, '#');
        }

        $host_pos = strpos($this->local_base_url, $parsed['host']);
        if ($host_pos === false) {
            $base = $json_escaped ? str_replace('/', '\\/', $this->local_base_url) : $this->local_base_url;
            return preg_quote($base, '#');
        }

        $before_host = substr($this->local_base_url, 0, $host_pos);
        $after_host = substr($this->local_base_url, $host_pos + strlen($parsed['host']));

        if ($json_escaped) {
            $before_host = str_replace('/', '\\/', $before_host);
            $after_host = str_replace('/', '\\/', $after_host);
        }

        return preg_quote($before_host, '#')
            . '(?i:' . preg_quote($parsed['host'], '#') . ')'
            . preg_quote($after_host, '#');
    }
}
