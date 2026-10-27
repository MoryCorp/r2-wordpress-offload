<?php
// A file name freed by local deletion is reused by WordPress for the next upload: the first
// attachment must keep serving its own bytes.
require __DIR__ . '/../lib.php';

r2t_reset(['remove_local_files' => true]);

$a = r2t_image(1);
$first = r2t_upload($a, 'photo.jpg');
$first_key = r2t_key_of_url(wp_get_attachment_url($first));
if ($first_key === null || r2t_store_get($first_key) !== file_get_contents($a)) {
    r2t_out('02', false, "premier upload non servi depuis le stockage (url " . wp_get_attachment_url($first) . ')');
    return;
}
if (file_exists(get_attached_file($first))) {
    r2t_out('02', false, 'copie locale du premier upload non supprimée, scénario non applicable');
    return;
}

$second = r2t_upload(r2t_image(2), 'photo.jpg');
$same_name = basename(get_attached_file($second)) === basename(get_attached_file($first));

$served = r2t_store_get((string) r2t_key_of_url(wp_get_attachment_url($first)));
$intact = $served === file_get_contents($a);
r2t_out('02', $intact, ($same_name ? 'nom réutilisé' : 'nom différent')
    . ', premier attachment ' . ($intact ? 'intact' : 'écrasé par le second'));

// 02b: the same file uploaded twice under the same name, then the second attachment deleted.
r2t_reset(['remove_local_files' => true]);
$a = r2t_image(23);
$first = r2t_upload($a, 'doublon.jpg');
$second = r2t_upload($a, 'doublon.jpg');
$same_name = basename(get_attached_file($second)) === basename(get_attached_file($first));
wp_delete_attachment($second, true);
r2t_reset_static_caches();
$served = r2t_store_get((string) r2t_key_of_url(wp_get_attachment_url($first)));
$intact = $served === file_get_contents($a);
r2t_out('02b', $intact, ($same_name ? 'nom réutilisé' : 'nom différent')
    . ', premier attachment ' . ($intact ? 'toujours servi' : 'perdu avec la suppression du second'));

// 02c: a media offloaded by the first release, now R2-only, then a never-offloaded upload under
// the same name, deleted.
r2t_reset(['remove_local_files' => true]);
$a = r2t_image(24);
$first = r2t_upload($a, 'homonyme.jpg', false);
r2t_make_legacy($first, true);
r2t_cleaner()->sync_from_r2();
$first_key = r2t_legacy_key(get_attached_file($first));
$second = r2t_upload(r2t_image(25), 'homonyme.jpg', false);
$same_name = get_attached_file($second) === get_attached_file($first);
wp_delete_attachment($second, true);
$intact = r2t_store_get($first_key) === file_get_contents($a);
r2t_out('02c', $intact, ($same_name ? 'nom réutilisé' : 'nom différent')
    . ', objet du premier attachment ' . ($intact ? 'intact' : 'supprimé avec le second'));
