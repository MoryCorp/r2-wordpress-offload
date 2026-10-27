<?php
// WordPress hands a file name freed by local deletion to the next upload: no operation on one
// attachment may then take the other's file for its own or destroy its only copy.
require __DIR__ . '/../lib.php';

if (r2t_variant() === 'avant') {
    r2t_out('homonymes', null, 'scénarios propres aux manifestes');
    return;
}
use NumosR2\Manifest;

function r2t_protected(int $id): bool {
    return in_array($id, r2t_cleaner()->get_r2_only_attachments(1000), true);
}

// h1: a local deletion dies after its unlinks, the name is reused, then the deletion is run again.
r2t_reset();
$a = r2t_upload(r2t_image(30), 'h1.jpg');
$manifest = Manifest::get($a);
foreach ($manifest['files'] as $relative => $entry) {
    $manifest['files'][$relative]['state'] = Manifest::STATE_DELETING;
}
Manifest::save($a, $manifest);
array_map('unlink', r2t_files($a));
r2t_upload(r2t_image(31), 'h1.jpg', false);
r2t_cleaner()->delete_local_files($a);
r2t_out('h1', r2t_protected($a), r2t_protected($a)
    ? 'premier attachment toujours marqué sans copie locale'
    : 'premier attachment déclaré local sur le fichier du second, R2 n\'est plus protégé');

// h2: an R2-only attachment is uploaded again while its names hold another attachment's files.
r2t_reset(['remove_local_files' => true]);
$a = r2t_upload(r2t_image(32), 'h2.jpg');
$before = Manifest::keys(Manifest::get($a));
r2t_upload(r2t_image(33), 'h2.jpg', false);
r2t_media_handler()->upload_attachment($a);
$after = Manifest::keys(Manifest::get($a) ?? ['files' => []]);
r2t_out('h2', $after === $before, $after === $before
    ? 'manifeste du premier inchangé'
    : 'manifeste du premier remplacé par les fichiers du second');

// h3: backfill of a media offloaded by the first release whose name now holds another file.
r2t_reset(['remove_local_files' => true]);
$a = r2t_upload(r2t_image(34), 'h3.jpg', false);
r2t_make_legacy($a, true);
r2t_upload(r2t_image(35), 'h3.jpg', false);
r2t_cleaner()->sync_from_r2();
r2t_out('h3', r2t_protected($a), r2t_protected($a)
    ? 'média historique toujours marqué sans copie locale'
    : 'fichier du second pris pour la copie locale du média historique');

// h4: two media of the first release on the same key (same file uploaded twice under one name).
r2t_reset(['remove_local_files' => true]);
$src = r2t_image(36);
$a = r2t_upload($src, 'h4.jpg', false);
r2t_make_legacy($a, true);
$b = r2t_upload($src, 'h4.jpg', false);
r2t_make_legacy($b, true);
r2t_cleaner()->sync_from_r2();
$shared = array_intersect(Manifest::keys(Manifest::get($a)), Manifest::keys(Manifest::get($b)));
wp_delete_attachment($b, true);
$lost = array_filter($shared, function ($key) { return r2t_store_get($key) === null; });
r2t_out('h4', $shared && !$lost, sprintf('%d clé(s) partagée(s), %d supprimée(s) avec le second média', count($shared), count($lost)));

// h5: an upload takes the name while the restore is downloading.
r2t_reset(['remove_local_files' => true]);
$a = r2t_upload(r2t_image(37), 'h5.jpg');
$local = get_attached_file($a);
r2t_fault(['delay_get' => [r2t_key_of_url(wp_get_attachment_url($a)) => 3]]);
$pid = (int) exec('timeout 30 ' . getenv('R2T_WP') . ' eval ' . escapeshellarg("(new NumosR2\\LocalCleaner(r2t_client()))->restore_from_r2($a);") . ' > /dev/null 2>&1 & echo $!');
sleep(1);
$bytes = file_get_contents(r2t_image(38));
file_put_contents($local, $bytes);
while (file_exists("/proc/$pid")) {
    usleep(200000);
}
r2t_fault([]);
$intact = file_get_contents($local) === $bytes;
r2t_out('h5', $intact, $intact ? 'fichier arrivé pendant la restauration intact' : 'fichier arrivé pendant la restauration écrasé');
