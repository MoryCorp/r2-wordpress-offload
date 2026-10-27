<?php
// A local deletion interrupted after its intent was saved must complete when run again.
require __DIR__ . '/../lib.php';

if (r2t_variant() === 'avant') {
    r2t_out('reprise', null, 'pas d\'état de suppression persisté dans cette version');
    return;
}
r2t_reset();
$id = r2t_upload(r2t_image(28), 'reprise-suppression.jpg');
$manifest = NumosR2\Manifest::get($id);
foreach ($manifest['files'] as $relative => $entry) {
    $manifest['files'][$relative]['state'] = NumosR2\Manifest::STATE_DELETING;
}
NumosR2\Manifest::save($id, $manifest);
$result = r2t_cleaner()->delete_local_files($id);
$left = count(array_filter(r2t_files($id), 'file_exists'));
r2t_out('reprise', $result['success'] && $left === 0, sprintf(
    'reprise %s, %d fichier(s) local(aux) restant(s)%s',
    $result['success'] ? 'réussie' : 'refusée',
    $left,
    $result['errors'] ? ' : ' . $result['errors'][0] : ''
));
