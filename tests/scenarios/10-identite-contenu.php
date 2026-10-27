<?php
// Local deletion must prove that the remote copy holds the same bytes as the local file.
require __DIR__ . '/../lib.php';

// 10a: remote object replaced by different bytes of the same size.
r2t_reset();
$id = r2t_upload(r2t_image(3), 'verif.jpg');
$local = get_attached_file($id);
$key = r2t_key_of_url(wp_get_attachment_url($id));
r2t_store_put($key, r2t_flip_byte(r2t_store_get($key)));
r2t_cleaner()->delete_local_files($id);
$kept = file_exists($local);
r2t_out('10a', $kept, $kept ? 'suppression refusée, copie locale conservée' : 'copie locale supprimée alors que l\'objet distant diffère');

// 10b: local file rewritten in place after upload at the same size, as an optimizer does.
r2t_reset();
$id = r2t_upload(r2t_image(4), 'optim.jpg');
$local = get_attached_file($id);
$changed = r2t_flip_byte(file_get_contents($local));
file_put_contents($local, $changed);
r2t_cleaner()->delete_local_files($id);
$kept = file_exists($local) && file_get_contents($local) === $changed;
r2t_out('10b', $kept, $kept ? 'suppression refusée, version locale conservée' : 'version locale modifiée supprimée, le stockage garde l\'ancienne');
