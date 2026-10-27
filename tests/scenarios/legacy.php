<?php
// Rebuilding manifests for media offloaded by the previous release must reproduce exactly the
// URLs that release served, and must not settle for a partial bucket listing.
require __DIR__ . '/../lib.php';

if (r2t_variant() === 'avant') {
    r2t_out('legacy', null, 'reconstruction propre au schéma à manifeste');
    return;
}

$prefix = numos_r2_get_site_prefix();
$public = rtrim(NUMOS_R2_PUBLIC_URL, '/') . '/';

r2t_reset();
$cases = [];
$cases['complet'] = r2t_upload(r2t_image(13), 'complet.jpg', false);
r2t_make_legacy($cases['complet'], true);
$cases['partiel'] = r2t_upload(r2t_image(14), 'partiel.jpg', false);
$partial_missing = r2t_files($cases['partiel'])[1];
r2t_make_legacy($cases['partiel'], true, [$partial_missing]);
$cases['local'] = r2t_upload(r2t_image(15), 'local.jpg', false);
r2t_make_legacy($cases['local'], false);
$cases['statut-partial'] = r2t_upload(r2t_image(16), 'statut.jpg', false);
r2t_make_legacy($cases['statut-partial'], true);
update_post_meta($cases['statut-partial'], '_numos_r2_status', 'partial');

$first = r2t_cleaner()->sync_from_r2();
r2t_reset_static_caches();

$problems = [];
foreach ($cases as $label => $id) {
    if (NumosR2\Manifest::get($id) === null) {
        $problems[] = "$label sans manifeste";
        continue;
    }
    foreach (r2t_files($id) as $path) {
        if ($path === $partial_missing) {
            continue;
        }
        $expected = $public . $prefix . '/' . r2t_relative($path);
        $rel = r2t_relative($path);
        $html = apply_filters('the_content', '<img src="' . wp_upload_dir()['baseurl'] . '/' . $rel . '">');
        if (strpos($html, $expected) === false) {
            $problems[] = "$label $rel non servi comme avant";
        }
    }
}
$status = get_post_meta($cases['partiel'], '_numos_r2_status', true);
if ($status !== 'partial') {
    $problems[] = "attachment incomplet au statut « $status »";
}
$second = r2t_cleaner()->sync_from_r2();
if (($second['total'] ?? -1) !== 0) {
    $problems[] = 'second passage non nul (' . ($second['total'] ?? '?') . ')';
}
r2t_out('legacy', empty($problems), sprintf('1er passage : %d synced, %d partial, %d skipped', $first['synced'], $first['partial'], $first['skipped'])
    . (empty($problems) ? ', URL identiques à la version précédente' : ' ; ' . implode(' ; ', $problems)));

// A listing that fails on a later page must abort the rebuild, not feed it a partial index.
r2t_reset();
for ($i = 0; $i < 1001; $i++) {
    r2t_store_put_meta(sprintf('%s/2000/01/remplissage-%04d.jpg', $prefix, $i), 10, md5((string) $i));
}
$id = r2t_upload(r2t_image(20), 'page2.jpg', false);
r2t_make_legacy($id, true);
r2t_fault(['list_fail_paged' => true]);
$result = r2t_cleaner()->sync_from_r2();
$aborted = isset($result['error']) && NumosR2\Manifest::get($id) === null;
r2t_out('legacy-liste', $aborted, $aborted ? 'listing en erreur, reconstruction interrompue' : 'listing incomplet utilisé sans erreur (' . json_encode($result) . ')');
