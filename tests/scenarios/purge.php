<?php
// Purging plugin metadata must not orphan media whose only copy is remote.
require __DIR__ . '/../lib.php';

r2t_reset(['remove_local_files' => true]);
$id = r2t_upload(r2t_image(17), 'purge.jpg');
r2t_ajax('ajax_purge_meta');
// The purge is a raw SQL delete: drop this process's meta cache, as the next request would start without it.
wp_cache_flush();
r2t_reset_static_caches();
$served = r2t_key_of_url(wp_get_attachment_url($id)) !== null;
r2t_out('purge', $served, $served ? 'média sans copie locale toujours servi' : 'métadonnées purgées, média servi vers un fichier local absent');
