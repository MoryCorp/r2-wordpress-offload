<?php
// Every size WordPress derives for a new upload must point at an object that exists.
require __DIR__ . '/../lib.php';

r2t_reset();
$id = r2t_upload(r2t_image(11), 'tailles.jpg');

$broken = [];
foreach (['thumbnail', 'medium', 'large', 'full'] as $size) {
    $src = wp_get_attachment_image_src($id, $size)[0] ?? '';
    $key = r2t_key_of_url($src);
    if ($key === null || r2t_store_meta($key) === null) {
        $broken[] = "$size ($src)";
    }
}

$img = wp_get_attachment_image($id, 'large');
preg_match('#srcset="([^"]+)"#', $img, $m);
$candidates = empty($m[1]) ? [] : array_map(function ($c) { return strtok(trim($c), ' '); }, explode(',', $m[1]));
foreach ($candidates as $url) {
    $key = r2t_key_of_url($url);
    if ($key === null || r2t_store_meta($key) === null) {
        $broken[] = "srcset ($url)";
    }
}

$ok = empty($broken) && count($candidates) >= 2;
r2t_out('tailles', $ok, (count($candidates) >= 2 ? count($candidates) . ' candidats srcset' : 'srcset absent')
    . (empty($broken) ? ', toutes les URL existent' : ', inexistantes : ' . implode(' ; ', $broken)));
