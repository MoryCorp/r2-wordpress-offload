<?php
// A file without generated sizes goes through the single-upload path.
require __DIR__ . '/../lib.php';

r2t_reset();
$source = wp_tempnam('unique.pdf');
file_put_contents($source, "%PDF-1.4\n" . str_repeat('r2t', 2000) . "\n%%EOF\n");
$id = r2t_upload($source, 'unique.pdf');
$key = r2t_key_of_url(wp_get_attachment_url($id));
$ok = $key !== null && r2t_store_get($key) === file_get_contents($source);
r2t_out('unique', $ok, $ok ? 'fichier sans taille servi depuis le stockage, contenu identique' : 'fichier sans taille non servi ou altéré (' . wp_get_attachment_url($id) . ')');
