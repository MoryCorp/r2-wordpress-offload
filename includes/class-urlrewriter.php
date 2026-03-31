<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * URL Rewriter - Rewrites media URLs to R2
 */
class UrlRewriter {

    private R2Client $r2_client;
    private array $settings;
    private string $local_base_url;
    private string $r2_base_url;
    private string $prefix;

    /** @var array|null Static cache of synced relative paths (main + thumbnails) */
    private static ?array $synced_paths_cache = null;

    public function __construct(R2Client $r2_client) {
        $this->r2_client = $r2_client;
        $this->settings = get_option('numos_r2_settings', []);
        $this->r2_base_url = rtrim(NUMOS_R2_PUBLIC_URL, '/');
        $this->prefix = numos_r2_get_site_prefix();

        $upload_dir = wp_upload_dir();
        $this->local_base_url = $upload_dir['baseurl'];
    }

    /**
     * Register WordPress hooks
     */
    public function register_hooks(): void {
        // Only if enabled
        if (empty($this->settings['enabled'])) {
            return;
        }

        // Attachment URL hooks
        add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 99, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'rewrite_image_src'], 99, 4);

        // Responsive images (srcset)
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_srcset'], 99, 5);

        // Content URLs (for hardcoded URLs in post content)
        add_filter('the_content', [$this, 'rewrite_content_urls'], 99);
        add_filter('widget_text', [$this, 'rewrite_content_urls'], 99);
        add_filter('widget_text_content', [$this, 'rewrite_content_urls'], 99);

        // ACF support
        if (function_exists('acf')) {
            add_filter('acf/format_value/type=image', [$this, 'rewrite_acf_image'], 99, 3);
            add_filter('acf/format_value/type=gallery', [$this, 'rewrite_acf_gallery'], 99, 3);
            add_filter('acf/format_value/type=url', [$this, 'rewrite_acf_url'], 99, 3);
            add_filter('acf/format_value/type=file', [$this, 'rewrite_acf_file'], 99, 3);
        }

        // Final output buffer — catches everything (Elementor templates, noscript, inline CSS, etc.)
        if (!is_admin()) {
            add_action('template_redirect', [$this, 'start_output_buffer'], 1);
        }

        // AJAX output buffer — catches dynamically loaded content (JetEngine listings, load-more, etc.)
        if (wp_doing_ajax()) {
            add_action('admin_init', [$this, 'start_output_buffer'], 1);
        }

        // REST API output buffer — catches content loaded via REST endpoints
        add_action('rest_api_init', [$this, 'start_output_buffer'], 1);
    }

    /**
     * Rewrite attachment URL to R2
     *
     * @param string $url Original URL
     * @param int $attachment_id Attachment ID
     * @return string Rewritten URL
     */
    public function rewrite_attachment_url(string $url, int $attachment_id): string {
        // Check if synced to R2
        $r2_url = get_post_meta($attachment_id, '_numos_r2_url', true);

        if (empty($r2_url)) {
            // Fallback: derive URL from r2_key if status is synced but _numos_r2_url is missing
            $status = get_post_meta($attachment_id, '_numos_r2_status', true);
            if ($status === 'synced') {
                $r2_key = get_post_meta($attachment_id, '_numos_r2_key', true);
                if (!empty($r2_key)) {
                    $r2_url = $this->r2_client->get_public_url($r2_key);
                    update_post_meta($attachment_id, '_numos_r2_url', $r2_url);
                }
            }
        }

        if (empty($r2_url)) {
            return $url;
        }

        // Check if R2 is available (with fallback)
        if (!$this->r2_client->is_available()) {
            return $url; // Fallback to local
        }

        return $r2_url;
    }

    /**
     * Rewrite image src array
     *
     * @param array|false $image Image data or false
     * @param int $attachment_id Attachment ID
     * @param string|int[] $size Image size
     * @param bool $icon Whether icon
     * @return array|false
     */
    public function rewrite_image_src($image, int $attachment_id, $size, bool $icon) {
        if (!is_array($image) || empty($image[0])) {
            return $image;
        }

        // Check if R2 is available
        if (!$this->r2_client->is_available()) {
            return $image;
        }

        // Rewrite URL in the array
        $image[0] = $this->maybe_rewrite_url($image[0], $attachment_id);

        return $image;
    }

    /**
     * Rewrite srcset URLs
     *
     * @param array $sources Array of image sources
     * @param array $size_array Size array
     * @param string $image_src Image source URL
     * @param array $image_meta Image metadata
     * @param int $attachment_id Attachment ID
     * @return array
     */
    public function rewrite_srcset(array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id): array {
        // Check if R2 is available
        if (!$this->r2_client->is_available()) {
            return $sources;
        }

        // Check if attachment is synced
        $status = get_post_meta($attachment_id, '_numos_r2_status', true);
        if ($status !== 'synced') {
            return $sources;
        }

        foreach ($sources as $width => &$source) {
            $source['url'] = $this->rewrite_local_to_r2($source['url']);
        }

        return $sources;
    }

    /**
     * Rewrite URLs in post content (only for synced files)
     *
     * @param string $content Post content
     * @return string
     */
    public function rewrite_content_urls(string $content): string {
        if (empty($content)) {
            return $content;
        }

        // Check if R2 is available
        if (!$this->r2_client->is_available()) {
            return $content;
        }

        // Only rewrite URLs for files that are actually synced
        $synced_paths = $this->get_synced_paths();

        if (empty($synced_paths)) {
            return $content;
        }

        $escaped_base = preg_quote($this->local_base_url, '#');
        $r2_url_with_prefix = $this->r2_base_url . '/' . $this->prefix;

        return preg_replace_callback(
            '#' . $escaped_base . '/([^\s"\'<>]+)#',
            function ($matches) use ($synced_paths, $r2_url_with_prefix) {
                $relative_path = $matches[1];

                // Decode URL-encoded characters for comparison
                $decoded_path = urldecode($relative_path);

                if (isset($synced_paths[$relative_path]) || isset($synced_paths[$decoded_path])) {
                    return $r2_url_with_prefix . '/' . $relative_path;
                }

                return $matches[0]; // Keep original URL for non-synced files
            },
            $content
        );
    }

    /**
     * Get all synced relative paths (main files + thumbnails + originals)
     * Cached statically per request.
     *
     * @return array Associative array of relative paths (used as a set)
     */
    private function get_synced_paths(): array {
        if (self::$synced_paths_cache !== null) {
            return self::$synced_paths_cache;
        }

        // Try transient cache first
        $cached = get_transient('numos_r2_synced_paths');
        if ($cached !== false) {
            self::$synced_paths_cache = $cached;
            return self::$synced_paths_cache;
        }

        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT pm_file.meta_value as file_path, pm_meta.meta_value as metadata
            FROM {$wpdb->postmeta} pm_status
            INNER JOIN {$wpdb->postmeta} pm_file
                ON pm_status.post_id = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->postmeta} pm_meta
                ON pm_status.post_id = pm_meta.post_id AND pm_meta.meta_key = '_wp_attachment_metadata'
            WHERE pm_status.meta_key = '_numos_r2_status'
            AND pm_status.meta_value = %s",
            'synced'
        ));

        $synced = [];

        foreach ($results as $row) {
            if (empty($row->file_path)) {
                continue;
            }

            // Add main file
            $synced[$row->file_path] = true;

            // Add thumbnails and original from metadata
            if (!empty($row->metadata)) {
                $meta = maybe_unserialize($row->metadata);
                $dir = dirname($row->file_path);
                $dir_prefix = ($dir === '.') ? '' : $dir . '/';

                if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                    foreach ($meta['sizes'] as $size) {
                        if (!empty($size['file'])) {
                            $synced[$dir_prefix . $size['file']] = true;
                        }
                    }
                }

                if (!empty($meta['original_image'])) {
                    $synced[$dir_prefix . $meta['original_image']] = true;
                }
            }
        }

        set_transient('numos_r2_synced_paths', $synced, 5 * MINUTE_IN_SECONDS);
        self::$synced_paths_cache = $synced;
        return self::$synced_paths_cache;
    }

    /**
     * Rewrite ACF image field
     *
     * @param mixed $value Field value
     * @param int $post_id Post ID
     * @param array $field Field settings
     * @return mixed
     */
    public function rewrite_acf_image($value, int $post_id, array $field) {
        if (empty($value)) {
            return $value;
        }

        // Check if R2 is available
        if (!$this->r2_client->is_available()) {
            return $value;
        }

        // Array format (image array)
        if (is_array($value)) {
            if (isset($value['url'])) {
                $value['url'] = $this->maybe_rewrite_url($value['url'], $value['id'] ?? 0);
            }
            if (isset($value['sizes']) && is_array($value['sizes'])) {
                foreach ($value['sizes'] as $size => &$url) {
                    if (is_string($url)) {
                        $url = $this->rewrite_local_to_r2($url);
                    }
                }
            }
        }
        // String format (URL only)
        elseif (is_string($value)) {
            $value = $this->rewrite_local_to_r2($value);
        }

        return $value;
    }

    /**
     * Rewrite ACF gallery field
     *
     * @param mixed $value Field value
     * @param int $post_id Post ID
     * @param array $field Field settings
     * @return mixed
     */
    public function rewrite_acf_gallery($value, int $post_id, array $field) {
        if (empty($value) || !is_array($value)) {
            return $value;
        }

        foreach ($value as &$image) {
            $image = $this->rewrite_acf_image($image, $post_id, $field);
        }

        return $value;
    }

    /**
     * Rewrite ACF URL field
     *
     * @param mixed $value Field value
     * @param int $post_id Post ID
     * @param array $field Field settings
     * @return mixed
     */
    public function rewrite_acf_url($value, int $post_id, array $field) {
        if (empty($value) || !is_string($value)) {
            return $value;
        }

        if (!$this->r2_client->is_available()) {
            return $value;
        }

        return $this->rewrite_local_to_r2_if_synced($value);
    }

    /**
     * Rewrite ACF file field
     *
     * @param mixed $value Field value
     * @param int $post_id Post ID
     * @param array $field Field settings
     * @return mixed
     */
    public function rewrite_acf_file($value, int $post_id, array $field) {
        if (empty($value)) {
            return $value;
        }

        if (!$this->r2_client->is_available()) {
            return $value;
        }

        if (is_array($value) && isset($value['url'])) {
            $value['url'] = $this->rewrite_local_to_r2_if_synced($value['url']);
        } elseif (is_string($value)) {
            $value = $this->rewrite_local_to_r2_if_synced($value);
        }

        return $value;
    }

    /**
     * Rewrite a local URL to R2 only if the file is in synced paths
     *
     * @param string $url URL to check
     * @return string
     */
    private function rewrite_local_to_r2_if_synced(string $url): string {
        if (strpos($url, $this->local_base_url) !== 0) {
            return $url;
        }

        $relative_path = substr($url, strlen($this->local_base_url) + 1);
        $synced_paths = $this->get_synced_paths();

        if (isset($synced_paths[$relative_path]) || isset($synced_paths[urldecode($relative_path)])) {
            return $this->r2_base_url . '/' . $this->prefix . '/' . $relative_path;
        }

        return $url;
    }

    /**
     * Maybe rewrite a URL if the attachment is synced
     *
     * @param string $url URL to check
     * @param int $attachment_id Attachment ID (optional)
     * @return string
     */
    private function maybe_rewrite_url(string $url, int $attachment_id = 0): string {
        // If we have an attachment ID, check if it's synced
        if ($attachment_id > 0) {
            $status = get_post_meta($attachment_id, '_numos_r2_status', true);
            if ($status !== 'synced') {
                return $url;
            }
        }

        return $this->rewrite_local_to_r2($url);
    }

    /**
     * Rewrite a local URL to R2
     *
     * @param string $url Local URL
     * @return string R2 URL
     */
    private function rewrite_local_to_r2(string $url): string {
        // Only rewrite if URL starts with our local uploads base (strict prefix match)
        if (strpos($url, $this->local_base_url) !== 0) {
            return $url;
        }

        // Replace the local base prefix with R2 URL + site prefix
        $r2_url_with_prefix = $this->r2_base_url . '/' . $this->prefix;
        return $r2_url_with_prefix . substr($url, strlen($this->local_base_url));
    }

    /**
     * Start output buffer to rewrite all URLs in the final HTML
     */
    public function start_output_buffer(): void {
        if (!$this->r2_client->is_available()) {
            return;
        }

        ob_start([$this, 'rewrite_output_buffer']);
    }

    /**
     * Rewrite local upload URLs in the final HTML/JSON output.
     * Only rewrites URLs for files that are actually synced to R2.
     * Handles both plain URLs and JSON-escaped URLs (with \/ slashes).
     *
     * @param string $output Full output (HTML or JSON)
     * @return string Rewritten output
     */
    public function rewrite_output_buffer(string $output): string {
        if (empty($output)) {
            return $output;
        }

        $synced_paths = $this->get_synced_paths();
        if (empty($synced_paths)) {
            return $output;
        }

        $r2_url_with_prefix = $this->r2_base_url . '/' . $this->prefix;

        // Pass 1: plain URLs (HTML, CSS, unescaped JSON)
        $escaped_base = preg_quote($this->local_base_url, '#');
        $output = preg_replace_callback(
            '#' . $escaped_base . '/([^\s"\'<>\\\\]+)#',
            function ($matches) use ($synced_paths, $r2_url_with_prefix) {
                $relative_path = $matches[1];
                $decoded_path = urldecode($relative_path);

                if (isset($synced_paths[$relative_path]) || isset($synced_paths[$decoded_path])) {
                    return $r2_url_with_prefix . '/' . $relative_path;
                }

                return $matches[0];
            },
            $output
        );

        // Pass 2: JSON-escaped URLs (slashes escaped as \/)
        $json_escaped_base = str_replace('/', '\\/', $this->local_base_url);
        $escaped_json_base = preg_quote($json_escaped_base, '#');
        $json_r2_prefix = str_replace('/', '\\/', $r2_url_with_prefix);

        $output = preg_replace_callback(
            '#' . $escaped_json_base . '((?:\\\\/[^\\s"\'<>&\\\\]+)+)#',
            function ($matches) use ($synced_paths, $json_r2_prefix) {
                // Unescape the path for lookup (capture includes leading \/)
                $escaped_path = $matches[1];
                $relative_path = str_replace('\\/', '/', ltrim($escaped_path, '\\/'));
                $decoded_path = urldecode($relative_path);

                if (isset($synced_paths[$relative_path]) || isset($synced_paths[$decoded_path])) {
                    return $json_r2_prefix . $escaped_path;
                }

                return $matches[0];
            },
            $output
        );

        return $output;
    }

}
