<?php
// A size that never reached the bucket must be served from its local copy, even though core
// derives size URLs from the main file's URL.
require __DIR__ . '/../lib.php';

r2t_reset();
$id = r2t_upload(r2t_image(24), 'partiel-taille.jpg');
$medium = dirname(get_attached_file($id)) . '/' . wp_get_attachment_metadata($id)['sizes']['medium']['file'];
$key = r2t_key_of_url(wp_get_attachment_image_src($id, 'medium')[0]);
unlink(r2t_store_file($key, 'data'));
unlink(r2t_store_file($key, 'meta'));
if (r2t_variant() === 'apres') {
    $manifest = NumosR2\Manifest::get($id);
    unset($manifest['files'][r2t_relative($medium)]);
    NumosR2\Manifest::save($id, $manifest);
}
update_post_meta($id, '_numos_r2_status', 'partial');
r2t_reset_static_caches();

$src = wp_get_attachment_image_src($id, 'medium')[0];
$key = r2t_key_of_url($src);
$ok = $key !== null ? r2t_store_meta($key) !== null : $src === wp_upload_dir()['baseurl'] . '/' . r2t_relative($medium);
r2t_out('partiel', $ok, 'taille absente du stockage servie par ' . $src);
