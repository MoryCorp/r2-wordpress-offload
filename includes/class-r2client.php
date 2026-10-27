<?php
namespace NumosR2;

defined('ABSPATH') || exit;

class R2Client {

    private string $account_id;
    private string $access_key;
    private string $secret_key;
    private string $bucket;
    private string $public_url;
    private string $endpoint;
    private string $region = 'auto';

    /**
     * Stored on every uploaded object. Safe as immutable because keys are content-addressed
     * (see Manifest::content_key): a changed payload lands on a new key, never this one.
     */
    const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    private static ?bool $available_cache = null;

    private $persistent_ch = null;

    private static function curl_id($ch): int {
        return PHP_MAJOR_VERSION >= 8 ? spl_object_id($ch) : (int)$ch;
    }

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

    private function get_persistent_handle() {
        if (!$this->persistent_ch) {
            $this->persistent_ch = curl_init();
        }
        curl_reset($this->persistent_ch);
        return $this->persistent_ch;
    }

    /**
     * Run a request on the persistent handle with the connection options shared by every
     * call; $options win over them.
     *
     * @return array{0:string|bool,1:int,2:string} response body, HTTP code, cURL error
     */
    private function send(array $options): array {
        $ch = $this->get_persistent_handle();
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE => 1,
        ]);
        return [curl_exec($ch), curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_error($ch)];
    }

    public function upload_with_retry(string $local_path, string $r2_key, int $max_retries = 3, ?string $known_md5 = null) {
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
                    // A multipart ETag (md5-N) is not the MD5 of the payload.
                    $r2_etag = trim($result['etag'], '"');
                    if (strpos($r2_etag, '-') === false) {
                        $local_md5 = $known_md5 ?? md5_file($local_path);
                        if ($local_md5 !== $r2_etag) {
                            throw new \Exception("Integrity check failed: local MD5 ({$local_md5}) != R2 ETag ({$r2_etag})");
                        }
                    }

                    Logger::log('info', "Successfully uploaded {$r2_key} (attempt {$attempt})");

                    return [
                        'url' => $this->get_public_url($r2_key),
                        'etag' => $r2_etag,
                    ];
                }

                throw new \Exception('Upload returned false');

            } catch (\Exception $e) {
                $last_error = $e->getMessage();
                Logger::log('warning', "Upload attempt {$attempt} failed for {$r2_key}: {$last_error}");

                if ($attempt < $max_retries) {
                    sleep(pow(2, $attempt - 1));
                }
            }
        }

        Logger::log('error', "Upload failed after {$max_retries} attempts for {$r2_key}: {$last_error}");
        return false;
    }

    /**
     * Signed PUT request shared by the sequential and parallel upload paths, so both store the
     * same Cache-Control and sign the same header set. The payload is UNSIGNED-PAYLOAD: integrity
     * is checked against the returned ETag.
     *
     * @return array{0:string,1:array<string,string>,2:int}|null [url, signed headers, size], or null if unreadable
     */
    private function build_put_request(string $local_path, string $key): ?array {
        $file_size = filesize($local_path);
        if ($file_size === false) {
            return null;
        }

        $encoded_key = self::encode_key(ltrim($key, '/'));
        $uri = "/{$this->bucket}/{$encoded_key}";
        $url = "{$this->endpoint}/{$this->bucket}/{$encoded_key}";

        $headers = [
            'Content-Type' => self::mime_type($local_path),
            'Content-Length' => $file_size,
            'Cache-Control' => self::CACHE_CONTROL,
            'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
        ];

        return [$url, $this->sign_request('PUT', $uri, $headers, ''), $file_size];
    }

    public function put_object(string $local_path, string $key) {
        $key = ltrim($key, '/');
        $req = $this->build_put_request($local_path, $key);
        if ($req === null) {
            return false;
        }

        $fp = fopen($local_path, 'rb');
        if ($fp === false) {
            Logger::log('error', "Cannot open file for reading: {$local_path}");
            return false;
        }

        $response_headers = [];
        $ch = $this->get_persistent_handle();

        try {
            curl_setopt_array($ch, $this->put_options($req, $fp, $response_headers));

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
     * @param array $files [['local_path' => string, 'r2_key' => string], ...]
     * @return array Keyed by r2_key: ['success' => bool, 'url' => ?string, 'etag' => ?string]
     */
    public function upload_files_parallel(array $files, int $concurrency = 10): array {
        if (empty($files)) {
            return [];
        }

        if (count($files) === 1) {
            $file = $files[0];
            $retry = $this->upload_with_retry($file['local_path'], $file['r2_key'], 3, $file['md5'] ?? null);
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
                    'md5' => $file['md5'] ?? null,
                    'fp' => $ch_info['fp'],
                ];
            }

            if (empty($active)) {
                break;
            }

            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }

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
                    $results[$r2_key] = ['success' => false, 'url' => null, 'etag' => null, '_retry' => $h['local_path'], '_retry_md5' => $h['md5'] ?? null];
                    Logger::log('warning', "Parallel upload failed for {$r2_key}: " . ($error ?: "HTTP {$code}"));
                } else {
                    $etag = trim($resp_headers[$id]['etag'] ?? '', '"');

                    // A multipart ETag (md5-N) is not the MD5 of the payload.
                    if (strpos($etag, '-') !== false) {
                        $results[$r2_key] = [
                            'success' => true,
                            'url' => $this->get_public_url($r2_key),
                            'etag' => $etag,
                        ];
                    } else {
                        $local_md5 = $h['md5'] ?? md5_file($h['local_path']);

                        if ($local_md5 === $etag) {
                            $results[$r2_key] = [
                                'success' => true,
                                'url' => $this->get_public_url($r2_key),
                                'etag' => $etag,
                            ];
                        } else {
                            $results[$r2_key] = ['success' => false, 'url' => null, 'etag' => null, '_retry' => $h['local_path'], '_retry_md5' => $h['md5'] ?? null];
                            Logger::log('warning', "MD5 mismatch for {$r2_key} in parallel upload, will retry");
                        }
                    }
                }

                unset($resp_headers[$id]);
            }
        }

        curl_multi_close($mh);

        foreach ($results as $r2_key => &$r) {
            if (!$r['success'] && isset($r['_retry'])) {
                $local_path = $r['_retry'];
                $retry_md5 = $r['_retry_md5'] ?? null;
                unset($r['_retry'], $r['_retry_md5']);

                $retry = $this->upload_with_retry($local_path, $r2_key, 2, $retry_md5);
                if ($retry) {
                    $r = ['success' => true, 'url' => $retry['url'], 'etag' => $retry['etag']];
                }
            }
            unset($r['_retry'], $r['_retry_md5']);
        }
        unset($r);

        return $results;
    }

    private function prepare_put_handle(string $local_path, string $r2_key, array &$resp_headers_store) {
        $req = $this->build_put_request($local_path, $r2_key);
        if ($req === null) {
            return false;
        }

        $fp = fopen($local_path, 'rb');
        if ($fp === false) {
            return false;
        }

        $ch = curl_init();
        $id = self::curl_id($ch);
        $resp_headers_store[$id] = [];
        curl_setopt_array($ch, $this->put_options($req, $fp, $resp_headers_store[$id]) + [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        ]);

        return ['ch' => $ch, 'fp' => $fp];
    }

    /**
     * @param array $req [url, signed headers, size] from build_put_request()
     */
    private function put_options(array $req, $fp, array &$headers): array {
        [$url, $signed_headers, $file_size] = $req;
        return [
            CURLOPT_URL => $url,
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $file_size,
            CURLOPT_HTTPHEADER => $this->format_headers($signed_headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 280,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_HEADERFUNCTION => self::header_collector($headers),
        ];
    }

    /**
     * Stores each response header in $headers under its lowercased name.
     */
    private static function header_collector(array &$headers): callable {
        return function ($ch, $header) use (&$headers) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        };
    }

    /**
     * Download an object to a local path. The download lands in a temp file on the same
     * volume, is checked for integrity, then renamed atomically: a reader never sees a
     * partial file, and an interrupted or corrupt download never replaces the target. When
     * $expected_md5 is a real MD5 (not empty, not a multipart ETag) the bytes are verified
     * before the rename.
     */
    public function get_object(string $key, string $local_path, ?string $expected_md5 = null): bool {
        $key = ltrim($key, '/');
        $encoded_key = self::encode_key($key);
        $url = "{$this->endpoint}/{$this->bucket}/{$encoded_key}";
        $uri = "/{$this->bucket}/{$encoded_key}";

        $content_hash = hash('sha256', '');
        $signed_headers = $this->sign_request('GET', $uri, ['x-amz-content-sha256' => $content_hash], '');

        $dir = dirname($local_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $tmp = $local_path . '.r2tmp-' . wp_generate_password(8, false);
        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            Logger::log('error', "Cannot open temp file for writing: {$tmp}");
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
            @unlink($tmp);
            Logger::log('error', "GET failed for {$key}: HTTP {$code}" . ($error ? " - {$error}" : ''));
            return false;
        }

        if ($expected_md5 !== null && $expected_md5 !== '' && strpos($expected_md5, '-') === false) {
            if (md5_file($tmp) !== $expected_md5) {
                @unlink($tmp);
                Logger::log('error', "Integrity mismatch restoring {$key}; download discarded");
                return false;
            }
        }

        // link() refuses an existing target, unlike rename(): a file that took this path during
        // the download is kept. rename() remains for filesystems without hard links.
        $placed = @link($tmp, $local_path) || (!file_exists($local_path) && @rename($tmp, $local_path));
        @unlink($tmp);
        if (!$placed) {
            Logger::log('error', "Could not move restored file into place: {$local_path}");
            return false;
        }

        return true;
    }

    public function delete_object(string $key): bool {
        $response = $this->request('DELETE', $key);

        if ($response['code'] === 204 || $response['code'] === 200) {
            Logger::log('info', "Deleted {$key} from R2");
            return true;
        }

        Logger::log('error', "DELETE failed for {$key}: HTTP {$response['code']}");
        return false;
    }

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

    public function head_bucket(): bool {
        $cached = get_transient('numos_r2_health_status');

        if ($cached !== false) {
            self::$available_cache = ($cached === 'up');
            return self::$available_cache;
        }

        $url = "{$this->endpoint}/{$this->bucket}";
        $uri = "/{$this->bucket}";
        $headers = $this->sign_request('HEAD', $uri, [], '');

        [, $code] = $this->send([
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_HTTPHEADER => $this->format_headers($headers),
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $is_up = ($code === 200);

        set_transient('numos_r2_health_status', $is_up ? 'up' : 'down', $is_up ? 60 : 30);
        self::$available_cache = $is_up;

        if (!$is_up) {
            Logger::log('warning', "R2 health check failed: HTTP {$code}");
        }

        return $is_up;
    }

    public function is_available(): bool {
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

    public function get_public_url(string $key): string {
        return $this->public_url . '/' . ltrim($key, '/');
    }

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

        // SigV4 signs the query parameters in sorted order.
        ksort($query_params);
        $query_string = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

        $uri = "/{$this->bucket}";
        $url = "{$this->endpoint}/{$this->bucket}?{$query_string}";

        $content_hash = hash('sha256', '');
        $headers = [
            'x-amz-content-sha256' => $content_hash,
        ];

        $signed_headers = $this->sign_request('GET', $uri, $headers, '', $query_string);

        [$response_body, $code, $error] = $this->send([
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $this->format_headers($signed_headers),
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($error) {
            Logger::log('error', "cURL error listing objects: {$error}");
            return false;
        }

        if ($code !== 200) {
            Logger::log('error', "List objects failed: HTTP {$code} - {$response_body}");
            return false;
        }

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
     * List every object under a prefix, following pagination. Null when any page fails: a
     * partial listing would pass for "these objects do not exist".
     *
     * @return array<string,array{size:int,etag:string}>|null
     */
    public function list_all_objects(string $prefix): ?array {
        $all = [];
        $token = '';
        do {
            $result = $this->list_objects($prefix, $token, 1000);
            if ($result === false) {
                return null;
            }
            foreach ($result['objects'] as $obj) {
                $all[$obj['key']] = ['size' => $obj['size'], 'etag' => $obj['etag']];
            }
            $token = $result['next_token'];
        } while ($result['is_truncated']);
        return $all;
    }

    public function delete_objects(array $keys): array {
        $results = [
            'deleted' => 0,
            'errors' => [],
        ];

        if (empty($keys)) {
            return $results;
        }

        // DeleteObjects takes at most 1000 keys per request.
        $chunks = array_chunk($keys, 1000);

        foreach ($chunks as $chunk) {
            $batch_result = $this->batch_delete($chunk);
            $results['deleted'] += $batch_result['deleted'];
            $results['errors'] = array_merge($results['errors'], $batch_result['errors']);
        }

        return $results;
    }

    private function batch_delete(array $keys): array {
        $result = ['deleted' => 0, 'errors' => []];

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

        [$response_body, $code, $error] = $this->send([
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => $this->format_headers($signed_headers),
            CURLOPT_TIMEOUT => 60,
        ]);

        if ($error) {
            Logger::log('error', "cURL error batch deleting: {$error}");
            return $this->sequential_delete($keys);
        }

        if ($code === 200) {
            // Quiet mode: the response lists only the keys that failed.
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

    private function request(string $method, string $key, array $extra_headers = [], string $body = ''): array {
        $key = ltrim($key, '/');
        $encoded_key = self::encode_key($key);
        $url = "{$this->endpoint}/{$this->bucket}/{$encoded_key}";
        $uri = "/{$this->bucket}/{$encoded_key}";

        $content_hash = hash('sha256', $body);
        $extra_headers['x-amz-content-sha256'] = $content_hash;

        $headers = $this->sign_request($method, $uri, $extra_headers, $body);

        $response_headers = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->format_headers($headers),
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HEADERFUNCTION => self::header_collector($response_headers),
        ];
        if ($method === 'PUT' || $method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        [$response_body, $code, $error] = $this->send($options);

        if ($error) {
            Logger::log('error', "cURL error: {$error}");
        }

        return [
            'code' => $code,
            'headers' => $response_headers,
            'body' => $response_body,
        ];
    }

    private function sign_request(string $method, string $uri, array $headers, string $body, string $query_string = ''): array {
        $service = 's3';
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $date = $now->format('Ymd');
        $datetime = $now->format('Ymd\THis\Z');

        $host = "{$this->account_id}.r2.cloudflarestorage.com";

        $headers['Host'] = $host;
        $headers['x-amz-date'] = $datetime;

        if (!isset($headers['x-amz-content-sha256'])) {
            $headers['x-amz-content-sha256'] = hash('sha256', $body);
        }

        $lower_headers = [];
        foreach ($headers as $k => $v) {
            $lower_headers[strtolower($k)] = trim($v);
        }

        $signed_headers_list = array_keys($lower_headers);
        sort($signed_headers_list);
        $signed_headers = implode(';', $signed_headers_list);

        $canonical_headers = '';
        foreach ($signed_headers_list as $h) {
            $canonical_headers .= $h . ':' . $lower_headers[$h] . "\n";
        }

        $content_hash = $lower_headers['x-amz-content-sha256'];
        $canonical_request = implode("\n", [
            $method,
            $uri,
            $query_string,
            $canonical_headers,
            $signed_headers,
            $content_hash,
        ]);

        $credential_scope = "{$date}/{$this->region}/{$service}/aws4_request";
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $k_date = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $k_region = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $headers['Authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        return $headers;
    }

    private function format_headers(array $headers): array {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }
        return $formatted;
    }

    public static function mime_type(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $mime_types = [
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
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'wmv' => 'video/x-ms-wmv',
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
