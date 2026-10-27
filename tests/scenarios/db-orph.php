<?php
// "Attachment without a local file" is not an orphan when its file lives on the bucket.
require __DIR__ . '/../lib.php';

r2t_reset();
$id = r2t_upload(r2t_image(18), 'dborph.jpg', false);
r2t_make_legacy($id, true);
delete_post_meta($id, '_numos_r2_status');
$keys = r2t_store_keys(numos_r2_get_site_prefix() . '/');

$migrator = new NumosR2\Migrator(r2t_client());
$migrator->set_media_handler(r2t_media_handler());
$migrator->delete_db_orphans([$id]);

$alive = get_post($id) !== null;
$remaining = count(array_filter($keys, function ($k) { return r2t_store_meta($k) !== null; }));
r2t_out('db-orph', $alive && $remaining === count($keys), sprintf(
    'attachment %s, %d/%d objet(s) conservé(s)',
    $alive ? 'conservé' : 'supprimé',
    $remaining,
    count($keys)
));
