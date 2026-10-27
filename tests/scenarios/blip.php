<?php
// During a storage API outage, media whose local copy is gone must keep their remote URLs.
require __DIR__ . '/../lib.php';

r2t_reset(['remove_local_files' => true]);
$id = r2t_upload(r2t_image(8), 'blip.jpg');
$baseurl = wp_upload_dir()['baseurl'];
$local_url = $baseurl . '/' . get_post_meta($id, '_wp_attached_file', true);

set_transient('numos_r2_health_status', 'down', 60);
r2t_reset_static_caches();

$urls = [
    'url' => wp_get_attachment_url($id),
    'src' => wp_get_attachment_image_src($id, 'full')[0],
    'contenu' => apply_filters('the_content', '<img src="' . $local_url . '">'),
];
$local = array_keys(array_filter($urls, function ($v) use ($baseurl) { return strpos($v, $baseurl) !== false; }));
r2t_out('blip', empty($local), empty($local)
    ? 'URL distantes conservées pendant la panne'
    : 'URL locales vers des fichiers supprimés : ' . implode(', ', $local));
