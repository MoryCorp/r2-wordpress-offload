<?php
// Filters can receive non-integer attachment IDs (customizer changesets, ACF option pages).
require __DIR__ . '/../lib.php';

r2t_reset();
$id = r2t_upload(r2t_image(9), 'coerc.jpg');
$url = wp_upload_dir()['baseurl'] . '/' . get_post_meta($id, '_wp_attached_file', true);
$rewriter = r2t_url_rewriter();

$cases = [
    'url-uuid' => function () use ($url) { return apply_filters('wp_get_attachment_url', $url, 'f3b2c1d4-9a7e'); },
    'url-null' => function () use ($url) { return apply_filters('wp_get_attachment_url', $url, null); },
    'srcset' => function () use ($url) { return apply_filters('wp_calculate_image_srcset', [], [1, 1], $url, [], 'abc'); },
    'acf-options' => function () use ($rewriter, $url, $id) { return $rewriter->rewrite_acf_image(['url' => $url, 'id' => $id], 'options', []); },
];
$fatal = [];
foreach ($cases as $name => $call) {
    try {
        $call();
    } catch (TypeError $e) {
        $fatal[] = $name;
    }
}
$numeric = apply_filters('wp_get_attachment_url', $url, (string) $id);
$rewritten = r2t_key_of_url($numeric) !== null;
r2t_out('coerc', empty($fatal) && $rewritten, (empty($fatal) ? 'aucune erreur fatale' : 'TypeError : ' . implode(', ', $fatal))
    . ', ID numérique en chaîne ' . ($rewritten ? 'réécrit' : 'non réécrit'));
