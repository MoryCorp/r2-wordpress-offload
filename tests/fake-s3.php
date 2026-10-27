<?php
/**
 * Router for `php -S`: a path-style S3 endpoint covering what R2Client uses (PUT, GET, HEAD,
 * DELETE, DeleteObjects, ListObjectsV2). Signatures are not checked.
 *
 * State lives under $R2T_STATE/s3: object bytes in data/<key>, metadata in meta/<key>.json.
 * An object may exist as metadata only (imported from a real bucket listing); GET then 404s.
 * Faults are read from $R2T_STATE/faults.json on every request:
 *   bucket_status       HTTP status for HEAD on the bucket (simulates an outage)
 *   truncate_get        {key: bytes} sends the full Content-Length but only that many bytes
 *   stall_get           {key: bytes} sends that many bytes, then hangs (client killed mid-download)
 *   delay_get           {key: seconds} waits that long before sending the body
 *   list_fail_paged     any listing request carrying a continuation token returns 500
 */

$state = getenv('R2T_STATE');
$data_root = "$state/s3/data";
$meta_root = "$state/s3/meta";
$faults = json_decode((string) @file_get_contents("$state/faults.json"), true) ?: [];

$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
[$bucket, $key] = array_pad(explode('/', ltrim($path, '/'), 2), 2, '');

function meta_path(string $root, string $key): string {
    return "$root/$key.json";
}

function read_meta(string $root, string $key): ?array {
    $file = meta_path($root, $key);
    return is_file($file) ? json_decode(file_get_contents($file), true) : null;
}

function send_xml(int $status, string $xml): void {
    http_response_code($status);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . $xml;
}

function remove_object(string $data_root, string $meta_root, string $key): void {
    @unlink("$data_root/$key");
    @unlink(meta_path($meta_root, $key));
}

/** @return string[] every stored key, in S3 byte order */
function all_keys(string $meta_root): array {
    if (!is_dir($meta_root)) {
        return [];
    }
    $keys = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($meta_root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $keys[] = substr($file->getPathname(), strlen($meta_root) + 1, -5);
    }
    sort($keys, SORT_STRING);
    return $keys;
}

const NS = 'http://s3.amazonaws.com/doc/2006-03-01/';

if ($key === '') {
    if ($method === 'HEAD') {
        http_response_code($faults['bucket_status'] ?? 200);
        return true;
    }

    if ($method === 'POST' && array_key_exists('delete', $query)) {
        preg_match_all('#<Key>(.*?)</Key>#s', file_get_contents('php://input'), $m);
        foreach ($m[1] as $deleted) {
            remove_object($data_root, $meta_root, html_entity_decode($deleted, ENT_XML1, 'UTF-8'));
        }
        send_xml(200, '<DeleteResult xmlns="' . NS . '"></DeleteResult>');
        return true;
    }

    if ($method === 'GET' && ($query['list-type'] ?? '') === '2') {
        $token = (string) ($query['continuation-token'] ?? '');
        if ($token !== '' && !empty($faults['list_fail_paged'])) {
            send_xml(500, '<Error><Code>InternalError</Code></Error>');
            return true;
        }
        $prefix = (string) ($query['prefix'] ?? '');
        $max = (int) ($query['max-keys'] ?? 1000);
        $after = $token === '' ? null : base64_decode($token);

        $page = [];
        $truncated = false;
        foreach (all_keys($meta_root) as $k) {
            if (strpos($k, $prefix) !== 0 || ($after !== null && strcmp($k, $after) <= 0)) {
                continue;
            }
            if (count($page) === $max) {
                $truncated = true;
                break;
            }
            $page[] = $k;
        }

        $xml = '<ListBucketResult xmlns="' . NS . '"><Name>' . htmlspecialchars($bucket, ENT_XML1) . '</Name>'
            . '<Prefix>' . htmlspecialchars($prefix, ENT_XML1) . '</Prefix><KeyCount>' . count($page) . '</KeyCount>'
            . "<MaxKeys>$max</MaxKeys><IsTruncated>" . ($truncated ? 'true' : 'false') . '</IsTruncated>';
        if ($truncated) {
            $xml .= '<NextContinuationToken>' . base64_encode(end($page)) . '</NextContinuationToken>';
        }
        foreach ($page as $k) {
            $meta = read_meta($meta_root, $k);
            $xml .= '<Contents><Key>' . htmlspecialchars($k, ENT_XML1) . '</Key>'
                . '<LastModified>' . $meta['last_modified'] . '</LastModified>'
                . '<ETag>"' . $meta['etag'] . '"</ETag><Size>' . $meta['size'] . '</Size>'
                . '<StorageClass>STANDARD</StorageClass></Contents>';
        }
        send_xml(200, $xml . '</ListBucketResult>');
        return true;
    }

    http_response_code(400);
    return true;
}

switch ($method) {
    case 'PUT':
        $body = file_get_contents('php://input');
        @mkdir(dirname("$data_root/$key"), 0777, true);
        @mkdir(dirname(meta_path($meta_root, $key)), 0777, true);
        file_put_contents("$data_root/$key", $body);
        $etag = md5($body);
        file_put_contents(meta_path($meta_root, $key), json_encode([
            'size' => strlen($body),
            'etag' => $etag,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'cache_control' => $_SERVER['HTTP_CACHE_CONTROL'] ?? '',
            'last_modified' => gmdate('Y-m-d\TH:i:s.000\Z'),
        ]));
        header('ETag: "' . $etag . '"');
        http_response_code(200);
        return true;

    case 'HEAD':
    case 'GET':
        $meta = read_meta($meta_root, $key);
        $has_body = is_file("$data_root/$key");
        if ($meta === null || ($method === 'GET' && !$has_body)) {
            send_xml(404, '<Error><Code>NoSuchKey</Code></Error>');
            return true;
        }
        header('ETag: "' . $meta['etag'] . '"');
        header('Content-Type: ' . ($meta['content_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . $meta['size']);
        if ($meta['cache_control'] !== '') {
            header('Cache-Control: ' . $meta['cache_control']);
        }
        if ($method === 'GET') {
            $body = file_get_contents("$data_root/$key");
            sleep((int) ($faults['delay_get'][$key] ?? 0));
            $stall = $faults['stall_get'][$key] ?? null;
            $cut = $faults['truncate_get'][$key] ?? $stall;
            echo $cut === null ? $body : substr($body, 0, (int) $cut);
            if ($stall !== null) {
                flush();
                sleep(20);
            }
        }
        return true;

    case 'DELETE':
        remove_object($data_root, $meta_root, $key);
        http_response_code(204);
        return true;
}

http_response_code(405);
return true;
