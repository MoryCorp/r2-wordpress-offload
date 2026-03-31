<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * R2 Client - Handles all communication with Cloudflare R2 via S3 API
 * Uses cURL with AWS4-HMAC-SHA256 signature
 */
class R2Client {

    private string $account_id;
    private string $access_key;
    private string $secret_key;
    private string $bucket;
    private string $public_url;
    private string $endpoint;
    private string $region = 'auto';

    /** @var bool|null Static cache for is_available() within a single request */
    private static ?bool $available_cache = null;

    /** @var \CurlHandle|null Persistent cURL handle for connection reuse */
    private $persistent_ch = null;

    /**
     * Get a unique integer ID for a cURL handle (PHP 7 resource or PHP 8+ CurlHandle object)
     */
    private static function curl_id($ch): int {
        return PHP_MAJOR_VERSION >= 8 ? spl_object_id($ch) : (int)$ch;
    }

    /**
     * URL-encode an R2 key for use in URI paths (encode each segment individually)
     */
    private static function encode_key(string $key): string {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    public function __construct() {
        $this->account_id = NUMOS_R2_ACCOUNT_ID;
        $this->access_key = NUMOS_R2_ACCESS_KEY;
        $this->secret_key = NUMOS_R2_SECRET_KEY;
        $this->bucket = NUMOS_R2_BUCKET;
        $this->public_url = rtrim(NUMOS_R2_PUBLIC_URL, '/');
        $this->endpoint = "https://{$this->account_id}.r2.cloudflarestorage.com";
    }

    public function __destruct() {
        if ($this->persistent_ch) {
            curl_close($this->persistent_ch);
            $this->persistent_ch = null;
        }
    }

    /**
     * Get or create a persistent cURL handle (reuses TCP+TLS connections)
     */
    private function get_persistent_handle() {
        if (!$this->persistent_ch) {
            $this->persistent_ch = curl_init();
        }
        curl_reset($this->persistent_ch);
        return $this->persistent_ch;
    }

    /**
     * Upload a file to R2 with retry logic
     *
     * @param string $local_path Local file path
     * @param string $r2_key R2 object key (path in bucket)
     * @param int $max_retries Maximum retry attempts
     * @return array|false Array with 'url' and 'etag' on success, false on failure
     */
    public function upload_with_retry(string $local_path, string $r2_key, int $max_retries = 3) {
        if (!file_exists($local_path)) {
            Logger::log('error', "File does not exist: {$local_path}");
            return false;
        }

        $attempt = 0;
        $last_error = null;

        while ($attempt < $max_retries) {
            $attempt++;

            try {
                $result = $this->put_object($local_path, $r2_key);

                if ($result !== false) {
                    // Verify integrity (skip if multipart ETag format: md5-N)
                    $r2_etag = trim($result['etag'], '"');
                    if (strpos($r2_etag, '-') === false) {
                        $local_md5 = md5_file($local_path);
                        if ($local_md5 !== $r2_etag) {
                            throw new \Exception("Integrity check failed: local MD5 ({$local_md5}) != R2 ETag ({$r2_etag})");
                        }
                    }

                    Logger::log('info', "Successfully uploaded {$r2_key} (attempt {$attempt})");

                    return [
                        'url' => $this->public_url . '/' . ltrim($r2_key, '/'),
                        'etag' => $r2_etag,
                    ];
                }

                throw new \Exception('Upload returned false');

            } catch (\Exception $e) {
                $last_error = $e->getMessage();
                Logger::log('warning', "Upload attempt {$attempt} failed for {$r2_key}: {$last_error}");

                if ($attempt < $max_retries) {
                    // Exponential backoff: 1s, 2s, 4s (reduced from 2/4/8 to avoid timeout)
                    sleep(pow(2, $attempt - 1));
                }
            }
        }

        Logger::log('error', "Upload failed after {$max_retries} attempts for {$r2_key}: {$last_error}");
        return false;
    }

    /**
     * Put object to R2
     *
     * @param string $local_path Local file path
     * @param string $key Object key
     * @return array|false Array with 'etag' on success, false on failure
     */
    public function put_object(string $local_path, string $key) {
        $file_size = filesize($local_path);
        if ($file_size === false) {
            return false;
        }

        $content_type = $this->get_mime_type($local_path);

        // Use UNSIGNED-PAYLOAD for all uploads — R2 verifies integrity via ETag/MD5
        $content_hash = 'UNSIGNED-PAYLOAD';

        $key = ltrim($key, '/');
        $encoded_key = self::encode_key($key);
        $uri = "/{$this->bucket}/{$encoded_key}";
        $url = "{$this->endpoint}/{$this->bucket}/{$encoded_key}";

        $headers = [
            'Content-Type' => $content_type,
            'Content-Length' => $file_size,
            'x-amz-content-sha256' => $content_hash,
        ];

        $signed_headers = $this->sign_request('PUT', $uri, $headers, '');

        // Open file for streaming
        $fp = fopen($local_path, 'rb');
        if ($fp === false) {
            Logger::log('error', "Cannot open file for reading: {$local_path}");
            return false;
        }

        $response_headers = [];
        $ch = $this->get_persistent_handle();

        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fp,
                CURLOPT_INFILESIZE => $file_size,
                CURLOPT_HTTPHEADER => $this->format_headers($signed_headers),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 280,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$response_headers) {
                    $len = strlen($header);
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $response_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $len;
                },
            ]);

            $response_body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($error) {
                Logger::log('error', "cURL error uploading {$key}: {$error}");
                return false;
            }

            if ($code === 200) {
                return [
                    'etag' => $response_headers['etag'] ?? '',
                ];
            }

            Logger::log('error', "PUT failed for {$key}: HTTP {$code} - {$response_body}");
            return false;

        } finally {
            fclose($fp);
        }
    }

    /**
     * Upload multiple files in parallel using curl_multi
     *
     * @param array $files Array of ['local_path' => string, 'r2_key' => string]
     * @param int $concurrency Max concurrent uploads
     * @return array Keyed by r2_key: ['success' => bool, 'url' => string|null, 'etag' => string|null]
     */
    public function upload_files_parallel(array $files, int $concurrency = 10): array {
        if (empty($files)) {
            return [];
        }

        // Single file: use sequential path (no curl_multi overhead)
        if (count($files) === 1) {
            $file = $files[0];
            $retry = $this->upload_with_retry($file['local_path'], $file['r2_key']);
            return [
                $file['r2_key'] => $retry
                    ? ['success' => true, 'url' => $retry['url'], 'etag' => $retry['etag']]
                    : ['success' => false, 'url' => null, 'etag' => null],
            ];
        }

        $results = [];
        $resp_headers = [];
        $mh = curl_multi_init();
        $queue = $files;
        $active = []; // resource_id => handle info

        while (!empty($queue) || !empty($active)) {
            // Fill slots up to concurrency
            while (count($active) < $concurrency && !empty($queue)) {
                $file = array_shift($queue);
                $ch_info = $this->prepare_put_handle($file['local_path'], $file['r2_key'], $resp_headers);

                if ($ch_info === false) {
                    $results[$file['r2_key']] = ['success' => false, 'url' => null, 'etag' => null];
                    continue;
                }

                curl_multi_add_handle($mh, $ch_info['ch']);
                $id = self::curl_id($ch_info['ch']);
                $active[$id] = [
                    'ch' => $ch_info['ch'],
                    'r2_key' => $file['r2_key'],
                    'local_path' => $file['local_path'],
                    'fp' => $ch_info['fp'],
                ];
            }

            if (empty($active)) {
                break;
            }

            // Execute
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }

            // Process completed transfers
            while ($info = curl_multi_info_read($mh)) {
                if ($info['msg'] !== CURLMSG_DONE) {
                    continue;
                }

                $ch = $info['handle'];
                $id = self::curl_id($ch);

                if (!isset($active[$id])) {
                    continue;
                }

                $h = $active[$id];
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);

                fclose($h['fp']);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[$id]);

                $r2_key = $h['r2_key'];

                if ($error || $code !== 200) {
                    // Mark for retry
                    $results[$r2_key] = ['success' => false, 'url' => null, 'etag' => null, '_retry' => $h['local_path']];
                    Logger::log('warning', "Parallel upload failed for {$r2_key}: " . ($error ?: "HTTP {$code}"));
                } else {
                    $etag = trim($resp_headers[$id]['etag'] ?? '', '"');

                    // Multipart ETags (contain '-') are not MD5 checksums — skip integrity check
                    if (strpos($etag, '-') !== false) {
                        $results[$r2_key] = [
                            'success' => true,
                            'url' => $this->public_url . '/' . ltrim($r2_key, '/'),
                            'etag' => $etag,
                        ];
                    } else {
                        $local_md5 = md5_file($h['local_path']);

                        if ($local_md5 === $etag) {
                            $results[$r2_key] = [
                                'success' => true,
                                'url' => $this->public_url . '/' . ltrim($r2_key, '/'),
                                'etag' => $etag,
                            ];
                        } else {
                            $results[$r2_key] = ['success' => false, 'url' => null, 'etag' => null, '_retry' => $h['local_path']];
                            Logger::log('warning', "MD5 mismatch for {$r2_key} in parallel upload, will retry");
                        }
                    }
                }

                unset($resp_headers[$id]);
            }
        }

        curl_multi_close($mh);

        // Retry failed uploads sequentially
        foreach ($results as $r2_key => &$r) {
            if (!$r['success'] && isset($r['_retry'])) {
                $local_path = $r['_retry'];
                unset($r['_retry']);

                $retry = $this->upload_with_retry($local_path, $r2_key, 2);
                if ($retry) {
                    $r = ['success' => true, 'url' => $retry['url'], 'etag' => $retry['etag']];
                }
            }
            unset($r['_retry']);
        }
        unset($r);

        return $results;
    }

    /**
     * Prepare a cURL handle for PUT upload (used by parallel uploads)
     *
     * @param string $local_path Local file path
     * @param string $r2_key R2 object key
     * @param array &$resp_headers_store Reference to store response headers by handle ID
     * @return array|false ['ch' => resource, 'fp' => resource] or false on failure
     */
    private function prepare_put_handle(string $local_path, string $r2_key, array &$resp_headers_store) {
        $file_size = filesize($local_path);
        if ($file_size === false) {
            return false;
        }

        $content_type = $this->get_mime_type($local_path);
        $key = ltrim($r2_key, '/');
        $encoded_key = self::encode_key($key);
        $uri = "/{$this->bucket}/{$encoded_key}";
        $url = "{$this->endpoint}/{$this->bucket}/{$encoded_key}";

        $headers = [
            'Content-Type' => $content_type,
            'Content-Length' => $file_size,
            'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
        ];

        $signed_headers = $this->sign_request('PUT', $uri, $headers, '');

        $fp = fopen($local_path, 'rb');
        if ($fp === false) {
            return false;
        }

        $ch = curl_init();
        $id = self::curl_id($ch);
        $resp_headers_store[$id] = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $file_size,
            CURLOPT_HTTPHEADER => $this->format_headers($signed_headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 280,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_HEADERFUNCTION => function ($ch_inner, $header) use (&$resp_headers_store, $id) {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $resp_headers_store[$id][strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        return ['ch' => $ch, 'fp' => $fp];
    }

    /**
     * Get object from R2 (streams to file instead of loading into memory)
     *
     * @param string $key Object key
     * @param string $local_path Local path to save file
     * @return bool Success
     */
    public function get_object(string $key, string $local_path): bool {
        $key = ltrim($key, '/');
        $encoded_key = self::encode_key($key);
        $url = "{$this->endpoint}/{$this->bucket}/{$encoded_key}";
        $uri = "/{$this->bucket}/{$encoded_key}";

        $content_hash = hash('sha256', '');
        $headers = [
            'x-amz-content-sha256' => $content_hash,
        ];

        $signed_headers = $this->sign_request('GET', $uri, $headers, '');

        $dir = dirname($local_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $fp = fopen($local_path, 'wb');
        if ($fp === false) {
            Logger::log('error', "Cannot open file for writing: {$local_path}");
            return false;
        }

        $ch = $this->get_persistent_handle();

        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $this->format_headers($signed_headers),
                CURLOPT_FILE => $fp,
                CURLOPT_TIMEOUT => 280,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TCP_KEEPALIVE => 1,
            ]);

            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

        } finally {
            fclose($fp);
        }

        if ($error || $code !== 200) {
            // Clean up failed download
            if (file_exists($local_path)) {
                unlink($local_path);
            }
            Logger::log('error', "GET failed for {$key}: HTTP {$code}" . ($error ? " - {$error}" : ''));
            return false;
        }

        return true;
    }

    /**
     * Delete object from R2
     *
     * @param string $key Object key
     * @return bool Success
     */
    public function delete_object(string $key): bool {
        $response = $this->request('DELETE', $key);

        // 204 No Content is the expected response for successful delete
        if ($response['code'] === 204 || $response['code'] === 200) {
            Logger::log('info', "Deleted {$key} from R2");
            return true;
        }

        Logger::log('error', "DELETE failed for {$key}: HTTP {$response['code']}");
        return false;
    }

    /**
     * Check if object exists and get its metadata
     *
     * @param string $key Object key
     * @return array|false Metadata array or false if not exists
     */
    public function head_object(string $key) {
        $response = $this->request('HEAD', $key);

        if ($response['code'] === 200) {
            return [
                'etag' => trim($response['headers']['etag'] ?? '', '"'),
                'content_length' => (int)($response['headers']['content-length'] ?? 0),
                'content_type' => $response['headers']['content-type'] ?? '',
            ];
        }

        return false;
    }

    /**
     * Check if bucket is accessible (health check)
     *
     * @return bool
     */
    public function head_bucket(): bool {
        $cached = get_transient('numos_r2_health_status');

        if ($cached !== false) {
            self::$available_cache = ($cached === 'up');
            return self::$available_cache;
        }

        $url = "{$this->endpoint}/{$this->bucket}";
        $uri = "/{$this->bucket}";
        $headers = $this->sign_request('HEAD', $uri, [], '');

        $ch = $this->get_persistent_handle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_HTTPHEADER => $this->format_headers($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TCP_KEEPALIVE => 1,
        ]);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $is_up = ($code === 200);

        // Cache: 60s if up, 30s if down (to retry faster)
        set_transient('numos_r2_health_status', $is_up ? 'up' : 'down', $is_up ? 60 : 30);
        self::$available_cache = $is_up;

        if (!$is_up) {
            Logger::log('warning', "R2 health check failed: HTTP {$code}");
        }

        return $is_up;
    }

    /**
     * Check if R2 is available (cached statically per request + via transient)
     *
     * @return bool
     */
    public function is_available(): bool {
        // Static cache: avoid repeated transient lookups within the same request
        if (self::$available_cache !== null) {
            return self::$available_cache;
        }

        $cached = get_transient('numos_r2_health_status');

        if ($cached !== false) {
            self::$available_cache = ($cached === 'up');
            return self::$available_cache;
        }

        return $this->head_bucket();
    }

    /**
     * Configure CORS on the R2 bucket to allow cross-origin access from the site
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function put_bucket_cors(): array {
        $cors_xml = '<CORSConfiguration><CORSRule>'
            . '<AllowedOrigin>*</AllowedOrigin>'
            . '<AllowedMethod>GET</AllowedMethod>'
            . '<AllowedMethod>HEAD</AllowedMethod>'
            . '<AllowedHeader>*</AllowedHeader>'
            . '<MaxAgeSeconds>86400</MaxAgeSeconds>'
            . '</CORSRule></CORSConfiguration>';

        $content_md5 = base64_encode(md5($cors_xml, true));
        $content_hash = hash('sha256', $cors_xml);

        $uri = "/{$this->bucket}";
        $url = "{$this->endpoint}/{$this->bucket}?cors";
        $query_string = 'cors=';

        $headers = [
            'Content-MD5' => $content_md5,
            'Content-Type' => 'application/xml',
            'x-amz-content-sha256' => $content_hash,
        ];

        $signed_headers = $this->sign_request('PUT', $uri, $headers, $cors_xml, $query_string);

        $ch = $this->get_persistent_handle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $cors_xml,
            CURLOPT_HTTPHEADER => $this->format_headers($signed_headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Reset method for next call on persistent handle
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, null);
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);

        if ($code === 200) {
            Logger::log('info', 'CORS configured on R2 bucket');
            return ['success' => true, 'message' => 'CORS configured'];
        }

        $msg = "CORS configuration failed: HTTP {$code}";
        if ($code === 403) {
            $msg = 'CORS configuration failed: token lacks PutBucketCors permission. Update your R2 API token to "Admin Read & Write" in Cloudflare dashboard.';
        }
        Logger::log('warning', $msg);
        return ['success' => false, 'message' => $msg];
    }

    /**
     * Get the public URL for an object
     *
     * @param string $key Object key
     * @return string Public URL
     */
    public function get_public_url(string $key): string {
        return $this->public_url . '/' . ltrim($key, '/');
    }

    /**
     * List objects in the bucket
     *
     * @param string $prefix Optional prefix filter
     * @param string $continuation_token Token for pagination
     * @param int $max_keys Maximum number of keys to return
     * @return array|false Array with 'objects', 'prefixes', 'next_token', 'is_truncated' or false on error
     */
    public function list_objects(string $prefix = '', string $continuation_token = '', int $max_keys = 1000) {
        $query_params = [
            'list-type' => '2',
            'max-keys' => (string)$max_keys,
        ];

        if ($prefix !== '') {
            $query_params['prefix'] = $prefix;
        }

        if ($continuation_token !== '') {
            $query_params['continuation-token'] = $continuation_token;
        }

        // Sort query params alphabetically (required for signing)
        ksort($query_params);
        $query_string = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

        $uri = "/{$this->bucket}";
        $url = "{$this->endpoint}/{$this->bucket}?{$query_string}";

        $content_hash = hash('sha256', '');
        $headers = [
            'x-amz-content-sha256' => $content_hash,
        ];

        $signed_headers = $this->sign_request('GET', $uri, $headers, '', $query_string);

        $ch = $this->get_persistent_handle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $this->format_headers($signed_headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE => 1,
        ]);

        $response_body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            Logger::log('error', "cURL error listing objects: {$error}");
            return false;
        }

        if ($code !== 200) {
            Logger::log('error', "List objects failed: HTTP {$code} - {$response_body}");
            return false;
        }

        // Parse XML response (save and restore libxml state)
        $previous_libxml_use_internal_errors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response_body);

        if ($xml === false) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous_libxml_use_internal_errors);
            Logger::log('error', 'Failed to parse list objects XML response');
            return false;
        }
        libxml_use_internal_errors($previous_libxml_use_internal_errors);

        $objects = [];
        $prefixes = [];

        // Get namespace (S3 uses xmlns)
        $namespaces = $xml->getNamespaces(true);
        $ns = $namespaces[''] ?? '';

        if ($ns) {
            $xml->registerXPathNamespace('s3', $ns);
            $contents = $xml->xpath('//s3:Contents');
            $common_prefixes = $xml->xpath('//s3:CommonPrefixes/s3:Prefix');
        } else {
            $contents = $xml->Contents ?? [];
            $common_prefixes = [];
        }

        foreach ($contents as $content) {
            $objects[] = [
                'key' => (string)$content->Key,
                'size' => (int)$content->Size,
                'last_modified' => (string)$content->LastModified,
                'etag' => trim((string)$content->ETag, '"'),
            ];
        }

        foreach ($common_prefixes as $cp) {
            $prefixes[] = (string)$cp;
        }

        $is_truncated = ((string)$xml->IsTruncated === 'true');
        $next_token = $is_truncated ? (string)$xml->NextContinuationToken : '';

        return [
            'objects' => $objects,
            'prefixes' => $prefixes,
            'next_token' => $next_token,
            'is_truncated' => $is_truncated,
        ];
    }

    /**
     * List ALL objects under a prefix, handling pagination automatically
     *
     * @param string $prefix Prefix filter
     * @return array Associative array [r2_key => ['size' => int, 'etag' => string]]
     */
    public function list_all_objects(string $prefix): array {
        $all = [];
        $token = '';
        do {
            $result = $this->list_objects($prefix, $token, 1000);
            if ($result === false) break;
            foreach ($result['objects'] as $obj) {
                $all[$obj['key']] = ['size' => $obj['size'], 'etag' => $obj['etag']];
            }
            $token = $result['next_token'];
        } while ($result['is_truncated']);
        return $all;
    }

    /**
     * Delete multiple objects from R2 using S3 batch delete API
     *
     * @param array $keys Array of object keys to delete
     * @return array Results with 'deleted' and 'errors'
     */
    public function delete_objects(array $keys): array {
        $results = [
            'deleted' => 0,
            'errors' => [],
        ];

        if (empty($keys)) {
            return $results;
        }

        // Process in chunks of 1000 (S3 limit)
        $chunks = array_chunk($keys, 1000);

        foreach ($chunks as $chunk) {
            $batch_result = $this->batch_delete($chunk);
            $results['deleted'] += $batch_result['deleted'];
            $results['errors'] = array_merge($results['errors'], $batch_result['errors']);
        }

        return $results;
    }

    /**
     * Batch delete objects via S3 DeleteObjects API
     *
     * @param array $keys Keys to delete (max 1000)
     * @return array Result with 'deleted' and 'errors'
     */
    private function batch_delete(array $keys): array {
        $result = ['deleted' => 0, 'errors' => []];

        // Build XML body
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        $xml .= '<Quiet>true</Quiet>';
        foreach ($keys as $key) {
            $xml .= '<Object><Key>' . htmlspecialchars($key, ENT_XML1, 'UTF-8') . '</Key></Object>';
        }
        $xml .= '</Delete>';

        $content_md5 = base64_encode(md5($xml, true));
        $content_hash = hash('sha256', $xml);

        $uri = "/{$this->bucket}";
        $url = "{$this->endpoint}/{$this->bucket}?delete";

        $headers = [
            'Content-Type' => 'application/xml',
            'Content-MD5' => $content_md5,
            'Content-Length' => strlen($xml),
            'x-amz-content-sha256' => $content_hash,
        ];

        $signed_headers = $this->sign_request('POST', $uri, $headers, $xml, 'delete=');

        $ch = $this->get_persistent_handle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => $this->format_headers($signed_headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE => 1,
        ]);

        $response_body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            Logger::log('error', "cURL error batch deleting: {$error}");
            // Fallback to sequential delete
            return $this->sequential_delete($keys);
        }

        if ($code === 200) {
            // Parse response for errors (Quiet mode only returns errors)
            $previous = libxml_use_internal_errors(true);
            $response_xml = simplexml_load_string($response_body);
            libxml_use_internal_errors($previous);

            if ($response_xml) {
                $ns = $response_xml->getNamespaces(true);
                if (!empty($ns[''])) {
                    $response_xml->registerXPathNamespace('s3', $ns['']);
                    $errors = $response_xml->xpath('//s3:Error');
                } else {
                    $errors = $response_xml->Error ?? [];
                }

                foreach ($errors as $err) {
                    $result['errors'][] = (string)$err->Key;
                }
            }

            $result['deleted'] = count($keys) - count($result['errors']);
            Logger::log('info', "Batch deleted {$result['deleted']} objects from R2");
        } else {
            Logger::log('warning', "Batch delete failed (HTTP {$code}), falling back to sequential");
            return $this->sequential_delete($keys);
        }

        return $result;
    }

    /**
     * Sequential delete fallback
     *
     * @param array $keys Keys to delete
     * @return array Result with 'deleted' and 'errors'
     */
    private function sequential_delete(array $keys): array {
        $result = ['deleted' => 0, 'errors' => []];

        foreach ($keys as $key) {
            if ($this->delete_object($key)) {
                $result['deleted']++;
            } else {
                $result['errors'][] = $key;
            }
        }

        return $result;
    }

    /**
     * Make a signed request to R2
     *
     * @param string $method HTTP method
     * @param string $key Object key
     * @param array $extra_headers Additional headers
     * @param string $body Request body
     * @return array Response with 'code', 'headers', 'body'
     */
    private function request(string $method, string $key, array $extra_headers = [], string $body = ''): array {
        $key = ltrim($key, '/');
        $encoded_key = self::encode_key($key);
        $url = "{$this->endpoint}/{$this->bucket}/{$encoded_key}";
        $uri = "/{$this->bucket}/{$encoded_key}";

        $content_hash = hash('sha256', $body);
        $extra_headers['x-amz-content-sha256'] = $content_hash;

        $headers = $this->sign_request($method, $uri, $extra_headers, $body);

        $response_headers = [];
        $ch = $this->get_persistent_handle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->format_headers($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$response_headers) {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $response_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        $response_body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            Logger::log('error', "cURL error: {$error}");
        }

        return [
            'code' => $code,
            'headers' => $response_headers,
            'body' => $response_body,
        ];
    }

    /**
     * Sign a request using AWS4-HMAC-SHA256
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param array $headers Additional headers
     * @param string $body Request body
     * @param string $query_string Query string for canonical request (default empty)
     * @return array Signed headers
     */
    private function sign_request(string $method, string $uri, array $headers, string $body, string $query_string = ''): array {
        $service = 's3';
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $date = $now->format('Ymd');
        $datetime = $now->format('Ymd\THis\Z');

        $host = "{$this->account_id}.r2.cloudflarestorage.com";

        // Add required headers
        $headers['Host'] = $host;
        $headers['x-amz-date'] = $datetime;

        if (!isset($headers['x-amz-content-sha256'])) {
            $headers['x-amz-content-sha256'] = hash('sha256', $body);
        }

        // Create lowercase header map for O(n) lookup
        $lower_headers = [];
        foreach ($headers as $k => $v) {
            $lower_headers[strtolower($k)] = trim($v);
        }

        // Sort headers for signing
        $signed_headers_list = array_keys($lower_headers);
        sort($signed_headers_list);
        $signed_headers = implode(';', $signed_headers_list);

        // Create canonical headers (O(n) with pre-built map)
        $canonical_headers = '';
        foreach ($signed_headers_list as $h) {
            $canonical_headers .= $h . ':' . $lower_headers[$h] . "\n";
        }

        // Create canonical request
        $content_hash = $lower_headers['x-amz-content-sha256'];
        $canonical_request = implode("\n", [
            $method,
            $uri,
            $query_string,
            $canonical_headers,
            $signed_headers,
            $content_hash,
        ]);

        // Create string to sign
        $credential_scope = "{$date}/{$this->region}/{$service}/aws4_request";
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        // Calculate signature
        $k_date = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $k_region = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        // Create authorization header
        $headers['Authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        return $headers;
    }

    /**
     * Format headers for cURL
     *
     * @param array $headers Associative array of headers
     * @return array Formatted headers array
     */
    private function format_headers(array $headers): array {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }
        return $formatted;
    }

    /**
     * Get MIME type of a file
     *
     * @param string $path File path
     * @return string MIME type
     */
    private function get_mime_type(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $mime_types = [
            // Images
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            'avif' => 'image/avif',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            // Documents
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'zip' => 'application/zip',
            // Video
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'wmv' => 'video/x-ms-wmv',
            // Audio
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            'wma' => 'audio/x-ms-wma',
        ];

        if (isset($mime_types[$extension])) {
            return $mime_types[$extension];
        }

        // Fallback to finfo
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime) {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }
}
