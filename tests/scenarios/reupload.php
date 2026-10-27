<?php
// Re-syncing an attachment must not forget files that only exist remotely, and a repair run
// must not re-upload media of the previous release under new keys.
require __DIR__ . '/../lib.php';

if (r2t_variant() === 'avant') {
    r2t_out('reupload', null, 'propre au schéma à manifeste');
    r2t_out('fix', null, 'propre au schéma à manifeste');
    return;
}

r2t_reset();
$id = r2t_upload(r2t_image(19), 'reup.jpg');
$gone = r2t_files($id)[1];
$rel = r2t_relative($gone);
unlink($gone);
$manifest = NumosR2\Manifest::get($id);
$manifest['files'][$rel]['state'] = NumosR2\Manifest::STATE_LOCAL_DELETED;
NumosR2\Manifest::save($id, $manifest);

r2t_media_handler()->upload_attachment($id);
r2t_reset_static_caches();
$html = apply_filters('the_content', '<img src="' . wp_upload_dir()['baseurl'] . '/' . $rel . '">');
$kept = isset(NumosR2\Manifest::get($id)['files'][$rel]) && strpos($html, rtrim(NUMOS_R2_PUBLIC_URL, '/')) !== false;
r2t_out('reupload', $kept, $kept ? 'fichier distant seul conservé dans le manifeste' : "fichier distant seul oublié, $rel servi en local (absent)");

r2t_reset();
$id = r2t_upload(r2t_image(21), 'fix.jpg', false);
r2t_make_legacy($id, false);
$before = r2t_store_keys();
WP_CLI::runcommand('numos-r2 verify --fix', ['return' => 'all', 'launch' => false, 'exit_error' => false]);
$added = array_diff(r2t_store_keys(), $before);
r2t_out('fix', empty($added), empty($added) ? 'aucun ré-upload sous de nouvelles clés' : count($added) . ' objet(s) ré-uploadé(s) sous de nouvelles clés');
