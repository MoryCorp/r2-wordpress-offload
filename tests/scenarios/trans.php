<?php
// The first page rendered after an upgrade may still find the previous release's path cache.
require __DIR__ . '/../lib.php';

r2t_reset();
$id = r2t_upload(r2t_image(10), 'trans.jpg');
$relative = get_post_meta($id, '_wp_attached_file', true);
set_transient('numos_r2_synced_paths', [$relative => true], 300);
r2t_reset_static_caches();

$html = apply_filters('the_content', '<img src="' . wp_upload_dir()['baseurl'] . '/' . $relative . '">');
preg_match('#src="([^"]+)"#', $html, $m);
$key = r2t_key_of_url($m[1] ?? '');
$ok = $key !== null && r2t_store_get($key) !== null;
r2t_out('trans', $ok, 'contenu réécrit vers ' . ($m[1] ?? '(rien)'));
