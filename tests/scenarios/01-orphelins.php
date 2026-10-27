<?php
// The orphan tool must stay inside this site's prefix and never offer a live object.
require __DIR__ . '/../lib.php';

$prefix = numos_r2_get_site_prefix();

// 01a: another site shares the bucket.
r2t_reset();
$neighbour = 'autre-site/2026/10/voisin.jpg';
r2t_store_put($neighbour, 'voisin');
r2t_store_put("$prefix/2026/10/orphelin.jpg", 'orphelin');
$listed = array_column(r2t_ajax('ajax_list_orphans')['data']['orphans'] ?? [], 'key');
$foreign = array_filter($listed, function ($k) use ($prefix) { return strpos($k, "$prefix/") !== 0; });
r2t_ajax('ajax_delete_orphans', ['keys' => [$neighbour]]);
$survived = r2t_store_get($neighbour) !== null;
r2t_out('01a', empty($foreign) && $survived, sprintf(
    '%d objet(s) d\'un autre site listé(s), objet voisin %s',
    count($foreign),
    $survived ? 'intact' : 'supprimé'
));

// 01b: media offloaded by the previous release, manifests not yet rebuilt.
r2t_reset();
$id = r2t_upload(r2t_image(7), 'historique.jpg', false);
r2t_make_legacy($id, true);
$live = r2t_store_keys("$prefix/");
$listed = array_column(r2t_ajax('ajax_list_orphans')['data']['orphans'] ?? [], 'key');
$offered = array_intersect($live, $listed);
r2t_out('01b', empty($offered), count($offered) . '/' . count($live) . ' objet(s) en service proposé(s) comme orphelins');
